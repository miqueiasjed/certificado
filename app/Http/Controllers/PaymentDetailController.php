<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentDetailRequest;
use App\Models\FinancialEntry;
use App\Models\PaymentDetail;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class PaymentDetailController extends Controller
{
    /**
     * Store a newly created payment detail.
     */
    public function store(PaymentDetailRequest $request): JsonResponse
    {
        try {
            Log::info('PaymentDetail store - dados recebidos:', $request->all());
            Log::info('PaymentDetail store - work_order_id:', ['work_order_id' => $request->input('work_order_id')]);
            Log::info('PaymentDetail store - dados validados:', $request->validated());

            // Verificar se já existe parcela pendente com o mesmo valor para evitar duplicatas
            // Mas permitir criação automática (quando payment_notes contém "Parcela restante do pagamento original")
            if ($request->input('payment_status') === 'pending') {
                $isAutomaticCreation = str_contains($request->input('payment_notes', ''), 'Parcela restante do pagamento original');

                if (!$isAutomaticCreation) {
                    $existingPendingPayment = PaymentDetail::where('work_order_id', $request->input('work_order_id'))
                        ->where('payment_status', 'pending')
                        ->where('amount_paid', $request->input('amount_paid'))
                        ->first();

                    if ($existingPendingPayment) {
                        Log::info('Tentativa de criar parcela pendente duplicada bloqueada', [
                            'work_order_id' => $request->input('work_order_id'),
                            'amount_paid' => $request->input('amount_paid'),
                            'existing_payment_id' => $existingPendingPayment->id
                        ]);

                        return response()->json([
                            'success' => false,
                            'message' => 'Já existe uma parcela pendente com este valor para esta ordem de serviço.'
                        ], 422);
                    }
                } else {
                    Log::info('Criação automática de parcela pendente permitida', [
                        'work_order_id' => $request->input('work_order_id'),
                        'amount_paid' => $request->input('amount_paid'),
                        'payment_notes' => $request->input('payment_notes')
                    ]);
                }
            }

            $paymentDetail = PaymentDetail::create($request->validated());

            Log::info('PaymentDetail criado:', $paymentDetail->toArray());

            // Se o status não foi fornecido pelo usuário, calcular automaticamente
            if (!$request->has('payment_status') || empty($request->input('payment_status'))) {
                Log::info('Status não fornecido, calculando automaticamente');
                $this->updatePaymentStatusBasedOnAmount($paymentDetail);
            } else {
                Log::info('Status fornecido pelo usuário:', ['payment_status' => $request->input('payment_status')]);
            }

            // Atualizar o status de pagamento da ordem de serviço
            $this->updateWorkOrderPaymentStatus($paymentDetail->work_order_id);

            return response()->json([
                'success' => true,
                'message' => 'Pagamento registrado com sucesso!',
                'payment_detail' => $paymentDetail->load('workOrder')
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao registrar pagamento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified payment detail.
     */
    public function show(PaymentDetail $paymentDetail): JsonResponse
    {
        $paymentDetail->load('workOrder');

        return response()->json([
            'success' => true,
            'payment_detail' => $paymentDetail
        ]);
    }

    /**
     * Update the specified payment detail.
     */
    public function update(PaymentDetailRequest $request, PaymentDetail $paymentDetail): JsonResponse
    {
        try {
            // Verificar se o pagamento está pago e não está sendo reaberto
            if ($paymentDetail->payment_status === 'paid' && !$request->has('payment_status')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não é possível editar um pagamento confirmado. Use a opção "Reabrir Pagamento" se necessário.'
                ], 422);
            }

            // Capturar o status anterior para detectar mudanças
            $previousStatus = $paymentDetail->payment_status;

            // Validar se o valor pago é maior que zero quando status é 'paid'
            if ($request->input('payment_status') === 'paid' && $request->input('amount_paid', 0) <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Não é possível confirmar um pagamento com valor zero ou negativo.'
                ], 422);
            }

            $paymentDetail->update($request->validated());

            // Se o status não foi fornecido pelo usuário, calcular automaticamente
            if (!$request->has('payment_status') || empty($request->input('payment_status'))) {
                $this->updatePaymentStatusBasedOnAmount($paymentDetail);
            }

            // Atualizar o status de pagamento da ordem de serviço
            $this->updateWorkOrderPaymentStatus($paymentDetail->work_order_id);

            // Gerenciar entradas financeiras baseado na mudança de status
            $this->handleFinancialEntryStatusChange($paymentDetail, $previousStatus);

            return response()->json([
                'success' => true,
                'message' => 'Pagamento atualizado com sucesso!',
                'payment_detail' => $paymentDetail->load('workOrder')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar pagamento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified payment detail.
     */
    public function destroy(PaymentDetail $paymentDetail): JsonResponse
    {
        try {
            // Verificar se o pagamento está pago
            if ($paymentDetail->payment_status === 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Não é possível excluir um pagamento que já foi confirmado. Use a opção "Reabrir Pagamento" se necessário.'
                ], 422);
            }

            $workOrderId = $paymentDetail->work_order_id;

            // Verificar se já existe uma saída financeira para este pagamento (indicando que foi reaberto)
            $existingWithdrawal = FinancialEntry::where('payment_detail_id', $paymentDetail->id)
                ->where('source', 'payment_reopen')
                ->where('status', 'confirmed')
                ->first();

            // Só cancelar entrada financeira se NÃO foi reaberto (não tem saída correspondente)
            if (!$existingWithdrawal) {
                $this->cancelFinancialEntryFromPayment($paymentDetail);
                Log::info('Entrada financeira cancelada na exclusão de pagamento', [
                    'payment_detail_id' => $paymentDetail->id,
                    'work_order_id' => $workOrderId
                ]);
            } else {
                Log::info('Pagamento já foi reaberto, não cancelando entrada financeira na exclusão', [
                    'payment_detail_id' => $paymentDetail->id,
                    'work_order_id' => $workOrderId,
                    'existing_withdrawal_id' => $existingWithdrawal->id
                ]);
            }

            $paymentDetail->delete();

            // Atualizar o status de pagamento da ordem de serviço
            $this->updateWorkOrderPaymentStatus($workOrderId);

            return response()->json([
                'success' => true,
                'message' => 'Pagamento excluído com sucesso!'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao excluir pagamento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reabrir um pagamento (mudar status de 'paid' para 'pending')
     */
    public function reopen(PaymentDetail $paymentDetail): JsonResponse
    {
        try {
            // Verificar se o pagamento está realmente pago
            if ($paymentDetail->payment_status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Apenas pagamentos confirmados podem ser reabertos.'
                ], 422);
            }

            // Capturar o status anterior
            $previousStatus = $paymentDetail->payment_status;

            // Reabrir o pagamento (remover data de pagamento e zerar valor pago)
            $paymentDetail->update([
                'payment_status' => 'pending',
                'payment_date' => null,
                'amount_paid' => 0,
                'payment_notes' => ($paymentDetail->payment_notes ?? '') . ' [REABERTO EM ' . now()->format('d/m/Y H:i') . ']'
            ]);

            // Atualizar o status de pagamento da ordem de serviço
            $this->updateWorkOrderPaymentStatus($paymentDetail->work_order_id);

            // Cancelar entrada financeira (debitar das entradas financeiras)
            $this->cancelFinancialEntryFromPayment($paymentDetail);

            Log::info('Pagamento reaberto com sucesso', [
                'payment_detail_id' => $paymentDetail->id,
                'work_order_id' => $paymentDetail->work_order_id,
                'previous_status' => $previousStatus,
                'new_status' => 'pending'
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Pagamento reaberto com sucesso! O valor foi debitado das entradas financeiras.',
                'payment_detail' => $paymentDetail->load('workOrder')
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao reabrir pagamento', [
                'payment_detail_id' => $paymentDetail->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao reabrir pagamento: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment details for a specific work order.
     */
    public function getByWorkOrder(WorkOrder $workOrder): JsonResponse
    {
        $paymentDetails = $workOrder->paymentDetails()
            ->orderBy('payment_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'payment_details' => $paymentDetails
        ]);
    }

    /**
     * Update the payment status based on amount and dates.
     */
    private function updatePaymentStatusBasedOnAmount(PaymentDetail $paymentDetail): void
    {
        $workOrder = $paymentDetail->workOrder;
        if (!$workOrder) {
            return;
        }

        $amountPaid = $paymentDetail->amount_paid ?? 0;
        $finalAmount = $workOrder->final_amount ?? $workOrder->total_cost ?? 0;
        $paymentDate = $paymentDetail->payment_date;

        $status = 'pending';

        // Se não há valor pago
        if ($amountPaid <= 0) {
            // Se data é futura, é vencimento futuro
            if ($paymentDate && $paymentDate > now()->toDateString()) {
                $status = 'pending';
            } else {
                // Se data é passada/hoje e não há pagamento, está vencido
                $status = 'overdue';
            }
        } else {
            // Se há valor pago
            // Se valor pago >= valor final, está pago
            if ($amountPaid >= $finalAmount) {
                $status = 'paid';
            } else {
                // Se valor pago < valor final, é pagamento parcial
                $status = 'partial';
            }
        }

        // Atualizar o status do pagamento
        $paymentDetail->update(['payment_status' => $status]);
    }

    /**
     * Update the payment status of a work order based on payment details.
     */
    private function updateWorkOrderPaymentStatus(int $workOrderId): void
    {
        $workOrder = WorkOrder::find($workOrderId);
        if (!$workOrder) {
            return;
        }

        $totalPaid = $workOrder->paymentDetails()
            ->whereNotNull('payment_date')
            ->sum('amount_paid');

        $finalAmount = $workOrder->final_amount ?? $workOrder->total_cost ?? 0;

        if ($finalAmount <= 0) {
            $workOrder->update(['payment_status' => 'pending']);
            return;
        }

        // Calcular status baseado no total pago vs valor final
        if ($totalPaid >= $finalAmount) {
            $workOrder->update(['payment_status' => 'paid']);
        } elseif ($totalPaid > 0) {
            $workOrder->update(['payment_status' => 'partial']);
        } else {
            // Verificar se há pagamentos vencidos
            $overduePayments = $workOrder->paymentDetails()
                ->whereNull('payment_date')
                ->where('payment_due_date', '<', now()->toDateString())
                ->exists();

            if ($overduePayments) {
                $workOrder->update(['payment_status' => 'overdue']);
            } else {
                $workOrder->update(['payment_status' => 'pending']);
            }
        }
    }

    /**
     * Gerenciar mudanças de status das entradas financeiras
     */
    private function handleFinancialEntryStatusChange(PaymentDetail $paymentDetail, string $previousStatus): void
    {
        $currentStatus = $paymentDetail->payment_status;

        // Se mudou de 'paid' para outro status (reabertura)
        if ($previousStatus === 'paid' && $currentStatus !== 'paid') {
            $this->cancelFinancialEntryFromPayment($paymentDetail);
        }
        // Se mudou para 'paid' (baixa)
        elseif ($previousStatus !== 'paid' && $currentStatus === 'paid' && $paymentDetail->payment_date) {
            $this->createFinancialEntryFromPayment($paymentDetail);
        }
    }

    /**
     * Cancelar entrada financeira quando um pagamento é reaberto
     */
    private function cancelFinancialEntryFromPayment(PaymentDetail $paymentDetail): void
    {
        try {
            // Buscar a entrada financeira relacionada ao pagamento
            $financialEntry = FinancialEntry::where('payment_detail_id', $paymentDetail->id)
                ->where('source', 'work_order')
                ->where('status', 'confirmed')
                ->first();

            if (!$financialEntry) {
                Log::info('Nenhuma entrada financeira confirmada encontrada para cancelar', [
                    'payment_detail_id' => $paymentDetail->id
                ]);
                return;
            }

            // Criar uma retirada (withdrawal) para compensar a entrada anterior
            $withdrawalEntry = FinancialEntry::create([
                'source' => 'payment_reopen',
                'amount' => $financialEntry->amount,
                'description' => 'Retirada por reabertura de pagamento - OS #' . $paymentDetail->work_order_id,
                'entry_date' => now()->toDateString(), // Usar apenas a data, sem hora
                'payment_method' => $financialEntry->payment_method,
                'reference_number' => 'WITHDRAW-' . $paymentDetail->id . '-' . now()->format('YmdHis'),
                'notes' => 'Retirada automática devido à reabertura do pagamento #' . $paymentDetail->id,
                'status' => 'confirmed',
                'work_order_id' => $paymentDetail->work_order_id,
                'payment_detail_id' => $paymentDetail->id,
                'created_by' => Auth::id(),
            ]);

            Log::info('Retirada criada automaticamente para reabertura de pagamento', [
                'payment_detail_id' => $paymentDetail->id,
                'work_order_id' => $paymentDetail->work_order_id,
                'amount' => $financialEntry->amount,
                'original_entry_id' => $financialEntry->id,
                'withdrawal_entry_id' => $withdrawalEntry->id,
                'withdrawal_status' => $withdrawalEntry->status,
                'withdrawal_entry_date' => $withdrawalEntry->entry_date
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao criar retirada para reabertura de pagamento', [
                'payment_detail_id' => $paymentDetail->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Criar entrada financeira a partir de um pagamento
     */
    private function createFinancialEntryFromPayment(PaymentDetail $paymentDetail): void
    {
        try {
            // Buscar a última entrada de pagamento para este payment_detail_id
            $lastPaymentEntry = FinancialEntry::where('payment_detail_id', $paymentDetail->id)
                ->where('source', 'work_order')
                ->where('status', 'confirmed')
                ->orderBy('created_at', 'desc')
                ->first();

            // Buscar a última retirada para este payment_detail_id
            $lastWithdrawalEntry = FinancialEntry::where('payment_detail_id', $paymentDetail->id)
                ->where('source', 'payment_reopen')
                ->where('status', 'confirmed')
                ->orderBy('created_at', 'desc')
                ->first();

            // Se existe uma entrada de pagamento E não existe retirada, ou se a retirada é mais antiga que a entrada
            if ($lastPaymentEntry && (!$lastWithdrawalEntry || $lastWithdrawalEntry->created_at < $lastPaymentEntry->created_at)) {
                Log::info('Entrada financeira já existe para o pagamento e não foi compensada', [
                    'payment_detail_id' => $paymentDetail->id,
                    'last_payment_entry_id' => $lastPaymentEntry->id,
                    'last_withdrawal_entry_id' => $lastWithdrawalEntry?->id
                ]);
                return;
            }

            // Criar nova entrada financeira
            FinancialEntry::create([
                'source' => 'work_order',
                'amount' => $paymentDetail->amount_paid,
                'description' => 'Pagamento recebido - OS #' . $paymentDetail->work_order_id,
                'entry_date' => $paymentDetail->payment_date,
                'payment_method' => $paymentDetail->payment_method,
                'reference_number' => 'PAY-' . $paymentDetail->id . '-' . now()->format('YmdHis'),
                'notes' => $paymentDetail->payment_notes,
                'status' => 'confirmed',
                'work_order_id' => $paymentDetail->work_order_id,
                'payment_detail_id' => $paymentDetail->id,
                'created_by' => Auth::id(),
            ]);

            // Verificar se precisa criar parcela pendente
            $this->createPendingInstallmentIfNeeded($paymentDetail);

            Log::info('Entrada financeira criada automaticamente', [
                'payment_detail_id' => $paymentDetail->id,
                'work_order_id' => $paymentDetail->work_order_id,
                'amount' => $paymentDetail->amount_paid
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao criar entrada financeira automaticamente', [
                'payment_detail_id' => $paymentDetail->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Criar parcela pendente se o valor recebido for menor que o valor final
     */
    private function createPendingInstallmentIfNeeded(PaymentDetail $paymentDetail): void
    {
        try {
            $workOrder = $paymentDetail->workOrder;
            if (!$workOrder) {
                return;
            }

            $amountPaid = $paymentDetail->amount_paid;
            $finalAmount = $workOrder->final_amount ?? $workOrder->total_cost ?? 0;

            // Calcular o total já pago em todas as parcelas confirmadas
            $totalPaidSoFar = PaymentDetail::where('work_order_id', $workOrder->id)
                ->where('payment_status', 'paid')
                ->sum('amount_paid');

            $remainingAmount = $finalAmount - $totalPaidSoFar;

            Log::info('Cálculo de parcela pendente', [
                'payment_detail_id' => $paymentDetail->id,
                'amount_paid' => $amountPaid,
                'final_amount' => $finalAmount,
                'total_paid_so_far' => $totalPaidSoFar,
                'remaining_amount' => $remainingAmount
            ]);

            // Se não há valor restante, não precisa criar parcela
            if ($remainingAmount <= 0) {
                Log::info('Valor pago cobre o valor final, não é necessário criar parcela pendente', [
                    'payment_detail_id' => $paymentDetail->id,
                    'amount_paid' => $amountPaid,
                    'final_amount' => $finalAmount,
                    'remaining_amount' => $remainingAmount
                ]);
                return;
            }

            // Verificar se já existe uma parcela pendente para este valor restante
            $existingPendingPayment = PaymentDetail::where('work_order_id', $workOrder->id)
                ->where('payment_status', 'pending')
                ->where('amount_paid', $remainingAmount)
                ->where('payment_notes', 'like', '%Parcela pendente criada automaticamente%')
                ->first();

            if ($existingPendingPayment) {
                Log::info('Já existe parcela pendente automática para o valor restante', [
                    'payment_detail_id' => $paymentDetail->id,
                    'existing_payment_id' => $existingPendingPayment->id,
                    'remaining_amount' => $remainingAmount
                ]);
                return;
            }

            // Verificar se já existe qualquer parcela pendente (incluindo manuais)
            $anyPendingPayment = PaymentDetail::where('work_order_id', $workOrder->id)
                ->where('payment_status', 'pending')
                ->where('amount_paid', $remainingAmount)
                ->first();

            if ($anyPendingPayment) {
                Log::info('Já existe parcela pendente (manual ou automática) para o valor restante', [
                    'payment_detail_id' => $paymentDetail->id,
                    'existing_payment_id' => $anyPendingPayment->id,
                    'remaining_amount' => $remainingAmount,
                    'existing_payment_notes' => $anyPendingPayment->payment_notes
                ]);
                return;
            }

            // Criar nova parcela pendente
            $pendingPayment = PaymentDetail::create([
                'work_order_id' => $workOrder->id,
                'payment_due_date' => now()->addDays(30)->toDateString(), // 30 dias a partir de hoje
                'amount_paid' => $remainingAmount,
                'payment_method' => $paymentDetail->payment_method,
                'payment_status' => 'pending',
                'payment_notes' => 'Parcela pendente criada automaticamente - valor restante do pagamento parcial',
                'created_by' => Auth::id(),
            ]);

            Log::info('Parcela pendente criada automaticamente', [
                'original_payment_detail_id' => $paymentDetail->id,
                'new_payment_detail_id' => $pendingPayment->id,
                'work_order_id' => $workOrder->id,
                'remaining_amount' => $remainingAmount,
                'final_amount' => $finalAmount,
                'amount_paid' => $amountPaid
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao criar parcela pendente automaticamente', [
                'payment_detail_id' => $paymentDetail->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
