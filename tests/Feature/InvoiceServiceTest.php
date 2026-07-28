<?php

namespace Tests\Feature;

use App\Contracts\GatewayAssinatura;
use App\Exceptions\GatewayIndisponivelException;
use App\Exceptions\GatewayRecusouException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\InvoiceService;
use App\Services\SubscriptionService;
use App\Services\TenantService;
use App\Support\BusinessDate;
use App\Support\Gateway\EventoDeGateway;
use App\Support\Gateway\RespostaDeAssinatura;
use App\Support\Gateway\RespostaDeCobranca;
use App\Support\TenantAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Task 7.5 do Plano 7: geração de fatura por período.
 *
 * O conjunto completo (webhook, régua de inadimplência, imunidade do tenant
 * interno em todos os caminhos) é a Task 7.11. Este arquivo trava agora os
 * critérios da 7.5, que são os que decidem se alguém é cobrado duas vezes ou
 * bloqueado sem dever: idempotência da geração, isolamento do erro do provedor,
 * tenant interno fora da cobrança e reativação condicionada ao motivo da
 * suspensão.
 *
 * O provedor nunca é chamado de verdade: `GatewayDeCobrancaDeTeste` fica no
 * lugar de `GatewayAssinatura` no container.
 */
class InvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private GatewayDeCobrancaDeTeste $gateway;

    private InvoiceService $invoiceService;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->gateway = new GatewayDeCobrancaDeTeste;
        $this->app->instance(GatewayAssinatura::class, $this->gateway);

        $this->invoiceService = $this->app->make(InvoiceService::class);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Idempotência
    // -----------------------------------------------------------------

    public function test_gerar_duas_vezes_no_mesmo_dia_produz_uma_unica_fatura_e_uma_unica_cobranca(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Alfa');
        $plano = $this->criarPlano('Essencial');
        $this->criarAssinatura($empresa, $plano, BusinessDate::hoje()->toDateString());

        $this->artisan('plataforma:gerar-faturas')->assertSuccessful();
        $this->artisan('plataforma:gerar-faturas')->assertSuccessful();

        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(1, $this->gateway->cobrancasEmitidas, 'a cobrança foi pedida ao provedor duas vezes');
    }

    /**
     * O caso que a idempotência "no mesmo dia" não cobre. A fatura de julho
     * segue em aberto quando o mês vira, e `proxima_cobranca_em` só anda quando
     * o período é pago: a passada de agosto precisa reencontrar a fatura de
     * julho, e não emitir a segunda cobrança do mesmo ciclo.
     */
    public function test_fatura_em_aberto_nao_e_duplicada_depois_da_virada_do_mes(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Beta');
        $plano = $this->criarPlano('Essencial');
        $vencida = BusinessDate::hoje()->subMonthNoOverflow()->toDateString();
        $this->criarAssinatura($empresa, $plano, $vencida);

        $this->artisan('plataforma:gerar-faturas')->assertSuccessful();
        $this->artisan('plataforma:gerar-faturas')->assertSuccessful();

        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(
            substr($vencida, 0, 7),
            Invoice::query()->firstOrFail()->referencia,
            'a referência precisa ser a do período em aberto, não a do mês corrente'
        );
    }

    /**
     * Fatura emitida hoje por uma rotina que ficou dias parada não pode nascer
     * vencida: a tolerância da régua (Task 7.7) começaria a correr antes de o
     * tenant ter tido a chance de pagar, e o fim dessa contagem é bloqueio.
     */
    public function test_fatura_de_ciclo_atrasado_nao_nasce_vencida(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Gama');
        $plano = $this->criarPlano('Essencial');
        $this->criarAssinatura($empresa, $plano, BusinessDate::hoje()->subDays(10)->toDateString());

        $this->artisan('plataforma:gerar-faturas')->assertSuccessful();

        $this->assertSame(
            BusinessDate::hoje()->toDateString(),
            Invoice::query()->firstOrFail()->vencimento->toDateString()
        );
    }

    // -----------------------------------------------------------------
    // Tenant interno
    // -----------------------------------------------------------------

    public function test_tenant_interno_com_assinatura_no_banco_nunca_e_faturado(): void
    {
        $interno = $this->tenantInterno();
        $plano = $this->criarPlano('Interno');

        // Assinatura criada direto no banco, como se tivesse entrado por
        // importação ou correção manual: a rotina continua barrando.
        $this->criarAssinatura($interno, $plano, BusinessDate::hoje()->toDateString());

        $this->artisan('plataforma:gerar-faturas')->assertSuccessful();

        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(0, $this->gateway->cobrancasEmitidas);
    }

    // -----------------------------------------------------------------
    // Isolamento do erro do provedor
    // -----------------------------------------------------------------

    public function test_falha_do_provedor_em_um_tenant_nao_impede_os_outros_e_deixa_a_fatura_sem_link(): void
    {
        $plano = $this->criarPlano('Essencial');
        $hoje = BusinessDate::hoje()->toDateString();

        $quebrada = $this->criarTenantComum('Dedetizadora Delta');
        $assinaturaQuebrada = $this->criarAssinatura($quebrada, $plano, $hoje);

        $sadia = $this->criarTenantComum('Dedetizadora Epsilon');
        $this->criarAssinatura($sadia, $plano, $hoje);

        $this->gateway->assinaturasIndisponiveis = [$assinaturaQuebrada->id];

        $this->artisan('plataforma:gerar-faturas')->assertSuccessful();

        // Duas faturas: a do tenant que o provedor recusou continua gravada,
        // sem instrução de pagamento, e a do tenant seguinte foi emitida
        // normalmente.
        $this->assertDatabaseCount('invoices', 2);

        $semCobranca = Invoice::query()->where('company_id', $quebrada->id)->firstOrFail();

        $this->assertNull($semCobranca->gateway_invoice_id);
        $this->assertNull($semCobranca->link_pagamento);
        $this->assertSame(RespostaDeCobranca::SITUACAO_ABERTA, $semCobranca->situacao);

        $comCobranca = Invoice::query()->where('company_id', $sadia->id)->firstOrFail();

        $this->assertNotNull($comCobranca->gateway_invoice_id);
    }

    public function test_fatura_sem_cobranca_tenta_de_novo_na_passada_seguinte_sem_gerar_fatura_nova(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Zeta');
        $plano = $this->criarPlano('Essencial');
        $assinatura = $this->criarAssinatura($empresa, $plano, BusinessDate::hoje()->toDateString());

        $this->gateway->assinaturasIndisponiveis = [$assinatura->id];
        $this->artisan('plataforma:gerar-faturas')->assertSuccessful();

        $this->gateway->assinaturasIndisponiveis = [];
        $this->artisan('plataforma:gerar-faturas')->assertSuccessful();

        $this->assertDatabaseCount('invoices', 1);
        $this->assertNotNull(Invoice::query()->firstOrFail()->gateway_invoice_id);
    }

    /**
     * No cartão quem cobra o período é a recorrência do provedor. A fatura
     * existe como registro do período, sem instrução de pagamento, e a recusa
     * do provedor não vira erro da rodada.
     */
    public function test_fatura_no_cartao_e_gravada_sem_cobranca_avulsa(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Eta');
        $plano = $this->criarPlano('Essencial');
        $this->criarAssinatura(
            $empresa,
            $plano,
            BusinessDate::hoje()->toDateString(),
            SubscriptionService::FORMA_CARTAO
        );

        $this->artisan('plataforma:gerar-faturas')->assertSuccessful();

        $fatura = Invoice::query()->firstOrFail();

        $this->assertSame(RespostaDeCobranca::SITUACAO_ABERTA, $fatura->situacao);
        $this->assertNull($fatura->link_pagamento);
        $this->assertNull($fatura->linha_digitavel);
        $this->assertNull($fatura->qr_code_pix);
    }

    // -----------------------------------------------------------------
    // Recorte por tenant e por ciclo
    // -----------------------------------------------------------------

    public function test_company_gera_so_do_tenant_informado(): void
    {
        $plano = $this->criarPlano('Essencial');
        $hoje = BusinessDate::hoje()->toDateString();

        $alvo = $this->criarTenantComum('Dedetizadora Teta');
        $this->criarAssinatura($alvo, $plano, $hoje);

        $outra = $this->criarTenantComum('Dedetizadora Iota');
        $this->criarAssinatura($outra, $plano, $hoje);

        $this->artisan('plataforma:gerar-faturas', ['--company' => (string) $alvo->id])->assertSuccessful();

        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseHas('invoices', ['company_id' => $alvo->id]);
    }

    public function test_assinatura_com_cobranca_no_futuro_ou_cancelada_nao_e_faturada(): void
    {
        $plano = $this->criarPlano('Essencial');

        $futura = $this->criarTenantComum('Dedetizadora Kappa');
        $this->criarAssinatura($futura, $plano, BusinessDate::hoje()->addDays(3)->toDateString());

        $cancelada = $this->criarTenantComum('Dedetizadora Lambda');
        $this->criarAssinatura(
            $cancelada,
            $plano,
            BusinessDate::hoje()->toDateString(),
            SubscriptionService::FORMA_PIX,
            RespostaDeAssinatura::SITUACAO_CANCELADA
        );

        $this->artisan('plataforma:gerar-faturas')->assertSuccessful();

        $this->assertDatabaseCount('invoices', 0);
    }

    // -----------------------------------------------------------------
    // Baixa do pagamento
    // -----------------------------------------------------------------

    public function test_pagar_reativa_a_empresa_suspensa_por_inadimplencia_e_avanca_o_ciclo(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Mi');
        $plano = $this->criarPlano('Essencial');
        $cobrancaAtual = BusinessDate::hoje()->toDateString();
        $assinatura = $this->criarAssinatura(
            $empresa,
            $plano,
            $cobrancaAtual,
            SubscriptionService::FORMA_PIX,
            RespostaDeAssinatura::SITUACAO_SUSPENSA
        );

        $this->app->make(TenantService::class)->suspender(
            $empresa,
            InvoiceService::MOTIVO_SUSPENSAO_INADIMPLENCIA
        );

        $fatura = $this->criarFatura($assinatura, $empresa, $plano);

        $paga = $this->invoiceService->marcarComoPaga($fatura, BusinessDate::hoje()->toDateString());

        $this->assertSame(RespostaDeCobranca::SITUACAO_PAGA, $paga->situacao);
        $this->assertSame(BusinessDate::hoje()->toDateString(), $paga->pago_em->toDateString());

        $empresa->refresh();

        $this->assertSame(TenantService::SITUACAO_ATIVA, $empresa->situacao);
        $this->assertNull($empresa->motivo_suspensao);
        $this->assertNull($empresa->suspensa_em);

        $assinatura->refresh();

        $this->assertSame(RespostaDeAssinatura::SITUACAO_ATIVA, $assinatura->situacao);
        $this->assertSame(
            BusinessDate::hoje()->addMonthNoOverflow()->toDateString(),
            $assinatura->proxima_cobranca_em->toDateString()
        );
    }

    /**
     * A regra que separa "pagou a conta" de "está liberado". Empresa que o super
     * admin suspendeu por outro motivo continua suspensa depois de uma fatura
     * antiga dela ser paga.
     */
    public function test_pagar_nao_reativa_empresa_suspensa_por_outro_motivo(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Ni');
        $plano = $this->criarPlano('Essencial');
        $assinatura = $this->criarAssinatura($empresa, $plano, BusinessDate::hoje()->toDateString());

        $this->app->make(TenantService::class)->suspender($empresa, 'fraude');

        $fatura = $this->criarFatura($assinatura, $empresa, $plano);

        $this->invoiceService->marcarComoPaga($fatura, BusinessDate::hoje()->toDateString());

        $empresa->refresh();

        $this->assertSame(TenantService::SITUACAO_SUSPENSA, $empresa->situacao);
        $this->assertSame('fraude', $empresa->motivo_suspensao);
    }

    public function test_pagar_duas_vezes_nao_avanca_o_ciclo_duas_vezes(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Xi');
        $plano = $this->criarPlano('Essencial');
        $assinatura = $this->criarAssinatura($empresa, $plano, BusinessDate::hoje()->toDateString());
        $fatura = $this->criarFatura($assinatura, $empresa, $plano);

        $this->invoiceService->marcarComoPaga($fatura, BusinessDate::hoje()->toDateString());
        $this->invoiceService->marcarComoPaga($fatura->refresh(), BusinessDate::hoje()->addDay()->toDateString());

        $assinatura->refresh();

        $this->assertSame(
            BusinessDate::hoje()->addMonthNoOverflow()->toDateString(),
            $assinatura->proxima_cobranca_em->toDateString()
        );
        $this->assertSame(BusinessDate::hoje()->toDateString(), $fatura->refresh()->pago_em->toDateString());
    }

    public function test_marcar_como_vencida_nao_desfaz_uma_fatura_paga(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Omicron');
        $plano = $this->criarPlano('Essencial');
        $assinatura = $this->criarAssinatura($empresa, $plano, BusinessDate::hoje()->toDateString());

        $aberta = $this->criarFatura($assinatura, $empresa, $plano, '2026-01');
        $paga = $this->criarFatura($assinatura, $empresa, $plano, '2026-02');

        $this->invoiceService->marcarComoPaga($paga, BusinessDate::hoje()->toDateString());

        $this->assertSame(
            RespostaDeCobranca::SITUACAO_VENCIDA,
            $this->invoiceService->marcarComoVencida($aberta)->situacao
        );
        $this->assertSame(
            RespostaDeCobranca::SITUACAO_PAGA,
            $this->invoiceService->marcarComoVencida($paga->refresh())->situacao
        );
    }

    // -----------------------------------------------------------------
    // Histórico
    // -----------------------------------------------------------------

    public function test_historico_traz_so_as_faturas_da_empresa_dentro_da_janela(): void
    {
        $plano = $this->criarPlano('Essencial');
        $hoje = BusinessDate::hoje();

        $empresa = $this->criarTenantComum('Dedetizadora Pi');
        $assinatura = $this->criarAssinatura($empresa, $plano, $hoje->toDateString());

        $vizinha = $this->criarTenantComum('Dedetizadora Rô');
        $assinaturaVizinha = $this->criarAssinatura($vizinha, $plano, $hoje->toDateString());

        $this->criarFatura($assinatura, $empresa, $plano, $hoje->format('Y-m'));
        $this->criarFatura($assinatura, $empresa, $plano, $hoje->subMonthsNoOverflow(2)->format('Y-m'));
        $this->criarFatura($assinatura, $empresa, $plano, $hoje->subMonthsNoOverflow(20)->format('Y-m'));
        $this->criarFatura($assinaturaVizinha, $vizinha, $plano, $hoje->format('Y-m'));

        $historico = $this->invoiceService->historicoDe($empresa);

        $this->assertCount(2, $historico);
        $this->assertSame($hoje->format('Y-m'), $historico->first()->referencia);
        $this->assertTrue(
            $historico->every(fn (Invoice $fatura): bool => (int) $fatura->company_id === (int) $empresa->id)
        );
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * A empresa fundadora, marcada como interna pela migration da Task 5.1.
     */
    private function tenantInterno(): Company
    {
        return Company::query()->where('is_internal', true)->firstOrFail();
    }

    private function criarTenantComum(string $nome): Company
    {
        return Company::create(['name' => $nome]);
    }

    private function criarPlano(string $nome): Plan
    {
        return Plan::create([
            'nome' => $nome,
            'slug' => str($nome)->slug()->toString().'-'.uniqid(),
            'valor' => 199.90,
            'periodicidade' => SubscriptionService::PERIODICIDADE_MENSAL,
            'ativo' => true,
        ]);
    }

    private function criarAssinatura(
        Company $empresa,
        Plan $plano,
        string $proximaCobranca,
        string $forma = SubscriptionService::FORMA_PIX,
        string $situacao = RespostaDeAssinatura::SITUACAO_ATIVA,
    ): Subscription {
        return Subscription::create([
            'company_id' => $empresa->id,
            'plan_id' => $plano->id,
            'situacao' => $situacao,
            'forma_pagamento' => $forma,
            'gateway_subscription_id' => 'sub_'.$empresa->id,
            'inicio_em' => BusinessDate::hoje()->subMonthNoOverflow()->toDateString(),
            'proxima_cobranca_em' => $proximaCobranca,
        ]);
    }

    private function criarFatura(
        Subscription $assinatura,
        Company $empresa,
        Plan $plano,
        ?string $referencia = null,
    ): Invoice {
        return Invoice::create([
            'company_id' => $empresa->id,
            'subscription_id' => $assinatura->id,
            'referencia' => $referencia ?? BusinessDate::hoje()->format('Y-m'),
            'valor' => $plano->valor,
            'situacao' => RespostaDeCobranca::SITUACAO_ABERTA,
            'vencimento' => BusinessDate::hoje()->toDateString(),
        ]);
    }
}

