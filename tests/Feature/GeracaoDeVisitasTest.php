<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\WorkOrder;
use App\Services\GeracaoDeVisitasService;
use App\Services\ManutencaoDeVisitasService;
use App\Services\PendenciasDeContratoService;
use App\Support\BusinessDate;
use Carbon\Carbon;
use Database\Factories\AddressFactory;
use Database\Factories\WorkOrderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Plano 9, Tasks 9.3 a 9.7: geração das visitas do contrato como Ordem de
 * Serviço, idempotência, reprogramação, cancelamento em cascata e painel de
 * pendências de conformidade.
 *
 * Duas regras dominam este arquivo, e nenhuma delas pode ser afrouxada:
 *
 * 1. **Visita executada nunca é tocada.** Nem movida, nem renumerada, nem
 *    cancelada, por nenhuma operação. É histórico, tem valor perante
 *    fiscalização, e os testes que a protegem comparam a linha inteira do
 *    banco antes e depois, não só o status.
 * 2. **A geração nunca cria visita com data no passado.** Contrato vigente há
 *    dois anos, na primeira geração, cria apenas as visitas de hoje em diante.
 *    As datas passadas sem OS viram pendência de conformidade (RDC 622/2022),
 *    nunca OS retroativa. Esse defeito já existiu; o teste é o que impede a
 *    volta dele.
 *
 * O relógio é sempre fixado às 00:30 de 26/07 em UTC, que ainda é 21:30 de
 * 25/07 em Brasília: o "hoje" de todo o cálculo é o dia do negócio (25/07).
 * Trocar BusinessDate::hoje() por Carbon::today() nos Services derruba os
 * testes de corte de data.
 */
class GeracaoDeVisitasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 00:30 de 26/07 em UTC, que é 21:30 de 25/07 em Brasília.
     */
    private const MOMENTO_VIRADA = '2026-07-26 00:30:00';

    /**
     * Dias no fuso do negócio, relativos ao instante acima.
     */
    private const HOJE = '2026-07-25';

    /**
     * Fim da janela padrão de 3 meses (config('contratos.meses_de_janela')).
     */
    private const ATE_TRES_MESES = '2026-10-25';

    /**
     * Espelha a constante privada `GerarVisitasDeContratos::CHAVE_DO_LOCK`.
     * Mudou lá, muda aqui.
     */
    private const CHAVE_DO_LOCK = 'contratos:gerar-visitas:lock';

    private GeracaoDeVisitasService $geracao;

    private ManutencaoDeVisitasService $manutencao;

    private PendenciasDeContratoService $pendencias;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::MOMENTO_VIRADA, 'UTC'));

        $this->geracao = app(GeracaoDeVisitasService::class);
        $this->manutencao = app(ManutencaoDeVisitasService::class);
        $this->pendencias = app(PendenciasDeContratoService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Geração: o que a OS carrega
    // -----------------------------------------------------------------

    public function test_geracao_cria_os_agendada_com_contrato_origem_e_numero_da_visita(): void
    {
        // Guarda do próprio teste: sem esta divergência o cenário não exercita
        // a virada de dia e o corte por data passaria mesmo comparando em UTC.
        $this->assertSame('2026-07-26', Carbon::now('UTC')->toDateString());
        $this->assertSame(self::HOJE, BusinessDate::hoje()->toDateString());

        $contrato = $this->criarContrato();

        $criadas = $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);

        $this->assertCount(4, $criadas);

        foreach ($criadas as $indice => $visita) {
            $this->assertSame($contrato->id, (int) $visita->contract_id, 'a visita nasceu sem vínculo com o contrato');
            $this->assertSame('contrato', $visita->origem, 'sem origem = contrato o cancelamento em cascata não distingue visita de OS manual');
            $this->assertSame($indice + 1, (int) $visita->visita_numero);
            $this->assertSame('scheduled', $visita->status, 'visita gerada já nasce com data, então é agendada, nunca pendente');
            $this->assertNull($visita->technician_id, 'atribuir técnico automaticamente é o Plano 10');
            $this->assertNotEmpty($visita->order_number);
        }

        $this->assertSame(
            ['2026-07-25', '2026-08-25', '2026-09-25', '2026-10-25'],
            $this->datasGeradas($contrato)
        );

        $this->assertSame(
            "Visita 1 de 12 do contrato {$contrato->contract_number}",
            $criadas[0]->description
        );
    }

    public function test_client_id_da_os_vem_do_endereco_do_contrato(): void
    {
        $endereco = AddressFactory::new()->create();
        $contrato = $this->criarContrato([], $endereco->id);

        $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);

        $visitas = $this->geracao->visitasDe($contrato);

        $this->assertCount(4, $visitas);

        foreach ($visitas as $visita) {
            // `contracts` não guarda cliente, só address_id: o cliente da OS
            // sai do endereço, e errar isso põe a visita na agenda de outro
            // cliente.
            $this->assertSame((int) $endereco->client_id, (int) $visita->client_id);
            $this->assertSame((int) $endereco->id, (int) $visita->address_id);
        }
    }

    // -----------------------------------------------------------------
    // Idempotência: entregável explícito do plano
    // -----------------------------------------------------------------

    public function test_gerar_duas_vezes_nao_duplica_visita(): void
    {
        $contrato = $this->criarContrato();

        $primeira = $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);
        $segunda = $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);

        $this->assertCount(4, $primeira);
        $this->assertSame([], $segunda, 'a segunda geração criou visita de novo');
        $this->assertSame(4, WorkOrder::query()->count());

        $datas = $this->datasGeradas($contrato);
        $this->assertSame($datas, array_unique($datas), 'a mesma data ficou com mais de uma OS');
    }

    /**
     * A idempotência é pelo par [contract_id, scheduled_date], nunca por
     * `visita_numero`: a reprogramação renumera mantendo datas, e um unique em
     * `visita_numero` travaria isso. O teste força o cenário renumerando à mão
     * antes da segunda geração.
     */
    public function test_idempotencia_e_pela_data_e_nao_pelo_numero_da_visita(): void
    {
        $contrato = $this->criarContrato();

        $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);

        WorkOrder::query()->where('contract_id', $contrato->id)->update(['visita_numero' => 99]);

        $this->assertSame([], $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES));
        $this->assertSame(4, WorkOrder::query()->count());
    }

    // -----------------------------------------------------------------
    // Nenhuma OS retroativa
    // -----------------------------------------------------------------

    /**
     * Contrato mensal vigente há dois anos, gerado pela primeira vez hoje.
     *
     * A numeração continua ancorada em `start_date` (a visita de hoje é a 25,
     * não a 1), e nenhuma OS nasce com data anterior a hoje. OS retroativa
     * apareceria na agenda do cliente já vencida, para visita que nunca foi
     * devida, e um contrato antigo inundaria a agenda na primeira execução da
     * rotina.
     */
    public function test_geracao_nunca_cria_visita_com_data_no_passado(): void
    {
        $contrato = $this->criarContrato([
            'start_date' => '2024-07-25',
            'end_date' => null,
        ]);

        $criadas = $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);

        $this->assertCount(4, $criadas);
        $this->assertSame(
            ['2026-07-25', '2026-08-25', '2026-09-25', '2026-10-25'],
            $this->datasGeradas($contrato)
        );
        $this->assertSame([25, 26, 27, 28], array_map(
            fn (WorkOrder $visita) => (int) $visita->visita_numero,
            $criadas
        ), 'a numeração precisa continuar ancorada em start_date, sem reiniciar em 1');

        $this->assertSame(
            0,
            WorkOrder::query()->where('scheduled_date', '<', self::HOJE)->count(),
            'a geração criou OS com data no passado'
        );
    }

    public function test_datas_passadas_sem_os_viram_pendencia_de_conformidade_e_nao_os_retroativa(): void
    {
        $contrato = $this->criarContrato([
            'start_date' => '2024-07-25',
            'end_date' => null,
        ]);

        $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);

        $pendencias = $this->geracao->pendenciasDeConformidade($contrato);

        // De 25/07/2024 a 25/06/2026 são 24 visitas mensais que já eram
        // devidas e nunca viraram OS.
        $this->assertCount(24, $pendencias);
        $this->assertSame(['numero' => 1, 'data' => '2024-07-25'], $pendencias[0]);
        $this->assertSame(['numero' => 24, 'data' => '2026-06-25'], end($pendencias));

        $this->assertSame(4, WorkOrder::query()->count(), 'a pendência não pode virar OS');
    }

    public function test_data_passada_que_ja_tem_os_nao_e_pendencia_de_conformidade(): void
    {
        $contrato = $this->criarContrato([
            'start_date' => '2026-05-25',
            'end_date' => null,
        ]);

        // A visita de maio foi executada e a de junho foi criada à mão: as
        // duas datas estão cobertas, nenhuma está faltando.
        $this->criarOs($contrato, '2026-05-25', 'completed', 1);
        $this->criarOs($contrato, '2026-06-25', 'completed', null, null);

        $this->assertSame([], $this->geracao->pendenciasDeConformidade($contrato));
    }

    // -----------------------------------------------------------------
    // Visita cancelada: buraco de conformidade que o painel precisa acusar
    // -----------------------------------------------------------------

    /**
     * Visita cancelada não foi executada e não vai ser. A data continua sem
     * cobertura, e contrato periódico sem visita no período é pendência de
     * conformidade com a RDC 622/2022. Contar a OS cancelada como "data
     * coberta" era o que fazia o painel calar sobre visita que nunca vai
     * acontecer.
     */
    public function test_data_com_visita_cancelada_no_passado_volta_a_ser_pendencia_de_conformidade(): void
    {
        $contrato = $this->criarContrato([
            'start_date' => '2026-05-25',
            'end_date' => '2026-12-25',
        ]);

        $this->criarOs($contrato, '2026-05-25', 'cancelled', 1);
        $this->criarOs($contrato, '2026-06-25', 'completed', 2);

        $this->assertSame(
            [['numero' => 1, 'data' => '2026-05-25']],
            $this->geracao->pendenciasDeConformidade($contrato),
            'a data com visita cancelada sumiu do radar de conformidade'
        );
    }

    /**
     * A contrapartida decidida, e ela é assimétrica de propósito: a data
     * cancelada volta a aparecer no painel, mas a geração continua tratando
     * a data como ocupada. Se `visitaJaExiste` ignorasse a cancelada, a
     * rotina diária recriaria toda madrugada a OS que alguém cancelou pela
     * tela, e o usuário não teria como fazer o cancelamento pegar.
     */
    public function test_visita_cancelada_no_futuro_nao_e_recriada_pela_geracao(): void
    {
        $contrato = $this->criarContrato();

        $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);

        $cancelada = $this->osNaData($contrato, '2026-08-25');
        $cancelada->update(['status' => 'cancelled']);
        $antes = $this->linhaCrua($cancelada);

        $this->assertSame(
            [],
            $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES),
            'a geração ressuscitou uma OS cancelada de propósito pela tela'
        );

        $this->assertSame($antes, $this->linhaCrua($cancelada));
        $this->assertSame(4, WorkOrder::query()->count());
    }

    /**
     * O cenário inteiro do defeito: mensal a partir de 25/07, troca para
     * bimestral (25/08 e 25/10 são canceladas), troca de volta para mensal.
     *
     * O reaproveitamento das datas canceladas não acontece, pelo mesmo motivo
     * do teste acima, e o contrato fica com duas visitas ativas em vez de
     * quatro. O que não pode acontecer é isso passar em silêncio: quando as
     * duas datas vencem, elas voltam a ser pendência de conformidade, tanto
     * na fonte quanto no painel.
     */
    public function test_ida_e_volta_de_periodicidade_deixa_as_datas_canceladas_visiveis_no_painel(): void
    {
        $contrato = $this->criarContrato(['end_date' => '2026-12-25']);

        $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);

        $contrato->update(['visit_frequency_valor' => 2]);
        $this->assertSame(2, $this->manutencao->reprogramar($contrato->fresh())['canceladas']);

        $contrato->update(['visit_frequency_valor' => 1]);
        $this->manutencao->reprogramar($contrato->fresh());

        $this->assertSame(
            ['2026-07-25' => 'scheduled', '2026-08-25' => 'cancelled', '2026-09-25' => 'scheduled', '2026-10-25' => 'cancelled'],
            $this->geracao->visitasDe($contrato)
                ->mapWithKeys(fn (WorkOrder $visita) => [BusinessDate::diaDe($visita->scheduled_date) => $visita->status])
                ->all()
        );

        // Passados quatro meses, 25/08 e 25/10 venceram sem nenhuma visita.
        Carbon::setTestNow(Carbon::parse('2026-11-26 00:30:00', 'UTC'));

        $this->assertSame(
            ['2026-08-25', '2026-10-25'],
            array_column($this->geracao->pendenciasDeConformidade($contrato), 'data'),
            'duas visitas que nunca vão acontecer ficaram fora da lista de pendências'
        );

        $painel = $this->pendencias->listar();

        $this->assertCount(1, $painel);
        $this->assertSame($contrato->id, $painel[0]['contrato_id']);
        $this->assertStringContainsString(
            'Sem visita gerada no período configurado: 2 data(s) prevista(s)',
            implode(' ', $painel[0]['motivos']),
            'o painel que existe para impedir gap de conformidade ficou em silêncio'
        );
    }

    // -----------------------------------------------------------------
    // Contrato que não gera calendário
    // -----------------------------------------------------------------

    public function test_contrato_pontual_sem_periodicidade_e_com_unidade_desconhecida_nao_geram_nada(): void
    {
        $pontual = $this->criarContrato(['service_type' => 'pontual']);
        $semPeriodicidade = $this->criarContrato([
            'visit_frequency_valor' => null,
            'visit_frequency_unidade' => null,
        ]);
        $unidadeDesconhecida = $this->criarContrato(['visit_frequency_unidade' => 'anos']);

        foreach (['pontual' => $pontual, 'sem periodicidade' => $semPeriodicidade, 'unidade desconhecida' => $unidadeDesconhecida] as $rotulo => $contrato) {
            $this->assertSame([], $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES), "o contrato {$rotulo} gerou visita");
            $this->assertSame([], $this->geracao->pendenciasDeConformidade($contrato), "o contrato {$rotulo} produziu pendência de calendário");
        }

        $this->assertSame(0, WorkOrder::query()->count());
    }

    // -----------------------------------------------------------------
    // Leitura: visitasDe e visitasComSituacao
    // -----------------------------------------------------------------

    public function test_visitas_de_traz_apenas_as_geradas_pelo_contrato(): void
    {
        $contrato = $this->criarContrato();

        $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);
        $manual = $this->criarOs($contrato, '2026-08-10', 'scheduled', null, null);

        $ids = $this->geracao->visitasDe($contrato)->pluck('id')->all();

        $this->assertCount(4, $ids);
        $this->assertNotContains($manual->id, $ids, 'OS criada à mão não é visita do contrato');
    }

    public function test_visitas_com_situacao_classifica_cada_linha_do_calendario(): void
    {
        $contrato = $this->criarContrato([
            'start_date' => '2026-04-25',
            'end_date' => '2026-10-25',
        ]);

        $this->criarOs($contrato, '2026-04-25', 'completed', 1);
        // 25/05 fica sem OS de propósito: é a pendência.
        $this->criarOs($contrato, '2026-06-25', 'scheduled', 3);
        $this->criarOs($contrato, '2026-07-25', 'scheduled', 4);
        $this->criarOs($contrato, '2026-08-25', 'cancelled', 5);
        // 25/09 fica sem OS de propósito: ainda é futura.
        $this->criarOs($contrato, '2026-10-25', 'in_progress', 7);

        $linhas = $this->geracao->visitasComSituacao($contrato);

        $this->assertSame([
            'executada',   // 25/04, concluída
            'pendente',    // 25/05, passou e nunca virou OS
            'atrasada',    // 25/06, agendada e vencida
            'agendada',    // 25/07, é hoje: ainda não venceu
            'cancelada',   // 25/08
            'prevista',    // 25/09, futura e sem OS
            'em_execucao', // 25/10
        ], array_column($linhas, 'situacao'));

        $this->assertSame('25/04/2026', $linhas[0]['data'], 'a data da linha vai formatada em pt-BR pelo backend');
        $this->assertSame(range(1, 7), array_column($linhas, 'numero'));
        $this->assertNull($linhas[1]['ordem_servico'], 'a linha pendente não tem OS');
        $this->assertSame('completed', $linhas[0]['ordem_servico']['status']);
    }

    // -----------------------------------------------------------------
    // Reprogramação
    // -----------------------------------------------------------------

    public function test_mudar_a_periodicidade_cancela_as_que_sobraram_e_mantem_as_coincidentes(): void
    {
        $contrato = $this->criarContrato();

        $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);

        // Mensal vira bimestral: 25/07 e 25/09 continuam previstas, 25/08 e
        // 25/10 deixam de existir no calendário.
        $contrato->update(['visit_frequency_valor' => 2]);

        $resumo = $this->manutencao->reprogramar($contrato->fresh());

        $this->assertSame(2, $resumo['canceladas']);
        $this->assertSame(0, $resumo['movidas']);
        $this->assertSame(0, $resumo['criadas']);
        $this->assertSame(1, $resumo['renumeradas']);

        $mantida = $this->osNaData($contrato, '2026-07-25');
        $this->assertSame('scheduled', $mantida->status);
        $this->assertSame(1, (int) $mantida->visita_numero);

        $renumerada = $this->osNaData($contrato, '2026-09-25');
        $this->assertSame('scheduled', $renumerada->status, 'data que continua prevista não pode ser cancelada');
        $this->assertSame(2, (int) $renumerada->visita_numero, 'a visita que continuou na mesma data precisava ser renumerada');
        $this->assertSame(
            "Visita 2 de 12 do contrato {$contrato->contract_number}",
            $renumerada->description,
            'a descrição é impressa no documento e precisa acompanhar o número'
        );

        foreach (['2026-08-25', '2026-10-25'] as $data) {
            $cancelada = $this->osNaData($contrato, $data);

            $this->assertSame('cancelled', $cancelada->status, "a visita de {$data} saiu do calendário e deveria ter sido cancelada");
            $this->assertStringContainsString(
                ManutencaoDeVisitasService::MOTIVO_REPROGRAMACAO,
                (string) $cancelada->completion_notes,
                'visita que some sem motivo gravado vira discussão com o cliente'
            );
        }
    }

    /**
     * Visita futura que saiu do calendário é aproveitada para a primeira data
     * prevista ainda livre, preservando o que já foi anotado na OS, em vez de
     * ser cancelada e recriada do zero.
     */
    public function test_visita_que_saiu_do_calendario_e_aproveitada_na_primeira_data_livre(): void
    {
        $contrato = $this->criarContrato([
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'semanas',
            'end_date' => '2026-08-08',
        ]);

        $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);
        $this->assertSame(['2026-07-25', '2026-08-01', '2026-08-08'], $this->datasGeradas($contrato));

        $aproveitada = $this->osNaData($contrato, '2026-08-01');
        $aproveitada->update(['observations' => 'Cliente pediu para entrar pelos fundos']);

        // Semanal vira mensal e a vigência é esticada: 01/08 e 08/08 somem do
        // calendário, e 25/08 e 25/09 entram.
        $contrato->update([
            'visit_frequency_unidade' => 'meses',
            'end_date' => self::ATE_TRES_MESES,
        ]);

        $resumo = $this->manutencao->reprogramar($contrato->fresh());

        $this->assertSame(2, $resumo['movidas']);
        $this->assertSame(0, $resumo['canceladas']);

        $movida = $aproveitada->fresh();

        $this->assertSame('2026-08-25', BusinessDate::diaDe($movida->scheduled_date));
        $this->assertSame(2, (int) $movida->visita_numero);
        $this->assertSame('Cliente pediu para entrar pelos fundos', $movida->observations, 'mover a visita não pode apagar o que já foi anotado nela');
        $this->assertSame('scheduled', $movida->status);
    }

    // -----------------------------------------------------------------
    // Histórico intocável: o teste mais importante do plano
    // -----------------------------------------------------------------

    /**
     * Cenário construído para que a reprogramação tivesse todo motivo para
     * mexer: as duas visitas executadas caem em datas que somem do calendário
     * novo. Elas continuam exatamente como estavam, linha por linha do banco.
     */
    public function test_visita_executada_nunca_e_alterada_nem_cancelada_pela_reprogramacao(): void
    {
        $contrato = $this->criarContrato([
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'semanas',
            'end_date' => '2026-08-22',
        ]);

        $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);
        $this->assertSame(
            ['2026-07-25', '2026-08-01', '2026-08-08', '2026-08-15', '2026-08-22'],
            $this->datasGeradas($contrato)
        );

        $concluida = $this->osNaData($contrato, '2026-08-01');
        $concluida->update(['status' => 'completed', 'completion_notes' => 'Serviço executado e assinado pelo cliente']);

        $emExecucao = $this->osNaData($contrato, '2026-08-08');
        $emExecucao->update(['status' => 'in_progress']);

        $antesDaConcluida = $this->linhaCrua($concluida);
        $antesDaEmExecucao = $this->linhaCrua($emExecucao);

        // Semanal vira mensal: 01/08 e 08/08 deixam de existir no calendário.
        $contrato->update([
            'visit_frequency_unidade' => 'meses',
            'end_date' => self::ATE_TRES_MESES,
        ]);

        $resumo = $this->manutencao->reprogramar($contrato->fresh());

        $this->assertSame(2, $resumo['executadas_preservadas']);

        $this->assertSame($antesDaConcluida, $this->linhaCrua($concluida), 'a visita concluída foi alterada pela reprogramação');
        $this->assertSame($antesDaEmExecucao, $this->linhaCrua($emExecucao), 'a visita em execução foi alterada pela reprogramação');

        $this->assertSame('completed', $concluida->fresh()->status);
        $this->assertSame('2026-08-01', BusinessDate::diaDe($concluida->fresh()->scheduled_date), 'a visita concluída foi movida de data');
        $this->assertSame(2, (int) $concluida->fresh()->visita_numero, 'a visita concluída foi renumerada');
        $this->assertSame('Serviço executado e assinado pelo cliente', $concluida->fresh()->completion_notes);

        $this->assertSame('in_progress', $emExecucao->fresh()->status);
        $this->assertSame('2026-08-08', BusinessDate::diaDe($emExecucao->fresh()->scheduled_date));
    }

    public function test_visita_executada_nunca_e_cancelada_pelo_encerramento_do_contrato(): void
    {
        $contrato = $this->criarContrato();

        $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);

        $concluida = $this->osNaData($contrato, '2026-09-25');
        $concluida->update(['status' => 'completed']);

        $antes = $this->linhaCrua($concluida);

        $resumo = $this->manutencao->cancelarFuturas($contrato);

        $this->assertSame(3, $resumo['canceladas']);
        $this->assertSame(1, $resumo['executadas_preservadas']);
        $this->assertSame($antes, $this->linhaCrua($concluida), 'o encerramento do contrato tocou uma visita já executada');
        $this->assertSame('completed', $concluida->fresh()->status);
    }

    /**
     * Visita vencida e não executada é pendência real de operação. Cancelar ou
     * mover esconderia a falha em vez de apontá-la no painel de conformidade.
     */
    public function test_visita_passada_nao_executada_nao_e_movida_pela_reprogramacao(): void
    {
        $contrato = $this->criarContrato([
            'start_date' => '2026-04-25',
            'end_date' => self::ATE_TRES_MESES,
        ]);

        $vencida = $this->criarOs($contrato, '2026-06-25', 'scheduled', 3);
        $antes = $this->linhaCrua($vencida);

        $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);

        // Mensal vira bimestral, o que reembaralha todo o calendário futuro.
        $contrato->update(['visit_frequency_valor' => 2]);

        $resumo = $this->manutencao->reprogramar($contrato->fresh());

        $this->assertSame(1, $resumo['passadas_preservadas']);
        $this->assertSame($antes, $this->linhaCrua($vencida), 'a visita vencida e não executada foi alterada');
        $this->assertSame('2026-06-25', BusinessDate::diaDe($vencida->fresh()->scheduled_date));
        $this->assertSame('scheduled', $vencida->fresh()->status);
    }

    public function test_encerrar_contrato_cancela_as_futuras_com_motivo_gravado(): void
    {
        $contrato = $this->criarContrato([
            'start_date' => '2026-04-25',
            'end_date' => self::ATE_TRES_MESES,
        ]);

        $vencida = $this->criarOs($contrato, '2026-06-25', 'scheduled', 3);
        $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);

        $resumo = $this->manutencao->cancelarFuturas($contrato);

        $this->assertSame(4, $resumo['canceladas']);
        $this->assertSame(1, $resumo['passadas_preservadas'], 'a visita vencida é pendência de operação e continua como está');

        foreach (['2026-07-25', '2026-08-25', '2026-09-25', '2026-10-25'] as $data) {
            $cancelada = $this->osNaData($contrato, $data);

            $this->assertSame('cancelled', $cancelada->status);
            $this->assertStringContainsString(
                ManutencaoDeVisitasService::MOTIVO_ENCERRAMENTO,
                (string) $cancelada->completion_notes
            );
            $this->assertStringContainsString('25/07/2026', (string) $cancelada->completion_notes, 'o motivo é datado no fuso do negócio');
        }

        $this->assertSame('scheduled', $vencida->fresh()->status);
    }

    public function test_motivo_do_cancelamento_nao_apaga_a_anotacao_que_ja_existia(): void
    {
        $contrato = $this->criarContrato();

        $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);

        $visita = $this->osNaData($contrato, '2026-08-25');
        $visita->update(['completion_notes' => 'Portaria pede aviso com 24h de antecedência']);

        $this->manutencao->cancelarFuturas($contrato, 'Encerramento do contrato');

        $notas = (string) $visita->fresh()->completion_notes;

        $this->assertStringContainsString('Portaria pede aviso com 24h de antecedência', $notas);
        $this->assertStringContainsString('Encerramento do contrato', $notas);
    }

    // -----------------------------------------------------------------
    // Numeração: nunca repetida em dois documentos do mesmo contrato
    // -----------------------------------------------------------------

    /**
     * `visita_numero` e a descrição são impressos no documento entregue ao
     * cliente, e documento emitido tem valor perante fiscalização. Duas
     * regras corretas somadas produziam duplicata: visita executada nunca é
     * renumerada, e a numeração do calendário novo recomeça no `start_date`.
     * A renumeração salta os números presos em visita executada.
     */
    public function test_reprogramacao_nunca_repete_o_numero_de_visita_ja_executada(): void
    {
        $contrato = $this->criarContrato([
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'semanas',
            'end_date' => '2026-08-22',
        ]);

        $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);

        $this->osNaData($contrato, '2026-08-01')->update(['status' => 'completed']);
        $this->osNaData($contrato, '2026-08-08')->update(['status' => 'in_progress']);

        // Semanal vira mensal: 01/08 e 08/08 saem do calendário, mas os
        // números 2 e 3 já foram impressos e continuam presos nelas.
        $contrato->update([
            'visit_frequency_unidade' => 'meses',
            'end_date' => self::ATE_TRES_MESES,
        ]);

        $this->manutencao->reprogramar($contrato->fresh());

        $numeros = $this->numerosPorData($contrato);

        $this->assertSame([
            '2026-07-25' => 1,
            '2026-08-01' => 2, // executada: número congelado
            '2026-08-08' => 3, // em execução: número congelado
            '2026-08-25' => 4,
            '2026-09-25' => 5,
            '2026-10-25' => 6,
        ], $numeros);

        $this->assertSame(
            array_values($numeros),
            array_values(array_unique($numeros)),
            'dois documentos do mesmo contrato saíram com o mesmo número de visita'
        );

        $this->assertSame(
            "Visita 2 de 12 do contrato {$contrato->contract_number}",
            $this->osNaData($contrato, '2026-08-01')->description,
            'a descrição da visita executada é histórico e não pode mudar'
        );
        $this->assertSame(
            "Visita 4 de 12 do contrato {$contrato->contract_number}",
            $this->osNaData($contrato, '2026-08-25')->description,
            'a descrição é impressa no documento e precisa acompanhar o número'
        );

        // A tela do contrato não pode mostrar um número diferente do que está
        // impresso na OS.
        foreach ($this->geracao->visitasComSituacao($contrato) as $linha) {
            if ($linha['ordem_servico'] === null) {
                continue;
            }

            $this->assertSame(
                (int) WorkOrder::query()->findOrFail($linha['ordem_servico']['id'])->visita_numero,
                $linha['numero'],
                "a linha de {$linha['data']} mostra um número diferente do gravado na OS"
            );
        }
    }

    /**
     * Quando a renumeração passa do total contratado, o buraco na sequência é
     * aceito (reflete que o calendário mudou no meio da vigência), mas o
     * total impresso sai do texto: "Visita 7 de 5" é documento mentindo.
     */
    public function test_numero_acima_do_total_contratado_imprime_a_visita_sem_o_total(): void
    {
        $contrato = $this->criarContrato([
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'semanas',
            'visit_count' => 5,
            'start_date' => '2026-06-20',
            'end_date' => '2026-07-18',
        ]);

        foreach (['2026-06-20', '2026-06-27', '2026-07-04', '2026-07-11', '2026-07-18'] as $indice => $data) {
            $this->criarOs($contrato, $data, 'completed', $indice + 1);
        }

        // As cinco visitas contratadas já foram executadas e o contrato é
        // esticado com periodicidade mensal.
        $contrato->update([
            'visit_frequency_unidade' => 'meses',
            'end_date' => '2026-10-20',
        ]);

        $this->manutencao->reprogramar($contrato->fresh());

        $this->assertSame([
            '2026-06-20' => 1,
            '2026-06-27' => 2,
            '2026-07-04' => 3,
            '2026-07-11' => 4,
            '2026-07-18' => 5,
            '2026-08-20' => 7,
            '2026-09-20' => 8,
            '2026-10-20' => 9,
        ], $this->numerosPorData($contrato), 'o número 6 é o buraco de 20/07, data que já passou e não vira OS');

        $this->assertSame(
            "Visita 7 do contrato {$contrato->contract_number}",
            $this->osNaData($contrato, '2026-08-20')->description,
            '"Visita 7 de 5" seria documento mentindo: o total sai, o número fica'
        );
    }

    // -----------------------------------------------------------------
    // OS criada à mão: nunca varrida
    // -----------------------------------------------------------------

    /**
     * Só OS com `origem = 'contrato'` entra na varredura. OS que alguém criou
     * à mão apontando para o mesmo contrato não é movida, renumerada nem
     * cancelada por nenhuma das três operações.
     */
    public function test_os_criada_a_mao_nunca_e_tocada_por_nenhuma_operacao(): void
    {
        $contrato = $this->criarContrato();

        // Data futura que não é nenhuma das previstas pelo calendário.
        $manual = $this->criarOs($contrato, '2026-08-10', 'scheduled', null, null);
        $antes = $this->linhaCrua($manual);

        $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);
        $this->assertSame($antes, $this->linhaCrua($manual), 'a geração alterou uma OS criada à mão');

        $contrato->update(['visit_frequency_valor' => 2]);
        $this->manutencao->reprogramar($contrato->fresh());
        $this->assertSame($antes, $this->linhaCrua($manual), 'a reprogramação alterou uma OS criada à mão');

        $this->manutencao->cancelarFuturas($contrato);
        $this->assertSame($antes, $this->linhaCrua($manual), 'o cancelamento em cascata varreu uma OS criada à mão');

        $manual = $manual->fresh();
        $this->assertSame('scheduled', $manual->status);
        $this->assertNull($manual->origem);
        $this->assertNull($manual->visita_numero);
        $this->assertNull($manual->completion_notes);
    }

    // -----------------------------------------------------------------
    // Varredura dos contratos vigentes
    // -----------------------------------------------------------------

    /**
     * "Vigente" para a varredura é contrato com periodicidade configurada e
     * com `end_date` nulo ou de hoje em diante. Contrato fora disso não entra
     * no relatório, e o que entra vem com `erro` nulo.
     */
    public function test_gerar_do_periodo_percorre_apenas_os_contratos_vigentes_com_periodicidade(): void
    {
        $vigente = $this->criarContrato();
        $vigenteAteHoje = $this->criarContrato(['end_date' => self::HOJE]);
        $encerradoOntem = $this->criarContrato(['end_date' => '2026-07-24']);
        $semPeriodicidade = $this->criarContrato([
            'visit_frequency_valor' => null,
            'visit_frequency_unidade' => null,
        ]);

        $relatorio = $this->geracao->gerarDoPeriodo(3);

        $this->assertSame([$vigente->id, $vigenteAteHoje->id], array_keys($relatorio));
        $this->assertNull($relatorio[$vigente->id]['erro']);
        $this->assertCount(4, $relatorio[$vigente->id]['criadas']);
        $this->assertCount(1, $relatorio[$vigenteAteHoje->id]['criadas'], 'contrato que vence hoje ainda tem a visita de hoje');

        $this->assertSame([], $this->datasGeradas($encerradoOntem));
        $this->assertSame([], $this->datasGeradas($semPeriodicidade));
    }

    // -----------------------------------------------------------------
    // Rotina agendada: contratos:gerar-visitas
    // -----------------------------------------------------------------

    public function test_comando_respeita_a_janela_informada_em_meses(): void
    {
        $contrato = $this->criarContrato();

        $this->assertSame(0, Artisan::call('contratos:gerar-visitas', ['--meses' => '1']));

        $this->assertSame(['2026-07-25', '2026-08-25'], $this->datasGeradas($contrato));
    }

    public function test_comando_com_meses_invalido_nao_processa_nada(): void
    {
        $this->criarContrato();

        $this->assertSame(2, Artisan::call('contratos:gerar-visitas', ['--meses' => 'tres']), 'opção inválida precisa devolver INVALID');
        $this->assertStringContainsString('--meses precisa ser um número inteiro maior que zero', Artisan::output());

        $this->assertSame(0, WorkOrder::query()->count());
    }

    public function test_comando_rodado_duas_vezes_nao_muda_a_contagem_de_os(): void
    {
        $this->criarContrato();

        $this->assertSame(0, Artisan::call('contratos:gerar-visitas'));
        $depoisDaPrimeira = WorkOrder::query()->count();

        $this->assertSame(4, $depoisDaPrimeira);

        $this->assertSame(0, Artisan::call('contratos:gerar-visitas'));

        $this->assertSame($depoisDaPrimeira, WorkOrder::query()->count(), 'a segunda execução da rotina duplicou visita');
    }

    public function test_comando_nunca_cria_visita_com_data_anterior_a_hoje(): void
    {
        $this->criarContrato([
            'start_date' => '2024-07-25',
            'end_date' => null,
        ]);

        $this->assertSame(0, Artisan::call('contratos:gerar-visitas'));

        $this->assertSame(
            0,
            WorkOrder::query()->where('scheduled_date', '<', self::HOJE)->count(),
            'a rotina agendada criou OS retroativa'
        );
        $this->assertSame(4, WorkOrder::query()->count());
    }

    /**
     * O `withoutOverlapping` do agendamento não vê uma chamada manual no
     * terminal. Com o lock tomado, o comando não pode processar nada, e sai
     * com sucesso: ele fez exatamente o que devia ao encontrar outra execução
     * em curso.
     */
    public function test_comando_com_lock_ativo_nao_processa_nada_e_sai_com_sucesso(): void
    {
        $this->criarContrato();

        $lock = Cache::lock(self::CHAVE_DO_LOCK, 60);
        $this->assertTrue($lock->get(), 'o cenário exige que o teste tome o lock antes do comando');

        try {
            $this->assertSame(0, Artisan::call('contratos:gerar-visitas'), 'o lock ativo não é falha do comando');
            $saida = Artisan::output();

            $this->assertStringContainsString('Encerrando sem processar nada', $saida);
            $this->assertSame(0, WorkOrder::query()->count(), 'o comando processou mesmo com o lock tomado');
        } finally {
            $lock->release();
        }

        // Liberado o lock, a execução seguinte volta a trabalhar normalmente.
        $this->assertSame(0, Artisan::call('contratos:gerar-visitas'));
        $this->assertSame(4, WorkOrder::query()->count());
    }

    public function test_dry_run_nao_grava_nada_e_reporta_a_mesma_contagem_da_execucao_real(): void
    {
        $this->criarContrato();

        $this->assertSame(0, Artisan::call('contratos:gerar-visitas', ['--dry-run' => true]));
        $saidaSimulada = Artisan::output();

        $this->assertSame(0, WorkOrder::query()->count(), 'a simulação gravou OS');
        $this->assertStringContainsString('Nenhuma visita foi gravada', $saidaSimulada);

        $this->assertSame(0, Artisan::call('contratos:gerar-visitas'));
        $saidaReal = Artisan::output();

        $simuladas = $this->contagemDaSaida($saidaSimulada, 'que seriam criadas');
        $criadas = $this->contagemDaSaida($saidaReal, 'criadas');

        $this->assertSame(4, $simuladas);
        $this->assertSame($simuladas, $criadas, 'o dry-run reportou contagem diferente da execução real');
        $this->assertSame($criadas, WorkOrder::query()->count());
    }

    public function test_comando_relata_contrato_sem_periodicidade_como_pulado(): void
    {
        $this->criarContrato();
        $this->criarContrato([
            'visit_frequency_valor' => null,
            'visit_frequency_unidade' => null,
        ]);

        $this->assertSame(0, Artisan::call('contratos:gerar-visitas'));
        $saida = Artisan::output();

        $this->assertStringContainsString('Contratos processados: 1', $saida);
        $this->assertStringContainsString('Contratos pulados (sem periodicidade reconhecida): 1', $saida);
        $this->assertStringContainsString('Contratos com erro: 0', $saida);
    }

    // -----------------------------------------------------------------
    // Painel de pendências de conformidade (RDC 622/2022)
    // -----------------------------------------------------------------

    public function test_contrato_sem_periodicidade_aparece_no_painel_de_pendencias(): void
    {
        $contrato = $this->criarContrato([
            'visit_frequency_valor' => null,
            'visit_frequency_unidade' => null,
        ]);

        $painel = $this->pendencias->listar();

        $this->assertCount(1, $painel);
        $this->assertSame($contrato->id, $painel[0]['contrato_id']);
        $this->assertCount(1, $painel[0]['motivos']);
        $this->assertStringContainsString('Periodicidade não preenchida', $painel[0]['motivos'][0]);
        $this->assertSame('25/07/2026', $painel[0]['start_date'], 'a data do painel vai formatada em pt-BR pelo backend');
    }

    public function test_contrato_periodico_sem_visita_no_periodo_aparece_no_painel_de_pendencias(): void
    {
        $contrato = $this->criarContrato([
            'start_date' => '2026-01-25',
            'end_date' => '2026-12-25',
        ]);

        $painel = $this->pendencias->listar();

        $this->assertCount(1, $painel);
        $this->assertSame($contrato->id, $painel[0]['contrato_id']);
        $this->assertCount(1, $painel[0]['motivos']);

        // Corte de 30 dias (config('contratos.dias_de_alerta_sem_visita')):
        // de 25/01 a 25/05 são 5 datas vencidas há mais de 30 dias sem OS.
        $this->assertStringContainsString(
            'Sem visita gerada no período configurado: 5 data(s) prevista(s)',
            $painel[0]['motivos'][0]
        );
    }

    public function test_contrato_com_visita_vencida_nao_executada_aparece_no_painel(): void
    {
        $contrato = $this->criarContrato([
            'start_date' => '2026-06-25',
            'end_date' => '2026-12-25',
        ]);

        $this->criarOs($contrato, '2026-06-25', 'scheduled', 1);

        $painel = $this->pendencias->listar();

        $this->assertCount(1, $painel);
        $this->assertCount(1, $painel[0]['motivos']);
        $this->assertStringContainsString(
            'Visita vencida não executada: 1 Ordem(ns) de Serviço',
            $painel[0]['motivos'][0]
        );
    }

    public function test_contrato_em_dia_e_contrato_pontual_nao_aparecem_no_painel(): void
    {
        $emDia = $this->criarContrato(['end_date' => '2026-12-25']);
        $this->geracao->gerarPara($emDia, self::ATE_TRES_MESES);

        $this->criarContrato(['service_type' => 'pontual']);

        $encerrado = $this->criarContrato(['end_date' => '2026-07-24']);

        $painel = $this->pendencias->listar();

        $this->assertSame([], $painel);
        $this->assertNotContains(
            $encerrado->id,
            array_column($painel, 'contrato_id'),
            'contrato com vigência encerrada ontem não é mais pendência de conformidade'
        );
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Contrato periódico mensal começando hoje, salvo com o endereço dele.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function criarContrato(array $atributos = [], ?int $addressId = null): Contract
    {
        $addressId ??= AddressFactory::new()->create()->id;

        return Contract::query()->create(array_merge([
            'address_id' => $addressId,
            'contract_number' => 'CONT-'.str_pad((string) $addressId, 6, '0', STR_PAD_LEFT),
            'service_type' => 'periodico',
            'visit_frequency' => 'mensal',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'meses',
            'visit_count' => 12,
            'start_date' => self::HOJE,
            'end_date' => null,
        ], $atributos));
    }

    /**
     * OS gravada direto, sem passar pela geração. Serve para montar cenário
     * que a geração nunca produz (visita no passado) e para a OS criada à mão
     * (`$origem` nulo).
     */
    private function criarOs(
        Contract $contrato,
        string $data,
        string $status = 'scheduled',
        ?int $numero = null,
        ?string $origem = 'contrato'
    ): WorkOrder {
        $contrato->loadMissing('address');

        return WorkOrderFactory::new()->create([
            'client_id' => $contrato->address->client_id,
            'address_id' => $contrato->address_id,
            'contract_id' => $contrato->id,
            'origem' => $origem,
            'visita_numero' => $numero,
            'scheduled_date' => $data,
            'status' => $status,
        ]);
    }

    /**
     * Datas das visitas geradas pelo contrato, em ordem.
     *
     * @return array<int, string>
     */
    private function datasGeradas(Contract $contrato): array
    {
        return $this->geracao->visitasDe($contrato)
            ->map(fn (WorkOrder $visita) => BusinessDate::diaDe($visita->scheduled_date))
            ->values()
            ->all();
    }

    /**
     * `visita_numero` de cada visita gerada pelo contrato, indexado pela data
     * e em ordem cronológica. É a forma de conferir de uma vez que nenhum
     * número saiu repetido em dois documentos.
     *
     * @return array<string, int>
     */
    private function numerosPorData(Contract $contrato): array
    {
        return $this->geracao->visitasDe($contrato)
            ->mapWithKeys(fn (WorkOrder $visita) => [
                BusinessDate::diaDe($visita->scheduled_date) => (int) $visita->visita_numero,
            ])
            ->all();
    }

    private function osNaData(Contract $contrato, string $data): WorkOrder
    {
        $os = WorkOrder::query()
            ->where('contract_id', $contrato->id)
            ->where('scheduled_date', $data)
            ->first();

        $this->assertNotNull($os, "não existe OS do contrato na data {$data}");

        return $os;
    }

    /**
     * Linha crua da OS, como está no banco. Comparar o array inteiro antes e
     * depois é o que prova que a operação não encostou no registro, em vez de
     * conferir só o campo que o teste lembrou de olhar.
     *
     * @return array<string, mixed>
     */
    private function linhaCrua(WorkOrder $visita): array
    {
        return WorkOrder::query()->findOrFail($visita->id)->getAttributes();
    }

    /**
     * Lê a contagem de visitas do relatório impresso pelo comando.
     */
    private function contagemDaSaida(string $saida, string $rotulo): int
    {
        $encontrou = preg_match('/Visitas '.preg_quote($rotulo, '/').': (\d+)/', $saida, $partes);

        $this->assertSame(1, $encontrou, "a saída do comando não trouxe a linha \"Visitas {$rotulo}\"");

        return (int) $partes[1];
    }
}
