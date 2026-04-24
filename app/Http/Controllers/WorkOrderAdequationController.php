<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkOrderAdequationRequest;
use App\Models\WorkOrder;
use App\Models\WorkOrderAdequation;
use App\Services\WorkOrderAdequationService;

class WorkOrderAdequationController extends Controller
{
    public function __construct(
        private WorkOrderAdequationService $service
    ) {}

    public function store(WorkOrderAdequationRequest $request, WorkOrder $workOrder)
    {
        $this->service->create($workOrder, $request->validated());

        return redirect()->back()->with('success', 'Adequação registrada com sucesso.');
    }

    public function update(WorkOrderAdequationRequest $request, WorkOrder $workOrder, WorkOrderAdequation $adequation)
    {
        $this->service->update($adequation, $request->validated());

        return redirect()->back()->with('success', 'Adequação atualizada com sucesso.');
    }

    public function destroy(WorkOrder $workOrder, WorkOrderAdequation $adequation)
    {
        $this->service->delete($adequation);

        return redirect()->back()->with('success', 'Adequação removida com sucesso.');
    }
}
