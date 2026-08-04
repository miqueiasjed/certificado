<?php

namespace Tests\Feature;

use App\Exceptions\GatewayCredencialInvalidaException;
use App\Exceptions\GatewayDadosClienteInvalidosException;
use App\Exceptions\GatewayIndisponivelException;
use App\Exceptions\GatewayNaoConfiguradoException;
use App\Exceptions\GatewayValorAbaixoDoMinimoException;
use App\Models\Company;
use App\Models\PaymentGatewayConfig;
use App\Services\Payments\GatewayDeCobranca;
use App\Services\Payments\GatewayPagBank;
use App\Services\Payments\ResolvedorDeGateway;
use App\Support\Gateway\RespostaDeCobranca;
use App\Support\TenantAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as RequisicaoHttp;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 19.2 do Plano 19: `GatewayDeCobranca`, `GatewayPagBank` e
 * `ResolvedorDeGateway` — a cobrança que o tenant emite ao cliente final dele,
 * com a própria credencial.
 *
 * Nenhum teste toca a rede: todo tráfego passa por `Http::fake()` com
 * `preventStrayRequests()`. O que está sendo coberto, além do caminho feliz de
 * emissão/consulta/cancelamento:
 *
 * - **Sem estado de tenant na instância**: a mesma instância de
 *   `GatewayPagBank` atende duas empresas em sequência, cada uma com o
 *   próprio token, sem vazar a credencial de uma para a outra.
 * - **`ResolvedorDeGateway` resolve pela empresa recebida**, não pelo tenant
 *   corrente da sessão — inclusive quando os dois divergem, o cenário de uma
 *   rotina em lote (Task 19.5).
 * - **Tenant sem configuração ativa** lança exceção clara, em português.
 * - **Erros do provedor traduzidos**: credencial inválida, dado do cliente
 *   incompleto e valor abaixo do mínimo geram exceções distintas; nenhuma
 *   delas é repetida. Falha de rede e 5xx viram indisponibilidade e são
 *   repetidos (2 tentativas no total).
 * - **Credencial nunca aparece em log**, em nenhum dos casos acima.
 * - **`validarCredenciais`/`ResolvedorDeGateway::validar()`** grava
 *   `verificado_em` só quando o provedor confirma a credencial.
 */
class GatewayDeCobrancaTest extends TestCase
{
    use RefreshDatabase;

    private const HOST_SANDBOX = 'https://sandbox.api.pagseguro.com';

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Interface: emissão, consulta e cancelamento
    // -----------------------------------------------------------------

