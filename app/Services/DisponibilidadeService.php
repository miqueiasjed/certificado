<?php

namespace App\Services;

use App\Models\Technician;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Conflito de horário do técnico e carga de trabalho no período.
 *
 * Serviço de leitura: não grava nada e não olha para o usuário autenticado. O
 * isolamento por empresa vem do escopo global de `BelongsToCompany` em
 * `WorkOrder` e `Technician`, e por isso nenhuma consulta daqui pode chamar
 * `withoutGlobalScope()` ou `deTodasAsEmpresas()`. Técnico de outra empresa não
 * aparece porque o escopo já cuidou disso, não porque exista filtro manual.
 *
 * Fuso horário
 * ------------
 * Este arquivo mistura de propósito dois tipos de campo, e tratá-los igual é o
 * erro clássico do projeto:
 *
 * - `scheduled_date` é `date`, ou seja, um dia sem hora. Passa por
 *   `BusinessDate`, que reconstrói o dia no fuso do negócio sem converter. É a
 *   única forma de não perder (ou ganhar) um dia.
 * - `start_time` e `end_time` são `datetime`, ou seja, instantes reais gravados
 *   no fuso da aplicação (UTC). Aqui usa-se conversão direta de fuso
 *   (`setTimezone`), **nunca** `BusinessDate::paraFusoNegocio()`: aquele método
 *   trata meia-noite exata como dia puro, e uma OS que começa às 00:00 UTC
 *   (21:00 de Brasília do dia anterior) seria deslocada em três horas.
 *
 * A comparação de sobreposição é feita entre instantes absolutos, o que é
 * correto por construção. O fuso do negócio entra em dois pontos: para ancorar
 * uma hora de parede (`HH:MM`) no dia informado, e para devolver a hora ao
 * usuário já legível.
 *
 * Quem é o técnico da OS
 * ----------------------
 * A atribuição vale pela coluna `work_orders.technician_id` **e** pela pivot
 * `work_order_technicians`, exatamente como `WorkOrderAccessService` já define
 * no Plano 2. O formulário de OS grava a pivot (`technicians[]`), então checar
 * só a coluna deixaria passar o conflito real da maior parte das OS.
 */
class DisponibilidadeService
{
    /**
     * OS cancelada nunca gera conflito nem entra na carga.
     */
    public const STATUS_CANCELADA = 'cancelled';

    /**
     * Motivo de um item bloqueante: as duas OS têm horário e os intervalos se
     * cruzam.
     */
    public const MOTIVO_SOBREPOSICAO = 'sobreposicao';

    /**
     * Motivo de um item apenas informativo: mesmo técnico e mesmo dia, mas ao
     * menos uma das duas OS não tem horário, então não há como afirmar
     * sobreposição.
     */
    public const MOTIVO_SEM_HORARIO = 'sem_horario';

    /**
     * Teto do período aceito por `cargaPorTecnico()`. A carga monta um balde por
     * dia para cada técnico, e sem limite uma chamada distraída com intervalo de
     * dez anos montaria um array gigante em memória.
     */
    private const MAXIMO_DE_DIAS_NA_CARGA = 366;

