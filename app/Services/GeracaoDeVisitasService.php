<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractVisitJustification;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Materializa as datas previstas de visita (calculadas por
 * CalendarioDeVisitasService) em Ordens de Serviço agendadas.
 *
 * Idempotência é a regra central deste Service: rodar a geração duas vezes
 * para o mesmo contrato não duplica visita. A verificação é pelo par
 * [contract_id, scheduled_date], nunca por `visita_numero`, porque a
 * reprogramação da Task 9.5 pode renumerar as visitas futuras mantendo as
 * datas já geradas.
 *
 * A distinção de `origem` importa aqui nos dois sentidos: toda OS criada por
 * este Service nasce com `origem = 'contrato'`, e nenhuma OS com `origem`
 * nula (criada à mão por um usuário) é lida, alterada ou contada por ele.
 *
 * Este Service também é a fonte única do `visita_numero` de todo o Plano 9,
 * em `calendarioNumerado`: geração, reprogramação, painel de pendências e
 * tela do contrato leem a numeração dali, e é o que impede dois documentos do
 * mesmo contrato de sairem com o mesmo número.
 */
class GeracaoDeVisitasService
{
    /**
     * Marca gravada em `origem` para toda OS criada por este Service. Sem
     * essa marca, o cancelamento em cascata da Task 9.5 não teria como
     * distinguir visita gerada de OS criada à mão.
     */
    private const ORIGEM_CONTRATO = 'contrato';

    /**
     * Visita gerada já nasce com data definida pelo contrato, então o status
     * inicial é 'scheduled' (agendada), nunca 'pending': 'pending' é para OS
     * sem data ainda decidida.
     */
    private const STATUS_INICIAL = 'scheduled';

    /**
     * Visita executada ou em execução: histórico intocável, e o único
     * conjunto cujo `visita_numero` já foi impresso em documento entregue ao
     * cliente. Mesmo conjunto de `ManutencaoDeVisitasService::STATUS_EXECUTADOS`
     * (privado lá, por isso repetido aqui).
     */
    private const STATUS_EXECUTADOS = ['completed', 'in_progress'];

    /**
     * Visita cancelada. Ocupa a data na agenda, mas não cobre a visita
     * devida: ver `datasComOs`.
     */
    private const STATUS_CANCELADA = 'cancelled';

    /**
     * Janela padrão de `gerarDoPeriodo`, decidida no Plano 9: gerar o
     * contrato inteiro de uma vez enche a agenda de OS que ainda vão mudar de
     * data (a periodicidade pode ser alterada, e a Task 9.5 reprograma só o
     * que ainda não foi executado); janela curta demais impede a equipe de
     * enxergar o mês seguinte.
     */
    private const MESES_JANELA_PADRAO = 3;

    public function __construct(
        private readonly CalendarioDeVisitasService $calendario,
        private readonly WorkOrderService $workOrderService,
    ) {
    }

    /**
     * Gera as OS agendadas do contrato para cada data prevista até `$ate`
     * (ou até `end_date` do contrato / limite de segurança do calendário,
     * quando `$ate` não é informado), nunca com data anterior a `$apartirDe`.
     *
     * `$apartirDe` tem padrão seguro: quando não informado, o corte é hoje
     * (fuso do negócio). Visita gerada é OS agendada de verdade, que aparece
     * na agenda do cliente e vira documento; gerar retroativo criaria OS
     * "nascida vencida" para visita que nunca foi devida, e um contrato
     * antigo com anos de vigência inundaria a agenda na primeira execução da
     * rotina. Data prevista anterior ao corte que não tem OS correspondente é
     * pendência de conformidade, não OS a criar agora — ver
     * `pendenciasDeConformidade`.
     *
     * O parâmetro existe (em vez de "hoje" fixo no corpo do método) porque
     * quem chama pode precisar de um corte diferente de forma deliberada e
     * explícita; o valor padrão nulo é o que mantém todo caminho existente
     * seguro sem precisar ser alterado.
     *
     * Data prevista que já tem OS gravada para o mesmo `contract_id` é
     * pulada, nunca duplicada.
     *
     * Tudo roda em uma única transação: se a criação de uma visita falhar no
     * meio do laço, nenhuma OS desta chamada fica gravada. Contrato com
     * metade das visitas criadas é pior que contrato sem nenhuma, porque
     * esconde a pendência em vez de apontá-la.
     *
     * Contrato pontual, sem periodicidade configurada ou com unidade não
     * reconhecida não gera nada: `CalendarioDeVisitasService::datasPrevistas`
     * devolve array vazio para esses casos, sem lançar exceção, e este
     * método não abre transação à toa quando não há nada para gerar.
     *
     * @param  string|null  $ate  Ver `CalendarioDeVisitasService::datasPrevistas`.
     * @param  string|null  $apartirDe  Data mínima (formato 'Y-m-d') a partir
     *                                  da qual uma visita prevista pode virar
     *                                  OS. Nulo usa hoje no fuso do negócio.
     * @return array<int, WorkOrder> Somente as OS criadas nesta chamada. As
     *                               datas que já tinham OS gravada, e as
     *                               anteriores a `$apartirDe`, não entram no
     *                               retorno.
     */
    public function gerarPara(Contract $contrato, ?string $ate = null, ?string $apartirDe = null): array
    {
        $contrato->loadMissing('address');

        $corte = $this->resolverCorte($apartirDe);

        $datasAGerar = $this->datasPrevistasApartirDe($contrato, $ate, $corte);

        if (empty($datasAGerar)) {
            return [];
        }

        return DB::transaction(function () use ($contrato, $datasAGerar): array {
            $criadas = [];

            foreach ($datasAGerar as $visita) {
                if ($this->visitaJaExiste($contrato, $visita['data'])) {
                    continue;
                }

                $criadas[] = $this->criarOs($contrato, $visita['numero'], $visita['data']);
            }

            return $criadas;
        });
    }

