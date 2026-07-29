<?php

namespace Tests\Feature;

use App\Console\Commands\SyncPermissions;
use App\Models\Company;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use App\Support\TenantAtual;
use Database\Factories\WorkOrderFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 13.4 do Plano 13: os endpoints de painel que assinam, recusam e
 * corrigem uma ordem de serviço já assinada.
 *
 * Cobre `WorkOrderSignatureController` (`store`, `recusar`, `corrigir`), as
 * duas permissões novas do catálogo (`os.assinar`, `os.corrigir_assinada`) e a
 * verificação de dono da OS pelo `WorkOrderAccessService` nas três rotas.
 *
 * A coleta pelo aplicativo em campo (via lote de sincronização) já está
 * coberta por `AplicadoresDeExecucaoTest`/`AppSyncServiceTest`, e o
 * comportamento interno de `WorkOrderSignatureService` já está coberto por
 * `WorkOrderSignatureServiceTest` (Task 13.3). Este arquivo prende a camada
 * HTTP: rota, middleware de permissão e FormRequest.
 */
class WorkOrderSignatureEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * PNG 1x1 transparente, o menor PNG válido possível, para não depender de
     * `extension_loaded('gd')` na suíte.
     */
    private const PNG_BASE64_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->empresa = Company::query()->firstOrFail();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Assinar pelo painel: grava e trava a OS
    // -----------------------------------------------------------------

    public function test_assinar_pelo_painel_grava_e_trava_a_os(): void
    {
        $tecnico = $this->criarTecnico();
        $usuario = $this->criarUsuarioTecnico($tecnico);
        $ordem = $this->criarOrdemDeServico(['technician_id' => $tecnico->id]);

        $resposta = $this->actingAs($usuario)->post(
            "/work-orders/{$ordem->id}/signature",
            $this->dadosDeAssinatura()
        );

        $resposta->assertSessionHasNoErrors();

        $ordem->refresh();
        $this->assertSame('assinada', $ordem->situacao_assinatura);
        $this->assertNotNull($ordem->assinada_em);
        $this->assertTrue($ordem->estaTravada());
    }

    // -----------------------------------------------------------------
    // Assinar OS de outro técnico: 403
    // -----------------------------------------------------------------

    public function test_assinar_os_de_outro_tecnico_devolve_403(): void
    {
        $tecnicoA = $this->criarTecnico();
        $usuarioA = $this->criarUsuarioTecnico($tecnicoA);

        $tecnicoB = $this->criarTecnico();
        $this->criarUsuarioTecnico($tecnicoB);
        $ordemB = $this->criarOrdemDeServico(['technician_id' => $tecnicoB->id]);

        $resposta = $this->actingAs($usuarioA)->postJson(
            "/work-orders/{$ordemB->id}/signature",
            $this->dadosDeAssinatura()
        );

        $resposta->assertForbidden();

        $ordemB->refresh();
        $this->assertSame('nao_coletada', $ordemB->situacao_assinatura, 'a assinatura não deveria ter sido gravada');
    }

    // -----------------------------------------------------------------
    // Registrar recusa: grava motivo e mantém a OS editável
    // -----------------------------------------------------------------

    public function test_registrar_recusa_grava_motivo_e_mantem_a_os_editavel(): void
    {
        $tecnico = $this->criarTecnico();
        $usuario = $this->criarUsuarioTecnico($tecnico);
        $ordem = $this->criarOrdemDeServico(['technician_id' => $tecnico->id]);

        $resposta = $this->actingAs($usuario)->post(
            "/work-orders/{$ordem->id}/signature/refusal",
            ['motivo' => 'Cliente se recusou a assinar']
        );

        $resposta->assertSessionHasNoErrors();

        $ordem->refresh();
        $this->assertSame('recusada', $ordem->situacao_assinatura);
        $this->assertSame('Cliente se recusou a assinar', $ordem->recusa_motivo);
        $this->assertNotNull($ordem->recusa_registrada_em);
        $this->assertFalse($ordem->estaTravada());

        // OS recusada continua editável pelo caminho comum: o usuário ainda
        // está autenticado (actingAs persiste no teste), então o mesmo
        // guard de tenant/autenticação usado pelo painel se aplica aqui.
        $resultado = app(WorkOrderService::class)->updateWorkOrder($ordem, [
            'observations' => 'Editado após a recusa',
        ]);

        $this->assertTrue($resultado);
        $this->assertSame('Editado após a recusa', $ordem->fresh()->observations);
    }

    // -----------------------------------------------------------------
    // Corrigir sem os.corrigir_assinada: 403
    // -----------------------------------------------------------------

    public function test_corrigir_sem_permissao_devolve_403(): void
    {
        $tecnico = $this->criarTecnico();
        $usuario = $this->criarUsuarioTecnico($tecnico);
        $ordem = $this->criarOrdemDeServico(['technician_id' => $tecnico->id]);

        $this->assertFalse($usuario->fresh()->can('os.corrigir_assinada'));

        $resposta = $this->actingAs($usuario)->postJson(
            "/work-orders/{$ordem->id}/correction",
            ['justificativa' => 'Justificativa com mais de vinte caracteres para a correção.']
        );

        $resposta->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Corrigir com justificativa curta: 422
    // -----------------------------------------------------------------

    public function test_corrigir_com_justificativa_curta_devolve_422(): void
    {
        $administrador = $this->criarAdministrador();
        $ordem = $this->criarOrdemDeServico();

        $this->actingAs($administrador)->post(
            "/work-orders/{$ordem->id}/signature",
            $this->dadosDeAssinatura()
        );

        $resposta = $this->actingAs($administrador)->postJson(
            "/work-orders/{$ordem->id}/correction",
            [
                'justificativa' => 'muito curta',
                'observations' => 'Nova observação',
            ]
        );

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('justificativa');
    }

    // -----------------------------------------------------------------
    // Correção justificada válida: administrador corrige a OS assinada
    // -----------------------------------------------------------------

    public function test_administrador_corrige_a_os_assinada_com_justificativa_valida(): void
    {
        $administrador = $this->criarAdministrador();
        $ordem = $this->criarOrdemDeServico();

        $this->actingAs($administrador)->post(
            "/work-orders/{$ordem->id}/signature",
            $this->dadosDeAssinatura()
        );

        $resposta = $this->actingAs($administrador)->post(
            "/work-orders/{$ordem->id}/correction",
            [
                'justificativa' => 'Corrigido o texto da observação após conferência do escritório.',
                'observations' => 'Observação corrigida pelo administrador',
            ]
        );

        $resposta->assertSessionHasNoErrors();

        $ordem->refresh();
        $this->assertSame('Observação corrigida pelo administrador', $ordem->observations);
        $this->assertTrue($ordem->estaTravada(), 'a correção não deveria destravar a OS');
    }

    // -----------------------------------------------------------------
    // CPF inválido no documento: 422
    // -----------------------------------------------------------------

    public function test_cpf_invalido_no_documento_devolve_422(): void
    {
        $tecnico = $this->criarTecnico();
        $usuario = $this->criarUsuarioTecnico($tecnico);
        $ordem = $this->criarOrdemDeServico(['technician_id' => $tecnico->id]);

        $resposta = $this->actingAs($usuario)->postJson(
            "/work-orders/{$ordem->id}/signature",
            $this->dadosDeAssinatura(['assinante_documento' => '11111111111'])
        );

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('assinante_documento');

        $ordem->refresh();
        $this->assertSame('nao_coletada', $ordem->situacao_assinatura);
    }

    // -----------------------------------------------------------------
    // Imagem acima de 300 KB: 422
    // -----------------------------------------------------------------

    public function test_imagem_acima_de_300kb_devolve_422(): void
    {
        $tecnico = $this->criarTecnico();
        $usuario = $this->criarUsuarioTecnico($tecnico);
        $ordem = $this->criarOrdemDeServico(['technician_id' => $tecnico->id]);

        $imagemGigante = str_repeat('A', 300 * 1024 + 1);

        $resposta = $this->actingAs($usuario)->postJson(
            "/work-orders/{$ordem->id}/signature",
            $this->dadosDeAssinatura(['imagem' => $imagemGigante])
        );

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('imagem');
    }

    // -----------------------------------------------------------------
    // Catálogo de permissões: as duas entradas novas existem
    // -----------------------------------------------------------------

    public function test_permissoes_os_assinar_e_os_corrigir_assinada_estao_no_catalogo(): void
    {
        $todasAsPermissoes = collect(SyncPermissions::catalogo())->flatten()->all();

        $this->assertContains('os.assinar', $todasAsPermissoes);
        $this->assertContains('os.corrigir_assinada', $todasAsPermissoes);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function dadosDeAssinatura(array $sobrepor = []): array
    {
        return array_merge([
            'assinante_nome' => 'Cliente de Teste',
            'assinante_documento' => null,
            'imagem' => self::PNG_BASE64_1X1,
            'coletada_em' => now()->subMinute()->toIso8601String(),
        ], $sobrepor);
    }

    /**
     * Todas as fixtures nascem explicitamente dentro do tenant corrente
     * (`TenantAtual::comTenant()`), e não pelo `company_id` implícito da
     * coluna (que só existe como DEFAULT temporário em tabelas antigas,
     * removido pelo Deploy 5). `work_order_signatures` é tabela nova (Task
     * 13.1) e não tem esse default: sem o tenant preenchido explicitamente na
     * criação do usuário, o `company_id` fica `null` em memória e a
     * autenticação (`actingAs()`) resolve o tenant da requisição como
     * nenhum, e a gravação da assinatura falha.
     */
    private function criarOrdemDeServico(array $atributos = []): WorkOrder
    {
        return TenantAtual::comTenant(
            $this->empresa->id,
            fn () => WorkOrderFactory::new()->create(array_merge(['status' => 'completed'], $atributos))
        );
    }

    private function criarAdministrador(): User
    {
        $usuario = TenantAtual::comTenant($this->empresa->id, fn () => User::factory()->create());
        $usuario->assignRole('administrador');

        return $usuario;
    }

    /**
     * Cria o técnico e o usuário com papel `tecnico` vinculado a ele em
     * `technicians.user_id`, exatamente como `UserService::criar()` faz.
     */
    private function criarUsuarioTecnico(Technician $tecnico): User
    {
        $usuario = TenantAtual::comTenant($this->empresa->id, fn () => User::factory()->create());
        $usuario->assignRole('tecnico');

        $tecnico->update(['user_id' => $usuario->id]);

        return $usuario;
    }

    private function criarTecnico(array $atributos = []): Technician
    {
        return TenantAtual::comTenant($this->empresa->id, fn () => Technician::create(array_merge([
            'name' => 'Técnico de teste',
            'email' => 'tecnico-'.uniqid().'@example.com',
            'phone' => '11999999999',
            'is_active' => true,
        ], $atributos)));
    }
}
