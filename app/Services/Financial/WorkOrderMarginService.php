<?php

namespace App\Services\Financial;

use App\Models\Receivable;
use App\Models\WorkOrder;
use App\Services\Fleet\RateioDeDeslocamentoService;
use App\Services\WorkOrderStockService;
use App\Support\Dinheiro;

/**
 * Margem de uma ordem de serviço (Plano 18, Task 18.6): receita do título
 * menos custo de produto (Plano 17), custo de mão de obra e custo de
 * deslocamento.
 *
 * Receita
 * -------
 * É o valor do título a receber gerado a partir desta OS
 * (`Receivable::valor_total`, o mesmo que `ReceivableService::gerarDaOs()`
 * grava), não `final_amount` da OS direto: o título é o documento que de fato
 * vale perante o cliente, e pode ter sido ajustado nas condições de
 * pagamento. OS sem título gerado ainda entra com receita zerada e a margem
 * sai marcada como parcial, em vez de recorrer a `final_amount` como
 * segunda fonte - misturar as duas fontes faria a margem contar um valor que
 * pode nunca ter virado título de verdade.
 *
 * Custo de produto
 * ----------------
 * Vem de `WorkOrderStockService::custoDeProdutos()` (Plano 17): soma de
 * quantidade x custo unitário congelado no fechamento da OS. Produto sem
 * custo congelado (sem controle de estoque, sem lote, ou baixa pendente)
 * já entra como zero por conta daquele service; este aqui só repassa o
 * número.
 *
 * Custo de mão de obra
 * --------------------
 * Horas de execução (`WorkOrder::duration_hours`, já calculado a partir de
 * `start_time`/`end_time`) multiplicadas pelo `custo_hora` do técnico
 * vinculado à OS. Sem técnico vinculado, ou com técnico sem `custo_hora`
 * preenchido, o componente sai zerado e a margem inteira é marcada como
 * parcial: um número de mão de obra chutado seria pior que nenhum número,
 * porque passaria a impressão de custo real conhecido.
 *
 * Custo de deslocamento: calculado pela frota desde o Plano 27
 * -------------------------------------------------------------
 * Até o Plano 26 este componente era um valor **fixo por visita** (R$ 50,00), e
 * o retorno carregava `deslocamento_e_estimado => true` sempre. A Task 27.2
 * substituiu o fixo pelo cálculo real: quando a OS tem veículo vinculado,
 * `RateioDeDeslocamentoService` multiplica a quilometragem do deslocamento
 * (informada em `work_orders.km_deslocamento`, ou estimada pelo roteiro do
 * Plano 22) pelo custo por quilômetro do veículo no período.
 *
 * O fixo continua existindo como reserva, em duas situações:
 *
 * - **OS sem veículo vinculado.** Empresa que não controla frota não perde a
 *   margem: o Plano 18 continua funcionando exatamente como antes, e a margem
 *   **não** é marcada como parcial por isso. Não ter frota não é falta de dado.
 * - **OS com veículo vinculado, mas sem quilometragem ou sem custo por
 *   quilômetro.** Aí é falta de dado de verdade — a empresa optou pela frota e
 *   o número não fechou —, então o fixo entra e a margem sai **parcial**, com o
 *   motivo correspondente.
 *
 * `deslocamento_e_estimado` continua verdadeiro sempre que o componente não for
 * custo medido: no fixo de reserva, na quilometragem estimada pelo roteiro e no
 * custo por quilômetro vindo do `custo_km_padrao` do veículo. Só sai falso com
 * quilometragem informada e custo apurado sobre histórico de tanque cheio
 * suficiente.
 *
 * **Efeito sobre margem histórica.** O cálculo é feito na leitura e nada é
 * reescrito, mas a margem exibida de OS antigas muda assim que o módulo de
 * frota é ligado e os veículos passam a ser vinculados. É mudança esperada, e
 * precisa ser comunicada à empresa antes de ligar o módulo.
 *
 * Margem parcial
 * ---------------
 * Todo componente que não pôde ser calculado por falta de dado entra com
 * valor zero, e o motivo correspondente é acrescentado a `motivos_parcial`.
 * A margem inteira sai com `parcial => true` assim que houver ao menos um
 * motivo: número que parece completo e não é vale menos que número nenhum,
 * porque leva a decisão errada com confiança (skill `laravel-arquitetura`,
 * regra de negócio da Task 18.6).
 */
class WorkOrderMarginService
{
    /**
     * Receita sem título a receber gerado para a OS.
     */
    public const MOTIVO_SEM_TITULO = 'titulo_nao_gerado';

