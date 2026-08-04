<?php

namespace Tests\Feature;

use App\Exceptions\CobrancaAtivaExistenteException;
use App\Exceptions\CobrancaJaPagaException;
use App\Exceptions\DadosDeCobrancaInvalidosException;
use App\Exceptions\GatewayDadosClienteInvalidosException;
use App\Models\Charge;
use App\Models\Client;
use App\Models\Company;
use App\Models\NotificationQueue;
use App\Models\PaymentGatewayConfig;
use App\Models\Receivable;
use App\Models\ReceivableInstallment;
use App\Services\ChargeService;
use App\Services\Payments\GatewayPagBank;
use App\Services\Payments\ValidadorDeDadosDeCobranca;
use App\Support\EventosDeNotificacao;
use App\Support\TenantAtual;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as RequisicaoHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 19.3 do Plano 19: `ChargeService`, `ValidadorDeDadosDeCobranca` e
 * `StoreChargeRequest` — emissão de boleto e Pix a partir de uma parcela a
 * receber, com a regra crítica de uma cobrança ativa por parcela.
 *
 * Nenhum teste toca a rede: todo tráfego passa por `Http::fake()` com
 * `preventStrayRequests()`, mesmo critério de `GatewayDeCobrancaTest`
 * (Task 19.2).
 */
class ChargeServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HOST_SANDBOX = 'https://sandbox.api.pagseguro.com';

    private ChargeService $servico;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        Http::preventStrayRequests();

        $this->servico = app(ChargeService::class);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Emissão: boleto e Pix
    // -----------------------------------------------------------------

    public function test_emitir_boleto_grava_link_e_linha_digitavel(): void
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

        $empresa = $this->empresa();
        $this->configuracao($empresa);
        $cliente = $this->clienteValido($empresa);
        $parcela = $this->parcela($cliente, $empresa);

        $cobranca = $this->comTenant($empresa, fn () => $this->servico->emitir($parcela, 'boleto'));

        $this->assertSame('emitida', $cobranca->situacao);
        $this->assertSame('boleto', $cobranca->tipo);
        $this->assertSame('ORDE_BOL_1', $cobranca->gateway_charge_id);
        $this->assertSame('https://sandbox.pagbank.test/boleto/1.pdf', $cobranca->link_pagamento);
        $this->assertSame(
            '03399.85301 29700.000002 00726.101017 7 77550000005100',
            $cobranca->linha_digitavel
        );
        $this->assertNull($cobranca->qr_code_pix);
        $this->assertSame('300.00', $cobranca->valor);

        $this->assertSame(
            1,
            NotificationQueue::where('evento', EventosDeNotificacao::COBRANCA_EMITIDA)->count(),
            'o aviso de cobrança emitida não foi enfileirado'
        );
    }

    public function test_emitir_pix_grava_o_qr_code(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/orders' => Http::response([
                'id' => 'ORDE_PIX_1',
                'qr_codes' => [['id' => 'QRCO_1', 'text' => '00020126...copia-e-cola']],
            ]),
        ]);

        $empresa = $this->empresa();
        $this->configuracao($empresa);
        $cliente = $this->clienteValido($empresa);
        $parcela = $this->parcela($cliente, $empresa);

        $cobranca = $this->comTenant($empresa, fn () => $this->servico->emitir($parcela, 'pix'));

        $this->assertSame('emitida', $cobranca->situacao);
        $this->assertSame('pix', $cobranca->tipo);
        $this->assertSame('00020126...copia-e-cola', $cobranca->qr_code_pix);
        $this->assertNull($cobranca->linha_digitavel);
    }

    // -----------------------------------------------------------------
    // Dados do cliente, validados antes do gateway
    // -----------------------------------------------------------------

    public function test_cliente_sem_cpf_ou_cnpj_e_recusado_antes_do_gateway_com_a_lista_do_que_falta(): void
    {
        Http::fake();

        $empresa = $this->empresa();
        $this->configuracao($empresa);
        $cliente = $this->clienteValido($empresa, ['cnpj' => '']);
        $parcela = $this->parcela($cliente, $empresa);

        try {
            $this->comTenant($empresa, fn () => $this->servico->emitir($parcela, 'boleto'));
            $this->fail('Era para ter lançado DadosDeCobrancaInvalidosException.');
        } catch (DadosDeCobrancaInvalidosException $excecao) {
            $this->assertContains('CPF ou CNPJ do cliente', $excecao->faltando);
        }

        Http::assertNothingSent();
    }

    public function test_cliente_com_endereco_sem_cep_e_recusado_antes_do_gateway(): void
    {
        Http::fake();

        $empresa = $this->empresa();
        $this->configuracao($empresa);

        $cliente = $this->comTenant($empresa, function () {
            $cliente = ClientFactory::new()->create(['cnpj' => '111.444.777-35']);
            AddressFactory::new()->create(['client_id' => $cliente->id, 'zip' => '']);

            return $cliente->fresh();
        });

        $parcela = $this->parcela($cliente, $empresa);

        try {
            $this->comTenant($empresa, fn () => $this->servico->emitir($parcela, 'boleto'));
            $this->fail('Era para ter lançado DadosDeCobrancaInvalidosException.');
        } catch (DadosDeCobrancaInvalidosException $excecao) {
            $this->assertContains('CEP do endereço do cliente', $excecao->faltando);
        }

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Uma cobrança ativa por parcela
    // -----------------------------------------------------------------

    public function test_parcela_com_cobranca_ativa_recusa_nova_emissao(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/orders' => Http::response(['id' => 'ORDE_1', 'charges' => []]),
        ]);

        $empresa = $this->empresa();
        $this->configuracao($empresa);
        $cliente = $this->clienteValido($empresa);
        $parcela = $this->parcela($cliente, $empresa);

        $primeira = $this->comTenant($empresa, fn () => $this->servico->emitir($parcela, 'boleto'));
        $this->assertSame('emitida', $primeira->situacao);

        try {
            $this->comTenant($empresa, fn () => $this->servico->emitir($parcela->fresh(), 'boleto'));
            $this->fail('Era para ter lançado CobrancaAtivaExistenteException.');
        } catch (CobrancaAtivaExistenteException $excecao) {
            $this->assertSame($primeira->id, $excecao->cobrancaAtiva->id);
        }

        // Só a primeira emissão chegou a chamar o gateway.
        Http::assertSentCount(1);
        $this->assertSame(1, Charge::query()->count());
    }

    // -----------------------------------------------------------------
    // Falha do gateway grava erro, nunca falha em silêncio
    // -----------------------------------------------------------------

    public function test_falha_do_gateway_grava_situacao_erro_com_a_mensagem_traduzida(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/orders' => Http::response([
                'error_messages' => [[
                    'parameter_name' => 'customer.tax_id',
                    'description' => 'tax_id is required',
                ]],
            ], 422),
        ]);

        $empresa = $this->empresa();
        $this->configuracao($empresa);
        $cliente = $this->clienteValido($empresa);
        $parcela = $this->parcela($cliente, $empresa);

        try {
            $this->comTenant($empresa, fn () => $this->servico->emitir($parcela, 'boleto'));
            $this->fail('Era para ter lançado GatewayDadosClienteInvalidosException.');
        } catch (GatewayDadosClienteInvalidosException $excecao) {
            $this->assertStringContainsString(
                'cliente',
                mb_strtolower($excecao->getMessage(), 'UTF-8')
            );
        }

        $cobranca = Charge::query()->latest('id')->first();

        $this->assertNotNull($cobranca, 'a tentativa falha precisa deixar um registro de Charge');
        $this->assertSame('erro', $cobranca->situacao);
        $this->assertNotNull($cobranca->erro_mensagem);
        $this->assertStringContainsString('cliente', mb_strtolower($cobranca->erro_mensagem, 'UTF-8'));
    }

    // -----------------------------------------------------------------
    // Reemissão
    // -----------------------------------------------------------------

    public function test_reemitir_cancela_a_anterior_no_gateway_e_vincula_as_duas(): void
    {
        $chamadasDeEmissao = 0;

        Http::fake(function (RequisicaoHttp $requisicao) use (&$chamadasDeEmissao) {
            if (str_ends_with($requisicao->url(), '/orders/ORDE_1/cancel')) {
                return Http::response([]);
            }

            $chamadasDeEmissao++;

            return Http::response([
                'id' => 'ORDE_'.$chamadasDeEmissao,
                'charges' => [[
                    'id' => 'CHAR_'.$chamadasDeEmissao,
                    'status' => 'WAITING',
                    'payment_method' => [
                        'type' => 'BOLETO',
                        'boleto' => ['barcode' => 'x', 'formatted_barcode' => 'linha-'.$chamadasDeEmissao],
                    ],
                ]],
            ]);
        });

        $empresa = $this->empresa();
        $this->configuracao($empresa);
        $cliente = $this->clienteValido($empresa);
        $parcela = $this->parcela($cliente, $empresa);

        $antiga = $this->comTenant($empresa, fn () => $this->servico->emitir($parcela, 'boleto'));
        $this->assertSame('ORDE_1', $antiga->gateway_charge_id);

        $nova = $this->comTenant($empresa, fn () => $this->servico->reemitir($antiga));

        $this->assertSame('emitida', $nova->situacao);
        $this->assertSame('ORDE_2', $nova->gateway_charge_id);
        $this->assertSame($antiga->id, $nova->charge_anterior_id);

        $antigaFresca = $antiga->fresh();
        $this->assertSame('cancelada', $antigaFresca->situacao);

        Http::assertSent(fn (RequisicaoHttp $requisicao): bool => str_ends_with($requisicao->url(), '/orders/ORDE_1/cancel')
            && $requisicao->method() === 'POST');
    }

    // -----------------------------------------------------------------
    // Cobrança paga não é cancelável nem reemitida
    // -----------------------------------------------------------------

    public function test_cobranca_paga_nao_e_cancelavel_nem_reemitida(): void
    {
        Http::fake();

        $empresa = $this->empresa();
        $this->configuracao($empresa);
        $cliente = $this->clienteValido($empresa);
        $parcela = $this->parcela($cliente, $empresa, ['situacao' => 'paga', 'valor_pago' => 300.00]);

        $cobranca = $this->comTenant($empresa, fn () => Charge::create([
            'receivable_installment_id' => $parcela->id,
            'tipo' => 'boleto',
            'gateway' => GatewayPagBank::NOME,
            'gateway_charge_id' => 'ORDE_PAGA',
            'valor' => '300.00',
            'vencimento' => '2026-08-20',
            'situacao' => 'paga',
            'valor_pago' => '300.00',
            'pago_em' => '2026-08-15',
        ]));

        try {
            $this->comTenant($empresa, fn () => $this->servico->cancelar($cobranca, 'Cliente pediu estorno'));
            $this->fail('Era para ter lançado CobrancaJaPagaException.');
        } catch (CobrancaJaPagaException $excecao) {
            $this->assertSame($cobranca->id, $excecao->cobranca->id);
        }

        try {
            $this->comTenant($empresa, fn () => $this->servico->reemitir($cobranca));
            $this->fail('Era para ter lançado CobrancaJaPagaException.');
        } catch (CobrancaJaPagaException) {
            // Esperado.
        }

        Http::assertNothingSent();
        $this->assertSame('paga', $cobranca->fresh()->situacao);
    }

    public function test_cancelar_marca_cancelada_e_grava_o_motivo(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/orders/ORDE_1/cancel' => Http::response([]),
        ]);

        $empresa = $this->empresa();
        $this->configuracao($empresa);
        $cliente = $this->clienteValido($empresa);
        $parcela = $this->parcela($cliente, $empresa);

        $cobranca = $this->comTenant($empresa, fn () => Charge::create([
            'receivable_installment_id' => $parcela->id,
            'tipo' => 'boleto',
            'gateway' => GatewayPagBank::NOME,
            'gateway_charge_id' => 'ORDE_1',
            'valor' => '300.00',
            'vencimento' => '2026-08-20',
            'situacao' => 'emitida',
        ]));

        $cancelada = $this->comTenant($empresa, fn () => $this->servico->cancelar($cobranca, 'Cliente pagou em dinheiro'));

        $this->assertSame('cancelada', $cancelada->situacao);
        $this->assertStringContainsString('Cliente pagou em dinheiro', $cancelada->erro_mensagem);

        Http::assertSent(fn (RequisicaoHttp $requisicao): bool => str_ends_with($requisicao->url(), '/orders/ORDE_1/cancel'));
    }

    // -----------------------------------------------------------------
    // Emissão em lote
    // -----------------------------------------------------------------

    public function test_emissao_em_lote_devolve_resultado_individual_por_parcela(): void
    {
        $chamada = 0;

        Http::fake(function () use (&$chamada) {
            $chamada++;

            return Http::response([
                'id' => 'ORDE_LOTE_'.$chamada,
                'charges' => [[
                    'id' => 'CHAR_'.$chamada,
                    'status' => 'WAITING',
                    'payment_method' => [
                        'type' => 'BOLETO',
                        'boleto' => ['barcode' => 'x', 'formatted_barcode' => 'y'.$chamada],
                    ],
                ]],
            ]);
        });

        $empresa = $this->empresa();
        $this->configuracao($empresa);

        $clienteValido = $this->clienteValido($empresa);
        $clienteSemDocumento = $this->clienteValido($empresa, ['cnpj' => '']);

        $parcelaOk = $this->parcela($clienteValido, $empresa);
        $parcelaComFalha = $this->parcela($clienteSemDocumento, $empresa);

        $resultados = $this->comTenant(
            $empresa,
            fn () => $this->servico->emitirEmLote([$parcelaOk, $parcelaComFalha], 'boleto')
        );

        $this->assertCount(2, $resultados);

        $this->assertSame($parcelaOk->id, $resultados[0]['receivable_installment_id']);
        $this->assertSame('emitida', $resultados[0]['situacao']);
        $this->assertNotNull($resultados[0]['charge_id']);
        $this->assertNull($resultados[0]['mensagem']);

        $this->assertSame($parcelaComFalha->id, $resultados[1]['receivable_installment_id']);
        $this->assertSame('erro', $resultados[1]['situacao']);
        $this->assertNull($resultados[1]['charge_id']);
        $this->assertStringContainsString('CPF ou CNPJ', (string) $resultados[1]['mensagem']);

        // A falha da segunda parcela não impediu a primeira de ser emitida.
        $this->assertSame(1, Charge::query()->where('situacao', 'emitida')->count());
    }

    // -----------------------------------------------------------------
    // ValidadorDeDadosDeCobranca isolado
    // -----------------------------------------------------------------

    public function test_validador_aponta_tudo_que_falta_no_cadastro_do_cliente(): void
    {
        $validador = app(ValidadorDeDadosDeCobranca::class);
        $empresa = $this->empresa();

        $cliente = $this->comTenant($empresa, fn () => ClientFactory::new()->create([
            'name' => '',
            'cnpj' => '11.222.333/0001-99', // dígito verificador errado
            'email' => 'nao-e-email',
            'email_notificacao' => null,
        ]));

        $faltando = $validador->validar($cliente);

        $this->assertContains('nome do cliente', $faltando);
        $this->assertContains('CPF ou CNPJ do cliente (documento inválido)', $faltando);
        $this->assertContains('e-mail do cliente', $faltando);
        $this->assertContains('endereço do cliente', $faltando);
    }

    public function test_validador_aceita_cliente_com_cpf_valido_e_endereco_completo(): void
    {
        $validador = app(ValidadorDeDadosDeCobranca::class);
        $empresa = $this->empresa();
        $cliente = $this->clienteValido($empresa);

        $this->assertSame([], $validador->validar($cliente));
        $this->assertTrue($validador->valido($cliente));
    }

    public function test_validador_aceita_cnpj_valido(): void
    {
        $validador = app(ValidadorDeDadosDeCobranca::class);
        $empresa = $this->empresa();

        $cliente = $this->comTenant($empresa, function () {
            $cliente = ClientFactory::new()->create(['cnpj' => '11.222.333/0001-81']);
            AddressFactory::new()->create(['client_id' => $cliente->id, 'zip' => '01000-000']);

            return $cliente->fresh();
        });

        $this->assertTrue($validador->valido($cliente));
    }

    // -----------------------------------------------------------------
    // Auxiliares
    // -----------------------------------------------------------------

    private function empresa(string $nome = 'Dedetizadora Teste'): Company
    {
        return Company::create(['name' => $nome]);
    }

    private function configuracao(Company $empresa, string $token = 'token-empresa'): PaymentGatewayConfig
    {
        return TenantAtual::comTenant($empresa->id, fn (): PaymentGatewayConfig => PaymentGatewayConfig::create([
            'gateway' => GatewayPagBank::NOME,
            'ambiente' => 'sandbox',
            'credenciais' => ['token' => $token],
            'webhook_token' => Str::random(40),
            'ativo' => true,
        ]));
    }

    /**
     * Cliente com nome, CPF válido, e-mail e endereço com CEP: passa em
     * `ValidadorDeDadosDeCobranca` sem pendência. `$overrides` permite
     * quebrar deliberadamente um dos campos em um teste específico.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function clienteValido(Company $empresa, array $overrides = []): Client
    {
        return TenantAtual::comTenant($empresa->id, function () use ($overrides) {
            $cliente = ClientFactory::new()->create(array_merge([
                // CPF real (mesmo usado em GatewayDeCobrancaTest), com dígito
                // verificador válido.
                'cnpj' => '111.444.777-35',
            ], $overrides));

            AddressFactory::new()->create([
                'client_id' => $cliente->id,
                'zip' => '01000-000',
            ]);

            return $cliente->fresh();
        });
    }

    /**
     * Parcela única de um título simples, para o cliente informado.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function parcela(Client $cliente, Company $empresa, array $overrides = []): ReceivableInstallment
    {
        return TenantAtual::comTenant($empresa->id, function () use ($cliente, $overrides) {
            $valor = $overrides['valor'] ?? 300.00;

            $receivable = Receivable::create([
                'client_id' => $cliente->id,
                'descricao' => 'Serviço de dedetização',
                'valor_total' => $valor,
                'emitido_em' => '2026-08-01',
                'situacao' => 'aberto',
            ]);

            return ReceivableInstallment::create([
                'receivable_id' => $receivable->id,
                'numero' => 1,
                'valor' => $valor,
                'vencimento' => $overrides['vencimento'] ?? '2026-08-20',
                'valor_pago' => $overrides['valor_pago'] ?? 0,
                'situacao' => $overrides['situacao'] ?? 'aberta',
            ]);
        });
    }

    private function comTenant(Company $empresa, \Closure $callback): mixed
    {
        return TenantAtual::comTenant($empresa->id, $callback);
    }
}