    /**
     * Conflitos e avisos de um técnico para um horário em um dia.
     *
     * Regra de bloqueio (a regra explícita do Plano 10): há conflito quando as
     * duas OS têm início e fim e os intervalos se cruzam, pela comparação
     * `inicioA < fimB && inicioB < fimA`. A desigualdade é estrita, então
     * intervalo encostado (uma termina 10:00, a outra começa 10:00) não é
     * conflito.
     *
     * Regra de aviso: mesma data e mesmo técnico, com ao menos um dos dois lados
     * sem horário, entra em `avisos` e nunca em `conflitos`. Travar por isso
     * impediria o uso normal de quem não preenche horário.
     *
     * `$data` é o dia no formato `Y-m-d`. `$inicio` e `$fim` aceitam:
     *
     * - `HH:MM` ou `HH:MM:SS`: hora de parede no fuso do negócio, ancorada em
     *   `$data`. É o formato que o calendário usa e o recomendado.
     * - data e hora completas (`Y-m-d H:i:s`, ISO 8601, com ou sem deslocamento):
     *   lidas como instante na mesma convenção do model, isto é, no fuso da
     *   aplicação quando não houver deslocamento explícito, e convertidas para o
     *   fuso do negócio. É o formato que o formulário de OS já envia.
     * - `null` ou string vazia: sem horário.
     *
     * `$ignorarOsId` exclui a própria OS da checagem, para que editar uma OS não
     * a faça conflitar consigo mesma.
     *
     * Formato de retorno (consumido pela Task 10.3 e testado pela Task 10.8):
     *
     * ```
     * [
     *     'tem_conflito'      => bool,     // true bloqueia a operação
     *     'tem_aviso'         => bool,     // true apenas informa
     *     'conflitos'         => array,    // itens bloqueantes
     *     'avisos'            => array,    // itens informativos
     *     'mensagem_conflito' => ?string,  // nomeia as OS que colidem
     *     'mensagem_aviso'    => ?string,
     * ]
     * ```
     *
     * Cada item de `conflitos` e `avisos` tem a forma descrita em
     * `descreverOrdem()`, incluindo a chave `motivo`, que repete a distinção
     * dentro do próprio item.
     *
     * @return array{tem_conflito: bool, tem_aviso: bool, conflitos: array<int, array<string, mixed>>, avisos: array<int, array<string, mixed>>, mensagem_conflito: ?string, mensagem_aviso: ?string}
     */
    public function conflitos(
        int $technicianId,
        string $data,
        ?string $inicio,
        ?string $fim,
        ?int $ignorarOsId = null
    ): array {
        $dia = $this->diaDoNegocio($data);
        $intervalo = $this->intervalo($dia, $inicio, $fim);

        $ordens = $this->ordensDoDia($dia)
            ->when($ignorarOsId !== null, fn (Builder $consulta) => $consulta->whereKeyNot($ignorarOsId))
            ->where(function (Builder $consulta) use ($technicianId): void {
                $consulta
                    ->where('work_orders.technician_id', $technicianId)
                    ->orWhereHas('technicians', fn (Builder $pivot) => $pivot->where('technicians.id', $technicianId));
            })
            ->get();

        return $this->classificar($ordens, $intervalo);
    }

    /**
     * Conflitos e avisos de vários técnicos de uma vez, no mesmo formato de
     * `conflitos()`.
     *
     * Existe porque a atribuição de uma ordem de serviço vale pela coluna
     * `work_orders.technician_id` **e** pela pivot `work_order_technicians`, e
     * o formulário de ordem de serviço grava a pivot. Checar só um técnico ao
     * reagendar deixaria passar o conflito da maior parte das ordens, que é
     * justamente o que este serviço existe para impedir.
     *
     * A mesma ordem de serviço encontrada por dois técnicos aparece uma vez só:
     * a classificação entre conflito e aviso depende do intervalo pedido e do
     * intervalo dela, nunca de quem é o técnico.
     *
     * Lista vazia devolve o resultado neutro, sem nenhuma consulta.
     *
     * @param  array<int, int|string|null>  $technicianIds
     * @return array{tem_conflito: bool, tem_aviso: bool, conflitos: array<int, array<string, mixed>>, avisos: array<int, array<string, mixed>>, mensagem_conflito: ?string, mensagem_aviso: ?string}
     */
    public function conflitosDeTecnicos(
        array $technicianIds,
        string $data,
        ?string $inicio,
        ?string $fim,
        ?int $ignorarOsId = null
    ): array {
        $conflitos = [];
        $avisos = [];

        foreach ($this->idsUnicos($technicianIds) as $technicianId) {
            $resultado = $this->conflitos($technicianId, $data, $inicio, $fim, $ignorarOsId);

            foreach ($resultado['conflitos'] as $item) {
                $conflitos[$item['id']] = $item;
            }

            foreach ($resultado['avisos'] as $item) {
                $avisos[$item['id']] = $item;
            }
        }

        $conflitos = array_values($conflitos);
        $avisos = array_values($avisos);

        return [
            'tem_conflito' => $conflitos !== [],
            'tem_aviso' => $avisos !== [],
            'conflitos' => $conflitos,
            'avisos' => $avisos,
            'mensagem_conflito' => $this->mensagemDeConflito($conflitos),
            'mensagem_aviso' => $this->mensagemDeAviso($avisos),
        ];
    }

