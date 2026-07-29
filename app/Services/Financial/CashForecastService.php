<?php

namespace App\Services\Financial;

use App\Models\DailyCashBalance;
use App\Models\PayableInstallment;
use App\Models\ReceivableInstallment;
use App\Support\BusinessDate;
use App\Support\Dinheiro;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Previsão de caixa (Plano 18, Task 18.6): quanto entra e quanto sai nos
 * próximos meses, a partir do que está em aberto hoje (parcela a receber e a
 * pagar, por vencimento), e o saldo acumulado a partir de onde o caixa está
 * agora.
 *
 * Previsto x realizado, sem misturar
 * -----------------------------------
 * `realizado` é o retrato de hoje: o saldo de caixa (`DailyCashBalance`) tal
 * como o fechamento diário já gravou, dinheiro que já entrou ou saiu de
 * verdade. É lido, nunca recalculado ou escrito por este service (a task é só
 * leitura/cálculo).
 *
 * `previsto` é a expectativa por competência (mês do vencimento): o que está
 * em aberto hoje ainda pode não virar dinheiro nunca (inadimplência) ou pode
 * virar dinheiro num dia diferente do vencimento (a baixa usa a data
 * informada, não o vencimento - Task 18.4). As duas leituras nunca se
 * misturam num número só: `realizado` é uma chave própria, fora do array de
 * meses, e cada linha de `previsto.meses` só carrega números de expectativa,
 * inclusive o saldo acumulado (`saldo_acumulado_previsto`), nomeado assim de
 * propósito para nunca ser lido como saldo real de caixa.
 *
 * Janela da previsão
 * -------------------
 * Cobre do primeiro dia do mês corrente até o fim do mês `$meses - 1` à
 * frente. Parcela vencida de competência **anterior** ao mês corrente, ainda
 * em aberto, não entra aqui: ela é o passivo/ativo que já venceu, reportado
 * pelo aging (`AgingService`), não uma projeção de mês futuro.
 *
 * Toda soma acontece em centavos (inteiro), via `App\Support\Dinheiro`, e o
 * isolamento por empresa vem do escopo global dos models (`BelongsToCompany`),
 * nunca de filtro manual deste service.
 *
 * Performance: cada consulta usa o índice `[situacao, vencimento]` (Task
 * 18.1) e só percorre parcelas em aberto dentro da janela pedida, o que
 * mantém a resposta rápida mesmo com anos de parcela paga no histórico.
 */
class CashForecastService
{
    /**
     * Situações de parcela que ainda representam saldo em aberto.
     *
     * @var array<int, string>
     */
    private const SITUACOES_EM_ABERTO = ['aberta', 'parcial', 'vencida'];

    /**
     * Previsão de caixa para os próximos `$meses`, incluindo o mês corrente.
     *
     * @return array{
     *     realizado: array{saldo_de_caixa_atual: string, referencia: string},
     *     previsto: array{meses: list<array{
     *         competencia: string,
     *         a_receber: string,
     *         a_pagar: string,
     *         resultado: string,
     *         saldo_acumulado_previsto: string
     *     }>}
     * }
     */
    public function previsao(int $meses): array
    {
        if ($meses < 1) {
            throw new InvalidArgumentException('A previsão de caixa precisa de ao menos 1 mês.');
        }

        $hoje = BusinessDate::hoje();
        $inicio = $hoje->startOfMonth();
        $fim = $inicio->addMonthsNoOverflow($meses - 1)->endOfMonth();

        $aReceberPorCompetencia = $this->totaisPorCompetencia(ReceivableInstallment::query(), $inicio, $fim);
        $aPagarPorCompetencia = $this->totaisPorCompetencia(PayableInstallment::query(), $inicio, $fim);

        $saldoAtualCentavos = $this->saldoDeCaixaAtualEmCentavos($hoje);
        $saldoAcumuladoCentavos = $saldoAtualCentavos;

        $mesesDaPrevisao = [];

        for ($indice = 0; $indice < $meses; $indice++) {
            $competencia = $inicio->addMonthsNoOverflow($indice)->format('Y-m');

            $aReceberCentavos = $aReceberPorCompetencia[$competencia] ?? 0;
            $aPagarCentavos = $aPagarPorCompetencia[$competencia] ?? 0;
            $resultadoCentavos = $aReceberCentavos - $aPagarCentavos;

            $saldoAcumuladoCentavos += $resultadoCentavos;

            $mesesDaPrevisao[] = [
                'competencia' => $competencia,
                'a_receber' => Dinheiro::paraDecimal($aReceberCentavos),
                'a_pagar' => Dinheiro::paraDecimal($aPagarCentavos),
                'resultado' => Dinheiro::paraDecimal($resultadoCentavos),
                'saldo_acumulado_previsto' => Dinheiro::paraDecimal($saldoAcumuladoCentavos),
            ];
        }

        return [
            'realizado' => [
                'saldo_de_caixa_atual' => Dinheiro::paraDecimal($saldoAtualCentavos),
                'referencia' => $hoje->toDateString(),
            ],
            'previsto' => [
                'meses' => $mesesDaPrevisao,
            ],
        ];
    }

    /**
     * Soma, em centavos, o saldo em aberto (`valor - valor_pago`) das
     * parcelas de `$consultaBase` dentro da janela `[$inicio, $fim]`,
     * agrupado por competência (`Y-m` do vencimento).
     *
     * @return array<string, int>
     */
    private function totaisPorCompetencia(Builder $consultaBase, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $totais = [];

        $consulta = $consultaBase
            ->whereIn('situacao', self::SITUACOES_EM_ABERTO)
            ->whereBetween('vencimento', [$inicio->toDateString(), $fim->toDateString()])
            ->orderBy('vencimento');

        foreach ($consulta->cursor() as $parcela) {
            $dia = BusinessDate::diaDe($parcela->vencimento);

            if ($dia === null) {
                continue;
            }

            $saldoCentavos = Dinheiro::centavos($parcela->valor) - Dinheiro::centavos($parcela->valor_pago);

            if ($saldoCentavos <= 0) {
                continue;
            }

            $competencia = substr($dia, 0, 7);
            $totais[$competencia] = ($totais[$competencia] ?? 0) + $saldoCentavos;
        }

        return $totais;
    }

    /**
     * Saldo de caixa mais recente até hoje, em centavos. Leitura pura de
     * `DailyCashBalance`: nunca cria a linha do dia (isso é
     * `DailyCashBalance::getTodayBalance()`, que grava, e este service não
     * escreve nada). Sem nenhum saldo registrado ainda, o ponto de partida é
     * zero.
     */
    private function saldoDeCaixaAtualEmCentavos(CarbonImmutable $hoje): int
    {
        $ultimoSaldo = DailyCashBalance::query()
            ->where('balance_date', '<=', $hoje->toDateString())
            ->orderByDesc('balance_date')
            ->first();

        return $ultimoSaldo === null ? 0 : Dinheiro::centavos($ultimoSaldo->closing_balance);
    }
}
