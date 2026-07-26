<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Services\TenantService;
use App\Services\TenantUsageService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página inicial da área da plataforma: os números gerais do negócio, para
 * o super admin decidir onde prestar atenção sem abrir tenant por tenant.
 *
 * Controller fino: cada número vem de um método de `TenantService` ou
 * `TenantUsageService` (Tasks 5.4, 5.6 e os métodos acrescentados na Task
 * 5.8). O único trabalho daqui é montar o formato de `labels`/`datasets`
 * que o `chart.js` do projeto consome (mesmo padrão de
 * `FinancialDashboardController::getChartData()`), o que é formatação de
 * apresentação, não regra de negócio.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly TenantService $tenantService,
        private readonly TenantUsageService $tenantUsageService,
    ) {}

    public function index(): Response
    {
        $entradas = $this->tenantService->entradasPorMes();

        return Inertia::render('Plataforma/Dashboard', [
            'estatisticas' => $this->tenantService->estatisticasDaPlataforma(),
            'entradasPorMesChart' => [
                'labels' => $entradas['labels'],
                'datasets' => [[
                    'label' => 'Novos tenants',
                    'data' => $entradas['valores'],
                    'borderColor' => '#10B981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'borderWidth' => 2,
                    'fill' => true,
                    'tension' => 0.4,
                ]],
            ],
            'semAcessoRecente' => $this->tenantService->semAcessoRecente(),
            'avaliacoesProximasDoFim' => $this->tenantService->avaliacoesProximasDoFim(),
            'armazenamentoTotalMb' => $this->tenantUsageService->armazenamentoTotalDaPlataforma(),
        ]);
    }
}
