<?php

namespace App\Services\Payments;

use App\Exceptions\GatewayCredencialInvalidaException;
use App\Exceptions\GatewayDadosClienteInvalidosException;
use App\Exceptions\GatewayIndisponivelException;
use App\Exceptions\GatewayRecusouException;
use App\Exceptions\GatewayValorAbaixoDoMinimoException;
use App\Models\PaymentGatewayConfig;
use App\Support\BusinessDate;
use App\Support\Dinheiro;
use App\Support\Gateway\EventoDeCobranca;
use App\Support\Gateway\RespostaDeCobranca;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Implementação de `GatewayDeCobranca` contra a API do PagBank, para a
 * cobrança do tenant ao cliente final dele (Plano 19, Task 19.2).
 *
 * Prima de `App\Services\Gateway\PagBankGateway` (Plano 7), que fala com o
 * mesmo provedor para um fluxo diferente: lá a plataforma cobra o tenant, com
 * a credencial única da plataforma em configuração fixa. Aqui é o tenant
 * cobrando o cliente final dele, e a credencial é a do tenant, lida de
 * `PaymentGatewayConfig` a cada chamada — nunca guardada em propriedade desta
 * classe. Ver o cabeçalho de `GatewayDeCobranca` sobre por que isso importa:
 * instância reaproveitada entre tenants com credencial fixada seria cobrança
 * de um tenant caindo na conta de outro.
 *
 * Usa o mesmo host de "cobrança avulsa" (`/orders`, em
 * `api.pagseguro.com`/`sandbox.api.pagseguro.com`) que `PagBankGateway`
 * (Plano 7) usa para emitir a fatura de um tenant à plataforma. É a mesma
 * família de API, e por isso reaproveita as mesmas chaves de
 * `config('services.pagbank.base_url_pedidos_*')`: são endereços públicos do
 * provedor, não segredo — o segredo é só o token, e esse vem sempre de
 * `$configuracao`.
 *
 * ## Ambiente por tenant
 *
 * Diferente de `PagBankGateway` (Plano 7), que lê sandbox de uma flag fixa da
 * plataforma, aqui o ambiente vem de `PaymentGatewayConfig.ambiente`: cada
 * tenant escolhe testar em sandbox antes de ligar a cobrança de verdade, sem
 * depender de configuração da plataforma.
 *
 * ## Credencial
 *
 * O token vem só de `$configuracao->credenciais['token']`, nunca de
 * configuração fixa nem de propriedade da instância. Chamada sem token
 * configurado é recusada antes de ir à rede
 * (`GatewayCredencialInvalidaException::semCredencialConfigurada()`). Nada
 * daqui aparece em log, em mensagem de exceção nem em qualquer coisa que
 * volte para o domínio: o registro de cada chamada leva o método, o
 * caminho do endpoint, o ambiente, o status e o tempo, e nada mais — nem o
 * corpo enviado, nem o corpo recebido, porque os dois carregam dado do
 * cliente final (CPF/CNPJ, endereço), que também não é para vazar em log.
 *
 * ## Repetição
 *
 * `retry` só para falha de rede e 5xx, no máximo 2 tentativas no total (a
 * chamada original mais 1 repetição). **Nunca** para 4xx: reenviar uma
 * cobrança recusada por dado inválido é como se gera cobrança duplicada
 * quando o provedor aceitar na segunda vez.
 *
 * ## Cancelamento: presunção a confirmar
 *
 * O PagBank não documenta um cancelamento genérico de pedido no mesmo nível
 * de detalhe que emissão e consulta. `cancelar()` chama
 * `POST /orders/{id}/cancel`, no mesmo recurso usado para emitir e consultar,
 * pela mesma identidade (`idNoGateway` é sempre o id do **pedido**, nunca de
 * uma cobrança-filha dele). Confirme esse endpoint contra a documentação
 * viva do provedor antes de ligar cancelamento em produção com credencial
 * real: `PagBankGateway` (Plano 7) nunca precisou cancelar fatura pelo
 * provedor, então não há um caminho já testado em produção para copiar aqui.
 */
