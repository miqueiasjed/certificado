<?php

namespace App\Services\Gateway;

use App\Contracts\GatewayAssinatura;
use App\Exceptions\GatewayIndisponivelException;
use App\Exceptions\GatewayRecusouException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Plan;
use App\Support\BusinessDate;
use App\Support\Gateway\EventoDeGateway;
use App\Support\Gateway\RespostaDeAssinatura;
use App\Support\Gateway\RespostaDeCobranca;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Implementação de `GatewayAssinatura` contra a API do PagBank (Plano 7,
 * Task 7.3).
 *
 * Esta classe traduz protocolo e nada mais: nenhuma decisão de negócio mora
 * aqui, nenhum Model é gravado aqui. Quem decide o que fazer com o resultado é
 * `SubscriptionService` (Task 7.4), `InvoiceService` (Task 7.5) e
 * `GatewayEventService` (Task 7.6).
 *
 * ## Duas APIs, dois hosts
 *
 * Não é escolha do projeto, é como o provedor é organizado:
 *
 * - **Assinatura recorrente** vive em `api.assinaturas.pagseguro.com`
 *   (`/plans`, `/customers`, `/subscriptions`). É o motor que cobra sozinho
 *   mês a mês.
 * - **Cobrança avulsa de uma fatura** vive em `api.pagseguro.com`
 *   (`/orders`). É a única forma documentada de emitir um Pix ou um boleto sob
 *   demanda e receber de volta o "copia e cola" ou a linha digitável.
 *
 * A API de assinaturas não emite cobrança sob demanda e não aceita Pix, então
 * `gerarCobranca()` e `consultarCobranca()` usam `/orders`, enquanto o resto
 * usa a API de assinaturas.
 *
 * ## Dado de cartão não passa por aqui
 *
 * O PagBank cifra o cartão no navegador com a chave pública da conta
 * (`PagSeguro.encryptCard()`), e o servidor nunca vê número, validade ou CVV.
 * O que sobe é o texto cifrado, e esta classe o trata como caixa-preta: repassa
 * ao provedor e esquece. Não decifra, não valida campo a campo, não registra em
 * log e não guarda em lugar nenhum.
 *
 * São dois passos, e é `registrarMeioDePagamento()` que faz o primeiro:
 *
 * 1. `registrarMeioDePagamento()` manda o cartão cifrado para o assinante da
 *    empresa (`PUT /customers/{id}/billing_info`, ou `POST /customers` com
 *    `billing_info` quando o assinante ainda não existe lá).
 * 2. `criarAssinatura()` com forma cartão referencia esse assinante, que agora
 *    tem meio de pagamento.
 *
 * Na ordem inversa, o passo 2 é recusado com mensagem explícita, em vez de
 * abrir um caminho em que o cartão atravessaria a aplicação. Nenhum campo em
 * claro de cartão (`number`, `security_code`, `exp_month`, `exp_year`) é
 * montado por esta classe em nenhum ponto: o cifrado substitui todos eles.
 *
 * ## Credencial
 *
 * Token vem só de `config('services.pagbank.token')`, nunca escrito no código.
 * Ele não aparece em log, em mensagem de exceção nem em nada que volte para o
 * domínio: o registro de cada chamada leva o método, o caminho do endpoint, o
 * ambiente, o status e o tempo, e nada mais.
 *
 * ## Repetição
 *
 * `retry` só para falha de rede e 5xx. **Nunca** para 4xx: reenviar uma
 * cobrança recusada é como se gera cobrança duplicada no cartão do cliente.
 * A separação chega ao domínio nas duas exceções: `GatewayIndisponivelException`
 * ("não se sabe se aconteceu", pode repetir depois) e `GatewayRecusouException`
 * ("o provedor disse não", não repetir).
 */
class PagBankGateway implements GatewayAssinatura
{
    /**
     * Nome do provedor, como gravado em `subscriptions.gateway` e
     * `gateway_events.gateway`.
     */
    public const NOME = 'pagbank';

    /**
     * Formas de pagamento do domínio (`subscriptions.forma_pagamento`).
     */
    public const FORMA_PIX = 'pix';

    public const FORMA_BOLETO = 'boleto';

    public const FORMA_CARTAO = 'cartao';

    /**
     * Único tipo de meio de pagamento que o PagBank guarda no assinante. Boleto
     * e Pix não têm meio de pagamento a cadastrar: são emitidos por cobrança.
     */
    private const TIPO_CARTAO_NO_PROVEDOR = 'CREDIT_CARD';

    /**
     * Três tentativas no total, com meio segundo entre elas. O suficiente para
     * atravessar uma oscilação de rede sem segurar a requisição do usuário por
     * tempo demais.
     */
    private const TENTATIVAS = 3;

    private const ESPERA_ENTRE_TENTATIVAS_MS = 500;

    private readonly string $token;

    private readonly ?string $urlDeWebhook;

    private readonly bool $sandbox;

    private readonly int $timeout;

    public function __construct()
    {
        $this->token = (string) config('services.pagbank.token', '');
        $this->urlDeWebhook = config('services.pagbank.webhook_url');
        $this->sandbox = (bool) config('services.pagbank.sandbox', true);
        $this->timeout = max(1, (int) config('services.pagbank.timeout', 20));
    }

    // -----------------------------------------------------------------
    // Meio de pagamento
    // -----------------------------------------------------------------

