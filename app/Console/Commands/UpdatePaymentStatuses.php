<?php

namespace App\Console\Commands;

use App\Models\PaymentDetail;
use App\Models\WorkOrder;
use Illuminate\Console\Command;

class UpdatePaymentStatuses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:update-statuses';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update all payment and work order statuses based on current data';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Updating payment statuses...');

        // Update payment details statuses
        $payments = PaymentDetail::all();
        foreach ($payments as $payment) {
            $workOrder = $payment->workOrder;
            if ($workOrder) {
                $amountPaid = $payment->amount_paid ?? 0;
                $finalAmount = $workOrder->final_amount ?? $workOrder->total_cost ?? 0;
                $paymentDate = $payment->payment_date;

                $status = 'pending';
                if ($amountPaid <= 0) {
                    if ($paymentDate && $paymentDate > now()->toDateString()) {
                        $status = 'pending';
                    } else {
                        $status = 'overdue';
                    }
                } else {
                    if ($amountPaid >= $finalAmount) {
                        $status = 'paid';
                    } else {
                        $status = 'partial';
                    }
                }

                $payment->update(['payment_status' => $status]);
                $this->line("Updated payment {$payment->id} to status: {$status}");
            }
        }

        // Update work order statuses
        $workOrders = WorkOrder::all();
        foreach ($workOrders as $workOrder) {
            $totalPaid = $workOrder->paymentDetails()->whereNotNull('payment_date')->sum('amount_paid');
            $finalAmount = $workOrder->final_amount ?? $workOrder->total_cost ?? 0;

            $status = 'pending';
            if ($finalAmount > 0) {
                if ($totalPaid >= $finalAmount) {
                    $status = 'paid';
                } elseif ($totalPaid > 0) {
                    $status = 'partial';
                } else {
                    $overduePayments = $workOrder->paymentDetails()
                        ->whereNull('payment_date')
                        ->where('payment_due_date', '<', now()->toDateString())
                        ->exists();
                    $status = $overduePayments ? 'overdue' : 'pending';
                }
            }

            $workOrder->update(['payment_status' => $status]);
            $this->line("Updated work order {$workOrder->id} to status: {$status}");
        }

        $this->info('All statuses updated successfully!');
    }
}
