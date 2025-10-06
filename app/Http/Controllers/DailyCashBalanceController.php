<?php

namespace App\Http\Controllers;

use App\Models\DailyCashBalance;
use App\Models\FinancialEntry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Artisan;
use Carbon\Carbon;

class DailyCashBalanceController extends Controller
{
    /**
     * Display daily cash balances
     */
    public function index(Request $request)
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', now()->endOfMonth()->toDateString());

        $balances = DailyCashBalance::with('closedBy')
            ->byDateRange($startDate, $endDate)
            ->orderBy('balance_date', 'desc')
            ->paginate(15);

        $stats = $this->getStats($startDate, $endDate);

        return inertia('DailyCashBalances/Index', [
            'balances' => $balances,
            'stats' => $stats,
            'filters' => $request->only(['start_date', 'end_date'])
        ]);
    }

    /**
     * Get daily cash balance statistics
     */
    public function getStats(string $startDate, string $endDate): array
    {
        $balances = DailyCashBalance::byDateRange($startDate, $endDate)->get();

        return [
            'total_entries' => $balances->sum('total_entries'),
            'total_withdrawals' => $balances->sum('total_withdrawals'),
            'net_balance' => $balances->sum('total_entries') - $balances->sum('total_withdrawals'),
            'average_daily_balance' => $balances->avg('closing_balance'),
            'days_with_activity' => $balances->where('entries_count', '>', 0)->count() +
                                   $balances->where('withdrawals_count', '>', 0)->count(),
            'closed_days' => $balances->where('is_closed', true)->count(),
            'open_days' => $balances->where('is_closed', false)->count(),
        ];
    }

    /**
     * Get today's balance
     */
    public function getToday(): JsonResponse
    {
        $todayBalance = DailyCashBalance::getTodayBalance();
        $todayBalance->updateFromFinancialEntries();

        return response()->json([
            'success' => true,
            'balance' => $todayBalance->fresh()
        ]);
    }

    /**
     * Update today's balance
     */
    public function updateToday(Request $request): JsonResponse
    {
        $todayBalance = DailyCashBalance::getTodayBalance();
        $todayBalance->updateFromFinancialEntries();

        return response()->json([
            'success' => true,
            'message' => 'Saldo do dia atualizado com sucesso!',
            'balance' => $todayBalance->fresh()
        ]);
    }

    /**
     * Close a day
     */
    public function closeDay(Request $request, DailyCashBalance $balance): JsonResponse
    {
        if ($balance->is_closed) {
            return response()->json([
                'success' => false,
                'message' => 'Este dia já foi fechado.'
            ], 422);
        }

        $balance->closeDay(Auth::id(), $request->input('notes'));

        return response()->json([
            'success' => true,
            'message' => 'Dia fechado com sucesso!',
            'balance' => $balance->fresh()
        ]);
    }

    /**
     * Reopen a closed day
     */
    public function reopenDay(DailyCashBalance $balance): JsonResponse
    {
        if (!$balance->is_closed) {
            return response()->json([
                'success' => false,
                'message' => 'Este dia não está fechado.'
            ], 422);
        }

        $balance->update([
            'is_closed' => false,
            'closed_at' => null,
            'closed_by' => null,
            'notes' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Dia reaberto com sucesso!',
            'balance' => $balance->fresh()
        ]);
    }

    /**
     * Get daily cash flow data for charts
     */
    public function getDailyFlow(Request $request): JsonResponse
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', now()->endOfMonth()->toDateString());

        $balances = DailyCashBalance::byDateRange($startDate, $endDate)
            ->orderBy('balance_date')
            ->get();

        $labels = [];
        $entries = [];
        $withdrawals = [];
        $balances_data = [];

        foreach ($balances as $balance) {
            $labels[] = $balance->balance_date->format('d/m');
            $entries[] = $balance->total_entries;
            $withdrawals[] = $balance->total_withdrawals;
            $balances_data[] = $balance->closing_balance;
        }

        return response()->json([
            'success' => true,
            'data' => [
                'labels' => $labels,
                'datasets' => [
                    [
                        'label' => 'Entradas',
                        'data' => $entries,
                        'backgroundColor' => 'rgba(34, 197, 94, 0.2)',
                        'borderColor' => 'rgb(34, 197, 94)',
                        'borderWidth' => 2,
                        'fill' => false,
                    ],
                    [
                        'label' => 'Saídas',
                        'data' => $withdrawals,
                        'backgroundColor' => 'rgba(239, 68, 68, 0.2)',
                        'borderColor' => 'rgb(239, 68, 68)',
                        'borderWidth' => 2,
                        'fill' => false,
                    ],
                    [
                        'label' => 'Saldo',
                        'data' => $balances_data,
                        'backgroundColor' => 'rgba(59, 130, 246, 0.2)',
                        'borderColor' => 'rgb(59, 130, 246)',
                        'borderWidth' => 2,
                        'fill' => true,
                        'tension' => 0.4,
                    ]
                ]
            ]
        ]);
    }

    /**
     * Sync all daily balances
     */
    public function syncAll(): JsonResponse
    {
        try {
            Artisan::call('cash:sync-daily-balances');

            return response()->json([
                'success' => true,
                'message' => 'Sincronização concluída com sucesso!'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro na sincronização: ' . $e->getMessage()
            ], 500);
        }
    }
}
