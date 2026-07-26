<?php

namespace Tests\Unit;

use App\Models\Contract;
use App\Services\CalendarioDeVisitasService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Task 9.3: cálculo das datas previstas de visita de um contrato periódico.
 *
 * Cálculo puro, sem banco: todo contrato aqui é um model em memória, nunca
 * gravado. Estende Tests\TestCase porque BusinessDate lê
 * config('app.business_timezone') e isso exige a aplicação de pé.
 *
 * O caso que mais importa é o do contrato iniciado no último dia do mês. A
 * soma de mês padrão do Carbon transborda (31/01 + 1 mês = 03/03, porque
 * fevereiro não tem dia 31) e a soma em cascata trava o contrato no dia
 * reduzido pelo resto da vigência (31/01 -> 28/02 -> 28/03 -> 28/04). O
 * calendário correto é ancorado em start_date e usa addMonthsNoOverflow:
 * 31/01 -> 28/02 -> 31/03 -> 30/04. Trocar addMonthsNoOverflow por addMonths,
 * ou passar a somar a partir da visita anterior, derruba
 * test_contrato_mensal_iniciado_no_dia_31_cai_no_ultimo_dia_do_mes_curto_e_volta_ao_dia_31.
 *
 * Data de visita errada é OS agendada no dia errado para um cliente real, e
 * contrato periódico sem visita no período é pendência de conformidade com a
 * RDC 622/2022. Nenhum caso aqui é decorativo.
 */
class CalendarioDeVisitasTest extends TestCase
{
    /**
     * 00:30 de 26/07 em UTC, que é 21:30 de 25/07 em Brasília. Só os testes de
     * `proximasDatas` dependem do relógio, e usam este instante para que o
     * "hoje" do cálculo seja o dia do negócio (25/07), nunca o dia em UTC.
     */
    private const MOMENTO_VIRADA = '2026-07-26 00:30:00';

    private const HOJE = '2026-07-25';

    private CalendarioDeVisitasService $calendario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calendario = new CalendarioDeVisitasService;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Caso 1: contrato mensal de 12 meses
    // -----------------------------------------------------------------

    public function test_contrato_mensal_de_doze_meses_gera_doze_datas_na_ordem(): void
    {
        $contrato = $this->contrato([
            'start_date' => '2026-01-10',
            'end_date' => '2026-12-10',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'meses',
        ]);

        $datas = $this->calendario->datasPrevistas($contrato);

        $this->assertCount(12, $datas);
        $this->assertSame([
            '2026-01-10', '2026-02-10', '2026-03-10', '2026-04-10',
            '2026-05-10', '2026-06-10', '2026-07-10', '2026-08-10',
            '2026-09-10', '2026-10-10', '2026-11-10', '2026-12-10',
        ], $this->apenasDatas($datas));

        $this->assertSame(range(1, 12), array_column($datas, 'numero'), 'a numeração precisa ser sequencial e em ordem');
    }

    public function test_start_date_e_sempre_a_primeira_visita(): void
    {
        $contrato = $this->contrato([
            'start_date' => '2026-03-05',
            'end_date' => '2026-06-05',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'meses',
        ]);

        $primeira = $this->calendario->datasPrevistas($contrato)[0];

        $this->assertSame(['numero' => 1, 'data' => '2026-03-05'], $primeira);
    }

    // -----------------------------------------------------------------
    // Caso 2 e 3: fim de mês e ano bissexto
    // -----------------------------------------------------------------