/**
 * Dublê de `GatewayAssinatura` para os caminhos de cobrança, sem rede.
 *
 * Reproduz de propósito a única recusa de domínio que o PagBank faz sozinho:
 * fatura no cartão não tem cobrança avulsa, porque quem cobra o período é a
 * recorrência do provedor.
 *
 * Fica neste arquivo, e não em `tests/Support/`, pelo mesmo motivo do dublê da
 * Task 7.4: a Task 7.11 monta o dela com as necessidades dela, e um dublê
 * compartilhado cedo demais vira o lugar onde os testes brigam.
 */
class GatewayDeCobrancaDeTeste implements GatewayAssinatura
{
    public int $cobrancasEmitidas = 0;

    /**
     * Ids de assinatura para os quais o provedor "não responde".
     *
     * @var array<int, int>
     */
    public array $assinaturasIndisponiveis = [];

    public function registrarMeioDePagamento(Company $empresa, string $cartaoCifrado): void {}

    public function criarAssinatura(Company $empresa, Plan $plano, string $formaPagamento): RespostaDeAssinatura
    {
        return new RespostaDeAssinatura('sub_de_teste', RespostaDeAssinatura::SITUACAO_ATIVA);
    }

    public function cancelarAssinatura(string $idNoGateway): void {}

    public function trocarPlano(string $idNoGateway, Plan $novoPlano): RespostaDeAssinatura
    {
        return new RespostaDeAssinatura($idNoGateway, RespostaDeAssinatura::SITUACAO_ATIVA);
    }

