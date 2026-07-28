<?php

namespace Tests\Unit;

use App\Exceptions\GatewayIndisponivelException;
use App\Exceptions\GatewayRecusouException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Gateway\PagBankGateway;
use App\Support\Gateway\EventoDeGateway;
use App\Support\Gateway\RespostaDeAssinatura;
use App\Support\Gateway\RespostaDeCobranca;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as RequisicaoHttp;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 7.3 do Plano 7: `PagBankGateway`, a implementação de
 * `GatewayAssinatura` contra a API do PagBank.
 *
 * Nenhum teste toca o banco nem a rede: os Models são montados na memória com
 * as relações injetadas por `setRelation()`, e todo o tráfego passa por
 * `Http::fake()` com `preventStrayRequests()`. Chamada que escapar do dublê
 * derruba o teste em vez de sair para a internet.
 *
 * O que está sendo cobrado aqui, além do caminho feliz:
 *
 * - **4xx não repete.** Reenviar uma cobrança recusada gera cobrança
 *   duplicada, então a contagem de requisições é afirmada, não só o tipo da
 *   exceção.
 * - **5xx e falha de rede repetem**, porque nesses dois não se sabe se o
 *   provedor processou o pedido.
 * - **Token não aparece em log.** O teste captura tudo que passou pelo canal
 *   de log e procura a credencial no conteúdo serializado. O mesmo vale para o
 *   cartão cifrado que `registrarMeioDePagamento()` recebe.
 * - **Cartão em claro não existe.** O corpo montado para o provedor leva só
 *   `card.encrypted`; o teste afirma a ausência de `number`, `security_code`,
 *   `exp_month` e `exp_year`.
 * - **`interpretarWebhook()` nunca lança.** Payload não mapeado vira
 *   `desconhecido`, porque evento descartado é confirmação de pagamento
 *   perdida.
 */
class PagBankGatewayTest extends TestCase
{
    private const TOKEN = 'token-de-api-super-secreto-abc123';

    private const TOKEN_WEBHOOK = 'token-de-webhook-secreto-xyz789';

    /**
     * Fica no lugar do que `PagSeguro.encryptCard()` devolve no navegador. Para
     * o servidor é texto opaco: ele repassa e esquece, e é exatamente isso que
     * os testes daqui cobram.
     */
    private const CARTAO_CIFRADO = 'eyJhbGciOiJSU0EtT0FFUCJ9.cartao-cifrado-pelo-navegador-nunca-em-claro';

    private const HOST_ASSINATURAS = 'https://sandbox.api.assinaturas.pagseguro.com';

    private const HOST_PEDIDOS = 'https://sandbox.api.pagseguro.com';

    protected function setUp(): void
    {
        parent::setUp();

        // Sem espera real entre as tentativas: o que interessa é quantas vezes
        // a chamada saiu, não quanto tempo a suíte fica parada.
        Sleep::fake();

        Http::preventStrayRequests();

        config([
            'services.pagbank.base_url_sandbox' => self::HOST_ASSINATURAS,
            'services.pagbank.base_url_producao' => 'https://api.assinaturas.pagseguro.com',
            'services.pagbank.base_url_pedidos_sandbox' => self::HOST_PEDIDOS,
            'services.pagbank.base_url_pedidos_producao' => 'https://api.pagseguro.com',
            'services.pagbank.token' => self::TOKEN,
            'services.pagbank.webhook_token' => self::TOKEN_WEBHOOK,
            'services.pagbank.webhook_url' => 'https://certificado.test/webhooks/pagbank',
            'services.pagbank.sandbox' => true,
            'services.pagbank.timeout' => 5,

            // Evita escrever arquivo de log durante a suíte; o evento
            // MessageLogged continua sendo disparado, que é do que o teste de
            // vazamento de credencial precisa.
            'logging.default' => 'null',
        ]);
    }

    // -----------------------------------------------------------------
    // registrarMeioDePagamento
    // -----------------------------------------------------------------

    public function test_registra_o_cartao_cifrado_no_assinante_que_ja_existe_no_provedor(): void
    {
        Http::fake([
            self::HOST_ASSINATURAS.'/customers?*' => Http::response(['customers' => [['id' => 'CUST_ABC']]]),
            self::HOST_ASSINATURAS.'/customers/CUST_ABC/billing_info' => Http::response([
                'id' => 'CUST_ABC',
                'billing_info' => [[
                    'type' => 'CREDIT_CARD',
                    'card' => ['token' => 'TOKE_1', 'brand' => 'visa', 'last_digits' => '1111'],
                ]],
            ]),
        ]);

        $this->gateway()->registrarMeioDePagamento($this->empresa(), self::CARTAO_CIFRADO);

        Http::assertSent(function (RequisicaoHttp $requisicao): bool {
            if ($requisicao->method() !== 'PUT') {
                return false;
            }

            if (! Str::endsWith($requisicao->url(), '/customers/CUST_ABC/billing_info')) {
                return false;
            }

            // O corpo é um array JSON na raiz, e não um objeto: o endpoint
            // recebe a lista inteira de meios de pagamento do assinante.
            if (! Str::startsWith(trim($requisicao->body()), '[')) {
                return false;
            }

            // O cifrado vai inteiro em `card.encrypted`, sem nenhum campo de
            // cartão em claro junto.
            return $requisicao->data() === [[
                'type' => 'CREDIT_CARD',
                'card' => ['encrypted' => self::CARTAO_CIFRADO],
            ]];
        });

        Http::assertSentCount(2);
    }

