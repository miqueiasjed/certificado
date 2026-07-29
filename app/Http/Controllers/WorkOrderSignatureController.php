<?php

namespace App\Http\Controllers;

use App\Http\Requests\CorrigirWorkOrderAssinadaRequest;
use App\Http\Requests\RecusarWorkOrderSignatureRequest;
use App\Http\Requests\StoreWorkOrderSignatureRequest;
use App\Models\WorkOrder;
use App\Services\WorkOrderAccessService;
use App\Services\WorkOrderService;
use App\Services\WorkOrderSignatureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Coleta, recusa e correção justificada da assinatura do cliente, pelo
 * painel (Plano 13, Task 13.4).
 *
 * A coleta feita pelo aplicativo em campo não passa por aqui: ela chega pelo
 * lote de sincronização, tratada por `App\Services\Sync\AplicadorDeAssinatura`
 * (Task 13.3), de propósito, para manter a mesma garantia de idempotência das
 * demais operações offline. Este controller cobre só a coleta feita no
 * computador do escritório, quando o cliente assina na tela do notebook.
 */
class WorkOrderSignatureController extends Controller
{
    public function __construct(
        private readonly WorkOrderSignatureService $workOrderSignatureService,
        private readonly WorkOrderAccessService $workOrderAccessService,
        private readonly WorkOrderService $workOrderService,
    ) {}

    /**
     * Coleta a assinatura do cliente feita no computador do escritório.
     */
    public function store(StoreWorkOrderSignatureRequest $request, WorkOrder $workOrder): RedirectResponse
    {
        $this->garantirAcesso($workOrder);

        $this->workOrderSignatureService->coletar(
            $workOrder,
            array_merge($request->validated(), ['origem' => 'web']),
            $request->user()
        );

        return back()->with('success', 'Assinatura registrada com sucesso.');
    }

    /**
     * Registra a recusa do cliente em assinar.
     */
    public function recusar(RecusarWorkOrderSignatureRequest $request, WorkOrder $workOrder): RedirectResponse
    {
        $this->garantirAcesso($workOrder);

        $this->workOrderSignatureService->registrarRecusa(
            $workOrder,
            $request->validated('motivo'),
            $request->user()
        );

        return back()->with('success', 'Recusa de assinatura registrada.');
    }

    /**
     * Correção justificada de uma ordem de serviço já assinada.
     */
    public function corrigir(CorrigirWorkOrderAssinadaRequest $request, WorkOrder $workOrder): RedirectResponse
    {
        $this->garantirAcesso($workOrder);

        $dadosAlterados = $request->safe()->except('justificativa');

        $this->workOrderSignatureService->corrigirComJustificativa(
            $workOrder,
            fn (WorkOrder $os) => $this->workOrderService->updateWorkOrder($os, $dadosAlterados),
            $request->validated('justificativa'),
            $request->user()
        );

        return back()->with('success', 'Ordem de serviço corrigida com sucesso.');
    }

    /**
     * Bloqueia o técnico que não está atribuído a esta ordem de serviço.
     */
    private function garantirAcesso(WorkOrder $workOrder): void
    {
        $usuario = Auth::user();

        if (! $usuario) {
            return;
        }

        $this->workOrderAccessService->garantirAcesso($workOrder, $usuario);
    }
}
