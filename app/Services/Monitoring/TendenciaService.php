<?php

namespace App\Services\Monitoring;

use App\Models\Address;
use App\Models\Device;
use App\Models\WorkOrderDeviceEvent;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Evolução de infestação por dispositivo e comparação entre períodos (Plano
 * 21, Task 21.2): transforma o evento bruto de campo
 * (`work_order_device_events`, gravado hoje por
 * `App\Services\Sync\AplicadorDeEventoDeDispositivo` e pelo formulário web da
 * OS) em série temporal e em variação percentual.
 *
 * Fonte de dado: `WorkOrderDeviceEvent`, não `DeviceEvent`
 * -----------------------------------------------------------------------
 * O projeto tem duas tabelas de evento por dispositivo. `work_order_device_events`
 * é a atual: é nela que o aplicativo do técnico grava (`bait_consumption_status`
 * e `pest_found`, Task 13.2) e é ela que aparece no PDF da OS. `device_events`
 * é a mais antiga, sem relação com o documento oficial. Um relatório de
 * monitoramento que fosse ler a tabela errada mostraria menos ocorrência do
 * que a OS realmente documentou, então este service só lê
 * `work_order_device_events`.
 *
 * "Sem visita" nunca é zero
 * -----------------------------------------------------------------------
 * Em `evolucaoPorDispositivo`, cada célula da série (dispositivo x período)
 * pode estar em um de dois estados: visitada (com contagem de captura e o
 * detalhamento de consumo de isca, podendo ser zero em ambos, e zero aqui é
 * uma boa notícia) ou **sem visita** (`sem_visita: true`, com `capturas` e
 * `consumo_de_isca` em `null`, nunca em zero). As duas coisas significam o
 * oposto uma da outra: zero é controle comprovado, `null` é ausência de
 * serviço. Colapsar as duas no mesmo número é a distorção mais grave possível
 * neste relatório, porque ele alimenta auditoria de indústria alimentícia.
 *
 * Variação a partir de zero
 * -----------------------------------------------------------------------
 * Em `comparar`, quando o total do período A é zero, a variação percentual
 * não é calculável (seria divisão por zero) nem "infinita": o retorno traz
 * `percentual: null` e os números crus (`de`/`para`) para quem apresenta o
 * dado montar "de 0 para N" com o próprio texto, sem que este service gere
 * frase alguma.
 *
 * Nenhuma conclusão automática
 * -----------------------------------------------------------------------
 * Este service entrega número, nunca leitura interpretativa ("infestação
 * controlada", "atenção recomendada" etc.). Isso é o Plano 25, e sai marcado
 * como gerado por modelo quando existir.
 *
 * Performance e índice
 * -----------------------------------------------------------------------
 * Toda consulta filtra `event_date` por `WHERE ... BETWEEN` (índice
 * `work_order_device_events.event_date`, criado na Task da própria tabela) e
 * `devices.address_id` (índice `devices_address_id_active_index`, Plano 11).
 * Nenhum método aqui carrega o histórico inteiro em PHP para filtrar depois:
 * a soma por dispositivo em `comparar` é feita em SQL
 * (`SUM(CASE WHEN ...)`), e a série de `evolucaoPorDispositivo` percorre com
 * `cursor()` apenas as linhas já restritas ao intervalo pedido.
 *
 * Isolamento por empresa
 * -----------------------------------------------------------------------
 * `WorkOrderDeviceEvent` e `Device` usam `BelongsToCompany`: toda consulta
 * feita por Eloquent aqui já nasce filtrada pela empresa corrente
 * (`TenantAtual`). O `join` com `devices` reforça o filtro explicitamente por
 * `devices.company_id = $endereco->company_id`, em vez de confiar só no
 * relacionamento `address_id`: um `join` em SQL cru não herda escopo global
 * de outro model automaticamente, e este segundo filtro é o que garante que
 * nenhum dispositivo de outra empresa entre na série mesmo que a invariante
 * "endereço e dispositivo sempre da mesma empresa" seja quebrada em outro
 * lugar do sistema por engano.
 */
class TendenciaService
{
    public const GRANULARIDADE_SEMANA = 'semana';

    public const GRANULARIDADE_MES = 'mes';

    /**
     * @var array<int, string>
     */
    private const GRANULARIDADES_VALIDAS = [self::GRANULARIDADE_SEMANA, self::GRANULARIDADE_MES];

    /**
     * Série de evolução por dispositivo, um ponto por período (semana ou
     * mês), cobrindo consumo de isca (detalhado por status) e captura
     * (contagem de eventos com praga encontrada).
     *
     * @return array{
     *     address_id: int,
     *     granularidade: string,
     *     de: string,
     *     ate: string,
     *     periodos: list<array{chave: string, inicio: string, fim: string}>,
     *     dispositivos: list<array{
     *         device_id: int,
     *         rotulo: string,
     *         codigo_publico: string|null,
     *         serie: list<array{
     *             chave: string,
     *             inicio: string,
     *             fim: string,
     *             sem_visita: bool,
     *             visitas: int,
     *             capturas: int|null,
     *             consumo_de_isca: array<string, int>|null
     *         }>
     *     }>
     * }
     */
    public function evolucaoPorDispositivo(Address $endereco, string $de, string $ate, string $granularidade): array
    {
        $this->validarGranularidade($granularidade);
        [$inicio, $fim] = $this->validarIntervalo($de, $ate);

        $periodos = $this->periodosDoIntervalo($inicio, $fim, $granularidade);

        $dispositivos = Device::query()
            ->where('address_id', $endereco->id)
            ->orderBy('label')
            ->get(['id', 'label', 'number', 'codigo_publico']);

        $acumulado = $this->acumularEventosPorDispositivoEPeriodo($endereco, $inicio, $fim, $granularidade);

        $serieDeDispositivos = $dispositivos
            ->map(fn (Device $dispositivo): array => [
                'device_id' => $dispositivo->id,
                'rotulo' => $dispositivo->full_label,
                'codigo_publico' => $dispositivo->codigo_publico,
                'serie' => $this->montarSerieDoDispositivo($dispositivo->id, $periodos, $acumulado),
            ])
            ->values()
            ->all();

        return [
            'address_id' => $endereco->id,
            'granularidade' => $granularidade,
            'de' => $inicio->toDateString(),
            'ate' => $fim->toDateString(),
            'periodos' => array_map(
                static fn (array $periodo): array => [
                    'chave' => $periodo['chave'],
                    'inicio' => $periodo['inicio'],
                    'fim' => $periodo['fim'],
                ],
                $periodos
            ),
            'dispositivos' => $serieDeDispositivos,
        ];
    }

    /**
     * Totais dos dois períodos e a variação, por dispositivo e no total do
     * endereço, para três métricas: visitas, capturas e consumo de isca
     * registrado (evento com `bait_consumption_status` preenchido).
     *
     * Dispositivo sem nenhum evento em nenhum dos dois períodos ainda entra
     * no resultado com os três totais zerados: ele existe no endereço, e a
     * ausência de dado nos dois períodos é, em si, uma informação (nenhuma
     * visita registrada), diferente de omitir a linha.
     *
     * @param  array{de: string, ate: string}  $periodoA
     * @param  array{de: string, ate: string}  $periodoB
     * @return array{
     *     address_id: int,
     *     periodo_a: array{de: string, ate: string},
     *     periodo_b: array{de: string, ate: string},
     *     por_dispositivo: list<array{
     *         device_id: int,
     *         rotulo: string,
     *         codigo_publico: string|null,
     *         visitas: array{de: int, para: int, percentual: float|null, a_partir_de_zero: bool},
     *         capturas: array{de: int, para: int, percentual: float|null, a_partir_de_zero: bool},
     *         consumo_de_isca: array{de: int, para: int, percentual: float|null, a_partir_de_zero: bool}
     *     }>,
     *     total_endereco: array{
     *         visitas: array{de: int, para: int, percentual: float|null, a_partir_de_zero: bool},
     *         capturas: array{de: int, para: int, percentual: float|null, a_partir_de_zero: bool},
     *         consumo_de_isca: array{de: int, para: int, percentual: float|null, a_partir_de_zero: bool}
     *     }
     * }
     */
    public function comparar(Address $endereco, array $periodoA, array $periodoB): array
    {
        $deA = $this->chaveObrigatoria($periodoA, 'de');
        $ateA = $this->chaveObrigatoria($periodoA, 'ate');
        $deB = $this->chaveObrigatoria($periodoB, 'de');
        $ateB = $this->chaveObrigatoria($periodoB, 'ate');

        $totaisA = $this->totaisPorDispositivo($endereco, $deA, $ateA);
        $totaisB = $this->totaisPorDispositivo($endereco, $deB, $ateB);

        $dispositivos = Device::query()
            ->where('address_id', $endereco->id)
            ->orderBy('label')
            ->get(['id', 'label', 'number', 'codigo_publico']);

        $totalGeralA = ['visitas' => 0, 'capturas' => 0, 'consumo_de_isca' => 0];
        $totalGeralB = ['visitas' => 0, 'capturas' => 0, 'consumo_de_isca' => 0];

        $porDispositivo = [];

        foreach ($dispositivos as $dispositivo) {
            $linhaA = $totaisA->get($dispositivo->id);
            $linhaB = $totaisB->get($dispositivo->id);

            $valoresA = $this->valoresDaLinha($linhaA);
            $valoresB = $this->valoresDaLinha($linhaB);

            foreach ($totalGeralA as $metrica => $_) {
                $totalGeralA[$metrica] += $valoresA[$metrica];
                $totalGeralB[$metrica] += $valoresB[$metrica];
            }

            $porDispositivo[] = [
                'device_id' => $dispositivo->id,
                'rotulo' => $dispositivo->full_label,
                'codigo_publico' => $dispositivo->codigo_publico,
                'visitas' => $this->calcularVariacao($valoresA['visitas'], $valoresB['visitas']),
                'capturas' => $this->calcularVariacao($valoresA['capturas'], $valoresB['capturas']),
                'consumo_de_isca' => $this->calcularVariacao($valoresA['consumo_de_isca'], $valoresB['consumo_de_isca']),
            ];
        }

        return [
            'address_id' => $endereco->id,
            'periodo_a' => ['de' => $deA, 'ate' => $ateA],
            'periodo_b' => ['de' => $deB, 'ate' => $ateB],
            'por_dispositivo' => $porDispositivo,
            'total_endereco' => [
                'visitas' => $this->calcularVariacao($totalGeralA['visitas'], $totalGeralB['visitas']),
                'capturas' => $this->calcularVariacao($totalGeralA['capturas'], $totalGeralB['capturas']),
                'consumo_de_isca' => $this->calcularVariacao($totalGeralA['consumo_de_isca'], $totalGeralB['consumo_de_isca']),
            ],
        ];
    }

    /**
     * As chaves de período existentes no intervalo, na granularidade pedida,
     * cada uma com o primeiro e o último dia que ela cobre.
     *
     * Semana usa o padrão ISO-8601 (segunda a domingo, o mesmo que
     * `CarbonImmutable::startOfWeek()`/`endOfWeek()` já aplicam por padrão
     * neste projeto), identificada como "AAAA-Www" (ex.: "2026-W32"). Mês
     * usa "AAAA-MM".
     *
     * @return list<array{chave: string, inicio: string, fim: string}>
     */
    private function periodosDoIntervalo(CarbonImmutable $inicio, CarbonImmutable $fim, string $granularidade): array
    {
        $ehSemana = $granularidade === self::GRANULARIDADE_SEMANA;

        $cursor = $ehSemana ? $inicio->startOfWeek() : $inicio->startOfMonth();
        $limite = $ehSemana ? $fim->endOfWeek() : $fim->endOfMonth();

        $periodos = [];

        while ($cursor->lessThanOrEqualTo($limite)) {
            $fimDoPeriodo = $ehSemana ? $cursor->endOfWeek() : $cursor->endOfMonth();

            $periodos[] = [
                'chave' => $this->chaveDoPeriodo($cursor, $granularidade),
                'inicio' => $cursor->toDateString(),
                'fim' => $fimDoPeriodo->toDateString(),
            ];

            $cursor = $ehSemana ? $cursor->addWeek() : $cursor->addMonthNoOverflow();
        }

        return $periodos;
    }

    private function chaveDoPeriodo(CarbonImmutable $data, string $granularidade): string
    {
        return $granularidade === self::GRANULARIDADE_SEMANA
            ? $data->format('o-\WW')
            : $data->format('Y-m');
    }

    /**
     * Percorre, com `cursor()`, os eventos de `$endereco` dentro de
     * `[$inicio, $fim]` e acumula, por dispositivo e por período, a
     * contagem de visitas, de captura e o detalhamento de consumo de isca.
     *
     * Uma única query, filtrada no banco pelos dois índices descritos no
     * cabeçalho da classe: nenhum evento fora do intervalo ou de outro
     * endereço chega a este loop.
     *
     * @return array<int, array<string, array{visitas: int, capturas: int, consumo_de_isca: array<string, int>}>>
     */
    private function acumularEventosPorDispositivoEPeriodo(
        Address $endereco,
        CarbonImmutable $inicio,
        CarbonImmutable $fim,
        string $granularidade
    ): array {
        $acumulado = [];

        $eventos = WorkOrderDeviceEvent::query()
            ->join('devices', 'devices.id', '=', 'work_order_device_events.device_id')
            ->where('devices.address_id', $endereco->id)
            ->where('devices.company_id', $endereco->company_id)
            ->whereBetween('work_order_device_events.event_date', [$inicio->toDateString(), $fim->toDateString()])
            ->orderBy('work_order_device_events.event_date')
            ->select([
                'work_order_device_events.device_id',
                'work_order_device_events.event_date',
                'work_order_device_events.bait_consumption_status',
                'work_order_device_events.pest_found',
            ]);

        foreach ($eventos->cursor() as $evento) {
            $dataDoEvento = BusinessDate::paraFusoNegocio($evento->event_date);

            if ($dataDoEvento === null) {
                continue;
            }

            $chave = $this->chaveDoPeriodo($dataDoEvento, $granularidade);
            $deviceId = (int) $evento->device_id;

            $acumulado[$deviceId][$chave] ??= ['visitas' => 0, 'capturas' => 0, 'consumo_de_isca' => []];

            $acumulado[$deviceId][$chave]['visitas']++;

            if (filled($evento->pest_found)) {
                $acumulado[$deviceId][$chave]['capturas']++;
            }

            if (filled($evento->bait_consumption_status)) {
                $status = (string) $evento->bait_consumption_status;
                $acumulado[$deviceId][$chave]['consumo_de_isca'][$status] =
                    ($acumulado[$deviceId][$chave]['consumo_de_isca'][$status] ?? 0) + 1;
            }
        }

        return $acumulado;
    }

    /**
     * Monta a série de um dispositivo, período a período. Período sem
     * entrada em `$acumulado` é "sem visita": `capturas` e
     * `consumo_de_isca` saem `null`, nunca `0`.
     *
     * @param  list<array{chave: string, inicio: string, fim: string}>  $periodos
     * @param  array<int, array<string, array{visitas: int, capturas: int, consumo_de_isca: array<string, int>}>>  $acumulado
     * @return list<array{
     *     chave: string,
     *     inicio: string,
     *     fim: string,
     *     sem_visita: bool,
     *     visitas: int,
     *     capturas: int|null,
     *     consumo_de_isca: array<string, int>|null
     * }>
     */
    private function montarSerieDoDispositivo(int $deviceId, array $periodos, array $acumulado): array
    {
        $serie = [];

        foreach ($periodos as $periodo) {
            $dados = $acumulado[$deviceId][$periodo['chave']] ?? null;

            if ($dados === null) {
                $serie[] = [
                    'chave' => $periodo['chave'],
                    'inicio' => $periodo['inicio'],
                    'fim' => $periodo['fim'],
                    'sem_visita' => true,
                    'visitas' => 0,
                    'capturas' => null,
                    'consumo_de_isca' => null,
                ];

                continue;
            }

            $serie[] = [
                'chave' => $periodo['chave'],
                'inicio' => $periodo['inicio'],
                'fim' => $periodo['fim'],
                'sem_visita' => false,
                'visitas' => $dados['visitas'],
                'capturas' => $dados['capturas'],
                'consumo_de_isca' => $dados['consumo_de_isca'],
            ];
        }

        return $serie;
    }

    /**
     * Totais por dispositivo dentro de `[$de, $ate]`, somados em SQL
     * (`COUNT`/`SUM(CASE WHEN ...)`), uma linha por dispositivo com pelo
     * menos um evento no intervalo. Dispositivo sem nenhum evento
     * simplesmente não aparece na coleção, e quem chama trata a ausência
     * como zero (ver `valoresDaLinha`).
     *
     * @return Collection<int, object{device_id: int, visitas: int, capturas: int, consumo_de_isca: int}>
     */
    private function totaisPorDispositivo(Address $endereco, string $de, string $ate): Collection
    {
        [$inicio, $fim] = $this->validarIntervalo($de, $ate);

        return WorkOrderDeviceEvent::query()
            ->join('devices', 'devices.id', '=', 'work_order_device_events.device_id')
            ->where('devices.address_id', $endereco->id)
            ->where('devices.company_id', $endereco->company_id)
            ->whereBetween('work_order_device_events.event_date', [$inicio->toDateString(), $fim->toDateString()])
            ->groupBy('work_order_device_events.device_id')
            ->selectRaw('work_order_device_events.device_id as device_id')
            ->selectRaw('COUNT(*) as visitas')
            ->selectRaw(
                "SUM(CASE WHEN work_order_device_events.pest_found IS NOT NULL AND work_order_device_events.pest_found <> '' THEN 1 ELSE 0 END) as capturas"
            )
            ->selectRaw(
                "SUM(CASE WHEN work_order_device_events.bait_consumption_status IS NOT NULL AND work_order_device_events.bait_consumption_status <> '' THEN 1 ELSE 0 END) as consumo_de_isca"
            )
            ->get()
            ->keyBy(static fn (object $linha): int => (int) $linha->device_id);
    }

    /**
     * @return array{visitas: int, capturas: int, consumo_de_isca: int}
     */
    private function valoresDaLinha(?object $linha): array
    {
        if ($linha === null) {
            return ['visitas' => 0, 'capturas' => 0, 'consumo_de_isca' => 0];
        }

        return [
            'visitas' => (int) $linha->visitas,
            'capturas' => (int) $linha->capturas,
            'consumo_de_isca' => (int) $linha->consumo_de_isca,
        ];
    }

    /**
     * Variação entre dois totais, sempre com os números crus além do
     * percentual.
     *
     * Total inicial zero não produz percentual: a divisão seria por zero, e
     * "infinito por cento" não é um número que se imprime em relatório de
     * auditoria. Nesse caso `percentual` sai `null` e `a_partir_de_zero`
     * sai `true` quando houve crescimento (0 para N), para quem apresenta o
     * dado montar "de 0 para N" com o texto próprio - este método nunca
     * gera frase, só o par de números.
     *
     * @return array{de: int, para: int, percentual: float|null, a_partir_de_zero: bool}
     */
    private function calcularVariacao(int $totalPeriodoA, int $totalPeriodoB): array
    {
        if ($totalPeriodoA === 0) {
            return [
                'de' => $totalPeriodoA,
                'para' => $totalPeriodoB,
                'percentual' => $totalPeriodoB === 0 ? 0.0 : null,
                'a_partir_de_zero' => $totalPeriodoB > 0,
            ];
        }

        return [
            'de' => $totalPeriodoA,
            'para' => $totalPeriodoB,
            'percentual' => round((($totalPeriodoB - $totalPeriodoA) / $totalPeriodoA) * 100, 2),
            'a_partir_de_zero' => false,
        ];
    }

    private function validarGranularidade(string $granularidade): void
    {
        if (! in_array($granularidade, self::GRANULARIDADES_VALIDAS, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Granularidade inválida: "%s". Use "%s" ou "%s".',
                    $granularidade,
                    self::GRANULARIDADE_SEMANA,
                    self::GRANULARIDADE_MES
                )
            );
        }
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function validarIntervalo(string $de, string $ate): array
    {
        $inicio = BusinessDate::paraFusoNegocio($de)?->startOfDay();
        $fim = BusinessDate::paraFusoNegocio($ate)?->startOfDay();

        if ($inicio === null || $fim === null) {
            throw new InvalidArgumentException('Informe as duas datas do intervalo, "de" e "até".');
        }

        if ($fim->lessThan($inicio)) {
            throw new InvalidArgumentException('A data final não pode ser anterior à data inicial.');
        }

        return [$inicio, $fim];
    }

    /**
     * @param  array{de?: string, ate?: string}  $periodo
     */
    private function chaveObrigatoria(array $periodo, string $chave): string
    {
        $valor = $periodo[$chave] ?? null;

        if (! is_string($valor) || trim($valor) === '') {
            throw new InvalidArgumentException(sprintf('O período precisa informar "%s".', $chave));
        }

        return $valor;
    }
}