    public function test_cria_o_assinante_com_o_cartao_no_mesmo_corpo_quando_ele_ainda_nao_existe(): void
    {
        Http::fake([
            self::HOST_ASSINATURAS.'/customers?*' => Http::response(['customers' => []]),
            self::HOST_ASSINATURAS.'/customers' => Http::response(['id' => 'CUST_NOVO']),
        ]);

        $this->gateway()->registrarMeioDePagamento($this->empresa(), self::CARTAO_CIFRADO);

        // Criar o assinante vazio e só depois cadastrar o cartão deixaria
        // assinante órfão quando a segunda chamada falhasse: é uma ida só.
        Http::assertSent(function (RequisicaoHttp $requisicao): bool {
            if ($requisicao->method() !== 'POST' || ! Str::endsWith($requisicao->url(), '/customers')) {
                return false;
            }

            $dados = $requisicao->data();

            return $dados['reference_id'] === 'company-7'
                && $dados['billing_info'] === [[
                    'type' => 'CREDIT_CARD',
                    'card' => ['encrypted' => self::CARTAO_CIFRADO],
                ]];
        });

        Http::assertSentCount(2);
    }

    public function test_nao_monta_nenhum_campo_de_cartao_em_claro_no_corpo(): void
    {
        Http::fake([
            self::HOST_ASSINATURAS.'/customers?*' => Http::response(['customers' => [['id' => 'CUST_ABC']]]),
            self::HOST_ASSINATURAS.'/customers/CUST_ABC/billing_info' => Http::response(['id' => 'CUST_ABC']),
        ]);

        $this->gateway()->registrarMeioDePagamento($this->empresa(), self::CARTAO_CIFRADO);

        Http::assertSent(function (RequisicaoHttp $requisicao): bool {
            if ($requisicao->method() !== 'PUT') {
                return false;
            }

            // O cifrado substitui todos os outros dados do cartão. Campo em
            // claro que o servidor não monta é campo que ele não vaza.
            return ! Str::contains(strtolower($requisicao->body()), [
                'security_code',
                'exp_month',
                'exp_year',
                '"number"',
            ]);
        });
    }