    /**
     * Cadastra o cartão da empresa no assinante dela dentro do PagBank.
     *
     * Passo obrigatório antes de `criarAssinatura()` com forma cartão: o motor
     * de recorrência cobra do `billing_info` do assinante, e um assinante sem
     * `billing_info` não recebe assinatura no cartão.
     *
     * Dois caminhos, porque o provedor não tem um só:
     *
     * - Assinante já existe: `PUT /customers/{id}/billing_info`, que é o único
     *   endpoint que troca o meio de pagamento de quem já está cadastrado.
     * - Assinante ainda não existe: `POST /customers` com `billing_info` no
     *   mesmo corpo. Criar vazio e atualizar em seguida seria uma ida a mais à
     *   rede e deixaria assinante órfão quando a segunda chamada falhasse.
     *
     * Chamar de novo substitui o cartão em vigor. É assim que a troca de cartão
     * do tenant acontece, e é por isso que não há método separado para trocar.
     *
     * `$cartaoCifrado` é o retorno de `PagSeguro.encryptCard()` no navegador.
     * Vai inteiro para `card.encrypted` e nada mais é montado junto: o provedor
     * documenta que o cifrado substitui todos os outros dados do cartão, então
     * `number`, `security_code`, `exp_month` e `exp_year` não existem neste
     * corpo. Não existirem é o ponto: campo em claro que o servidor não monta é
     * campo que ele não pode vazar.
     *
     * @throws GatewayRecusouException Cifra vazia, cartão recusado ou dado cadastral que o provedor não aceita.
     * @throws GatewayIndisponivelException Falha de rede ou erro 5xx do provedor.
     */
    public function registrarMeioDePagamento(Company $empresa, string $cartaoCifrado): void
    {
        $cifrado = trim($cartaoCifrado);

        if ($cifrado === '') {
            // Recusa antes da rede: mandar cifra vazia só gastaria uma ida para
            // receber o mesmo não, com mensagem do provedor mais obscura.
            throw GatewayRecusouException::operacaoNaoSuportada(
                'O cartão cifrado chegou vazio. O cartão é cifrado no navegador com a chave pública '
                .'da conta no PagBank, e é o resultado dessa cifragem que precisa chegar aqui: '
                .'o servidor não recebe número de cartão em nenhum momento.'
            );
        }

        $meioDePagamento = [[
            'type' => self::TIPO_CARTAO_NO_PROVEDOR,
            'card' => ['encrypted' => $cifrado],
        ]];

        $idDoAssinante = $this->procurarAssinante($empresa);

        if ($idDoAssinante === null) {
            $assinante = $this->dadosDoAssinante($empresa);
            $assinante['billing_info'] = $meioDePagamento;

            $this->chamar($this->baseUrlAssinaturas(), 'POST', '/customers', $assinante);

            return;
        }

        // O corpo aqui é um array JSON na raiz, não um objeto: o provedor
        // aceita mais de um meio de pagamento por assinante, e este endpoint
        // recebe a lista inteira.
        $this->chamar(
            $this->baseUrlAssinaturas(),
            'PUT',
            '/customers/'.$idDoAssinante.'/billing_info',
            $meioDePagamento
        );
    }

    // -----------------------------------------------------------------
    // Assinatura
    // -----------------------------------------------------------------

    /**
     * Cria a assinatura recorrente do tenant.
     *
     * São até três idas ao provedor porque a API pede identificadores dele nos
     * dois lados do vínculo: o plano e o assinante. Os dois são resolvidos por
     * `reference_id`, o campo que o PagBank reserva para a chave da aplicação,
     * o que evita guardar o id do provedor em coluna nova de `plans` e
     * `companies`.
     *
     * Pix não passa por aqui: o motor de recorrência do PagBank cobra por
     * cartão ou boleto, e não por Pix. A fatura de um tenant no Pix é emitida
     * por `gerarCobranca()`, que usa a API de pedidos.
     *
     * @throws GatewayRecusouException Forma não suportada, plano ausente no provedor, assinante de cartão ainda sem meio de pagamento, ou recusa 4xx do provedor.
     * @throws GatewayIndisponivelException Falha de rede ou erro 5xx do provedor.
     */
    public function criarAssinatura(Company $empresa, Plan $plano, string $formaPagamento): RespostaDeAssinatura
    {
        $tipoNoProvedor = $this->tipoDePagamentoDaAssinatura($formaPagamento);
        $planoNoProvedor = $this->planoNoProvedor($plano);
        $assinanteNoProvedor = $this->assinanteNoProvedor($empresa, $formaPagamento);

        $corpo = $this->chamar($this->baseUrlAssinaturas(), 'POST', '/subscriptions', [
            'reference_id' => $this->referenciaDaEmpresa($empresa),
            'plan' => ['id' => $planoNoProvedor],
            'customer' => ['id' => $assinanteNoProvedor],
            'payment_method' => [['type' => $tipoNoProvedor]],
        ]);

        return new RespostaDeAssinatura(
            idNoGateway: $this->exigirTexto($corpo, 'id', '/subscriptions'),
            situacao: $this->situacaoDaAssinatura($corpo['status'] ?? null),
        );
    }

    /**
     * Cancela a assinatura no provedor.
     *
     * Cancelamento no PagBank é definitivo: a assinatura não volta a ficar
     * ativa depois disso. Quem decide o que gravar em `Subscription` é a
     * Task 7.4; aqui só o protocolo acontece.
     */
    public function cancelarAssinatura(string $idNoGateway): void
    {
        $this->chamar(
            $this->baseUrlAssinaturas(),
            'PUT',
            '/subscriptions/'.$idNoGateway.'/cancel'
        );
    }

