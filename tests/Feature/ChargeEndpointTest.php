<?php

namespace Tests\Feature;

use App\Models\Charge;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Company;
use App\Models\CompanyBillingSetting;
use App\Models\GatewayEvent;
use App\Models\PaymentDetail;
use App\Models\PaymentGatewayConfig;
use App\Models\Receivable;
use App\Models\ReceivableInstallment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ModuleService;
use App\Services\Payments\GatewayPagBank;
use App\Services\TenantService;
use App\Support\Gateway\EventoDeCobranca;
use App\Support\TenantAtual;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 19.6 do Plano 19: endpoints de cobrança (`ChargeController`),
 * conciliação (`ConciliacaoService`) e pagamento no portal
 * (`PortalPagamentoController`).
 *
 * Cobre todos os critérios de aceitação da task: listagem com filtro e
 * situação, emitir/reemitir/cancelar pelos endpoints, as três divergências da
 * conciliação, reprocessar evento pendente, o portal mostrando link/linha
 * digitável/QR code, o cliente não vendo além do permitido pelo Plano 15, a
 * credencial nunca voltando na resposta, usuário sem `cobranca-configurar`
 * recebendo 403, e tenant sem o módulo vendo a página de indisponível.
 *
 * Nenhum teste toca a rede: todo tráfego passa por `Http::fake()` com
 * `preventStrayRequests()`, mesmo critério de `ChargeServiceTest` (Task
 * 19.3) e `GatewayDeCobrancaTest` (Task 19.2).
 */
class ChargeEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const HOST_SANDBOX = 'https://sandbox.api.pagseguro.com';

    private const SENHA_PORTAL = 'senha-teste-123';

    private ModuleService $moduleService;

    private TenantService $tenantService;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        Http::preventStrayRequests();

        $this->seed(RolesAndPermissionsSeeder::class);

        // Tenant fundador (id 1) entra no "Plano Interno", com todos os
        // módulos do catálogo liberados, inclusive `cobranca_recorrente`
        // (Task 19.6) - mesmo critério de `ModuloBloqueadoTest`/
        // `IsolamentoDoPortalTest`.
        $this->seed(ModulesSeeder::class);

        $this->moduleService = app(ModuleService::class);
        $this->tenantService = app(TenantService::class);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Listagem: filtra e mostra situação
    // -----------------------------------------------------------------

    public function test_lista_filtra_por_situacao_e_mostra_a_situacao_de_cada_cobranca(): void
    {
        $empresa = $this->empresaComModulo();
        $administrador = $this->administrador($empresa);
        $cliente = $this->clienteValido($empresa);

        $parcelaEmitida = $this->parcela($cliente, $empresa, ['vencimento' => '2026-08-20']);
        $parcelaPaga = $this->parcela($cliente, $empresa, ['vencimento' => '2026-08-10']);

        $this->comTenant($empresa, function () use ($parcelaEmitida, $parcelaPaga): void {
            Charge::create([
                'receivable_installment_id' => $parcelaEmitida->id,
                'tipo' => 'boleto',
                'gateway' => GatewayPagBank::NOME,
                'gateway_charge_id' => 'ORDE_EMITIDA',
                'valor' => '300.00',
                'vencimento' => '2026-08-20',
                'situacao' => 'emitida',
            ]);

            Charge::create([
                'receivable_installment_id' => $parcelaPaga->id,
                'tipo' => 'pix',
                'gateway' => GatewayPagBank::NOME,
                'gateway_charge_id' => 'ORDE_PAGA',
                'valor' => '300.00',
                'vencimento' => '2026-08-10',
                'situacao' => 'paga',
                'valor_pago' => '300.00',
                'pago_em' => '2026-08-09',
            ]);
        });

        $resposta = $this->actingAs($administrador)->getJson('/cobrancas?situacao=paga');
        $resposta->assertOk();

        $ids = array_column($resposta->json('cobrancas'), 'id');
        $situacoes = array_column($resposta->json('cobrancas'), 'situacao');

        $this->assertCount(1, $ids);
        $this->assertSame(['paga'], array_unique($situacoes));

        $semFiltro = $this->actingAs($administrador)->getJson('/cobrancas');
        $semFiltro->assertOk();
        $this->assertCount(2, $semFiltro->json('cobrancas'));
    }

    // -----------------------------------------------------------------
    // Emitir, reemitir e cancelar pelos endpoints
    // -----------------------------------------------------------------

    public function test_emitir_pelo_endpoint_grava_a_cobranca(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/orders' => Http::response([
                'id' => 'ORDE_END_1',
                'charges' => [[
                    'id' => 'CHAR_1',
                    'status' => 'WAITING',
                    'payment_method' => [
                        'type' => 'BOLETO',
                        'boleto' => ['barcode' => 'x', 'formatted_barcode' => 'linha-digitavel-1'],
                    ],
                ]],
            ]),
        ]);

        $empresa = $this->empresaComModulo();
        $this->configuracaoGateway($empresa);
        $administrador = $this->administrador($empresa);
        $cliente = $this->clienteValido($empresa);
        $parcela = $this->parcela($cliente, $empresa);

        $resposta = $this->actingAs($administrador)->postJson('/cobrancas', [
            'tipo' => 'boleto',
            'receivable_installment_id' => $parcela->id,
        ]);

        $resposta->assertCreated();
        $resposta->assertJsonPath('cobranca.situacao', 'emitida');
        $resposta->assertJsonPath('cobranca.linha_digitavel', 'linha-digitavel-1');

        $this->assertSame(1, $this->comTenant($empresa, fn () => Charge::query()->count()));
    }

    public function test_emitir_em_lote_pelo_endpoint_processa_cada_parcela(): void
    {
        $chamada = 0;

        Http::fake(function () use (&$chamada) {
            $chamada++;

            return Http::response([
                'id' => 'ORDE_LOTE_'.$chamada,
                'charges' => [[
                    'id' => 'CHAR_'.$chamada,
                    'status' => 'WAITING',
                    'payment_method' => ['type' => 'BOLETO', 'boleto' => ['barcode' => 'x', 'formatted_barcode' => 'y'.$chamada]],
                ]],
            ]);
        });

        $empresa = $this->empresaComModulo();
        $this->configuracaoGateway($empresa);
        $administrador = $this->administrador($empresa);

        $clienteValido = $this->clienteValido($empresa);
        $clienteSemDocumento = $this->clienteValido($empresa, ['cnpj' => '']);

        $parcelaOk = $this->parcela($clienteValido, $empresa);
        $parcelaComFalha = $this->parcela($clienteSemDocumento, $empresa);

        $resposta = $this->actingAs($administrador)->postJson('/cobrancas', [
            'tipo' => 'boleto',
            'receivable_installment_ids' => [$parcelaOk->id, $parcelaComFalha->id, 999999],
        ]);

        $resposta->assertOk();
        $resultados = $resposta->json('resultados');

        $this->assertCount(3, $resultados);
        $this->assertSame('emitida', $resultados[0]['situacao']);
        $this->assertSame('erro', $resultados[1]['situacao']);
        $this->assertSame('erro', $resultados[2]['situacao']);
        $this->assertSame(999999, $resultados[2]['receivable_installment_id']);
    }

    public function test_reemitir_e_cancelar_pelos_endpoints(): void
    {
        $chamadasDeEmissao = 0;

        Http::fake(function ($requisicao) use (&$chamadasDeEmissao) {
            if (str_ends_with($requisicao->url(), '/cancel')) {
                return Http::response([]);
            }

            $chamadasDeEmissao++;

            return Http::response([
                'id' => 'ORDE_R_'.$chamadasDeEmissao,
                'charges' => [[
                    'id' => 'CHAR_'.$chamadasDeEmissao,
                    'status' => 'WAITING',
                    'payment_method' => ['type' => 'BOLETO', 'boleto' => ['barcode' => 'x', 'formatted_barcode' => 'linha-'.$chamadasDeEmissao]],
                ]],
            ]);
        });

        $empresa = $this->empresaComModulo();
        $this->configuracaoGateway($empresa);
        $administrador = $this->administrador($empresa);
        $cliente = $this->clienteValido($empresa);
        $parcela = $this->parcela($cliente, $empresa);

        $emitida = $this->actingAs($administrador)->postJson('/cobrancas', [
            'tipo' => 'boleto',
            'receivable_installment_id' => $parcela->id,
        ])->json('cobranca');

        $reemitida = $this->actingAs($administrador)
            ->postJson("/cobrancas/{$emitida['id']}/reemitir")
            ->assertOk()
            ->json('cobranca');

        $this->assertSame('emitida', $reemitida['situacao']);
        $this->assertSame($emitida['id'], $reemitida['charge_anterior_id']);

        $anteriorCancelada = $this->comTenant($empresa, fn () => Charge::find($emitida['id']));
        $this->assertSame('cancelada', $anteriorCancelada->situacao);

        $canceladaPeloEndpoint = $this->actingAs($administrador)
            ->postJson("/cobrancas/{$reemitida['id']}/cancelar", ['motivo' => 'Cliente pagou em dinheiro'])
            ->assertOk()
            ->json('cobranca');

        $this->assertSame('cancelada', $canceladaPeloEndpoint['situacao']);
        $this->assertStringContainsString('Cliente pagou em dinheiro', $canceladaPeloEndpoint['erro_mensagem']);
    }

    public function test_cancelar_sem_motivo_devolve_422(): void
    {
        $empresa = $this->empresaComModulo();
        $this->configuracaoGateway($empresa);
        $administrador = $this->administrador($empresa);
        $cliente = $this->clienteValido($empresa);
        $parcela = $this->parcela($cliente, $empresa);

        $cobranca = $this->comTenant($empresa, fn () => Charge::create([
            'receivable_installment_id' => $parcela->id,
            'tipo' => 'boleto',
            'gateway' => GatewayPagBank::NOME,
            'gateway_charge_id' => 'ORDE_SEM_MOTIVO',
            'valor' => '300.00',
            'vencimento' => '2026-08-20',
            'situacao' => 'emitida',
        ]));

        $this->actingAs($administrador)
            ->postJson("/cobrancas/{$cobranca->id}/cancelar", ['motivo' => ''])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Conciliação: as três divergências
    // -----------------------------------------------------------------

    public function test_conciliacao_aponta_as_tres_divergencias(): void
    {
        $empresa = $this->empresaComModulo();
        $administrador = $this->administrador($empresa);
        $cliente = $this->clienteValido($empresa);

        $this->comTenant($empresa, function () use ($cliente, $empresa): void {
            $parcelaSemBaixa = $this->parcela($cliente, $empresa, ['vencimento' => '2026-08-05']);
            $parcelaBaixaManual = $this->parcela($cliente, $empresa, ['vencimento' => '2026-08-05']);
            $parcelaDivergente = $this->parcela($cliente, $empresa, ['vencimento' => '2026-08-05']);

            // 1) Evento de pagamento sem baixa correspondente: o gateway
            // avisou, mas a Charge nunca chegou a "paga" (ficou emitida - o
            // processamento falhou ou nunca rodou).
            Charge::create([
                'receivable_installment_id' => $parcelaSemBaixa->id,
                'tipo' => 'boleto',
                'gateway' => GatewayPagBank::NOME,
                'gateway_charge_id' => 'ORDE_SEM_BAIXA',
                'valor' => '300.00',
                'vencimento' => '2026-08-05',
                'situacao' => 'emitida',
            ]);

            GatewayEvent::create([
                'company_id' => $empresa->id,
                'gateway' => GatewayPagBank::NOME,
                'evento_id' => (string) Str::uuid(),
                'tipo' => EventoDeCobranca::TIPO_PAGA,
                'payload' => [
                    'id' => 'ORDE_SEM_BAIXA',
                    'charges' => [[
                        'id' => 'CHAR_SEM_BAIXA',
                        'status' => 'PAID',
                        'amount' => ['value' => 30000],
                        'paid_at' => '2026-08-05T10:00:00-03:00',
                    ]],
                ],
                'processado_em' => now(),
            ]);

            // 2) Baixa sem evento: recebimento manual, legítimo. Charge paga
            // sem nenhum GatewayEvent referenciando o mesmo gateway_charge_id.
            Charge::create([
                'receivable_installment_id' => $parcelaBaixaManual->id,
                'tipo' => 'boleto',
                'gateway' => GatewayPagBank::NOME,
                'gateway_charge_id' => 'ORDE_BAIXA_MANUAL',
                'valor' => '300.00',
                'vencimento' => '2026-08-05',
                'situacao' => 'paga',
                'valor_pago' => '300.00',
                'pago_em' => '2026-08-05',
            ]);

            // 3) Valor divergente: os dois lados concordam que pagou, mas por
            // valores diferentes.
            Charge::create([
                'receivable_installment_id' => $parcelaDivergente->id,
                'tipo' => 'boleto',
                'gateway' => GatewayPagBank::NOME,
                'gateway_charge_id' => 'ORDE_DIVERGENTE',
                'valor' => '300.00',
                'vencimento' => '2026-08-05',
                'situacao' => 'paga',
                'valor_pago' => '300.00',
                'pago_em' => '2026-08-05',
            ]);

            GatewayEvent::create([
                'company_id' => $empresa->id,
                'gateway' => GatewayPagBank::NOME,
                'evento_id' => (string) Str::uuid(),
                'tipo' => EventoDeCobranca::TIPO_PAGA,
                'payload' => [
                    'id' => 'ORDE_DIVERGENTE',
                    'charges' => [[
                        'id' => 'CHAR_DIVERGENTE',
                        'status' => 'PAID',
                        'amount' => ['value' => 25000], // R$ 250,00, o sistema baixou R$ 300,00
                        'paid_at' => '2026-08-05T10:00:00-03:00',
                    ]],
                ],
                'processado_em' => now(),
            ]);
        });

        $resposta = $this->actingAs($administrador)->getJson('/cobrancas/conciliacao?de=2026-08-01&ate=2026-08-10');
        $resposta->assertOk();

        $eventosSemBaixa = $resposta->json('eventos_sem_baixa');
        $baixasSemEvento = $resposta->json('baixas_sem_evento');
        $valoresDivergentes = $resposta->json('valores_divergentes');

        $this->assertCount(1, $eventosSemBaixa);
        $this->assertSame('ORDE_SEM_BAIXA', $eventosSemBaixa[0]['charge_id_no_gateway']);

        $this->assertCount(1, $baixasSemEvento);
        $this->assertSame('ORDE_BAIXA_MANUAL', $this->comTenant(
            $empresa,
            fn () => Charge::find($baixasSemEvento[0]['charge_id'])->gateway_charge_id
        ));
        $this->assertStringContainsString('manual', mb_strtolower($baixasSemEvento[0]['observacao'], 'UTF-8'));

        $this->assertCount(1, $valoresDivergentes);
        $this->assertSame('250.00', $valoresDivergentes[0]['valor_pago_no_gateway']);
        $this->assertSame('300.00', $valoresDivergentes[0]['valor_baixado_no_sistema']);
    }

    // -----------------------------------------------------------------
    // Reprocessar evento pendente
    // -----------------------------------------------------------------

    public function test_reprocessar_evento_pendente_aplica_a_baixa(): void
    {
        $empresa = $this->empresaComModulo();
        $administrador = $this->administrador($empresa);
        $cliente = $this->clienteValido($empresa);

        [$cobranca, $evento] = $this->comTenant($empresa, function () use ($cliente, $empresa) {
            $parcela = $this->parcela($cliente, $empresa, ['vencimento' => '2026-08-05']);

            $cobranca = Charge::create([
                'receivable_installment_id' => $parcela->id,
                'tipo' => 'boleto',
                'gateway' => GatewayPagBank::NOME,
                'gateway_charge_id' => 'ORDE_REPROCESSAR',
                'valor' => '300.00',
                'vencimento' => '2026-08-05',
                'situacao' => 'emitida',
            ]);

            $evento = GatewayEvent::create([
                'company_id' => $empresa->id,
                'gateway' => GatewayPagBank::NOME,
                'evento_id' => (string) Str::uuid(),
                'tipo' => EventoDeCobranca::TIPO_PAGA,
                'payload' => [
                    'id' => 'ORDE_REPROCESSAR',
                    'charges' => [[
                        'id' => 'CHAR_REPROCESSAR',
                        'status' => 'PAID',
                        'amount' => ['value' => 30000],
                        'paid_at' => '2026-08-05T10:00:00-03:00',
                    ]],
                ],
                'processado_em' => null,
            ]);

            return [$cobranca, $evento];
        });

        $resposta = $this->actingAs($administrador)->postJson("/cobrancas/conciliacao/eventos/{$evento->id}/reprocessar");
        $resposta->assertOk();

        $cobrancaFresca = $this->comTenant($empresa, fn () => $cobranca->fresh());
        $eventoFresco = $evento->fresh();

        $this->assertSame('paga', $cobrancaFresca->situacao);
        $this->assertNotNull($eventoFresco->processado_em);

        $parcelaFresca = $this->comTenant($empresa, fn () => $cobrancaFresca->receivableInstallment()->first());
        $this->assertSame('paga', $parcelaFresca->situacao);
    }

    public function test_reprocessar_evento_de_outra_empresa_devolve_404(): void
    {
        $empresaA = $this->empresaComModulo();
        $empresaB = $this->tenantService->criar([
            'name' => 'Outra Empresa Reprocessar',
            'plan_id' => null,
            'administrador_nome' => 'Administrador B',
            'administrador_email' => 'admin-b-reprocessar@exemplo.test',
        ]);
        $this->moduleService->liberarPara($empresaB, $this->moduloCobranca(), 'teste', null);

        $administradorA = $this->administrador($empresaA);

        $eventoDeB = TenantAtual::comTenant($empresaB->id, fn () => GatewayEvent::create([
            'company_id' => $empresaB->id,
            'gateway' => GatewayPagBank::NOME,
            'evento_id' => (string) Str::uuid(),
            'tipo' => EventoDeCobranca::TIPO_PAGA,
            'payload' => ['id' => 'ORDE_DE_B', 'charges' => [['id' => 'X', 'status' => 'PAID', 'amount' => ['value' => 100]]]],
            'processado_em' => null,
        ]));

        $this->actingAs($administradorA)
            ->postJson("/cobrancas/conciliacao/eventos/{$eventoDeB->id}/reprocessar")
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Configuração: credencial nunca volta, e só quem tem a permissão salva
    // -----------------------------------------------------------------

    public function test_credencial_nunca_volta_na_resposta_de_configuracao(): void
    {
        $empresa = $this->empresaComModulo();
        $segredo = 'segredo-ultra-secreto-nao-pode-vazar-123';
        $this->configuracaoGateway($empresa, $segredo);
        $administrador = $this->administrador($empresa);

        $resposta = $this->actingAs($administrador)->getJson('/cobrancas/configuracao');
        $resposta->assertOk();

        $resposta->assertJsonMissingPath('gateway.credenciais');
        $resposta->assertJsonMissingPath('gateway.webhook_token');
        $this->assertArrayNotHasKey('credenciais', $resposta->json('gateway'));

        $this->assertStringNotContainsString($segredo, $resposta->getContent());
        $this->assertTrue($resposta->json('gateway.possui_credencial'));

        // A resposta do PUT também nunca ecoa a credencial enviada.
        $respostaPut = $this->actingAs($administrador)->putJson('/cobrancas/configuracao', [
            'credenciais' => ['token' => 'novo-segredo-que-tambem-nao-pode-vazar'],
        ]);
        $respostaPut->assertOk();
        $this->assertStringNotContainsString('novo-segredo-que-tambem-nao-pode-vazar', $respostaPut->getContent());

        $configuracaoNoBanco = $this->comTenant($empresa, fn () => PaymentGatewayConfig::query()->first());
        $this->assertSame('novo-segredo-que-tambem-nao-pode-vazar', $configuracaoNoBanco->credenciais['token']);
    }

    public function test_usuario_sem_permissao_de_configurar_recebe_403_ao_salvar_credencial(): void
    {
        $empresa = $this->empresaComModulo();
        $usuarioFinanceiro = $this->usuarioComPapel($empresa, 'financeiro');

        $this->assertTrue($usuarioFinanceiro->can('cobranca-ver'));
        $this->assertTrue($usuarioFinanceiro->can('cobranca-emitir'));
        $this->assertFalse($usuarioFinanceiro->can('cobranca-configurar'));

        $resposta = $this->actingAs($usuarioFinanceiro)->putJson('/cobrancas/configuracao', [
            'gateway' => GatewayPagBank::NOME,
            'ambiente' => 'sandbox',
            'credenciais' => ['token' => 'tentativa-direta-no-endpoint'],
            'ativo' => true,
        ]);

        $resposta->assertStatus(403);

        $this->assertSame(0, $this->comTenant($empresa, fn () => PaymentGatewayConfig::query()->count()));
    }

    // -----------------------------------------------------------------
    // Tenant sem o módulo vê a página de indisponível
    // -----------------------------------------------------------------

    public function test_tenant_sem_modulo_ve_pagina_de_indisponivel(): void
    {
        $empresa = $this->tenantService->criar([
            'name' => 'Tenant Sem Cobranca Recorrente',
            'plan_id' => null,
            'administrador_nome' => 'Administrador Sem Cobranca',
            'administrador_email' => 'admin-sem-cobranca@exemplo.test',
        ]);
        $administrador = User::where('email', 'admin-sem-cobranca@exemplo.test')->firstOrFail();

        $this->assertFalse($this->moduleService->ativo('cobranca_recorrente', $empresa->fresh()));

        $paginaResposta = $this->actingAs($administrador)->get('/cobrancas');
        $paginaResposta->assertRedirect(route('modulo-indisponivel', ['modulo' => 'cobranca_recorrente']));

        $jsonResposta = $this->actingAs($administrador)->getJson('/cobrancas');
        $jsonResposta->assertStatus(403);
        $jsonResposta->assertJson(['modulo' => 'cobranca_recorrente']);
    }

    // -----------------------------------------------------------------
    // Portal: link, linha digitável, QR code, e nada além do permitido
    // -----------------------------------------------------------------

    public function test_portal_mostra_cobranca_ativa_da_fatura_com_link_linha_digitavel_e_qr_code(): void
    {
        $empresa = $this->empresaComModulo();
        [$cliente, $clientUser, $fatura] = $this->fixturePortal($empresa);

        $this->comTenant($empresa, function () use ($cliente, $empresa, $fatura): void {
            $parcela = $this->parcelaComPaymentDetail($cliente, $empresa, $fatura);

            Charge::create([
                'receivable_installment_id' => $parcela->id,
                'tipo' => 'pix',
                'gateway' => GatewayPagBank::NOME,
                'gateway_charge_id' => 'ORDE_PORTAL_1',
                'valor' => '300.00',
                'vencimento' => '2026-09-01',
                'situacao' => 'emitida',
                'link_pagamento' => 'https://sandbox.pagbank.test/pix/1',
                'linha_digitavel' => '03399.85301 29700.000002 00726.101017 7 77550000005100',
                'qr_code_pix' => '00020126...copia-e-cola',
            ]);
        });

        $this->autenticarPortal($clientUser);

        $resposta = $this->get('/portal/faturas');
        $resposta->assertOk();

        $faturas = $resposta->inertiaProps('faturas');
        $linha = collect($faturas['data'])->firstWhere('id', $fatura->id);

        $this->assertNotNull($linha, 'a fatura da fixture não apareceu na listagem do portal');
        $this->assertSame('https://sandbox.pagbank.test/pix/1', $linha['cobranca']['link_pagamento']);
        $this->assertSame(
            '03399.85301 29700.000002 00726.101017 7 77550000005100',
            $linha['cobranca']['linha_digitavel']
        );
        $this->assertSame('00020126...copia-e-cola', $linha['cobranca']['qr_code_pix']);
    }

    public function test_portal_nao_expoe_campo_alem_do_permitido_na_cobranca(): void
    {
        $empresa = $this->empresaComModulo();
        [$cliente, $clientUser, $fatura] = $this->fixturePortal($empresa);

        $this->comTenant($empresa, function () use ($cliente, $empresa, $fatura): void {
            $parcela = $this->parcelaComPaymentDetail($cliente, $empresa, $fatura);

            Charge::create([
                'receivable_installment_id' => $parcela->id,
                'tipo' => 'boleto',
                'gateway' => GatewayPagBank::NOME,
                'gateway_charge_id' => 'ORDE_PORTAL_CAMPOS',
                'valor' => '300.00',
                'vencimento' => '2026-09-01',
                'situacao' => 'emitida',
                'link_pagamento' => 'https://sandbox.pagbank.test/boleto/2.pdf',
                'linha_digitavel' => 'linha-2',
            ]);
        });

        $this->autenticarPortal($clientUser);

        $resposta = $this->get('/portal/faturas');
        $resposta->assertOk();

        $faturas = $resposta->inertiaProps('faturas');
        $linha = collect($faturas['data'])->firstWhere('id', $fatura->id);

        $this->assertEqualsCanonicalizing(
            \App\Support\CamposVisiveisAoCliente::COBRANCA,
            array_keys($linha['cobranca'])
        );

        // Regra do Plano 15, replicada para a cobrança: nada de gateway,
        // identificador no provedor, custo, margem ou comissão.
        foreach (['gateway', 'gateway_charge_id', 'custo', 'margem', 'comissao', 'company_id'] as $campoProibido) {
            $this->assertArrayNotHasKey($campoProibido, $linha['cobranca'], "o campo '{$campoProibido}' vazou na cobrança do portal");
        }
    }

    public function test_portal_cliente_gera_a_propria_cobranca_quando_empresa_libera(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/orders' => Http::response([
                'id' => 'ORDE_PORTAL_GERADA',
                'charges' => [[
                    'id' => 'CHAR_PORTAL',
                    'status' => 'WAITING',
                    'payment_method' => ['type' => 'BOLETO', 'boleto' => ['barcode' => 'x', 'formatted_barcode' => 'linha-portal']],
                ]],
            ]),
        ]);

        $empresa = $this->empresaComModulo();
        $this->configuracaoGateway($empresa);
        $this->comTenant($empresa, fn () => CompanyBillingSetting::create(['emissao_pelo_cliente_habilitada' => true]));

        [$cliente, $clientUser, $fatura] = $this->fixturePortal($empresa);
        $this->comTenant($empresa, fn () => $this->parcelaComPaymentDetail($cliente, $empresa, $fatura));

        $this->autenticarPortal($clientUser);

        $resposta = $this->postJson("/portal/faturas/{$fatura->id}/cobranca", ['tipo' => 'boleto']);
        $resposta->assertCreated();
        $resposta->assertJsonPath('cobranca.linha_digitavel', 'linha-portal');

        $this->assertSame(1, $this->comTenant($empresa, fn () => Charge::query()->count()));
    }

    public function test_portal_cliente_nao_gera_cobranca_quando_empresa_nao_libera(): void
    {
        $empresa = $this->empresaComModulo();
        $this->configuracaoGateway($empresa);
        // Sem CompanyBillingSetting nenhuma: `emissaoPeloClienteHabilitada()`
        // cai no padrão `false` (opt-in nunca ligado por padrão).

        [$cliente, $clientUser, $fatura] = $this->fixturePortal($empresa);
        $this->comTenant($empresa, fn () => $this->parcelaComPaymentDetail($cliente, $empresa, $fatura));

        $this->autenticarPortal($clientUser);

        $resposta = $this->postJson("/portal/faturas/{$fatura->id}/cobranca", ['tipo' => 'boleto']);
        $resposta->assertStatus(403);

        $this->assertSame(0, $this->comTenant($empresa, fn () => Charge::query()->count()));
    }

    public function test_portal_cliente_nao_gera_cobranca_de_fatura_de_outro_cliente(): void
    {
        $empresa = $this->empresaComModulo();
        $this->configuracaoGateway($empresa);
        $this->comTenant($empresa, fn () => CompanyBillingSetting::create(['emissao_pelo_cliente_habilitada' => true]));

        [$clienteA, $clientUserA, $faturaA] = $this->fixturePortal($empresa, 'A');
        [$clienteB, , $faturaB] = $this->fixturePortal($empresa, 'B');

        $this->comTenant($empresa, fn () => $this->parcelaComPaymentDetail($clienteB, $empresa, $faturaB));

        $this->autenticarPortal($clientUserA);

        $this->postJson("/portal/faturas/{$faturaB->id}/cobranca", ['tipo' => 'boleto'])
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Apoio: empresa, usuários, gateway, cliente, parcela
    // -----------------------------------------------------------------

    private function empresaComModulo(): Company
    {
        return Company::query()->firstOrFail()->fresh();
    }

    private function moduloCobranca(): \App\Models\Module
    {
        return \App\Models\Module::query()->where('chave', 'cobranca_recorrente')->firstOrFail();
    }

    private function administrador(Company $empresa): User
    {
        return $this->usuarioComPapel($empresa, 'administrador');
    }

    private function usuarioComPapel(Company $empresa, string $papel): User
    {
        $usuario = TenantAtual::comTenant(
            $empresa->id,
            fn (): User => User::factory()->create(['is_active' => true])
        );
        $usuario->assignRole($papel);

        return $usuario->fresh();
    }

    private function configuracaoGateway(Company $empresa, string $token = 'token-empresa'): PaymentGatewayConfig
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
     * @param  array<string, mixed>  $overrides
     */
    private function clienteValido(Company $empresa, array $overrides = []): Client
    {
        return TenantAtual::comTenant($empresa->id, function () use ($overrides) {
            $cliente = ClientFactory::new()->create(array_merge([
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

    /**
     * Parcela ligada a um `PaymentDetail` (a "fatura" do portal), como a
     * migração da Task 18.2 faz de verdade.
     */
    private function parcelaComPaymentDetail(Client $cliente, Company $empresa, PaymentDetail $fatura): ReceivableInstallment
    {
        return TenantAtual::comTenant($empresa->id, function () use ($cliente, $fatura) {
            $receivable = Receivable::create([
                'client_id' => $cliente->id,
                'descricao' => 'Serviço de dedetização',
                'valor_total' => 300.00,
                'emitido_em' => '2026-08-01',
                'situacao' => 'aberto',
            ]);

            return ReceivableInstallment::create([
                'receivable_id' => $receivable->id,
                'numero' => 1,
                'valor' => 300.00,
                'vencimento' => '2026-09-01',
                'valor_pago' => 0,
                'situacao' => 'aberta',
                'payment_detail_id' => $fatura->id,
            ]);
        });
    }

    /**
     * Cliente com cadastro válido para cobrança, endereço, `ClientUser` de
     * acesso ao portal e uma fatura (`PaymentDetail`) pendente e futura, para
     * `payment_status` sair `pending`.
     *
     * @return array{0: Client, 1: ClientUser, 2: PaymentDetail}
     */
    private function fixturePortal(Company $empresa, string $marca = ''): array
    {
        return TenantAtual::comTenant($empresa->id, function () use ($marca) {
            $cliente = ClientFactory::new()->create(['cnpj' => '111.444.777-35']);
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id, 'zip' => '01000-000']);

            $visita = WorkOrder::factory()->create([
                'client_id' => $cliente->id,
                'address_id' => $endereco->id,
                'order_number' => 'OT-PORTAL-'.$marca.'-'.Str::random(6),
                'scheduled_date' => '2026-08-01',
                'status' => 'scheduled',
            ]);

            $fatura = PaymentDetail::factory()->create([
                'work_order_id' => $visita->id,
                'payment_due_date' => '2026-09-01',
                'payment_date' => null,
            ]);

            $clientUser = ClientUser::create([
                'client_id' => $cliente->id,
                'nome' => 'Usuário do portal '.$marca,
                'email' => 'portal-cobranca-'.mb_strtolower($marca ?: 'x').'-'.Str::random(6).'@exemplo.test',
                'password' => Hash::make(self::SENHA_PORTAL),
                'ativo' => true,
                'email_verificado_em' => now(),
            ]);

            return [$cliente->fresh(), $clientUser->fresh(), $fatura->fresh()];
        });
    }

    private function autenticarPortal(ClientUser $clientUser): void
    {
        $this->post('/portal/login', [
            'email' => $clientUser->email,
            'password' => self::SENHA_PORTAL,
        ])->assertRedirect();
    }

    /**
     * @return mixed
     */
    private function comTenant(Company $empresa, \Closure $callback)
    {
        return TenantAtual::comTenant($empresa->id, $callback);
    }
}