    public function test_recusa_cartao_cifrado_vazio_sem_chamar_o_provedor(): void
    {
        Http::fake();

        $this->expectException(GatewayRecusouException::class);
        $this->expectExceptionMessageMatches('/cartão cifrado chegou vazio/');

        try {
            $this->gateway()->registrarMeioDePagamento($this->empresa(), '   ');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_cartao_recusado_pelo_provedor_nao_e_repetido(): void
    {
        Http::fake([
            self::HOST_ASSINATURAS.'/customers?*' => Http::response(['customers' => [['id' => 'CUST_ABC']]]),
            self::HOST_ASSINATURAS.'/customers/CUST_ABC/billing_info' => Http::response([
                'error_messages' => [[
                    'code' => '40002',
                    'description' => 'cartão inválido',
                    'parameter_name' => 'billing_info[0].card.encrypted',
                ]],
            ], 400),
        ]);

        try {
            $this->gateway()->registrarMeioDePagamento($this->empresa(), self::CARTAO_CIFRADO);
            $this->fail('Era para ter lançado GatewayRecusouException.');
        } catch (GatewayRecusouException $excecao) {
            $this->assertStringContainsString('cartão inválido', $excecao->getMessage());
            $this->assertStringNotContainsString(self::CARTAO_CIFRADO, $excecao->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $excecao->getMessage());
        }

        // Busca do assinante + UMA tentativa de cadastro. Repetir cartão
        // recusado é como se dispara antifraude no cliente.
        Http::assertSentCount(2);
    }

    public function test_o_cartao_cifrado_nunca_aparece_no_log(): void
    {
        Http::fake([
            self::HOST_ASSINATURAS.'/customers?*' => Http::response(['customers' => [['id' => 'CUST_ABC']]]),
            self::HOST_ASSINATURAS.'/customers/CUST_ABC/billing_info' => Http::response(['id' => 'CUST_ABC']),
        ]);

        $registros = [];

        Log::listen(function (MessageLogged $mensagem) use (&$registros): void {
            $registros[] = $mensagem;
        });

        $this->gateway()->registrarMeioDePagamento($this->empresa(), self::CARTAO_CIFRADO);

        $this->assertNotEmpty($registros, 'A chamada HTTP precisa deixar registro no log.');

        foreach ($registros as $registro) {
            $serializado = $registro->message.' '.json_encode($registro->context);

            $this->assertStringNotContainsString(self::CARTAO_CIFRADO, $serializado);
            $this->assertStringNotContainsString('encrypted', $serializado);
            $this->assertStringNotContainsString(self::TOKEN, $serializado);
        }
    }

    public function test_assinar_no_cartao_passa_a_funcionar_depois_de_registrar_o_meio_de_pagamento(): void
    {
        // O fluxo inteiro, que é o que estava sem caminho possível antes:
        // navegador cifra -> registrarMeioDePagamento() -> criarAssinatura().
        Http::fake([
            self::HOST_ASSINATURAS.'/plans*' => Http::response(['plans' => [['id' => 'PLAN_ABC']]]),
            self::HOST_ASSINATURAS.'/customers?*' => Http::sequence()
                ->push(['customers' => []])
                ->push(['customers' => [['id' => 'CUST_NOVO']]]),
            self::HOST_ASSINATURAS.'/customers' => Http::response(['id' => 'CUST_NOVO']),
            self::HOST_ASSINATURAS.'/subscriptions' => Http::response(['id' => 'SUBS_ABC', 'status' => 'ACTIVE']),
        ]);

        $gateway = $this->gateway();
        $empresa = $this->empresa();

        $gateway->registrarMeioDePagamento($empresa, self::CARTAO_CIFRADO);
        $resposta = $gateway->criarAssinatura($empresa, $this->plano(), PagBankGateway::FORMA_CARTAO);

        $this->assertSame('SUBS_ABC', $resposta->idNoGateway);
        $this->assertSame(RespostaDeAssinatura::SITUACAO_ATIVA, $resposta->situacao);

        Http::assertSent(fn (RequisicaoHttp $requisicao): bool => Str::endsWith($requisicao->url(), '/subscriptions')
            && $requisicao->data()['payment_method'] === [['type' => 'CREDIT_CARD']]);
    }

    // -----------------------------------------------------------------
    // criarAssinatura: sucesso
    // -----------------------------------------------------------------

    public function test_cria_assinatura_no_boleto_e_traduz_a_situacao_do_provedor(): void
    {
        Http::fake([
            self::HOST_ASSINATURAS.'/plans*' => Http::response(['plans' => [['id' => 'PLAN_ABC']]]),
            self::HOST_ASSINATURAS.'/customers*' => Http::response(['customers' => [['id' => 'CUST_ABC']]]),
            self::HOST_ASSINATURAS.'/subscriptions' => Http::response(['id' => 'SUBS_ABC', 'status' => 'ACTIVE']),
        ]);

        $resposta = $this->gateway()->criarAssinatura($this->empresa(), $this->plano(), PagBankGateway::FORMA_BOLETO);

        $this->assertSame('SUBS_ABC', $resposta->idNoGateway);
        $this->assertSame(RespostaDeAssinatura::SITUACAO_ATIVA, $resposta->situacao);

        Http::assertSent(function (RequisicaoHttp $requisicao): bool {
            if (! Str::endsWith($requisicao->url(), '/subscriptions')) {
                return false;
            }

            return $requisicao->data() === [
                'reference_id' => 'company-7',
                'plan' => ['id' => 'PLAN_ABC'],
                'customer' => ['id' => 'CUST_ABC'],
                'payment_method' => [['type' => 'BOLETO']],
            ];
        });
    }

    public function test_cria_assinante_no_provedor_quando_a_empresa_ainda_nao_existe_la(): void
    {
        Http::fake([
            self::HOST_ASSINATURAS.'/plans*' => Http::response(['plans' => [['id' => 'PLAN_ABC']]]),
            self::HOST_ASSINATURAS.'/customers*' => Http::sequence()
                ->push(['customers' => []])
                ->push(['id' => 'CUST_NOVO']),
            self::HOST_ASSINATURAS.'/subscriptions' => Http::response(['id' => 'SUBS_ABC', 'status' => 'PENDING']),
        ]);

        $resposta = $this->gateway()->criarAssinatura($this->empresa(), $this->plano(), PagBankGateway::FORMA_BOLETO);

        $this->assertSame(RespostaDeAssinatura::SITUACAO_ATIVA, $resposta->situacao);

        // O cadastro do assinante não leva nenhum campo de cartão: o cartão é
        // cifrado no navegador e nunca passa pelo servidor.
        Http::assertSent(function (RequisicaoHttp $requisicao): bool {
            if ($requisicao->method() !== 'POST' || ! Str::endsWith($requisicao->url(), '/customers')) {
                return false;
            }

            $corpo = strtolower($requisicao->body());

            return ! Str::contains($corpo, ['security_code', 'encrypted', 'billing_info', 'exp_month'])
                && Str::contains($corpo, 'company-7');
        });
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function situacoesDeAssinatura(): array
    {
        return [
            'ativa' => ['ACTIVE', RespostaDeAssinatura::SITUACAO_ATIVA],
            'em teste' => ['TRIAL', RespostaDeAssinatura::SITUACAO_ATIVA],
            'aguardando o primeiro pagamento' => ['PENDING', RespostaDeAssinatura::SITUACAO_ATIVA],
            'pagamento em atraso' => ['OVERDUE', RespostaDeAssinatura::SITUACAO_EM_ATRASO],
            'pagamento negado sem repetir' => ['PENDING_ACTION', RespostaDeAssinatura::SITUACAO_EM_ATRASO],
            'suspensa' => ['SUSPENDED', RespostaDeAssinatura::SITUACAO_SUSPENSA],
            'cancelada' => ['CANCELED', RespostaDeAssinatura::SITUACAO_CANCELADA],
            'expirada' => ['EXPIRED', RespostaDeAssinatura::SITUACAO_CANCELADA],
            // Palavra nova na API do provedor não pode cortar o acesso de um
            // cliente adimplente: entra como ativa, com aviso no log.
            'status que o provedor ainda não tinha' => ['STATUS_NOVO', RespostaDeAssinatura::SITUACAO_ATIVA],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('situacoesDeAssinatura')]
    public function test_traduz_a_situacao_da_assinatura_do_provedor(string $noProvedor, string $noDominio): void
    {
        Http::fake([
            self::HOST_ASSINATURAS.'/plans*' => Http::response(['plans' => [['id' => 'PLAN_ABC']]]),
            self::HOST_ASSINATURAS.'/customers*' => Http::response(['customers' => [['id' => 'CUST_ABC']]]),
            self::HOST_ASSINATURAS.'/subscriptions' => Http::response(['id' => 'SUBS_ABC', 'status' => $noProvedor]),
        ]);

        $resposta = $this->gateway()->criarAssinatura($this->empresa(), $this->plano(), PagBankGateway::FORMA_BOLETO);

        $this->assertSame($noDominio, $resposta->situacao);
    }

    // -----------------------------------------------------------------
    // criarAssinatura: recusas decididas antes da rede
    // -----------------------------------------------------------------

    public function test_recusa_assinatura_no_pix_sem_chamar_o_provedor(): void
    {
        Http::fake();

        $this->expectException(GatewayRecusouException::class);
        $this->expectExceptionMessageMatches('/não cobra assinatura recorrente por Pix/');

        try {
            $this->gateway()->criarAssinatura($this->empresa(), $this->plano(), PagBankGateway::FORMA_PIX);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_recusa_assinatura_no_cartao_quando_a_empresa_nao_tem_meio_de_pagamento_no_provedor(): void
    {
        Http::fake([
            self::HOST_ASSINATURAS.'/plans*' => Http::response(['plans' => [['id' => 'PLAN_ABC']]]),
            self::HOST_ASSINATURAS.'/customers*' => Http::response(['customers' => []]),
        ]);

        $this->expectException(GatewayRecusouException::class);
        $this->expectExceptionMessageMatches('/cifrado no navegador/');

        $this->gateway()->criarAssinatura($this->empresa(), $this->plano(), PagBankGateway::FORMA_CARTAO);
    }

    public function test_recusa_quando_o_plano_nao_existe_no_provedor(): void
    {
        Http::fake([
            self::HOST_ASSINATURAS.'/plans*' => Http::response(['plans' => []]),
        ]);

        $this->expectException(GatewayRecusouException::class);
        $this->expectExceptionMessageMatches('/reference_id igual ao slug/');

        $this->gateway()->criarAssinatura($this->empresa(), $this->plano(), PagBankGateway::FORMA_BOLETO);
    }

    // -----------------------------------------------------------------
    // Recusa 4xx: traduzida, com a mensagem do provedor, e sem repetir
    // -----------------------------------------------------------------

    public function test_recusa_do_provedor_vira_excecao_de_dominio_e_nao_e_repetida(): void
    {
        Http::fake([
            self::HOST_ASSINATURAS.'/plans*' => Http::response(['plans' => [['id' => 'PLAN_ABC']]]),
            self::HOST_ASSINATURAS.'/customers*' => Http::response(['customers' => [['id' => 'CUST_ABC']]]),
            self::HOST_ASSINATURAS.'/subscriptions' => Http::response([
                'error_messages' => [[
                    'code' => '40002',
                    'description' => 'plano inativo',
                    'parameter_name' => 'plan.id',
                ]],
            ], 400),
        ]);

        try {
            $this->gateway()->criarAssinatura($this->empresa(), $this->plano(), PagBankGateway::FORMA_BOLETO);
            $this->fail('Era para ter lançado GatewayRecusouException.');
        } catch (GatewayRecusouException $excecao) {
            $this->assertStringContainsString('plan.id: plano inativo', $excecao->getMessage());
            $this->assertStringContainsString('(HTTP 400)', $excecao->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $excecao->getMessage());
        }

        // Três requisições no total: /plans, /customers e UMA a /subscriptions.
        // Repetir a recusa é o que geraria cobrança duplicada.
        Http::assertSentCount(3);
    }

    // -----------------------------------------------------------------
    // Indisponibilidade: 5xx e falha de rede, com repetição
    // -----------------------------------------------------------------

    public function test_erro_do_servidor_do_provedor_vira_indisponibilidade_e_e_repetido(): void
    {
        Http::fake([
            self::HOST_ASSINATURAS.'/plans*' => Http::response(['plans' => [['id' => 'PLAN_ABC']]]),
            self::HOST_ASSINATURAS.'/customers*' => Http::response(['customers' => [['id' => 'CUST_ABC']]]),
            self::HOST_ASSINATURAS.'/subscriptions' => Http::response(['message' => 'internal error'], 503),
        ]);

        try {
            $this->gateway()->criarAssinatura($this->empresa(), $this->plano(), PagBankGateway::FORMA_BOLETO);
            $this->fail('Era para ter lançado GatewayIndisponivelException.');
        } catch (GatewayIndisponivelException $excecao) {
            $this->assertStringContainsString('(HTTP 503)', $excecao->getMessage());
            $this->assertStringContainsString('internal error', $excecao->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $excecao->getMessage());
        }

        // /plans + /customers + três tentativas de /subscriptions.
        Http::assertSentCount(5);
    }

    public function test_falha_de_rede_vira_indisponibilidade_e_e_repetida(): void
    {
        // A requisição que estoura na conexão nunca chega a ser registrada por
        // `Http::assertSentCount()`, então o contador fica no próprio dublê.
        $tentativas = 0;

        Http::fake(function () use (&$tentativas): never {
            $tentativas++;

            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        try {
            $this->gateway()->consultarCobranca('ORDE_ABC');
            $this->fail('Era para ter lançado GatewayIndisponivelException.');
        } catch (GatewayIndisponivelException $excecao) {
            $this->assertStringContainsString('/orders/ORDE_ABC', $excecao->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $excecao->getMessage());
        }

        $this->assertSame(3, $tentativas);
    }

    // -----------------------------------------------------------------
    // Credencial nunca no log
    // -----------------------------------------------------------------

    public function test_log_registra_endpoint_status_e_tempo_sem_o_token(): void
    {
        Http::fake([
            self::HOST_PEDIDOS.'/orders/*' => Http::response(['id' => 'ORDE_ABC', 'charges' => []]),
        ]);

        $registros = [];

        Log::listen(function (MessageLogged $mensagem) use (&$registros): void {
            $registros[] = $mensagem;
        });

        $this->gateway()->consultarCobranca('ORDE_ABC');

        $this->assertNotEmpty($registros, 'A chamada HTTP precisa deixar registro no log.');

        $chamada = collect($registros)->firstWhere('message', 'pagbank.chamada');

        $this->assertNotNull($chamada, 'Faltou o registro da chamada bem-sucedida.');
        $this->assertSame('GET', $chamada->context['metodo']);
        $this->assertSame('/orders/ORDE_ABC', $chamada->context['endpoint']);
        $this->assertSame('sandbox', $chamada->context['ambiente']);
        $this->assertSame(200, $chamada->context['status']);
        $this->assertIsInt($chamada->context['ms']);

        foreach ($registros as $registro) {
            $serializado = $registro->message.' '.json_encode($registro->context);

            $this->assertStringNotContainsString(self::TOKEN, $serializado);
            $this->assertStringNotContainsString(self::TOKEN_WEBHOOK, $serializado);
            $this->assertStringNotContainsString('Bearer', $serializado);
        }
    }

    // -----------------------------------------------------------------
    // gerarCobranca e consultarCobranca
    // -----------------------------------------------------------------

    public function test_gera_cobranca_pix_com_o_copia_e_cola_preenchido(): void
    {
        Http::fake([
            self::HOST_PEDIDOS.'/orders' => Http::response([
                'id' => 'ORDE_PIX',
                'qr_codes' => [['id' => 'QRCO_1', 'text' => '00020101021226...copia-e-cola']],
                'charges' => [],
            ]),
        ]);

        $resposta = $this->gateway()->gerarCobranca($this->fatura(PagBankGateway::FORMA_PIX));

        $this->assertSame('ORDE_PIX', $resposta->idNoGateway);
        $this->assertSame(RespostaDeCobranca::SITUACAO_ABERTA, $resposta->situacao);
        $this->assertSame('00020101021226...copia-e-cola', $resposta->qrCodePix);
        $this->assertNull($resposta->linhaDigitavel);
        $this->assertNull($resposta->pagoEm);

        Http::assertSent(function (RequisicaoHttp $requisicao): bool {
            $dados = $requisicao->data();

            return $dados['reference_id'] === 'invoice-42'
                && $dados['qr_codes'][0]['amount']['value'] === 19990
                && ! isset($dados['charges'])
                && $dados['notification_urls'] === ['https://certificado.test/webhooks/pagbank'];
        });
    }

    public function test_gera_cobranca_boleto_com_a_linha_digitavel_preenchida(): void
    {
        Http::fake([
            self::HOST_PEDIDOS.'/orders' => Http::response([
                'id' => 'ORDE_BOL',
                'charges' => [[
                    'id' => 'CHAR_1',
                    'status' => 'WAITING',
                    'payment_method' => [
                        'type' => 'BOLETO',
                        'boleto' => [
                            'barcode' => '03399853012970000000200726101017775500000051',
                            'formatted_barcode' => '03399.85301 29700.000002 00726.101017 7 77550000005100',
                        ],
                    ],
                    'links' => [[
                        'rel' => 'PAY',
                        'href' => 'https://sandbox.api.pagseguro.com/boletos/BOL_1.pdf',
                    ]],
                ]],
            ]),
        ]);

        $resposta = $this->gateway()->gerarCobranca($this->fatura(PagBankGateway::FORMA_BOLETO));

        $this->assertSame(RespostaDeCobranca::SITUACAO_ABERTA, $resposta->situacao);
        $this->assertSame('03399.85301 29700.000002 00726.101017 7 77550000005100', $resposta->linhaDigitavel);
        $this->assertSame('https://sandbox.api.pagseguro.com/boletos/BOL_1.pdf', $resposta->linkPagamento);
        $this->assertNull($resposta->qrCodePix);

        Http::assertSent(function (RequisicaoHttp $requisicao): bool {
            $boleto = $requisicao->data()['charges'][0]['payment_method']['boleto'];

            return $boleto['due_date'] === '2026-08-10'
                && $requisicao->data()['charges'][0]['amount'] === ['value' => 19990, 'currency' => 'BRL'];
        });
    }

    public function test_recusa_cobranca_avulsa_de_fatura_no_cartao(): void
    {
        Http::fake();

        $this->expectException(GatewayRecusouException::class);
        $this->expectExceptionMessageMatches('/cobrada automaticamente pela recorrência/');

        try {
            $this->gateway()->gerarCobranca($this->fatura(PagBankGateway::FORMA_CARTAO));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_consulta_cobranca_paga_devolve_o_dia_do_pagamento_sem_hora(): void
    {
        Http::fake([
            self::HOST_PEDIDOS.'/orders/*' => Http::response([
                'id' => 'ORDE_PIX',
                'charges' => [[
                    'id' => 'CHAR_1',
                    'status' => 'PAID',
                    'paid_at' => '2026-08-09T22:40:11.000-03:00',
                ]],
            ]),
        ]);

        $resposta = $this->gateway()->consultarCobranca('ORDE_PIX');

        $this->assertSame(RespostaDeCobranca::SITUACAO_PAGA, $resposta->situacao);
        $this->assertSame('2026-08-09', $resposta->pagoEm?->toDateString());
        $this->assertSame('00:00:00', $resposta->pagoEm?->toTimeString());
    }

    public function test_status_de_cobranca_desconhecido_nunca_vira_paga(): void
    {
        Http::fake([
            self::HOST_PEDIDOS.'/orders/*' => Http::response([
                'id' => 'ORDE_X',
                'charges' => [['id' => 'CHAR_1', 'status' => 'STATUS_QUE_NAO_EXISTE']],
            ]),
        ]);

        $resposta = $this->gateway()->consultarCobranca('ORDE_X');

        $this->assertSame(RespostaDeCobranca::SITUACAO_ABERTA, $resposta->situacao);
        $this->assertNull($resposta->pagoEm);
    }

    // -----------------------------------------------------------------
    // cancelarAssinatura e trocarPlano
    // -----------------------------------------------------------------

    public function test_cancela_assinatura_pelo_endpoint_do_provedor(): void
    {
        Http::fake([
            self::HOST_ASSINATURAS.'/subscriptions/SUBS_ABC/cancel' => Http::response([]),
        ]);

        $this->gateway()->cancelarAssinatura('SUBS_ABC');

        Http::assertSent(fn (RequisicaoHttp $requisicao): bool => $requisicao->method() === 'PUT'
            && Str::endsWith($requisicao->url(), '/subscriptions/SUBS_ABC/cancel'));
    }

    public function test_troca_de_plano_consulta_a_assinatura_quando_a_resposta_nao_traz_a_situacao(): void
    {
        Http::fake([
            self::HOST_ASSINATURAS.'/plans*' => Http::response(['plans' => [['id' => 'PLAN_NOVO']]]),
            self::HOST_ASSINATURAS.'/subscriptions/SUBS_ABC' => Http::sequence()
                ->push([])
                ->push(['id' => 'SUBS_ABC', 'status' => 'ACTIVE']),
        ]);

        $resposta = $this->gateway()->trocarPlano('SUBS_ABC', $this->plano('avancado'));

        $this->assertSame('SUBS_ABC', $resposta->idNoGateway);
        $this->assertSame(RespostaDeAssinatura::SITUACAO_ATIVA, $resposta->situacao);

        Http::assertSent(fn (RequisicaoHttp $requisicao): bool => $requisicao->method() === 'PUT'
            && $requisicao->data() === ['plan' => ['id' => 'PLAN_NOVO']]);
    }

    // -----------------------------------------------------------------
    // validarWebhook
    // -----------------------------------------------------------------

    public function test_valida_webhook_com_o_token_de_autenticidade_correto(): void
    {
        $corpo = '{"event":"subscription.canceled","resource":{"id":"SUBS_ABC"}}';
        $assinatura = hash('sha256', self::TOKEN_WEBHOOK.'-'.$corpo);

        $this->assertTrue($this->gateway()->validarWebhook($this->requisicaoDeWebhook($corpo, $assinatura)));
    }

    public function test_recusa_webhook_com_assinatura_errada(): void
    {
        $corpo = '{"event":"subscription.canceled"}';

        $this->assertFalse($this->gateway()->validarWebhook(
            $this->requisicaoDeWebhook($corpo, hash('sha256', 'token-errado-'.$corpo))
        ));
    }

    public function test_recusa_webhook_sem_o_cabecalho_de_autenticidade(): void
    {
        $this->assertFalse($this->gateway()->validarWebhook(
            $this->requisicaoDeWebhook('{"event":"subscription.canceled"}', null)
        ));
    }

    public function test_recusa_webhook_quando_o_token_nao_esta_configurado(): void
    {
        config(['services.pagbank.webhook_token' => null]);

        $corpo = '{"event":"subscription.canceled"}';

        $this->assertFalse($this->gateway()->validarWebhook(
            $this->requisicaoDeWebhook($corpo, hash('sha256', '-'.$corpo))
        ));
    }

    public function test_recusa_webhook_quando_o_corpo_foi_alterado_no_caminho(): void
    {
        $corpoOriginal = '{"event":"subscription.canceled","resource":{"id":"SUBS_ABC"}}';
        $assinatura = hash('sha256', self::TOKEN_WEBHOOK.'-'.$corpoOriginal);
        $corpoAdulterado = '{"event":"subscription.canceled","resource":{"id":"SUBS_OUTRA"}}';

        $this->assertFalse($this->gateway()->validarWebhook(
            $this->requisicaoDeWebhook($corpoAdulterado, $assinatura)
        ));
    }

    // -----------------------------------------------------------------
    // interpretarWebhook
    // -----------------------------------------------------------------

    public function test_interpreta_cancelamento_de_assinatura(): void
    {
        $evento = $this->gateway()->interpretarWebhook([
            'env' => 'sandbox',
            'event' => 'subscription.canceled',
            'resource' => [
                'id' => 'SUBS_ABC',
                'status' => 'CANCELED',
                'updated_at' => '2026-07-28T10:00:00.000-03:00',
            ],
        ]);

        $this->assertSame(EventoDeGateway::TIPO_ASSINATURA_CANCELADA, $evento->tipo);
        $this->assertSame('SUBS_ABC', $evento->faturaIdNoGateway);
        $this->assertStringContainsString('subscription.canceled', $evento->eventoId);
        $this->assertStringContainsString('SUBS_ABC', $evento->eventoId);
        $this->assertNull($evento->situacao);
    }

    public function test_interpreta_cobranca_paga_vinda_do_pedido(): void
    {
        $evento = $this->gateway()->interpretarWebhook([
            'id' => 'ORDE_PIX',
            'reference_id' => 'invoice-42',
            'charges' => [[
                'id' => 'CHAR_1',
                'status' => 'PAID',
                'paid_at' => '2026-08-09T22:40:11.000-03:00',
            ]],
        ]);

        $this->assertSame(EventoDeGateway::TIPO_COBRANCA_PAGA, $evento->tipo);
        $this->assertSame('ORDE_PIX', $evento->faturaIdNoGateway);
        $this->assertSame(RespostaDeCobranca::SITUACAO_PAGA, $evento->situacao);
        $this->assertSame('2026-08-09', $evento->pagoEm?->toDateString());
    }

    public function test_interpreta_cobranca_recusada_como_cancelada(): void
    {
        $evento = $this->gateway()->interpretarWebhook([
            'id' => 'ORDE_X',
            'charges' => [['id' => 'CHAR_1', 'status' => 'DECLINED']],
        ]);

        $this->assertSame(EventoDeGateway::TIPO_COBRANCA_CANCELADA, $evento->tipo);
        $this->assertSame(RespostaDeCobranca::SITUACAO_CANCELADA, $evento->situacao);
        $this->assertNull($evento->pagoEm);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function payloadsNaoMapeados(): array
    {
        return [
            'evento de assinatura sem equivalente no domínio' => [[
                'event' => 'subscription.recurrence',
                'resource' => ['id' => 'SUBS_ABC'],
            ]],
            'evento de plano' => [[
                'event' => 'plan.updated',
                'resource' => ['id' => 'PLAN_ABC'],
            ]],
            'evento inventado' => [[
                'event' => 'coisa.que.nao.existe',
                'resource' => ['id' => 'XPTO'],
            ]],
            'pedido em estado que não move fatura' => [[
                'id' => 'ORDE_X',
                'charges' => [['id' => 'CHAR_1', 'status' => 'IN_ANALYSIS']],
            ]],
            'pedido sem cobrança nenhuma' => [[
                'id' => 'ORDE_X',
                'charges' => [],
            ]],
            'payload vazio' => [[]],
            'payload de formato desconhecido' => [[
                'alguma_coisa' => 'que o provedor passou a mandar',
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('payloadsNaoMapeados')]
    public function test_payload_nao_mapeado_vira_desconhecido_sem_lancar(array $payload): void
    {
        $evento = $this->gateway()->interpretarWebhook($payload);

        $this->assertSame(EventoDeGateway::TIPO_DESCONHECIDO, $evento->tipo);
        $this->assertNotSame('', $evento->eventoId);
        $this->assertNull($evento->faturaIdNoGateway);
        $this->assertNull($evento->situacao);
        $this->assertNull($evento->pagoEm);
    }

    public function test_eventos_diferentes_do_mesmo_recurso_geram_chaves_de_deduplicacao_diferentes(): void
    {
        $gateway = $this->gateway();

        $pago = $gateway->interpretarWebhook([
            'id' => 'ORDE_X',
            'charges' => [['id' => 'CHAR_1', 'status' => 'PAID', 'paid_at' => '2026-08-09T10:00:00-03:00']],
        ]);

        $aguardando = $gateway->interpretarWebhook([
            'id' => 'ORDE_X',
            'charges' => [['id' => 'CHAR_1', 'status' => 'WAITING']],
        ]);

        $this->assertNotSame($pago->eventoId, $aguardando->eventoId);

        // O mesmo evento reenviado precisa cair na MESMA chave, senão a unique
        // de gateway_events não segura o processamento duplicado.
        $reenvio = $gateway->interpretarWebhook([
            'id' => 'ORDE_X',
            'charges' => [['id' => 'CHAR_1', 'status' => 'PAID', 'paid_at' => '2026-08-09T10:00:00-03:00']],
        ]);

        $this->assertSame($pago->eventoId, $reenvio->eventoId);
    }

    // -----------------------------------------------------------------
    // Ambiente
    // -----------------------------------------------------------------

    public function test_usa_a_url_de_producao_quando_o_sandbox_esta_desligado(): void
    {
        config(['services.pagbank.sandbox' => false]);

        Http::fake([
            'https://api.pagseguro.com/orders/*' => Http::response(['id' => 'ORDE_ABC', 'charges' => []]),
        ]);

        $this->gateway()->consultarCobranca('ORDE_ABC');

        Http::assertSent(fn (RequisicaoHttp $requisicao): bool => Str::startsWith($requisicao->url(), 'https://api.pagseguro.com/'));
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function gateway(): PagBankGateway
    {
        return new PagBankGateway;
    }

    private function empresa(): Company
    {
        $empresa = new Company([
            'name' => 'Dedetizadora Exemplo LTDA',
            'cnpj' => '12.345.678/0001-99',
            'email' => 'financeiro@exemplo.com.br',
            'phone' => '(11) 98888-7777',
            'street' => 'Rua das Palmeiras',
            'number' => '100',
            'complement' => 'Sala 3',
            'district' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'sp',
            'zip' => '01452-002',
        ]);

        $empresa->id = 7;

        return $empresa;
    }

    private function plano(string $slug = 'essencial'): Plan
    {
        $plano = new Plan([
            'nome' => 'Essencial',
            'slug' => $slug,
            'valor' => '199.90',
            'periodicidade' => 'mensal',
        ]);

        $plano->id = 3;

        return $plano;
    }

    private function fatura(string $formaPagamento): Invoice
    {
        $assinatura = new Subscription([
            'situacao' => 'ativa',
            'forma_pagamento' => $formaPagamento,
            'gateway' => PagBankGateway::NOME,
            'gateway_subscription_id' => 'SUBS_ABC',
            'inicio_em' => '2026-07-01',
        ]);

        $assinatura->id = 5;

        $fatura = new Invoice([
            'referencia' => '2026-08',
            'valor' => '199.90',
            'situacao' => 'aberta',
            'vencimento' => '2026-08-10',
        ]);

        $fatura->id = 42;
        $fatura->setRelation('company', $this->empresa());
        $fatura->setRelation('subscription', $assinatura);

        return $fatura;
    }

    private function requisicaoDeWebhook(string $corpo, ?string $assinatura): Request
    {
        $cabecalhos = $assinatura === null ? [] : ['HTTP_X_AUTHENTICITY_TOKEN' => $assinatura];

        return Request::create(
            '/webhooks/pagbank',
            'POST',
            [],
            [],
            [],
            $cabecalhos,
            $corpo
        );
    }
}