class GatewayPagBank implements GatewayDeCobranca
{
    /**
     * Nome do provedor, como gravado em `payment_gateway_configs.gateway` e
     * `charges.gateway`.
     */
    public const NOME = 'pagbank';

    /**
     * Duas tentativas no total (a chamada original mais uma repetição), com
     * meio segundo entre elas — só quando vale repetir (ver `valeRepetir()`).
     */
    private const TENTATIVAS = 2;

    private const ESPERA_ENTRE_TENTATIVAS_MS = 500;

    /**
     * Tempo limite da chamada, em segundos.
     */
    private const TIMEOUT = 15;

    private const FORMA_BOLETO = 'boleto';

    private const FORMA_PIX = 'pix';

    /**
     * Endpoint de leitura sem efeito colateral, usado só para confirmar que
     * o token é aceito. Não cria nem altera nada no provedor.
     */
    private const ENDPOINT_VERIFICACAO = '/public-keys';

    // -----------------------------------------------------------------
    // Emissão
    // -----------------------------------------------------------------

    public function emitirBoleto(
        PaymentGatewayConfig $configuracao,
        string $referencia,
        string $descricao,
        string $valor,
        DateTimeInterface|string $vencimento,
        array $cliente,
    ): RespostaDeCobranca {
        $corpo = $this->chamar(
            $configuracao,
            'POST',
            '/orders',
            $this->pedido($referencia, $descricao, $valor, $vencimento, $cliente, self::FORMA_BOLETO, $configuracao)
        );

        return $this->cobrancaDoPedido($corpo, '/orders', $configuracao);
    }

    public function emitirPix(
        PaymentGatewayConfig $configuracao,
        string $referencia,
        string $descricao,
        string $valor,
        DateTimeInterface|string $vencimento,
        array $cliente,
    ): RespostaDeCobranca {
        $corpo = $this->chamar(
            $configuracao,
            'POST',
            '/orders',
            $this->pedido($referencia, $descricao, $valor, $vencimento, $cliente, self::FORMA_PIX, $configuracao)
        );

        return $this->cobrancaDoPedido($corpo, '/orders', $configuracao);
    }

    public function consultar(PaymentGatewayConfig $configuracao, string $idNoGateway): RespostaDeCobranca
    {
        $endpoint = '/orders/'.$idNoGateway;

        return $this->cobrancaDoPedido(
            $this->chamar($configuracao, 'GET', $endpoint),
            $endpoint,
            $configuracao
        );
    }

    public function cancelar(PaymentGatewayConfig $configuracao, string $idNoGateway): void
    {
        // Ver o cabeçalho da classe sobre a presunção deste endpoint.
        $this->chamar($configuracao, 'POST', '/orders/'.$idNoGateway.'/cancel');
    }

    public function validarCredenciais(PaymentGatewayConfig $configuracao): bool
    {
        try {
            $this->chamar($configuracao, 'GET', self::ENDPOINT_VERIFICACAO);

            return true;
        } catch (GatewayRecusouException) {
            // Cobre credencial inválida (a mais comum), e qualquer outra
            // recusa de negócio: nada disso torna a credencial "válida".
            // Falha de rede/indisponibilidade não é capturada aqui de
            // propósito — não dá para dizer que o token está errado quando
            // nem foi possível falar com o provedor.
            return false;
        }
    }

    // -----------------------------------------------------------------
    // Webhook (Plano 19, Task 19.4)
    // -----------------------------------------------------------------

