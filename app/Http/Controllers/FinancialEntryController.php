<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinancialEntryRequest;
use App\Models\FinancialEntry;
use App\Models\PaymentDetail;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class FinancialEntryController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = FinancialEntry::with(['workOrder', 'paymentDetail', 'createdBy'])
            ->orderBy('entry_date', 'desc');

        // Filtros
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('start_date')) {
            $query->where('entry_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->where('entry_date', '<=', $request->end_date);
        }

        $entries = $query->paginate(15);

        // Se for uma requisição AJAX, retorna JSON
        if ($request->wantsJson()) {
            return response()->json($entries);
        }

        // Senão, retorna a view Inertia
        return inertia('FinancialEntries/Index', [
            'entries' => $entries,
            'stats' => $this->getStatsData($request)
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(FinancialEntryRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $data['created_by'] = Auth::id();
            $data['status'] = $data['status'] ?? 'confirmed';

            $entry = FinancialEntry::create($data);

            return response()->json([
                'success' => true,
                'message' => 'Entrada financeira criada com sucesso!',
                'entry' => $entry->load(['workOrder', 'paymentDetail', 'createdBy'])
            ], 201);

        } catch (\Exception $e) {
            Log::error('Erro ao criar entrada financeira: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar entrada financeira: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(FinancialEntry $financialEntry)
    {
        $financialEntry->load(['workOrder', 'paymentDetail', 'createdBy']);
        return response()->json($financialEntry);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(FinancialEntryRequest $request, FinancialEntry $financialEntry): JsonResponse
    {
        try {
            // Verificar se a entrada veio de uma OS (não pode ser editada)
            if ($financialEntry->source === 'work_order' || $financialEntry->source === 'payment_reopen') {
                return response()->json([
                    'success' => false,
                    'message' => 'Não é possível editar entradas financeiras geradas automaticamente por ordens de serviço. Use a opção "Reabrir Pagamento" na OS correspondente.'
                ], 422);
            }

            $financialEntry->update($request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Entrada financeira atualizada com sucesso!',
                'entry' => $financialEntry->load(['workOrder', 'paymentDetail', 'createdBy'])
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao atualizar entrada financeira: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar entrada financeira: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(FinancialEntry $financialEntry): JsonResponse
    {
        try {
            // Verificar se a entrada veio de uma OS (não pode ser excluída)
            if ($financialEntry->source === 'work_order' || $financialEntry->source === 'payment_reopen') {
                return response()->json([
                    'success' => false,
                    'message' => 'Não é possível excluir entradas financeiras geradas automaticamente por ordens de serviço. Use a opção "Reabrir Pagamento" na OS correspondente.'
                ], 422);
            }

            $financialEntry->delete();

            return response()->json([
                'success' => true,
                'message' => 'Entrada financeira excluída com sucesso!'
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao excluir entrada financeira: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao excluir entrada financeira: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Criar entrada financeira a partir de um pagamento
     */
    public function createFromPayment(PaymentDetail $paymentDetail): JsonResponse
    {
        try {
            $entry = FinancialEntry::create([
                'type' => 'payment',
                'source' => 'work_order',
                'amount' => $paymentDetail->amount_paid,
                'description' => 'Pagamento recebido - OS #' . $paymentDetail->work_order_id,
                'entry_date' => $paymentDetail->payment_date ?? now()->toDateString(),
                'payment_method' => $paymentDetail->payment_method,
                'reference_number' => 'PAY-' . $paymentDetail->id,
                'notes' => $paymentDetail->payment_notes,
                'status' => 'confirmed',
                'work_order_id' => $paymentDetail->work_order_id,
                'payment_detail_id' => $paymentDetail->id,
                'created_by' => Auth::id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Entrada financeira criada a partir do pagamento!',
                'entry' => $entry->load(['workOrder', 'paymentDetail', 'createdBy'])
            ], 201);

        } catch (\Exception $e) {
            Log::error('Erro ao criar entrada financeira do pagamento: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar entrada financeira: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obter estatísticas das entradas financeiras
     */
    public function getStats(Request $request): JsonResponse
    {
        try {
            $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
            $endDate = $request->get('end_date', now()->endOfMonth()->toDateString());

            $query = FinancialEntry::confirmed()
                ->byDateRange($startDate, $endDate);

            // Log para debug - verificar filtros
            Log::info('Filtros aplicados', [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'confirmed_only' => true
            ]);

            $paymentAmount = FinancialEntry::confirmed()
                ->byDateRange($startDate, $endDate)
                ->byType('payment')
                ->sum('amount');

            $withdrawalAmount = FinancialEntry::confirmed()
                ->byDateRange($startDate, $endDate)
                ->byType('withdrawal')
                ->sum('amount');

            $manualAmount = FinancialEntry::confirmed()
                ->byDateRange($startDate, $endDate)
                ->byType('manual')
                ->sum('amount');

            $totalEntries = FinancialEntry::confirmed()
                ->byDateRange($startDate, $endDate)
                ->count();

            // Log para debug - verificar registros
            $allEntries = FinancialEntry::confirmed()
                ->byDateRange($startDate, $endDate)
                ->get();

            $paymentEntries = FinancialEntry::confirmed()
                ->byDateRange($startDate, $endDate)
                ->byType('payment')
                ->get();

            $withdrawalEntries = FinancialEntry::confirmed()
                ->byDateRange($startDate, $endDate)
                ->byType('withdrawal')
                ->get();

            Log::info('Registros encontrados', [
                'total_entries_count' => $allEntries->count(),
                'payment_entries_count' => $paymentEntries->count(),
                'withdrawal_entries_count' => $withdrawalEntries->count(),
                'all_entries' => $allEntries->map(function($entry) {
                    return [
                        'id' => $entry->id,
                        'type' => $entry->type,
                        'amount' => $entry->amount,
                        'entry_date' => $entry->entry_date,
                        'status' => $entry->status
                    ];
                }),
                'payment_entries' => $paymentEntries->pluck('type', 'amount'),
                'withdrawal_entries' => $withdrawalEntries->pluck('type', 'amount'),
            ]);

            // Calcular saldo líquido (entradas - saídas)
            $netAmount = $paymentAmount + $manualAmount - $withdrawalAmount;

            // Total recebido = saldo líquido (pagamentos + manuais - retiradas)
            $totalReceived = $netAmount;

            // Log para debug
            Log::info('Cálculo de estatísticas financeiras', [
                'payment_amount' => $paymentAmount,
                'withdrawal_amount' => $withdrawalAmount,
                'manual_amount' => $manualAmount,
                'net_amount' => $netAmount,
                'total_received' => $totalReceived,
                'total_entries' => $totalEntries
            ]);

            $byMethod = FinancialEntry::confirmed()
                ->byDateRange($startDate, $endDate)
                ->selectRaw('payment_method, SUM(amount) as total')
                ->groupBy('payment_method')
                ->get();

            return response()->json([
                'success' => true,
                'stats' => [
                    'total_received' => $totalReceived,
                    'payment_amount' => $paymentAmount,
                    'withdrawal_amount' => $withdrawalAmount,
                    'manual_amount' => $manualAmount,
                    'net_amount' => $netAmount,
                    'total_entries' => $totalEntries,
                    'by_method' => $byMethod,
                    'period' => [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao obter estatísticas: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao obter estatísticas: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obter dados de estatísticas para a view
     */
    private function getStatsData(Request $request): array
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', now()->endOfMonth()->toDateString());

        // Criar query base
        $baseQuery = FinancialEntry::confirmed()->byDateRange($startDate, $endDate);

        // Aplicar filtros se existirem
        if ($request->filled('type')) {
            $baseQuery->where('type', $request->type);
        }

        if ($request->filled('status')) {
            $baseQuery->where('status', $request->status);
        }

        // Criar queries independentes para cada cálculo
        $paymentAmount = (clone $baseQuery)->byType('payment')->sum('amount');
        $withdrawalAmount = (clone $baseQuery)->byType('withdrawal')->sum('amount');
        $manualAmount = (clone $baseQuery)->byType('manual')->sum('amount');
        $totalEntries = (clone $baseQuery)->count();

        // Calcular pagamentos efetivamente recebidos (não compensados por retiradas)
        $effectivePaymentAmount = $this->calculateEffectivePayments($startDate, $endDate, $request);

        $netAmount = $paymentAmount + $manualAmount - $withdrawalAmount;

        // Total recebido = saldo líquido (pagamentos + manuais - retiradas)
        $totalReceived = $netAmount;

        // Log para debug
        Log::info('Cálculo de estatísticas (getStatsData)', [
            'payment_amount' => $paymentAmount,
            'effective_payment_amount' => $effectivePaymentAmount,
            'withdrawal_amount' => $withdrawalAmount,
            'manual_amount' => $manualAmount,
            'net_amount' => $netAmount,
            'total_received' => $totalReceived,
            'total_entries' => $totalEntries
        ]);

        return [
            'total_received' => $totalReceived,
            'payment_amount' => $effectivePaymentAmount, // Usar pagamentos efetivos
            'withdrawal_amount' => $withdrawalAmount,
            'manual_amount' => $manualAmount,
            'net_amount' => $netAmount,
            'total_entries' => $totalEntries,
        ];
    }

    /**
     * Calcular pagamentos efetivamente recebidos (não compensados por retiradas)
     */
    private function calculateEffectivePayments(string $startDate, string $endDate, Request $request): float
    {
        // Buscar todos os pagamentos no período
        $payments = FinancialEntry::confirmed()
            ->byDateRange($startDate, $endDate)
            ->byType('payment')
            ->get();

        // Aplicar filtros se existirem
        if ($request->filled('type') && $request->type !== 'payment') {
            return 0;
        }

        if ($request->filled('status') && $request->status !== 'confirmed') {
            return 0;
        }

        $effectiveAmount = 0;

        foreach ($payments as $payment) {
            // Verificar se este pagamento foi compensado por uma retirada
            $hasWithdrawal = FinancialEntry::confirmed()
                ->byDateRange($startDate, $endDate)
                ->byType('withdrawal')
                ->where('payment_detail_id', $payment->payment_detail_id)
                ->where('created_at', '>', $payment->created_at) // Retirada deve ser posterior ao pagamento
                ->exists();

            // Se não tem retirada correspondente, conta como efetivo
            if (!$hasWithdrawal) {
                $effectiveAmount += $payment->amount;
            }
        }

        Log::info('Cálculo de pagamentos efetivos', [
            'total_payments' => $payments->count(),
            'effective_amount' => $effectiveAmount,
            'start_date' => $startDate,
            'end_date' => $endDate
        ]);

        return $effectiveAmount;
    }
}
