<?php

namespace App\Http\Controllers;

use App\Models\FinancialEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CashFlowController extends Controller
{
    /**
     * Display cash flow with complete history.
     */
    public function index(Request $request)
    {
        $query = FinancialEntry::with(['workOrder', 'paymentDetail'])
            ->confirmed();

        // Apply filters
        if ($request->filled('start_date')) {
            $query->where('entry_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->where('entry_date', '<=', $request->end_date);
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('reference_number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $entries = $query->orderBy('entry_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        // Get comprehensive stats
        $stats = $this->getStats($request);

        return inertia('CashFlow/Index', [
            'entries' => $entries,
            'stats' => $stats,
            'filters' => $request->only(['start_date', 'end_date', 'payment_method', 'search'])
        ]);
    }

    /**
     * Get comprehensive cash flow statistics.
     */
    public function getStats(Request $request): array
    {
        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', now()->endOfMonth()->toDateString());

        // Ensure dates are valid strings
        if (empty($startDate)) {
            $startDate = now()->startOfMonth()->toDateString();
        }
        if (empty($endDate)) {
            $endDate = now()->endOfMonth()->toDateString();
        }

        $baseQuery = FinancialEntry::confirmed()->byDateRange($startDate, $endDate);

        // Apply additional filters
        if ($request->filled('payment_method')) {
            $baseQuery->where('payment_method', $request->payment_method);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $baseQuery->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('reference_number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        // Calculate different amounts
        $paymentAmount = (clone $baseQuery)->bySource('work_order')->sum('amount');
        $withdrawalAmount = (clone $baseQuery)->whereIn('source', ['payment_reopen', 'manual_withdrawal'])->sum('amount');
        $manualAmount = (clone $baseQuery)->bySource('manual')->sum('amount');
        $totalEntries = (clone $baseQuery)->count();

        // Calculate effective payments (payments not compensated by withdrawals)
        $effectivePaymentAmount = $this->calculateEffectivePayments($startDate, $endDate, $request);

        // Calculate net amount
        $netAmount = $paymentAmount + $manualAmount - $withdrawalAmount;

        // Group by type
        $byType = [
            'payment' => (clone $baseQuery)->bySource('work_order')->sum('amount'),
            'withdrawal' => (clone $baseQuery)->whereIn('source', ['payment_reopen', 'manual_withdrawal'])->sum('amount'),
            'manual' => (clone $baseQuery)->bySource('manual')->sum('amount'),
        ];

        // Group by payment method
        $byMethod = (clone $baseQuery)->selectRaw('payment_method, SUM(amount) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method')
            ->toArray();

        // Daily breakdown for the period
        $dailyBreakdown = $this->getDailyBreakdown($startDate, $endDate, $request);

        return [
            'total_received' => $netAmount,
            'effective_payment_amount' => $effectivePaymentAmount,
            'payment_amount' => $paymentAmount,
            'withdrawal_amount' => $withdrawalAmount,
            'manual_amount' => $manualAmount,
            'net_amount' => $netAmount,
            'total_entries' => $totalEntries,
            'by_type' => $byType,
            'by_method' => $byMethod,
            'daily_breakdown' => $dailyBreakdown,
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate
            ]
        ];
    }

    /**
     * Calculate effective payments (payments not compensated by withdrawals).
     */
    private function calculateEffectivePayments(string $startDate, string $endDate, Request $request): float
    {
        $payments = FinancialEntry::confirmed()
            ->byDateRange($startDate, $endDate)
            ->bySource('work_order')
            ->get();

        $effectiveAmount = 0;

        foreach ($payments as $payment) {
            // Check if this payment has a corresponding withdrawal
            $hasWithdrawal = FinancialEntry::confirmed()
                ->byDateRange($startDate, $endDate)
                ->bySource('payment_reopen')
                ->where('payment_detail_id', $payment->payment_detail_id)
                ->where('created_at', '>', $payment->created_at)
                ->exists();

            if (!$hasWithdrawal) {
                $effectiveAmount += $payment->amount;
            }
        }

        return $effectiveAmount;
    }

    /**
     * Get daily breakdown of cash flow.
     */
    private function getDailyBreakdown(string $startDate, string $endDate, Request $request): array
    {
        $baseQuery = FinancialEntry::confirmed()
            ->byDateRange($startDate, $endDate);

        // Apply additional filters
        if ($request->filled('payment_method')) {
            $baseQuery->where('payment_method', $request->payment_method);
        }

        $dailyData = (clone $baseQuery)
            ->selectRaw('entry_date, source, SUM(amount) as total')
            ->groupBy('entry_date', 'source')
            ->orderBy('entry_date', 'desc')
            ->get()
            ->groupBy('entry_date')
            ->map(function ($dayEntries) {
                $dayData = [
                    'date' => $dayEntries->first()->entry_date,
                    'payments' => 0,
                    'withdrawals' => 0,
                    'manual' => 0,
                    'net' => 0
                ];

                foreach ($dayEntries as $entry) {
                    if ($entry->source === 'work_order') {
                        $dayData['payments'] += $entry->total;
                    } elseif ($entry->source === 'manual') {
                        $dayData['manual'] += $entry->total;
                    } elseif (in_array($entry->source, ['payment_reopen', 'manual_withdrawal'], true)) {
                        $dayData['withdrawals'] += $entry->total;
                    }
                }

                $dayData['net'] = $dayData['payments'] + $dayData['manual'] - $dayData['withdrawals'];

                return $dayData;
            })
            ->values()
            ->toArray();

        return $dailyData;
    }

    /**
     * Export cash flow data.
     */
    public function export(Request $request)
    {
        // This could be implemented to export to CSV/Excel
        // For now, just return the data
        $stats = $this->getStats($request);

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}