    /**
     * Confirma que a requisição veio mesmo do PagBank, para a cobrança de um
     * tenant específico.
     *
     * Mesmo algoritmo de `App\Services\Gateway\PagBankGateway::validarWebhook()`
     * (Plano 7): o provedor manda no cabeçalho `x-authenticity-token` o
     * SHA-256, em hexadecimal, de `token + "-" + corpo cru da requisição`. A
     * diferença é o token em si — lá é um único segredo da plataforma, em
     * `config('services.pagbank.webhook_token')`; aqui é o próprio token de
     * API do tenant (`$configuracao->credenciais['token']`), o mesmo que
     * autentica as chamadas de emissão. Confirme este comportamento contra a
     * documentação viva do provedor antes de ligar em produção com credencial
     * real: `PagBankGateway` (Plano 7) nunca precisou assinar webhook por
     * conta de um tenant individual, então não há um caminho já testado em
     * produção para copiar aqui — mesma ressalva já registrada no cabeçalho
     * desta classe sobre `cancelar()`.
     *
     * Sem token configurado ou sem cabeçalho, recusa sem comparar nada: é a
     * única postura segura, e é ela que faz `interpretarWebhook()` nunca ser
     * chamado para um payload que não se sabe de onde veio.
     *
     * A comparação é `hash_equals`, não `===`, para não vazar por tempo de
     * resposta quantos caracteres do hash o atacante já acertou.
     */
    public function validarWebhook(PaymentGatewayConfig $configuracao, Request $requisicao): bool
    {
        $credenciais = $configuracao->credenciais;
        $token = is_array($credenciais) ? ($credenciais['token'] ?? null) : null;
        $token = is_string($token) ? trim($token) : '';
        $assinaturaRecebida = (string) $requisicao->header('x-authenticity-token', '');

        if ($token === '' || $assinaturaRecebida === '') {
            return false;
        }

        $esperada = hash('sha256', $token.'-'.$requisicao->getContent());

        return hash_equals($esperada, $assinaturaRecebida);
    }

    /**
     * Traduz o payload do webhook de cobrança para o vocabulário do domínio.
     *
     * Nunca lança. Payload que esta classe não reconhece vira
     * `EventoDeCobranca::TIPO_DESCONHECIDO`, e quem chama (Task 19.4) grava do
     * mesmo jeito: evento desconhecido registrado dá para investigar depois,
     * evento descartado não dá.
     *
     * O corpo é o próprio objeto do pedido (`/orders`), com `charges[]` —
     * igual ao webhook de fatura da plataforma
     * (`PagBankGateway::eventoDePedido()`, Plano 7), porque é o mesmo recurso
     * do provedor. `chargeIdNoGateway` é o id do **pedido**, não o da
     * cobrança-filha: é o pedido que `emitirBoleto()`/`emitirPix()` devolveu e
     * que está gravado em `Charge.gateway_charge_id`.
     *
     * `cobranca_vencida` não nasce daqui, pelo mesmo motivo já registrado em
     * `PagBankGateway::eventoDePedido()`: o PagBank não avisa boleto vencido
     * por webhook. `cobranca_estornada` nasce de um status `REFUNDED` —
     * **presunção a confirmar** contra a documentação viva do provedor antes
     * de produção, igual à ressalva de `cancelar()` no cabeçalho da classe:
     * nenhum estorno real passou por este código ainda.
     *
     * @param  array<string, mixed>  $payload
     */
    public function interpretarWebhook(array $payload): EventoDeCobranca
    {
        if (! isset($payload['charges']) || ! is_array($payload['charges'])) {
            return new EventoDeCobranca(
                eventoId: $this->identificadorSinteticoDeCobranca(['desconhecido', $payload['id'] ?? null]),
                tipo: EventoDeCobranca::TIPO_DESCONHECIDO,
            );
        }

        $cobranca = is_array(data_get($payload, 'charges.0')) ? data_get($payload, 'charges.0') : [];
        $statusNoProvedor = is_string($cobranca['status'] ?? null) ? $cobranca['status'] : null;
        $idDoPedido = is_string($payload['id'] ?? null) ? $payload['id'] : null;

        $tipo = match (strtoupper((string) $statusNoProvedor)) {
            'PAID' => EventoDeCobranca::TIPO_PAGA,
            'DECLINED', 'CANCELED' => EventoDeCobranca::TIPO_CANCELADA,
            'REFUNDED' => EventoDeCobranca::TIPO_ESTORNADA,
            default => EventoDeCobranca::TIPO_DESCONHECIDO,
        };

        $desconhecido = $tipo === EventoDeCobranca::TIPO_DESCONHECIDO;
        $centavos = data_get($cobranca, 'amount.value');

        return new EventoDeCobranca(
            eventoId: $this->identificadorSinteticoDeCobranca([$idDoPedido, $cobranca['id'] ?? null, $statusNoProvedor]),
            tipo: $tipo,
            chargeIdNoGateway: $desconhecido ? null : $idDoPedido,
            valorPago: ($tipo === EventoDeCobranca::TIPO_PAGA && is_numeric($centavos))
                ? Dinheiro::paraDecimal((int) $centavos)
                : null,
            pagoEm: $tipo === EventoDeCobranca::TIPO_PAGA
                ? $this->diaDoPagamento($cobranca['paid_at'] ?? null)
                : null,
        );
    }

