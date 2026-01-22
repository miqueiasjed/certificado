<?php

namespace App\Http\Controllers;

use App\Models\FinancialEntry;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class FinancialWithdrawalController extends Controller
{
    /**
     * Display a listing of withdrawals.
     */
    public function index(Request $request)
    {
        $query = FinancialEntry::with(['workOrder', 'paymentDetail'])
            ->whereIn('source', ['payment_reopen', 'manual_withdrawal'])
            ->confirmed();

        // Apply filters
        if ($request->filled('start_date')) {
            $query->where('entry_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->where('entry_date', '<=', $request->end_date);
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('reference_number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $withdrawals = $query->orderBy('entry_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return inertia('FinancialWithdrawals/Index', [
            'withdrawals' => $withdrawals,
            'stats' => $this->getStats($request),
            'filters' => $request->only(['start_date', 'end_date', 'payment_method', 'search'])
        ]);
    }

    /**
     * Get withdrawal statistics.
     */
    public function getStats(Request $request): array
    {
        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', now()->endOfMonth()->toDateString());

        $baseQuery = FinancialEntry::confirmed()
            ->whereIn('source', ['payment_reopen', 'manual_withdrawal'])
            ->byDateRange($startDate, $endDate);

        // Apply additional filters
        if ($request->filled('payment_method')) {
            $baseQuery->where('payment_method', $request->payment_method);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $baseQuery->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                  ->orWhere('reference_number', 'like', "%{$search}%")
                  ->orWhere('notes', 'like', "%{$search}%");
            });
        }

        $totalWithdrawals = (clone $baseQuery)->sum('amount');
        $reopenAmount = (clone $baseQuery)->where('source', 'payment_reopen')->sum('amount');
        $manualAmount = (clone $baseQuery)->where('source', 'manual_withdrawal')->sum('amount');
        $totalEntries = (clone $baseQuery)->count();

        // Group by payment method
        $byMethod = (clone $baseQuery)->selectRaw('payment_method, SUM(amount) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method')
            ->toArray();

        return [
            'total_withdrawals' => $totalWithdrawals,
            'reopen_amount' => $reopenAmount,
            'manual_amount' => $manualAmount,
            'total_entries' => $totalEntries,
            'by_method' => $byMethod,
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate
            ]
        ];
    }

    /**
     * Store a newly created withdrawal.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:255',
            'entry_date' => 'required|date',
            'payment_method' => 'required|string|in:cash,pix,credit_card,debit_card,bank_transfer',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        $withdrawal = FinancialEntry::create([
            'source' => 'manual_withdrawal',
            'amount' => $request->amount,
            'description' => $request->description,
            'entry_date' => $request->entry_date,
            'payment_method' => $request->payment_method,
            'reference_number' => $request->reference_number,
            'notes' => $request->notes,
            'status' => 'confirmed',
            'created_by' => Auth::id(),
        ]);

        Log::info('Saída financeira criada manualmente', [
            'withdrawal_id' => $withdrawal->id,
            'amount' => $withdrawal->amount,
            'description' => $withdrawal->description,
            'created_by' => Auth::id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Saída financeira registrada com sucesso!',
            'withdrawal' => $withdrawal
        ]);
    }

    /**
     * Update the specified withdrawal.
     */
    public function update(Request $request, FinancialEntry $financialEntry): JsonResponse
    {
        // Only allow editing manual withdrawals
        if ($financialEntry->source !== 'manual_withdrawal') {
            return response()->json([
                'success' => false,
                'message' => 'Não é possível editar saídas financeiras geradas automaticamente por ordens de serviço.'
            ], 422);
        }

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:255',
            'entry_date' => 'required|date',
            'payment_method' => 'required|string|in:cash,pix,credit_card,debit_card,bank_transfer',
            'reference_number' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);

        $financialEntry->update($request->validated());

        Log::info('Saída financeira atualizada', [
            'withdrawal_id' => $financialEntry->id,
            'amount' => $financialEntry->amount,
            'description' => $financialEntry->description,
            'updated_by' => Auth::id()
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Saída financeira atualizada com sucesso!',
            'withdrawal' => $financialEntry
        ]);
    }

    /**
     * Remove the specified withdrawal.
     */
    public function destroy(FinancialEntry $financialEntry): JsonResponse
    {
        // Only allow deleting manual withdrawals
        if ($financialEntry->source !== 'manual_withdrawal') {
            return response()->json([
                'success' => false,
                'message' => 'Não é possível excluir saídas financeiras geradas automaticamente por ordens de serviço.'
            ], 422);
        }

        Log::info('Saída financeira excluída', [
            'withdrawal_id' => $financialEntry->id,
            'amount' => $financialEntry->amount,
            'description' => $financialEntry->description,
            'deleted_by' => Auth::id()
        ]);

        $financialEntry->delete();

        return response()->json([
            'success' => true,
            'message' => 'Saída financeira excluída com sucesso!'
        ]);
    }
}
