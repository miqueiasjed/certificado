<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyAvailabilitySetting;
use App\Models\User;
use App\Support\TenantAtual;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 16.7 do Plano 16: a tela de configuração de disponibilidade e
 * agendamento online (`Settings/Disponibilidade.vue`) precisa de um endpoint
 * de leitura e um de gravação que ainda não existiam nas Tasks 16.2/16.4 -
 * `CompanyAvailabilitySettingController` e `AvailabilityService::
 * configuracaoDaEmpresa()`/`salvarConfiguracao()`.
 */
class CompanyAvailabilitySettingTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Company $outraEmpresa;

    private User $administrador;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->empresa = Company::query()->firstOrFail();
        $this->outraEmpresa = Company::create(['name' => 'Dedetizadora B', 'email' => 'contato@dedetizadora-b.test']);

        $this->administrador = TenantAtual::comTenant(
            (int) $this->empresa->id,
            fn (): User => User::factory()->create(['name' => 'Administradora da Empresa'])
        );
        $this->administrador->assignRole('administrador');

        $this->actingAs($this->administrador);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    public function test_tela_sem_configuracao_salva_mostra_o_padrao_do_sistema(): void
    {
        $resposta = $this->get('/settings/disponibilidade');

        $resposta->assertOk();
        $resposta->assertInertia(fn ($pagina) => $pagina
            ->component('Settings/Disponibilidade')
            ->where('configuracao.dias_da_semana', CompanyAvailabilitySetting::DIAS_PADRAO)
            ->where('configuracao.visitas_por_periodo', CompanyAvailabilitySetting::VISITAS_POR_PERIODO_PADRAO)
            ->where('configuracao.aceita_agendamento_online', false)
            ->where('slugPublico', null)
        );
    }

    public function test_salvar_grava_configuracao_e_slug(): void
    {
        $resposta = $this->from('/settings/disponibilidade')->put('/settings/disponibilidade', [
            'dias_da_semana' => [1, 2, 3, 4, 5, 6],
            'visitas_por_periodo' => 6,
            'antecedencia_minima_dias' => 1,
            'janela_maxima_dias' => 90,
            'aceita_agendamento_online' => true,
            'slug_publico' => 'dedetizadora-a',
        ]);

        $resposta->assertRedirect('/settings/disponibilidade');
        $resposta->assertSessionHas('success');

        $configuracao = CompanyAvailabilitySetting::query()->sole();

        $this->assertSame([1, 2, 3, 4, 5, 6], $configuracao->dias_da_semana);
        $this->assertSame(6, $configuracao->visitas_por_periodo);
        $this->assertSame(1, $configuracao->antecedencia_minima_dias);
        $this->assertSame(90, $configuracao->janela_maxima_dias);
        $this->assertTrue($configuracao->aceita_agendamento_online);

        $this->assertSame('dedetizadora-a', $this->empresa->fresh()->slug_publico);
    }

    public function test_salvar_de_novo_atualiza_a_mesma_linha_em_vez_de_criar_outra(): void
    {
        CompanyAvailabilitySetting::create([
            'dias_da_semana' => [1, 2, 3, 4, 5],
            'visitas_por_periodo' => 4,
            'antecedencia_minima_dias' => 2,
            'janela_maxima_dias' => 60,
            'aceita_agendamento_online' => false,
        ]);

        $this->put('/settings/disponibilidade', [
            'dias_da_semana' => [1, 2, 3, 4, 5],
            'visitas_por_periodo' => 8,
            'antecedencia_minima_dias' => 2,
            'janela_maxima_dias' => 60,
            'aceita_agendamento_online' => false,
        ])->assertRedirect();

        $this->assertSame(1, CompanyAvailabilitySetting::query()->count());
        $this->assertSame(8, CompanyAvailabilitySetting::query()->sole()->visitas_por_periodo);
    }

    public function test_ligar_agendamento_online_sem_slug_devolve_422(): void
    {
        $resposta = $this->putJson('/settings/disponibilidade', [
            'dias_da_semana' => [1, 2, 3, 4, 5],
            'visitas_por_periodo' => 4,
            'antecedencia_minima_dias' => 2,
            'janela_maxima_dias' => 60,
            'aceita_agendamento_online' => true,
            'slug_publico' => '',
        ]);

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('slug_publico');
    }

    public function test_slug_com_maiuscula_ou_acento_devolve_422(): void
    {
        $resposta = $this->putJson('/settings/disponibilidade', [
            'dias_da_semana' => [1, 2, 3, 4, 5],
            'visitas_por_periodo' => 4,
            'antecedencia_minima_dias' => 2,
            'janela_maxima_dias' => 60,
            'aceita_agendamento_online' => false,
            'slug_publico' => 'Dedetização',
        ]);

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('slug_publico');
    }

    public function test_slug_ja_usado_por_outra_empresa_devolve_422(): void
    {
        $this->outraEmpresa->update(['slug_publico' => 'ja-existe']);

        $resposta = $this->putJson('/settings/disponibilidade', [
            'dias_da_semana' => [1, 2, 3, 4, 5],
            'visitas_por_periodo' => 4,
            'antecedencia_minima_dias' => 2,
            'janela_maxima_dias' => 60,
            'aceita_agendamento_online' => false,
            'slug_publico' => 'ja-existe',
        ]);

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('slug_publico');
    }

    public function test_empresa_pode_salvar_de_novo_com_o_proprio_slug(): void
    {
        $this->empresa->update(['slug_publico' => 'dedetizadora-a']);

        $resposta = $this->from('/settings/disponibilidade')->put('/settings/disponibilidade', [
            'dias_da_semana' => [1, 2, 3, 4, 5],
            'visitas_por_periodo' => 4,
            'antecedencia_minima_dias' => 2,
            'janela_maxima_dias' => 60,
            'aceita_agendamento_online' => true,
            'slug_publico' => 'dedetizadora-a',
        ]);

        $resposta->assertRedirect('/settings/disponibilidade');
        $resposta->assertSessionHasNoErrors();
    }

    public function test_usuario_sem_permissao_recebe_403(): void
    {
        $usuarioLeitura = TenantAtual::comTenant(
            (int) $this->empresa->id,
            fn (): User => User::factory()->create()
        );
        $usuarioLeitura->assignRole('leitura');

        $this->actingAs($usuarioLeitura);

        $this->get('/settings/disponibilidade')->assertForbidden();
        $this->put('/settings/disponibilidade', [])->assertForbidden();
    }
}
