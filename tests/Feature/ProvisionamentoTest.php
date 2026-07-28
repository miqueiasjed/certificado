<?php

namespace Tests\Feature;

use App\Models\ActiveIngredient;
use App\Models\Antidote;
use App\Models\BaitType;
use App\Models\ChemicalGroup;
use App\Models\Company;
use App\Models\EventType;
use App\Models\OnboardingStep;
use App\Models\OrganRegistration;
use App\Models\ServiceType;
use App\Models\User;
use App\Services\ProvisionamentoService;
use App\Services\TenantService;
use App\Support\CatalogoInicialDoTenant;
use App\Support\PassosDeOnboarding;
use App\Support\TenantAtual;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 8.3 do Plano 8: o provisionamento de um tenant novo.
 *
 * O caso que mais importa é
 * `test_falha_no_administrador_nao_deixa_catalogo_nem_trilha`. Tenant meio
 * provisionado é pior que tenant inexistente: aparece na listagem da plataforma
 * com cara de pronto, o cliente entra e não encontra tipo de serviço nem tipo
 * de evento para escolher na primeira ordem de serviço. Ninguém descobre isso
 * por erro no log.
 *
 * Logo atrás vem `test_dois_tenants_recebem_catalogos_independentes`: o
 * catálogo é copiado por empresa, nunca compartilhado. Se as duas empresas
 * apontassem para a mesma linha, uma dedetizadora renomear um tipo de serviço
 * mudaria o cadastro da concorrente dela.
 *
 * O fluxo completo de ponta a ponta (cadastro público, OS e certificado no
 * tenant novo) é a Task 8.10, em `OnboardingCompletoTest`.
 */
class ProvisionamentoTest extends TestCase
{
    use RefreshDatabase;

    private ProvisionamentoService $provisionamento;

