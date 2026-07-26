<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use App\Services\WorkOrderAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WorkOrderFinancialController extends Controller
{
    public function __construct(
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

    /**
     * Update financial information for a work order.
     */
    public function updateFinancialInfo(Request $request, WorkOrder $workOrder): JsonResponse
    {
        $this->garantirAcesso($workOrder);

        $validated = $request->validate([
            'total_cost' => 'required|numeric|min:0|max:999999.99',
            'discount_amount' => 'nullable|numeric|min:0|max:999999.99',
            'final_amount' => 'nullable|numeric|min:0|max:999999.99',
            'payment_due_date' => 'nullable|date',
        ], [
            'total_cost.required' => 'O custo total é obrigatório.',
            'total_cost.numeric' => 'O custo total deve ser um número.',
            'total_cost.min' => 'O custo total deve ser maior ou igual a zero.',
            'total_cost.max' => 'O custo total não pode ser maior que 999.999,99.',
            'discount_amount.numeric' => 'O desconto deve ser um número.',
            'discount_amount.min' => 'O desconto deve ser maior ou igual a zero.',
            'discount_amount.max' => 'O desconto não pode ser maior que 999.999,99.',
            'final_amount.numeric' => 'O valor final deve ser um número.',
            'final_amount.min' => 'O valor final deve ser maior ou igual a zero.',
            'final_amount.max' => 'O valor final não pode ser maior que 999.999,99.',
            'payment_due_date.date' => 'A data de vencimento deve ser uma data válida.',
        ]);

        try {
            $workOrder->update($validated);

            return response()->json([
                'success' => true,
                'message' => 'Informações financeiras atualizadas com sucesso!',
                'work_order' => $workOrder->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar informações financeiras: ' . $e->getMessage()
            ], 500);
        }
    }
}
