<?php

namespace App\Services\Payments;

use App\Models\PaymentGatewayConfig;
use App\Support\Gateway\EventoDeCobranca;
use App\Support\Gateway\RespostaDeCobranca;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * Contrato do gateway que cobra o cliente final do tenant, por boleto ou PIX
 * (Plano 19, Task 19.2). `validarWebhook()`/`interpretarWebhook()` entraram na
 * Task 19.4, para a confirmação de pagamento chegar por webhook.
 *
 * Fluxo inverso do de `App\Contracts\GatewayAssinatura` (Plano 7). Lá é a
 * plataforma cobrando o tenant, com uma única credencial da plataforma em
 * `config('services.pagbank')`. Aqui é o tenant cobrando o cliente final
 * dele, cada empresa com a própria credencial, guardada e cifrada em
 * `PaymentGatewayConfig` (Task 19.1). É por isso que **todo** método recebe a
 * configuração como primeiro parâmetro, em vez de vir do construtor ou de
 * configuração fixa: quem implementa esta interface não pode fixar tenant
 * nenhum na instância.
 *
 * ## Sem estado de tenant na instância
 *
 * A implementação concreta pode ser reaproveitada entre chamadas de tenants
 * diferentes — é exatamente o que acontece numa rotina que percorre todas as
 * empresas para cobrar a mensalidade recorrente (Task 19.5). Se a credencial
 * de um tenant ficasse guardada em propriedade da instância entre uma
 * chamada e outra, a cobrança do tenant seguinte sairia com a credencial do
 * tenant anterior: dinheiro do cliente final caindo na conta errada, sem
 * conserto possível depois de compensado. Nenhum método desta interface pode
 * ler nada além do `$configuracao` recebido no próprio parâmetro daquela
 * chamada.
 *
 * ## Erros
 *
 * Toda implementação traduz a resposta do provedor para uma exceção de
 * domínio em português, nunca deixa a exceção crua do cliente HTTP escapar:
 *
 * - `App\Exceptions\GatewayCredencialInvalidaException` — a credencial do
 *   tenant foi recusada pelo provedor, ou não está configurada.
 * - `App\Exceptions\GatewayDadosClienteInvalidosException` — o provedor
 *   recusou por dado do cliente final incompleto ou inválido (CPF/CNPJ,
 *   e-mail, endereço).
 * - `App\Exceptions\GatewayValorAbaixoDoMinimoException` — o valor da
 *   cobrança é menor que o mínimo aceito pelo provedor.
 * - `App\Exceptions\GatewayRecusouException` — qualquer outra recusa 4xx,
 *   sem tradução mais específica. As três de cima estendem esta, então um
 *   `catch (GatewayRecusouException)` genérico continua funcionando.
 * - `App\Exceptions\GatewayIndisponivelException` — falha de rede ou erro
 *   5xx do provedor: não se sabe se a operação aconteceu, e só este caso é
 *   seguro repetir depois.
 *
 * Nenhuma delas carrega a credencial do tenant, nem parcial.
 */
interface GatewayDeCobranca
{
    /**
     * Emite um boleto a partir dos dados de uma cobrança.
     *
     * @param  string  $referencia  Identificador único da cobrança no domínio (ex.: "charge-42"). O provedor devolve este valor sem alteração nos eventos de webhook, e é ele que casa o evento com `Charge` (Task 19.4).
     * @param  string  $descricao  Texto exibido ao cliente final no boleto ou no PIX.
     * @param  string  $valor  Valor em reais, com duas casas decimais (ex.: "150.00"). String, nunca float: dinheiro não passa por ponto flutuante.
     * @param  DateTimeInterface|string  $vencimento  Data de vencimento. A implementação converte para o fuso do negócio via `App\Support\BusinessDate` antes de montar a chamada; quem chama não formata a data.
     * @param  array<string, mixed>  $cliente  Dados do cliente final que recebe a cobrança: `nome`, `documento` (CPF ou CNPJ, com ou sem máscara), `email`, e `endereco` com `rua`, `numero`, `complemento`, `bairro`, `cidade`, `uf`, `cep`. É o único ponto desta interface em que um array solto é aceito: o formato espelha o cadastro de `Client`/`Address`, e cada implementação decide como montar o corpo específico do provedor a partir dele.
     *
     * @throws \App\Exceptions\GatewayCredencialInvalidaException
     * @throws \App\Exceptions\GatewayDadosClienteInvalidosException
     * @throws \App\Exceptions\GatewayValorAbaixoDoMinimoException
     * @throws \App\Exceptions\GatewayRecusouException
     * @throws \App\Exceptions\GatewayIndisponivelException
     */
    public function emitirBoleto(
        PaymentGatewayConfig $configuracao,
        string $referencia,
        string $descricao,
        string $valor,
        DateTimeInterface|string $vencimento,
        array $cliente,
    ): RespostaDeCobranca;

