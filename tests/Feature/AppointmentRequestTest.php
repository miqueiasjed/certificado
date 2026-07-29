<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\AppointmentRequest;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyAvailabilitySetting;
use App\Models\NotificationQueue;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\EventosDeNotificacao;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Task 16.4 do Plano 16: confirmação e recusa do pedido de horário pela
 * empresa - o painel que transforma `AppointmentRequest` em ordem de serviço,
 * ou recusa com motivo, avisando o solicitante nos dois casos.
 *
 * "Hoje" é fixado, mesmo critério de `AvailabilityServiceTest`: a
 * disponibilidade depende do relógio, e relógio livre em teste de agenda é
 * fonte de falha intermitente.
 *
 * `visitas_por_periodo = 1` na configuração padrão do arquivo inteiro:
 * simplifica o cenário de "período lotado" para uma única OS pré-existente, e
 * não atrapalha os cenários de sucesso porque eles partem sempre de um
 * técnico sem nenhuma OS ainda na data escolhida.
 */
class AppointmentRequestTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-08-10 12:00:00';

    private const DIA_ESCOLHIDO = '2026-08-12';

    private Company $empresa;

    private Company $outraEmpresa;

    private User $administrador;

    private ServiceType $tipoDeServico;

    private Technician $tecnico;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        Carbon::setTestNow(self::HOJE);

        $this->seed(RolesAndPermissionsSeeder::class);

        // A empresa 1 vem da migration de fundação do tenant.
        $this->empresa = Company::query()->firstOrFail();
        $this->empresa->update(['name' => 'Dedetizadora A']);
        $this->empresa = $this->empresa->fresh();

        $this->outraEmpresa = Company::create(['name' => 'Dedetizadora B', 'email' => 'contato@dedetizadora-b.test']);

        $this->administrador = TenantAtual::comTenant(
            (int) $this->empresa->id,
            fn (): User => User::factory()->create(['name' => 'Administradora da Empresa'])
        );
        $this->administrador->assignRole('administrador');

        // Autenticar resolve o tenant pelo usuário, como em uma requisição
        // real. Os dados de apoio (técnico, tipo de serviço, configuração)
        // são criados depois disto para nascerem na empresa certa sem
        // precisar de TenantAtual::comTenant() explícito, mesmo critério de
        // AvailabilityServiceTest.
        $this->actingAs($this->administrador);

        CompanyAvailabilitySetting::create([
            'dias_da_semana' => [1, 2, 3, 4, 5],
            'visitas_por_periodo' => 1,
            'antecedencia_minima_dias' => 0,
            'janela_maxima_dias' => 60,
            'aceita_agendamento_online' => true,
        ]);

        $this->tecnico = $this->criarTecnico('Ana Ferreira');
        $this->tipoDeServico = ServiceType::query()->active()->ordered()->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Confirmar cria a OS e vincula ao pedido
    // -----------------------------------------------------------------

    public function test_confirmar_cria_ordem_de_servico_e_vincula_ao_pedido(): void
    {
        $cliente = ClientFactory::new()->create(['name' => 'Padaria Central']);
        $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);

        $pedido = $this->criarPedido([
            'client_id' => $cliente->id,
            'address_id' => $endereco->id,
        ]);

        $resposta = $this->confirmar($pedido, ['technician_id' => $this->tecnico->id]);

        $resposta->assertOk();
        $resposta->assertJsonPath('success', true);

        $pedido = $pedido->fresh();

        $this->assertSame(AppointmentRequest::SITUACAO_CONFIRMADA, $pedido->situacao);
        $this->assertNotNull($pedido->work_order_id);
        $this->assertSame((int) $this->administrador->id, (int) $pedido->respondida_por);
        $this->assertNotNull($pedido->respondida_em);

        $ordem = WorkOrder::query()->findOrFail($pedido->work_order_id);

        $this->assertSame($cliente->id, $ordem->client_id);
        $this->assertSame($endereco->id, $ordem->address_id);
        $this->assertSame($this->tecnico->id, $ordem->technician_id);
        $this->assertSame(self::DIA_ESCOLHIDO, $ordem->scheduled_date->toDateString());
        $this->assertSame('scheduled', $ordem->status);
        $this->assertSame('agendamento_online', $ordem->origem);

        // A criação da OS também enfileira `visita_agendada` para o cliente
        // (WorkOrderNotificationObserver, Task 14.4), então este teste filtra
        // pelo evento específico da Task 16.4 em vez de assumir um item só na
        // fila.
        $aviso = NotificationQueue::query()->where('evento', EventosDeNotificacao::SOLICITACAO_HORARIO_CONFIRMADA)->sole();

        $this->assertSame($pedido->email, $aviso->destino);
        $this->assertStringContainsString($ordem->order_number, $aviso->corpo);
    }

    // -----------------------------------------------------------------
    // Confirmar pedido de não cliente cria cliente e endereço
    // -----------------------------------------------------------------

    public function test_confirmar_pedido_de_nao_cliente_cria_cliente_e_endereco(): void
    {
        $pedido = $this->criarPedido(['client_id' => null, 'address_id' => null]);

        $this->assertSame(0, Client::query()->count(), 'o pedido não pode ter criado cliente sozinho');

        $resposta = $this->confirmar($pedido, [
            'technician_id' => $this->tecnico->id,
            'cliente' => [
                'name' => 'João da Silva',
                'email' => 'joao@exemplo.test',
                'phone' => '(11) 98888-7777',
            ],
            'endereco' => [
                'street' => 'Rua das Acácias',
                'number' => '120',
                'district' => 'Centro',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zip' => '01000-000',
            ],
        ]);

        $resposta->assertOk();

        $cliente = Client::query()->sole();
        $endereco = Address::query()->sole();

        $this->assertSame('João da Silva', $cliente->name);
        $this->assertSame($cliente->id, $endereco->client_id);
        $this->assertSame('Rua das Acácias', $endereco->street);

        $pedido = $pedido->fresh();

        $this->assertSame($cliente->id, $pedido->client_id, 'a decisão de cadastrar é da empresa, mas o pedido precisa nascer vinculado depois de confirmado');
        $this->assertSame($endereco->id, $pedido->address_id);

        $ordem = WorkOrder::query()->findOrFail($pedido->work_order_id);

        $this->assertSame($cliente->id, $ordem->client_id);
        $this->assertSame($endereco->id, $ordem->address_id);
    }

    public function test_confirmar_pedido_de_nao_cliente_sem_dados_de_cliente_devolve_422(): void
    {
        $pedido = $this->criarPedido(['client_id' => null, 'address_id' => null]);

        $resposta = $this->confirmar($pedido, ['technician_id' => $this->tecnico->id]);

        $resposta->assertStatus(422);
        $this->assertSame(0, Client::query()->count());
        $this->assertSame(AppointmentRequest::SITUACAO_PENDENTE, $pedido->fresh()->situacao);
    }

    // -----------------------------------------------------------------
    // Confirmar em período que lotou devolve 422
    // -----------------------------------------------------------------

    public function test_confirmar_em_periodo_lotado_devolve_422(): void
    {
        $cliente = ClientFactory::new()->create();
        $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);

        // Uma OS de manhã já ocupa a única vaga do teto (visitas_por_periodo
        // = 1) do único técnico ativo, no mesmo dia do pedido. 10h-13h UTC
        // caem em 7h-10h em Brasília (manhã), mesma convenção de
        // AvailabilityServiceTest.
        WorkOrder::create([
            'order_number' => 'OS-000001',
            'client_id' => $cliente->id,
            'address_id' => $endereco->id,
            'technician_id' => $this->tecnico->id,
            'scheduled_date' => self::DIA_ESCOLHIDO,
            'start_time' => self::DIA_ESCOLHIDO.' 13:00:00',
            'status' => 'scheduled',
        ]);

        $pedido = $this->criarPedido([
            'client_id' => $cliente->id,
            'address_id' => $endereco->id,
            'periodo' => AppointmentRequest::PERIODO_MANHA,
        ]);

        $resposta = $this->confirmar($pedido, ['technician_id' => $this->tecnico->id]);

        $resposta->assertStatus(422);

        $this->assertSame(AppointmentRequest::SITUACAO_PENDENTE, $pedido->fresh()->situacao, 'o pedido não pode ser confirmado quando o período lotou');
        $this->assertNull($pedido->fresh()->work_order_id);
        $this->assertSame(1, WorkOrder::query()->count(), 'nenhuma OS nova pode ter sido criada (a única é a que lotou o período)');

        // A OS que lota o período (criada acima, fora do fluxo de
        // confirmação) já enfileira `visita_agendada` por conta própria
        // (WorkOrderNotificationObserver); o que este teste garante é que o
        // aviso específico da Task 16.4 não sai, porque a confirmação foi
        // recusada.
        $this->assertSame(
            0,
            NotificationQueue::query()->where('evento', EventosDeNotificacao::SOLICITACAO_HORARIO_CONFIRMADA)->count(),
            'sem confirmação, o solicitante não pode ser avisado de uma confirmação que não aconteceu'
        );
    }

    // -----------------------------------------------------------------
    // Recusar exige motivo e avisa o solicitante
    // -----------------------------------------------------------------

    public function test_recusar_sem_motivo_devolve_422(): void
    {
        $pedido = $this->criarPedido();

        $resposta = $this->postJson($this->urlRecusar($pedido), []);

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('motivo');

        $this->assertSame(AppointmentRequest::SITUACAO_PENDENTE, $pedido->fresh()->situacao);
        $this->assertSame(0, NotificationQueue::query()->count());
    }

    public function test_recusar_com_motivo_avisa_o_solicitante(): void
    {
        $pedido = $this->criarPedido();

        $resposta = $this->postJson($this->urlRecusar($pedido), [
            'motivo' => 'Fora da área de atendimento.',
        ]);

        $resposta->assertOk();

        $pedido = $pedido->fresh();

        $this->assertSame(AppointmentRequest::SITUACAO_RECUSADA, $pedido->situacao);
        $this->assertSame('Fora da área de atendimento.', $pedido->motivo_recusa);
        $this->assertSame((int) $this->administrador->id, (int) $pedido->respondida_por);
        $this->assertNotNull($pedido->respondida_em);
        $this->assertNull($pedido->work_order_id);

        $this->assertSame(0, Client::query()->count(), 'recusar nunca cria cliente');
        $this->assertSame(0, WorkOrder::query()->count());

        $aviso = NotificationQueue::query()->sole();

        $this->assertSame(EventosDeNotificacao::SOLICITACAO_HORARIO_RECUSADA, $aviso->evento);
        $this->assertSame($pedido->email, $aviso->destino);
        $this->assertStringContainsString('Fora da área de atendimento.', $aviso->corpo);
    }

    // -----------------------------------------------------------------
    // Pedido já respondido devolve 422 com a situação atual
    // -----------------------------------------------------------------

    public function test_confirmar_pedido_ja_confirmado_devolve_422_com_a_situacao(): void
    {
        $cliente = ClientFactory::new()->create();
        $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);
        $pedido = $this->criarPedido(['client_id' => $cliente->id, 'address_id' => $endereco->id]);

        $this->confirmar($pedido, ['technician_id' => $this->tecnico->id])->assertOk();

        $this->assertSame(1, WorkOrder::query()->count());

        $segunda = $this->confirmar($pedido, ['technician_id' => $this->tecnico->id]);

        $segunda->assertStatus(422);
        $segunda->assertJsonPath('situacao', AppointmentRequest::SITUACAO_CONFIRMADA);

        $this->assertSame(1, WorkOrder::query()->count(), 'o segundo atendente não pode criar uma segunda OS para o mesmo pedido');
    }

    public function test_recusar_pedido_ja_recusado_devolve_422_com_a_situacao(): void
    {
        $pedido = $this->criarPedido();

        $this->postJson($this->urlRecusar($pedido), ['motivo' => 'Sem disponibilidade na data e período pedidos.'])
            ->assertOk();

        $segunda = $this->postJson($this->urlRecusar($pedido), ['motivo' => 'Outro motivo qualquer.']);

        $segunda->assertStatus(422);
        $segunda->assertJsonPath('situacao', AppointmentRequest::SITUACAO_RECUSADA);

        $this->assertSame(
            'Sem disponibilidade na data e período pedidos.',
            $pedido->fresh()->motivo_recusa,
            'a segunda tentativa não pode ter sobrescrito o motivo já gravado'
        );
    }

    public function test_recusar_pedido_ja_confirmado_devolve_422_com_a_situacao(): void
    {
        $cliente = ClientFactory::new()->create();
        $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);
        $pedido = $this->criarPedido(['client_id' => $cliente->id, 'address_id' => $endereco->id]);

        $this->confirmar($pedido, ['technician_id' => $this->tecnico->id])->assertOk();

        $resposta = $this->postJson($this->urlRecusar($pedido), ['motivo' => 'Não atende mais.']);

        $resposta->assertStatus(422);
        $resposta->assertJsonPath('situacao', AppointmentRequest::SITUACAO_CONFIRMADA);
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas
    // -----------------------------------------------------------------

    public function test_pedido_de_um_tenant_nao_aparece_no_outro(): void
    {
        $pedidoDaOutraEmpresa = TenantAtual::comTenant(
            (int) $this->outraEmpresa->id,
            function (): AppointmentRequest {
                $cliente = ClientFactory::new()->create();
                $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);

                return AppointmentRequest::create([
                    'client_id' => $cliente->id,
                    'address_id' => $endereco->id,
                    'nome' => 'Cliente da Concorrente',
                    'email' => 'concorrente@exemplo.test',
                    'telefone' => '(11) 97777-0000',
                    'data_preferida' => self::DIA_ESCOLHIDO,
                    'periodo' => AppointmentRequest::PERIODO_MANHA,
                    'situacao' => AppointmentRequest::SITUACAO_PENDENTE,
                    'origem' => AppointmentRequest::ORIGEM_PAGINA_PUBLICA,
                ]);
            }
        );

        $meuPedido = $this->criarPedido();

        $indice = $this->get(route('solicitacoes-de-horario.index', absolute: false));
        $indice->assertOk();

        $ids = collect($indice->inertiaProps('pedidos')['data'])->pluck('id')->all();

        $this->assertContains($meuPedido->id, $ids);
        $this->assertNotContains($pedidoDaOutraEmpresa->id, $ids, 'pedido de outro tenant não pode aparecer na listagem');

        // O escopo global também precisa recusar a ação direta pelo id: quem
        // está autenticado na empresa A não pode responder um pedido da
        // empresa B, mesmo sabendo o id.
        $tecnicoDaOutraEmpresa = TenantAtual::comTenant(
            (int) $this->outraEmpresa->id,
            fn (): Technician => Technician::create([
                'name' => 'Técnico da Concorrente',
                'email' => 'tecnico-concorrente@exemplo.test',
                'phone' => '11999990000',
                'is_active' => true,
            ])
        );

        $resposta = $this->confirmar($pedidoDaOutraEmpresa, ['technician_id' => $tecnicoDaOutraEmpresa->id]);
        $resposta->assertNotFound();

        // Recusar é a outra metade da mesma porta: com motivo válido em mãos,
        // recusar o pedido da concorrente estragaria o dado dela sem nunca
        // aparecer em tela nenhuma.
        $this->postJson($this->urlRecusar($pedidoDaOutraEmpresa), ['motivo' => 'Agenda cheia neste período.'])
            ->assertNotFound();

        $this->assertSame(
            AppointmentRequest::SITUACAO_PENDENTE,
            AppointmentRequest::query()->deTodasAsEmpresas()->findOrFail($pedidoDaOutraEmpresa->id)->situacao,
            'o pedido da outra empresa precisa continuar intocado'
        );

        $this->assertSame(
            0,
            NotificationQueue::query()
                ->deTodasAsEmpresas()
                ->where('company_id', $this->outraEmpresa->id)
                ->count(),
            'nenhum aviso pode sair na outra empresa por causa de uma tentativa vinda daqui'
        );
    }

    // -----------------------------------------------------------------
    // Sem permissão, 403
    // -----------------------------------------------------------------

    public function test_usuario_sem_permissao_de_responder_recebe_403(): void
    {
        $leitura = TenantAtual::comTenant(
            (int) $this->empresa->id,
            fn (): User => User::factory()->create(['name' => 'Usuária de Leitura'])
        );
        $leitura->assignRole('leitura');

        $this->actingAs($leitura);

        $pedido = TenantAtual::comTenant((int) $this->empresa->id, fn (): AppointmentRequest => $this->criarPedido());

        // `leitura` tem agendamento-ver (permissão terminada em "-ver"), e
        // consegue listar.
        $this->getJson(route('solicitacoes-de-horario.index', absolute: false))->assertOk();

        // Mas não tem agendamento-responder.
        $this->confirmar($pedido, ['technician_id' => $this->tecnico->id])->assertForbidden();
        $this->postJson($this->urlRecusar($pedido), ['motivo' => 'Qualquer motivo.'])->assertForbidden();
    }

    public function test_usuario_sem_nenhuma_permissao_recebe_403_ate_para_listar(): void
    {
        $semPapel = TenantAtual::comTenant(
            (int) $this->empresa->id,
            fn (): User => User::factory()->create(['name' => 'Sem Papel Nenhum'])
        );

        $this->actingAs($semPapel);

        $this->getJson(route('solicitacoes-de-horario.index', absolute: false))->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Contador de pendentes no menu
    // -----------------------------------------------------------------

    public function test_contador_de_pendentes_aparece_para_o_frontend(): void
    {
        $this->criarPedido();
        $this->criarPedido(['email' => 'outra@exemplo.test', 'telefone' => '(11) 98888-1111']);

        $confirmado = $this->criarPedido(['email' => 'terceiro@exemplo.test', 'telefone' => '(11) 98888-2222']);
        $cliente = ClientFactory::new()->create();
        $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);
        $confirmado->update(['client_id' => $cliente->id, 'address_id' => $endereco->id]);
        $this->confirmar($confirmado, ['technician_id' => $this->tecnico->id])->assertOk();

        $resposta = $this->get(route('solicitacoes-de-horario.index', absolute: false));

        $resposta->assertOk();

        $this->assertSame(2, $resposta->inertiaProps('pedidosDeHorarioPendentes'));
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function criarTecnico(string $nome, bool $ativo = true): Technician
    {
        return Technician::create([
            'name' => $nome,
            'email' => 'tecnico-'.uniqid().'@exemplo.test',
            'phone' => '11999990000',
            'specialty' => 'Controle de pragas',
            'is_active' => $ativo,
        ]);
    }

    /**
     * @param  array<string, mixed>  $sobrescritos
     */
    private function criarPedido(array $sobrescritos = []): AppointmentRequest
    {
        return AppointmentRequest::create(array_merge([
            'client_id' => null,
            'address_id' => null,
            'nome' => 'Maria Souza',
            'email' => 'maria@exemplo.test',
            'telefone' => '(11) 98888-7777',
            'endereco_texto' => 'Rua das Acácias, 120, Centro',
            'service_type_id' => $this->tipoDeServico->id,
            'data_preferida' => self::DIA_ESCOLHIDO,
            'periodo' => AppointmentRequest::PERIODO_MANHA,
            'observacao' => 'Portão azul, tocar a campainha.',
            'situacao' => AppointmentRequest::SITUACAO_PENDENTE,
            'origem' => AppointmentRequest::ORIGEM_PAGINA_PUBLICA,
        ], $sobrescritos));
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function confirmar(AppointmentRequest $pedido, array $dados): TestResponse
    {
        return $this->postJson(
            route('solicitacoes-de-horario.confirmar', ['pedido' => $pedido->id], absolute: false),
            $dados
        );
    }

    private function urlRecusar(AppointmentRequest $pedido): string
    {
        return route('solicitacoes-de-horario.recusar', ['pedido' => $pedido->id], absolute: false);
    }
}