    /**
     * Datas previstas do contrato, anteriores a `$apartirDe` (ou a hoje,
     * quando não informado), que ainda não têm nenhuma OS **em pé** gravada
     * para o mesmo `contract_id` **e** que ninguém justificou. É a pendência
     * de conformidade que o painel da Task 9.7 mostra: visita que já deveria
     * estar agendada e não está, porque `gerarPara` nunca cria OS com data
     * retroativa.
     *
     * Conta qualquer OS do contrato na data, inclusive criada à mão: se já
     * existe alguma OS naquela data, a visita não está faltando, mesmo que a
     * geração automática nunca tenha passado por ali. A única exceção é a OS
     * cancelada, que não cobre visita nenhuma — ver `datasComOs`. Por isso o
     * critério aqui é mais estreito que o de `visitaJaExiste`, e é de
     * propósito.
     *
     * Por que a data justificada sai daqui, e não do painel
     * ----------------------------------------------------
     * Este método é a fonte oficial de "data prevista no passado sem OS
     * correspondente": `PendenciasDeContratoService` o consome de propósito
     * em vez de recalcular. Filtrar a data justificada só no painel deixaria
     * esta fonte continuando a afirmar uma pendência que o negócio já
     * resolveu, e o próximo consumidor (relatório, exportação, outro painel)
     * herdaria o ruído de volta. A justificativa é a resolução da lacuna, com
     * motivo, autor e data gravados; ela pertence ao mesmo lugar que decide o
     * que é lacuna. Remover a justificativa devolve a data a esta lista, e
     * daí ao painel, sem nenhuma outra mudança.
     *
     * Método somente leitura: nunca cria, move nem cancela OS, e nunca grava
     * nem apaga justificativa.
     *
     * @param  string|null  $apartirDe  Ver `gerarPara`.
     * @return array<int, array{numero: int, data: string}>
     */
    public function pendenciasDeConformidade(Contract $contrato, ?string $apartirDe = null): array
    {
        $corte = $this->resolverCorte($apartirDe);

        $datasComOs = $this->datasComOs($contrato);
        $justificadas = $this->justificativasPorData($contrato);

        return array_values(array_filter(
            $this->calendarioNumerado($contrato, $corte->toDateString()),
            fn (array $visita) => BusinessDate::paraFusoNegocio($visita['data'])->lessThan($corte)
                && ! isset($datasComOs[$visita['data']])
                && ! isset($justificadas[$visita['data']])
        ));
    }