    /**
     * Troca o plano de uma assinatura ativa.
     *
     * O PagBank não tem endpoint próprio de migração: a troca é o mesmo
     * `PUT /subscriptions/{id}` que altera qualquer campo. A resposta nem
     * sempre traz a assinatura inteira, então quando o `status` não vier, ele é
     * consultado logo em seguida — melhor uma ida a mais do que devolver ao
     * domínio uma situação inventada.
     */
    public function trocarPlano(string $idNoGateway, Plan $novoPlano): RespostaDeAssinatura
    {
        $planoNoProvedor = $this->planoNoProvedor($novoPlano);
        $endpoint = '/subscriptions/'.$idNoGateway;

        $corpo = $this->chamar($this->baseUrlAssinaturas(), 'PUT', $endpoint, [
            'plan' => ['id' => $planoNoProvedor],
        ]);

        if (! isset($corpo['status'])) {
            $corpo = $this->chamar($this->baseUrlAssinaturas(), 'GET', $endpoint);
        }

        return new RespostaDeAssinatura(
            idNoGateway: (string) ($corpo['id'] ?? $idNoGateway),
            situacao: $this->situacaoDaAssinatura($corpo['status'] ?? null),
        );
    }

    // -----------------------------------------------------------------
    // Cobrança
    // -----------------------------------------------------------------

    /**
     * Emite a cobrança de uma fatura: Pix ou boleto, conforme a forma de
     * pagamento da assinatura que gerou a fatura.
     *
     * Fatura de cartão não passa por aqui de propósito. No cartão, quem cobra é
     * o motor de recorrência do PagBank, na data que ele controla, e emitir uma
     * segunda cobrança do mesmo período seria cobrar o cliente duas vezes.
     *
     * O identificador devolvido em `idNoGateway` é o id do **pedido**
     * (`ORDE_...`), e é ele que `consultarCobranca()` recebe de volta e que o
     * webhook de pedido traz no campo `id`. É o valor que vai para
     * `Invoice.gateway_invoice_id`.
     *
     * @throws GatewayRecusouException Forma de pagamento sem cobrança avulsa, ou recusa 4xx do provedor.
     * @throws GatewayIndisponivelException Falha de rede ou erro 5xx do provedor.
     */
    public function gerarCobranca(Invoice $fatura): RespostaDeCobranca
    {
        $forma = (string) ($fatura->subscription?->forma_pagamento ?? '');

        if ($forma === self::FORMA_CARTAO) {
            throw GatewayRecusouException::operacaoNaoSuportada(
                'Fatura no cartão é cobrada automaticamente pela recorrência do PagBank: '
                .'não há cobrança avulsa a emitir, e emitir uma segunda cobraria o cliente duas vezes.'
            );
        }

        if (! in_array($forma, [self::FORMA_PIX, self::FORMA_BOLETO], true)) {
            throw GatewayRecusouException::operacaoNaoSuportada(sprintf(
                'Forma de pagamento "%s" não emite cobrança no PagBank. Formas com cobrança avulsa: %s e %s.',
                $forma,
                self::FORMA_PIX,
                self::FORMA_BOLETO
            ));
        }

        $corpo = $this->chamar(
            $this->baseUrlPedidos(),
            'POST',
            '/orders',
            $this->pedidoDaFatura($fatura, $forma)
        );

        return $this->cobrancaDoPedido($corpo, '/orders');
    }

    /**
     * Consulta a situação atual de uma cobrança já emitida.
     */
    public function consultarCobranca(string $idNoGateway): RespostaDeCobranca
    {
        $endpoint = '/orders/'.$idNoGateway;

        return $this->cobrancaDoPedido(
            $this->chamar($this->baseUrlPedidos(), 'GET', $endpoint),
            $endpoint
        );
    }

    // -----------------------------------------------------------------
    // Webhook
    // -----------------------------------------------------------------

    /**
     * Confirma que a requisição veio mesmo do PagBank.
     *
     * O provedor manda no cabeçalho `x-authenticity-token` o SHA-256, em
     * hexadecimal, de `token + "-" + corpo cru da requisição`. O corpo tem que
     * ser o texto exato recebido: qualquer reserialização do JSON muda o hash e
     * derruba webhook legítimo.
     *
     * Sem token configurado, tudo é recusado. É a única postura segura: sem
     * validação, qualquer um confirma pagamento por POST.
     *
     * A comparação é `hash_equals`, não `===`, para não vazar por tempo de
     * resposta quantos caracteres do hash o atacante já acertou.
     */
    public function validarWebhook(Request $requisicao): bool
    {
        $tokenDeWebhook = (string) config('services.pagbank.webhook_token', '');
        $assinaturaRecebida = (string) $requisicao->header('x-authenticity-token', '');

        if ($tokenDeWebhook === '' || $assinaturaRecebida === '') {
            return false;
        }

        $esperada = hash('sha256', $tokenDeWebhook.'-'.$requisicao->getContent());

        return hash_equals($esperada, $assinaturaRecebida);
    }

    /**
     * Traduz o payload do webhook para o vocabulário do domínio.
     *
     * Nunca lança. Payload que esta classe não reconhece vira
     * `EventoDeGateway::TIPO_DESCONHECIDO`, e quem chama (Task 7.6) grava do
     * mesmo jeito: evento desconhecido registrado dá para investigar depois,
     * evento descartado não dá.
     *
     * Dois formatos chegam, porque são duas APIs:
     *
     * - **Assinatura**: `{"env":..., "event":"subscription.canceled",
     *   "resource":{...}}`.
     * - **Pedido**: o próprio objeto do pedido, com `charges[]`, sem campo
     *   `event`.
     *
     * Nenhum dos dois traz identificador de evento, então `eventoId` é montado
     * a partir do recurso e do estado que o evento comunica. É esse valor que
     * vira a chave unique de `gateway_events.evento_id`, ou seja, a
     * deduplicação: reenvio do mesmo evento colide, mudança de estado do mesmo
     * recurso não.
     */
    public function interpretarWebhook(array $payload): EventoDeGateway
    {
        $evento = $payload['event'] ?? null;

        if (is_string($evento) && $evento !== '') {
            return $this->eventoDeAssinatura($evento, $payload);
        }

        if (isset($payload['charges']) && is_array($payload['charges'])) {
            return $this->eventoDePedido($payload);
        }

        return new EventoDeGateway(
            eventoId: $this->identificadorSintetico(['desconhecido', $payload['id'] ?? null]),
            tipo: EventoDeGateway::TIPO_DESCONHECIDO,
        );
    }

