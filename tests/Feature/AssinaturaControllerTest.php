<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Services\TenantService;
use App\Support\Gateway\RespostaDeAssinatura;
use App\Support\Gateway\RespostaDeCobranca;
use App\Support\TenantAtual;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 7.8 do Plano 7: cobertura mínima dos critérios de aceitação de
 * `AssinaturaController` (a área das empresas). O webhook está em
 * `GatewayWebhookTest`, a régua em `InadimplenciaTest` e a imunidade do tenant
 * interno de ponta a ponta em `TenantInternoImuneTest` (Task 7.11).
 */
class AssinaturaControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // show(): plano, situação e faturas do próprio tenant
    // -----------------------------------------------------------------

    public function test_assinatura_mostra_plano_situacao_e_faturas_do_proprio_tenant(): void
    {
        $plano = $this->criarPlano('Essencial');
        $empresa = $this->criarEmpresa('Dedetizadora Um');
        $usuario = $this->criarUsuario($empresa, 'leitura');

        $assinatura = $this->criarAssinatura($empresa, $plano);
        $fatura = $this->criarFatura($empresa, $assinatura, '2026-07');

        $resposta = $this->actingAs($usuario)->get('/assinatura');

        $resposta->assertOk();
        $resposta->assertInertia(fn ($page) => $page
            ->component('Assinatura/Show', false)
            ->where('situacao.tem_assinatura', true)
            ->where('situacao.situacao', RespostaDeAssinatura::SITUACAO_ATIVA)
            ->where('situacao.plano.id', $plano->id)
            ->has('faturas', 1)
            ->where('faturas.0.id', $fatura->id)
            ->where('faturas.0.referencia', '2026-07')
        );
    }

    // -----------------------------------------------------------------
    // faturaShow(): tenant A não vê fatura do tenant B por id direto
    // -----------------------------------------------------------------

    public function test_tenant_nao_consegue_ver_fatura_de_outro_tenant_por_id_direto(): void
    {
        $plano = $this->criarPlano('Essencial');

        $empresaUm = $this->criarEmpresa('Dedetizadora Um');
        $empresaDois = $this->criarEmpresa('Dedetizadora Dois');

        $assinaturaDois = $this->criarAssinatura($empresaDois, $plano);
        $faturaDoTenantDois = $this->criarFatura($empresaDois, $assinaturaDois, '2026-07');

        $usuarioUm = $this->criarUsuario($empresaUm, 'leitura');

        $resposta = $this->actingAs($usuarioUm)->get("/assinatura/faturas/{$faturaDoTenantDois->id}");

        $resposta->assertNotFound();
    }

    public function test_tenant_ve_a_propria_fatura_por_id_direto(): void
    {
        $plano = $this->criarPlano('Essencial');
        $empresa = $this->criarEmpresa('Dedetizadora Um');
        $usuario = $this->criarUsuario($empresa, 'leitura');

        $assinatura = $this->criarAssinatura($empresa, $plano);
        $fatura = $this->criarFatura($empresa, $assinatura, '2026-07');

        $resposta = $this->actingAs($usuario)->get("/assinatura/faturas/{$fatura->id}");

        $resposta->assertOk();
        $resposta->assertJson(['id' => $fatura->id, 'referencia' => '2026-07']);
    }

    // -----------------------------------------------------------------
    // A tela continua acessível com a empresa suspensa
    // -----------------------------------------------------------------

    public function test_tela_de_assinatura_continua_acessivel_com_a_empresa_suspensa(): void
    {
        $plano = $this->criarPlano('Essencial');
        $empresa = $this->criarEmpresa('Dedetizadora Suspensa');
        $usuario = $this->criarUsuario($empresa, 'leitura');

        $empresa->update([
            'situacao' => TenantService::SITUACAO_SUSPENSA,
            'suspensa_em' => now(),
            'motivo_suspensao' => 'inadimplencia',
        ]);

        $resposta = $this->actingAs($usuario)->get('/assinatura');

        $resposta->assertOk();
        $resposta->assertInertia(fn ($page) => $page->component('Assinatura/Show', false));
    }

    // -----------------------------------------------------------------
    // trocarFormaDePagamento(): exige assinatura-gerenciar
    // -----------------------------------------------------------------

    public function test_usuario_sem_assinatura_gerenciar_recebe_403_ao_trocar_forma_de_pagamento(): void
    {
        $plano = $this->criarPlano('Essencial');
        $empresa = $this->criarEmpresa('Dedetizadora Um');
        $usuario = $this->criarUsuario($empresa, 'leitura');

        $this->criarAssinatura($empresa, $plano, SubscriptionService::FORMA_PIX);

        $resposta = $this->actingAs($usuario)->post('/assinatura/forma-pagamento', [
            'forma' => SubscriptionService::FORMA_BOLETO,
        ]);

        $resposta->assertForbidden();
    }

    public function test_administrador_troca_a_forma_de_pagamento_com_sucesso(): void
    {
        $plano = $this->criarPlano('Essencial');
        $empresa = $this->criarEmpresa('Dedetizadora Um');
        $usuario = $this->criarUsuario($empresa, 'administrador');

        $assinatura = $this->criarAssinatura($empresa, $plano, SubscriptionService::FORMA_PIX);

        $resposta = $this->actingAs($usuario)->post('/assinatura/forma-pagamento', [
            'forma' => SubscriptionService::FORMA_BOLETO,
        ]);

        $resposta->assertRedirect();
        $resposta->assertSessionHas('success');

        $this->assertSame(SubscriptionService::FORMA_BOLETO, $assinatura->fresh()->forma_pagamento);
    }

    // -----------------------------------------------------------------
    // Tenant interno: sem fatura e imune
    // -----------------------------------------------------------------

    public function test_tenant_interno_ve_situacao_imune_e_nenhuma_fatura(): void
    {
        $interno = Company::query()->where('is_internal', true)->firstOrFail();
        $usuario = TenantAtual::comTenant($interno->id, fn () => User::factory()->create(['is_active' => true]));
        $usuario->assignRole('leitura');

        $resposta = $this->actingAs($usuario)->get('/assinatura');

        $resposta->assertOk();
        $resposta->assertInertia(fn ($page) => $page
            ->component('Assinatura/Show', false)
            ->where('situacao.imune', true)
            ->where('faturas', [])
        );
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function criarEmpresa(string $nome): Company
    {
        return Company::create(['name' => $nome]);
    }

    private function criarUsuario(Company $empresa, string $papel): User
    {
        $usuario = TenantAtual::comTenant($empresa->id, fn () => User::factory()->create(['is_active' => true]));
        $usuario->assignRole($papel);

        return $usuario->fresh();
    }

    private function criarPlano(string $nome): Plan
    {
        return Plan::create([
            'nome' => $nome,
            'slug' => str($nome)->slug()->toString(),
            'valor' => 199.90,
            'periodicidade' => SubscriptionService::PERIODICIDADE_MENSAL,
            'ativo' => true,
        ]);
    }

    private function criarAssinatura(Company $empresa, Plan $plano, string $forma = SubscriptionService::FORMA_PIX): Subscription
    {
        return Subscription::create([
            'company_id' => $empresa->id,
            'plan_id' => $plano->id,
            'situacao' => RespostaDeAssinatura::SITUACAO_ATIVA,
            'forma_pagamento' => $forma,
            'gateway_subscription_id' => 'sub_teste_'.$empresa->id,
            'inicio_em' => '2026-07-01',
            'proxima_cobranca_em' => '2026-08-01',
        ]);
    }

    private function criarFatura(Company $empresa, Subscription $assinatura, string $referencia): Invoice
    {
        return Invoice::create([
            'company_id' => $empresa->id,
            'subscription_id' => $assinatura->id,
            'referencia' => $referencia,
            'valor' => 199.90,
            'situacao' => RespostaDeCobranca::SITUACAO_ABERTA,
            'vencimento' => '2026-07-01',
            'link_pagamento' => 'https://pagamento.teste/'.$referencia,
        ]);
    }
}