    /**
     * OS sem técnico vinculado (`technician_id` nulo).
     */
    public const MOTIVO_SEM_TECNICO = 'sem_tecnico_vinculado';

    /**
     * Técnico vinculado, mas sem `custo_hora` preenchido.
     */
    public const MOTIVO_TECNICO_SEM_CUSTO_HORA = 'tecnico_sem_custo_hora';

    /**
     * OS com veículo vinculado, mas sem quilometragem informada nem estimada
     * pelo roteiro: o deslocamento cai no fixo de reserva (Plano 27).
     */
    public const MOTIVO_DESLOCAMENTO_SEM_QUILOMETRAGEM = 'deslocamento_sem_quilometragem';

    /**
     * OS com veículo vinculado e quilometragem conhecida, mas sem custo por
     * quilômetro: histórico de tanque cheio insuficiente e `custo_km_padrao`
     * não cadastrado no veículo (Plano 27).
     */
    public const MOTIVO_DESLOCAMENTO_SEM_CUSTO_KM = 'deslocamento_sem_custo_km';

    /**
     * Deslocamento calculado pela frota (Plano 27).
     */
    public const FONTE_DESLOCAMENTO_FROTA = 'frota';

    /**
     * Deslocamento vindo do valor fixo por visita, a reserva do Plano 18.
     */
    public const FONTE_DESLOCAMENTO_FIXA = 'fixa';

    /**
     * Custo fixo de deslocamento por visita, em centavos. R$ 50,00.
     *
     * Deixou de ser o cálculo padrão na Task 27.2 e virou a reserva: vale para
     * OS sem veículo vinculado (empresa que não controla frota) e para OS cujo
     * rateio não fechou por falta de dado. Ver o cabeçalho da classe. Este
     * valor nunca deve ser lido como custo real de deslocamento, e por isso
     * quem cai nele sempre recebe `deslocamento_e_estimado => true`.
     */
    private const CUSTO_DESLOCAMENTO_POR_VISITA_CENTAVOS = 5000;

    public function __construct(
        private readonly WorkOrderStockService $estoque,
        private readonly RateioDeDeslocamentoService $deslocamento,
    ) {}

    /**
     * Margem da OS informada, com os componentes separados e o total.
     *
     * @return array{
     *     work_order_id: int,
     *     receita: string,
     *     custo_produto: string,
     *     custo_mao_de_obra: string,
     *     custo_deslocamento: string,
     *     custo_total: string,
     *     margem: string,
     *     horas_de_execucao: float,
     *     custo_hora_tecnico: ?string,
     *     deslocamento_e_estimado: bool,
     *     deslocamento_fonte: string,
     *     deslocamento_km: ?float,
     *     deslocamento_origem_km: ?string,
     *     deslocamento_custo_por_km: ?string,
     *     deslocamento_origem_custo_km: ?string,
     *     parcial: bool,
     *     motivos_parcial: list<string>
     * }
     */
    public function margem(WorkOrder $os): array
    {
        $motivosParcial = [];

        ['centavos' => $receitaCentavos, 'motivo' => $motivoReceita] = $this->receita($os);
        $this->registrarMotivo($motivosParcial, $motivoReceita);

        $custoProdutoCentavos = Dinheiro::centavos($this->estoque->custoDeProdutos($os));

        ['centavos' => $custoMaoDeObraCentavos, 'motivo' => $motivoMaoDeObra] = $this->custoDeMaoDeObra($os);
        $this->registrarMotivo($motivosParcial, $motivoMaoDeObra);

        $rateio = $this->custoDeDeslocamento($os);
        $this->registrarMotivo($motivosParcial, $rateio['motivo']);
        $custoDeslocamentoCentavos = $rateio['centavos'];

        $custoTotalCentavos = $custoProdutoCentavos + $custoMaoDeObraCentavos + $custoDeslocamentoCentavos;
        $margemCentavos = $receitaCentavos - $custoTotalCentavos;

        $tecnico = $os->technician;

        return [
            'work_order_id' => (int) $os->getKey(),
            'receita' => Dinheiro::paraDecimal($receitaCentavos),
            'custo_produto' => Dinheiro::paraDecimal($custoProdutoCentavos),
            'custo_mao_de_obra' => Dinheiro::paraDecimal($custoMaoDeObraCentavos),
            'custo_deslocamento' => Dinheiro::paraDecimal($custoDeslocamentoCentavos),
            'custo_total' => Dinheiro::paraDecimal($custoTotalCentavos),
            'margem' => Dinheiro::paraDecimal($margemCentavos),
            'horas_de_execucao' => (float) $os->duration_hours,
            'custo_hora_tecnico' => $tecnico?->custo_hora !== null ? (string) $tecnico->custo_hora : null,
            'deslocamento_e_estimado' => $rateio['estimado'],
            'deslocamento_fonte' => $rateio['fonte'],
            'deslocamento_km' => $rateio['km'],
            'deslocamento_origem_km' => $rateio['origem_km'],
            'deslocamento_custo_por_km' => $rateio['custo_por_km'],
            'deslocamento_origem_custo_km' => $rateio['origem_custo_km'],
            'parcial' => $motivosParcial !== [],
            'motivos_parcial' => array_values($motivosParcial),
        ];
    }