    // -----------------------------------------------------------------
    // Webhook: tradução de cada formato
    // -----------------------------------------------------------------

    /**
     * Evento da API de assinaturas.
     *
     * Só `subscription.canceled` e `subscription.expired` têm equivalente no
     * vocabulário do domínio: as duas encerram a assinatura sem volta. Os
     * demais (`initial`, `updated`, `activated`, `recurrence`, `suspended`,
     * `migrated`, e os de plano, cupom, assinante e estorno) viram
     * `desconhecido` de propósito — mapear `recurrence` para "cobrança paga"
     * seria supor pagamento a partir de um evento que só diz que o ciclo virou.
     *
     * @param  array<string, mixed>  $payload
     */
    private function eventoDeAssinatura(string $evento, array $payload): EventoDeGateway
    {
        $recurso = is_array($payload['resource'] ?? null) ? $payload['resource'] : [];
        $idDoRecurso = is_string($recurso['id'] ?? null) ? $recurso['id'] : null;

        $tipo = match ($evento) {
            'subscription.canceled', 'subscription.expired' => EventoDeGateway::TIPO_ASSINATURA_CANCELADA,
            default => EventoDeGateway::TIPO_DESCONHECIDO,
        };

        return new EventoDeGateway(
            eventoId: $this->identificadorSintetico([
                $evento,
                $idDoRecurso,
                $recurso['updated_at'] ?? null,
            ]),
            tipo: $tipo,
            faturaIdNoGateway: $tipo === EventoDeGateway::TIPO_DESCONHECIDO ? null : $idDoRecurso,
        );
    }

    /**
     * Evento da API de pedidos, que é o objeto do pedido inteiro.
     *
     * `faturaIdNoGateway` é o id do **pedido**, não o da cobrança: é o pedido
     * que `gerarCobranca()` devolveu e que está gravado em
     * `Invoice.gateway_invoice_id`. Casar pelo id da cobrança não encontraria
     * fatura nenhuma.
     *
     * `cobranca_vencida` não nasce aqui: o PagBank não avisa boleto vencido, e
     * inventar o evento a partir da ausência de aviso seria pior que não ter o
     * evento. Vencimento é apurado pela rotina diária do domínio, que já
     * compara `Invoice.vencimento` com o dia de hoje no fuso do negócio.
     *
     * @param  array<string, mixed>  $payload
     */
    private function eventoDePedido(array $payload): EventoDeGateway
    {
        $cobranca = is_array(data_get($payload, 'charges.0')) ? data_get($payload, 'charges.0') : [];
        $statusNoProvedor = is_string($cobranca['status'] ?? null) ? $cobranca['status'] : null;
        $idDoPedido = is_string($payload['id'] ?? null) ? $payload['id'] : null;

        $tipo = match (strtoupper((string) $statusNoProvedor)) {
            'PAID' => EventoDeGateway::TIPO_COBRANCA_PAGA,
            'DECLINED', 'CANCELED' => EventoDeGateway::TIPO_COBRANCA_CANCELADA,
            default => EventoDeGateway::TIPO_DESCONHECIDO,
        };

        $desconhecido = $tipo === EventoDeGateway::TIPO_DESCONHECIDO;

        return new EventoDeGateway(
            eventoId: $this->identificadorSintetico([
                $idDoPedido,
                $cobranca['id'] ?? null,
                $statusNoProvedor,
            ]),
            tipo: $tipo,
            faturaIdNoGateway: $desconhecido ? null : $idDoPedido,
            situacao: $desconhecido ? null : $this->situacaoDaCobranca($statusNoProvedor),
            pagoEm: $tipo === EventoDeGateway::TIPO_COBRANCA_PAGA
                ? $this->diaDoPagamento($cobranca['paid_at'] ?? null)
                : null,
        );
    }

    /**
     * Chave de deduplicação montada a partir do recurso e do estado, já que
     * nenhum webhook do PagBank traz identificador de evento próprio.
     *
     * @param  array<int, mixed>  $partes
     */
    private function identificadorSintetico(array $partes): string
    {
        $limpas = array_filter(
            array_map(static fn (mixed $parte): string => is_scalar($parte) ? (string) $parte : '', $partes),
            static fn (string $parte): bool => $parte !== ''
        );

        if ($limpas === []) {
            return self::NOME.'-'.hash('sha256', 'sem-recurso');
        }

        return self::NOME.'-'.implode('-', $limpas);
    }

    // -----------------------------------------------------------------
    // Montagem de payload
    // -----------------------------------------------------------------

