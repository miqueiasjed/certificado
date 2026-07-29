<?php

namespace App\Http\Controllers;

use App\Models\FinancialEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Painel financeiro: a tela que o cliente abre todo dia.
 *
 * Fonte de cada painel, depois da Task 18.7 (Plano 18)
 * ====================================================
 *
 * | painel                          | fonte             | trocado? |
 * |---------------------------------|-------------------|----------|
 * | resumo (`stats`)                | financial_entries | não      |
 * | gráfico por tipo                | financial_entries | não      |
 * | gráfico por forma de pagamento  | financial_entries | não      |
 * | evolução mensal                 | financial_entries | não      |
 * | entradas recentes               | financial_entries | não      |
 *
 * **Nenhum painel teve a fonte trocada para o modelo de títulos
 * (`receivables`/`receivable_installments`/`payables`/`payable_installments`),
 * e o motivo é estrutural, não uma tentativa que deu errado.** Por isso este
 * arquivo não tem nenhuma linha de código alterada pela Task 18.7: só esta
 * explicação.
 *
 * Por que a troca não acontece nesta task
 * ---------------------------------------
 * Os cinco painéis acima medem **caixa**: quanto dinheiro entrou e saiu, em
 * que dia, por qual forma de pagamento. O modelo de títulos não substitui o
 * caixa, ele o alimenta. `App\Services\Financial\IntegracaoComCaixa` continua
 * gravando cada baixa e cada estorno em `financial_entries`, de propósito e
 * com o mesmo vocabulário de `source` de sempre (ver o cabeçalho daquela
 * classe), para que o dinheiro nascido de título apareça no painel e no saldo
 * diário do dia certo sem nenhuma alteração aqui.
 *
 * Ou seja: o painel **já enxerga** o que o modelo novo produz. Trocar a fonte
 * para o título não traria informação nova, e mudaria a definição do número em
 * três casos que existem no dado real do cliente:
 *
 * 1. **Parcela reaberta e paga de novo.** O caixa tem dois lançamentos de
 *    entrada e um de saída (`payment_reopen`), e a parcela guarda um
 *    `valor_pago` só, apontando apenas para o lançamento mais recente
 *    (`ReceivableInstallment::$financial_entry_id`, e o critério de vínculo de
 *    `MigrarFinanceiroParaTitulos::lancamentosPorParcela()`). Lido pelo
 *    título, o recebimento anterior desaparece do painel.
 * 2. **Recebimento antigo sem lançamento vinculado.** A migração da Task 18.2
 *    grava essas parcelas com `financial_entry_id` nulo e as lista no
 *    relatório; elas têm `valor_pago` no título e **nenhum** dinheiro no
 *    caixa. Lido pelo título, o painel passaria a mostrar dinheiro que nunca
 *    entrou.
 * 3. **Lançamento manual e saída manual.** `financial_entries` com
 *    `source = manual`/`manual_withdrawal` não têm título nenhum: são o caixa
 *    da empresa fora de qualquer cobrança. Lidos pelo título, sumiriam do
 *    resumo e dos três gráficos.
 *
 * Some-se a isso que `payment_method` e `entry_date`, as duas dimensões do
 * gráfico por forma e da evolução mensal, vivem só no lançamento: o título não
 * sabe por qual meio nem em que dia o dinheiro entrou no caixa (a parcela
 * guarda `pago_em`, que é o dia em que ela terminou de ser paga, e só existe
 * quando ela é quitada).
 *
 * `FinancialEndpointTest` prende os dois lados disso: que os números destes
 * painéis continuam idênticos aos do modelo antigo, centavo a centavo, com o
 * modelo novo já povoado; e que a leitura pelo título diverge nos três casos
 * acima, com o valor exato de cada um. É a evidência de que a troca foi
 * recusada com prova, e não por falta de tentativa.
 *
 * O que precisa acontecer antes de trocar
 * ---------------------------------------
 * A troca só passa a ser possível quando a cauda legada estiver zerada
 * (exceções da migração resolvidas, lançamentos órfãos explicados, reaberturas
 * convertidas em estorno de título) **e** o caixa passar a ser derivado do
 * título, com origem própria em `financial_entries.source`
 * (`IntegracaoComCaixa::SOURCE_POR_NATUREZA`). Introduzir origem nova agora
 * exigiria alterar junto `DailyCashBalance::updateFromFinancialEntries()`,
 * `CashFlowController`, `FinancialEntryController` e as constantes de
 * `ConferenciaDeTotais`, tudo em cima de uma tabela com dado de produção: o
 * oposto de "um painel por vez, cada troca conferida".
 */
class FinancialDashboardController extends Controller
{
    /**
     * Display the financial dashboard.
     */
    public function index(Request $request)
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', now()->endOfMonth()->toDateString());

        // Estatísticas básicas
        $stats = $this->getBasicStats($startDate, $endDate);

        // Dados para gráficos
        $chartData = $this->getChartData($startDate, $endDate);

        // Entradas recentes
        $recentEntries = $this->getRecentEntries();