    private TenantService $tenantService;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->provisionamento = app(ProvisionamentoService::class);
        $this->tenantService = app(TenantService::class);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();
        parent::tearDown();
    }

    public function test_provisionar_cria_administrador_catalogo_e_trilha_no_tenant_novo(): void
    {
        $empresa = Company::create(['name' => 'Dedetizadora Provisionada']);

        // O tenant fundador já tem cadastro próprio (os 5 tipos de serviço da
        // migration de instalação, por exemplo). O que se prova aqui é que o
        // provisionamento não acrescenta nem altera nada fora do tenant novo.
        $foraAntes = $this->contagemForaDoTenant($empresa->id);

        $administrador = $this->provisionamento->provisionar($empresa, [
            'nome' => 'Admin Provisionado',
            'email' => 'admin@provisionada.test',
        ]);

        $this->assertSame($empresa->id, (int) $administrador->company_id);
        $this->assertTrue($administrador->hasRole('administrador'));
        $this->assertTrue((bool) $administrador->is_active);
        $this->assertFalse((bool) $administrador->is_platform_admin);

        TenantAtual::comTenant($empresa->id, function (): void {
            $this->assertSame(count(CatalogoInicialDoTenant::tiposDeEvento()), EventType::query()->count());
            $this->assertSame(count(CatalogoInicialDoTenant::tiposDeIsca()), BaitType::query()->count());
            $this->assertSame(count(CatalogoInicialDoTenant::tiposDeServico()), ServiceType::query()->count());
            $this->assertSame(count(CatalogoInicialDoTenant::gruposQuimicos()), ChemicalGroup::query()->count());
            $this->assertSame(count(CatalogoInicialDoTenant::antidotos()), Antidote::query()->count());
            $this->assertSame(count(CatalogoInicialDoTenant::principiosAtivos()), ActiveIngredient::query()->count());
            $this->assertSame(count(CatalogoInicialDoTenant::registrosEmOrgao()), OrganRegistration::query()->count());

            $passos = OnboardingStep::query()->get();

            $this->assertEqualsCanonicalizing(
                array_column(PassosDeOnboarding::catalogo(), 'chave'),
                $passos->pluck('chave')->all(),
                'a trilha do tenant novo não nasceu com as chaves de PassosDeOnboarding'
            );
            $this->assertCount(8, $passos, 'a trilha da Task 8.5 tem 8 passos');
            $this->assertTrue(
                $passos->every(fn (OnboardingStep $passo): bool => $passo->concluido_em === null && $passo->ignorado_em === null),
                'passo da trilha nasceu concluído ou ignorado; todo passo nasce pendente'
            );
        });

        $this->assertSame(
            $foraAntes,
            $this->contagemForaDoTenant($empresa->id),
            'o provisionamento criou ou apagou registro fora do tenant novo'
        );
    }

    public function test_falha_no_administrador_nao_deixa_catalogo_nem_trilha(): void
    {
        $empresa = Company::create(['name' => 'Dedetizadora Falha']);

        $existente = TenantAtual::comTenant(
            Company::query()->where('is_internal', true)->firstOrFail()->id,
            fn (): User => User::factory()->create(['email' => 'repetido@falha.test'])
        );

        try {
            $this->provisionamento->provisionar($empresa, [
                'nome' => 'Admin Repetido',
                'email' => $existente->email,
            ]);
            $this->fail('e-mail repetido deveria estourar no unique de users');
        } catch (QueryException) {
            // esperado
        }

        $this->assertSame(0, DB::table('event_types')->where('company_id', $empresa->id)->count());
        $this->assertSame(0, DB::table('onboarding_steps')->where('company_id', $empresa->id)->count());
        $this->assertSame(0, DB::table('users')->where('company_id', $empresa->id)->count());
    }

    public function test_reprovisionar_cadastros_e_idempotente(): void
    {
        $empresa = $this->tenantService->criar([
            'name' => 'Dedetizadora Reprovisionada',
            'administrador_nome' => 'Admin Repro',
            'administrador_email' => 'admin@repro.test',
        ]);

        $segunda = $this->provisionamento->reprovisionarCadastros($empresa);

        $this->assertSame(
            array_fill_keys(array_keys($segunda), 0),
            $segunda,
            'reaplicar o catálogo duplicou cadastro'
        );

        TenantAtual::comTenant($empresa->id, function (): void {
            $this->assertSame(count(CatalogoInicialDoTenant::tiposDeEvento()), EventType::query()->count());
            $this->assertSame(count(CatalogoInicialDoTenant::tiposDeServico()), ServiceType::query()->count());
        });

        // Falta de um cadastro é reposta, e só ela.
        TenantAtual::comTenant($empresa->id, fn () => EventType::query()->limit(2)->get()->each->delete());

        $terceira = $this->provisionamento->reprovisionarCadastros($empresa);

        $this->assertSame(2, $terceira['tipos_de_evento']);
        $this->assertSame(0, $terceira['tipos_de_servico']);
    }

    public function test_dois_tenants_recebem_catalogos_independentes(): void
    {
        $primeiro = $this->tenantService->criar([
            'name' => 'Tenant Um',
            'administrador_nome' => 'Admin Um',
            'administrador_email' => 'admin@um.test',
        ]);

        $segundo = $this->tenantService->criar([
            'name' => 'Tenant Dois',
            'administrador_nome' => 'Admin Dois',
            'administrador_email' => 'admin@dois.test',
        ]);

        $doPrimeiro = TenantAtual::comTenant($primeiro->id, fn () => ServiceType::query()->pluck('id')->all());
        $doSegundo = TenantAtual::comTenant($segundo->id, fn () => ServiceType::query()->pluck('id')->all());

        $this->assertCount(count(CatalogoInicialDoTenant::tiposDeServico()), $doPrimeiro);
        $this->assertCount(count(CatalogoInicialDoTenant::tiposDeServico()), $doSegundo);
        $this->assertEmpty(array_intersect($doPrimeiro, $doSegundo), 'os dois tenants estão compartilhando a mesma linha de cadastro');

        // Editar no segundo não afeta o primeiro.
        TenantAtual::comTenant($segundo->id, fn () => ServiceType::query()->firstOrFail()->update(['name' => 'Renomeado no Dois']));

        $this->assertSame(
            0,
            TenantAtual::comTenant($primeiro->id, fn () => ServiceType::query()->where('name', 'Renomeado no Dois')->count()),
            'a edição de um tenant alterou o cadastro do outro'
        );
    }

    public function test_tenant_interno_nao_recebe_trilha_por_este_caminho(): void
    {
        $interno = Company::query()->where('is_internal', true)->firstOrFail();

        $this->assertSame(0, DB::table('onboarding_steps')->where('company_id', $interno->id)->count());
    }

    /**
     * Quantos registros de cada cadastro semeado existem FORA da empresa
     * informada, contados direto na tabela para não depender do escopo global.
     *
     * @return array<string, int>
     */
    private function contagemForaDoTenant(int $empresaId): array
    {
        $tabelas = [
            'event_types',
            'bait_types',
            'service_types',
            'chemical_groups',
            'antidotes',
            'active_ingredients',
            'organ_registrations',
            'onboarding_steps',
        ];

        $contagem = [];

        foreach ($tabelas as $tabela) {
            $contagem[$tabela] = DB::table($tabela)->where('company_id', '!=', $empresaId)->count();
        }

        return $contagem;
    }
}