    /**
     * Pedido que emite a cobrança de uma fatura.
     *
     * Pix e boleto usam ramos diferentes do mesmo recurso: Pix é `qr_codes`,
     * sem `charges`; boleto é `charges` com `payment_method.type = BOLETO`.
     *
     * @return array<string, mixed>
     */
    private function pedidoDaFatura(Invoice $fatura, string $forma): array
    {
        $empresa = $fatura->company;
        $centavos = $this->emCentavos($fatura->valor);
        $referencia = 'invoice-'.$fatura->id;
        $descricao = 'Assinatura '.$fatura->referencia;

        $pedido = [
            'reference_id' => $referencia,
            'customer' => [
                'name' => (string) ($empresa->name ?? ''),
                'email' => (string) ($empresa->email ?? ''),
                'tax_id' => $this->somenteDigitos($empresa->cnpj ?? ''),
            ],
            'items' => [[
                'reference_id' => $referencia,
                'name' => $descricao,
                'quantity' => 1,
                'unit_amount' => $centavos,
            ]],
        ];

        if (is_string($this->urlDeWebhook) && $this->urlDeWebhook !== '') {
            $pedido['notification_urls'] = [$this->urlDeWebhook];
        }

        if ($forma === self::FORMA_PIX) {
            $pedido['qr_codes'] = [[
                'amount' => ['value' => $centavos],
                'expiration_date' => $this->fimDoDiaDoVencimento($fatura),
            ]];

            return $pedido;
        }

        $pedido['charges'] = [[
            'reference_id' => $referencia,
            'description' => $descricao,
            'amount' => ['value' => $centavos, 'currency' => 'BRL'],
            'payment_method' => [
                'type' => 'BOLETO',
                'boleto' => [
                    'due_date' => BusinessDate::diaDe($fatura->vencimento),
                    'instruction_lines' => [
                        'line_1' => 'Referente à '.$descricao,
                        'line_2' => (string) config('app.name'),
                    ],
                    'holder' => [
                        'name' => (string) ($empresa->name ?? ''),
                        'tax_id' => $this->somenteDigitos($empresa->cnpj ?? ''),
                        'email' => (string) ($empresa->email ?? ''),
                        'address' => $this->enderecoDaEmpresa($empresa),
                    ],
                ],
            ],
        ]];

        return $pedido;
    }

    /**
     * Dados cadastrais do assinante: nome, e-mail, documento, endereço e
     * telefone. Nenhum campo de pagamento.
     *
     * Quem precisa mandar o cartão junto é `registrarMeioDePagamento()`, e ele
     * acrescenta `billing_info` por fora, com o texto que o navegador já
     * cifrou. Manter a separação aqui é o que garante que os outros chamadores
     * (`assinanteNoProvedor()` no boleto) nunca montem corpo com cartão.
     *
     * @return array<string, mixed>
     */
    private function dadosDoAssinante(Company $empresa): array
    {
        $assinante = [
            'reference_id' => $this->referenciaDaEmpresa($empresa),
            'name' => (string) ($empresa->name ?? ''),
            'email' => (string) ($empresa->email ?? ''),
            'tax_id' => $this->somenteDigitos($empresa->cnpj ?? ''),
            'address' => $this->enderecoDaEmpresa($empresa),
        ];

        $telefone = $this->telefoneDaEmpresa($empresa);

        if ($telefone !== null) {
            $assinante['phones'] = [$telefone];
        }

        return $assinante;
    }

    /**
     * @return array<string, string>
     */
    private function enderecoDaEmpresa(Company $empresa): array
    {
        return [
            'street' => (string) ($empresa->street ?? ''),
            'number' => (string) ($empresa->number ?? ''),
            'complement' => (string) ($empresa->complement ?? ''),
            'locality' => (string) ($empresa->district ?? ''),
            'city' => (string) ($empresa->city ?? ''),
            'region_code' => strtoupper((string) ($empresa->state ?? '')),
            'country' => 'BRA',
            'postal_code' => $this->somenteDigitos($empresa->zip ?? ''),
        ];
    }

    /**
     * Telefone no formato do provedor, ou nulo quando a empresa não tem um
     * número aproveitável. O PagBank exige DDD separado do número.
     *
     * @return array<string, string>|null
     */
    private function telefoneDaEmpresa(Company $empresa): ?array
    {
        $digitos = $this->somenteDigitos($empresa->phone ?? '');

        if (strlen($digitos) < 10) {
            return null;
        }

        // Número com o 55 na frente vem de cadastro que já guardou o país.
        if (strlen($digitos) > 11 && str_starts_with($digitos, '55')) {
            $digitos = substr($digitos, 2);
        }

        return [
            'country' => '55',
            'area' => substr($digitos, 0, 2),
            'number' => substr($digitos, 2),
            'type' => strlen($digitos) >= 11 ? 'MOBILE' : 'BUSINESS',
        ];
    }

    /**
     * Chave da empresa dentro do provedor. Usa o id, não o CNPJ: o CNPJ pode
     * ser corrigido depois de um cadastro errado, e a chave que liga os dois
     * lados não pode mudar embaixo de uma assinatura em vigor.
     */
    private function referenciaDaEmpresa(Company $empresa): string
    {
        return 'company-'.$empresa->id;
    }

    // -----------------------------------------------------------------
    // Resolução de identificadores no provedor
    // -----------------------------------------------------------------

    /**
     * Id do plano no PagBank, casado por `reference_id` igual ao slug do plano
     * do catálogo.
     *
     * O plano precisa existir no painel do provedor com esse `reference_id`.
     * Criar o plano automaticamente daqui seria decisão de negócio (valor,
     * periodicidade, dia de cobrança) na camada errada, e um plano criado por
     * engano vira cobrança recorrente errada em cliente real.
     */
    private function planoNoProvedor(Plan $plano): string
    {
        $corpo = $this->chamar($this->baseUrlAssinaturas(), 'GET', '/plans', [
            'reference_id' => $plano->slug,
            'limit' => 1,
        ]);

        $id = data_get($corpo, 'plans.0.id');

        if (! is_string($id) || $id === '') {
            throw GatewayRecusouException::operacaoNaoSuportada(sprintf(
                'O plano "%s" não existe no PagBank. Cadastre o plano no provedor com reference_id igual ao slug antes de criar a assinatura.',
                $plano->slug
            ));
        }

        return $id;
    }

