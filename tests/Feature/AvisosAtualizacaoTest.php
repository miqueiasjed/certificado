<?php

namespace Tests\Feature;

use App\Models\AvisoAtualizacaoLido;
use App\Models\Company;
use App\Models\User;
use App\Services\AvisosAtualizacao\CatalogoAvisosAtualizacao;
use App\Services\AvisosAtualizacao\VersaoDoBuild;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CriaAvisosAtualizacao;
use Tests\TestCase;

/**
 * Pop-up "O sistema foi atualizado": quem recebe cada aviso, marcação de lido
 * e isolamento entre empresas.
 */
class AvisosAtualizacaoTest extends TestCase
{
    use CriaAvisosAtualizacao;
    use RefreshDatabase;

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        // 16/11 às 12h em São Paulo.
        Carbon::setTestNow(Carbon::parse('2026-11-16 15:00:00', 'UTC'));
        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ModulesSeeder::class);

        $this->empresa = Company::query()->firstOrFail();
        $this->usarPastaDeAvisos();
    }

    protected function tearDown(): void
    {
        $this->apagarPastaDeAvisos();
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    private function criarUsuario(?string $papel, ?Company $empresa = null, ?string $criadoEm = null): User
    {
        $empresa ??= $this->empresa;

        $usuario = TenantAtual::comTenant($empresa->id, fn () => User::factory()->create([
            'email' => ($papel ?? 'usuario').'-'.uniqid().'@exemplo.test',
            'is_active' => true,
        ]));

        if ($criadoEm !== null) {
            $usuario->forceFill(['created_at' => Carbon::parse($criadoEm, 'UTC')])->save();
        }

        if ($papel !== null) {
            $usuario->assignRole($papel);
        }

        return $usuario->fresh();
    }

    /** @return list<string> */
    private function idsPendentes(User $usuario): array
    {
        TenantAtual::limpar();

        return collect($this->actingAs($usuario)->getJson('/avisos-atualizacao/pendentes')
            ->assertOk()
            ->json('dados.avisos'))->pluck('id')->all();
    }

    public function test_publico_gestao_recebe_e_tecnico_nao(): void
    {
        $this->criarAviso('2026-11-16-gestao', ['gestao']);
        $this->criarAviso('2026-11-16-tecnico', ['tecnico']);
        $this->criarAviso('2026-11-16-todos', ['todos']);

        foreach (['administrador', 'financeiro', 'comercial', 'leitura'] as $papel) {
            $this->assertSame(
                ['2026-11-16-gestao', '2026-11-16-todos'],
                $this->idsPendentes($this->criarUsuario($papel)),
                "papel {$papel}"
            );
        }

        $this->assertSame(['2026-11-16-tecnico', '2026-11-16-todos'], $this->idsPendentes($this->criarUsuario('tecnico')));
        $this->assertSame([], $this->idsPendentes($this->criarUsuario(null)));
    }

    public function test_usuario_com_dois_papeis_ve_cada_aviso_uma_vez(): void
    {
        $this->criarAviso('2026-11-16-todos', ['todos']);
        $this->criarAviso('2026-11-16-ambos', ['gestao', 'tecnico']);

        $usuario = $this->criarUsuario('administrador');
        $usuario->assignRole('tecnico');

        $this->assertSame(['2026-11-16-ambos', '2026-11-16-todos'], $this->idsPendentes($usuario->fresh()));
    }

    public function test_permissao_filtra_quem_nao_ve_a_tela(): void
    {
        $this->criarAviso('2026-11-16-financeiro', ['gestao'], permissao: 'financeiro-ver');

        $this->assertSame(['2026-11-16-financeiro'], $this->idsPendentes($this->criarUsuario('administrador')));
        $this->assertSame([], $this->idsPendentes($this->criarUsuario('comercial')));
    }

    public function test_tenants_filtra_pelo_slug_publico_da_empresa(): void
    {
        $this->empresa->forceFill(['slug_publico' => 'aride'])->save();
        $this->criarAviso('2026-11-16-so-aride', ['gestao'], tenants: ['aride']);
        $this->criarAviso('2026-11-16-so-outra', ['gestao'], tenants: ['outra']);

        $this->assertSame(['2026-11-16-so-aride'], $this->idsPendentes($this->criarUsuario('administrador')));
    }

    public function test_cadastro_depois_do_aviso_nao_recebe_e_dia_conta_em_brasilia(): void
    {
        $this->criarAviso('2026-11-15-ontem', ['gestao']);
        $this->criarAviso('2026-11-16-hoje', ['gestao']);

        // 16/11 02h30 UTC ainda é 15/11 em Brasília: recebe os dois.
        $antigo = $this->criarUsuario('administrador', criadoEm: '2026-11-16 02:30:00');
        $this->assertSame(['2026-11-15-ontem', '2026-11-16-hoje'], $this->idsPendentes($antigo));

        // 16/11 03h30 UTC já é 16/11 em Brasília: só o de hoje.
        $novo = $this->criarUsuario('administrador', criadoEm: '2026-11-16 03:30:00');
        $this->assertSame(['2026-11-16-hoje'], $this->idsPendentes($novo));
    }

    public function test_super_admin_da_plataforma_recebe_403(): void
    {
        $this->criarAviso('2026-11-16-todos', ['todos']);

        $usuario = $this->criarUsuario('administrador');
        $usuario->is_platform_admin = true;
        $usuario->save();

        $this->actingAs($usuario)->getJson('/avisos-atualizacao/pendentes')->assertForbidden();
        $this->actingAs($usuario)->postJson('/avisos-atualizacao/lidos', ['ids' => ['2026-11-16-todos']])->assertForbidden();
    }

    public function test_marcar_lido_some_da_lista_e_ignora_id_de_fora(): void
    {
        $this->criarAviso('2026-11-16-gestao', ['gestao']);
        $this->criarAviso('2026-11-16-tecnico', ['tecnico']);

        $usuario = $this->criarUsuario('administrador');

        $this->actingAs($usuario)
            ->postJson('/avisos-atualizacao/lidos', ['ids' => ['2026-11-16-gestao', '2026-11-16-tecnico', '2026-11-16-inexistente']])
            ->assertOk()
            ->assertJsonPath('dados.marcados', 1);

        // Segunda vez não duplica.
        TenantAtual::limpar();
        $this->actingAs($usuario)
            ->postJson('/avisos-atualizacao/lidos', ['ids' => ['2026-11-16-gestao']])
            ->assertOk()
            ->assertJsonPath('dados.marcados', 0);

        $this->assertSame([], $this->idsPendentes($usuario));

        $registro = AvisoAtualizacaoLido::query()->deTodasAsEmpresas()->sole();
        $this->assertSame($this->empresa->id, $registro->company_id);
        $this->assertSame('2026-11-16-gestao', $registro->aviso_id);
    }

    public function test_lido_numa_empresa_nao_vale_para_outra(): void
    {
        $this->criarAviso('2026-11-16-gestao', ['gestao']);

        $outraEmpresa = Company::create([
            'name' => 'Outra Dedetizadora',
            'cnpj' => '77.777.777/0001-77',
            'email' => 'contato@outra.test',
        ]);

        $daPrimeira = $this->criarUsuario('administrador');
        $daSegunda = $this->criarUsuario('administrador', $outraEmpresa);

        $this->actingAs($daPrimeira)->postJson('/avisos-atualizacao/lidos', ['ids' => ['2026-11-16-gestao']])->assertOk();

        $this->assertSame(['2026-11-16-gestao'], $this->idsPendentes($daSegunda));

        // A leitura da segunda empresa não enxerga o registro da primeira.
        TenantAtual::comTenant($outraEmpresa->id, function (): void {
            $this->assertSame(0, AvisoAtualizacaoLido::query()->count());
        });
    }

    public function test_ids_mal_formados_sao_recusados(): void
    {
        $usuario = $this->criarUsuario('administrador');

        $this->actingAs($usuario)->postJson('/avisos-atualizacao/lidos', ['ids' => []])->assertUnprocessable();
        $this->actingAs($usuario)->postJson('/avisos-atualizacao/lidos', ['ids' => ['../etc/passwd']])->assertUnprocessable();
    }

    public function test_aviso_real_dos_certificados_vai_para_gestao_com_certificado_ver(): void
    {
        $this->app->instance(CatalogoAvisosAtualizacao::class, new CatalogoAvisosAtualizacao);
        $id = '2026-11-16-certificados-observacao-e-vencimento';

        $this->assertContains($id, $this->idsPendentes($this->criarUsuario('administrador')));
        $this->assertNotContains($id, $this->idsPendentes($this->criarUsuario('tecnico')));
    }

    public function test_versao_vem_na_resposta_e_pagina_sem_manifesto_tem_meta_vazia(): void
    {
        $this->app->instance(VersaoDoBuild::class, new VersaoDoBuild('/caminho/inexistente/manifest.json'));

        $usuario = $this->criarUsuario('administrador');

        $this->actingAs($usuario)->getJson('/avisos-atualizacao/pendentes')
            ->assertOk()
            ->assertJsonPath('dados.versao', null);

        TenantAtual::limpar();
        $this->actingAs($usuario)->get('/dashboard')
            ->assertOk()
            ->assertSee('<meta name="versao-build" content="">', false);
    }

    public function test_versao_do_build_usa_o_manifesto_e_ignora_modo_dev(): void
    {
        $pasta = storage_path('framework/testing/versao-'.uniqid());
        mkdir($pasta, 0777, true);
        file_put_contents($pasta.'/manifest.json', '{"a":1}');

        try {
            $versao = (new VersaoDoBuild($pasta.'/manifest.json', $pasta.'/hot'))->atual();
            $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', (string) $versao);

            file_put_contents($pasta.'/hot', 'http://localhost:5173');
            $this->assertNull((new VersaoDoBuild($pasta.'/manifest.json', $pasta.'/hot'))->atual());
        } finally {
            array_map('unlink', glob($pasta.'/*') ?: []);
            rmdir($pasta);
        }
    }
}
