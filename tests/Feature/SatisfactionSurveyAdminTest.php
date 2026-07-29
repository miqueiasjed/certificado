<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\SatisfactionSurvey;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\SatisfactionSurveyService;
use App\Support\TenantAtual;
use Database\Factories\ClientFactory;
use Database\Factories\WorkOrderFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 16.7 do Plano 16: painel de satisfação (`Satisfacao/Index.vue`) precisa
 * do endpoint de leitura dos indicadores e da fila de pendência de contato, e
 * da ação de marcar contato feito - `SatisfactionSurveyController` e
 * `SatisfactionSurveyService::marcarContatoFeito()`/`pendenciasDeContato()`,
 * que ainda não existiam na Task 16.5.
 */
class SatisfactionSurveyAdminTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Company $outraEmpresa;

    private User $administrador;

    private Technician $tecnico;

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

        $this->tecnico = Technician::create([
            'name' => 'Ana Ferreira',
            'email' => 'ana-'.uniqid().'@exemplo.test',
            'phone' => '11999990000',
            'specialty' => 'Controle de pragas',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    public function test_index_lista_indicadores_e_pendencias_de_contato(): void
    {
        $this->respostaRespondida(nota: 1, comComentario: true);

        $resposta = $this->get('/satisfacao');

        $resposta->assertOk();
        $resposta->assertInertia(fn ($pagina) => $pagina
            ->component('Satisfacao/Index')
            ->where('indicadores.geral.respostas', 1)
            ->where('indicadores.geral.media_omitida', true)
            ->has('pendenciasDeContato', 1)
        );
    }

    public function test_marcar_contato_feito_fecha_a_pendencia(): void
    {
        $pesquisa = $this->respostaRespondida(nota: 2);

        $this->assertTrue($pesquisa->pendencia_de_contato);

        $resposta = $this->post("/satisfacao/{$pesquisa->id}/contato-feito");

        $resposta->assertRedirect();
        $resposta->assertSessionHas('success');

        $pesquisa = $pesquisa->fresh();

        $this->assertFalse($pesquisa->pendencia_de_contato);
        $this->assertNotNull($pesquisa->contato_feito_em);
    }

    public function test_marcar_contato_feito_de_pesquisa_ja_fechada_nao_sobrescreve_o_instante(): void
    {
        $pesquisa = $this->respostaRespondida(nota: 1);

        $this->post("/satisfacao/{$pesquisa->id}/contato-feito")->assertRedirect();
        $primeiroInstante = $pesquisa->fresh()->contato_feito_em;

        $this->travel(1)->hours();

        $this->post("/satisfacao/{$pesquisa->id}/contato-feito")->assertRedirect();

        $this->assertTrue($primeiroInstante->eq($pesquisa->fresh()->contato_feito_em));
    }

    public function test_pesquisa_de_outra_empresa_devolve_404(): void
    {
        $daOutraEmpresa = TenantAtual::comTenant((int) $this->outraEmpresa->id, function (): SatisfactionSurvey {
            $cliente = ClientFactory::new()->create();
            $visita = WorkOrderFactory::new()->create([
                'client_id' => $cliente->id,
                'status' => 'completed',
            ]);

            return SatisfactionSurvey::create([
                'work_order_id' => $visita->id,
                'client_id' => $cliente->id,
                'token' => Str::random(SatisfactionSurveyService::TAMANHO_DO_TOKEN),
                'expira_em' => now()->addDays(30)->toDateString(),
                'nota' => 1,
                'respondida_em' => now(),
                'pendencia_de_contato' => true,
            ]);
        });

        $this->post("/satisfacao/{$daOutraEmpresa->id}/contato-feito")->assertNotFound();
    }

    public function test_usuario_sem_permissao_de_responder_recebe_403_mas_ve_o_indice(): void
    {
        $usuarioLeitura = TenantAtual::comTenant(
            (int) $this->empresa->id,
            fn (): User => User::factory()->create()
        );
        $usuarioLeitura->assignRole('leitura');

        $pesquisa = $this->respostaRespondida(nota: 1);

        $this->actingAs($usuarioLeitura);

        $this->get('/satisfacao')->assertOk();
        $this->post("/satisfacao/{$pesquisa->id}/contato-feito")->assertForbidden();
    }

    private function respostaRespondida(int $nota, bool $comComentario = false): SatisfactionSurvey
    {
        return TenantAtual::comTenant((int) $this->empresa->id, function () use ($nota, $comComentario): SatisfactionSurvey {
            $cliente = ClientFactory::new()->create();

            $visita = WorkOrderFactory::new()->create([
                'client_id' => $cliente->id,
                'technician_id' => $this->tecnico->id,
                'status' => 'completed',
            ]);

            return SatisfactionSurvey::create([
                'work_order_id' => $visita->id,
                'client_id' => $cliente->id,
                'technician_id' => $this->tecnico->id,
                'token' => Str::random(SatisfactionSurveyService::TAMANHO_DO_TOKEN),
                'enviada_em' => now(),
                'expira_em' => now()->addDays(30)->toDateString(),
                'nota' => $nota,
                'comentario' => $comComentario ? 'Atendimento atrasou muito.' : null,
                'respondida_em' => now(),
                'pendencia_de_contato' => $nota <= SatisfactionSurveyService::NOTA_MAXIMA_DE_PENDENCIA,
            ]);
        });
    }
}
