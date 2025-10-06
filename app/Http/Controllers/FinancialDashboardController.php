<?php

namespace App\Http\Controllers;

use App\Models\FinancialEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        $query = FinancialEntry::confirmed()
            ->whereBetween('entry_date', [$startDate, $endDate]);

        return [
            'total_amount' => $query->sum('amount'),
            'payment_amount' => $query->where('type', 'payment')->sum('amount'),
            'manual_amount' => $query->where('type', 'manual')->sum('amount'),
            'total_entries' => $query->count(),
        ];
    }

    /**
     * Obter dados para os gráficos
     */
    private function getChartData(string $startDate, string $endDate): array
    {
        // Gráfico por tipo (Doughnut)
        $typeData = FinancialEntry::confirmed()
            ->whereBetween('entry_date', [$startDate, $endDate])
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->get();

        $typeChart = [
            'labels' => ['Pagamentos', 'Manuais'],
            'datasets' => [[
                'data' => [
                    $typeData->where('type', 'payment')->first()->total ?? 0,
                    $typeData->where('type', 'manual')->first()->total ?? 0,
                ],
                'backgroundColor' => ['#3B82F6', '#F59E0B'],
                'borderWidth' => 2,
                'borderColor' => '#ffffff'
            ]]
        ];

        // Gráfico por forma de pagamento (Bar)
        $methodData = FinancialEntry::confirmed()
            ->whereBetween('entry_date', [$startDate, $endDate])
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

        // Gráfico de evolução mensal (Line)
        $monthlyData = FinancialEntry::confirmed()
            ->whereBetween('entry_date', [$startDate, $endDate])
            ->selectRaw('DATE_FORMAT(entry_date, "%Y-%m") as month, SUM(amount) as total')
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
