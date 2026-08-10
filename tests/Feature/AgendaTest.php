<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Company;
use App\Models\Compromisso;
use App\Models\Service;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\TenantAtual;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Factories\CompromissoFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 10.8 do Plano 10: os doze casos das rotas da agenda (Tasks 10.1 e 10.3).
 *
 * Dois casos são entregável explícito do plano e não podem ser afrouxados:
 *
 * - Caso 8: reagendar com conflito devolve 422 nomeando a ordem conflitante.
 *   Comentar a checagem de conflito em `AgendaController@reagendar` faz este
 *   caso falhar, o que foi conferido durante a escrita do teste.
 * - Caso 10: o reagendamento aparece em `audit_logs` com autor, instante e
 *   valores antes e depois. A asserção lê o registro gravado, e não a resposta
 *   HTTP: sem a trait `Auditavel` no model não há registro e o caso falha.
 *
 * O caso 6 não repete o `AcessoTecnicoTest` (Task 2.16 do Plano 2): lá o escopo
 * do técnico é testado nas rotas de ordem de serviço, aqui nas rotas da agenda,
 * que são novas.
 *
 * Fuso: `start_time` e `end_time` viajam no formato `Y-m-d\TH:i` já em UTC, a
 * mesma convenção do formulário de ordem de serviço. Os cenários usam horário
 * comercial (13:00 a 22:00 UTC, ou 10:00 a 19:00 de Brasília) para que o
 * instante caia no mesmo dia de `scheduled_date` nos dois fusos.
 */
class AgendaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Período padrão das consultas da agenda nos casos que não dependem de
     * outro mês. Data fixa: agenda com relógio livre falha na virada do mês.
     */
    private const INICIO = '2026-08-01';

    private const FIM = '2026-08-31';

    private const DIA = '2026-08-20';

    private Company $empresaUm;

    private Company $empresaDois;

    private User $administrador;

    private Client $clienteCampinas;

    private Address $enderecoCampinas;

    private Client $clienteSantos;

    private Address $enderecoSantos;

    private Service $desinsetizacao;

    private Service $desratizacao;

    private Technician $ana;

    private Technician $bruno;

    private int $contadorDeOrdens = 0;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->empresaUm = Company::query()->firstOrFail();
        $this->empresaDois = Company::create([
            'name' => 'Dedetizadora Concorrente',
            'cnpj' => '22.222.222/0001-22',
            'email' => 'contato@concorrente.test',
        ]);

        $this->administrador = $this->criarUsuario('administrador', 'Administradora Marta');

        $this->actingAs($this->administrador);

        $this->clienteCampinas = ClientFactory::new()->create(['name' => 'Padaria Central']);
        $this->enderecoCampinas = AddressFactory::new()->create([
            'client_id' => $this->clienteCampinas->id,
            'street' => 'Rua das Acácias',
            'number' => '120',
            'district' => 'Centro',
            'city' => 'Campinas',
        ]);

        $this->clienteSantos = ClientFactory::new()->create(['name' => 'Mercado do Porto']);
        $this->enderecoSantos = AddressFactory::new()->create([
            'client_id' => $this->clienteSantos->id,
            'street' => 'Avenida Beira Mar',
            'number' => '900',
            'district' => 'Gonzaga',
            'city' => 'Santos',
        ]);

        $this->desinsetizacao = Service::create(['name' => 'Desinsetização', 'price' => 300, 'is_active' => true]);
        $this->desratizacao = Service::create(['name' => 'Desratização', 'price' => 400, 'is_active' => true]);

        $this->ana = $this->criarTecnico('Ana Ferreira');
        $this->bruno = $this->criarTecnico('Bruno Souza');
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Caso 1: dados do período com os campos esperados
    // -----------------------------------------------------------------

    public function test_dados_devolve_as_ordens_do_periodo_com_os_campos_esperados(): void
    {
        $ordem = $this->criarOrdem([
            'scheduled_date' => self::DIA,
            'technician_id' => $this->ana->id,
            'service_id' => $this->desinsetizacao->id,
            'start_time' => self::DIA.' 13:00:00',
            'end_time' => self::DIA.' 15:00:00',
            'status' => 'scheduled',
        ]);

        $foraDoPeriodo = $this->criarOrdem(['scheduled_date' => '2026-09-02']);

        $resposta = $this->getJson($this->urlDeDados());

        $resposta->assertOk();

        $this->assertSame(
            ['inicio' => self::INICIO, 'fim' => self::FIM],
            $resposta->json('periodo')
        );

        $ordens = $resposta->json('ordens');

        $this->assertCount(1, $ordens, 'só a ordem dentro do período deveria voltar');
        $this->assertNotContains($foraDoPeriodo->id, array_column($ordens, 'id'));

        $item = $ordens[0];

        $this->assertSame([
            'id',
            // Campo novo, aditivo (Task 30.4): distingue OS de compromisso
            // avulso na lista mesclada da agenda. O resto do formato do item
            // de OS não muda, e é isso que o restante desta asserção prova.
            'tipo_item',
            'numero',
            'data',
            'hora_inicio',
            'hora_fim',
            'cliente',
            'endereco',
            'cidade',
            'servico',
            'status',
            'status_texto',
            'tecnico',
            'sem_tecnico',
            'origem',
        ], array_keys($item), 'o formato do item da agenda mudou: o calendário depende dessas chaves');

        $this->assertSame('os', $item['tipo_item']);
        $this->assertSame($ordem->id, $item['id']);
        $this->assertSame($ordem->order_number, $item['numero']);
        $this->assertSame(self::DIA, $item['data']);
        $this->assertSame('10:00', $item['hora_inicio'], 'o horário chega ao frontend já no fuso de Brasília');
        $this->assertSame('12:00', $item['hora_fim']);
        $this->assertSame(['id' => $this->clienteCampinas->id, 'nome' => 'Padaria Central'], $item['cliente']);
        $this->assertSame('Rua das Acácias, 120 - Centro', $item['endereco']);
        $this->assertSame('Campinas', $item['cidade']);
        $this->assertSame(['id' => $this->desinsetizacao->id, 'nome' => 'Desinsetização'], $item['servico']);
        $this->assertSame('scheduled', $item['status']);
        $this->assertSame('Agendada', $item['status_texto']);
        $this->assertSame(['id' => $this->ana->id, 'nome' => 'Ana Ferreira'], $item['tecnico']);
        $this->assertFalse($item['sem_tecnico']);

        // Período mal formado é bug de integração do frontend e precisa
        // aparecer como erro, não como agenda vazia.
        $this->getJson('/agenda/dados?inicio=01/08/2026&fim=31/08/2026')->assertStatus(422);
        $this->getJson('/agenda/dados?inicio=2026-02-30&fim=2026-03-01')->assertStatus(422);
        $this->getJson('/agenda/dados')->assertStatus(422);
    }

    /**
     * Compatibilidade explícita: sem nenhum `Compromisso` cadastrado no
     * período, o item de OS que `/agenda/dados` devolve precisa continuar
     * exatamente como antes da Task 30.4, a não ser pelo campo aditivo
     * `tipo_item`. O caso 1 acima já teria acusado uma regressão de campo
     * (é o mesmo `assertSame` de chaves), mas este caso isola a garantia como
     * entregável da Task 30.6, sem depender de nenhum outro cenário do
     * arquivo.
     */
    public function test_sem_compromisso_no_periodo_a_resposta_nao_regride_no_formato_do_item_de_os(): void
    {
        $ordem = $this->criarOrdem([
            'scheduled_date' => self::DIA,
            'technician_id' => $this->ana->id,
            'service_id' => $this->desinsetizacao->id,
            'status' => 'scheduled',
        ]);

        $resposta = $this->getJson($this->urlDeDados());

        $resposta->assertOk();

        $itens = $resposta->json('ordens');

        $this->assertCount(1, $itens, 'sem compromisso cadastrado no período, só a ordem deveria aparecer');

        $item = $itens[0];

        $this->assertSame('os', $item['tipo_item']);
        $this->assertSame($ordem->id, $item['id']);
        $this->assertSame([
            'id',
            'tipo_item',
            'numero',
            'data',
            'hora_inicio',
            'hora_fim',
            'cliente',
            'endereco',
            'cidade',
            'servico',
            'status',
            'status_texto',
            'tecnico',
            'sem_tecnico',
            'origem',
        ], array_keys($item), 'o item de OS não pode perder nem ganhar campo além do tipo_item aditivo');
    }

    // -----------------------------------------------------------------
    // Compromisso avulso na agenda (Plano 30, Tasks 30.4 e 30.6)
    // -----------------------------------------------------------------

    /**
     * A mescla da Task 30.4: OS e compromisso no mesmo período, cada um
     * marcado com o `tipo_item` certo e no formato próprio (o de compromisso
     * não tem `numero`, `servico` nem `origem` - conceitos que não existem
     * para ele).
     */
    public function test_dados_devolve_a_os_e_o_compromisso_do_periodo_cada_um_com_o_tipo_item_correto(): void
    {
        $ordem = $this->criarOrdem([
            'scheduled_date' => self::DIA,
            'technician_id' => $this->ana->id,
            'service_id' => $this->desinsetizacao->id,
            'status' => 'scheduled',
        ]);

        $compromisso = $this->criarCompromisso([
            'tipo' => 'orcamento',
            'titulo' => 'Visita de orçamento',
            'technician_id' => $this->bruno->id,
            'data' => self::DIA,
            'hora_inicio' => '08:00:00',
            'hora_fim' => '09:00:00',
        ]);

        $resposta = $this->getJson($this->urlDeDados());

        $resposta->assertOk();

        $itens = $resposta->json('ordens');

        $this->assertCount(2, $itens, 'a agenda deveria trazer a ordem e o compromisso juntos');

        $itemDaOrdem = collect($itens)->firstWhere('tipo_item', 'os');
        $itemDoCompromisso = collect($itens)->firstWhere('tipo_item', 'compromisso');

        $this->assertNotNull($itemDaOrdem, 'a ordem de serviço não veio marcada como tipo_item "os"');
        $this->assertNotNull($itemDoCompromisso, 'o compromisso não veio marcado como tipo_item "compromisso"');

        $this->assertSame($ordem->id, $itemDaOrdem['id']);
        $this->assertSame($compromisso->id, $itemDoCompromisso['id']);

        $this->assertSame([
            'id',
            'tipo_item',
            'tipo',
            'titulo',
            'observacoes',
            'data',
            'hora_inicio',
            'hora_fim',
            'cliente',
            'endereco',
            'cidade',
            'situacao',
            'situacao_texto',
            'tecnico',
            'sem_tecnico',
            'work_order_id',
        ], array_keys($itemDoCompromisso), 'o formato do item de compromisso na agenda mudou');

        $this->assertSame('orcamento', $itemDoCompromisso['tipo']);
        $this->assertSame('Visita de orçamento', $itemDoCompromisso['titulo']);
        $this->assertSame(self::DIA, $itemDoCompromisso['data']);
        $this->assertSame('08:00', $itemDoCompromisso['hora_inicio']);
        $this->assertSame('09:00', $itemDoCompromisso['hora_fim']);
        $this->assertSame(['id' => $this->bruno->id, 'nome' => 'Bruno Souza'], $itemDoCompromisso['tecnico']);
        $this->assertFalse($itemDoCompromisso['sem_tecnico']);
        $this->assertSame('agendado', $itemDoCompromisso['situacao']);
        $this->assertSame('Agendado', $itemDoCompromisso['situacao_texto']);
        $this->assertNull($itemDoCompromisso['work_order_id'], 'compromisso recém-criado ainda não foi promovido');
    }

    /**
     * Mesmo critério de `WorkOrderAccessService`, mas para `Compromisso`
     * (`AgendaController::escoparCompromissosPorUsuario()`): o papel
     * `tecnico` só enxerga os compromissos do próprio `technician_id`. Não
     * repete o caso 6 acima, que já prova o escopo equivalente para OS - aqui
     * o alvo é só o compromisso.
     */
    public function test_tecnico_autenticado_so_recebe_os_proprios_compromissos(): void
    {
        $usuarioDaAna = $this->criarUsuario('tecnico', 'Ana Ferreira');
        $this->ana->update(['user_id' => $usuarioDaAna->id]);

        $usuarioDoBruno = $this->criarUsuario('tecnico', 'Bruno Souza');
        $this->bruno->update(['user_id' => $usuarioDoBruno->id]);

        $daAna = $this->criarCompromisso(['technician_id' => $this->ana->id]);
        $doBruno = $this->criarCompromisso(['technician_id' => $this->bruno->id]);
        $semTecnico = $this->criarCompromisso();

        $idsDaAna = $this->idsDeCompromissosDaAgenda($usuarioDaAna);

        $this->assertSame([$daAna->id], $idsDaAna, 'o técnico só deveria enxergar o próprio compromisso');
        $this->assertNotContains($doBruno->id, $idsDaAna);
        $this->assertNotContains($semTecnico->id, $idsDaAna);

        // O administrador continua vendo todos: sem isto, o escopo poderia
        // estar simplesmente quebrado para todo mundo.
        $idsDoAdministrador = $this->idsDeCompromissosDaAgenda($this->administrador);

        $this->assertEqualsCanonicalizing([$daAna->id, $doBruno->id, $semTecnico->id], $idsDoAdministrador);
    }

    /**
     * Mesmo critério do caso 2 acima (`aplicarFiltroDeSituacao`, para OS),
     * agora para `aplicarFiltroDeSituacaoDoCompromisso`: sem filtro de
     * situação informado, o compromisso cancelado fica de fora; informado
     * (mesmo que só com "cancelado"), ele reaparece.
     */
    public function test_compromisso_cancelado_nao_aparece_sem_o_filtro_de_situacao_pedir(): void
    {
        $agendado = $this->criarCompromisso(['situacao' => 'agendado']);
        $cancelado = $this->criarCompromisso(['situacao' => 'cancelado']);

        $this->assertSame(
            [$agendado->id],
            $this->idsDeCompromissosDaAgenda(),
            'sem filtro de situação o compromisso cancelado deveria ficar de fora'
        );

        $this->assertSame(
            [$cancelado->id],
            $this->idsDeCompromissosDaAgenda(null, ['status' => ['cancelado']]),
            'com o filtro de situação informado, o cancelado volta a aparecer'
        );
    }

    // -----------------------------------------------------------------
    // Caso 2: cada filtro isolado e todos combinados
    // -----------------------------------------------------------------

    public function test_cada_filtro_funciona_isoladamente_e_todos_combinados(): void
    {
        $daAnaEmCampinas = $this->criarOrdem([
            'technician_id' => $this->ana->id,
            'service_id' => $this->desinsetizacao->id,
            'status' => 'scheduled',
        ]);

        $doBrunoEmSantos = $this->criarOrdem([
            'client_id' => $this->clienteSantos->id,
            'address_id' => $this->enderecoSantos->id,
            'technician_id' => $this->bruno->id,
            'service_id' => $this->desratizacao->id,
            'status' => 'pending',
        ]);

        $semTecnicoEmCampinas = $this->criarOrdem([
            'service_id' => $this->desinsetizacao->id,
            'status' => 'completed',
        ]);

        $cancelada = $this->criarOrdem([
            'technician_id' => $this->ana->id,
            'service_id' => $this->desinsetizacao->id,
            'status' => 'cancelled',
        ]);

        $this->assertSame(
            [$daAnaEmCampinas->id, $doBrunoEmSantos->id, $semTecnicoEmCampinas->id],
            $this->idsDaAgenda(),
            'sem filtro de situação a cancelada fica de fora da agenda'
        );

        $this->assertSame(
            [$daAnaEmCampinas->id],
            $this->idsDaAgenda(['technician_id' => $this->ana->id]),
            'o filtro de técnico deveria trazer só as ordens da Ana ainda ativas'
        );

        $this->assertSame(
            [$daAnaEmCampinas->id, $semTecnicoEmCampinas->id],
            $this->idsDaAgenda(['service_id' => $this->desinsetizacao->id])
        );

        $this->assertSame(
            [$doBrunoEmSantos->id],
            $this->idsDaAgenda(['status' => ['pending']])
        );

        $this->assertSame(
            [$daAnaEmCampinas->id, $semTecnicoEmCampinas->id],
            $this->idsDaAgenda(['status' => ['scheduled', 'completed']])
        );

        $this->assertSame(
            [$cancelada->id],
            $this->idsDaAgenda(['status' => ['cancelled']]),
            'com filtro de situação informado, a cancelada volta a aparecer'
        );

        $this->assertSame(
            [$daAnaEmCampinas->id, $semTecnicoEmCampinas->id],
            $this->idsDaAgenda(['cidade' => 'Campi']),
            'o filtro de cidade é por trecho, reaproveitando Address::scopeByCity()'
        );

        $this->assertSame(
            [$doBrunoEmSantos->id],
            $this->idsDaAgenda(['cidade' => 'Santos'])
        );

        $this->assertSame(
            [$daAnaEmCampinas->id],
            $this->idsDaAgenda([
                'technician_id' => $this->ana->id,
                'service_id' => $this->desinsetizacao->id,
                'status' => ['scheduled'],
                'cidade' => 'Campinas',
            ]),
            'os quatro filtros combinados deveriam isolar uma única ordem'
        );

        $this->assertSame(
            [],
            $this->idsDaAgenda([
                'technician_id' => $this->ana->id,
                'service_id' => $this->desinsetizacao->id,
                'status' => ['scheduled'],
                'cidade' => 'Santos',
            ]),
            'combinação sem correspondência precisa devolver lista vazia, e não ignorar um dos filtros'
        );
    }

    // -----------------------------------------------------------------
    // Caso 3: filtro sem_tecnico
    // -----------------------------------------------------------------

    /**
     * Ordem sem responsável é o item mais urgente de uma agenda, e o filtro
     * existe para achá-la. Ordem atribuída só pela pivot continua contando como
     * "sem técnico" aqui de propósito: a agenda mostra
     * `work_orders.technician_id`, que é o campo que o calendário atribui.
     */
    public function test_filtro_sem_tecnico_traz_apenas_as_ordens_sem_tecnico(): void
    {
        $comTecnico = $this->criarOrdem(['technician_id' => $this->ana->id]);
        $semTecnico = $this->criarOrdem();
        $outraSemTecnico = $this->criarOrdem(['scheduled_date' => '2026-08-25']);

        $ids = $this->idsDaAgenda(['technician_id' => 'sem_tecnico']);

        $this->assertSame([$semTecnico->id, $outraSemTecnico->id], $ids);
        $this->assertNotContains($comTecnico->id, $ids);
    }

    // -----------------------------------------------------------------
    // Caso 4: ordem sem data agendada não aparece
    // -----------------------------------------------------------------

    /**
     * `work_orders.scheduled_date` é NOT NULL desde a migration de criação, e
     * continua assim: uma ordem sem data agendada não consegue existir no
     * banco, então o cenário não pode ser montado inserindo a linha (e forçar a
     * coluna a aceitar nulo dentro do teste quebraria a transação do
     * `RefreshDatabase`, com DDL causando commit implícito no MySQL).
     *
     * O que dá para provar, e é o que a agenda precisa garantir no dia em que a
     * coluna virar opcional, é que a consulta carrega a guarda: o
     * `whereNotNull('scheduled_date')` do `AgendaService` sai no SQL executado,
     * e o `whereBetween` do período nunca casa com nulo (em SQL, `NULL BETWEEN
     * a AND b` é desconhecido, jamais verdadeiro). Tirar o `whereNotNull` do
     * serviço derruba este caso.
     */
    public function test_ordem_sem_data_agendada_nao_aparece(): void
    {
        $this->criarOrdem();

        $this->assertFalse(
            Schema::getColumns('work_orders')[array_search(
                'scheduled_date',
                array_column(Schema::getColumns('work_orders'), 'name'),
                true
            )]['nullable'],
            'scheduled_date virou opcional: este caso precisa passar a inserir uma ordem sem data'
        );

        $consultas = [];

        DB::listen(function ($consulta) use (&$consultas): void {
            $consultas[] = str_replace('`', '', $consulta->sql);
        });

        $this->getJson($this->urlDeDados())->assertOk();

        $daAgenda = collect($consultas)
            ->first(fn (string $sql) => str_contains($sql, 'from work_orders') && str_contains($sql, 'between'));

        $this->assertNotNull($daAgenda, 'a consulta da agenda não foi encontrada entre as executadas');

        $this->assertStringContainsString(
            'scheduled_date is not null',
            $daAgenda,
            'a agenda precisa excluir explicitamente a ordem sem data agendada'
        );

        $this->assertSame(
            null,
            DB::selectOne('select (null between ? and ?) as resultado', [self::INICIO, self::FIM])->resultado,
            'ordem com data nula nunca é alcançada pelo intervalo do período'
        );
    }

    // -----------------------------------------------------------------
    // Caso 5: ordem de outra empresa não aparece
    // -----------------------------------------------------------------

    public function test_ordem_de_outra_empresa_nao_aparece(): void
    {
        $daPropriaEmpresa = $this->criarOrdem(['technician_id' => $this->ana->id]);

        $daOutraEmpresa = TenantAtual::comTenant($this->empresaDois->id, function (): WorkOrder {
            $cliente = ClientFactory::new()->create(['name' => 'Cliente da Concorrente']);
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id, 'city' => 'Campinas']);

            return WorkOrder::create([
                'order_number' => 'OS-CONCORRENTE',
                'client_id' => $cliente->id,
                'address_id' => $endereco->id,
                'scheduled_date' => self::DIA,
                'status' => 'scheduled',
            ]);
        });

        $this->assertSame($this->empresaDois->id, (int) $daOutraEmpresa->company_id);

        $resposta = $this->getJson($this->urlDeDados());

        $resposta->assertOk();

        $ids = array_column($resposta->json('ordens'), 'id');

        $this->assertSame([$daPropriaEmpresa->id], $ids, 'a agenda mostrou ordem de outra empresa');
        $this->assertStringNotContainsString('Cliente da Concorrente', $resposta->getContent());
        $this->assertStringNotContainsString('OS-CONCORRENTE', $resposta->getContent());

        // Nem por id: o route model binding não encontra a ordem da outra
        // empresa e responde 404, sem confirmar que ela existe em algum lugar.
        $this->putJson("/agenda/{$daOutraEmpresa->id}/reagendar", [
            'scheduled_date' => '2026-08-21',
            'start_time' => null,
            'end_time' => null,
        ])->assertStatus(404);

        $this->putJson("/agenda/{$daOutraEmpresa->id}/tecnico", [
            'technician_id' => $this->ana->id,
        ])->assertStatus(404);

        $this->assertSame(
            self::DIA,
            TenantAtual::comTenant(
                $this->empresaDois->id,
                fn () => WorkOrder::query()->whereKey($daOutraEmpresa->id)->first()->scheduled_date->toDateString()
            ),
            'a ordem da outra empresa não pode ter sido alterada'
        );
    }

    // -----------------------------------------------------------------
    // Caso 6: técnico autenticado só alcança as próprias ordens
    // -----------------------------------------------------------------

    /**
     * A visão do dia do técnico (`Agenda/MeuDia.vue`, Task 10.7) não tem rota
     * de backend própria: o que existe do lado do servidor é o mesmo
     * `GET /agenda/dados`, pedido para um único dia. Por isso o caso confere o
     * escopo nas duas leituras, a do mês (calendário) e a do dia (visão do
     * técnico), além das rotas de escrita da agenda.
     *
     * O usuário técnico recebe `ordem-servico-editar` direto para este caso:
     * sem a permissão, o middleware barraria antes, e o teste provaria a
     * permissão em vez do dono da ordem, que é o que interessa aqui.
     */
    public function test_tecnico_autenticado_recebe_apenas_as_proprias_ordens(): void
    {
        $usuarioDaAna = $this->criarUsuario('tecnico', 'Ana Ferreira');
        $this->ana->update(['user_id' => $usuarioDaAna->id]);
        $usuarioDaAna->givePermissionTo('ordem-servico-editar');

        $usuarioDoBruno = $this->criarUsuario('tecnico', 'Bruno Souza');
        $this->bruno->update(['user_id' => $usuarioDoBruno->id]);

        $daAna = $this->criarOrdem(['technician_id' => $this->ana->id, 'scheduled_date' => self::DIA]);
        $daAnaPelaPivot = $this->criarOrdem(['scheduled_date' => self::DIA]);
        $daAnaPelaPivot->technicians()->attach($this->ana->id);

        $doBruno = $this->criarOrdem(['technician_id' => $this->bruno->id, 'scheduled_date' => self::DIA]);
        $semTecnico = $this->criarOrdem(['scheduled_date' => self::DIA]);

        $doMes = $this->actingAs($usuarioDaAna)->getJson($this->urlDeDados());
        $doMes->assertOk();

        $idsDoMes = array_column($doMes->json('ordens'), 'id');

        $this->assertContains($daAna->id, $idsDoMes);
        $this->assertContains($daAnaPelaPivot->id, $idsDoMes, 'ordem atribuída pela pivot é do técnico também');
        $this->assertNotContains($doBruno->id, $idsDoMes, 'a agenda mostrou a ordem de outro técnico');
        $this->assertNotContains($semTecnico->id, $idsDoMes, 'ordem sem técnico não é do técnico');

        // A mesma consulta que a visão do dia do técnico (MeuDia) faz: um único
        // dia, com o mesmo escopo.
        $doDia = $this->actingAs($usuarioDaAna)
            ->getJson('/agenda/dados?inicio='.self::DIA.'&fim='.self::DIA);
        $doDia->assertOk();

        $idsDoDia = array_column($doDia->json('ordens'), 'id');

        $this->assertEqualsCanonicalizing([$daAna->id, $daAnaPelaPivot->id], $idsDoDia);

        // O administrador continua vendo tudo: sem isto, o escopo poderia estar
        // simplesmente quebrado para todo mundo.
        $doAdministrador = $this->actingAs($this->administrador)->getJson($this->urlDeDados());
        $doAdministrador->assertOk();
        $this->assertCount(4, $doAdministrador->json('ordens'));

        // Escrita: o técnico não reagenda nem atribui a ordem de outro.
        $this->actingAs($usuarioDaAna)
            ->putJson("/agenda/{$doBruno->id}/reagendar", [
                'scheduled_date' => '2026-08-21',
                'start_time' => null,
                'end_time' => null,
            ])
            ->assertStatus(403);

        $this->actingAs($usuarioDaAna)
            ->putJson("/agenda/{$doBruno->id}/tecnico", ['technician_id' => $this->ana->id])
            ->assertStatus(403);

        $this->assertSame(
            self::DIA,
            $doBruno->fresh()->scheduled_date->toDateString(),
            'a ordem do outro técnico não pode ter sido reagendada'
        );
        $this->assertSame($this->bruno->id, $doBruno->fresh()->technician_id);

        // Na própria ordem, o técnico com permissão de editar reagenda.
        $this->actingAs($usuarioDaAna)
            ->putJson("/agenda/{$daAna->id}/reagendar", [
                'scheduled_date' => '2026-08-21',
                'start_time' => null,
                'end_time' => null,
            ])
            ->assertOk();

        $this->assertSame('2026-08-21', $daAna->fresh()->scheduled_date->toDateString());
    }

    // -----------------------------------------------------------------
    // Caso 7: reagendar sem conflito grava a nova data
    // -----------------------------------------------------------------

    public function test_reagendar_sem_conflito_grava_a_nova_data(): void
    {
        $ordem = $this->criarOrdem([
            'scheduled_date' => self::DIA,
            'technician_id' => $this->ana->id,
            'service_id' => $this->desinsetizacao->id,
            'start_time' => self::DIA.' 13:00:00',
            'end_time' => self::DIA.' 15:00:00',
            'status' => 'scheduled',
        ]);

        $resposta = $this->putJson("/agenda/{$ordem->id}/reagendar", [
            'scheduled_date' => '2026-08-24',
            'start_time' => '2026-08-24T17:00',
            'end_time' => '2026-08-24T19:00',
        ]);

        $resposta->assertOk();

        $this->assertTrue($resposta->json('success'));
        $this->assertSame('Ordem de serviço reagendada com sucesso.', $resposta->json('message'));
        $this->assertFalse($resposta->json('tem_aviso'));
        $this->assertSame([], $resposta->json('avisos'));

        $this->assertSame('2026-08-24', $resposta->json('ordem.data'));
        $this->assertSame('14:00', $resposta->json('ordem.hora_inicio'), '17:00 UTC são 14:00 em Brasília');
        $this->assertSame('16:00', $resposta->json('ordem.hora_fim'));
        $this->assertSame($ordem->id, $resposta->json('ordem.id'));

        $gravada = $ordem->fresh();

        $this->assertSame('2026-08-24', $gravada->scheduled_date->toDateString());
        $this->assertSame('2026-08-24 17:00:00', $gravada->start_time->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-24 19:00:00', $gravada->end_time->format('Y-m-d H:i:s'));

        // Reagendar limpando o horário continua valendo: é o caso de quem move
        // a visita de dia sem saber ainda a hora.
        $this->putJson("/agenda/{$ordem->id}/reagendar", [
            'scheduled_date' => '2026-08-26',
            'start_time' => null,
            'end_time' => null,
        ])->assertOk();

        $semHorario = $ordem->fresh();

        $this->assertSame('2026-08-26', $semHorario->scheduled_date->toDateString());
        $this->assertNull($semHorario->start_time);
        $this->assertNull($semHorario->end_time);
    }

    // -----------------------------------------------------------------
    // Caso 8: reagendar com conflito devolve 422 nomeando a ordem conflitante
    // -----------------------------------------------------------------

    /**
     * Entregável do plano. A resposta precisa dizer com o que a visita colidiu:
     * "horário indisponível" sem nomear a ordem obriga o usuário a caçar o
     * motivo na mão.
     *
     * `conflito: true` é o que separa esta 422 da 422 de validação, e a
     * asserção é feita sobre ele, não só sobre o código de status.
     */
    public function test_reagendar_com_conflito_devolve_422_nomeando_a_ordem_conflitante(): void
    {
        $ocupando = $this->criarOrdem([
            'scheduled_date' => self::DIA,
            'technician_id' => $this->ana->id,
            'start_time' => self::DIA.' 13:00:00',
            'end_time' => self::DIA.' 15:00:00',
            'status' => 'scheduled',
        ]);

        $aMover = $this->criarOrdem([
            'scheduled_date' => '2026-08-21',
            'technician_id' => $this->ana->id,
            'start_time' => '2026-08-21 13:00:00',
            'end_time' => '2026-08-21 15:00:00',
            'status' => 'scheduled',
        ]);

        $resposta = $this->putJson("/agenda/{$aMover->id}/reagendar", [
            'scheduled_date' => self::DIA,
            'start_time' => self::DIA.'T14:00',
            'end_time' => self::DIA.'T16:00',
        ]);

        $resposta->assertStatus(422);

        $this->assertTrue($resposta->json('conflito'), 'a 422 de conflito precisa se identificar como conflito');
        $this->assertNull($resposta->json('errors'), 'conflito não é erro de validação de campo');

        $mensagem = $resposta->json('message');

        $this->assertStringContainsString($ocupando->order_number, $mensagem, 'a mensagem precisa nomear a ordem conflitante');
        $this->assertStringContainsString('Padaria Central', $mensagem);
        $this->assertStringContainsString('das 10:00 às 12:00', $mensagem);

        $conflitos = $resposta->json('conflitos');

        $this->assertCount(1, $conflitos);
        $this->assertSame($ocupando->id, $conflitos[0]['id']);
        $this->assertSame('sobreposicao', $conflitos[0]['motivo']);
        $this->assertTrue($conflitos[0]['bloqueia']);

        $intacta = $aMover->fresh();

        $this->assertSame('2026-08-21', $intacta->scheduled_date->toDateString(), 'o reagendamento em conflito não pode ter sido gravado');
        $this->assertSame('2026-08-21 13:00:00', $intacta->start_time->format('Y-m-d H:i:s'));

        // O conflito também pega o técnico atribuído só pela pivot, que é como
        // o formulário de ordem de serviço grava a equipe.
        $daEquipe = $this->criarOrdem([
            'scheduled_date' => '2026-08-22',
            'start_time' => '2026-08-22 13:00:00',
            'end_time' => '2026-08-22 15:00:00',
            'status' => 'scheduled',
        ]);
        $daEquipe->technicians()->attach($this->ana->id);

        $this->putJson("/agenda/{$daEquipe->id}/reagendar", [
            'scheduled_date' => self::DIA,
            'start_time' => self::DIA.'T14:00',
            'end_time' => self::DIA.'T16:00',
        ])->assertStatus(422)->assertJsonPath('conflito', true);

        $this->assertSame('2026-08-22', $daEquipe->fresh()->scheduled_date->toDateString());

        // Horário encostado passa: a regra bloqueia cruzamento, não vizinhança.
        $this->putJson("/agenda/{$aMover->id}/reagendar", [
            'scheduled_date' => self::DIA,
            'start_time' => self::DIA.'T15:00',
            'end_time' => self::DIA.'T17:00',
        ])->assertOk();

        $this->assertSame(self::DIA, $aMover->fresh()->scheduled_date->toDateString());
    }

    // -----------------------------------------------------------------
    // Caso 9: reagendar ordem concluída é recusado
    // -----------------------------------------------------------------

    public function test_reagendar_ordem_concluida_e_recusado(): void
    {
        $concluida = $this->criarOrdem(['scheduled_date' => self::DIA, 'status' => 'completed']);

        $resposta = $this->putJson("/agenda/{$concluida->id}/reagendar", [
            'scheduled_date' => '2026-08-25',
            'start_time' => null,
            'end_time' => null,
        ]);

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('status');

        $this->assertNull($resposta->json('conflito'), 'recusa por situação não é conflito de horário');
        $this->assertStringContainsString('concluída', $resposta->json('errors.status.0'));

        $this->assertSame(self::DIA, $concluida->fresh()->scheduled_date->toDateString());

        $cancelada = $this->criarOrdem(['scheduled_date' => self::DIA, 'status' => 'cancelled']);

        $this->putJson("/agenda/{$cancelada->id}/reagendar", [
            'scheduled_date' => '2026-08-25',
            'start_time' => null,
            'end_time' => null,
        ])->assertStatus(422)->assertJsonValidationErrors('status');

        $this->assertSame(self::DIA, $cancelada->fresh()->scheduled_date->toDateString());

        // Atribuir técnico pelo calendário segue a mesma regra.
        $this->putJson("/agenda/{$concluida->id}/tecnico", ['technician_id' => $this->ana->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        // Contraste: a 422 de validação de campo também traz `errors`, e nunca
        // `conflito`. Omitir start_time é recusado de propósito, para o dia e o
        // horário nunca saírem apontando para datas diferentes.
        $emAberto = $this->criarOrdem(['scheduled_date' => self::DIA, 'status' => 'scheduled']);

        $this->putJson("/agenda/{$emAberto->id}/reagendar", ['scheduled_date' => '2026-08-25'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['start_time', 'end_time']);

        $this->assertSame(self::DIA, $emAberto->fresh()->scheduled_date->toDateString());
    }

    // -----------------------------------------------------------------
    // Caso 10: o reagendamento aparece em audit_logs
    // -----------------------------------------------------------------

    /**
     * Entregável do plano. Nenhum campo novo foi criado para "quem reagendou":
     * a trait `Auditavel` do Plano 3 grava autor, instante e valores antes e
     * depois a cada `update()`. Este caso lê o registro gravado, não a resposta
     * HTTP, então ele falha se a trait sair do model ou se o reagendamento
     * passar a alterar a ordem por fora do Eloquent.
     */
    public function test_reagendamento_aparece_em_audit_logs_com_autor_instante_e_valores(): void
    {
        $ordem = $this->criarOrdem([
            'scheduled_date' => self::DIA,
            'technician_id' => $this->ana->id,
            'start_time' => self::DIA.' 13:00:00',
            'end_time' => self::DIA.' 15:00:00',
            'status' => 'scheduled',
        ]);

        $registrosAntes = AuditLog::query()
            ->where('auditable_type', WorkOrder::class)
            ->where('auditable_id', $ordem->id)
            ->where('acao', 'alterado')
            ->count();

        $this->putJson("/agenda/{$ordem->id}/reagendar", [
            'scheduled_date' => '2026-08-27',
            'start_time' => '2026-08-27T17:30',
            'end_time' => '2026-08-27T19:30',
        ])->assertOk();

        $registros = AuditLog::query()
            ->where('auditable_type', WorkOrder::class)
            ->where('auditable_id', $ordem->id)
            ->where('acao', 'alterado')
            ->orderByDesc('id')
            ->get();

        $this->assertCount(
            $registrosAntes + 1,
            $registros,
            'o reagendamento precisa gerar exatamente um registro de alteração em audit_logs'
        );

        $registro = $registros->first();

        $this->assertSame($this->administrador->id, $registro->user_id, 'o autor do reagendamento precisa ficar registrado');
        $this->assertSame('Administradora Marta', $registro->autor_nome);
        $this->assertNotNull($registro->created_at, 'o instante do reagendamento precisa ficar registrado');
        $this->assertTrue(
            $registro->created_at->diffInMinutes(now()) < 5,
            'o instante gravado deveria ser o do reagendamento'
        );

        $antes = $registro->valores_antes;
        $depois = $registro->valores_depois;

        $this->assertArrayHasKey('scheduled_date', $antes);
        $this->assertArrayHasKey('start_time', $antes);
        $this->assertArrayHasKey('end_time', $antes);

        $this->assertStringContainsString(self::DIA, $antes['scheduled_date']);
        $this->assertStringContainsString('2026-08-27', $depois['scheduled_date']);
        $this->assertStringContainsString('13:00', $antes['start_time']);
        $this->assertStringContainsString('17:30', $depois['start_time']);
        $this->assertStringContainsString('15:00', $antes['end_time']);
        $this->assertStringContainsString('19:30', $depois['end_time']);

        $this->assertSame(
            $this->empresaUm->id,
            (int) $registro->company_id,
            'o registro de auditoria pertence à empresa de quem alterou'
        );

        // Reagendamento recusado por conflito não gera registro: nada foi
        // alterado, e histórico com alteração que não aconteceu é pior que
        // histórico curto.
        $ocupando = $this->criarOrdem([
            'scheduled_date' => '2026-08-28',
            'technician_id' => $this->ana->id,
            'start_time' => '2026-08-28 13:00:00',
            'end_time' => '2026-08-28 15:00:00',
            'status' => 'scheduled',
        ]);

        $this->assertNotNull($ocupando->id);

        $this->putJson("/agenda/{$ordem->id}/reagendar", [
            'scheduled_date' => '2026-08-28',
            'start_time' => '2026-08-28T14:00',
            'end_time' => '2026-08-28T16:00',
        ])->assertStatus(422)->assertJsonPath('conflito', true);

        $this->assertCount(
            $registrosAntes + 1,
            AuditLog::query()
                ->where('auditable_type', WorkOrder::class)
                ->where('auditable_id', $ordem->id)
                ->where('acao', 'alterado')
                ->get(),
            'reagendamento bloqueado por conflito não pode virar registro de alteração'
        );
    }

    // -----------------------------------------------------------------
    // Caso 11: usuário sem ordem-servico-editar recebe 403 ao reagendar
    // -----------------------------------------------------------------

    public function test_usuario_sem_permissao_de_editar_recebe_403_ao_reagendar(): void
    {
        $leitura = $this->criarUsuario('leitura', 'Consultora Rita');

        $ordem = $this->criarOrdem(['scheduled_date' => self::DIA, 'technician_id' => $this->ana->id]);

        // Ler a agenda continua permitido: o 403 abaixo é da permissão de
        // edição, e não de autenticação.
        $this->actingAs($leitura)->getJson($this->urlDeDados())->assertOk();

        $this->actingAs($leitura)
            ->putJson("/agenda/{$ordem->id}/reagendar", [
                'scheduled_date' => '2026-08-25',
                'start_time' => null,
                'end_time' => null,
            ])
            ->assertStatus(403);

        $this->actingAs($leitura)
            ->putJson("/agenda/{$ordem->id}/tecnico", ['technician_id' => $this->bruno->id])
            ->assertStatus(403);

        $this->actingAs($leitura)
            ->getJson('/agenda/tecnicos-disponiveis?data='.self::DIA)
            ->assertStatus(403);

        $intacta = $ordem->fresh();

        $this->assertSame(self::DIA, $intacta->scheduled_date->toDateString());
        $this->assertSame($this->ana->id, $intacta->technician_id);
    }

    // -----------------------------------------------------------------
    // Caso 12: um mês de agenda não gera consulta por linha
    // -----------------------------------------------------------------

    /**
     * A agenda carrega cliente, endereço, técnico e serviço de cada visita.
     * Sem o `with()` do `AgendaService`, cada linha vira quatro consultas, e o
     * volume que o Plano 9 traz (geração automática de visitas de contrato)
     * transforma um mês cheio em centenas de consultas por abertura de tela.
     *
     * A asserção é comparativa, e não um número mágico: o mesmo endpoint, no
     * mesmo formato, com 3 e com 45 visitas, precisa executar a mesma
     * quantidade de consultas. Tirar o `with()` faz o mês cheio disparar dezenas
     * de consultas a mais e derruba este caso (conferido durante a escrita do
     * teste).
     */
    public function test_um_mes_de_agenda_nao_gera_consulta_por_linha(): void
    {
        $mesVazio = ['inicio' => '2026-09-01', 'fim' => '2026-09-30'];
        $mesCheio = ['inicio' => '2026-10-01', 'fim' => '2026-10-31'];

        foreach (range(1, 3) as $indice) {
            $this->criarOrdemDoVolume('2026-09-'.str_pad((string) $indice, 2, '0', STR_PAD_LEFT), $indice);
        }

        foreach (range(1, 45) as $indice) {
            $dia = '2026-10-'.str_pad((string) (($indice % 28) + 1), 2, '0', STR_PAD_LEFT);
            $this->criarOrdemDoVolume($dia, $indice);
        }

        // Aquecimento: a primeira requisição resolve papéis e permissões, que
        // ficam em cache no processo. Sem isto a comparação mediria o cache.
        $this->getJson($this->urlDeDados($mesVazio))->assertOk();

        [$consultasDoMesVazio, $ordensDoMesVazio] = $this->medirConsultas($mesVazio);
        [$consultasDoMesCheio, $ordensDoMesCheio] = $this->medirConsultas($mesCheio);

        $this->assertCount(3, $ordensDoMesVazio, 'o cenário exige três visitas no mês vazio');
        $this->assertCount(45, $ordensDoMesCheio, 'o cenário exige quarenta e cinco visitas no mês cheio');

        $this->assertSame(
            $consultasDoMesVazio,
            $consultasDoMesCheio,
            "a agenda passou de {$consultasDoMesVazio} para {$consultasDoMesCheio} consultas ao sair de 3 para 45 visitas: "
            .'o número de consultas está crescendo por linha, provavelmente porque o with() das relações caiu do AgendaService'
        );

        $this->assertGreaterThan(
            0,
            $consultasDoMesCheio,
            'nenhuma consulta foi contada: o DB::listen não capturou nada e a comparação acima não provaria nada'
        );

        $this->assertLessThanOrEqual(
            20,
            $consultasDoMesCheio,
            'um mês de agenda deveria caber em poucas consultas: '.$consultasDoMesCheio.' é sinal de consulta por linha'
        );

        // O eager load precisa ter trazido as relações de fato: agenda rápida
        // com cartão sem cliente e sem técnico não serve.
        $primeira = $ordensDoMesCheio[0];

        $this->assertNotNull($primeira['cliente']);
        $this->assertNotNull($primeira['tecnico']);
        $this->assertNotNull($primeira['servico']);
        $this->assertNotNull($primeira['endereco']);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function criarUsuario(string $papel, string $nome): User
    {
        $usuario = TenantAtual::comTenant(
            $this->empresaUm->id,
            fn () => User::factory()->create(['name' => $nome])
        );

        $usuario->assignRole($papel);

        return $usuario->fresh();
    }

    private function criarTecnico(string $nome): Technician
    {
        return Technician::create([
            'name' => $nome,
            'email' => 'tecnico-'.uniqid().'@exemplo.test',
            'phone' => '11999990000',
            'is_active' => true,
        ]);
    }

    private function criarOrdem(array $atributos = []): WorkOrder
    {
        $this->contadorDeOrdens++;

        return WorkOrder::create(array_merge([
            'order_number' => 'OS-'.str_pad((string) $this->contadorDeOrdens, 4, '0', STR_PAD_LEFT),
            'client_id' => $this->clienteCampinas->id,
            'address_id' => $this->enderecoCampinas->id,
            'scheduled_date' => self::DIA,
            'status' => 'scheduled',
        ], $atributos));
    }

    /**
     * Visita do cenário de volume: todas com cliente, endereço, técnico e
     * serviço preenchidos, para que o eager load tenha o mesmo formato nos dois
     * meses comparados.
     */
    private function criarOrdemDoVolume(string $dia, int $indice): WorkOrder
    {
        return $this->criarOrdem([
            'scheduled_date' => $dia,
            'client_id' => $indice % 2 === 0 ? $this->clienteCampinas->id : $this->clienteSantos->id,
            'address_id' => $indice % 2 === 0 ? $this->enderecoCampinas->id : $this->enderecoSantos->id,
            'technician_id' => $indice % 2 === 0 ? $this->ana->id : $this->bruno->id,
            'service_id' => $indice % 2 === 0 ? $this->desinsetizacao->id : $this->desratizacao->id,
            'start_time' => $dia.' 13:00:00',
            'end_time' => $dia.' 15:00:00',
        ]);
    }

    /**
     * Compromisso avulso do cenário (Task 30.4/30.6): mesmo dia e mesmo
     * cliente/endereço padrão das ordens de serviço (`self::DIA`,
     * `clienteCampinas`/`enderecoCampinas`), salvo o que `$atributos`
     * sobrescrever.
     */
    private function criarCompromisso(array $atributos = []): Compromisso
    {
        return CompromissoFactory::new()->create(array_merge([
            'client_id' => $this->clienteCampinas->id,
            'address_id' => $this->enderecoCampinas->id,
            'data' => self::DIA,
            'situacao' => 'agendado',
        ], $atributos));
    }

    /**
     * Consultas executadas em uma requisição da agenda, com as ordens
     * devolvidas.
     *
     * @return array{0: int, 1: array<int, array<string, mixed>>}
     */
    private function medirConsultas(array $periodo): array
    {
        $total = 0;

        DB::listen(function () use (&$total): void {
            $total++;
        });

        $resposta = $this->getJson($this->urlDeDados($periodo));

        $medido = $total;

        $resposta->assertOk();

        return [$medido, $resposta->json('ordens')];
    }

    private function urlDeDados(array $periodo = []): string
    {
        return '/agenda/dados?'.http_build_query(array_merge([
            'inicio' => self::INICIO,
            'fim' => self::FIM,
        ], $periodo));
    }

    /**
     * Ids das ordens devolvidas por `GET /agenda/dados`, na ordem em que a
     * agenda as entrega.
     *
     * @return array<int, int>
     */
    private function idsDaAgenda(array $filtros = []): array
    {
        $resposta = $this->getJson($this->urlDeDados($filtros));

        $resposta->assertOk();

        return array_column($resposta->json('ordens'), 'id');
    }

    /**
     * Ids dos itens de compromisso avulso (`tipo_item === 'compromisso'`)
     * devolvidos por `GET /agenda/dados`, na ordem em que a agenda os
     * entrega. Ignora os itens de OS da mesma resposta.
     *
     * @return array<int, int>
     */
    private function idsDeCompromissosDaAgenda(?User $usuario = null, array $filtros = []): array
    {
        $resposta = $usuario
            ? $this->actingAs($usuario)->getJson($this->urlDeDados($filtros))
            : $this->getJson($this->urlDeDados($filtros));

        $resposta->assertOk();

        $itens = array_filter(
            $resposta->json('ordens'),
            fn (array $item): bool => $item['tipo_item'] === 'compromisso'
        );

        return array_column($itens, 'id');
    }
}