    /**
     * Emite uma cobrança PIX. Mesmos parâmetros de `emitirBoleto()`; o
     * vencimento aqui vira a expiração do QR code, no fim do dia informado.
     *
     * @param  array<string, mixed>  $cliente  Ver `emitirBoleto()`.
     *
     * @throws \App\Exceptions\GatewayCredencialInvalidaException
     * @throws \App\Exceptions\GatewayDadosClienteInvalidosException
     * @throws \App\Exceptions\GatewayValorAbaixoDoMinimoException
     * @throws \App\Exceptions\GatewayRecusouException
     * @throws \App\Exceptions\GatewayIndisponivelException
     */
    public function emitirPix(
        PaymentGatewayConfig $configuracao,
        string $referencia,
        string $descricao,
        string $valor,
        DateTimeInterface|string $vencimento,
        array $cliente,
    ): RespostaDeCobranca;

    /**
     * Consulta a situação atual de uma cobrança já emitida.
     *
     * @throws \App\Exceptions\GatewayCredencialInvalidaException
     * @throws \App\Exceptions\GatewayRecusouException
     * @throws \App\Exceptions\GatewayIndisponivelException
     */
    public function consultar(PaymentGatewayConfig $configuracao, string $idNoGateway): RespostaDeCobranca;

    /**
     * Cancela a cobrança no provedor. Não decide nada sobre `Charge`; quem
     * chama grava o resultado no domínio (Task 19.3). Cobrança já paga não é
     * cancelável — quem chama confere isso antes, esta chamada não repete a
     * checagem.
     *
     * @throws \App\Exceptions\GatewayCredencialInvalidaException
     * @throws \App\Exceptions\GatewayRecusouException
     * @throws \App\Exceptions\GatewayIndisponivelException
     */
    public function cancelar(PaymentGatewayConfig $configuracao, string $idNoGateway): void;

    /**
     * Confirma junto ao provedor que a credencial da configuração é válida,
     * com uma chamada sem efeito colateral (não cria nem altera nada no
     * provedor).
     *
     * Devolve verdadeiro quando o provedor confirma a credencial. Devolve
     * **falso**, sem lançar, quando o provedor responde e diz que ela é
     * inválida — o caso comum de um tenant digitando o token errado, que não
     * é uma situação excepcional. Só falha de rede ou erro do lado do
     * provedor vira `GatewayIndisponivelException`: aí não se sabe se a
     * credencial é válida ou não, e devolver falso apagaria uma credencial
     * certa por causa de uma instabilidade do provedor.
     *
     * Quem chama grava `PaymentGatewayConfig.verificado_em` quando o retorno
     * é verdadeiro (`ResolvedorDeGateway::validar()`).
     *
     * @throws \App\Exceptions\GatewayIndisponivelException
     */
    public function validarCredenciais(PaymentGatewayConfig $configuracao): bool;

    /**
     * Confirma que a requisição de webhook realmente veio do provedor, pela
     * assinatura que ele manda (Task 19.4).
     *
     * Recebe `$configuracao` pelo mesmo motivo do resto da interface (ver o
     * cabeçalho da classe): cada tenant assina com a própria credencial, e
     * quem resolve qual tenant é este webhook é `PaymentGatewayConfig::paraToken()`,
     * a partir do `webhookToken` da URL — antes mesmo de chamar este método.
     * Não interpreta o payload e não tem efeito colateral; quem chama decide
     * o que fazer depois de saber que é autêntico.
     */
    public function validarWebhook(PaymentGatewayConfig $configuracao, Request $requisicao): bool;

    /**
     * Traduz o payload de um webhook de cobrança para o vocabulário do
     * domínio. Chamar só depois de `validarWebhook()` confirmar a
     * autenticidade.
     *
     * @param  array<string, mixed>  $payload  Corpo do webhook, já decodificado do JSON do provedor. É o único ponto desta interface em que um array solto é aceito, porque é exatamente aqui que ele deixa de existir: a implementação o traduz para `EventoDeCobranca` antes de devolver.
     */
    public function interpretarWebhook(array $payload): EventoDeCobranca;
}
