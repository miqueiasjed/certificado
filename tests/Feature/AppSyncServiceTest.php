<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SyncConflict;
use App\Models\SyncOperation;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPhoto;
use App\Services\AppSyncService;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Database\Factories\WorkOrderFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 12.4 do Plano 12: aplicação idempotente das operações de sincronização
 * do aplicativo do técnico e detecção de conflito.
 *
 * Cobre `AppSyncService::aplicar()` (idempotência pelo `operacao_uuid`,
 * detecção de `registro_alterado`, `registro_removido` e `regra_de_negocio`,
 * recusa por falta de acesso à OS) e `AppSyncService::resolver()` (aplicação
 * do lado guardado do aplicativo e registro em auditoria).
 */
class AppSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private AppSyncService $service;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->empresa = Company::query()->firstOrFail();
        $this->service = app(AppSyncService::class);

        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * `work_orders.updated_at` é `timestamp`, sem fração de segundo. Duas
     * gravações no mesmo segundo de relógio real ficam com o mesmo valor, o
     * que esconderia a diferença que `registro_alterado` precisa enxergar.
     * Avança o relógio de teste para garantir um `updated_at` estritamente
     * posterior ao capturado antes.
     */
    private function avancarRelogio(int $segundos = 5): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds($segundos));
    }

    // -----------------------------------------------------------------
    // Idempotência
    // -----------------------------------------------------------------

    public function test_reenviar_a_mesma_operacao_aplica_uma_vez_e_devolve_o_mesmo_resultado(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('Um');
        $ordem = $this->criarOrdem($tecnico);

        $path = $this->criarFotoNoDisco($ordem, 'foto-idempotencia.jpg');
        $operacao = $this->operacaoDeFoto($ordem, $path);

        $resultado1 = $this->service->aplicar($operacao, $usuario->fresh());
        $resultado2 = $this->service->aplicar($operacao, $usuario->fresh());

        $this->assertTrue($resultado1->foiAplicada());
        $this->assertTrue($resultado2->foiAplicada());
        $this->assertSame($resultado1->operacao->id, $resultado2->operacao->id);

        $this->assertSame(
            1,
            SyncOperation::where('operacao_uuid', $operacao['uuid'])->count(),
            'reenviar a mesma operação não pode gravar uma segunda sync_operation'
        );
        $this->assertSame(
            1,
            WorkOrderPhoto::where('work_order_id', $ordem->id)->count(),
            'reenviar a mesma operação não pode criar a foto duas vezes'
        );
    }

    public function test_reenviar_uma_operacao_em_conflito_devolve_o_mesmo_conflito_sem_duplicar(): void
    {
        [$usuario] = $this->criarTecnico('Oito');

        $operacao = [
            'uuid' => (string) Str::uuid(),
            'tipo' => 'foto',
            'work_order_id' => 888888,
            'registrada_em' => now(),
            'updated_at_conhecido' => null,
            'payload' => ['entity_type' => 'room_event', 'path' => 'nao-importa.jpg'],
        ];

        $resultado1 = $this->service->aplicar($operacao, $usuario->fresh());
        $resultado2 = $this->service->aplicar($operacao, $usuario->fresh());

        $this->assertTrue($resultado1->estaEmConflito());
        $this->assertSame($resultado1->conflito->id, $resultado2->conflito->id);
        $this->assertSame(
            1,
            SyncConflict::where('sync_operation_id', $resultado1->operacao->id)->count()
        );
    }

    // -----------------------------------------------------------------
    // registro_alterado
    // -----------------------------------------------------------------

    public function test_updated_at_desatualizado_abre_conflito_registro_alterado_sem_perder_o_valor_do_tecnico(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('Dois');
        $ordem = $this->criarOrdem($tecnico);

        $updatedAtConhecidoPeloApp = $ordem->fresh()->updated_at;
        $this->avancarRelogio();

        // Alguém altera a OS pelo painel enquanto o técnico está offline.
        TenantAtual::comTenant(
            $this->empresa->id,
            fn () => $ordem->update(['observations' => 'Alterado por outra pessoa'])
        );

        $path = $this->criarFotoNoDisco($ordem, 'foto-desatualizada.jpg');

        $operacao = $this->operacaoDeFoto($ordem, $path, [
            'updated_at_conhecido' => $updatedAtConhecidoPeloApp->toJSON(),
            'payload' => [
                'entity_type' => 'room_event',
                'entity_id' => null,
                'room_id' => null,
                'path' => $path,
                'caption' => 'Registro do técnico antes da alteração',
            ],
        ]);

        $resultado = $this->service->aplicar($operacao, $usuario->fresh());

        $this->assertTrue($resultado->estaEmConflito());
        $this->assertSame('registro_alterado', $resultado->conflito->motivo);
        $this->assertSame(
            'Registro do técnico antes da alteração',
            $resultado->conflito->valor_do_aplicativo['caption']
        );
        $this->assertSame(
            0,
            WorkOrderPhoto::where('work_order_id', $ordem->id)->count(),
            'operação em conflito não deveria ter sido aplicada'
        );
    }

    // -----------------------------------------------------------------
    // registro_removido
    // -----------------------------------------------------------------

    public function test_os_inexistente_abre_conflito_registro_removido(): void
    {
        [$usuario] = $this->criarTecnico('Tres');

        $operacao = [
            'uuid' => (string) Str::uuid(),
            'tipo' => 'foto',
            'work_order_id' => 999999,
            'registrada_em' => now(),
            'updated_at_conhecido' => null,
            'payload' => [
                'entity_type' => 'room_event',
                'path' => 'nao-importa.jpg',
                'caption' => 'Foto de uma OS que sumiu',
            ],
        ];

        $resultado = $this->service->aplicar($operacao, $usuario->fresh());

        $this->assertTrue($resultado->estaEmConflito());
        $this->assertSame('registro_removido', $resultado->conflito->motivo);
        $this->assertNull($resultado->conflito->work_order_id);
        $this->assertSame(999999, $resultado->conflito->valor_do_aplicativo['work_order_id']);
    }

    // -----------------------------------------------------------------
    // regra_de_negocio
    // -----------------------------------------------------------------

    public function test_recusa_por_regra_de_negocio_grava_o_motivo_em_portugues(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('Quatro');
        $ordem = $this->criarOrdem($tecnico);

        $operacao = $this->operacaoDeFoto($ordem, 'inexistente.jpg', [
            'payload' => [
                'entity_type' => 'tipo-invalido',
                'path' => 'inexistente.jpg',
            ],
        ]);

        $resultado = $this->service->aplicar($operacao, $usuario->fresh());

        $this->assertTrue($resultado->estaEmConflito());
        $this->assertSame('regra_de_negocio', $resultado->conflito->motivo);
        $this->assertStringContainsString('Tipo de foto desconhecido', (string) $resultado->mensagem);
    }

    // -----------------------------------------------------------------
    // resolver(): lado do aplicativo + auditoria
    // -----------------------------------------------------------------

    public function test_resolver_pelo_lado_do_aplicativo_aplica_o_valor_guardado_e_aparece_na_auditoria(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('Cinco');
        $administrador = $this->criarAdministrador('CincoAdmin');
        $ordem = $this->criarOrdem($tecnico);

        $updatedAtConhecidoPeloApp = $ordem->fresh()->updated_at;
        $this->avancarRelogio();

        TenantAtual::comTenant(
            $this->empresa->id,
            fn () => $ordem->update(['observations' => 'Alterado enquanto o técnico estava offline'])
        );

        $path = $this->criarFotoNoDisco($ordem, 'foto-a-resolver.jpg');

        $operacao = $this->operacaoDeFoto($ordem, $path, [
            'updated_at_conhecido' => $updatedAtConhecidoPeloApp->toJSON(),
            'payload' => [
                'entity_type' => 'room_event',
                'entity_id' => null,
                'room_id' => null,
                'path' => $path,
                'caption' => 'Foto registrada pelo técnico em campo',
            ],
        ]);

        $resultado = $this->service->aplicar($operacao, $usuario->fresh());
        $this->assertTrue($resultado->estaEmConflito());

        $conflitoResolvido = $this->service->resolver($resultado->conflito, 'aplicativo', $administrador->fresh());

        $this->assertSame('resolvido_aplicativo', $conflitoResolvido->situacao);
        $this->assertSame($administrador->id, $conflitoResolvido->resolvido_por);
        $this->assertNotNull($conflitoResolvido->resolvido_em);

        $foto = WorkOrderPhoto::where('work_order_id', $ordem->id)->first();
        $this->assertNotNull(
            $foto,
            'resolver pelo lado do aplicativo deveria ter criado a foto guardada no conflito'
        );
        $this->assertSame('Foto registrada pelo técnico em campo', $foto->caption);

        $this->assertSame(
            'aplicada',
            SyncOperation::find($resultado->operacao->id)->situacao,
            'a sync_operation original deveria ficar marcada como aplicada depois da resolução'
        );

        $log = AuditLog::where('auditable_type', SyncConflict::class)
            ->where('auditable_id', $conflitoResolvido->id)
            ->first();

        $this->assertNotNull($log, 'a resolução do conflito deveria aparecer na auditoria');
        $this->assertSame($administrador->id, $log->user_id);
    }

    // -----------------------------------------------------------------
    // Acesso: OS de outro técnico
    // -----------------------------------------------------------------

    public function test_operacao_de_os_de_outro_tecnico_e_recusada(): void
    {
        [$usuarioA] = $this->criarTecnico('SeteA');
        [, $tecnicoB] = $this->criarTecnico('SeteB');

        $ordemDoTecnicoB = $this->criarOrdem($tecnicoB);

        $path = $this->criarFotoNoDisco($ordemDoTecnicoB, 'foto-outro-tecnico.jpg');
        $operacao = $this->operacaoDeFoto($ordemDoTecnicoB, $path);

        $resultado = $this->service->aplicar($operacao, $usuarioA->fresh());

        $this->assertTrue($resultado->foiRecusada());
        $this->assertStringContainsString('permissão', mb_strtolower((string) $resultado->mensagem));
        $this->assertSame(
            0,
            SyncConflict::where('sync_operation_id', $resultado->operacao->id)->count(),
            'recusa por falta de acesso não deveria abrir um conflito'
        );
    }

    // -----------------------------------------------------------------
    // Acesso: OS de outra empresa (outro tenant)
    // -----------------------------------------------------------------

    /**
     * Task 12.12: uma operação que referencia `work_order_id` de outra
     * empresa não pode alcançar nada. `aplicar()` fixa o tenant corrente como
     * o da empresa do usuário que enviou a operação (`$usuario->company_id`),
     * nunca o da OS informada. Com o escopo global de `BelongsToCompany`
     * ativo, `WorkOrder::find()` roda filtrado pela empresa do técnico A e
     * por isso não encontra a OS da empresa B, exatamente como se ela
     * tivesse sido removida. O efeito observável é o mesmo caminho de
     * `registro_removido`, nunca a aplicação da operação nem uma exceção.
     */
    public function test_operacao_com_work_order_de_outra_empresa_nao_alcanca_nada(): void
    {
        [$usuarioDaEmpresaUm] = $this->criarTecnico('OnzeUm');

        $empresaDois = Company::create(['name' => 'Empresa Onze Dois']);

        [, $tecnicoDaEmpresaDois] = TenantAtual::comTenant($empresaDois->id, function () {
            $usuario = User::factory()->create([
                'name' => 'Técnico OnzeDois',
                'is_active' => true,
            ]);
            $usuario->assignRole('tecnico');

            $tecnico = Technician::create([
                'name' => 'Cadastro Técnico OnzeDois',
                'email' => 'tecnico-onzedois-'.uniqid().'@exemplo.test',
                'phone' => '11999990000',
                'is_active' => true,
                'user_id' => $usuario->id,
            ]);

            return [$usuario, $tecnico];
        });

        $ordemDaEmpresaDois = TenantAtual::comTenant(
            $empresaDois->id,
            fn () => WorkOrderFactory::new()->create(['technician_id' => $tecnicoDaEmpresaDois->id])
        );

        $path = $this->criarFotoNoDisco($ordemDaEmpresaDois, 'foto-outra-empresa.jpg');
        $operacao = $this->operacaoDeFoto($ordemDaEmpresaDois, $path);

        $resultado = $this->service->aplicar($operacao, $usuarioDaEmpresaUm->fresh());

        $this->assertFalse(
            $resultado->foiAplicada(),
            'operação com work_order_id de outra empresa nunca pode ser aplicada'
        );
        $this->assertTrue(
            $resultado->estaEmConflito(),
            'o escopo global deveria tratar a OS de outra empresa como inexistente, abrindo o mesmo conflito de OS removida'
        );
        $this->assertSame('registro_removido', $resultado->conflito->motivo);
        $this->assertNull($resultado->conflito->work_order_id);
        $this->assertSame(
            $ordemDaEmpresaDois->id,
            $resultado->conflito->valor_do_aplicativo['work_order_id'],
            'o id originalmente pedido continua preservado no conflito, mesmo pertencendo a outra empresa'
        );

        $this->assertSame(
            0,
            WorkOrderPhoto::where('work_order_id', $ordemDaEmpresaDois->id)->count(),
            'a operação de outra empresa não pode gravar nada na OS da empresa alheia'
        );
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * @return array{0: User, 1: Technician}
     */
    private function criarTecnico(string $marca): array
    {
        return TenantAtual::comTenant($this->empresa->id, function () use ($marca) {
            $usuario = User::factory()->create([
                'name' => 'Técnico '.$marca,
                'is_active' => true,
            ]);
            $usuario->assignRole('tecnico');

            $tecnico = Technician::create([
                'name' => 'Cadastro Técnico '.$marca,
                'email' => 'tecnico-'.strtolower($marca).'-'.uniqid().'@exemplo.test',
                'phone' => '11999990000',
                'is_active' => true,
                'user_id' => $usuario->id,
            ]);

            return [$usuario, $tecnico];
        });
    }

    private function criarAdministrador(string $marca): User
    {
        $administrador = TenantAtual::comTenant($this->empresa->id, fn () => User::factory()->create([
            'name' => 'Administrador '.$marca,
            'is_active' => true,
        ]));

        $administrador->assignRole('administrador');

        return $administrador;
    }

    private function criarOrdem(Technician $tecnico, array $atributos = []): WorkOrder
    {
        return TenantAtual::comTenant(
            $this->empresa->id,
            fn () => WorkOrderFactory::new()->create(array_merge(['technician_id' => $tecnico->id], $atributos))
        );
    }

    /**
     * Simula o upload já feito pela requisição própria de foto (Task 12.5):
     * o arquivo já está no disco antes da operação de sincronização chegar.
     */
    private function criarFotoNoDisco(WorkOrder $ordem, string $nomeDoArquivo): string
    {
        $path = "work-orders/{$ordem->id}/photos/{$nomeDoArquivo}";
        Storage::disk('public')->put($path, 'conteudo-fake-de-teste');

        return $path;
    }

    private function operacaoDeFoto(WorkOrder $ordem, string $path, array $sobrepor = []): array
    {
        return array_merge([
            'uuid' => (string) Str::uuid(),
            'tipo' => 'foto',
            'work_order_id' => $ordem->id,
            'registrada_em' => now(),
            'updated_at_conhecido' => $ordem->fresh()->updated_at->toJSON(),
            'payload' => [
                'entity_type' => 'room_event',
                'entity_id' => null,
                'room_id' => null,
                'path' => $path,
                'caption' => 'Foto de teste',
            ],
        ], $sobrepor);
    }
}
