<?php

namespace App\Services\Fleet;

use App\Models\RouteStop;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use App\Support\Dinheiro;

/**
 * Custo de deslocamento de uma ordem de serviço (Plano 27, Task 27.2).
 *
 * Multiplica a quilometragem do deslocamento pelo custo por quilômetro do
 * veículo no período, e é o que substitui o valor fixo por visita que a margem
 * do Plano 18 usava até esta entrega.
 *
 * A origem da quilometragem sempre acompanha o resultado
 * ------------------------------------------------------
 * Duas fontes, nesta ordem de prioridade:
 *
 * 1. **Informada** (`work_orders.km_deslocamento`): alguém mediu e digitou.
 * 2. **Estimada** (`route_stops.distancia_anterior_km`, Plano 22): o motor de
 *    roteirização calculou a distância do trecho anterior até esta parada, por
 *    linha reta com fator de correção viária — nunca uma medição.
 *
 * O retorno carrega `origem_km` sempre, e `estimado => true` sempre que a
 * quilometragem for estimada **ou** o custo por quilômetro vier do padrão do
 * veículo. É a mesma regra da margem parcial do Plano 18: um número que parece
 * medido e não é leva a decisão errada com confiança, e nesse caso vale menos
 * que número nenhum.
 *
 * Período de apuração
 * -------------------
 * O custo por quilômetro é apurado nos `MESES_DE_APURACAO` meses que terminam
 * no dia da OS, e não sobre o histórico inteiro do veículo. Duas razões: o
 * preço do combustível muda demais ao longo de um ano para uma média de vida
 * inteira significar alguma coisa, e a margem de uma OS de março não deve mudar
 * porque o veículo passou a consumir mais em outubro. A janela olha para trás a
 * partir da OS, então o número é reprodutível: recalcular a margem da mesma OS
 * amanhã dá o mesmo resultado de hoje.
 *
 * O cálculo é feito na leitura
 * ----------------------------
 * Nada aqui é gravado. A margem de uma OS antiga passa a exibir deslocamento
 * calculado a partir do momento em que o módulo é ligado, sem reescrever dado
 * histórico — e é por isso que o número na tela muda, o que precisa ser
 * comunicado à empresa.
 */
class RateioDeDeslocamentoService
{
    /**
     * Quilometragem informada por quem executou a OS.
     */
    public const ORIGEM_KM_INFORMADA = 'informada';

    /**
     * Quilometragem estimada pelo roteiro do Plano 22.
     */
    public const ORIGEM_KM_ESTIMADA = 'estimada';

    /**
     * OS sem veículo vinculado: não há frota a ratear, e quem chama volta para
     * o comportamento anterior ao Plano 27.
     */
    public const MOTIVO_SEM_VEICULO = 'sem_veiculo';

    /**
     * Veículo vinculado, mas sem quilometragem informada nem estimada.
     */
    public const MOTIVO_SEM_QUILOMETRAGEM = 'sem_quilometragem';

    /**
     * Veículo vinculado e quilometragem conhecida, mas sem custo por
     * quilômetro: histórico insuficiente e `custo_km_padrao` não cadastrado.
     */
    public const MOTIVO_SEM_CUSTO_KM = 'sem_custo_km';

    /**
     * Janela de apuração do custo por quilômetro, em meses, contada para trás
     * a partir do dia da OS. Ver o cabeçalho da classe.
     */
    public const MESES_DE_APURACAO = 6;

    public function __construct(
        private readonly CustoPorKmService $custoPorKm,
    ) {}