    /**
     * O caso 2 da task, e o defeito mais provável do cálculo de meses.
     *
     * Duas garantias em um teste só, porque as duas quebram no mesmo cenário:
     *
     * - 28/02 e não 03/03: é o addMonthsNoOverflow.
     * - 31/03 e não 28/03: é a ancoragem em start_date. Em cascata, o mês
     *   curto contaminaria todos os meses seguintes e o cliente que contratou
     *   dia 31 receberia visita dia 28 pelo resto da vigência.
     */
    public function test_contrato_mensal_iniciado_no_dia_31_cai_no_ultimo_dia_do_mes_curto_e_volta_ao_dia_31(): void
    {
        $contrato = $this->contrato([
            'start_date' => '2026-01-31',
            'end_date' => '2026-04-30',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'meses',
        ]);

        $datas = $this->apenasDatas($this->calendario->datasPrevistas($contrato));

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], $datas);

        $this->assertSame('2026-02-28', $datas[1], 'a soma de mês transbordou para março: addMonthsNoOverflow é obrigatório');
        $this->assertNotSame('2026-03-03', $datas[1], '31/01 + 1 mês nunca pode virar 03/03');
        $this->assertSame('2026-03-31', $datas[2], 'a terceira visita voltou ao dia 28: o cálculo virou cascata em vez de ancorado em start_date');
    }

    public function test_ano_bissexto_leva_o_contrato_iniciado_em_31_de_janeiro_para_29_de_fevereiro(): void
    {
        $contrato = $this->contrato([
            'start_date' => '2028-01-31',
            'end_date' => '2028-04-30',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'meses',
        ]);

        $datas = $this->apenasDatas($this->calendario->datasPrevistas($contrato));

        $this->assertSame(['2028-01-31', '2028-02-29', '2028-03-31', '2028-04-30'], $datas);
        $this->assertSame('2028-02-29', $datas[1], '2028 é bissexto: fevereiro tem 29 dias');
    }

    public function test_contrato_iniciado_em_31_de_janeiro_de_ano_comum_para_em_28_de_fevereiro(): void
    {
        $contrato = $this->contrato([
            'start_date' => '2027-01-31',
            'end_date' => '2027-02-28',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'meses',
        ]);

        $this->assertSame(
            ['2027-01-31', '2027-02-28'],
            $this->apenasDatas($this->calendario->datasPrevistas($contrato)),
            '2027 não é bissexto'
        );
    }

    // -----------------------------------------------------------------
    // Casos 4 e 5: quinzenal e semanal
    // -----------------------------------------------------------------

    /**
     * "Quinzenal" no projeto é 15 dias, não duas semanas: é o que o backfill
     * da Task 9.2 grava e o que a tela do contrato exibe.
     */
    public function test_contrato_quinzenal_gera_a_cada_quinze_dias(): void
    {
        $contrato = $this->contrato([
            'start_date' => '2026-07-01',
            'end_date' => '2026-08-30',
            'visit_frequency_valor' => 15,
            'visit_frequency_unidade' => 'dias',
        ]);

        $this->assertSame(
            ['2026-07-01', '2026-07-16', '2026-07-31', '2026-08-15', '2026-08-30'],
            $this->apenasDatas($this->calendario->datasPrevistas($contrato))
        );
    }

    public function test_contrato_semanal_gera_a_cada_sete_dias(): void
    {
        $contrato = $this->contrato([
            'start_date' => '2026-07-01',
            'end_date' => '2026-07-29',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'semanas',
        ]);

        $this->assertSame(
            ['2026-07-01', '2026-07-08', '2026-07-15', '2026-07-22', '2026-07-29'],
            $this->apenasDatas($this->calendario->datasPrevistas($contrato))
        );
    }

    public function test_periodicidade_maior_que_um_multiplica_o_intervalo(): void
    {
        $bimestral = $this->contrato([
            'start_date' => '2026-01-15',
            'end_date' => '2026-07-15',
            'visit_frequency_valor' => 2,
            'visit_frequency_unidade' => 'meses',
        ]);

        $this->assertSame(
            ['2026-01-15', '2026-03-15', '2026-05-15', '2026-07-15'],
            $this->apenasDatas($this->calendario->datasPrevistas($bimestral))
        );
    }

    // -----------------------------------------------------------------
    // Casos 6 e 7: contrato que não gera calendário
    // -----------------------------------------------------------------

    public function test_contrato_pontual_devolve_array_vazio(): void
    {
        $contrato = $this->contrato([
            'service_type' => 'pontual',
            'start_date' => '2026-01-10',
            'end_date' => '2026-12-10',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'meses',
        ]);

        $this->assertSame([], $this->calendario->datasPrevistas($contrato), 'contrato pontual não tem calendário de visitas');
    }

    public function test_contrato_sem_periodicidade_devolve_array_vazio_sem_excecao(): void
    {
        $semValor = $this->contrato([
            'start_date' => '2026-01-10',
            'end_date' => '2026-12-10',
            'visit_frequency_valor' => null,
            'visit_frequency_unidade' => 'meses',
        ]);

        $semUnidade = $this->contrato([
            'start_date' => '2026-01-10',
            'end_date' => '2026-12-10',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => null,
        ]);

        $semNada = $this->contrato([
            'start_date' => '2026-01-10',
            'end_date' => '2026-12-10',
            'visit_frequency_valor' => null,
            'visit_frequency_unidade' => null,
        ]);

        $this->assertSame([], $this->calendario->datasPrevistas($semValor));
        $this->assertSame([], $this->calendario->datasPrevistas($semUnidade));
        $this->assertSame([], $this->calendario->datasPrevistas($semNada));
    }

    /**
     * Unidade fora das três que o backfill grava é contrato não configurado, e
     * a pendência é resolvida na tela. Chutar uma unidade aqui geraria visita
     * em data inventada, e por isso `avancar()` nunca chega a ser chamado com
     * ela: a exceção de unidade não suportada é rede de segurança interna, não
     * comportamento de borda deste método.
     */
    public function test_contrato_com_unidade_nao_reconhecida_devolve_array_vazio_sem_excecao(): void
    {
        $contrato = $this->contrato([
            'start_date' => '2026-01-10',
            'end_date' => '2026-12-10',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'anos',
        ]);

        $this->assertSame([], $this->calendario->datasPrevistas($contrato));
    }

    public function test_periodicidade_zero_ou_negativa_devolve_array_vazio(): void
    {
        $zero = $this->contrato([
            'start_date' => '2026-01-10',
            'end_date' => '2026-12-10',
            'visit_frequency_valor' => 0,
            'visit_frequency_unidade' => 'meses',
        ]);

        $negativa = $this->contrato([
            'start_date' => '2026-01-10',
            'end_date' => '2026-12-10',
            'visit_frequency_valor' => -1,
            'visit_frequency_unidade' => 'meses',
        ]);

        $this->assertSame([], $this->calendario->datasPrevistas($zero), 'periodicidade zero geraria visita todo dia ou nenhuma');
        $this->assertSame([], $this->calendario->datasPrevistas($negativa));
    }

    public function test_contrato_sem_data_de_inicio_devolve_array_vazio(): void
    {
        $contrato = $this->contrato([
            'start_date' => null,
            'end_date' => '2026-12-10',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'meses',
        ]);

        $this->assertSame([], $this->calendario->datasPrevistas($contrato), 'sem start_date não há de onde ancorar o calendário');
    }

    // -----------------------------------------------------------------
    // Casos 8 e 9: limites da vigência e da janela
    // -----------------------------------------------------------------

    public function test_nenhuma_data_ultrapassa_o_end_date_do_contrato(): void
    {
        $contrato = $this->contrato([
            'start_date' => '2026-01-15',
            'end_date' => '2026-03-20',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'meses',
        ]);

        $datas = $this->apenasDatas($this->calendario->datasPrevistas($contrato));

        $this->assertSame(['2026-01-15', '2026-02-15', '2026-03-15'], $datas);

        foreach ($datas as $data) {
            $this->assertLessThanOrEqual('2026-03-20', $data, "a data {$data} ultrapassou a vigência do contrato");
        }
    }

    public function test_data_que_cai_exatamente_no_end_date_entra_no_calendario(): void
    {
        $contrato = $this->contrato([
            'start_date' => '2026-01-15',
            'end_date' => '2026-03-15',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'meses',
        ]);

        $this->assertSame(
            ['2026-01-15', '2026-02-15', '2026-03-15'],
            $this->apenasDatas($this->calendario->datasPrevistas($contrato)),
            'a visita marcada para o último dia de vigência ainda é devida'
        );
    }

    public function test_contrato_sem_end_date_respeita_o_limite_de_janela_informado(): void
    {
        $contrato = $this->contrato([
            'start_date' => '2026-01-10',
            'end_date' => null,
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'meses',
        ]);

        $this->assertSame(
            ['2026-01-10', '2026-02-10', '2026-03-10', '2026-04-10', '2026-05-10', '2026-06-10'],
            $this->apenasDatas($this->calendario->datasPrevistas($contrato, '2026-06-10'))
        );
    }

    public function test_entre_end_date_e_janela_informada_prevalece_a_mais_cedo(): void
    {
        $contrato = $this->contrato([
            'start_date' => '2026-01-10',
            'end_date' => '2026-03-10',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'meses',
        ]);

        $janelaMaisLonga = $this->apenasDatas($this->calendario->datasPrevistas($contrato, '2026-12-10'));
        $janelaMaisCurta = $this->apenasDatas($this->calendario->datasPrevistas($contrato, '2026-02-10'));

        $this->assertSame(['2026-01-10', '2026-02-10', '2026-03-10'], $janelaMaisLonga, 'a vigência do contrato deveria ter cortado antes da janela');
        $this->assertSame(['2026-01-10', '2026-02-10'], $janelaMaisCurta, 'a janela informada deveria ter cortado antes da vigência');
    }

    public function test_contrato_sem_end_date_e_sem_janela_nao_entra_em_laco_infinito(): void
    {
        $contrato = $this->contrato([
            'start_date' => '2026-01-10',
            'end_date' => null,
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'meses',
        ]);

        $datas = $this->calendario->datasPrevistas($contrato);

        // Rede de segurança do Service: 10 anos de vigência presumida.
        $this->assertCount(121, $datas);
        $this->assertSame('2036-01-10', end($datas)['data']);
    }

    // -----------------------------------------------------------------
    // proximasDatas: janela a partir de hoje, no fuso do negócio
    // -----------------------------------------------------------------

    /**
     * A visita marcada para hoje ainda não aconteceu, então hoje conta como
     * futura. O relógio é fixado às 00:30 de 26/07 em UTC, que ainda é 25/07
     * em Brasília: se o cálculo usasse o dia em UTC, a visita de 25/07
     * sumiria da lista das próximas.
     */
    public function test_proximas_datas_descartam_o_passado_e_mantem_a_de_hoje(): void
    {
        Carbon::setTestNow(Carbon::parse(self::MOMENTO_VIRADA, 'UTC'));

        // Guarda do próprio teste: sem esta divergência o cenário não exercita
        // a virada de dia.
        $this->assertSame('2026-07-26', Carbon::now('UTC')->toDateString());

        $contrato = $this->contrato([
            'start_date' => '2026-05-25',
            'end_date' => '2026-12-25',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'meses',
        ]);

        $proximas = $this->apenasDatas($this->calendario->proximasDatas($contrato, 3));

        $this->assertSame(['2026-07-25', '2026-08-25', '2026-09-25', '2026-10-25'], $proximas);
        $this->assertContains(self::HOJE, $proximas, 'a visita de hoje ainda não aconteceu e não pode sumir da lista');
    }

    public function test_proximas_datas_preservam_a_numeracao_do_calendario_completo(): void
    {
        Carbon::setTestNow(Carbon::parse(self::MOMENTO_VIRADA, 'UTC'));

        $contrato = $this->contrato([
            'start_date' => '2026-05-25',
            'end_date' => '2026-12-25',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'meses',
        ]);

        $proximas = $this->calendario->proximasDatas($contrato, 3);

        // 25/05 é a visita 1 e 25/06 é a 2: a primeira das próximas é a 3.
        $this->assertSame([3, 4, 5, 6], array_column($proximas, 'numero'));
    }

    public function test_proximas_datas_de_contrato_sem_periodicidade_e_vazio(): void
    {
        Carbon::setTestNow(Carbon::parse(self::MOMENTO_VIRADA, 'UTC'));

        $contrato = $this->contrato([
            'start_date' => '2026-05-25',
            'end_date' => '2026-12-25',
            'visit_frequency_valor' => null,
            'visit_frequency_unidade' => null,
        ]);

        $this->assertSame([], $this->calendario->proximasDatas($contrato, 3));
    }

    // -----------------------------------------------------------------
    // dataDaVisita: usada pela renumeração da Task 9.5
    // -----------------------------------------------------------------

    public function test_data_da_visita_coincide_com_a_lista_completa(): void
    {
        $contrato = $this->contrato([
            'start_date' => '2026-01-31',
            'end_date' => '2026-06-30',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'meses',
        ]);

        $completa = $this->calendario->datasPrevistas($contrato);

        foreach ($completa as $visita) {
            $this->assertSame(
                $visita['data'],
                $this->calendario->dataDaVisita($contrato, $visita['numero']),
                "dataDaVisita divergiu da lista completa na visita {$visita['numero']}"
            );
        }
    }

    public function test_data_da_visita_devolve_nulo_fora_da_vigencia_e_para_numero_invalido(): void
    {
        $contrato = $this->contrato([
            'start_date' => '2026-01-15',
            'end_date' => '2026-03-15',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'meses',
        ]);

        $this->assertSame('2026-03-15', $this->calendario->dataDaVisita($contrato, 3));
        $this->assertNull($this->calendario->dataDaVisita($contrato, 4), 'a visita 4 cairia além da vigência');
        $this->assertNull($this->calendario->dataDaVisita($contrato, 0));
        $this->assertNull($this->calendario->dataDaVisita($contrato, -1));
    }

    public function test_data_da_visita_de_contrato_sem_periodicidade_e_nula(): void
    {
        $contrato = $this->contrato([
            'start_date' => '2026-01-15',
            'end_date' => '2026-03-15',
            'visit_frequency_valor' => null,
            'visit_frequency_unidade' => null,
        ]);

        $this->assertNull($this->calendario->dataDaVisita($contrato, 1));
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Contrato em memória, nunca gravado: o Service só lê atributos.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function contrato(array $atributos): Contract
    {
        return new Contract(array_merge([
            'service_type' => 'periodico',
            'visit_count' => 12,
        ], $atributos));
    }

    /**
     * @param  array<int, array{numero: int, data: string}>  $datas
     * @return array<int, string>
     */
    private function apenasDatas(array $datas): array
    {
        return array_column($datas, 'data');
    }
}
