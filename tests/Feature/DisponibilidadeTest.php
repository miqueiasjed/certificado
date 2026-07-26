<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Client;
use App\Models\Company;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\DisponibilidadeService;
use App\Support\TenantAtual;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 10.8 do Plano 10: os sete casos do `DisponibilidadeService` (Task 10.2).
 *
 * O que este arquivo prende é a regra de bloqueio do plano: conflito existe
 * quando as duas ordens de serviço têm horário e os intervalos se cruzam, e
 * qualquer outra coincidência de dia é aviso, nunca bloqueio. Afrouxar a
 * distinção entre conflito e aviso é o que faria a agenda travar o uso normal
 * de quem não preenche horário, ou pior, deixar passar dois atendimentos no
 * mesmo intervalo.
 *
 * Fuso: `start_time` e `end_time` são instantes gravados em UTC, e os horários
 * de parede passados ao serviço (`HH:MM`) são lidos em Brasília. Por isso todo
 * cenário usa horário comercial em UTC (13:00 a 22:00), que cai no mesmo dia em
 * Brasília (10:00 a 19:00) e não cruza a fronteira do dia.
 */
class DisponibilidadeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Dia único dos casos de conflito. Data fixa: nenhum caso aqui depende de
     * "hoje", e relógio livre em teste de agenda é fonte de falha intermitente.
     */
    private const DIA = '2026-08-10';

    private Company $empresaUm;

    private Company $empresaDois;

    private User $administrador;

    private DisponibilidadeService $servico;

    private Client $cliente;

    private Address $endereco;

    private Technician $tecnico;

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

        $this->administrador = TenantAtual::comTenant(
            $this->empresaUm->id,
            fn () => User::factory()->create(['name' => 'Administradora da Empresa 1'])
        );
        $this->administrador->assignRole('administrador');

        // Autenticar resolve o tenant pelo usuário, exatamente como em uma
        // requisição real: sem isso o escopo global não filtra nada e o caso 6
        // passaria à toa.
        $this->actingAs($this->administrador);

        $this->servico = app(DisponibilidadeService::class);

        $this->cliente = ClientFactory::new()->create(['name' => 'Padaria Central']);
        $this->endereco = AddressFactory::new()->create(['client_id' => $this->cliente->id]);
        $this->tecnico = $this->criarTecnico('Ana Ferreira');
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Caso 1: intervalos sobrepostos do mesmo técnico são conflito
    // -----------------------------------------------------------------

    /**
     * A ordem existente ocupa das 10:00 às 12:00 de Brasília. Pedir das 11:00
     * às 12:30 no mesmo dia cruza o intervalo e precisa bloquear, com a ordem
     * conflitante nomeada.
     *
     * A segunda metade do caso cobre a atribuição pela pivot
     * `work_order_technicians`: o formulário de ordem de serviço grava a equipe
     * por lá, então checar só `work_orders.technician_id` deixaria passar o
     * conflito da maior parte das ordens reais.
     */
    public function test_intervalos_sobrepostos_do_mesmo_tecnico_sao_conflito(): void
    {
        $ocupada = $this->criarOrdem([
            'technician_id' => $this->tecnico->id,
            'start_time' => self::DIA.' 13:00:00',
            'end_time' => self::DIA.' 15:00:00',
        ]);

        $resultado = $this->servico->conflitos($this->tecnico->id, self::DIA, '11:00', '12:30');

        $this->assertTrue($resultado['tem_conflito'], 'intervalo sobreposto do mesmo técnico deveria ser conflito');
        $this->assertFalse($resultado['tem_aviso'], 'com os dois lados tendo horário, a resposta é conflito, e não aviso');
        $this->assertCount(1, $resultado['conflitos']);

        $item = $resultado['conflitos'][0];

        $this->assertSame($ocupada->id, $item['id']);
        $this->assertSame($ocupada->order_number, $item['order_number']);
        $this->assertSame('Padaria Central', $item['cliente']);
        $this->assertSame(self::DIA, $item['data']);
        $this->assertSame('10:00', $item['inicio'], 'o horário volta ao usuário no fuso de Brasília');
        $this->assertSame('12:00', $item['fim']);
        $this->assertSame(DisponibilidadeService::MOTIVO_SOBREPOSICAO, $item['motivo']);
        $this->assertTrue($item['bloqueia']);

        $this->assertStringContainsString($ocupada->order_number, $resultado['mensagem_conflito']);
        $this->assertStringContainsString('Padaria Central', $resultado['mensagem_conflito']);
        $this->assertStringContainsString('das 10:00 às 12:00', $resultado['mensagem_conflito']);

        // Mesma sobreposição, com o técnico atribuído só pela pivot.
        $tecnicoDaEquipe = $this->criarTecnico('Bruno Lima');
        $daEquipe = $this->criarOrdem([
            'start_time' => self::DIA.' 13:00:00',
            'end_time' => self::DIA.' 15:00:00',
        ]);
        $daEquipe->technicians()->attach($tecnicoDaEquipe->id);

        $this->assertNull($daEquipe->fresh()->technician_id, 'o cenário exige a ordem sem technician_id direto');

        $pelaPivot = $this->servico->conflitos($tecnicoDaEquipe->id, self::DIA, '11:00', '12:30');

        $this->assertTrue(
            $pelaPivot['tem_conflito'],
            'ordem atribuída só pela pivot work_order_technicians também ocupa o técnico'
        );
        $this->assertSame($daEquipe->id, $pelaPivot['conflitos'][0]['id']);
    }

    // -----------------------------------------------------------------
    // Caso 2: intervalos encostados não são conflito
    // -----------------------------------------------------------------

    /**
     * Uma visita termina 12:00 e a seguinte começa 12:00. É o encaixe normal de
     * dois atendimentos seguidos, e bloquear isso inviabilizaria o dia do
     * técnico. Nem conflito, nem aviso: o técnico está livre no intervalo
     * pedido.
     */
    public function test_intervalos_encostados_nao_sao_conflito(): void
    {
        $this->criarOrdem([
            'technician_id' => $this->tecnico->id,
            'start_time' => self::DIA.' 13:00:00',
            'end_time' => self::DIA.' 15:00:00',
        ]);

        $depois = $this->servico->conflitos($this->tecnico->id, self::DIA, '12:00', '13:00');

        $this->assertFalse($depois['tem_conflito'], 'começar no minuto em que a outra termina não é conflito');
        $this->assertFalse($depois['tem_aviso']);
        $this->assertSame([], $depois['conflitos']);
        $this->assertNull($depois['mensagem_conflito']);

        $antes = $this->servico->conflitos($this->tecnico->id, self::DIA, '09:00', '10:00');

        $this->assertFalse($antes['tem_conflito'], 'terminar no minuto em que a outra começa também não é conflito');

        // Um minuto dentro já é conflito: prova que a borda é o único caso
        // liberado, e não que a checagem parou de funcionar.
        $porUmMinuto = $this->servico->conflitos($this->tecnico->id, self::DIA, '11:59', '13:00');

        $this->assertTrue($porUmMinuto['tem_conflito'], 'um minuto de cruzamento continua sendo conflito');
    }

    // -----------------------------------------------------------------
    // Caso 3: ordem sem horário na mesma data gera aviso, não conflito
    // -----------------------------------------------------------------

    /**
     * A maior parte das ordens de serviço de hoje não tem horário preenchido.
     * Elas precisam aparecer como aviso, para o usuário decidir, e nunca
     * bloquear o agendamento.
     */
    public function test_ordem_sem_horario_no_mesmo_dia_gera_aviso_e_nao_conflito(): void
    {
        $semHorario = $this->criarOrdem([
            'technician_id' => $this->tecnico->id,
            'start_time' => null,
            'end_time' => null,
        ]);

        $resultado = $this->servico->conflitos($this->tecnico->id, self::DIA, '10:00', '11:00');

        $this->assertFalse($resultado['tem_conflito'], 'ordem sem horário não pode bloquear o agendamento');
        $this->assertTrue($resultado['tem_aviso']);
        $this->assertSame([], $resultado['conflitos']);
        $this->assertCount(1, $resultado['avisos']);

        $item = $resultado['avisos'][0];

        $this->assertSame($semHorario->id, $item['id']);
        $this->assertSame(DisponibilidadeService::MOTIVO_SEM_HORARIO, $item['motivo']);
        $this->assertFalse($item['bloqueia']);
        $this->assertSame('sem horário definido', $item['horario']);

        $this->assertNull($resultado['mensagem_conflito']);
        $this->assertStringContainsString($semHorario->order_number, $resultado['mensagem_aviso']);

        // O outro lado da regra: quem pede sem horário também recebe aviso, e
        // não bloqueio, mesmo com a ordem existente tendo horário completo.
        $this->criarOrdem([
            'technician_id' => $this->tecnico->id,
            'start_time' => self::DIA.' 13:00:00',
            'end_time' => self::DIA.' 15:00:00',
        ]);

        $semPedirHorario = $this->servico->conflitos($this->tecnico->id, self::DIA, null, null);

        $this->assertFalse($semPedirHorario['tem_conflito'], 'sem intervalo pedido não há sobreposição a provar');
        $this->assertTrue($semPedirHorario['tem_aviso']);
        $this->assertCount(2, $semPedirHorario['avisos']);
    }

    // -----------------------------------------------------------------
    // Caso 4: $ignorarOsId impede a ordem de conflitar consigo mesma
    // -----------------------------------------------------------------

    /**
     * Sem isto, mover uma visita dez minutos para a frente seria recusado por
     * conflito com ela própria, e o reagendamento ficaria impossível.
     */
    public function test_ignorar_os_id_impede_a_ordem_de_conflitar_consigo_mesma(): void
    {
        $ordem = $this->criarOrdem([
            'technician_id' => $this->tecnico->id,
            'start_time' => self::DIA.' 13:00:00',
            'end_time' => self::DIA.' 15:00:00',
        ]);

        $semIgnorar = $this->servico->conflitos($this->tecnico->id, self::DIA, '10:30', '11:30');

        $this->assertTrue($semIgnorar['tem_conflito'], 'o cenário exige que o intervalo pedido cruze a própria ordem');
        $this->assertSame($ordem->id, $semIgnorar['conflitos'][0]['id']);

        $ignorando = $this->servico->conflitos($this->tecnico->id, self::DIA, '10:30', '11:30', $ordem->id);

        $this->assertFalse($ignorando['tem_conflito'], 'a ordem ignorada não pode conflitar consigo mesma');
        $this->assertSame([], $ignorando['conflitos']);
        $this->assertSame([], $ignorando['avisos']);

        // Ignorar uma ordem não pode esconder o conflito com outra.
        $outra = $this->criarOrdem([
            'technician_id' => $this->tecnico->id,
            'start_time' => self::DIA.' 14:00:00',
            'end_time' => self::DIA.' 16:00:00',
        ]);

        $comOutra = $this->servico->conflitos($this->tecnico->id, self::DIA, '10:30', '11:30', $ordem->id);

        $this->assertTrue($comOutra['tem_conflito']);
        $this->assertSame($outra->id, $comOutra['conflitos'][0]['id']);
    }

    // -----------------------------------------------------------------
    // Caso 5: ordem cancelada não gera conflito
    // -----------------------------------------------------------------

    /**
     * Visita cancelada não ocupa o técnico. Ela não pode nem bloquear nem
     * avisar: o horário está livre de verdade.
     */
    public function test_ordem_cancelada_nao_gera_conflito(): void
    {
        $cancelada = $this->criarOrdem([
            'technician_id' => $this->tecnico->id,
            'status' => 'cancelled',
            'start_time' => self::DIA.' 13:00:00',
            'end_time' => self::DIA.' 15:00:00',
        ]);

        $resultado = $this->servico->conflitos($this->tecnico->id, self::DIA, '10:30', '11:30');

        $this->assertFalse($resultado['tem_conflito'], 'ordem cancelada não ocupa o horário do técnico');
        $this->assertFalse($resultado['tem_aviso']);
        $this->assertSame([], $resultado['conflitos']);
        $this->assertSame([], $resultado['avisos']);

        // A mesma ordem, fora do cancelamento, volta a conflitar: prova que o
        // que a excluiu foi a situação, e não o cenário mal montado.
        $cancelada->update(['status' => 'scheduled']);

        $depoisDeReabrir = $this->servico->conflitos($this->tecnico->id, self::DIA, '10:30', '11:30');

        $this->assertTrue($depoisDeReabrir['tem_conflito']);
        $this->assertSame($cancelada->id, $depoisDeReabrir['conflitos'][0]['id']);
    }

    // -----------------------------------------------------------------
    // Caso 6: técnico de outra empresa nunca aparece em tecnicosDisponiveis()
    // -----------------------------------------------------------------

    /**
     * O isolamento vem do escopo global de `Technician`, e não de filtro
     * manual. Este caso confere os dois lados: dentro da empresa 1 o técnico da
     * empresa 2 não existe, e dentro da empresa 2 acontece o contrário.
     *
     * O técnico da outra empresa é criado dentro de `TenantAtual::comTenant()`
     * de propósito: `Technician::company_id` não está em `$fillable`, então
     * passar a coluna no `create()` não teria efeito e ele nasceria na empresa
     * 1, esvaziando o teste.
     */
    public function test_tecnico_de_outra_empresa_nunca_aparece_em_tecnicos_disponiveis(): void
    {
        $daOutraEmpresa = TenantAtual::comTenant($this->empresaDois->id, fn () => Technician::create([
            'name' => 'Zelia da Concorrente',
            'email' => 'zelia@concorrente.test',
            'phone' => '11988887777',
            'is_active' => true,
        ]));

        $this->assertSame(
            $this->empresaDois->id,
            (int) $daOutraEmpresa->company_id,
            'o cenário exige o técnico gravado na empresa 2'
        );

        $disponiveis = $this->servico->tecnicosDisponiveis(self::DIA, '10:00', '11:00');
        $ids = array_column($disponiveis, 'id');

        $this->assertContains($this->tecnico->id, $ids, 'o técnico da própria empresa deveria estar disponível');
        $this->assertNotContains(
            $daOutraEmpresa->id,
            $ids,
            'técnico de outra empresa apareceu na lista de disponíveis: o escopo global de Technician caiu'
        );

        $nomes = array_column($disponiveis, 'nome');
        $this->assertNotContains('Zelia da Concorrente', $nomes);

        // Dentro da empresa 2 a lista se inverte, o que prova que o filtro é o
        // escopo por empresa e não um acaso do cenário.
        $naOutraEmpresa = TenantAtual::comTenant(
            $this->empresaDois->id,
            fn () => $this->servico->tecnicosDisponiveis(self::DIA, '10:00', '11:00')
        );
        $idsDaOutra = array_column($naOutraEmpresa, 'id');

        $this->assertContains($daOutraEmpresa->id, $idsDaOutra);
        $this->assertNotContains($this->tecnico->id, $idsDaOutra);

        // Técnico ocupado sai da lista; técnico com aviso continua nela.
        $ocupado = $this->criarTecnico('Carlos Ocupado');
        $this->criarOrdem([
            'technician_id' => $ocupado->id,
            'start_time' => self::DIA.' 13:00:00',
            'end_time' => self::DIA.' 15:00:00',
        ]);

        $comAviso = $this->criarTecnico('Diana Sem Horario');
        $this->criarOrdem([
            'technician_id' => $comAviso->id,
            'start_time' => null,
            'end_time' => null,
        ]);

        $depois = $this->servico->tecnicosDisponiveis(self::DIA, '10:30', '11:30');
        $idsDepois = array_column($depois, 'id');

        $this->assertNotContains($ocupado->id, $idsDepois, 'técnico com conflito bloqueante não pode ser oferecido');
        $this->assertContains($comAviso->id, $idsDepois, 'aviso não exclui técnico da lista');

        $linhaComAviso = collect($depois)->firstWhere('id', $comAviso->id);
        $this->assertTrue($linhaComAviso['tem_aviso']);
        $this->assertSame(1, $linhaComAviso['os_no_dia']);
    }

    // -----------------------------------------------------------------
    // Caso 7: cargaPorTecnico() soma OS e horas e inclui a linha "sem técnico"
    // -----------------------------------------------------------------

    /**
     * Cenário de três dias, montado para separar as somas:
     *
     * - Ana: duas ordens no dia 1 (uma de 2h e uma sem horário) e uma de 3h no
     *   dia 2, esta última compartilhada com Bruno pela pivot.
     * - Bruno: a compartilhada de 3h e uma de 1h30 no dia 3.
     * - Sem técnico: uma de 1h no dia 2.
     * - Elias, inativo, com uma ordem de 1h no dia 3: aparece, porque tem
     *   trabalho agendado.
     * - Fatima, inativa e sem ordem: não aparece.
     * - Uma ordem cancelada de 7h no dia 1, que não pode entrar em lugar
     *   nenhum.
     *
     * A ordem compartilhada conta para os dois técnicos, e por isso a soma das
     * linhas é maior que o total, que é apurado sobre ordens distintas.
     */
    public function test_carga_por_tecnico_soma_os_e_horas_e_inclui_a_linha_sem_tecnico(): void
    {
        $dia1 = '2026-08-10';
        $dia2 = '2026-08-11';
        $dia3 = '2026-08-12';

        $ana = $this->tecnico;
        $bruno = $this->criarTecnico('Bruno Souza');
        $elias = $this->criarTecnico('Elias Inativo', false);
        $this->criarTecnico('Fatima Inativa', false);

        $this->criarOrdem([
            'scheduled_date' => $dia1,
            'technician_id' => $ana->id,
            'start_time' => $dia1.' 13:00:00',
            'end_time' => $dia1.' 15:00:00',
        ]);

        $this->criarOrdem([
            'scheduled_date' => $dia1,
            'technician_id' => $ana->id,
            'start_time' => null,
            'end_time' => null,
        ]);

        $compartilhada = $this->criarOrdem([
            'scheduled_date' => $dia2,
            'technician_id' => $ana->id,
            'start_time' => $dia2.' 13:00:00',
            'end_time' => $dia2.' 16:00:00',
        ]);
        $compartilhada->technicians()->attach($bruno->id);

        $this->criarOrdem([
            'scheduled_date' => $dia3,
            'technician_id' => $bruno->id,
            'start_time' => $dia3.' 13:00:00',
            'end_time' => $dia3.' 14:30:00',
        ]);

        $this->criarOrdem([
            'scheduled_date' => $dia2,
            'start_time' => $dia2.' 13:00:00',
            'end_time' => $dia2.' 14:00:00',
        ]);

        $this->criarOrdem([
            'scheduled_date' => $dia3,
            'technician_id' => $elias->id,
            'start_time' => $dia3.' 13:00:00',
            'end_time' => $dia3.' 14:00:00',
        ]);

        $this->criarOrdem([
            'scheduled_date' => $dia1,
            'technician_id' => $ana->id,
            'status' => 'cancelled',
            'start_time' => $dia1.' 13:00:00',
            'end_time' => $dia1.' 20:00:00',
        ]);

        $carga = $this->servico->cargaPorTecnico($dia1, $dia3);

        $this->assertSame(['inicio' => $dia1, 'fim' => $dia3, 'dias' => 3], $carga['periodo']);

        $this->assertSame(
            ['total_os' => 6, 'total_horas' => 8.5, 'os_sem_horario' => 1],
            $carga['totais'],
            'os totais são apurados sobre ordens distintas e não podem incluir a cancelada'
        );

        $linhas = collect($carga['tecnicos']);

        $linhaDaAna = $linhas->firstWhere('technician_id', $ana->id);
        $this->assertSame(3, $linhaDaAna['total_os']);
        $this->assertSame(5.0, $linhaDaAna['total_horas'], 'Ana tem 2h no dia 1 e 3h no dia 2');
        $this->assertSame(1, $linhaDaAna['os_sem_horario']);
        $this->assertTrue($linhaDaAna['ativo']);
        $this->assertFalse($linhaDaAna['sem_tecnico']);
        $this->assertSame(2, $linhaDaAna['dias_com_os']);
        $this->assertSame(2, $linhaDaAna['pico_no_dia']);
        $this->assertSame(1.0, $linhaDaAna['media_os_por_dia']);
        $this->assertSame(['total_os' => 2, 'horas' => 2.0], $linhaDaAna['por_dia'][$dia1]);
        $this->assertSame(['total_os' => 1, 'horas' => 3.0], $linhaDaAna['por_dia'][$dia2]);
        $this->assertSame(['total_os' => 0, 'horas' => 0.0], $linhaDaAna['por_dia'][$dia3], 'o dia zerado vem preenchido');

        $linhaDoBruno = $linhas->firstWhere('technician_id', $bruno->id);
        $this->assertSame(2, $linhaDoBruno['total_os'], 'a ordem compartilhada conta para os dois técnicos');
        $this->assertSame(4.5, $linhaDoBruno['total_horas']);
        $this->assertSame(0, $linhaDoBruno['os_sem_horario']);

        $linhaDoElias = $linhas->firstWhere('technician_id', $elias->id);
        $this->assertNotNull($linhaDoElias, 'técnico inativo com trabalho agendado precisa aparecer na carga');
        $this->assertFalse($linhaDoElias['ativo']);
        $this->assertSame(1, $linhaDoElias['total_os']);

        $this->assertNull(
            $linhas->firstWhere('nome', 'Fatima Inativa'),
            'técnico inativo e sem trabalho no período não deveria aparecer'
        );

        $ultima = $linhas->last();
        $this->assertTrue($ultima['sem_tecnico'], 'a linha "sem técnico" fecha a lista');
        $this->assertNull($ultima['technician_id']);
        $this->assertSame('Sem técnico', $ultima['nome']);
        $this->assertSame(1, $ultima['total_os']);
        $this->assertSame(1.0, $ultima['total_horas']);
        $this->assertSame(['total_os' => 1, 'horas' => 1.0], $ultima['por_dia'][$dia2]);

        // A soma das linhas passa do total porque a ordem compartilhada conta
        // duas vezes. É o comportamento documentado, e não um erro de conta.
        $this->assertSame(7, $linhas->sum('total_os'));
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

    private function criarOrdem(array $atributos = []): WorkOrder
    {
        $this->contadorDeOrdens++;

        return WorkOrder::create(array_merge([
            'order_number' => 'OS-'.str_pad((string) $this->contadorDeOrdens, 4, '0', STR_PAD_LEFT),
            'client_id' => $this->cliente->id,
            'address_id' => $this->endereco->id,
            'scheduled_date' => self::DIA,
            'status' => 'scheduled',
        ], $atributos));
    }
}
