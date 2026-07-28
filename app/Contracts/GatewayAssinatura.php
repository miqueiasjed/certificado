<?php

namespace App\Contracts;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Plan;
use App\Support\Gateway\EventoDeGateway;
use App\Support\Gateway\RespostaDeAssinatura;
use App\Support\Gateway\RespostaDeCobranca;
use Illuminate\Http\Request;

/**
 * Contrato do provedor de assinatura recorrente e cobrança dos tenants
 * ("Assinaturas dos tenants" em `.claude/prd/saas-multitenant.md`, Plano 7).
 *
 * O provedor concreto (Task 7.3, resolvido por configuração) pode mudar com o
 * tempo, mas nenhum tipo dele pode atravessar esta interface: todo método
 * recebe ou devolve Model do domínio, tipo primitivo, ou um dos objetos de
 * transporte de `App\Support\Gateway`, nunca o array cru de uma resposta
 * HTTP do provedor. É essa fronteira que permite trocar de gateway sem tocar
 * em `SubscriptionService`, `InvoiceService` ou em qualquer outro lugar do
 * domínio que dependa desta interface.
 *
 * A implementação concreta é resolvida pelo container a partir de
 * `config('services.gateway_assinatura')`, ligado em `AppServiceProvider`.
 *
 * Três obrigações de quem implementa:
 *
 * 1. **Nunca devolver ou aceitar array associativo solto**, fora do payload
 *    cru de `interpretarWebhook()`. Toda tradução do formato do provedor
 *    para o do domínio acontece dentro da implementação, antes de cruzar
 *    esta fronteira.
 * 2. **`interpretarWebhook()` nunca lança para payload que não reconhece.**
 *    Vira `EventoDeGateway` com `tipo = EventoDeGateway::TIPO_DESCONHECIDO`,
 *    e mesmo assim é registrado por quem chama. Descartar silenciosamente é
 *    como se perde confirmação de pagamento.
 * 3. **`validarWebhook()` só confirma autenticidade**, pela assinatura ou
 *    token que o provedor define. Não interpreta o payload nem tem efeito
 *    colateral: quem chama decide o que fazer depois de saber que é válido.
 *
 * ## Cartão: três passos, nesta ordem
 *
 * 1. O navegador cifra o cartão com a chave pública da conta no provedor.
 *    Número, validade e código de segurança nunca chegam ao servidor: o que
 *    sobe é o texto cifrado, opaco para a aplicação.
 * 2. `registrarMeioDePagamento()` entrega esse texto cifrado ao provedor e
 *    associa o meio de pagamento à empresa.
 * 3. `criarAssinatura()` com forma `cartao` usa o meio de pagamento que o
 *    passo 2 cadastrou.
 *
 * Pular o passo 2 é recusa, não erro de rede: sem meio de pagamento
 * cadastrado, o provedor não tem com o que cobrar a recorrência. Pix e boleto
 * não passam por nada disso, porque não têm meio de pagamento a guardar.
 */
interface GatewayAssinatura
{
    /**
     * Cadastra no provedor o cartão com que a recorrência do tenant será
     * cobrada, a partir do texto que o navegador já cifrou.
     *
     * É o passo que falta antes de `criarAssinatura()` com forma `cartao`: o
     * provedor só aceita assinatura no cartão de quem já tem meio de pagamento
     * associado, e é esta chamada que cria a associação. Chamar de novo troca
     * o cartão em vigor, que é como a troca de cartão do tenant acontece.
     *
     * Não devolve nada de propósito. O identificador do meio de pagamento
     * pertence ao provedor e é ele quem o resolve na hora de cobrar; o domínio
     * não guarda nem precisa guardar esse dado, e devolvê-lo só abriria porta
     * para alguém persistir um identificador de provedor fora da fronteira.
     *
     * @param  string  $cartaoCifrado  Resultado da cifragem feita no navegador com a chave pública da conta no provedor. Número, validade e código de segurança estão aqui dentro, cifrados: a aplicação não decifra, não valida campo a campo, não registra em log e não guarda em banco. Implementação que precisar do cartão em claro para cumprir este contrato está errada.
     *
     * @throws \App\Exceptions\GatewayRecusouException Cartão recusado, cifra inválida ou dado cadastral que o provedor não aceita.
     * @throws \App\Exceptions\GatewayIndisponivelException Falha de rede ou erro do lado do provedor.
     */
    public function registrarMeioDePagamento(Company $empresa, string $cartaoCifrado): void;

    /**
     * Cria a assinatura recorrente do tenant no provedor.
     *
     * Com forma `cartao`, exige que `registrarMeioDePagamento()` já tenha sido
     * chamado para esta empresa.
     *
     * @param  string  $formaPagamento  pix, boleto ou cartao, no vocabulário que a implementação concreta define.
     */
    public function criarAssinatura(Company $empresa, Plan $plano, string $formaPagamento): RespostaDeAssinatura;

    /**
     * Cancela a assinatura no provedor. Não apaga nem altera nada no
     * domínio: quem chama decide o que fazer com `Subscription` depois da
     * confirmação.
     */
    public function cancelarAssinatura(string $idNoGateway): void;

    /**
     * Troca o plano de uma assinatura já ativa no provedor.
     */
    public function trocarPlano(string $idNoGateway, Plan $novoPlano): RespostaDeAssinatura;

    /**
     * Gera a cobrança de uma fatura no provedor: link, linha digitável ou QR
     * Pix, conforme a forma de pagamento da fatura.
     */
    public function gerarCobranca(Invoice $fatura): RespostaDeCobranca;

    /**
     * Consulta a situação atual de uma cobrança já existente no provedor.
     */
    public function consultarCobranca(string $idNoGateway): RespostaDeCobranca;

    /**
     * Confirma que a requisição de webhook realmente veio do provedor, pela
     * assinatura ou token que ele define. Não interpreta o corpo da
     * requisição, e chamar isto não tem efeito colateral.
     */
    public function validarWebhook(Request $requisicao): bool;

    /**
     * Traduz o payload de um webhook do provedor para o vocabulário do
     * domínio. Chamar só depois de `validarWebhook()` confirmar a
     * autenticidade.
     *
     * @param  array<string, mixed>  $payload  Corpo do webhook, já decodificado do JSON do provedor. É o único ponto desta interface em que um array solto é aceito, porque é exatamente aqui que ele deixa de existir: a implementação o traduz para `EventoDeGateway` antes de devolver.
     */
    public function interpretarWebhook(array $payload): EventoDeGateway;
}