    /**
     * Id do assinante no PagBank, ou nulo quando a empresa ainda não foi
     * cadastrada lá.
     *
     * Casa por `reference_id`, a chave que este projeto mantém dos dois lados,
     * o que evita guardar o id do provedor em coluna nova de `companies`.
     */
    private function procurarAssinante(Company $empresa): ?string
    {
        $corpo = $this->chamar($this->baseUrlAssinaturas(), 'GET', '/customers', [
            'reference_id' => $this->referenciaDaEmpresa($empresa),
            'limit' => 1,
        ]);

        $id = data_get($corpo, 'customers.0.id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Id do assinante no PagBank, criado quando ainda não existe.
     *
     * No cartão, criar o assinante aqui não resolveria nada: um assinante sem
     * `billing_info` não pode receber assinatura no cartão, e cadastrar o meio
     * de pagamento exige o cartão cifrado que só o navegador tem. Então a
     * operação é recusada com a instrução do que falta, em vez de abrir um
     * caminho em que o cartão passaria pelo servidor. Quem cumpre esse
     * pré-requisito é `registrarMeioDePagamento()`, e é ele que cria o
     * assinante junto com o meio de pagamento quando os dois faltam.
     */
    private function assinanteNoProvedor(Company $empresa, string $formaPagamento): string
    {
        $id = $this->procurarAssinante($empresa);

        if ($id !== null) {
            return $id;
        }

        if ($formaPagamento === self::FORMA_CARTAO) {
            throw GatewayRecusouException::operacaoNaoSuportada(
                'A empresa ainda não tem meio de pagamento cadastrado no PagBank. '
                .'Chame registrarMeioDePagamento() antes de assinar no cartão: o cartão é cifrado '
                .'no navegador e só o resultado cifrado sobe, então o servidor não recebe número '
                .'de cartão em nenhum momento.'
            );
        }

        $criado = $this->chamar(
            $this->baseUrlAssinaturas(),
            'POST',
            '/customers',
            $this->dadosDoAssinante($empresa)
        );

        return $this->exigirTexto($criado, 'id', '/customers');
    }

    // -----------------------------------------------------------------
    // Tradução de vocabulário
    // -----------------------------------------------------------------

    /**
     * Forma do domínio para o tipo do provedor, na assinatura recorrente.
     */
    private function tipoDePagamentoDaAssinatura(string $formaPagamento): string
    {
        return match ($formaPagamento) {
            self::FORMA_CARTAO => self::TIPO_CARTAO_NO_PROVEDOR,
            self::FORMA_BOLETO => 'BOLETO',
            self::FORMA_PIX => throw GatewayRecusouException::operacaoNaoSuportada(
                'O PagBank não cobra assinatura recorrente por Pix. '
                .'Assine no boleto ou no cartão; a fatura de cada período pode ser paga por Pix.'
            ),
            default => throw GatewayRecusouException::operacaoNaoSuportada(sprintf(
                'Forma de pagamento "%s" não existe no PagBank. Formas aceitas: %s e %s.',
                $formaPagamento,
                self::FORMA_BOLETO,
                self::FORMA_CARTAO
            )),
        };
    }

    /**
     * Situação da assinatura no provedor para o vocabulário do domínio.
     *
     * `PENDING` e `TRIAL` entram como ativa: nos dois a assinatura existe e
     * ninguém está devendo nada. `PENDING_ACTION` entra como em atraso porque é
     * pagamento negado com instrução de não repetir.
     *
     * Status novo, que o provedor passe a mandar sem aviso, também vira ativa,
     * com registro no log. Um status desconhecido tratado como atraso ou
     * suspensão cortaria o acesso de um cliente adimplente por causa de uma
     * palavra nova na API, e esse é o pior erro possível dos dois lados.
     */
    private function situacaoDaAssinatura(mixed $status): string
    {
        $normalizado = strtoupper((string) (is_scalar($status) ? $status : ''));

        return match ($normalizado) {
            'ACTIVE', 'TRIAL', 'PENDING' => RespostaDeAssinatura::SITUACAO_ATIVA,
            'OVERDUE', 'PENDING_ACTION' => RespostaDeAssinatura::SITUACAO_EM_ATRASO,
            'SUSPENDED' => RespostaDeAssinatura::SITUACAO_SUSPENSA,
            'CANCELED', 'EXPIRED' => RespostaDeAssinatura::SITUACAO_CANCELADA,
            default => $this->assinaturaComStatusDesconhecido($normalizado),
        };
    }

    private function assinaturaComStatusDesconhecido(string $status): string
    {
        Log::warning('pagbank.status_de_assinatura_nao_mapeado', [
            'status' => $status,
            'ambiente' => $this->ambiente(),
        ]);

        return RespostaDeAssinatura::SITUACAO_ATIVA;
    }

    /**
     * Situação de uma cobrança no provedor para o vocabulário do domínio.
     *
     * Pedido Pix ainda não pago não tem cobrança nenhuma no corpo, e por isso
     * a ausência de status também é "aberta". Status desconhecido vira aberta
     * pelo mesmo motivo, com registro no log: o que não se pode fazer é ler
     * palavra desconhecida como "paga" e dar baixa numa fatura que ninguém
     * pagou.
     */
    private function situacaoDaCobranca(mixed $status): string
    {
        $normalizado = strtoupper((string) (is_scalar($status) ? $status : ''));

        return match ($normalizado) {
            'PAID' => RespostaDeCobranca::SITUACAO_PAGA,
            'DECLINED', 'CANCELED' => RespostaDeCobranca::SITUACAO_CANCELADA,
            'AUTHORIZED', 'IN_ANALYSIS', 'WAITING', '' => RespostaDeCobranca::SITUACAO_ABERTA,
            default => $this->cobrancaComStatusDesconhecido($normalizado),
        };
    }

    private function cobrancaComStatusDesconhecido(string $status): string
    {
        Log::warning('pagbank.status_de_cobranca_nao_mapeado', [
            'status' => $status,
            'ambiente' => $this->ambiente(),
        ]);

        return RespostaDeCobranca::SITUACAO_ABERTA;
    }

    /**
     * Pedido do provedor traduzido para `RespostaDeCobranca`.
     *
     * O "copia e cola" do Pix vem em `qr_codes[0].text`; a imagem do QR não é
     * lida daqui, porque o projeto gera a imagem a partir do texto. A linha
     * digitável do boleto vem em `formatted_barcode`, com `barcode` como
     * segunda opção.
     *
     * @param  array<string, mixed>  $corpo
     */
    private function cobrancaDoPedido(array $corpo, string $endpoint): RespostaDeCobranca
    {
        $cobranca = is_array(data_get($corpo, 'charges.0')) ? data_get($corpo, 'charges.0') : [];
        $situacao = $this->situacaoDaCobranca($cobranca['status'] ?? null);

        $boleto = data_get($cobranca, 'payment_method.boleto');
        $linhaDigitavel = is_array($boleto)
            ? ($this->textoOuNulo($boleto['formatted_barcode'] ?? null) ?? $this->textoOuNulo($boleto['barcode'] ?? null))
            : null;

        return new RespostaDeCobranca(
            idNoGateway: $this->exigirTexto($corpo, 'id', $endpoint),
            situacao: $situacao,
            linkPagamento: $this->linkDePagamento($corpo, $cobranca),
            linhaDigitavel: $linhaDigitavel,
            qrCodePix: $this->textoOuNulo(data_get($corpo, 'qr_codes.0.text')),
            pagoEm: $situacao === RespostaDeCobranca::SITUACAO_PAGA
                ? $this->diaDoPagamento($cobranca['paid_at'] ?? null)
                : null,
        );
    }

    /**
     * Primeiro link de pagamento do pedido ou da cobrança.
     *
     * @param  array<string, mixed>  $corpo
     * @param  array<string, mixed>  $cobranca
     */
    private function linkDePagamento(array $corpo, array $cobranca): ?string
    {
        foreach ([$cobranca['links'] ?? [], $corpo['links'] ?? []] as $links) {
            if (! is_array($links)) {
                continue;
            }

            foreach ($links as $link) {
                if (! is_array($link)) {
                    continue;
                }

                $rel = strtoupper((string) ($link['rel'] ?? ''));

                if (str_contains($rel, 'PAY') && $this->textoOuNulo($link['href'] ?? null) !== null) {
                    return (string) $link['href'];
                }
            }
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Cliente HTTP
    // -----------------------------------------------------------------

    /**
     * Executa a chamada, registra o que aconteceu e traduz o erro do provedor
     * em exceção de domínio.
     *
     * O log leva método, caminho, ambiente, status e tempo. Nunca o token,
     * nunca o corpo enviado, nunca o corpo recebido: é no corpo que trafegam
     * dados do assinante e o cartão cifrado, e é no cabeçalho que trafega a
     * credencial.
     *
     * @param  array<array-key, mixed>  $dados  Objeto JSON na maioria dos endpoints. Lista quando o endpoint espera um array JSON na raiz, que hoje é só `PUT /customers/{id}/billing_info`.
     * @return array<string, mixed>
     *
     * @throws GatewayIndisponivelException
     * @throws GatewayRecusouException
     */
    private function chamar(string $baseUrl, string $metodo, string $endpoint, array $dados = []): array
    {
        $inicio = microtime(true);
        $requisicao = $this->clienteHttp($baseUrl);

        try {
            $resposta = match ($metodo) {
                'GET' => $requisicao->get($endpoint, $dados),
                'POST' => $requisicao->post($endpoint, $dados),
                'PUT' => $requisicao->put($endpoint, $dados),
                default => throw GatewayRecusouException::operacaoNaoSuportada(
                    sprintf('Método HTTP "%s" não é usado nesta integração.', $metodo)
                ),
            };
        } catch (ConnectionException $excecao) {
            $this->registrarChamada($metodo, $endpoint, null, $inicio);

            throw GatewayIndisponivelException::semResposta($endpoint, $excecao);
        }

        $this->registrarChamada($metodo, $endpoint, $resposta->status(), $inicio);

        if ($resposta->serverError()) {
            throw GatewayIndisponivelException::erroDoProvedor(
                $endpoint,
                $resposta->status(),
                $this->mensagemDoProvedor($resposta)
            );
        }

        if ($resposta->clientError()) {
            throw GatewayRecusouException::comRespostaDoProvedor(
                $endpoint,
                $resposta->status(),
                $this->mensagemDoProvedor($resposta)
            );
        }

        $corpo = $resposta->json();

        return is_array($corpo) ? $corpo : [];
    }

    /**
     * Cliente com timeout explícito e repetição só onde repetir é seguro.
     *
     * `throw: false` faz a resposta 4xx voltar como resposta, e não como
     * exceção do cliente HTTP: quem traduz o erro é `chamar()`, que sabe
     * separar recusa de indisponibilidade.
     */
    private function clienteHttp(string $baseUrl): PendingRequest
    {
        return Http::baseUrl($baseUrl)
            ->withToken($this->token)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout(min($this->timeout, 10))
            ->retry(
                times: self::TENTATIVAS,
                sleepMilliseconds: self::ESPERA_ENTRE_TENTATIVAS_MS,
                when: fn (Throwable $excecao): bool => $this->valeRepetir($excecao),
                throw: false,
            );
    }

    /**
     * Falha de rede e erro do servidor podem ser repetidos, porque em nenhum
     * dos dois se sabe se o provedor processou o pedido.
     *
     * Recusa 4xx **nunca** é repetida. O provedor respondeu e disse não, e o
     * mesmo pedido enviado de novo dá o mesmo não — exceto no caso em que ele
     * não dá, que é justamente o perigoso: cobrança recusada reenviada vira
     * cobrança duplicada.
     */
    private function valeRepetir(Throwable $excecao): bool
    {
        if ($excecao instanceof ConnectionException) {
            return true;
        }

        if ($excecao instanceof RequestException) {
            return $excecao->response->serverError();
        }

        return false;
    }

    /**
     * Registro da chamada. Sem token, sem corpo, sem dado de cartão.
     */
    private function registrarChamada(string $metodo, string $endpoint, ?int $status, float $inicio): void
    {
        $contexto = [
            'metodo' => $metodo,
            'endpoint' => $endpoint,
            'ambiente' => $this->ambiente(),
            'status' => $status,
            'ms' => (int) round((microtime(true) - $inicio) * 1000),
        ];

        if ($status === null || $status >= 400) {
            Log::warning('pagbank.chamada_falhou', $contexto);

            return;
        }

        Log::info('pagbank.chamada', $contexto);
    }

    /**
     * Texto que o provedor devolveu explicando a recusa, para o domínio poder
     * mostrar o que corrigir.
     *
     * Só campos conhecidos do formato de erro do PagBank são lidos. O corpo
     * cru nunca é repassado: é ele que carrega dado do assinante, e mensagem de
     * erro é justamente o lugar por onde esse tipo de dado escapa para o log e
     * para a tela.
     */
    private function mensagemDoProvedor(Response $resposta): ?string
    {
        $corpo = $resposta->json();

        if (! is_array($corpo)) {
            return null;
        }

        $mensagens = [];
        $erros = is_array($corpo['error_messages'] ?? null) ? $corpo['error_messages'] : [];

        foreach ($erros as $erro) {
            if (! is_array($erro)) {
                continue;
            }

            $descricao = $this->textoOuNulo($erro['description'] ?? null)
                ?? $this->textoOuNulo($erro['message'] ?? null);

            if ($descricao === null) {
                continue;
            }

            $parametro = $this->textoOuNulo($erro['parameter_name'] ?? null);

            $mensagens[] = $parametro === null ? $descricao : $parametro.': '.$descricao;
        }

        if ($mensagens !== []) {
            return implode('; ', $mensagens);
        }

        return $this->textoOuNulo($corpo['message'] ?? null);
    }

    // -----------------------------------------------------------------
    // Configuração e utilidades
    // -----------------------------------------------------------------

    private function baseUrlAssinaturas(): string
    {
        return rtrim((string) config(
            $this->sandbox
                ? 'services.pagbank.base_url_sandbox'
                : 'services.pagbank.base_url_producao'
        ), '/');
    }

    private function baseUrlPedidos(): string
    {
        return rtrim((string) config(
            $this->sandbox
                ? 'services.pagbank.base_url_pedidos_sandbox'
                : 'services.pagbank.base_url_pedidos_producao'
        ), '/');
    }

    private function ambiente(): string
    {
        return $this->sandbox ? 'sandbox' : 'producao';
    }

    /**
     * Valor em centavos, que é como o PagBank recebe dinheiro. `Invoice.valor`
     * é decimal, nunca float, e a conversão arredonda para não perder um
     * centavo em cobrança real.
     */
    private function emCentavos(mixed $valor): int
    {
        return (int) round(((float) $valor) * 100);
    }

    /**
     * Fim do dia do vencimento, no fuso do negócio.
     *
     * O QR Pix expira no fim do dia de Brasília, não no fim do dia em UTC: com
     * UTC, o código deixaria de funcionar às 21h do próprio dia do vencimento.
     */
    private function fimDoDiaDoVencimento(Invoice $fatura): string
    {
        $vencimento = BusinessDate::paraFusoNegocio($fatura->vencimento) ?? BusinessDate::hoje();

        return $vencimento->endOfDay()->toIso8601String();
    }

    /**
     * Dia da confirmação do pagamento, sem hora, no fuso do negócio: é assim
     * que `Invoice.pago_em` guarda o valor.
     */
    private function diaDoPagamento(mixed $valor): ?CarbonImmutable
    {
        $texto = $this->textoOuNulo($valor);

        if ($texto === null) {
            return null;
        }

        return BusinessDate::paraFusoNegocio($texto)?->startOfDay();
    }

    private function somenteDigitos(mixed $valor): string
    {
        return preg_replace('/\D/', '', (string) $valor) ?? '';
    }

    private function textoOuNulo(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $limpo = trim($valor);

        return $limpo === '' ? null : $limpo;
    }

    /**
     * Campo de texto que a resposta precisava trazer.
     *
     * Resposta bem-sucedida sem o identificador é anomalia do provedor, não
     * recusa: entra como indisponibilidade, que é o lado que permite tentar de
     * novo mais tarde.
     *
     * @param  array<string, mixed>  $corpo
     */
    private function exigirTexto(array $corpo, string $campo, string $endpoint): string
    {
        $valor = $this->textoOuNulo($corpo[$campo] ?? null);

        if ($valor === null) {
            throw GatewayIndisponivelException::erroDoProvedor(
                $endpoint,
                200,
                sprintf('resposta sem o campo obrigatório "%s".', $campo)
            );
        }

        return $valor;
    }
}