    /**
     * Ids dos técnicos atribuídos a uma ordem de serviço, pela coluna direta e
     * pela pivot, sem repetição. Ordem sem técnico nenhum devolve lista vazia.
     *
     * @return array<int, int>
     */
    public function tecnicosAtribuidos(WorkOrder $ordem): array
    {
        $ordem->loadMissing('technicians:id');

        return $this->tecnicosDaOrdem($ordem);
    }

    /**
     * Resultado neutro, para quem precisa responder no mesmo formato sem ter o
     * que checar (ordem sem data agendada, atribuição que só desvincula).
     *
     * @return array{tem_conflito: bool, tem_aviso: bool, conflitos: array<int, array<string, mixed>>, avisos: array<int, array<string, mixed>>, mensagem_conflito: ?string, mensagem_aviso: ?string}
     */
    public function semConflito(): array
    {
        return [
            'tem_conflito' => false,
            'tem_aviso' => false,
            'conflitos' => [],
            'avisos' => [],
            'mensagem_conflito' => null,
            'mensagem_aviso' => null,
        ];
    }

    /**
     * Carga de trabalho por técnico entre dois dias, inclusive nas pontas.
     *
     * `$inicio` e `$fim` são dias no formato `Y-m-d`. Só entram OS não
     * canceladas com `scheduled_date` dentro do intervalo.
     *
     * Entram na lista todos os técnicos ativos da empresa, mesmo com zero OS
     * (técnico livre é informação de carga), e também o técnico inativo que
     * ainda tenha OS no período, para que a soma não esconda trabalho agendado.
     * A linha "sem técnico" vem sempre, mesmo zerada, para o consumidor ter uma
     * forma estável.
     *
     * OS com mais de um técnico conta para cada um deles, porque os dois gastam
     * as horas. Por isso `totais` é apurado sobre as OS distintas e pode ser
     * menor que a soma das linhas.
     *
     * Formato de retorno:
     *
     * ```
     * [
     *     'periodo'  => ['inicio' => 'Y-m-d', 'fim' => 'Y-m-d', 'dias' => int],
     *     'tecnicos' => [
     *         [
     *             'technician_id'    => ?int,   // null na linha "sem técnico"
     *             'nome'             => string,
     *             'sem_tecnico'      => bool,
     *             'ativo'            => bool,
     *             'total_os'         => int,
     *             'total_horas'      => float,  // só de OS com início e fim
     *             'os_sem_horario'   => int,
     *             'por_dia'          => ['Y-m-d' => ['total_os' => int, 'horas' => float], ...],
     *             'dias_com_os'      => int,
     *             'media_os_por_dia' => float,  // sobre todos os dias do período
     *             'pico_no_dia'      => int,
     *         ],
     *         ...
     *     ],
     *     'totais'   => ['total_os' => int, 'total_horas' => float, 'os_sem_horario' => int],
     * ]
     * ```
     *
     * `por_dia` traz todos os dias do período, inclusive os zerados: é a
     * distribuição pronta para virar barra no calendário, sem o frontend
     * precisar preencher buraco.
     *
     * @return array{periodo: array{inicio: string, fim: string, dias: int}, tecnicos: array<int, array<string, mixed>>, totais: array{total_os: int, total_horas: float, os_sem_horario: int}}
     */
    public function cargaPorTecnico(string $inicio, string $fim): array
    {
        $de = $this->diaDoNegocio($inicio);
        $ate = $this->diaDoNegocio($fim);

        if ($ate->lessThan($de)) {
            throw new InvalidArgumentException('O fim do período não pode ser anterior ao início.');
        }

        $dias = $this->diasDoPeriodo($de, $ate);

        $ordens = $this->naoCanceladas()
            ->whereBetween('scheduled_date', [$de->toDateString(), $ate->toDateString()])
            ->with(['technicians:id,name'])
            ->get();

        $porTecnico = $this->agruparPorTecnico($ordens);
        $linhas = [];

        foreach ($this->tecnicosDaEmpresa() as $tecnico) {
            $id = (int) $tecnico->id;
            $ordensDoTecnico = $porTecnico->get($id, collect());

            // Técnico inativo só aparece quando tem trabalho agendado no
            // período: some da lista quando não tem, mas nunca esconde carga.
            if (! $tecnico->is_active && $ordensDoTecnico->isEmpty()) {
                continue;
            }

            $linhas[] = $this->montarLinhaDeCarga(
                $id,
                $tecnico->name,
                false,
                (bool) $tecnico->is_active,
                $ordensDoTecnico,
                $dias
            );
        }

        // A linha "sem técnico" fecha a lista, sempre, mesmo zerada.
        $linhas[] = $this->montarLinhaDeCarga(
            null,
            'Sem técnico',
            true,
            true,
            $porTecnico->get('sem_tecnico', collect()),
            $dias
        );

        return [
            'periodo' => [
                'inicio' => $de->toDateString(),
                'fim' => $ate->toDateString(),
                'dias' => count($dias),
            ],
            'tecnicos' => $linhas,
            'totais' => [
                'total_os' => $ordens->count(),
                'total_horas' => $this->somarHoras($ordens),
                'os_sem_horario' => $ordens->filter(fn (WorkOrder $ordem) => $this->intervaloDaOrdem($ordem) === null)->count(),
            ],
        ];
    }

