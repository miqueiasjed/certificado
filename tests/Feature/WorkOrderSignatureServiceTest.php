<?php

namespace Tests\Feature;

use App\Exceptions\OsTravadaException;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderSignature;
use App\Services\WorkOrderService;
use App\Services\WorkOrderSignatureService;
use App\Support\TenantAtual;
use Database\Factories\WorkOrderFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Task 13.3 do Plano 13: coleta, recusa e correção justificada da assinatura
 * do cliente, e o travamento da OS que a assinatura ativa.
 *
 * Cobre `WorkOrderSignatureService` (`coletar`, `registrarRecusa`,
 * `corrigirComJustificativa`) e a guarda de OS travada acrescentada a
 * `WorkOrderService` (`updateWorkOrder`, `updateStatus`, `markAsCompleted`).
 */
class WorkOrderSignatureServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * PNG 1x1 transparente, o menor PNG válido possível, para não depender de
     * `extension_loaded('gd')` na suíte.
     */
    private const PNG_BASE64_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private Company $empresa;

    private WorkOrderSignatureService $service;

    private WorkOrderService $workOrderService;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->empresa = Company::query()->firstOrFail();
        $this->service = app(WorkOrderSignatureService::class);
        $this->workOrderService = app(WorkOrderService::class);

        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // coletar(): OS concluída
    // -----------------------------------------------------------------

    public function test_coletar_em_os_concluida_grava_a_assinatura_e_trava_a_os(): void
    {
        $administrador = $this->criarUsuario('administrador', 'Um');
        $ordem = $this->criarOrdem(['status' => 'completed']);

        $assinatura = TenantAtual::comTenant(
            $this->empresa->id,
            fn () => $this->service->coletar($ordem, $this->dadosDeAssinatura(), $administrador->fresh())
        );

        $this->assertInstanceOf(WorkOrderSignature::class, $assinatura);
        $this->assertNotNull($assinatura->imagem_path);
        Storage::disk('public')->assertExists($assinatura->imagem_path);

        $ordem->refresh();
        $this->assertSame('assinada', $ordem->situacao_assinatura);
        $this->assertNotNull($ordem->assinada_em);
        $this->assertTrue($ordem->estaTravada());
    }

    // -----------------------------------------------------------------
    // coletar(): OS não concluída
    // -----------------------------------------------------------------

    public function test_coletar_em_os_nao_concluida_e_recusado(): void
    {
        $administrador = $this->criarUsuario('administrador', 'Dois');
        $ordem = $this->criarOrdem(['status' => 'in_progress']);

        $this->expectException(ValidationException::class);

        TenantAtual::comTenant(
            $this->empresa->id,
            fn () => $this->service->coletar($ordem, $this->dadosDeAssinatura(), $administrador->fresh())
        );
    }

    // -----------------------------------------------------------------
    // Guarda de OS travada em WorkOrderService (caminho comum)
    // -----------------------------------------------------------------

    public function test_atualizar_os_travada_pelo_caminho_comum_devolve_excecao_de_dominio_em_portugues(): void
    {
        $administrador = $this->criarUsuario('administrador', 'Tres');
        $ordem = $this->criarOrdem(['status' => 'completed']);

        TenantAtual::comTenant(
            $this->empresa->id,
            fn () => $this->service->coletar($ordem, $this->dadosDeAssinatura(), $administrador->fresh())
        );

        $ordem->refresh();

        foreach ($this->metodosDeAtualizacao() as $rotulo => $chamada) {
            try {
                TenantAtual::comTenant($this->empresa->id, fn () => $chamada($this->workOrderService, $ordem));
                $this->fail("Esperava App\\Exceptions\\OsTravadaException para \"{$rotulo}\", nenhuma exceção foi lançada.");
            } catch (OsTravadaException $excecao) {
                $this->assertStringContainsString('assinada', mb_strtolower($excecao->getMessage()));
                $this->assertStringContainsString('travada', mb_strtolower($excecao->getMessage()));
            }
        }
    }

    /**
     * @return array<string, callable(WorkOrderService, WorkOrder): mixed>
     */
    private function metodosDeAtualizacao(): array
    {
        return [
            'updateWorkOrder' => fn (WorkOrderService $service, WorkOrder $os) => $service->updateWorkOrder($os, ['observations' => 'Tentativa de edição pelo caminho comum']),
            'updateStatus' => fn (WorkOrderService $service, WorkOrder $os) => $service->updateStatus($os, 'in_progress'),
            'markAsCompleted' => fn (WorkOrderService $service, WorkOrder $os) => $service->markAsCompleted($os),
        ];
    }

    // -----------------------------------------------------------------
    // corrigirComJustificativa(): justificativa válida
    // -----------------------------------------------------------------

    public function test_correcao_com_justificativa_valida_altera_a_os_e_grava_antes_e_depois_na_auditoria(): void
    {
        $administrador = $this->criarUsuario('administrador', 'Quatro');
        $ordem = $this->criarOrdem(['status' => 'completed', 'observations' => 'Observação original']);

        TenantAtual::comTenant(
            $this->empresa->id,
            fn () => $this->service->coletar($ordem, $this->dadosDeAssinatura(), $administrador->fresh())
        );

        $ordem->refresh();
        $justificativa = 'Corrigido o texto da observação após conferência do escritório.';

        $resultado = TenantAtual::comTenant($this->empresa->id, fn () => $this->service->corrigirComJustificativa(
            $ordem,
            fn (WorkOrder $os) => $this->workOrderService->updateWorkOrder($os, ['observations' => 'Observação corrigida']),
            $justificativa,
            $administrador->fresh()
        ));

        $this->assertTrue((bool) $resultado);

        $ordem->refresh();
        $this->assertSame('Observação corrigida', $ordem->observations);

        $log = AuditLog::where('auditable_type', WorkOrder::class)
            ->where('auditable_id', $ordem->id)
            ->where('acao', 'correcao_justificada')
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'a correção justificada deveria aparecer na auditoria');
        $this->assertSame($administrador->id, $log->user_id);
        $this->assertSame('Observação original', $log->valores_antes['observations'] ?? null);
        $this->assertSame('Observação corrigida', $log->valores_depois['observations'] ?? null);
        $this->assertSame($justificativa, $log->valores_depois['justificativa'] ?? null);
    }

    // -----------------------------------------------------------------
    // corrigirComJustificativa(): justificativa curta
    // -----------------------------------------------------------------

    public function test_justificativa_com_dezenove_caracteres_e_recusada(): void
    {
        $administrador = $this->criarUsuario('administrador', 'Cinco');
        $ordem = $this->criarOrdem(['status' => 'completed']);

        TenantAtual::comTenant(
            $this->empresa->id,
            fn () => $this->service->coletar($ordem, $this->dadosDeAssinatura(), $administrador->fresh())
        );

        $justificativaCurta = str_repeat('a', 19);
        $this->assertSame(19, mb_strlen($justificativaCurta));

        $this->expectException(ValidationException::class);

        TenantAtual::comTenant($this->empresa->id, fn () => $this->service->corrigirComJustificativa(
            $ordem,
            fn (WorkOrder $os) => $this->workOrderService->updateWorkOrder($os, ['observations' => 'x']),
            $justificativaCurta,
            $administrador->fresh()
        ));
    }

    // -----------------------------------------------------------------
    // corrigirComJustificativa(): sem a permissão os.corrigir_assinada
    // -----------------------------------------------------------------

    public function test_usuario_sem_permissao_de_correcao_nao_corrige(): void
    {
        $tecnico = $this->criarUsuario('tecnico', 'Seis');
        $administrador = $this->criarUsuario('administrador', 'SeisAdmin');
        $ordem = $this->criarOrdem(['status' => 'completed']);

        TenantAtual::comTenant(
            $this->empresa->id,
            fn () => $this->service->coletar($ordem, $this->dadosDeAssinatura(), $administrador->fresh())
        );

        $this->assertFalse($tecnico->fresh()->can(WorkOrderSignatureService::PERMISSAO_CORRECAO));

        $this->expectException(AuthorizationException::class);

        TenantAtual::comTenant($this->empresa->id, fn () => $this->service->corrigirComJustificativa(
            $ordem,
            fn (WorkOrder $os) => $this->workOrderService->updateWorkOrder($os, ['observations' => 'x']),
            'Justificativa com mais de vinte caracteres',
            $tecnico->fresh()
        ));
    }

    // -----------------------------------------------------------------
    // registrarRecusa(): motivo gravado, OS continua editável
    // -----------------------------------------------------------------

    public function test_recusa_registra_motivo_e_mantem_a_os_editavel(): void
    {
        $administrador = $this->criarUsuario('administrador', 'Sete');
        $ordem = $this->criarOrdem(['status' => 'completed']);

        TenantAtual::comTenant(
            $this->empresa->id,
            fn () => $this->service->registrarRecusa($ordem, 'Cliente se recusou a assinar', $administrador->fresh())
        );

        $ordem->refresh();
        $this->assertSame('recusada', $ordem->situacao_assinatura);
        $this->assertSame('Cliente se recusou a assinar', $ordem->recusa_motivo);
        $this->assertNotNull($ordem->recusa_registrada_em);
        $this->assertFalse($ordem->estaTravada());

        // OS recusada continua editável pelo caminho comum, sem exceção.
        $resultado = TenantAtual::comTenant(
            $this->empresa->id,
            fn () => $this->workOrderService->updateWorkOrder($ordem, ['observations' => 'Editado após a recusa'])
        );

        $this->assertTrue($resultado);
        $this->assertSame('Editado após a recusa', $ordem->fresh()->observations);
    }

    // -----------------------------------------------------------------
    // Recoleta: assinatura anterior fica na auditoria
    // -----------------------------------------------------------------

    public function test_recoleta_guarda_a_assinatura_anterior_na_auditoria_e_atualiza_a_mesma_linha(): void
    {
        $administrador = $this->criarUsuario('administrador', 'Oito');
        $ordem = $this->criarOrdem(['status' => 'completed']);

        $primeiraAssinatura = TenantAtual::comTenant(
            $this->empresa->id,
            fn () => $this->service->coletar(
                $ordem,
                $this->dadosDeAssinatura(['assinante_nome' => 'Fulano Primeiro']),
                $administrador->fresh()
            )
        );

        $justificativa = 'Assinatura errada, cliente pediu para assinar de novo no mesmo atendimento.';

        $segundaAssinatura = TenantAtual::comTenant($this->empresa->id, fn () => $this->service->coletar(
            $ordem->fresh(),
            $this->dadosDeAssinatura(['assinante_nome' => 'Fulano Segundo']),
            $administrador->fresh(),
            $justificativa
        ));

        $this->assertSame(
            $primeiraAssinatura->id,
            $segundaAssinatura->id,
            'a recoleta deve atualizar a mesma linha, nunca inserir uma segunda'
        );
        $this->assertSame(1, WorkOrderSignature::where('work_order_id', $ordem->id)->count());
        $this->assertSame('Fulano Segundo', $segundaAssinatura->fresh()->assinante_nome);

        $logDaAssinaturaAnterior = AuditLog::where('auditable_type', WorkOrderSignature::class)
            ->where('auditable_id', $segundaAssinatura->id)
            ->where('acao', 'alterado')
            ->latest('id')
            ->first();

        $this->assertNotNull(
            $logDaAssinaturaAnterior,
            'a recoleta deveria disparar o audit log automático de WorkOrderSignature (trait Auditavel)'
        );
        $this->assertSame('Fulano Primeiro', $logDaAssinaturaAnterior->valores_antes['assinante_nome'] ?? null);
        $this->assertSame('Fulano Segundo', $logDaAssinaturaAnterior->valores_depois['assinante_nome'] ?? null);

        $logDaCorrecao = AuditLog::where('auditable_type', WorkOrder::class)
            ->where('auditable_id', $ordem->id)
            ->where('acao', 'correcao_justificada')
            ->latest('id')
            ->first();

        $this->assertNotNull($logDaCorrecao, 'a recoleta deveria passar pelo mecanismo de correção justificada');
        $this->assertSame($justificativa, $logDaCorrecao->valores_depois['justificativa'] ?? null);
    }

    public function test_recoleta_sem_justificativa_e_recusada(): void
    {
        $administrador = $this->criarUsuario('administrador', 'Nove');
        $ordem = $this->criarOrdem(['status' => 'completed']);

        TenantAtual::comTenant(
            $this->empresa->id,
            fn () => $this->service->coletar($ordem, $this->dadosDeAssinatura(), $administrador->fresh())
        );

        $this->expectException(ValidationException::class);

        TenantAtual::comTenant($this->empresa->id, fn () => $this->service->coletar(
            $ordem->fresh(),
            $this->dadosDeAssinatura(),
            $administrador->fresh()
        ));
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
            'assinante_documento' => '12345678900',
            'imagem' => self::PNG_BASE64_1X1,
            'coletada_em' => now(),
            'origem' => 'web',
        ], $sobrepor);
    }

    private function criarOrdem(array $atributos = []): WorkOrder
    {
        return TenantAtual::comTenant(
            $this->empresa->id,
            fn () => WorkOrderFactory::new()->create($atributos)
        );
    }

    private function criarUsuario(string $papel, string $marca = ''): User
    {
        $usuario = TenantAtual::comTenant($this->empresa->id, fn () => User::factory()->create([
            'name' => 'Usuário '.$papel.' '.$marca,
            'is_active' => true,
        ]));

        $usuario->assignRole($papel);

        return $usuario;
    }
}
