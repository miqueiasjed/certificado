<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyAvailabilitySetting;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AvailabilityService;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 16.2 do Plano 16: os critérios de aceitação do `AvailabilityService`.
 *
 * "Hoje" é sempre fixado com `Carbon::setTestNow`, nunca deixado livre: a
 * grade depende de "hoje" (antecedência e janela contam a partir dele), e
 * relógio livre em teste de agenda é fonte de falha intermitente, mesmo
 * critério já registrado em `DisponibilidadeTest`.
 *
 * `HOJE` é uma segunda-feira (2026-08-10), de propósito: cai dentro dos dias
 * de atendimento padrão (segunda a sexta) e deixa um fim de semana
 * (15 e 16 de agosto) dentro da janela de quase todo cenário, para provar que
 * o fim de semana some da grade sem precisar de um teste à parte para isso.
 */
class AvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-08-10 12:00:00';

    /**
     * 02:00 UTC do dia 11 ainda é 23:00 do dia 10 em Brasília (fuso fixo
     * UTC-3, sem horário de verão desde 2019). É a janela em que UTC e o
     * fuso do negócio discordam sobre que dia é hoje, usada no teste de fuso.
     */
    private const MADRUGADA_UTC = '2026-08-11 02:00:00';

    private Company $empresa;

    private User $administrador;

    private AvailabilityService $servico;

    private Client $cliente;

    private Address $endereco;

    private int $contadorDeOrdens = 0;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->empresa = Company::query()->firstOrFail();

        $this->administrador = TenantAtual::comTenant(
            $this->empresa->id,
            fn () => User::factory()->create(['name' => 'Administradora da Empresa'])
        );
        $this->administrador->assignRole('administrador');

        // Autenticar resolve o tenant pelo usuário, como em uma requisição
        // real. O AvailabilityService não depende disso (ele mesmo abre
        // TenantAtual::comTenant() com a empresa recebida por parâmetro,
        // porque a página pública do Plano 16 não passa por login), mas criar
        // os dados de apoio (cliente, endereço, técnico) precisa de um tenant
        // corrente resolvido.
        $this->actingAs($this->administrador);

        $this->servico = app(AvailabilityService::class);

        $this->cliente = ClientFactory::new()->create(['name' => 'Padaria Central']);
        $this->endereco = AddressFactory::new()->create(['client_id' => $this->cliente->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Caso 1: a grade respeita dias de atendimento, antecedência e janela
    // -----------------------------------------------------------------

    /**
     * Configuração com janela curta (10 dias) para o limite superior caber
     * dentro dos 15 dias pedidos ao serviço, o que prova as duas pontas no
     * mesmo teste: dia antes da antecedência mínima, fim de semana (fora dos
     * dias de atendimento) e dia além da janela máxima somem da grade, e
     * nenhum deles aparece como `disponivel: false` — eles simplesmente não
     * estão na lista.
     */
    public function test_grade_respeita_dias_de_atendimento_antecedencia_e_janela(): void
    {
        $this->fixarRelogio(self::HOJE);

        $this->configurar([
            'dias_da_semana' => [1, 2, 3, 4, 5],
            'visitas_por_periodo' => 4,
            'antecedencia_minima_dias' => 2,
            'janela_maxima_dias' => 10,
            'aceita_agendamento_online' => true,
        ]);

        $this->criarTecnico('Ana Ferreira');

        $grade = $this->servico->gradeDisponivel($this->empresa, null, 15);
        $datas = collect($grade)->pluck('data')->all();

        // Hoje (2026-08-10) e amanhã (2026-08-11) ficam fora: a antecedência
        // mínima é de 2 dias, então o primeiro dia elegível é 2026-08-12.
        $this->assertNotContains('2026-08-10', $datas, 'hoje está antes da antecedência mínima');
        $this->assertNotContains('2026-08-11', $datas, 'amanhã ainda está antes da antecedência mínima');

        // Fim de semana está fora dos dias de atendimento (segunda a sexta).
        $this->assertNotContains('2026-08-15', $datas, 'sábado não é dia de atendimento');
        $this->assertNotContains('2026-08-16', $datas, 'domingo não é dia de atendimento');

        // A janela máxima é de 10 dias a partir de hoje: 2026-08-20 é o
        // último dia elegível, e 2026-08-21 (dentro dos 15 dias pedidos ao
        // serviço) já está além da janela.
        $this->assertNotContains('2026-08-21', $datas, 'está além da janela máxima de 10 dias');

        $this->assertSame(
            ['2026-08-12', '2026-08-13', '2026-08-14', '2026-08-17', '2026-08-18', '2026-08-19', '2026-08-20'],
            $datas,
            'só os dias úteis entre a antecedência mínima e a janela máxima deveriam aparecer'
        );
    }

    // -----------------------------------------------------------------
    // Caso 2: período lotado aparece como indisponível
    // -----------------------------------------------------------------

    public function test_periodo_lotado_aparece_como_indisponivel(): void
    {
        $this->fixarRelogio(self::HOJE);

        $this->configurar([
            'dias_da_semana' => [1, 2, 3, 4, 5],
            'visitas_por_periodo' => 2,
            'antecedencia_minima_dias' => 0,
            'janela_maxima_dias' => 30,
        ]);

        $tecnico = $this->criarTecnico('Ana Ferreira');

        // Duas OS de manhã lotam o único técnico (teto = 2). Nenhuma à
        // tarde: a tarde continua livre.
        $this->criarOrdem([
            'technician_id' => $tecnico->id,
            'scheduled_date' => '2026-08-11',
            'start_time' => '2026-08-11 13:00:00',
        ]);
        $this->criarOrdem([
            'technician_id' => $tecnico->id,
            'scheduled_date' => '2026-08-11',
            'start_time' => '2026-08-11 14:00:00',
        ]);

        $grade = $this->servico->gradeDisponivel($this->empresa, null, 5);
        $dia = collect($grade)->firstWhere('data', '2026-08-11');

        $this->assertNotNull($dia, 'o cenário exige 2026-08-11 dentro da janela pedida');
        $this->assertFalse($dia['manha']['disponivel'], 'o único técnico já está no teto de manhã');
        $this->assertTrue($dia['tarde']['disponivel'], 'a tarde não tem nenhuma OS ainda');
    }

    // -----------------------------------------------------------------
    // Caso 3: a grade não expõe contagem de vagas nem técnico
    // -----------------------------------------------------------------

    public function test_grade_nao_expoe_contagem_de_vagas_nem_tecnico(): void
    {
        $this->fixarRelogio(self::HOJE);

        $this->configurar([
            'dias_da_semana' => [1, 2, 3, 4, 5],
            'visitas_por_periodo' => 4,
            'antecedencia_minima_dias' => 1,
            'janela_maxima_dias' => 30,
        ]);

        $tecnico = $this->criarTecnico('Ana Ferreira');
        $this->criarOrdem([
            'technician_id' => $tecnico->id,
            'scheduled_date' => '2026-08-11',
            'start_time' => '2026-08-11 13:00:00',
        ]);

        $grade = $this->servico->gradeDisponivel($this->empresa, null, 5);

        $this->assertNotEmpty($grade, 'o cenário precisa de ao menos um dia na grade para o formato ser conferido');

        foreach ($grade as $dia) {
            $this->assertSame(['data', 'manha', 'tarde'], array_keys($dia));
            $this->assertSame(['disponivel'], array_keys($dia['manha']), 'nenhuma chave além de disponivel pode vazar no período');
            $this->assertSame(['disponivel'], array_keys($dia['tarde']), 'nenhuma chave além de disponivel pode vazar no período');
            $this->assertIsBool($dia['manha']['disponivel']);
            $this->assertIsBool($dia['tarde']['disponivel']);
        }
    }

    // -----------------------------------------------------------------
    // Caso 4: podeAceitar recusa período que lotou depois do pedido
    // -----------------------------------------------------------------

    /**
     * `podeAceitar()` é chamado de novo no momento da confirmação porque a
     * agenda muda entre o pedido e a confirmação. Este teste reproduz
     * exatamente essa passagem de tempo: a checagem passa antes da OS
     * existir e recusa depois que ela lota o período.
     */
    public function test_pode_aceitar_recusa_periodo_que_lotou_depois_do_pedido(): void
    {
        $this->fixarRelogio(self::HOJE);

        $this->configurar([
            'dias_da_semana' => [1, 2, 3, 4, 5],
            'visitas_por_periodo' => 1,
            'antecedencia_minima_dias' => 0,
            'janela_maxima_dias' => 30,
        ]);

        $tecnico = $this->criarTecnico('Ana Ferreira');

        $this->assertTrue(
            $this->servico->podeAceitar($this->empresa, '2026-08-11', AvailabilityService::PERIODO_MANHA),
            'sem nenhuma OS ainda, o único técnico tem vaga de manhã'
        );

        // A agenda muda: uma OS entra e ocupa a única vaga do teto.
        $this->criarOrdem([
            'technician_id' => $tecnico->id,
            'scheduled_date' => '2026-08-11',
            'start_time' => '2026-08-11 13:00:00',
        ]);

        $this->assertFalse(
            $this->servico->podeAceitar($this->empresa, '2026-08-11', AvailabilityService::PERIODO_MANHA),
            'o período lotou depois do pedido, e a confirmação precisa recusar'
        );

        $this->assertTrue(
            $this->servico->podeAceitar($this->empresa, '2026-08-11', AvailabilityService::PERIODO_TARDE),
            'a tarde não foi afetada pela OS de manhã'
        );
    }

    // -----------------------------------------------------------------
    // Caso 5: sem configuração salva, o padrão do sistema é aplicado
    // -----------------------------------------------------------------

    public function test_sem_configuracao_o_padrao_e_aplicado(): void
    {
        $this->fixarRelogio(self::HOJE);

        // Nenhuma CompanyAvailabilitySetting criada de propósito.
        $tecnico = $this->criarTecnico('Ana Ferreira');

        $grade = $this->servico->gradeDisponivel($this->empresa, null, 10);
        $datas = collect($grade)->pluck('data')->all();

        // Antecedência padrão de 2 dias: hoje e amanhã ficam de fora.
        $this->assertNotContains('2026-08-10', $datas);
        $this->assertNotContains('2026-08-11', $datas);
        $this->assertContains('2026-08-12', $datas, 'primeiro dia elegível pela antecedência padrão de 2 dias');

        // Dias de atendimento padrão: segunda a sexta.
        $this->assertNotContains('2026-08-15', $datas, 'sábado não é dia de atendimento no padrão');
        $this->assertNotContains('2026-08-16', $datas, 'domingo não é dia de atendimento no padrão');

        // Teto padrão de 4 visitas por período por técnico. Os horários estão
        // em UTC (é assim que o campo é gravado); 10h-13h UTC caem às
        // 7h-10h em Brasília (manhã), e 18h-20h UTC caem às 15h-17h em
        // Brasília (tarde), mesma convenção de horário comercial em UTC já
        // usada em DisponibilidadeTest.
        foreach (['10:00:00', '11:00:00', '12:00:00', '13:00:00'] as $horario) {
            $this->criarOrdem([
                'technician_id' => $tecnico->id,
                'scheduled_date' => '2026-08-12',
                'start_time' => '2026-08-12 '.$horario,
            ]);
        }
        // Só 3 à tarde: o teto padrão de 4 não é atingido.
        foreach (['18:00:00', '19:00:00', '20:00:00'] as $horario) {
            $this->criarOrdem([
                'technician_id' => $tecnico->id,
                'scheduled_date' => '2026-08-12',
                'start_time' => '2026-08-12 '.$horario,
            ]);
        }

        $grade = $this->servico->gradeDisponivel($this->empresa, null, 10);
        $dia = collect($grade)->firstWhere('data', '2026-08-12');

        $this->assertFalse($dia['manha']['disponivel'], 'quatro OS de manhã atingem o teto padrão de 4');
        $this->assertTrue($dia['tarde']['disponivel'], 'três OS à tarde ainda não atingem o teto padrão de 4');
    }

    // -----------------------------------------------------------------
    // Caso 6: o cálculo usa o dia e a hora de Brasília, nunca UTC
    // -----------------------------------------------------------------

    public function test_calculo_usa_o_dia_e_a_hora_de_brasilia_e_nunca_utc(): void
    {
        // 2026-08-11 02:00 UTC ainda é 2026-08-10 23:00 em Brasília: se a
        // grade usasse "hoje" em UTC, a antecedência mínima padrão (2 dias)
        // contaria a partir de 2026-08-11 (terça) em vez de 2026-08-10
        // (segunda), e 2026-08-12 não apareceria como primeiro dia elegível.
        $this->fixarRelogio(self::MADRUGADA_UTC);

        // Guarda do próprio teste: se estas duas linhas passarem a concordar,
        // o cenário deixou de exercitar a virada de dia e o teste perde o
        // sentido (mesmo critério do BusinessDateTest).
        $this->assertSame('2026-08-11', Carbon::now('UTC')->toDateString());
        $this->assertSame('2026-08-10', BusinessDate::hoje()->toDateString());

        $this->configurar([
            'dias_da_semana' => [1, 2, 3, 4, 5],
            'visitas_por_periodo' => 1,
            'antecedencia_minima_dias' => 2,
            'janela_maxima_dias' => 30,
        ]);

        $tecnico = $this->criarTecnico('Ana Ferreira');

        $grade = $this->servico->gradeDisponivel($this->empresa, null, 5);
        $datas = collect($grade)->pluck('data')->all();

        $this->assertContains(
            '2026-08-12',
            $datas,
            '2026-08-12 só é o primeiro dia elegível quando "hoje" é calculado no fuso de Brasília (2026-08-10), '
            .'não em UTC (2026-08-11)'
        );

        // Segunda parte: uma OS gravada às 02:30 UTC de 2026-08-12 é, em
        // Brasília, 23:30 de 2026-08-11 — hora 23, portanto tarde. Se o
        // serviço usasse a hora crua em UTC (02h) para classificar o
        // período, classificaria como manhã, e este teste pegaria o erro:
        // com teto 1, a OS lotaria o período errado.
        $this->criarOrdem([
            'technician_id' => $tecnico->id,
            'scheduled_date' => '2026-08-12',
            'start_time' => '2026-08-12 02:30:00',
        ]);

        $grade = $this->servico->gradeDisponivel($this->empresa, null, 5);
        $dia = collect($grade)->firstWhere('data', '2026-08-12');

        $this->assertNotNull($dia);
        $this->assertTrue($dia['manha']['disponivel'], 'a OS foi classificada como tarde, então a manhã segue livre');
        $this->assertFalse($dia['tarde']['disponivel'], 'a OS caiu na tarde (23h em Brasília), e o teto é 1');
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function fixarRelogio(string $instanteUtc): void
    {
        Carbon::setTestNow(Carbon::parse($instanteUtc, 'UTC'));
    }

    private function configurar(array $atributos): CompanyAvailabilitySetting
    {
        return CompanyAvailabilitySetting::create($atributos);
    }

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
            'scheduled_date' => '2026-08-11',
            'status' => 'scheduled',
        ], $atributos));
    }
}
