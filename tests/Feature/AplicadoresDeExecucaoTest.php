<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Device;
use App\Models\PestSighting;
use App\Models\Room;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAdequation;
use App\Models\WorkOrderDeviceEvent;
use App\Models\WorkOrderPhoto;
use App\Services\AppSyncService;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Database\Factories\AddressFactory;
use Database\Factories\WorkOrderFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 13.2 do Plano 13: os quatro aplicadores de operação de campo
 * (`AplicadorDeEventoDeDispositivo`, `AplicadorDeAvistamento`,
 * `AplicadorDeAdequacao`, `AplicadorDeExecucao`), ligando o que o técnico
 * registra em campo aos Services de domínio já existentes, pelo caminho de
 * sincronização do Plano 12 (`AppSyncService::aplicar()`).
 *
 * Cobre os sete critérios de aceitação da task: evento com `codigo_publico`
 * do endereço certo é gravado, evento de dispositivo de outro endereço é
 * recusado com mensagem própria, avistamento grava praga/produto/quantidade,
 * adequação grava e aceita a foto associada depois, concluir aplica as
 * validações do `WorkOrderService`, OS travada recusa todos os tipos com
 * motivo `os_travada`, e `registrada_em` do celular é preservado em todos.
 */
class AplicadoresDeExecucaoTest extends TestCase
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

    // -----------------------------------------------------------------
    // evento_dispositivo
    // -----------------------------------------------------------------

    public function test_evento_com_codigo_publico_do_endereco_certo_e_gravado(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('Um');
        $ordem = $this->criarOrdem($tecnico);
        $dispositivo = $this->criarDispositivo($ordem->address_id);

        $registradaEm = Carbon::parse('2026-07-20 09:15:00');

        $operacao = $this->operacao($ordem, 'evento_dispositivo', [
            'codigo_publico' => $dispositivo->codigo_publico,
            'event_description' => 'Isca parcialmente consumida',
            'event_observations' => 'Sem sinais de infestação além do previsto',
            'bait_consumption_status' => 'partial',
            'pest_found' => 'Baratas',
        ], $registradaEm);

        $resultado = $this->service->aplicar($operacao, $usuario->fresh());

        $this->assertTrue($resultado->foiAplicada(), (string) $resultado->mensagem);

        $evento = WorkOrderDeviceEvent::where('work_order_id', $ordem->id)->first();

        $this->assertNotNull($evento, 'o evento do dispositivo deveria ter sido gravado');
        $this->assertSame($dispositivo->id, $evento->device_id);
        $this->assertSame('partial', $evento->bait_consumption_status);
        $this->assertSame('Baratas', $evento->pest_found);
        $this->assertSame('2026-07-20', $evento->event_date->toDateString());
        $this->assertNotNull($evento->registrada_em);
        $this->assertTrue($registradaEm->eq($evento->registrada_em));
    }

    public function test_evento_com_id_do_dispositivo_tambem_e_gravado(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('UmB');
        $ordem = $this->criarOrdem($tecnico);
        $dispositivo = $this->criarDispositivo($ordem->address_id);

        $operacao = $this->operacao($ordem, 'evento_dispositivo', [
            'device_id' => $dispositivo->id,
            'event_observations' => 'Registrado pelo id, sem código público',
        ]);

        $resultado = $this->service->aplicar($operacao, $usuario->fresh());

        $this->assertTrue($resultado->foiAplicada(), (string) $resultado->mensagem);
        $this->assertSame(
            1,
            WorkOrderDeviceEvent::where('work_order_id', $ordem->id)->where('device_id', $dispositivo->id)->count()
        );
    }

    public function test_evento_de_dispositivo_de_outro_endereco_e_recusado_com_mensagem_propria(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('Dois');
        $ordem = $this->criarOrdem($tecnico);

        $outroEndereco = TenantAtual::comTenant(
            $this->empresa->id,
            fn () => AddressFactory::new()->create(['client_id' => $ordem->client_id])
        );
        $dispositivoDeOutroEndereco = $this->criarDispositivo($outroEndereco->id);

        $operacao = $this->operacao($ordem, 'evento_dispositivo', [
            'codigo_publico' => $dispositivoDeOutroEndereco->codigo_publico,
        ]);

        $resultado = $this->service->aplicar($operacao, $usuario->fresh());

        $this->assertTrue($resultado->estaEmConflito());
        $this->assertSame('regra_de_negocio', $resultado->conflito->motivo);
        $this->assertStringContainsString('outro endereço', (string) $resultado->mensagem);

        $this->assertSame(
            0,
            WorkOrderDeviceEvent::where('work_order_id', $ordem->id)->count(),
            'dispositivo de outro endereço não pode gerar evento nesta ordem de serviço'
        );
    }

    // -----------------------------------------------------------------
    // avistamento
    // -----------------------------------------------------------------

    public function test_avistamento_grava_praga_produto_e_quantidade(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('Tres');
        $ordem = $this->criarOrdem($tecnico);
        $comodo = $this->criarComodo($ordem->address_id, 'Cozinha');

        $registradaEm = Carbon::parse('2026-07-21 14:00:00');

        $operacao = $this->operacao($ordem, 'avistamento', [
            'room_id' => $comodo->id,
            'pest_type' => 'cockroaches',
            'severity_level' => 'high',
            'estimated_quantity' => 12,
            'applied_product' => 'Gel inseticida X',
            'applied_product_quantity' => 5.5,
            'observations' => 'Avistadas atrás do fogão',
        ], $registradaEm);

        $resultado = $this->service->aplicar($operacao, $usuario->fresh());

        $this->assertTrue($resultado->foiAplicada(), (string) $resultado->mensagem);

        $avistamento = PestSighting::where('work_order_id', $ordem->id)->first();

        $this->assertNotNull($avistamento, 'o avistamento deveria ter sido gravado');
        $this->assertSame('cockroaches', $avistamento->pest_type);
        $this->assertSame('Gel inseticida X', $avistamento->applied_product);
        $this->assertSame(12, $avistamento->estimated_quantity);
        $this->assertEquals(5.5, (float) $avistamento->applied_product_quantity);
        $this->assertSame('Cozinha', $avistamento->location_description);
        $this->assertNotNull($avistamento->registrada_em);
        $this->assertTrue($registradaEm->eq($avistamento->registrada_em));
    }

    public function test_avistamento_de_comodo_de_outro_endereco_e_recusado(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('TresB');
        $ordem = $this->criarOrdem($tecnico);

        $outroEndereco = TenantAtual::comTenant(
            $this->empresa->id,
            fn () => AddressFactory::new()->create(['client_id' => $ordem->client_id])
        );
        $comodoDeOutroEndereco = $this->criarComodo($outroEndereco->id, 'Sala');

        $operacao = $this->operacao($ordem, 'avistamento', [
            'room_id' => $comodoDeOutroEndereco->id,
            'pest_type' => 'ants',
        ]);

        $resultado = $this->service->aplicar($operacao, $usuario->fresh());

        $this->assertTrue($resultado->estaEmConflito());
        $this->assertSame('regra_de_negocio', $resultado->conflito->motivo);
        $this->assertSame(0, PestSighting::where('work_order_id', $ordem->id)->count());
    }

    // -----------------------------------------------------------------
    // adequacao
    // -----------------------------------------------------------------

    public function test_adequacao_grava_e_aceita_a_foto_associada_depois(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('Quatro');
        $ordem = $this->criarOrdem($tecnico);

        $registradaEm = Carbon::parse('2026-07-22 10:00:00');

        $operacaoAdequacao = $this->operacao($ordem, 'adequacao', [
            'type' => 'estrutural',
            'description' => 'Rachadura na parede externa',
            'priority' => 'alta',
            'deadline' => '2026-08-01',
        ], $registradaEm);

        $resultadoAdequacao = $this->service->aplicar($operacaoAdequacao, $usuario->fresh());

        $this->assertTrue($resultadoAdequacao->foiAplicada(), (string) $resultadoAdequacao->mensagem);

        $adequacao = WorkOrderAdequation::where('work_order_id', $ordem->id)->first();

        $this->assertNotNull($adequacao, 'a adequação deveria ter sido gravada');
        $this->assertSame('estrutural', $adequacao->type);
        $this->assertSame('alta', $adequacao->priority);
        $this->assertSame('pendente', $adequacao->status);
        $this->assertNotNull($adequacao->registrada_em);
        $this->assertTrue($registradaEm->eq($adequacao->registrada_em));

        // A foto chega depois, em operação própria do tipo `foto` (Plano 12),
        // associada pelo id desta mesma adequação. Cenário de compatibilidade:
        // quem já conhece o id (ex.: painel web) pode mandar direto.
        $path = "work-orders/{$ordem->id}/photos/adequacao.jpg";
        Storage::disk('public')->put($path, 'conteudo-fake-de-teste');

        $operacaoFoto = $this->operacao($ordem, 'foto', [
            'entity_type' => 'adequation',
            'entity_id' => $adequacao->id,
            'path' => $path,
            'caption' => 'Foto da rachadura',
        ]);

        $resultadoFoto = $this->service->aplicar($operacaoFoto, $usuario->fresh());

        $this->assertTrue($resultadoFoto->foiAplicada(), (string) $resultadoFoto->mensagem);

        $foto = WorkOrderPhoto::where('entity_type', 'adequation')->where('entity_id', $adequacao->id)->first();

        $this->assertNotNull($foto, 'a foto deveria ter sido associada à adequação gravada antes');
        $this->assertSame('Foto da rachadura', $foto->caption);
    }

    /**
     * Cenário real do aplicativo (Task 13.8): o técnico gera o uuid da
     * adequação e tira a foto dela offline, antes de a adequação ter `id` no
     * servidor. A foto chega referenciando o uuid, não o id, e
     * `AplicadorDeFoto::resolverEntityIdDeAdequacao()` precisa traduzir um
     * para o outro.
     */
    public function test_foto_de_adequacao_associada_pelo_uuid_gerado_no_aparelho(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('QuatroC');
        $ordem = $this->criarOrdem($tecnico);

        $uuidDaAdequacao = (string) Str::uuid();

        $operacaoAdequacao = $this->operacao($ordem, 'adequacao', [
            'uuid' => $uuidDaAdequacao,
            'type' => 'estrutural',
            'description' => 'Fresta no rodapé',
            'priority' => 'media',
            'deadline' => '2026-08-05',
        ]);

        $resultadoAdequacao = $this->service->aplicar($operacaoAdequacao, $usuario->fresh());

        $this->assertTrue($resultadoAdequacao->foiAplicada(), (string) $resultadoAdequacao->mensagem);

        $adequacao = WorkOrderAdequation::where('work_order_id', $ordem->id)->first();
        $this->assertSame($uuidDaAdequacao, $adequacao->uuid);

        $path = "work-orders/{$ordem->id}/photos/fresta.jpg";
        Storage::disk('public')->put($path, 'conteudo-fake-de-teste');

        $operacaoFoto = $this->operacao($ordem, 'foto', [
            'entity_type' => 'adequation',
            'entity_id' => $uuidDaAdequacao,
            'path' => $path,
            'caption' => 'Foto da fresta',
        ]);

        $resultadoFoto = $this->service->aplicar($operacaoFoto, $usuario->fresh());

        $this->assertTrue($resultadoFoto->foiAplicada(), (string) $resultadoFoto->mensagem);

        $foto = WorkOrderPhoto::where('entity_type', 'adequation')->where('entity_id', $adequacao->id)->first();

        $this->assertNotNull($foto, 'a foto deveria ter resolvido o uuid para o id real da adequação');
        $this->assertSame('Foto da fresta', $foto->caption);
    }

    /**
     * Uuid que não corresponde a nenhuma adequação (ex.: a operação
     * `adequacao` ainda não sincronizou) recusa com mensagem clara, em vez de
     * gravar um `entity_id` inválido ou estourar erro de banco.
     */
    public function test_foto_de_adequacao_com_uuid_desconhecido_e_recusada(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('QuatroD');
        $ordem = $this->criarOrdem($tecnico);

        $path = "work-orders/{$ordem->id}/photos/orfa.jpg";
        Storage::disk('public')->put($path, 'conteudo-fake-de-teste');

        $operacaoFoto = $this->operacao($ordem, 'foto', [
            'entity_type' => 'adequation',
            'entity_id' => (string) Str::uuid(),
            'path' => $path,
            'caption' => 'Foto órfã',
        ]);

        $resultado = $this->service->aplicar($operacaoFoto, $usuario->fresh());

        $this->assertFalse($resultado->foiAplicada());
    }

    public function test_adequacao_em_os_cancelada_e_recusada_pela_regra_do_service(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('QuatroB');
        $ordem = $this->criarOrdem($tecnico, ['status' => 'cancelled']);

        $operacao = $this->operacao($ordem, 'adequacao', [
            'type' => 'outros',
            'description' => 'Não deveria ser aceita',
            'priority' => 'baixa',
        ]);

        $resultado = $this->service->aplicar($operacao, $usuario->fresh());

        $this->assertTrue($resultado->estaEmConflito());
        $this->assertSame('regra_de_negocio', $resultado->conflito->motivo);
        $this->assertStringContainsString('cancelada', mb_strtolower((string) $resultado->mensagem));
    }

    // -----------------------------------------------------------------
    // execucao: iniciar e concluir
    // -----------------------------------------------------------------

    public function test_iniciar_grava_o_comeco_da_execucao_com_o_instante_do_celular(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('Cinco');
        $ordem = $this->criarOrdem($tecnico, ['status' => 'scheduled', 'start_time' => null]);

        $registradaEm = Carbon::parse('2026-07-24 08:05:00');

        $operacao = $this->operacao($ordem, 'execucao', ['acao' => 'iniciar'], $registradaEm);

        $resultado = $this->service->aplicar($operacao, $usuario->fresh());

        $this->assertTrue($resultado->foiAplicada(), (string) $resultado->mensagem);

        $ordem->refresh();

        $this->assertSame('in_progress', $ordem->status);
        $this->assertNotNull($ordem->start_time);
        $this->assertTrue($registradaEm->eq($ordem->start_time));
    }

    public function test_concluir_aplica_as_validacoes_do_work_order_service(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('Seis');
        $ordem = $this->criarOrdem($tecnico, ['status' => 'in_progress']);

        $registradaEm = Carbon::parse('2026-07-23 17:45:00');

        $operacao = $this->operacao($ordem, 'execucao', ['acao' => 'concluir'], $registradaEm);

        $resultado = $this->service->aplicar($operacao, $usuario->fresh());

        $this->assertTrue($resultado->foiAplicada(), (string) $resultado->mensagem);

        $ordem->refresh();

        // `markAsCompleted()` (WorkOrderService) é quem decide o status: a
        // regra de fechamento não é repetida aqui, só conferida.
        $this->assertSame('completed', $ordem->status);
        $this->assertNotNull($ordem->end_time);
        $this->assertTrue($registradaEm->eq($ordem->end_time));
    }

    public function test_acao_invalida_de_execucao_e_recusada(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('SeteB');
        $ordem = $this->criarOrdem($tecnico);

        $operacao = $this->operacao($ordem, 'execucao', ['acao' => 'pular-etapa']);

        $resultado = $this->service->aplicar($operacao, $usuario->fresh());

        $this->assertTrue($resultado->estaEmConflito());
        $this->assertSame('regra_de_negocio', $resultado->conflito->motivo);
    }

    // -----------------------------------------------------------------
    // recusa_assinatura: correção de lacuna encontrada na Task 13.9 -
    // AplicadorDeRecusaDeAssinatura liga a recusa registrada no aparelho a
    // WorkOrderSignatureService::registrarRecusa()
    // -----------------------------------------------------------------

    public function test_recusa_de_assinatura_via_sincronizacao_grava_motivo_e_mantem_a_os_editavel(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('Nove');
        $ordem = $this->criarOrdem($tecnico, ['status' => 'completed']);

        $operacao = $this->operacao($ordem, 'recusa_assinatura', [
            'motivo' => 'Cliente alegou pressa e não quis assinar na hora.',
        ]);

        $resultado = $this->service->aplicar($operacao, $usuario->fresh());

        $this->assertTrue($resultado->foiAplicada(), (string) $resultado->mensagem);

        $ordem->refresh();

        $this->assertSame('recusada', $ordem->situacao_assinatura);
        $this->assertSame('Cliente alegou pressa e não quis assinar na hora.', $ordem->recusa_motivo);
        $this->assertNotNull($ordem->recusa_registrada_em);
        $this->assertFalse($ordem->estaTravada(), 'recusa não deveria travar a ordem de serviço');
    }

    public function test_recusa_de_assinatura_sem_motivo_e_recusada(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('Dez');
        $ordem = $this->criarOrdem($tecnico, ['status' => 'completed']);

        $operacao = $this->operacao($ordem, 'recusa_assinatura', ['motivo' => '']);

        $resultado = $this->service->aplicar($operacao, $usuario->fresh());

        $this->assertTrue($resultado->estaEmConflito());
        $this->assertSame('regra_de_negocio', $resultado->conflito->motivo);

        $ordem->refresh();
        $this->assertSame('nao_coletada', $ordem->situacao_assinatura);
    }

    // -----------------------------------------------------------------
    // registrada_em do celular preservado, diferente de created_at
    // -----------------------------------------------------------------

    /**
     * Task 13.10: não basta `registrada_em` não ser nulo, o valor gravado
     * precisa ser o instante que o celular mandou (podendo ser dias antes da
     * sincronização, no caso do técnico offline), enquanto `created_at`
     * continua sendo o instante em que o servidor gravou a linha. Fixa o
     * "agora" do teste para comparar os dois valores de forma explícita.
     */
    public function test_registrada_em_do_celular_e_preservado_diferente_do_created_at(): void
    {
        $agora = Carbon::parse('2026-07-28 10:00:00');
        Carbon::setTestNow($agora);

        [$usuario, $tecnico] = $this->criarTecnico('Onze');
        $ordem = $this->criarOrdem($tecnico);
        $dispositivo = $this->criarDispositivo($ordem->address_id);

        $registradaEm = $agora->clone()->subDays(3);

        $operacao = $this->operacao($ordem, 'evento_dispositivo', [
            'codigo_publico' => $dispositivo->codigo_publico,
            'event_observations' => 'Registrado offline há alguns dias, sincronizado agora',
        ], $registradaEm);

        $resultado = $this->service->aplicar($operacao, $usuario->fresh());

        $this->assertTrue($resultado->foiAplicada(), (string) $resultado->mensagem);

        $evento = WorkOrderDeviceEvent::where('work_order_id', $ordem->id)->first();

        $this->assertNotNull($evento);
        $this->assertTrue(
            $registradaEm->eq($evento->registrada_em),
            'registrada_em deveria refletir o instante enviado pelo celular, três dias antes de agora'
        );
        $this->assertTrue(
            $agora->eq($evento->created_at),
            'created_at deveria refletir o instante do servidor no momento da gravação, não o do celular'
        );
        $this->assertFalse(
            $evento->created_at->eq($evento->registrada_em),
            'created_at e registrada_em precisam divergir: um é o instante do servidor, o outro o do celular'
        );
    }

    // -----------------------------------------------------------------
    // OS travada: os seis tipos hoje disponíveis recusam com o mesmo motivo
    // -----------------------------------------------------------------

    public function test_os_travada_recusa_todos_os_tipos_com_motivo_os_travada(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('Oito');
        $ordem = $this->criarOrdem($tecnico, ['status' => 'in_progress']);
        $dispositivo = $this->criarDispositivo($ordem->address_id);

        TenantAtual::comTenant($this->empresa->id, fn () => $ordem->update([
            'situacao_assinatura' => 'assinada',
            'assinada_em' => now(),
        ]));

        $operacoes = [
            'evento_dispositivo' => $this->operacao($ordem, 'evento_dispositivo', [
                'codigo_publico' => $dispositivo->codigo_publico,
            ]),
            'avistamento' => $this->operacao($ordem, 'avistamento', [
                'pest_type' => 'ants',
                'location_description' => 'Cozinha',
            ]),
            'adequacao' => $this->operacao($ordem, 'adequacao', [
                'type' => 'outros',
                'description' => 'Não deveria ser aceita: OS travada',
                'priority' => 'baixa',
            ]),
            'execucao' => $this->operacao($ordem, 'execucao', ['acao' => 'concluir']),
            // `assinatura` e `recusa_assinatura` passam pela mesma checagem
            // central de `AppSyncService::processar()` (`osEstaTravada()`),
            // antes até de chegar no aplicador do tipo: uma OS já assinada
            // recusa qualquer recoleta ou recusa vinda do aparelho pelo
            // caminho comum de sincronização, sem exceção de tipo.
            'assinatura' => $this->operacao($ordem, 'assinatura', [
                'assinante_nome' => 'Cliente Travado',
                'assinante_documento' => '00000000000',
            ]),
            'recusa_assinatura' => $this->operacao($ordem, 'recusa_assinatura', [
                'motivo' => 'Tentativa de recusa numa OS já travada',
            ]),
        ];

        foreach ($operacoes as $tipo => $operacao) {
            $resultado = $this->service->aplicar($operacao, $usuario->fresh());

            $this->assertTrue($resultado->estaEmConflito(), "operação \"{$tipo}\" deveria estar em conflito");
            $this->assertSame(
                'os_travada',
                $resultado->conflito->motivo,
                "operação \"{$tipo}\" deveria ter motivo os_travada"
            );
        }

        $this->assertSame(0, WorkOrderDeviceEvent::where('work_order_id', $ordem->id)->count());
        $this->assertSame(0, PestSighting::where('work_order_id', $ordem->id)->count());
        $this->assertSame(0, WorkOrderAdequation::where('work_order_id', $ordem->id)->count());

        $ordem->refresh();
        $this->assertNotSame('completed', $ordem->status, 'a conclusão não pode ter sido aplicada numa OS travada');
        $this->assertSame(
            'assinada',
            $ordem->situacao_assinatura,
            'a tentativa de nova assinatura não pode ter alterado o estado já travado'
        );
        $this->assertNull($ordem->recusa_motivo, 'a tentativa de recusa numa OS travada não pode ter gravado motivo');
    }

    // -----------------------------------------------------------------
    // Acesso: operação de OS de outro técnico, específico dos aplicadores
    // deste plano (o teste genérico de outro tipo de operação já existe em
    // AppSyncServiceTest, com o tipo `foto` do Plano 12)
    // -----------------------------------------------------------------

    public function test_evento_de_dispositivo_de_os_de_outro_tecnico_e_recusado(): void
    {
        [$usuarioA] = $this->criarTecnico('DozeA');
        [, $tecnicoB] = $this->criarTecnico('DozeB');

        $ordemDoTecnicoB = $this->criarOrdem($tecnicoB);
        $dispositivo = $this->criarDispositivo($ordemDoTecnicoB->address_id);

        $operacao = $this->operacao($ordemDoTecnicoB, 'evento_dispositivo', [
            'codigo_publico' => $dispositivo->codigo_publico,
        ]);

        $resultado = $this->service->aplicar($operacao, $usuarioA->fresh());

        $this->assertTrue($resultado->foiRecusada());
        $this->assertStringContainsString('permissão', mb_strtolower((string) $resultado->mensagem));
        $this->assertSame(
            0,
            WorkOrderDeviceEvent::where('work_order_id', $ordemDoTecnicoB->id)->count(),
            'operação de um técnico na OS de outro técnico não pode gravar evento nenhum'
        );
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas, específico dos aplicadores deste plano
    // -----------------------------------------------------------------

    public function test_operacao_de_evento_dispositivo_com_os_de_outra_empresa_nao_alcanca_nada(): void
    {
        [$usuarioDaEmpresaUm] = $this->criarTecnico('TrezeUm');

        $empresaDois = Company::create(['name' => 'Empresa Treze Dois']);

        [, $tecnicoDaEmpresaDois] = TenantAtual::comTenant($empresaDois->id, function () {
            $usuario = User::factory()->create([
                'name' => 'Técnico TrezeDois',
                'is_active' => true,
            ]);
            $usuario->assignRole('tecnico');

            $tecnico = Technician::create([
                'name' => 'Cadastro Técnico TrezeDois',
                'email' => 'tecnico-trezedois-'.uniqid().'@exemplo.test',
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

        // `aplicar()` fixa o tenant corrente pela empresa do usuário que
        // enviou a operação, nunca pela OS informada. Com o escopo global
        // ativo, a busca por `work_order_id` dentro do tenant da empresa Um
        // não encontra a OS da empresa Dois, e o efeito é o mesmo de
        // `registro_removido`: a operação nunca chega ao aplicador de
        // `evento_dispositivo`.
        $operacao = $this->operacao($ordemDaEmpresaDois, 'evento_dispositivo', [
            'codigo_publico' => 'NAO-IMPORTA',
        ]);

        $resultado = $this->service->aplicar($operacao, $usuarioDaEmpresaUm->fresh());

        $this->assertFalse($resultado->foiAplicada(), 'operação com work_order_id de outra empresa nunca pode ser aplicada');
        $this->assertTrue($resultado->estaEmConflito());
        $this->assertSame('registro_removido', $resultado->conflito->motivo);
        $this->assertSame(
            0,
            WorkOrderDeviceEvent::where('work_order_id', $ordemDaEmpresaDois->id)->count(),
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

    private function criarOrdem(Technician $tecnico, array $atributos = []): WorkOrder
    {
        return TenantAtual::comTenant(
            $this->empresa->id,
            fn () => WorkOrderFactory::new()->create(array_merge(['technician_id' => $tecnico->id], $atributos))
        );
    }

    private function criarDispositivo(int $addressId): Device
    {
        return TenantAtual::comTenant($this->empresa->id, fn () => Device::create([
            'address_id' => $addressId,
            'label' => 'Disp '.uniqid(),
            'number' => 'N-'.uniqid(),
            'active' => true,
        ]));
    }

    private function criarComodo(int $addressId, string $nome): Room
    {
        return TenantAtual::comTenant($this->empresa->id, fn () => Room::create([
            'address_id' => $addressId,
            'name' => $nome,
            'active' => true,
        ]));
    }

    /**
     * Operação de sincronização genérica, no mesmo formato aceito por
     * `AppSyncService::aplicar()`.
     */
    private function operacao(WorkOrder $ordem, string $tipo, array $payload, ?Carbon $registradaEm = null): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'tipo' => $tipo,
            'work_order_id' => $ordem->id,
            'registrada_em' => ($registradaEm ?? now())->toJSON(),
            'updated_at_conhecido' => null,
            'payload' => $payload,
        ];
    }
}
