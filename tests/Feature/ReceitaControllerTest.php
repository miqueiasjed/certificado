<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\Gateway\RespostaDeAssinatura;
use App\Support\Gateway\RespostaDeCobranca;
use App\Support\TenantAtual;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 7.8 do Plano 7: cobertura mínima dos critérios de aceitação de
 * `Plataforma\ReceitaController` (contadores de assinatura, receita
 * recorrente e faturas em aberto, todos excluindo o tenant interno).
 */
class ReceitaControllerTest extends TestCase
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

    public function test_receita_traz_contadores_e_receita_recorrente_corretos_e_exclui_o_tenant_interno(): void
    {
        $plano = $this->criarPlano('Essencial', 199.90);

        $empresaAtiva = $this->criarEmpresa('Dedetizadora Ativa');
        $this->criarAssinatura($empresaAtiva, $plano, RespostaDeAssinatura::SITUACAO_ATIVA);

        $empresaEmAtraso = $this->criarEmpresa('Dedetizadora Em Atraso');
        $this->criarAssinatura($empresaEmAtraso, $plano, RespostaDeAssinatura::SITUACAO_EM_ATRASO);

        $empresaSuspensa = $this->criarEmpresa('Dedetizadora Suspensa');
        $assinaturaSuspensa = $this->criarAssinatura($empresaSuspensa, $plano, RespostaDeAssinatura::SITUACAO_SUSPENSA);
        $this->criarFatura($empresaSuspensa, $assinaturaSuspensa, '2026-06', RespostaDeCobranca::SITUACAO_VENCIDA);

        $empresaCancelada = $this->criarEmpresa('Dedetizadora Cancelada');
        $this->criarAssinatura($empresaCancelada, $plano, RespostaDeAssinatura::SITUACAO_CANCELADA);

        // Assinatura do tenant interno: não deveria existir no mundo real (o
        // tenant interno não assina), mas se uma linha aparecer por importação
        // ou correção manual em banco, ela não pode entrar em número nenhum.
        $interno = Company::query()->where('is_internal', true)->firstOrFail();
        $assinaturaInterna = $this->criarAssinatura($interno, $plano, RespostaDeAssinatura::SITUACAO_ATIVA);
        $this->criarFatura($interno, $assinaturaInterna, '2026-07', RespostaDeCobranca::SITUACAO_ABERTA);

        $superAdmin = TenantAtual::comTenant($interno->id, fn () => User::factory()->create(['is_active' => true]));
        $superAdmin->is_platform_admin = true;
        $superAdmin->save();

        $resposta = $this->actingAs($superAdmin->fresh())->get('/plataforma/receita');

        $resposta->assertOk();
        $resposta->assertInertia(fn ($page) => $page
            ->component('Plataforma/Receita', false)
            ->where('assinaturas.ativa', 1)
            ->where('assinaturas.em_atraso', 1)
            ->where('assinaturas.suspensa', 1)
            ->where('assinaturas.cancelada', 1)
            // Só a assinatura ativa e não interna entra na receita recorrente.
            ->where('receitaRecorrenteMensal', 199.90)
            // Só a fatura vencida da empresa suspensa entra no valor em
            // aberto: a fatura do tenant interno é excluída.
            ->where('faturasEmAberto.quantidade', 1)
            ->where('faturasEmAberto.valor_total', 199.90)
            // A lista traz a fatura da empresa suspensa (Task 7.10), sem a
            // fatura do tenant interno.
            ->has('faturasEmAberto.lista', 1)
            ->where('faturasEmAberto.lista.0.empresa', 'Dedetizadora Suspensa')
            ->where('faturasEmAberto.lista.0.situacao', RespostaDeCobranca::SITUACAO_VENCIDA)
            ->where('faturasEmAberto.lista.0.valor', 199.90)
            // Tolerância configurada, para a tela não fixar "5" sozinha.
            ->where('diasDeTolerancia', 5)
        );
    }

    public function test_usuario_comum_recebe_403_em_plataforma_receita(): void
    {
        $empresa = $this->criarEmpresa('Dedetizadora Comum');
        $usuario = TenantAtual::comTenant($empresa->id, fn () => User::factory()->create(['is_active' => true]));
        $usuario->assignRole('administrador');

        $resposta = $this->actingAs($usuario->fresh())->get('/plataforma/receita');

        $resposta->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function criarEmpresa(string $nome): Company
    {
        return Company::create(['name' => $nome]);
    }

    private function criarPlano(string $nome, float $valor): Plan
    {
        return Plan::create([
            'nome' => $nome,
            'slug' => str($nome)->slug()->toString().'-'.uniqid(),
            'valor' => $valor,
            'periodicidade' => SubscriptionService::PERIODICIDADE_MENSAL,
            'ativo' => true,
        ]);
    }

    private function criarAssinatura(Company $empresa, Plan $plano, string $situacao): Subscription
    {
        return Subscription::create([
            'company_id' => $empresa->id,
            'plan_id' => $plano->id,
            'situacao' => $situacao,
            'forma_pagamento' => SubscriptionService::FORMA_PIX,
            'gateway_subscription_id' => 'sub_teste_'.$empresa->id,
            'inicio_em' => '2026-07-01',
            'proxima_cobranca_em' => '2026-08-01',
            'cancelada_em' => $situacao === RespostaDeAssinatura::SITUACAO_CANCELADA ? now() : null,
        ]);
    }

    private function criarFatura(Company $empresa, Subscription $assinatura, string $referencia, string $situacao): Invoice
    {
        return Invoice::create([
            'company_id' => $empresa->id,
            'subscription_id' => $assinatura->id,
            'referencia' => $referencia,
            'valor' => 199.90,
            'situacao' => $situacao,
            'vencimento' => '2026-06-01',
        ]);
    }
}