        return inertia('FinancialDashboard/Index', [
            'stats' => $stats,
            'chartData' => $chartData,
            'recentEntries' => $recentEntries
        ]);
    }

    /**
     * Obter estatísticas básicas
     */
    private function getBasicStats(string $startDate, string $endDate): array
    {
        $baseQuery = FinancialEntry::confirmed()
            ->whereBetween('entry_date', [$startDate, $endDate]);

        $paymentAmount = (clone $baseQuery)->where('source', 'work_order')->sum('amount');
        $manualAmount = (clone $baseQuery)->where('source', 'manual')->sum('amount');
        $withdrawalAmount = (clone $baseQuery)->whereIn('source', ['payment_reopen', 'manual_withdrawal'])->sum('amount');

        // Valor líquido = entradas - saídas
        $netAmount = $paymentAmount + $manualAmount - $withdrawalAmount;

        return [
            'total_amount' => $netAmount, // Valor líquido
            'payment_amount' => $paymentAmount,
            'manual_amount' => $manualAmount,
            'withdrawal_amount' => $withdrawalAmount,
            'total_entries' => (clone $baseQuery)->whereIn('source', ['work_order', 'manual'])->count(),
        ];
    }

    /**
     * Obter dados para os gráficos
     */
    private function getChartData(string $startDate, string $endDate): array
    {
        // Gráfico por tipo (Doughnut) - apenas entradas
        $typeData = FinancialEntry::confirmed()
            ->whereBetween('entry_date', [$startDate, $endDate])
            ->whereIn('source', ['work_order', 'manual']) // Apenas entradas
            ->selectRaw('source, SUM(amount) as total')
            ->groupBy('source')
            ->get();

        $typeChart = [
            'labels' => ['Pagamentos', 'Manuais'],
            'datasets' => [[
                'data' => [
                    $typeData->where('source', 'work_order')->first()->total ?? 0,
                    $typeData->where('source', 'manual')->first()->total ?? 0,
                ],
                'backgroundColor' => ['#3B82F6', '#F59E0B'],
                'borderWidth' => 2,
                'borderColor' => '#ffffff'
            ]]
        ];

        // Gráfico por forma de pagamento (Bar) - apenas entradas
        $methodData = FinancialEntry::confirmed()
            ->whereBetween('entry_date', [$startDate, $endDate])
            ->whereIn('source', ['work_order', 'manual']) // Apenas entradas
            ->whereNotNull('payment_method')
            ->selectRaw('payment_method, SUM(amount) as total')
            ->groupBy('payment_method')
            ->get();

        $methodLabels = [];
        $methodValues = [];
        $methodColors = [
            'pix' => '#10B981',
            'credit_card' => '#3B82F6',
            'debit_card' => '#8B5CF6',
            'boleto' => '#F59E0B',
            'cash' => '#EF4444',
            'bank_transfer' => '#06B6D4',
        ];

        foreach ($methodData as $item) {
            $methodLabels[] = $this->getPaymentMethodLabel($item->payment_method);
            $methodValues[] = $item->total;
        }

        $methodChart = [
            'labels' => $methodLabels,
            'datasets' => [[
                'label' => 'Valor (R$)',
                'data' => $methodValues,
                'backgroundColor' => array_map(fn($method) => $methodColors[$method] ?? '#6B7280', $methodData->pluck('payment_method')->toArray()),
                'borderRadius' => 4
            ]]
        ];

        // Gráfico de evolução mensal (Line) - valor líquido
        $monthlyData = FinancialEntry::confirmed()
            ->whereBetween('entry_date', [$startDate, $endDate])
            ->selectRaw('DATE_FORMAT(entry_date, "%Y-%m") as month,
                        SUM(CASE WHEN source IN ("work_order", "manual") THEN amount ELSE 0 END) -
                        SUM(CASE WHEN source IN ("payment_reopen", "manual_withdrawal") THEN amount ELSE 0 END) as total')
            ->groupBy('month')
            ->orderBy('month')
            ->get();

        $monthlyLabels = [];
        $monthlyValues = [];

        foreach ($monthlyData as $item) {
            $date = \Carbon\Carbon::createFromFormat('Y-m', $item->month);
            $monthlyLabels[] = $date->format('M/Y');
            $monthlyValues[] = $item->total;
        }

        $monthlyChart = [
            'labels' => $monthlyLabels,
            'datasets' => [[
                'label' => 'Receitas (R$)',
                'data' => $monthlyValues,
                'borderColor' => '#10B981',
                'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                'borderWidth' => 2,
                'fill' => true,
                'tension' => 0.4
            ]]
        ];

        return [
            'typeChart' => $typeChart,
            'methodChart' => $methodChart,
            'monthlyChart' => $monthlyChart,
        ];
    }

    /**
     * Obter entradas recentes
     */
    private function getRecentEntries(): array
    {
        return FinancialEntry::with(['workOrder', 'paymentDetail', 'createdBy'])
            ->orderBy('entry_date', 'desc')
            ->limit(10)
            ->get()
            ->toArray();
    }

    /**
     * Obter label da forma de pagamento
     */
    private function getPaymentMethodLabel(string $method): string
    {
        return match($method) {
            'pix' => 'PIX',
            'credit_card' => 'Cartão de Crédito',
            'debit_card' => 'Cartão de Débito',
            'boleto' => 'Boleto',
            'cash' => 'Dinheiro',
            'bank_transfer' => 'Transferência',
            default => 'Outros'
        };
    }
}