    public function test_emite_boleto_com_linha_digitavel_e_link_de_pagamento(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/orders' => Http::response([
                'id' => 'ORDE_BOL_1',
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
                    'links' => [['rel' => 'PAY', 'href' => 'https://sandbox.pagbank.test/boleto/1.pdf']],
                ]],
            ]),
        ]);

        $configuracao = $this->configuracao($this->empresa(), 'token-empresa-um');

        $resposta = (new GatewayPagBank)->emitirBoleto(
            $configuracao,
            referencia: 'charge-1',
            descricao: 'Mensalidade agosto/2026',
            valor: '150.00',
            vencimento: '2026-08-20',
            cliente: $this->cliente(),
        );

        $this->assertSame('ORDE_BOL_1', $resposta->idNoGateway);
        $this->assertSame(RespostaDeCobranca::SITUACAO_ABERTA, $resposta->situacao);
        $this->assertSame('03399.85301 29700.000002 00726.101017 7 77550000005100', $resposta->linhaDigitavel);
        $this->assertSame('https://sandbox.pagbank.test/boleto/1.pdf', $resposta->linkPagamento);
        $this->assertNull($resposta->qrCodePix);

        Http::assertSent(function (RequisicaoHttp $requisicao): bool {
            $dados = $requisicao->data();

            return $requisicao->hasHeader('Authorization', 'Bearer token-empresa-um')
                && $dados['customer']['tax_id'] === '11144477735'
                && $dados['charges'][0]['amount'] === ['value' => 15000, 'currency' => 'BRL']
                && $dados['charges'][0]['payment_method']['boleto']['due_date'] === '2026-08-20';
        });
    }

    /**
     * Sem `notification_urls`, o PagBank nunca chama de volta o webhook da
     * Task 19.4: a cobrança é emitida normalmente, mas a baixa automática
     * nunca acontece e ninguém recebe erro para perceber. Este teste existe
     * porque essa lacuna passou despercebida pelos testes de emissão até a
     * revisão da Task 19.4 encontrá-la.
     */
    public function test_emissao_envia_notification_urls_com_o_webhook_token_do_tenant(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/orders' => Http::response([
                'id' => 'ORDE_BOL_2',
                'charges' => [[
                    'id' => 'CHAR_2',
                    'status' => 'WAITING',
                    'payment_method' => ['type' => 'BOLETO', 'boleto' => []],
                ]],
            ]),
        ]);

        $configuracao = $this->configuracao($this->empresa(), 'token-empresa-um');

        (new GatewayPagBank)->emitirBoleto(
            $configuracao,
            referencia: 'charge-2',
            descricao: 'Mensalidade agosto/2026',
            valor: '150.00',
            vencimento: '2026-08-20',
            cliente: $this->cliente(),
        );

        $urlEsperada = route('webhooks.cobranca', ['webhookToken' => $configuracao->webhook_token]);

        Http::assertSent(function (RequisicaoHttp $requisicao) use ($urlEsperada): bool {
            $dados = $requisicao->data();

            return isset($dados['notification_urls']) && $dados['notification_urls'] === [$urlEsperada];
        });
    }

    public function test_emite_pix_com_o_copia_e_cola_preenchido(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/orders' => Http::response([
                'id' => 'ORDE_PIX_1',
                'qr_codes' => [['id' => 'QRCO_1', 'text' => '00020126...copia-e-cola']],
            ]),
        ]);

        $configuracao = $this->configuracao($this->empresa(), 'token-empresa-um');

        $resposta = (new GatewayPagBank)->emitirPix(
            $configuracao,
            referencia: 'charge-2',
            descricao: 'Mensalidade agosto/2026',
            valor: '150.00',
            vencimento: '2026-08-20',
            cliente: $this->cliente(),
        );

        $this->assertSame('ORDE_PIX_1', $resposta->idNoGateway);
        $this->assertSame(RespostaDeCobranca::SITUACAO_ABERTA, $resposta->situacao);
        $this->assertSame('00020126...copia-e-cola', $resposta->qrCodePix);
        $this->assertNull($resposta->linhaDigitavel);

        Http::assertSent(function (RequisicaoHttp $requisicao): bool {
            $dados = $requisicao->data();

            return ! isset($dados['charges']) && $dados['qr_codes'][0]['amount']['value'] === 15000;
        });
    }

    public function test_consulta_cobranca_ja_paga(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/orders/ORDE_BOL_1' => Http::response([
                'id' => 'ORDE_BOL_1',
                'charges' => [[
                    'id' => 'CHAR_1',
                    'status' => 'PAID',
                    'paid_at' => '2026-08-10T10:00:00-03:00',
                    'payment_method' => ['type' => 'BOLETO', 'boleto' => []],
                ]],
            ]),
        ]);

        $configuracao = $this->configuracao($this->empresa(), 'token-empresa-um');

        $resposta = (new GatewayPagBank)->consultar($configuracao, 'ORDE_BOL_1');

        $this->assertSame(RespostaDeCobranca::SITUACAO_PAGA, $resposta->situacao);
        $this->assertNotNull($resposta->pagoEm);
        $this->assertSame('2026-08-10', $resposta->pagoEm->toDateString());
    }

    public function test_cancela_cobranca_chama_o_endpoint_correspondente(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/orders/ORDE_BOL_1/cancel' => Http::response([]),
        ]);

        $configuracao = $this->configuracao($this->empresa(), 'token-empresa-um');

        (new GatewayPagBank)->cancelar($configuracao, 'ORDE_BOL_1');

        Http::assertSent(fn (RequisicaoHttp $requisicao): bool => $requisicao->url() === self::HOST_SANDBOX.'/orders/ORDE_BOL_1/cancel'
            && $requisicao->method() === 'POST');
    }

    // -----------------------------------------------------------------
    // Erros distintos, em português, sem repetir erro de negócio
    // -----------------------------------------------------------------

    public function test_credencial_recusada_pelo_provedor_gera_excecao_distinta_e_nao_repete(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/orders' => Http::response([
                'error_messages' => [['code' => '40300', 'description' => 'Invalid credentials']],
            ], 401),
        ]);

        $configuracao = $this->configuracao($this->empresa(), 'token-invalido');

        try {
            (new GatewayPagBank)->emitirPix($configuracao, 'charge-3', 'Mensalidade', '150.00', '2026-08-20', $this->cliente());
            $this->fail('Era para ter lançado GatewayCredencialInvalidaException.');
        } catch (GatewayCredencialInvalidaException $excecao) {
            $this->assertStringContainsString('credencial', mb_strtolower($excecao->getMessage(), 'UTF-8'));
            $this->assertStringNotContainsString('token-invalido', $excecao->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_sem_token_configurado_recusa_antes_de_chamar_o_provedor(): void
    {
        Http::fake();

        $configuracao = $this->configuracao($this->empresa(), '');

        $this->expectException(GatewayCredencialInvalidaException::class);

        (new GatewayPagBank)->consultar($configuracao, 'ORDE_X');
    }

    public function test_dado_do_cliente_incompleto_gera_excecao_distinta_e_nao_repete(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/orders' => Http::response([
                'error_messages' => [[
                    'parameter_name' => 'customer.tax_id',
                    'description' => 'tax_id is required',
                ]],
            ], 422),
        ]);

        $configuracao = $this->configuracao($this->empresa(), 'token-empresa-um');

        try {
            (new GatewayPagBank)->emitirBoleto($configuracao, 'charge-4', 'Mensalidade', '150.00', '2026-08-20', $this->cliente());
            $this->fail('Era para ter lançado GatewayDadosClienteInvalidosException.');
        } catch (GatewayDadosClienteInvalidosException $excecao) {
            $this->assertStringContainsString('cliente', mb_strtolower($excecao->getMessage(), 'UTF-8'));
        }

        Http::assertSentCount(1);
    }

    public function test_valor_abaixo_do_minimo_gera_excecao_distinta_e_nao_repete(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/orders' => Http::response([
                'error_messages' => [[
                    'parameter_name' => 'charges[0].amount.value',
                    'description' => 'Amount must be greater than or equal to the minimum value',
                ]],
            ], 422),
        ]);

        $configuracao = $this->configuracao($this->empresa(), 'token-empresa-um');

        try {
            (new GatewayPagBank)->emitirBoleto($configuracao, 'charge-5', 'Mensalidade', '0.01', '2026-08-20', $this->cliente());
            $this->fail('Era para ter lançado GatewayValorAbaixoDoMinimoException.');
        } catch (GatewayValorAbaixoDoMinimoException $excecao) {
            $this->assertStringContainsString('mínimo', mb_strtolower($excecao->getMessage(), 'UTF-8'));
        }

        Http::assertSentCount(1);
    }

    public function test_erro_5xx_vira_indisponibilidade_e_e_repetido(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/orders/*' => Http::response(['message' => 'internal error'], 503),
        ]);

        $configuracao = $this->configuracao($this->empresa(), 'token-empresa-um');

        try {
            (new GatewayPagBank)->consultar($configuracao, 'ORDE_X');
            $this->fail('Era para ter lançado GatewayIndisponivelException.');
        } catch (GatewayIndisponivelException $excecao) {
            $this->assertStringContainsString('HTTP 503', $excecao->getMessage());
        }

        // 2 tentativas no total: a chamada original mais uma repetição.
        Http::assertSentCount(2);
    }

    public function test_falha_de_rede_vira_indisponibilidade_e_e_repetida(): void
    {
        $tentativas = 0;

        Http::fake(function () use (&$tentativas): never {
            $tentativas++;

            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $configuracao = $this->configuracao($this->empresa(), 'token-empresa-um');

        try {
            (new GatewayPagBank)->consultar($configuracao, 'ORDE_X');
            $this->fail('Era para ter lançado GatewayIndisponivelException.');
        } catch (GatewayIndisponivelException) {
            // Esperado.
        }

        $this->assertSame(2, $tentativas);
    }

    // -----------------------------------------------------------------
    // Credencial e dado do cliente nunca aparecem em log
    // -----------------------------------------------------------------

    public function test_nenhuma_credencial_ou_dado_do_cliente_aparece_no_log(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/orders' => Http::response([
                'error_messages' => [[
                    'parameter_name' => 'customer.tax_id',
                    'description' => 'tax_id is required for document 11144477735',
                ]],
            ], 422),
        ]);

        $token = 'token-super-secreto-nao-pode-vazar';
        $configuracao = $this->configuracao($this->empresa(), $token);

        $registros = [];

        Log::listen(function (MessageLogged $mensagem) use (&$registros): void {
            $registros[] = $mensagem;
        });

        try {
            (new GatewayPagBank)->emitirBoleto($configuracao, 'charge-6', 'Mensalidade', '150.00', '2026-08-20', $this->cliente());
        } catch (GatewayDadosClienteInvalidosException) {
            // Esperado: o que interessa aqui é o conteúdo do log.
        }

        $this->assertNotEmpty($registros, 'A chamada precisa deixar registro no log.');

        foreach ($registros as $registro) {
            $serializado = $registro->message.' '.json_encode($registro->context);

            $this->assertStringNotContainsString($token, $serializado);
            $this->assertStringNotContainsString('Bearer', $serializado);
            // Dado do cliente final (CPF) também não pode vazar para o log,
            // mesmo vindo embutido na mensagem de erro do provedor: o registro
            // da chamada não repassa o corpo da resposta.
            $this->assertStringNotContainsString('11144477735', $serializado);
        }
    }

    // -----------------------------------------------------------------
    // ResolvedorDeGateway
    // -----------------------------------------------------------------

    public function test_resolvedor_devolve_a_implementacao_do_gateway_configurado(): void
    {
        $empresa = $this->empresa();
        $this->configuracao($empresa, 'token-empresa-um');

        $gateway = (new ResolvedorDeGateway)->para($empresa);

        $this->assertInstanceOf(GatewayDeCobranca::class, $gateway);
        $this->assertInstanceOf(GatewayPagBank::class, $gateway);
    }

    public function test_empresa_sem_configuracao_lanca_excecao_clara(): void
    {
        $empresa = $this->empresa('Dedetizadora Sem Gateway');

        try {
            (new ResolvedorDeGateway)->para($empresa);
            $this->fail('Era para ter lançado GatewayNaoConfiguradoException.');
        } catch (GatewayNaoConfiguradoException $excecao) {
            $this->assertStringContainsString('Dedetizadora Sem Gateway', $excecao->getMessage());
            $this->assertStringContainsString('gateway de cobrança', mb_strtolower($excecao->getMessage(), 'UTF-8'));
        }
    }

    public function test_empresa_com_configuracao_inativa_lanca_excecao_clara(): void
    {
        $empresa = $this->empresa();
        $this->configuracao($empresa, 'token-empresa-um', ativo: false);

        $this->expectException(GatewayNaoConfiguradoException::class);

        (new ResolvedorDeGateway)->para($empresa);
    }

    /**
     * Regressão: `ResolvedorDeGateway::para()` recebe a empresa
     * explicitamente e precisa resolver certo mesmo quando o tenant corrente
     * (sessão, ou nenhum) é outro — o cenário de uma rotina que percorre
     * várias empresas (Task 19.5) sem que cada uma esteja "logada".
     */
    public function test_resolvedor_funciona_mesmo_com_o_tenant_corrente_sendo_outra_empresa(): void
    {
        $empresaA = $this->empresa('Empresa A');
        $empresaB = $this->empresa('Empresa B');
        $this->configuracao($empresaB, 'token-empresa-b');

        TenantAtual::definir($empresaA->id);

        $gateway = (new ResolvedorDeGateway)->para($empresaB);

        $this->assertInstanceOf(GatewayPagBank::class, $gateway);
    }

    public function test_validar_credenciais_grava_verificado_em_quando_o_provedor_confirma(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/public-keys' => Http::response(['public_key' => 'xyz']),
        ]);

        $configuracao = $this->configuracao($this->empresa(), 'token-empresa-um');
        $this->assertNull($configuracao->verificado_em);

        $valida = (new ResolvedorDeGateway)->validar($configuracao);

        $this->assertTrue($valida);
        $this->assertNotNull($configuracao->fresh()->verificado_em);
    }

    public function test_validar_credenciais_nao_grava_verificado_em_quando_o_provedor_recusa(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/public-keys' => Http::response(['error_messages' => []], 401),
        ]);

        $configuracao = $this->configuracao($this->empresa(), 'token-errado');

        $valida = (new ResolvedorDeGateway)->validar($configuracao);

        $this->assertFalse($valida);
        $this->assertNull($configuracao->fresh()->verificado_em);
    }

    public function test_validar_credenciais_propaga_indisponibilidade_sem_gravar_nada(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/public-keys' => Http::response(['message' => 'timeout'], 503),
        ]);

        $configuracao = $this->configuracao($this->empresa(), 'token-empresa-um');

        $this->expectException(GatewayIndisponivelException::class);

        try {
            (new ResolvedorDeGateway)->validar($configuracao);
        } finally {
            $this->assertNull($configuracao->fresh()->verificado_em);
        }
    }

    // -----------------------------------------------------------------
    // Sem estado de tenant na instância
    // -----------------------------------------------------------------

    /**
     * O critério mais grave da Task 19.2: a mesma instância de
     * `GatewayPagBank`, chamada duas vezes seguidas para empresas
     * diferentes, tem que usar a credencial de cada uma — nunca a da
     * chamada anterior. É o que impede a cobrança de um tenant de sair na
     * conta de outro numa rotina que percorre vários.
     */
    public function test_duas_chamadas_seguidas_para_tenants_diferentes_usam_credenciais_diferentes(): void
    {
        $empresaA = $this->empresa('Empresa A');
        $empresaB = $this->empresa('Empresa B');

        $configuracaoA = $this->configuracao($empresaA, 'token-empresa-a');
        $configuracaoB = $this->configuracao($empresaB, 'token-empresa-b');

        $autorizacoesRecebidas = [];

        Http::fake(function (RequisicaoHttp $requisicao) use (&$autorizacoesRecebidas) {
            $autorizacoesRecebidas[] = $requisicao->header('Authorization')[0] ?? null;

            return Http::response(['id' => 'ORDE_X', 'charges' => []]);
        });

        // Mesma instância reaproveitada de propósito, exatamente como
        // aconteceria numa rotina que percorre várias empresas.
        $gateway = new GatewayPagBank;

        $gateway->consultar($configuracaoA, 'ORDE_A');
        $gateway->consultar($configuracaoB, 'ORDE_B');

        $this->assertSame('Bearer token-empresa-a', $autorizacoesRecebidas[0]);
        $this->assertSame('Bearer token-empresa-b', $autorizacoesRecebidas[1]);
        $this->assertNotSame($autorizacoesRecebidas[0], $autorizacoesRecebidas[1]);
    }

    public function test_ambiente_sandbox_e_producao_usam_hosts_diferentes(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/orders/*' => Http::response(['id' => 'ORDE_SANDBOX', 'charges' => []]),
            'https://api.pagseguro.com/orders/*' => Http::response(['id' => 'ORDE_PRODUCAO', 'charges' => []]),
        ]);

        $empresa = $this->empresa();
        $sandbox = $this->configuracao($empresa, 'token-sandbox', ambiente: 'sandbox');

        $gateway = new GatewayPagBank;
        $gateway->consultar($sandbox, 'ORDE_1');

        Http::assertSent(fn (RequisicaoHttp $requisicao): bool => str_starts_with($requisicao->url(), self::HOST_SANDBOX));

        $producao = $this->configuracao($this->empresa('Outra Empresa'), 'token-producao', ambiente: 'producao');

        $gateway->consultar($producao, 'ORDE_2');

        Http::assertSent(fn (RequisicaoHttp $requisicao): bool => str_starts_with($requisicao->url(), 'https://api.pagseguro.com'));
    }

    // -----------------------------------------------------------------
    // Auxiliares
    // -----------------------------------------------------------------

    private function empresa(string $nome = 'Dedetizadora Teste'): Company
    {
        return Company::create(['name' => $nome]);
    }

    /**
     * `company_id` não está em `$fillable` de `PaymentGatewayConfig` (é
     * `BelongsToCompany` quem o preenche, a partir do tenant corrente), por
     * isso a criação precisa acontecer dentro de `TenantAtual::comTenant()` —
     * mesmo padrão já usado no restante da suíte (ex.: `StockServiceTest`).
     */
    private function configuracao(
        Company $empresa,
        string $token,
        string $ambiente = 'sandbox',
        bool $ativo = true,
    ): PaymentGatewayConfig {
        return TenantAtual::comTenant($empresa->id, fn (): PaymentGatewayConfig => PaymentGatewayConfig::create([
            'gateway' => GatewayPagBank::NOME,
            'ambiente' => $ambiente,
            'credenciais' => ['token' => $token],
            'webhook_token' => Str::random(40),
            'ativo' => $ativo,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function cliente(): array
    {
        return [
            'nome' => 'Cliente Final de Teste',
            'documento' => '111.444.777-35',
            'email' => 'cliente@teste.test',
            'endereco' => [
                'rua' => 'Rua Teste',
                'numero' => '100',
                'complemento' => '',
                'bairro' => 'Centro',
                'cidade' => 'São Paulo',
                'uf' => 'sp',
                'cep' => '01000-000',
            ],
        ];
    }
}
