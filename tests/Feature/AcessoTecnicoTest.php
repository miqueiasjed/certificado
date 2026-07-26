<?php

namespace Tests\Feature;

use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Factories\WorkOrderFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 2.16 do Plano 2: cobre o escopo de acesso do técnico às ordens de
 * serviço, regra implementada em WorkOrderAccessService (Task 2.8) e aplicada
 * pelo WorkOrderController em index() e show().
 *
 * O escopo por empresa não separa um técnico do outro, então esta verificação
 * de dono continua obrigatória mesmo depois do multiempresa. É por isso que
 * este teste existe: prova que WorkOrderController continua chamando
 * aplicarEscopoDoUsuario() e garantirAcesso() antes de expor a ordem de
 * serviço de outro técnico.
 */
class AcessoTecnicoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // -----------------------------------------------------------------
    // Listagem: cada técnico só vê a própria ordem de serviço
    // -----------------------------------------------------------------

    public function test_tecnico_ve_apenas_a_propria_ordem_de_servico_na_listagem(): void
    {
        $tecnicoA = $this->criarTecnico();
        $usuarioA = $this->criarUsuarioTecnico($tecnicoA);
        $ordemA = $this->criarOrdemDeServico(['technician_id' => $tecnicoA->id]);

        $tecnicoB = $this->criarTecnico();
        $this->criarUsuarioTecnico($tecnicoB);
        $ordemB = $this->criarOrdemDeServico(['technician_id' => $tecnicoB->id]);

        $resposta = $this->actingAs($usuarioA)->get('/work-orders');

        $resposta->assertOk();

        $ids = $this->idsDaListagem($resposta);

        $this->assertTrue($ids->contains($ordemA->id), 'o técnico A deveria ver a própria ordem de serviço na listagem');
        $this->assertFalse($ids->contains($ordemB->id), 'o técnico A não deveria ver a ordem de serviço do técnico B');
    }

    // -----------------------------------------------------------------
    // Detalhe: 403 para ordem de serviço de outro técnico
    // -----------------------------------------------------------------

    public function test_tecnico_recebe_403_ao_abrir_ordem_de_servico_de_outro_tecnico(): void
    {
        $tecnicoA = $this->criarTecnico();
        $usuarioA = $this->criarUsuarioTecnico($tecnicoA);

        $tecnicoB = $this->criarTecnico();
        $this->criarUsuarioTecnico($tecnicoB);
        $ordemB = $this->criarOrdemDeServico(['technician_id' => $tecnicoB->id]);

        $resposta = $this->actingAs($usuarioA)->get("/work-orders/{$ordemB->id}");

        $resposta->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Papel técnico sem vínculo: listagem vazia, nunca a lista completa
    // -----------------------------------------------------------------

    public function test_usuario_com_papel_tecnico_e_sem_tecnico_vinculado_ve_listagem_vazia(): void
    {
        $usuarioSemVinculo = $this->criarUsuarioComPapel('tecnico');

        $tecnico = $this->criarTecnico();
        $this->criarOrdemDeServico(['technician_id' => $tecnico->id]);

        $resposta = $this->actingAs($usuarioSemVinculo)->get('/work-orders');

        $resposta->assertOk();

        $this->assertCount(
            0,
            $this->idsDaListagem($resposta),
            'usuário com papel técnico e sem vínculo deveria ver listagem vazia, não a lista completa'
        );
    }

    // -----------------------------------------------------------------
    // Administrador: enxerga tudo, sem filtro
    // -----------------------------------------------------------------

    public function test_administrador_ve_as_ordens_de_servico_dos_dois_tecnicos(): void
    {
        $tecnicoA = $this->criarTecnico();
        $ordemA = $this->criarOrdemDeServico(['technician_id' => $tecnicoA->id]);

        $tecnicoB = $this->criarTecnico();
        $ordemB = $this->criarOrdemDeServico(['technician_id' => $tecnicoB->id]);

        $administrador = $this->criarUsuarioComPapel('administrador');

        $resposta = $this->actingAs($administrador)->get('/work-orders');

        $resposta->assertOk();

        $ids = $this->idsDaListagem($resposta);

        $this->assertTrue($ids->contains($ordemA->id), 'administrador deveria ver a ordem de serviço do técnico A');
        $this->assertTrue($ids->contains($ordemB->id), 'administrador deveria ver a ordem de serviço do técnico B');
    }

    // -----------------------------------------------------------------
    // Atribuição pela pivot work_order_technicians
    // -----------------------------------------------------------------

    /**
     * A ordem não tem technician_id preenchido, só o vínculo pela pivot. O
     * técnico atribuído por esse caminho precisa ver a ordem na listagem e
     * conseguir abrir o detalhe, do mesmo jeito que veria uma ordem atribuída
     * pela coluna direta.
     */
    public function test_ordem_de_servico_atribuida_pela_pivot_aparece_para_o_tecnico_correspondente(): void
    {
        $tecnico = $this->criarTecnico();
        $usuario = $this->criarUsuarioTecnico($tecnico);

        $ordem = $this->criarOrdemDeServico();
        $ordem->technicians()->attach($tecnico->id);

        $this->assertNull($ordem->fresh()->technician_id, 'o cenário exige que a ordem não tenha technician_id direto');

        $respostaListagem = $this->actingAs($usuario)->get('/work-orders');
        $respostaListagem->assertOk();

        $this->assertTrue(
            $this->idsDaListagem($respostaListagem)->contains($ordem->id),
            'ordem atribuída só pela pivot deveria aparecer na listagem do técnico'
        );

        $respostaDetalhe = $this->actingAs($usuario)->get("/work-orders/{$ordem->id}");
        $respostaDetalhe->assertOk();
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function criarUsuarioComPapel(string $papel): User
    {
        $usuario = User::factory()->create();
        $usuario->assignRole($papel);

        return $usuario;
    }

    /**
     * Cria o técnico e o usuário com papel `tecnico` vinculado a ele em
     * technicians.user_id, exatamente como UserService::criar() faz.
     */
    private function criarUsuarioTecnico(Technician $tecnico): User
    {
        $usuario = $this->criarUsuarioComPapel('tecnico');

        $tecnico->update(['user_id' => $usuario->id]);

        return $usuario;
    }

    private function criarTecnico(array $atributos = []): Technician
    {
        return Technician::create(array_merge([
            'name' => 'Técnico de teste',
            'email' => 'tecnico-' . uniqid() . '@example.com',
            'phone' => '11999999999',
            'is_active' => true,
        ], $atributos));
    }

    private function criarOrdemDeServico(array $atributos = []): WorkOrder
    {
        return WorkOrderFactory::new()->create($atributos);
    }

    /**
     * Ids das ordens de serviço devolvidas na página `data` do paginador que
     * o WorkOrders/Index recebe via prop `workOrders`.
     */
    private function idsDaListagem($resposta)
    {
        return collect($resposta->inertiaProps('workOrders')['data'])->pluck('id');
    }
}