    /**
     * Custo de deslocamento da OS informada.
     *
     * `aplicavel` é falso quando não dá para calcular (sem veículo, sem
     * quilometragem ou sem custo por quilômetro); nesses casos `valor` vem
     * '0.00' e `motivo` diz o que faltou. Quem chama decide o que fazer com
     * isso — este serviço não inventa número.
     *
     * @return array{
     *     aplicavel: bool,
     *     vehicle_id: ?int,
     *     km: ?float,
     *     origem_km: ?string,
     *     custo_por_km: ?string,
     *     origem_custo_km: ?string,
     *     intervalos: int,
     *     valor: string,
     *     estimado: bool,
     *     motivo: ?string
     * }
     */
    public function daOs(WorkOrder $os): array
    {
        $veiculo = $os->vehicle;

        if ($veiculo === null) {
            return $this->naoAplicavel(null, self::MOTIVO_SEM_VEICULO);
        }

        ['km' => $km, 'origem' => $origemKm] = $this->quilometragem($os);

        if ($km === null) {
            return $this->naoAplicavel((int) $veiculo->getKey(), self::MOTIVO_SEM_QUILOMETRAGEM);
        }

        ['de' => $de, 'ate' => $ate] = $this->periodoDeApuracao($os);
        $custo = $this->custoPorKm->custoTotalPorKm($veiculo, $de, $ate);

        if ($custo['total_por_km'] === null) {
            $resultado = $this->naoAplicavel((int) $veiculo->getKey(), self::MOTIVO_SEM_CUSTO_KM);
            $resultado['km'] = $km;
            $resultado['origem_km'] = $origemKm;
            $resultado['intervalos'] = $custo['intervalos'];

            return $resultado;
        }

        // A taxa vira dinheiro aqui, e só aqui: multiplicação em reais,
        // arredondada uma única vez para centavos inteiros, no critério de
        // `App\Support\Dinheiro`.
        $valorCentavos = (int) round($km * (float) $custo['total_por_km'] * 100);

        return [
            'aplicavel' => true,
            'vehicle_id' => (int) $veiculo->getKey(),
            'km' => $km,
            'origem_km' => $origemKm,
            'custo_por_km' => $custo['total_por_km'],
            'origem_custo_km' => $custo['origem'],
            'intervalos' => $custo['intervalos'],
            'valor' => Dinheiro::paraDecimal($valorCentavos),
            'estimado' => $origemKm === self::ORIGEM_KM_ESTIMADA
                || $custo['origem'] === CustoPorKmService::ORIGEM_PADRAO,
            'motivo' => null,
        ];
    }

    /**
     * Quilometragem do deslocamento da OS: a informada tem prioridade sobre a
     * estimada, sempre.
     *
     * Zero informado é uma resposta legítima ("não houve deslocamento") e é
     * respeitado; o que faz cair na estimativa é a coluna nula, que significa
     * "ninguém informou".
     *
     * @return array{km: ?float, origem: ?string}
     */
    private function quilometragem(WorkOrder $os): array
    {
        if ($os->km_deslocamento !== null) {
            return ['km' => (float) $os->km_deslocamento, 'origem' => self::ORIGEM_KM_INFORMADA];
        }

        $parada = RouteStop::query()
            ->where('work_order_id', $os->getKey())
            ->whereNotNull('distancia_anterior_km')
            ->orderByDesc('id')
            ->first();

        if ($parada === null) {
            return ['km' => null, 'origem' => null];
        }

        return ['km' => (float) $parada->distancia_anterior_km, 'origem' => self::ORIGEM_KM_ESTIMADA];
    }

    /**
     * Janela de apuração do custo por quilômetro: `MESES_DE_APURACAO` meses
     * terminando no dia da OS.
     *
     * O dia da OS é `scheduled_date` (o dia em que ela foi programada, coluna
     * `date`, que nunca sofre conversão de fuso); sem ela, o dia de criação
     * convertido para o fuso do negócio. Sem nenhum dos dois, hoje.
     *
     * @return array{de: string, ate: string}
     */
    private function periodoDeApuracao(WorkOrder $os): array
    {
        $dia = BusinessDate::paraFusoNegocio($os->scheduled_date)
            ?? BusinessDate::paraFusoNegocio($os->created_at)
            ?? BusinessDate::hoje();

        return [
            'de' => $dia->subMonths(self::MESES_DE_APURACAO)->toDateString(),
            'ate' => $dia->toDateString(),
        ];
    }

    /**
     * Retorno de quando não há como calcular, com o motivo à vista.
     *
     * @return array{
     *     aplicavel: bool,
     *     vehicle_id: ?int,
     *     km: ?float,
     *     origem_km: ?string,
     *     custo_por_km: ?string,
     *     origem_custo_km: ?string,
     *     intervalos: int,
     *     valor: string,
     *     estimado: bool,
     *     motivo: ?string
     * }
     */
    private function naoAplicavel(?int $vehicleId, string $motivo): array
    {
        return [
            'aplicavel' => false,
            'vehicle_id' => $vehicleId,
            'km' => null,
            'origem_km' => null,
            'custo_por_km' => null,
            'origem_custo_km' => null,
            'intervalos' => 0,
            'valor' => Dinheiro::paraDecimal(0),
            'estimado' => true,
            'motivo' => $motivo,
        ];
    }
}