    /**
     * Chave de deduplicação montada a partir do recurso e do estado, já que
     * nenhum webhook do PagBank traz identificador de evento próprio. Mesma
     * técnica de `PagBankGateway::identificadorSintetico()` (Plano 7).
     *
     * @param  array<int, mixed>  $partes
     */
    private function identificadorSinteticoDeCobranca(array $partes): string
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
    // Montagem do pedido
    // -----------------------------------------------------------------

    /**
     * Pedido que emite a cobrança: Pix é `qr_codes`, sem `charges`; boleto é
     * `charges` com `payment_method.type = BOLETO`. Mesma divisão de
     * `PagBankGateway::pedidoDaFatura()` (Plano 7), porque é o mesmo recurso
     * do provedor.
     *
     * `notification_urls` aponta para `webhooks.cobranca` com o
     * `webhook_token` em claro deste tenant (o cast `encrypted` do model já
     * decifra ao ler a propriedade). Sem isso o PagBank nunca chama de volta
     * o endpoint da Task 19.4: a cobrança é emitida normalmente, mas a baixa
     * automática nunca acontece, e ninguém recebe erro para perceber.
     *
     * @param  array<string, mixed>  $cliente
     * @return array<string, mixed>
     */
    private function pedido(
        string $referencia,
        string $descricao,
        string $valor,
        DateTimeInterface|string $vencimento,
        array $cliente,
        string $forma,
        PaymentGatewayConfig $configuracao,
    ): array {
        $centavos = $this->emCentavos($valor);
        $nome = $this->textoOuVazio($cliente['nome'] ?? null);
        $email = $this->textoOuVazio($cliente['email'] ?? null);
        $documento = $this->somenteDigitos($cliente['documento'] ?? '');

        $pedido = [
            'reference_id' => $referencia,
            'customer' => [
                'name' => $nome,
                'email' => $email,
                'tax_id' => $documento,
            ],
            'items' => [[
                'reference_id' => $referencia,
                'name' => $descricao,
                'quantity' => 1,
                'unit_amount' => $centavos,
            ]],
        ];

        $webhookToken = $configuracao->webhook_token;

        if (is_string($webhookToken) && $webhookToken !== '') {
            $pedido['notification_urls'] = [route('webhooks.cobranca', ['webhookToken' => $webhookToken])];
        }

        if ($forma === self::FORMA_PIX) {
            $pedido['qr_codes'] = [[
                'amount' => ['value' => $centavos],
                'expiration_date' => $this->fimDoDiaDoVencimento($vencimento),
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
                    'due_date' => BusinessDate::diaDe($vencimento),
                    'instruction_lines' => [
                        'line_1' => $descricao,
                        'line_2' => (string) config('app.name'),
                    ],
                    'holder' => [
                        'name' => $nome,
                        'tax_id' => $documento,
                        'email' => $email,
                        'address' => $this->enderecoDoCliente($cliente['endereco'] ?? null),
                    ],
                ],
            ],
        ]];