    /**
     * Técnicos ativos da empresa que ficam sem conflito no horário informado,
     * para a atribuição direto pelo calendário.
     *
     * Técnico com conflito bloqueante é omitido de propósito: o método responde
     * "quem pode receber esta OS". Aviso não omite ninguém, porque aviso não
     * bloqueia, mas viaja junto para a tela poder mostrar que aquele técnico já
     * tem OS sem horário no mesmo dia.
     *
     * `$data`, `$inicio` e `$fim` seguem os mesmos formatos aceitos por
     * `conflitos()`. Sem horário, nenhum técnico é bloqueado, e todos voltam com
     * o aviso das OS que já têm no dia.
     *
     * Formato de retorno:
     *
     * ```
     * [
     *     [
     *         'id'             => int,
     *         'nome'           => string,
     *         'especialidade'  => ?string,
     *         'tem_aviso'      => bool,
     *         'avisos'         => array,   // mesma forma dos itens de conflitos()
     *         'mensagem_aviso' => ?string,
     *         'os_no_dia'      => int,     // OS não canceladas do técnico no dia
     *         'horas_no_dia'   => float,
     *     ],
     *     ...
     * ]
     * ```
     *
     * @return array<int, array<string, mixed>>
     */
    public function tecnicosDisponiveis(string $data, ?string $inicio, ?string $fim): array
    {
        $dia = $this->diaDoNegocio($data);
        $intervalo = $this->intervalo($dia, $inicio, $fim);

        // Uma consulta só para o dia inteiro: avaliar técnico a técnico geraria
        // uma consulta por linha na tela de atribuição.
        $ordens = $this->ordensDoDia($dia)
            ->with(['technicians:id,name'])
            ->get();

        $porTecnico = $this->agruparPorTecnico($ordens);
        $disponiveis = [];

        foreach ($this->tecnicosDaEmpresa()->where('is_active', true) as $tecnico) {
            $id = (int) $tecnico->id;
            $ordensDoTecnico = $porTecnico->get($id, collect());
            $resultado = $this->classificar($ordensDoTecnico, $intervalo);

            if ($resultado['tem_conflito']) {
                continue;
            }

            $disponiveis[] = [
                'id' => $id,
                'nome' => $tecnico->name,
                'especialidade' => $tecnico->specialty,
                'tem_aviso' => $resultado['tem_aviso'],
                'avisos' => $resultado['avisos'],
                'mensagem_aviso' => $resultado['mensagem_aviso'],
                'os_no_dia' => $ordensDoTecnico->count(),
                'horas_no_dia' => $this->somarHoras($ordensDoTecnico),
            ];
        }

        return $disponiveis;
    }

