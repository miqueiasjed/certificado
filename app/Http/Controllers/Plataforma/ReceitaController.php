<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Services\InvoiceService;
use App\Services\SubscriptionService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel de receita da plataforma (Plano 7, Task 7.8): os números que o super
 * admin usa para decidir onde prestar atenção no negócio de assinatura, sem
 * abrir tenant por tenant.
 *
 * Controller fino, mesmo padrão de `Plataforma\DashboardController`: cada
 * número vem de um método de `SubscriptionService` ou `InvoiceService`
 * (acrescentados nesta mesma task), que já carregam a exclusão do tenant
 * interno. O único trabalho daqui é montar o formato de `labels`/`datasets`
 * que o `chart.js` do projeto consome, o que é formatação de apresentação,
 * não regra de negócio.
 *
 * Dentro do grupo `Route::prefix('plataforma')->middleware(['auth',
 * 'platform.admin'])`: só o super admin abre este painel.
 */
class ReceitaController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly InvoiceService $invoiceService,
    ) {}

    public function index(): Response
    {
        $cancelamentos = $this->subscriptionService->cancelamentosPorMes();

        return Inertia::render('Plataforma/Receita', [
            'assinaturas' => $this->subscriptionService->contadoresPorSituacao(),
            'receitaRecorrenteMensal' => $this->subscriptionService->receitaRecorrenteMensal(),
            'faturasEmAberto' => $this->invoiceService->faturasEmAberto(),
            // Tolerância de dias antes da suspensão por inadimplência
            // (Task 7.7): a tela de receita usa este número para marcar em
            // vermelho a fatura além da tolerância, e lê daqui em vez de
            // fixar "5" sozinha, para não divergir se a config mudar.
            'diasDeTolerancia' => (int) config('assinatura.dias_de_tolerancia', 5),
            'cancelamentosPorMesChart' => [
                'labels' => $cancelamentos['labels'],
                'datasets' => [[
                    'label' => 'Cancelamentos',
                    'data' => $cancelamentos['valores'],
                    'borderColor' => '#EF4444',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                    'borderWidth' => 2,
                    'fill' => true,
                    'tension' => 0.4,
                ]],
            ],
        ]);
    }
}
