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
    public function index()
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

        // Estatísticas financeiras do mês atual
        $currentMonth = now()->startOfMonth()->toDateString();
        $endOfMonth = now()->endOfMonth()->toDateString();

        $financialStats = [
            'monthly_entries' => FinancialEntry::confirmed()
                ->whereIn('type', ['payment', 'manual'])
                ->whereBetween('entry_date', [$currentMonth, $endOfMonth])
                ->sum('amount'),
            'monthly_withdrawals' => FinancialEntry::confirmed()
                ->where('type', 'withdrawal')
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

        // Ordens de serviço recentes
        $recentWorkOrders = WorkOrder::with(['client'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return inertia('Dashboard', [
            'stats' => $stats,
            'financialStats' => $financialStats,
            'recentEntries' => $recentEntries,
            'recentServiceOrders' => $recentWorkOrders,
            'recentCertificates' => collect(), // Empty collection for now
        ]);
    }
}