    /**
     * Separa as OS candidatas entre bloqueantes e informativas.
     *
     * Só vira conflito quando os dois lados têm intervalo completo e há
     * cruzamento. Qualquer outro caso de mesmo técnico no mesmo dia é aviso.
     *
     * @param  Collection<int, WorkOrder>|EloquentCollection<int, WorkOrder>  $ordens
     * @param  array{inicio: CarbonImmutable, fim: CarbonImmutable}|null  $intervalo
     * @return array{tem_conflito: bool, tem_aviso: bool, conflitos: array<int, array<string, mixed>>, avisos: array<int, array<string, mixed>>, mensagem_conflito: ?string, mensagem_aviso: ?string}
     */
    private function classificar(Collection|EloquentCollection $ordens, ?array $intervalo): array
    {
        $conflitos = [];
        $avisos = [];

        foreach ($ordens as $ordem) {
            $intervaloDaOrdem = $this->intervaloDaOrdem($ordem);

            if ($intervalo !== null && $intervaloDaOrdem !== null) {
                if ($this->sobrepoe($intervalo, $intervaloDaOrdem)) {
                    $conflitos[] = $this->descreverOrdem($ordem, self::MOTIVO_SOBREPOSICAO);
                }

                // Dois horários que não se cruzam não geram nem aviso: o técnico
                // está livre naquele intervalo, e avisar seria ruído.
                continue;
            }

            $avisos[] = $this->descreverOrdem($ordem, self::MOTIVO_SEM_HORARIO);
        }

        return [
            'tem_conflito' => $conflitos !== [],
            'tem_aviso' => $avisos !== [],
            'conflitos' => $conflitos,
            'avisos' => $avisos,
            'mensagem_conflito' => $this->mensagemDeConflito($conflitos),
            'mensagem_aviso' => $this->mensagemDeAviso($avisos),
        ];
    }

    /**
     * Dois intervalos colidem quando `inicioA < fimB` e `inicioB < fimA`.
     *
     * A comparação é entre instantes absolutos, então não depende do fuso em que
     * cada ponta está sendo exibida. As duas desigualdades são estritas: com
     * `<=` a OS que termina 10:00 conflitaria com a que começa 10:00, que é
     * justamente o encaixe normal de dois atendimentos seguidos.
     *
     * @param  array{inicio: CarbonImmutable, fim: CarbonImmutable}  $a
     * @param  array{inicio: CarbonImmutable, fim: CarbonImmutable}  $b
     */
    private function sobrepoe(array $a, array $b): bool
    {
        return $a['inicio']->lessThan($b['fim']) && $b['inicio']->lessThan($a['fim']);
    }

    /**
     * Intervalo da OS já convertido para o fuso do negócio, ou null quando a OS
     * não tem horário utilizável.
     *
     * `start_time` e `end_time` são instantes: a conversão é `setTimezone` puro.
     * Usar `BusinessDate::paraFusoNegocio()` aqui deslocaria em três horas a OS
     * gravada em meia-noite exata, porque aquele método trata meia-noite como
     * dia puro.
     *
     * Intervalo invertido ou de duração zero é tratado como sem horário: não dá
     * para afirmar sobreposição a partir de um horário que não fecha, e o
     * caminho seguro é o aviso, não o bloqueio.
     *
     * @return array{inicio: CarbonImmutable, fim: CarbonImmutable}|null
     */
    private function intervaloDaOrdem(WorkOrder $ordem): ?array
    {
        if ($ordem->start_time === null || $ordem->end_time === null) {
            return null;
        }

        $fuso = BusinessDate::fuso();
        $inicio = CarbonImmutable::instance($ordem->start_time)->setTimezone($fuso);
        $fim = CarbonImmutable::instance($ordem->end_time)->setTimezone($fuso);

        if (! $inicio->lessThan($fim)) {
            return null;
        }

        return ['inicio' => $inicio, 'fim' => $fim];
    }