    public function gerarCobranca(Invoice $fatura): RespostaDeCobranca
    {
        $assinatura = $fatura->subscription;

        if (in_array((int) $fatura->subscription_id, $this->assinaturasIndisponiveis, true)) {
            throw GatewayIndisponivelException::semResposta('/orders');
        }

        if ($assinatura?->forma_pagamento === SubscriptionService::FORMA_CARTAO) {
            throw GatewayRecusouException::operacaoNaoSuportada(
                'Fatura no cartão é cobrada pela recorrência do provedor.'
            );
        }

        $this->cobrancasEmitidas++;

        return new RespostaDeCobranca(
            idNoGateway: 'ord_'.$fatura->id,
            situacao: RespostaDeCobranca::SITUACAO_ABERTA,
            linkPagamento: 'https://provedor.test/pagar/'.$fatura->id,
            qrCodePix: 'pix-copia-e-cola-'.$fatura->id,
        );
    }

    public function consultarCobranca(string $idNoGateway): RespostaDeCobranca
    {
        return new RespostaDeCobranca($idNoGateway, RespostaDeCobranca::SITUACAO_ABERTA);
    }

    public function validarWebhook(Request $requisicao): bool
    {
        return true;
    }

    public function interpretarWebhook(array $payload): EventoDeGateway
    {
        return new EventoDeGateway('evt_de_teste', EventoDeGateway::TIPO_DESCONHECIDO);
    }
}