    /**
     * Receita: valor do título a receber vinculado à OS (`work_order_id`).
     * Sem título gerado, zero e o motivo `MOTIVO_SEM_TITULO`.
     *
     * @return array{centavos: int, motivo: ?string}
     */
    private function receita(WorkOrder $os): array
    {
        $titulo = Receivable::query()->where('work_order_id', $os->getKey())->first();

        if ($titulo === null) {
            return ['centavos' => 0, 'motivo' => self::MOTIVO_SEM_TITULO];
        }

        return ['centavos' => Dinheiro::centavos($titulo->valor_total), 'motivo' => null];
    }

    /**
     * Custo de mão de obra: horas de execução x `custo_hora` do técnico.
     * Zero, com o motivo correspondente, quando falta o técnico ou o
     * `custo_hora` dele.
     *
     * @return array{centavos: int, motivo: ?string}
     */
    private function custoDeMaoDeObra(WorkOrder $os): array
    {
        $tecnico = $os->technician;

        if ($tecnico === null) {
            return ['centavos' => 0, 'motivo' => self::MOTIVO_SEM_TECNICO];
        }

        if ($tecnico->custo_hora === null) {
            return ['centavos' => 0, 'motivo' => self::MOTIVO_TECNICO_SEM_CUSTO_HORA];
        }

        $horas = (float) $os->duration_hours;
        $custoReais = round($horas * (float) $tecnico->custo_hora, 2);

        return ['centavos' => Dinheiro::centavos($custoReais), 'motivo' => null];
    }

    /**
     * Custo de deslocamento: rateio da frota quando dá, fixo de reserva quando
     * não dá.
     *
     * Só marca a margem como parcial quando a OS **tem** veículo vinculado e
     * ainda assim o rateio não fechou. Sem veículo não é falta de dado: é
     * empresa que não controla frota, e o Plano 18 continua valendo para ela
     * exatamente como antes desta entrega.
     *
     * @return array{
     *     centavos: int,
     *     fonte: string,
     *     estimado: bool,
     *     km: ?float,
     *     origem_km: ?string,
     *     custo_por_km: ?string,
     *     origem_custo_km: ?string,
     *     motivo: ?string
     * }
     */
    private function custoDeDeslocamento(WorkOrder $os): array
    {
        $rateio = $this->deslocamento->daOs($os);

        if ($rateio['aplicavel']) {
            return [
                'centavos' => Dinheiro::centavos($rateio['valor']),
                'fonte' => self::FONTE_DESLOCAMENTO_FROTA,
                'estimado' => $rateio['estimado'],
                'km' => $rateio['km'],
                'origem_km' => $rateio['origem_km'],
                'custo_por_km' => $rateio['custo_por_km'],
                'origem_custo_km' => $rateio['origem_custo_km'],
                'motivo' => null,
            ];
        }

        return [
            'centavos' => self::CUSTO_DESLOCAMENTO_POR_VISITA_CENTAVOS,
            'fonte' => self::FONTE_DESLOCAMENTO_FIXA,
            'estimado' => true,
            'km' => $rateio['km'],
            'origem_km' => $rateio['origem_km'],
            'custo_por_km' => null,
            'origem_custo_km' => null,
            'motivo' => match ($rateio['motivo']) {
                RateioDeDeslocamentoService::MOTIVO_SEM_QUILOMETRAGEM => self::MOTIVO_DESLOCAMENTO_SEM_QUILOMETRAGEM,
                RateioDeDeslocamentoService::MOTIVO_SEM_CUSTO_KM => self::MOTIVO_DESLOCAMENTO_SEM_CUSTO_KM,
                // MOTIVO_SEM_VEICULO cai aqui de propósito: ver o cabeçalho.
                default => null,
            },
        ];
    }

    /**
     * @param  list<string>  $motivosParcial
     */
    private function registrarMotivo(array &$motivosParcial, ?string $motivo): void
    {
        if ($motivo !== null) {
            $motivosParcial[] = $motivo;
        }
    }
}