    /**
     * Intervalo informado pelo chamador, ancorado no dia e no fuso do negócio.
     *
     * Devolve null quando falta uma das pontas: sem intervalo fechado não existe
     * sobreposição a provar, e a checagem inteira cai para aviso.
     *
     * @return array{inicio: CarbonImmutable, fim: CarbonImmutable}|null
     */
    private function intervalo(CarbonImmutable $dia, ?string $inicio, ?string $fim): ?array
    {
        $de = $this->momento($dia, $inicio);
        $ate = $this->momento($dia, $fim);

        if ($de === null || $ate === null) {
            return null;
        }

        if (! $de->lessThan($ate)) {
            return null;
        }

        return ['inicio' => $de, 'fim' => $ate];
    }

    /**
     * Converte uma das pontas do horário informado em instante no fuso do
     * negócio.
     *
     * `HH:MM` e `HH:MM:SS` são hora de parede e ficam ancoradas em `$dia`, já no
     * fuso do negócio. Qualquer outro texto é lido como data e hora completas,
     * na convenção do model: sem deslocamento explícito vale o fuso da
     * aplicação (UTC), que é como o campo está gravado e como o formulário de OS
     * envia.
     */
    private function momento(CarbonImmutable $dia, ?string $valor): ?CarbonImmutable
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim($valor);

