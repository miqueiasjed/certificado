<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkOrderAdequationRequest;
use App\Models\WorkOrder;
use App\Models\WorkOrderAdequation;
use App\Services\WorkOrderAdequationService;
use App\Services\WorkOrderAccessService;
use Illuminate\Support\Facades\Auth;

class WorkOrderAdequationController extends Controller
{
    public function __construct(
        private WorkOrderAdequationService $service,
        private WorkOrderAccessService $workOrderAccessService
    ) {}

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

    public function store(WorkOrderAdequationRequest $request, WorkOrder $workOrder)
    {
        $this->garantirAcesso($workOrder);

        $this->service->create($workOrder, $request->validated());

        return redirect()->back()->with('success', 'Adequação registrada com sucesso.');
    }

    public function update(WorkOrderAdequationRequest $request, WorkOrder $workOrder, WorkOrderAdequation $adequation)
    {
        $this->garantirAcesso($workOrder);

        $this->service->update($adequation, $request->validated());

        return redirect()->back()->with('success', 'Adequação atualizada com sucesso.');
    }

    public function destroy(WorkOrder $workOrder, WorkOrderAdequation $adequation)
    {
        $this->garantirAcesso($workOrder);

        $this->service->delete($adequation);

        return redirect()->back()->with('success', 'Adequação removida com sucesso.');
    }
}
