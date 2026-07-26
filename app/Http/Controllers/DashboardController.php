<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\WorkOrder;
use App\Models\FinancialEntry;
use App\Models\DailyCashBalance;
use App\Models\Product;
use App\Models\Technician;
use App\Models\Service;
use App\Models\Certificate;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Display the main dashboard.
     */
    public function index(Request $request)
    {
        // Estatísticas gerais
        $stats = [
            'total_clients' => Client::count(),
            'total_products' => Product::count(),
            'total_technicians' => Technician::count(),
            'total_services' => Service::count(),
            'total_work_orders' => WorkOrder::count(),
            'total_certificates' => Certificate::count(),
            'pending_work_orders' => WorkOrder::where('status', 'pending')->count(),
            'completed_work_orders' => WorkOrder::where('status', 'completed')->count(),
        ];

        // Dados financeiros só são calculados e enviados para quem tem
        // permissão de ver o financeiro. Sem a permissão, nem as somas rodam.
        $financialStats = null;
        $recentEntries = null;

        $podeVerFinanceiro = $request->user()->can('financeiro-ver');

        if ($podeVerFinanceiro) {
            // Estatísticas financeiras do mês atual
            $currentMonth = now()->startOfMonth()->toDateString();
            $endOfMonth = now()->endOfMonth()->toDateString();

            $financialStats = [
                'monthly_entries' => FinancialEntry::confirmed()
                    ->whereIn('source', ['work_order', 'manual'])
                    ->whereBetween('entry_date', [$currentMonth, $endOfMonth])
                    ->sum('amount'),
                'monthly_withdrawals' => FinancialEntry::confirmed()
                    ->whereIn('source', ['payment_reopen', 'manual_withdrawal'])
                    ->whereBetween('entry_date', [$currentMonth, $endOfMonth])
                    ->sum('amount'),
                'current_balance' => DailyCashBalance::getTodayBalance()->closing_balance,
            ];

            // Calcular saldo líquido do mês
            $financialStats['monthly_net'] = $financialStats['monthly_entries'] - $financialStats['monthly_withdrawals'];

            // Entradas recentes
            $recentEntries = FinancialEntry::with(['workOrder', 'paymentDetail'])
                ->confirmed()
                ->orderBy('entry_date', 'desc')
                ->limit(5)
                ->get();
        }

        // Ordens de serviço recentes
        $recentWorkOrders = WorkOrder::with(['client'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        // A ordem de serviço carrega valores (total_cost, final_amount,
        // total_paid, etc.). Sem a permissão de financeiro, esses campos
        // também não podem ir para o front, mesmo que hoje não sejam exibidos.
        if (! $podeVerFinanceiro) {
            $recentWorkOrders->makeHidden([
                'total_cost',
                'discount_amount',
                'final_amount',
                'total_paid',
                'remaining_amount',
                'effective_amount',
                'payment_status',
                'payment_status_text',
                'payment_status_color',
            ]);
        }

        return inertia('Dashboard', [
            'stats' => $stats,
            'financialStats' => $financialStats,
            'recentEntries' => $recentEntries,
            'recentServiceOrders' => $recentWorkOrders,
            'recentCertificates' => collect(), // Empty collection for now
        ]);
    }
}