        if ($texto === '') {
            return null;
        }

        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $texto) === 1) {
            return $dia->setTimeFromTimeString($texto);
        }

        try {
            return CarbonImmutable::parse($texto, $this->fusoDaAplicacao())
                ->setTimezone(BusinessDate::fuso());
        } catch (\Throwable $erro) {
            throw new InvalidArgumentException("Horário não reconhecido: {$valor}", 0, $erro);
        }
    }

    /**
     * Dia informado, reconstruído no fuso do negócio sem conversão.
     *
     * `BusinessDate` existe exatamente para isto: o dia é dia, e converter fuso
     * em campo sem hora é o que produz o erro de um dia.
     */
    private function diaDoNegocio(string $data): CarbonImmutable
    {
        $dia = BusinessDate::paraFusoNegocio(trim($data));

        if ($dia === null) {
            throw new InvalidArgumentException('Data não informada.');
        }

        return $dia->startOfDay();
    }

    /**
     * Consulta base: OS não canceladas, dentro do escopo de empresa do model.
     */
    private function naoCanceladas(): Builder
    {
        return WorkOrder::query()->where('work_orders.status', '!=', self::STATUS_CANCELADA);
    }

    /**
     * OS não canceladas agendadas para o dia informado.
     *
     * A comparação é direta contra a coluna `date`, sem `whereDate()`: a coluna
     * já guarda um dia, e envolver a coluna em função descartaria o índice.
     */
    private function ordensDoDia(CarbonImmutable $dia): Builder
    {
        return $this->naoCanceladas()
            ->where('work_orders.scheduled_date', $dia->toDateString())
            ->with(['client:id,name']);
    }

    /**
     * Técnicos da empresa, ordenados por nome. O escopo global de empresa já
     * filtra: nenhum técnico de outra empresa chega aqui.
     *
     * @return EloquentCollection<int, Technician>
     */
    private function tecnicosDaEmpresa(): EloquentCollection
    {
        return Technician::query()
            ->orderBy('name')
            ->get(['id', 'name', 'specialty', 'is_active']);
    }

    /**
     * Indexa as OS pelo técnico atribuído, considerando a coluna direta e a
     * pivot. A chave `sem_tecnico` junta as OS que não têm nenhum dos dois.
     *
     * Uma OS com dois técnicos aparece nas duas listas: os dois estão ocupados
     * naquele horário.
     *
     * @param  EloquentCollection<int, WorkOrder>  $ordens
     * @return Collection<int|string, Collection<int, WorkOrder>>
     */
    private function agruparPorTecnico(EloquentCollection $ordens): Collection
    {
        $agrupado = collect();

        foreach ($ordens as $ordem) {
            $tecnicos = $this->tecnicosDaOrdem($ordem);
            $chaves = $tecnicos === [] ? ['sem_tecnico'] : $tecnicos;

            foreach ($chaves as $chave) {
                if (! $agrupado->has($chave)) {
                    $agrupado->put($chave, collect());
                }

                $agrupado->get($chave)->push($ordem);
            }
        }

        return $agrupado;
    }

    /**
     * Ids informados, limpos de vazios e repetidos.
     *
     * @param  array<int, int|string|null>  $ids
     * @return array<int, int>
     */
    private function idsUnicos(array $ids): array
    {
        $limpos = [];

        foreach ($ids as $id) {
            if (blank($id)) {
                continue;
            }

            $limpos[] = (int) $id;
        }

        return array_values(array_unique($limpos));
    }

    /**
     * Ids dos técnicos atribuídos à OS, sem repetição.
     *
     * @return array<int, int>
     */
    private function tecnicosDaOrdem(WorkOrder $ordem): array
    {
        $ids = [];

        if ($ordem->technician_id !== null) {
            $ids[] = (int) $ordem->technician_id;
        }

        if ($ordem->relationLoaded('technicians')) {
            foreach ($ordem->technicians as $tecnico) {
                $ids[] = (int) $tecnico->id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Monta uma linha da carga, com o balde de todos os dias do período já
     * preenchido, inclusive os zerados.
     *
     * @param  Collection<int, WorkOrder>  $ordens
     * @param  array<int, string>  $dias
     * @return array<string, mixed>
     */
    private function montarLinhaDeCarga(
        ?int $technicianId,
        string $nome,
        bool $semTecnico,
        bool $ativo,
        Collection $ordens,
        array $dias
    ): array {
        $porDia = [];

        foreach ($dias as $dia) {
            $porDia[$dia] = ['total_os' => 0, 'horas' => 0.0];
        }

        $semHorario = 0;

        foreach ($ordens as $ordem) {
            $dia = BusinessDate::diaDe($ordem->scheduled_date);

            if ($dia === null || ! array_key_exists($dia, $porDia)) {
                continue;
            }

            $porDia[$dia]['total_os']++;
            $horas = $this->horasDaOrdem($ordem);
            $porDia[$dia]['horas'] = round($porDia[$dia]['horas'] + $horas, 2);

            if ($this->intervaloDaOrdem($ordem) === null) {
                $semHorario++;
            }
        }

        $totaisDiarios = array_column($porDia, 'total_os');
        $diasComOs = count(array_filter($totaisDiarios));
        $totalDeDias = count($dias);

        return [
            'technician_id' => $technicianId,
            'nome' => $nome,
            'sem_tecnico' => $semTecnico,
            'ativo' => $ativo,
            'total_os' => $ordens->count(),
            'total_horas' => $this->somarHoras($ordens),
            'os_sem_horario' => $semHorario,
            'por_dia' => $porDia,
            'dias_com_os' => $diasComOs,
            'media_os_por_dia' => $totalDeDias > 0 ? round($ordens->count() / $totalDeDias, 2) : 0.0,
            'pico_no_dia' => $totaisDiarios === [] ? 0 : max($totaisDiarios),
        ];
    }

    /**
     * Horas agendadas de uma OS. OS sem horário, ou com horário que não fecha,
     * vale zero hora: ela conta na quantidade de OS, não nas horas.
     */
    private function horasDaOrdem(WorkOrder $ordem): float
    {
        $intervalo = $this->intervaloDaOrdem($ordem);

        if ($intervalo === null) {
            return 0.0;
        }

        return round($intervalo['inicio']->diffInMinutes($intervalo['fim']) / 60, 2);
    }

    /**
     * @param  Collection<int, WorkOrder>|EloquentCollection<int, WorkOrder>  $ordens
     */
    private function somarHoras(Collection|EloquentCollection $ordens): float
    {
        $total = 0.0;

        foreach ($ordens as $ordem) {
            $total += $this->horasDaOrdem($ordem);
        }

        return round($total, 2);
    }

    /**
     * Dias do período, do início ao fim, inclusive nas pontas.
     *
     * @return array<int, string>
     */
    private function diasDoPeriodo(CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $dias = [];
        $cursor = $de;

        while (! $cursor->greaterThan($ate)) {
            $dias[] = $cursor->toDateString();

            if (count($dias) > self::MAXIMO_DE_DIAS_NA_CARGA) {
                throw new InvalidArgumentException(
                    'Período longo demais para a carga por técnico: o máximo é '
                    . self::MAXIMO_DE_DIAS_NA_CARGA . ' dias.'
                );
            }

            $cursor = $cursor->addDay();
        }

        return $dias;
    }

    /**
     * Descrição de uma OS envolvida, com o que a Task 10.3 precisa mostrar ao
     * usuário: número, cliente e horário.
     *
     * `motivo` diz, no próprio item, se ele bloqueia (`sobreposicao`) ou apenas
     * informa (`sem_horario`).
     *
     * @return array<string, mixed>
     */
    private function descreverOrdem(WorkOrder $ordem, string $motivo): array
    {
        $intervalo = $this->intervaloDaOrdem($ordem);
        $inicio = $intervalo !== null ? $intervalo['inicio']->format('H:i') : null;
        $fim = $intervalo !== null ? $intervalo['fim']->format('H:i') : null;

        return [
            'id' => (int) $ordem->id,
            'order_number' => $ordem->order_number,
            'cliente' => $ordem->client?->name,
            'data' => BusinessDate::diaDe($ordem->scheduled_date),
            'inicio' => $inicio,
            'fim' => $fim,
            'horario' => $intervalo !== null ? "das {$inicio} às {$fim}" : 'sem horário definido',
            'status' => $ordem->status,
            'status_texto' => $ordem->status_text,
            'motivo' => $motivo,
            'bloqueia' => $motivo === self::MOTIVO_SOBREPOSICAO,
        ];
    }

    /**
     * Mensagem que nomeia as OS que colidem. "Horário indisponível" sem dizer
     * com o quê obriga o usuário a caçar o motivo.
     *
     * @param  array<int, array<string, mixed>>  $conflitos
     */
    private function mensagemDeConflito(array $conflitos): ?string
    {
        if ($conflitos === []) {
            return null;
        }

        $descricoes = array_map(fn (array $item) => $this->identificar($item) . ', ' . $item['horario'], $conflitos);

        return count($descricoes) === 1
            ? 'Conflito de horário com a ' . $descricoes[0] . '.'
            : 'Conflito de horário com as ordens de serviço: ' . implode('; ', $descricoes) . '.';
    }

    /**
     * Mensagem informativa. Não repete o horário porque, por definição, o item
     * de aviso é justamente o que não tem horário para comparar.
     *
     * @param  array<int, array<string, mixed>>  $avisos
     */
    private function mensagemDeAviso(array $avisos): ?string
    {
        if ($avisos === []) {
            return null;
        }

        $descricoes = array_map(fn (array $item) => $this->identificar($item), $avisos);

        return count($descricoes) === 1
            ? 'Atenção: a ' . $descricoes[0] . ' está no mesmo dia sem horário definido, então não dá para conferir sobreposição.'
            : 'Atenção: as ordens de serviço ' . implode('; ', $descricoes)
                . ' estão no mesmo dia sem horário definido, então não dá para conferir sobreposição.';
    }

    /**
     * Identificação curta da OS para a mensagem: número e cliente.
     *
     * @param  array<string, mixed>  $item
     */
    private function identificar(array $item): string
    {
        $texto = 'OS ' . $item['order_number'];

        if (! blank($item['cliente'])) {
            $texto .= ' (' . $item['cliente'] . ')';
        }

        return $texto;
    }

    /**
     * Fuso em que a aplicação grava os campos `datetime`. Continua UTC neste
     * projeto, e o valor é lido da configuração para não ficar cravado aqui.
     */
    private function fusoDaAplicacao(): string
    {
        $fuso = config('app.timezone');

        return is_string($fuso) && $fuso !== '' ? $fuso : 'UTC';
    }
}
