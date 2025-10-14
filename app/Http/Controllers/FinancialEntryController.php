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
        // Only show entries (payments and manual entries), not withdrawals
        $query = FinancialEntry::with(['workOrder', 'paymentDetail', 'createdBy'])
            ->whereIn('type', ['payment', 'manual'])
            ->confirmed()
            ->orderBy('entry_date', 'desc');

        // Filtros
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('start_date')) {
            $query->where('entry_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->where('entry_date', '<=', $request->end_date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('reference_number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $entries = $query->paginate(15);

        // Se for uma requisição AJAX, retorna JSON
        if ($request->wantsJson()) {
            return response()->json($entries);
        }

        // Senão, retorna a view Inertia
        return inertia('FinancialEntries/Index', [
            'entries' => $entries,
            'stats' => $this->getStatsData($request),
            'filters' => $request->only(['type', 'source', 'payment_method', 'start_date', 'end_date', 'search'])
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(FinancialEntryRequest $request)
    {
        try {
            $data = $request->validated();
            $data['created_by'] = Auth::id();
            $data['status'] = $data['status'] ?? 'confirmed';

            $entry = FinancialEntry::create($data);

            return redirect()->route('financial-entries.index')
                ->with('success', 'Entrada financeira criada com sucesso!');

        } catch (\Exception $e) {
            Log::error('Erro ao criar entrada financeira: ' . $e->getMessage());
            return redirect()->back()
                ->withErrors(['error' => 'Erro ao criar entrada financeira: ' . $e->getMessage()])
                ->withInput();
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
    public function update(FinancialEntryRequest $request, FinancialEntry $financialEntry)
    {
        try {
            // Verificar se a entrada veio de uma OS (não pode ser editada)
            if ($financialEntry->source === 'work_order' || $financialEntry->source === 'payment_reopen') {
                return redirect()->back()
                    ->withErrors(['error' => 'Não é possível editar entradas financeiras geradas automaticamente por ordens de serviço. Use a opção "Reabrir Pagamento" na OS correspondente.']);
            }

            $financialEntry->update($request->validated());

            return redirect()->route('financial-entries.index')
                ->with('success', 'Entrada financeira atualizada com sucesso!');

        } catch (\Exception $e) {
            Log::error('Erro ao atualizar entrada financeira: ' . $e->getMessage());
            return redirect()->back()
                ->withErrors(['error' => 'Erro ao atualizar entrada financeira: ' . $e->getMessage()])
                ->withInput();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(FinancialEntry $financialEntry)
    {
        try {
            // Verificar se a entrada veio de uma OS (não pode ser excluída)
            if ($financialEntry->source === 'work_order' || $financialEntry->source === 'payment_reopen') {
                return redirect()->back()
                    ->withErrors(['error' => 'Não é possível excluir entradas financeiras geradas automaticamente por ordens de serviço. Use a opção "Reabrir Pagamento" na OS correspondente.']);
            }

            $financialEntry->delete();

            return redirect()->route('financial-entries.index')
                ->with('success', 'Entrada financeira excluída com sucesso!');

        } catch (\Exception $e) {
            Log::error('Erro ao excluir entrada financeira: ' . $e->getMessage());
            return redirect()->back()
                ->withErrors(['error' => 'Erro ao excluir entrada financeira: ' . $e->getMessage()]);
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

            // Only show entries (payments and manual entries), not withdrawals
            $query = FinancialEntry::confirmed()
                ->whereIn('type', ['payment', 'manual'])
                ->byDateRange($startDate, $endDate);

            // Log para debug - verificar filtros
            Log::info('Filtros aplicados - Entradas Financeiras', [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'confirmed_only' => true,
                'types' => ['payment', 'manual']
            ]);

            $paymentAmount = FinancialEntry::confirmed()
                ->byDateRange($startDate, $endDate)
                ->byType('payment')
                ->sum('amount');

            $manualAmount = FinancialEntry::confirmed()
                ->byDateRange($startDate, $endDate)
                ->byType('manual')
                ->sum('amount');

            $totalEntries = FinancialEntry::confirmed()
                ->whereIn('type', ['payment', 'manual'])
                ->byDateRange($startDate, $endDate)
                ->count();

            // Log para debug - verificar registros
            $allEntries = FinancialEntry::confirmed()
                ->whereIn('type', ['payment', 'manual'])
                ->byDateRange($startDate, $endDate)
                ->get();

            $paymentEntries = FinancialEntry::confirmed()
                ->byDateRange($startDate, $endDate)
                ->byType('payment')
                ->get();

            $manualEntries = FinancialEntry::confirmed()
                ->byDateRange($startDate, $endDate)
                ->byType('manual')
                ->get();

            Log::info('Registros encontrados - Entradas Financeiras', [
                'total_entries_count' => $allEntries->count(),
                'payment_entries_count' => $paymentEntries->count(),
                'manual_entries_count' => $manualEntries->count(),
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
                'manual_entries' => $manualEntries->pluck('type', 'amount'),
            ]);

            // Total de entradas (pagamentos + manuais)
            $totalEntryAmount = $paymentAmount + $manualAmount;

            // Log para debug
            Log::info('Cálculo de estatísticas - Entradas Financeiras', [
                'payment_amount' => $paymentAmount,
                'manual_amount' => $manualAmount,
                'total_entry_amount' => $totalEntryAmount,
                'total_entries' => $totalEntries
            ]);

            $byMethod = FinancialEntry::confirmed()
                ->whereIn('type', ['payment', 'manual'])
                ->byDateRange($startDate, $endDate)
                ->selectRaw('payment_method, SUM(amount) as total')
                ->groupBy('payment_method')
                ->pluck('total', 'payment_method')
                ->toArray();

            return response()->json([
                'success' => true,
                'stats' => [
                    'total_entries' => $totalEntryAmount,
                    'payment_amount' => $paymentAmount,
                    'manual_amount' => $manualAmount,
                    'total_count' => $totalEntries,
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

        // Only show entries (payments and manual entries), not withdrawals
        $baseQuery = FinancialEntry::confirmed()
            ->whereIn('type', ['payment', 'manual'])
            ->byDateRange($startDate, $endDate);

        // Aplicar filtros se existirem
        if ($request->filled('type')) {
            $baseQuery->where('type', $request->type);
        }

        if ($request->filled('status')) {
            $baseQuery->where('status', $request->status);
        }

        // Criar queries independentes para cada cálculo
        $paymentAmount = (clone $baseQuery)->byType('payment')->sum('amount');
        $manualAmount = (clone $baseQuery)->byType('manual')->sum('amount');
        $totalEntries = (clone $baseQuery)->count();

        // Total de entradas (pagamentos + manuais)
        $totalEntryAmount = $paymentAmount + $manualAmount;

        // Log para debug
        Log::info('Cálculo de estatísticas (getStatsData) - Entradas Financeiras', [
            'payment_amount' => $paymentAmount,
            'manual_amount' => $manualAmount,
            'total_entry_amount' => $totalEntryAmount,
            'total_entries' => $totalEntries
        ]);

        return [
            'total_entries' => $totalEntryAmount,
            'payment_amount' => $paymentAmount,
            'manual_amount' => $manualAmount,
            'total_count' => $totalEntries,
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