    /**
     * Percorre os contratos periódicos vigentes e gera, para cada um, as
     * visitas previstas dentro de uma janela de `$mesesAFrente` a partir de
     * hoje (fuso do negócio). É o ponto de entrada usado pela rotina
     * agendada da Task 9.6.
     *
     * "Vigente" aqui é contrato com periodicidade configurada (backfill da
     * Task 9.2) cuja vigência ainda não terminou (`end_date` nulo ou maior
     * ou igual a hoje). Contrato pontual não passa neste filtro porque não
     * tem `visit_frequency_valor`/`visit_frequency_unidade` preenchidos; o
     * filtro fino de periodicidade reconhecida continua sendo
     * responsabilidade exclusiva de `CalendarioDeVisitasService`, para não
     * duplicar essa regra aqui.
     *
     * Erro em um contrato é isolado e registrado em log: não impede a
     * geração dos demais contratos do laço.
     *
     * Chama `gerarPara` sem `$apartirDe`, então o corte é o padrão seguro
     * (hoje, fuso do negócio): a rotina agendada nunca cria OS com data
     * anterior a hoje, mesmo para contrato vigente há anos.
     *
     * @return array<int, array{criadas: array<int, WorkOrder>, erro: ?string}>
     *         Indexado pelo id do contrato.
     */
    public function gerarDoPeriodo(int $mesesAFrente = self::MESES_JANELA_PADRAO): array
    {
        $ate = BusinessDate::hoje()->addMonthsNoOverflow($mesesAFrente)->toDateString();

        $contratosVigentes = Contract::query()
            ->whereNotNull('visit_frequency_valor')
            ->whereNotNull('visit_frequency_unidade')
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', BusinessDate::hoje()->toDateString());
            })
            ->get();

        $resultado = [];

        foreach ($contratosVigentes as $contrato) {
            try {
                $resultado[$contrato->id] = [
                    'criadas' => $this->gerarPara($contrato, $ate),
                    'erro' => null,
                ];
            } catch (Throwable $erro) {
                Log::error('Falha ao gerar visitas do contrato', [
                    'contract_id' => $contrato->id,
                    'contract_number' => $contrato->contract_number,
                    'erro' => $erro->getMessage(),
                ]);

                $resultado[$contrato->id] = [
                    'criadas' => [],
                    'erro' => $erro->getMessage(),
                ];
            }
        }

        return $resultado;
    }

    /**
     * As OS geradas por este Service para o contrato, ordenadas por data. Só
     * traz OS com `origem = 'contrato'`: OS criada à mão para o mesmo
     * endereço, se existir, não entra aqui.
     */
    public function visitasDe(Contract $contrato): Collection
    {
        return WorkOrder::query()
            ->where('contract_id', $contrato->id)
            ->where('origem', self::ORIGEM_CONTRATO)
            ->orderBy('scheduled_date')
            ->get();
    }

    /**
     * Calendário previsto do contrato com a numeração já reconciliada com as
     * visitas que não podem ser renumeradas. Fonte única do `visita_numero`
     * de todo o Plano 9: geração, reprogramação, painel de pendências e tela
     * do contrato passam por aqui, e é isso que impede dois documentos do
     * mesmo contrato de sairem com o mesmo número.
     *
     * O problema que este método resolve: a numeração de
     * `CalendarioDeVisitasService` recomeça em 1 no `start_date` toda vez que
     * o calendário é recalculado, enquanto visita executada (ou em execução)
     * nunca é renumerada, porque já virou documento entregue ao cliente.
     * Trocada a periodicidade, o número que ficou preso na visita executada
     * reaparecia em uma visita futura, e o contrato terminava com dois
     * documentos "Visita 2 de 12".
     *
     * A regra é pular os números já consumidos, nesta ordem:
     *
     * 1. Data prevista que já tem visita executada fica com o número dessa
     *    visita, que é o que está impresso no documento.
     * 2. Toda outra data recebe o próximo número livre, contando de 1 e
     *    saltando os números presos em visitas executadas.
     *
     * Buraco na sequência é resultado aceito de propósito: ele reflete que o
     * calendário mudou no meio da vigência. Número repetido em dois
     * documentos, não.
     *
     * A numeração é estável em relação a `$ate`: `datasPrevistas` sempre
     * começa em `start_date`, então truncar o fim da lista não desloca o
     * número de nenhuma data que continua nela.
     *
     * @param  string|null  $ate  Ver `CalendarioDeVisitasService::datasPrevistas`.
     * @return array<int, array{numero: int, data: string}>
     */
    public function calendarioNumerado(Contract $contrato, ?string $ate = null): array
    {
        $previstas = $this->calendario->datasPrevistas($contrato, $ate);

        if ($previstas === []) {
            return [];
        }

        $executadasPorDia = $this->numerosDeVisitasExecutadas($contrato);

        if ($executadasPorDia === []) {
            return $previstas;
        }

        // Números presos em visita executada, inclusive os de visitas que não
        // estão em nenhuma data do calendário novo: eles seguem impressos em
        // um documento e não podem ser reemitidos para outra visita.
        $consumidos = array_flip(array_values($executadasPorDia));

        $proximo = 1;
        $numerado = [];

        foreach ($previstas as $visita) {
            if (isset($executadasPorDia[$visita['data']])) {
                $numerado[] = [
                    'numero' => $executadasPorDia[$visita['data']],
                    'data' => $visita['data'],
                ];

                continue;
            }

            while (isset($consumidos[$proximo])) {
                $proximo++;
            }

            $numerado[] = ['numero' => $proximo, 'data' => $visita['data']];
            $proximo++;
        }

        return $numerado;
    }

    /**
     * Junta o calendário previsto do contrato com as OS já materializadas,
     * uma linha por data prevista, para a tela do contrato (Task 9.7/9.8)
     * mostrar número, data, situação e a OS vinculada sem o consumidor
     * precisar cruzar as duas fontes sozinho. Método somente leitura: nunca
     * cria, move nem cancela OS.
     *
     * Situação de cada linha:
     * - 'executada' / 'em_execucao' / 'cancelada': status da OS, sem julgar
     *   a data.
     * - 'atrasada': OS ainda aberta ('scheduled' ou 'pending') com data no
     *   passado.
     * - 'agendada': OS ainda aberta com data futura ou hoje.
     * - 'pendente': data prevista no passado sem nenhuma OS gerada e sem
     *   justificativa. Mesma pendência de `pendenciasDeConformidade`, aqui em
     *   formato de linha para exibição.
     * - 'justificada': data prevista no passado sem OS gerada, com motivo
     *   registrado. A lacuna continua na lista, e é o ponto: quem abre o
     *   contrato meses depois precisa ver a visita que não aconteceu e por
     *   quê. O que ela deixa de ser é pendência aberta no painel.
     * - 'prevista': data prevista futura sem OS gerada ainda.
     *
     * A chave `justificativa` vem preenchida em toda linha cuja data tem
     * motivo registrado, inclusive quando a situação é outra (data com OS
     * cancelada, por exemplo, que o painel também acusa). É o que permite à
     * tela mostrar o motivo e oferecer a remoção sem uma segunda consulta.
     *
     * Datas devolvidas já formatadas em pt-BR no fuso do negócio.
     *
     * @return array<int, array{numero: int, data: string, situacao: string, ordem_servico: array{id: int, order_number: ?string, status: string}|null, justificativa: array{id: int, motivo: string, autor: ?string, registrada_em: ?string}|null}>
     */
    public function visitasComSituacao(Contract $contrato): array
    {
        $hoje = BusinessDate::hoje();
        $geradasPorDia = $this->visitasDe($contrato)
            ->keyBy(fn (WorkOrder $visita) => BusinessDate::diaDe($visita->scheduled_date));
        $justificadas = $this->justificativasPorData($contrato);

        return array_map(
            function (array $visita) use ($geradasPorDia, $justificadas, $hoje) {
                $os = $geradasPorDia->get($visita['data']);
                $justificativa = $justificadas[$visita['data']] ?? null;

                return [
                    'numero' => $visita['numero'],
                    'data' => BusinessDate::paraFusoNegocio($visita['data'])->format('d/m/Y'),
                    'situacao' => $this->situacaoDaLinha($visita['data'], $os, $hoje, $justificativa !== null),
                    'ordem_servico' => $os === null ? null : [
                        'id' => $os->id,
                        'order_number' => $os->order_number,
                        'status' => $os->status,
                    ],
                    'justificativa' => $justificativa === null ? null : $this->formatarJustificativa($justificativa),
                ];
            },
            $this->calendarioNumerado($contrato)
        );
    }

    /**
     * Justificativas do contrato indexadas pelo dia ('Y-m-d'), para checagem
     * em O(1) sem uma consulta por data.
     *
     * Consulta pelo model, e não pelo `JustificativaDeVisitaService`, de
     * propósito: aquele Service depende deste para saber quais datas podem ser
     * justificadas, e injetá-lo aqui fecharia um ciclo de dependência.
     *
     * @return array<string, ContractVisitJustification>
     */
    private function justificativasPorData(Contract $contrato): array
    {
        $indexadas = [];

        $justificativas = ContractVisitJustification::query()
            ->where('contract_id', $contrato->id)
            ->orderBy('expected_date')
            ->get();

        foreach ($justificativas as $justificativa) {
            $dia = BusinessDate::diaDe($justificativa->expected_date);

            if ($dia === null) {
                continue;
            }

            $indexadas[$dia] = $justificativa;
        }

        return $indexadas;
    }

    /**
     * Justificativa formatada para exibição, com o instante já no fuso do
     * negócio. Data em tela é sempre formatada no backend.
     *
     * @return array{id: int, motivo: string, autor: ?string, registrada_em: ?string}
     */
    private function formatarJustificativa(ContractVisitJustification $justificativa): array
    {
        return [
            'id' => $justificativa->id,
            'motivo' => (string) $justificativa->reason,
            'autor' => $justificativa->justified_by_name,
            'registrada_em' => BusinessDate::paraFusoNegocio($justificativa->created_at)?->format('d/m/Y H:i'),
        ];
    }

    /**
     * `visita_numero` das visitas executadas ou em execução do contrato,
     * indexado pela data ('Y-m-d'). São os números que a renumeração precisa
     * saltar: a visita já foi entregue com esse número impresso.
     *
     * Só OS gerada pelo contrato (`origem = 'contrato'`) com número gravado
     * entra: OS criada à mão não carrega número de visita e não participa da
     * sequência.
     *
     * @return array<string, int>
     */
    private function numerosDeVisitasExecutadas(Contract $contrato): array
    {
        $executadas = WorkOrder::query()
            ->where('contract_id', $contrato->id)
            ->where('origem', self::ORIGEM_CONTRATO)
            ->whereIn('status', self::STATUS_EXECUTADOS)
            ->whereNotNull('visita_numero')
            ->orderBy('scheduled_date')
            ->get(['scheduled_date', 'visita_numero']);

        $numeros = [];

        foreach ($executadas as $visita) {
            $dia = BusinessDate::diaDe($visita->scheduled_date);

            if ($dia === null) {
                continue;
            }

            $numeros[$dia] = (int) $visita->visita_numero;
        }

        return $numeros;
    }

    /**
     * Situação textual de uma linha de `visitasComSituacao`. Ver a
     * enumeração completa no docblock do método chamador.
     */
    private function situacaoDaLinha(string $data, ?WorkOrder $os, CarbonImmutable $hoje, bool $justificada = false): string
    {
        $vencida = BusinessDate::paraFusoNegocio($data)->lessThan($hoje);

        if ($os === null) {
            if (! $vencida) {
                return 'prevista';
            }

            return $justificada ? 'justificada' : 'pendente';
        }

        return match ($os->status) {
            'completed' => 'executada',
            'in_progress' => 'em_execucao',
            'cancelled' => 'cancelada',
            default => $vencida ? 'atrasada' : 'agendada',
        };
    }

    /**
     * Verdadeiro quando já existe OS gravada para este contrato na data
     * informada. É a checagem que garante a idempotência: chave
     * [contract_id, scheduled_date], nunca `visita_numero`.
     *
     * Conta OS cancelada, e isso é decisão, não esquecimento. Se a data
     * cancelada voltasse a ser considerada livre, a rotina diária recriaria
     * toda madrugada a visita que alguém cancelou pela tela, e o usuário não
     * teria como fazer o cancelamento pegar. O buraco de conformidade que a
     * OS cancelada deixa é acusado no painel, por `datasComOs`, e a decisão
     * de reagendar fica com a pessoa.
     */
    private function visitaJaExiste(Contract $contrato, string $dataAgendada): bool
    {
        return WorkOrder::query()
            ->where('contract_id', $contrato->id)
            ->where('scheduled_date', $dataAgendada)
            ->exists();
    }

    /**
     * Resolve o corte informado para o fuso do negócio, ou hoje quando nulo.
     * Único lugar que decide o valor padrão de `$apartirDe`, para
     * `gerarPara` e `pendenciasDeConformidade` nunca divergirem sobre o que
     * é "hoje".
     */
    private function resolverCorte(?string $apartirDe): CarbonImmutable
    {
        return $apartirDe !== null
            ? BusinessDate::paraFusoNegocio($apartirDe)
            : BusinessDate::hoje();
    }

    /**
     * Datas previstas do contrato (calendário completo, com a numeração
     * reconciliada por `calendarioNumerado`) que não são anteriores a
     * `$corte`. É o filtro que impede `gerarPara` de criar OS retroativa: o
     * cálculo do calendário continua completo desde `start_date`, só a
     * geração é que descarta o que já passou.
     *
     * @return array<int, array{numero: int, data: string}>
     */
    private function datasPrevistasApartirDe(Contract $contrato, ?string $ate, CarbonImmutable $corte): array
    {
        return array_values(array_filter(
            $this->calendarioNumerado($contrato, $ate),
            fn (array $visita) => ! BusinessDate::paraFusoNegocio($visita['data'])->lessThan($corte)
        ));
    }

    /**
     * Datas ('Y-m-d') que já têm alguma OS **não cancelada** gravada para o
     * contrato, qualquer que seja a origem, indexadas para checagem em O(1).
     * Usada por `pendenciasDeConformidade`, que precisa checar muitas datas
     * de uma vez sem uma consulta por data.
     *
     * A OS cancelada fica de fora de propósito, e é o ponto todo do método.
     * Visita cancelada não foi executada e não vai ser: a data continua sem
     * cobertura, e contrato periódico sem visita no período é pendência de
     * conformidade com a RDC 622/2022. Contá-la como "data coberta" é o que
     * fazia o painel calar sobre visita que nunca vai acontecer.
     *
     * Este critério é deliberadamente mais estreito que o de
     * `visitaJaExiste`, que continua contando a cancelada. Lá, ignorar o
     * cancelamento faria a rotina diária recriar toda madrugada a OS que
     * alguém cancelou pela tela, sem o usuário ter como fazer o cancelamento
     * valer. Acusar no painel e deixar a decisão com a pessoa é melhor que
     * ressuscitar OS automaticamente.
     *
     * @return array<string, true>
     */
    private function datasComOs(Contract $contrato): array
    {
        return WorkOrder::query()
            ->where('contract_id', $contrato->id)
            ->where('status', '!=', self::STATUS_CANCELADA)
            ->pluck('scheduled_date')
            ->map(fn ($data) => BusinessDate::diaDe($data))
            ->filter()
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /**
     * Cria a OS de uma visita, delegando a `WorkOrderService` para
     * reaproveitar a geração de `order_number` e não duplicar essa regra.
     *
     * `client_id` vem do endereço do contrato, não do contrato diretamente:
     * `contracts` não guarda cliente, só `address_id`. `technician_id` nasce
     * nulo porque atribuir automaticamente exige conhecer carga e agenda do
     * técnico, o que é o Plano 10.
     */
    private function criarOs(Contract $contrato, int $numero, string $dataAgendada): WorkOrder
    {
        return $this->workOrderService->createWorkOrder([
            'client_id' => $contrato->address->client_id,
            'address_id' => $contrato->address_id,
            'technician_id' => null,
            'service_id' => $contrato->service_id ?? null,
            'contract_id' => $contrato->id,
            'origem' => self::ORIGEM_CONTRATO,
            'visita_numero' => $numero,
            'order_number' => $this->workOrderService->generateOrderNumber(),
            'scheduled_date' => $dataAgendada,
            'status' => self::STATUS_INICIAL,
            'description' => $this->montarDescricao($contrato, $numero),
        ]);
    }

    /**
     * Monta a descrição da visita, no formato "Visita N de TOTAL do contrato
     * CONT-000012". Texto impresso no documento entregue ao cliente, por isso
     * é público: `ManutencaoDeVisitasService` chama este mesmo método ao
     * mover ou renumerar, para o formato ter uma implementação só.
     *
     * `visit_count` é obrigatório na criação do contrato (validado em
     * `ContractController`), mas o texto degrada para "Visita N do contrato
     * CONT-000012", sem total, em dois casos:
     *
     * 1. Contrato legado sem o campo preenchido, para não imprimir "de "
     *    vazio.
     * 2. Número maior que o total contratado. Acontece quando a renumeração
     *    salta os números presos em visita executada (ver
     *    `calendarioNumerado`) e a sequência passa do fim: "Visita 13 de 12"
     *    é documento mentindo. O número segue impresso porque é informação
     *    útil e única; o total, que deixou de valer, sai.
     */
    public function montarDescricao(Contract $contrato, int $numero): string
    {
        $total = (int) $contrato->visit_count;

        $numeroDaVisita = $total > 0 && $numero <= $total
            ? "{$numero} de {$total}"
            : (string) $numero;

        return "Visita {$numeroDaVisita} do contrato {$contrato->contract_number}";
    }
}
