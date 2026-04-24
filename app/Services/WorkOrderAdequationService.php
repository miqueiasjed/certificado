<?php

namespace App\Services;

use App\Models\WorkOrder;
use App\Models\WorkOrderAdequation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class WorkOrderAdequationService
{
    public function create(WorkOrder $workOrder, array $data): WorkOrderAdequation
    {
        $this->ensureWorkOrderIsEditable($workOrder);

        return $workOrder->adequations()->create($data);
    }

    public function update(WorkOrderAdequation $adequation, array $data): WorkOrderAdequation
    {
        $this->ensureWorkOrderIsEditable($adequation->workOrder);

        $adequation->update($data);

        return $adequation->fresh();
    }

    public function delete(WorkOrderAdequation $adequation): void
    {
        $this->ensureWorkOrderIsEditable($adequation->workOrder);

        $adequation->delete();
    }

    public function getByWorkOrder(WorkOrder $workOrder): Collection
    {
        return $workOrder->adequations()
            ->orderByRaw("FIELD(priority, 'alta', 'media', 'baixa')")
            ->orderBy('status')
            ->orderBy('deadline')
            ->get();
    }

    public function getPendingByWorkOrder(WorkOrder $workOrder): Collection
    {
        return $workOrder->adequations()
            ->where('status', 'pendente')
            ->orderByRaw("FIELD(priority, 'alta', 'media', 'baixa')")
            ->orderBy('deadline')
            ->get();
    }

    // Não permite criar ou editar adequações em OS cancelada
    private function ensureWorkOrderIsEditable(WorkOrder $workOrder): void
    {
        if ($workOrder->status === 'cancelled') {
            throw ValidationException::withMessages([
                'work_order' => 'Não é possível gerenciar adequações em uma ordem de serviço cancelada.',
            ]);
        }
    }
}