        return $pedido;
    }

    /**
     * @return array<string, string>
     */
    private function enderecoDoCliente(mixed $endereco): array
    {
        $endereco = is_array($endereco) ? $endereco : [];

        return [
            'street' => $this->textoOuVazio($endereco['rua'] ?? null),
            'number' => $this->textoOuVazio($endereco['numero'] ?? null),
            'complement' => $this->textoOuVazio($endereco['complemento'] ?? null),
            'locality' => $this->textoOuVazio($endereco['bairro'] ?? null),
            'city' => $this->textoOuVazio($endereco['cidade'] ?? null),
            'region_code' => strtoupper($this->textoOuVazio($endereco['uf'] ?? null)),
            'country' => 'BRA',
            'postal_code' => $this->somenteDigitos($endereco['cep'] ?? ''),
        ];
    }

    // -----------------------------------------------------------------
    // Tradução da resposta
    // -----------------------------------------------------------------

    /**
     * Pedido do provedor traduzido para `RespostaDeCobranca`. Mesma leitura
     * de `PagBankGateway::cobrancaDoPedido()` (Plano 7): o "copia e cola" do
     * Pix vem em `qr_codes[0].text`, a linha digitável do boleto em
     * `formatted_barcode`, com `barcode` como segunda opção.
     *
     * @param  array<string, mixed>  $corpo
     */
    private function cobrancaDoPedido(array $corpo, string $endpoint, PaymentGatewayConfig $configuracao): RespostaDeCobranca
    {
        $cobranca = is_array(data_get($corpo, 'charges.0')) ? data_get($corpo, 'charges.0') : [];
        $situacao = $this->situacaoDaCobranca($cobranca['status'] ?? null, $configuracao);

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
     * Situação de uma cobrança no provedor para o vocabulário do domínio.
     * Status desconhecido vira aberta, com registro no log: o que não se
     * pode fazer é ler palavra desconhecida como "paga" e dar baixa numa
     * cobrança que ninguém pagou.
     */
    private function situacaoDaCobranca(mixed $status, PaymentGatewayConfig $configuracao): string
    {
        $normalizado = strtoupper((string) (is_scalar($status) ? $status : ''));

        return match ($normalizado) {
            'PAID' => RespostaDeCobranca::SITUACAO_PAGA,
            'DECLINED', 'CANCELED' => RespostaDeCobranca::SITUACAO_CANCELADA,
            'AUTHORIZED', 'IN_ANALYSIS', 'WAITING', '' => RespostaDeCobranca::SITUACAO_ABERTA,
            default => $this->cobrancaComStatusDesconhecido($normalizado, $configuracao),
        };
    }

    private function cobrancaComStatusDesconhecido(string $status, PaymentGatewayConfig $configuracao): string
    {
        Log::warning('gateway_pagbank.status_de_cobranca_nao_mapeado', [
            'payment_gateway_config_id' => $configuracao->getKey(),
            'company_id' => $configuracao->company_id,
            'status' => $status,
            'ambiente' => $configuracao->ambiente,
        ]);

        return RespostaDeCobranca::SITUACAO_ABERTA;
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
     * Executa a chamada, registra o que aconteceu e traduz o erro do
     * provedor em exceção de domínio.
     *
     * @param  array<array-key, mixed>  $dados
     * @return array<string, mixed>
     *
     * @throws GatewayIndisponivelException
     * @throws GatewayRecusouException
     */
    private function chamar(PaymentGatewayConfig $configuracao, string $metodo, string $endpoint, array $dados = []): array
    {
        $inicio = microtime(true);
        $requisicao = $this->clienteHttp($configuracao, $this->baseUrl($configuracao));

        try {
            $resposta = match ($metodo) {
                'GET' => $requisicao->get($endpoint, $dados),
                'POST' => $requisicao->post($endpoint, $dados),
                default => throw new InvalidArgumentException(
                    sprintf('Método HTTP "%s" não é usado nesta integração.', $metodo)
                ),
            };
        } catch (ConnectionException $excecao) {
            $this->registrarChamada($configuracao, $metodo, $endpoint, null, $inicio);

            throw GatewayIndisponivelException::semResposta($endpoint, $excecao);
        }

        $this->registrarChamada($configuracao, $metodo, $endpoint, $resposta->status(), $inicio);

        $corpo = $resposta->json();
        $corpo = is_array($corpo) ? $corpo : [];

        if ($resposta->serverError()) {
            throw GatewayIndisponivelException::erroDoProvedor(
                $endpoint,
                $resposta->status(),
                $this->mensagemDoProvedor($corpo)
            );
        }

        if ($resposta->clientError()) {
            $this->tratarClientError($resposta->status(), $corpo, $endpoint);
        }

        return $corpo;
    }

    /**
     * Token do tenant, lido de `$configuracao` nesta chamada e em nenhum
     * outro lugar. Nunca fica em propriedade da classe.
     *
     * @throws GatewayCredencialInvalidaException Sem token configurado.
     */
    private function tokenDe(PaymentGatewayConfig $configuracao): string
    {
        $credenciais = $configuracao->credenciais;
        $token = is_array($credenciais) ? ($credenciais['token'] ?? null) : null;
        $token = is_string($token) ? trim($token) : '';

        if ($token === '') {
            throw GatewayCredencialInvalidaException::semCredencialConfigurada();
        }

        return $token;
    }

    private function clienteHttp(PaymentGatewayConfig $configuracao, string $baseUrl): PendingRequest
    {
        return Http::baseUrl($baseUrl)
            ->withToken($this->tokenDe($configuracao))
            ->acceptJson()
            ->asJson()
            ->timeout(self::TIMEOUT)
            ->connectTimeout(min(self::TIMEOUT, 10))
            ->retry(
                times: self::TENTATIVAS,
                sleepMilliseconds: self::ESPERA_ENTRE_TENTATIVAS_MS,
                when: fn (Throwable $excecao): bool => $this->valeRepetir($excecao),
                throw: false,
            );
    }

    /**
     * Falha de rede e erro do servidor podem ser repetidos, porque em
     * nenhum dos dois se sabe se o provedor processou o pedido. Recusa 4xx
     * **nunca** é repetida: o provedor respondeu e disse não, e reenviar uma
     * cobrança recusada é como se gera cobrança duplicada quando ele aceitar
     * na segunda vez.
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
     * Traduz uma resposta 4xx do provedor na exceção de domínio mais
     * específica que a situação permite.
     *
     * A classificação por status (401/403 -> credencial) é confiável. A
     * classificação por `parameter_name`/mensagem (dado do cliente, valor
     * mínimo) é heurística, sobre o formato de erro documentado do PagBank
     * (`error_messages[].parameter_name`/`description`): quando nada bate,
     * cai no `GatewayRecusouException` genérico, com a mensagem do provedor
     * preservada — nunca descartada.
     *
     * @param  array<string, mixed>  $corpo
     *
     * @throws GatewayCredencialInvalidaException
     * @throws GatewayDadosClienteInvalidosException
     * @throws GatewayValorAbaixoDoMinimoException
     * @throws GatewayRecusouException
     */
    private function tratarClientError(int $status, array $corpo, string $endpoint): never
    {
        $mensagem = $this->mensagemDoProvedor($corpo);

        if (in_array($status, [401, 403], true)) {
            throw GatewayCredencialInvalidaException::recusadaPeloProvedor($endpoint, $status, $mensagem);
        }

        $parametro = mb_strtolower($this->primeiroParametroDoErro($corpo) ?? '', 'UTF-8');
        $mensagemMinuscula = mb_strtolower($mensagem ?? '', 'UTF-8');

        if ($this->pareceDocumentoDoCliente($parametro, $mensagemMinuscula)) {
            throw GatewayDadosClienteInvalidosException::comRespostaDoProvedor($endpoint, $status, $mensagem);
        }

        if ($this->pareceValorAbaixoDoMinimo($parametro, $mensagemMinuscula)) {
            throw GatewayValorAbaixoDoMinimoException::comRespostaDoProvedor($endpoint, $status, $mensagem);
        }

        throw GatewayRecusouException::comRespostaDoProvedor($endpoint, $status, $mensagem);
    }

    private function pareceDocumentoDoCliente(string $parametro, string $mensagem): bool
    {
        foreach (['tax_id', 'customer', 'holder', 'document'] as $pista) {
            if (str_contains($parametro, $pista)) {
                return true;
            }
        }

        return str_contains($mensagem, 'tax_id') || str_contains($mensagem, 'cpf') || str_contains($mensagem, 'cnpj');
    }

    private function pareceValorAbaixoDoMinimo(string $parametro, string $mensagem): bool
    {
        $mencionaValor = str_contains($parametro, 'amount') || str_contains($parametro, 'value');
        $mencionaMinimo = str_contains($mensagem, 'minimum')
            || str_contains($mensagem, 'mínimo')
            || str_contains($mensagem, 'minimo');

        return $mencionaValor && $mencionaMinimo;
    }

    /**
     * Registro da chamada. Sem token, sem corpo enviado, sem corpo
     * recebido: os dois carregam dado do cliente final, que também não é
     * para vazar em log.
     */
    private function registrarChamada(
        PaymentGatewayConfig $configuracao,
        string $metodo,
        string $endpoint,
        ?int $status,
        float $inicio,
    ): void {
        $contexto = [
            'payment_gateway_config_id' => $configuracao->getKey(),
            'company_id' => $configuracao->company_id,
            'metodo' => $metodo,
            'endpoint' => $endpoint,
            'ambiente' => $configuracao->ambiente,
            'status' => $status,
            'ms' => (int) round((microtime(true) - $inicio) * 1000),
        ];

        if ($status === null || $status >= 400) {
            Log::warning('gateway_pagbank.chamada_falhou', $contexto);

            return;
        }

        Log::info('gateway_pagbank.chamada', $contexto);
    }

    /**
     * Texto que o provedor devolveu explicando a recusa.
     *
     * Só campos conhecidos do formato de erro do PagBank são lidos. O corpo
     * cru nunca é repassado por inteiro: é nele que trafega dado do cliente
     * final, e mensagem de erro é justamente por onde esse tipo de dado
     * escaparia para o log e para a tela.
     *
     * @param  array<string, mixed>  $corpo
     */
    private function mensagemDoProvedor(array $corpo): ?string
    {
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

    /**
     * @param  array<string, mixed>  $corpo
     */
    private function primeiroParametroDoErro(array $corpo): ?string
    {
        $primeiro = data_get($corpo, 'error_messages.0');

        return is_array($primeiro) ? $this->textoOuNulo($primeiro['parameter_name'] ?? null) : null;
    }

    // -----------------------------------------------------------------
    // Configuração e utilidades
    // -----------------------------------------------------------------

    /**
     * Base URL da API de pedidos, conforme o ambiente **do tenant**
     * (`PaymentGatewayConfig.ambiente`), nunca de uma flag fixa da
     * plataforma.
     */
    private function baseUrl(PaymentGatewayConfig $configuracao): string
    {
        $chave = $configuracao->ambiente === PaymentGatewayConfig::AMBIENTES[0] // 'sandbox'
            ? 'services.pagbank.base_url_pedidos_sandbox'
            : 'services.pagbank.base_url_pedidos_producao';

        return rtrim((string) config($chave), '/');
    }

    /**
     * Valor em centavos, que é como o PagBank recebe dinheiro. `$valor`
     * chega como string decimal (nunca float), e a conversão arredonda para
     * não perder um centavo em cobrança real.
     */
    private function emCentavos(string $valor): int
    {
        return (int) round(((float) $valor) * 100);
    }

    /**
     * Fim do dia do vencimento, no fuso do negócio. O QR Pix expira no fim
     * do dia de Brasília, não no fim do dia em UTC: com UTC, o código
     * deixaria de funcionar às 21h do próprio dia do vencimento.
     */
    private function fimDoDiaDoVencimento(DateTimeInterface|string $vencimento): string
    {
        $dia = BusinessDate::paraFusoNegocio($vencimento) ?? BusinessDate::hoje();

        return $dia->endOfDay()->toIso8601String();
    }

    /**
     * Dia da confirmação do pagamento, sem hora, no fuso do negócio: é assim
     * que `Charge.pago_em` guarda o valor (Task 19.1).
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

    private function textoOuVazio(mixed $valor): string
    {
        return is_string($valor) ? trim($valor) : '';
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
     * Campo de texto que a resposta precisava trazer. Resposta
     * bem-sucedida sem o identificador é anomalia do provedor, não recusa:
     * entra como indisponibilidade, que é o lado que permite tentar de novo
     * mais tarde.
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
