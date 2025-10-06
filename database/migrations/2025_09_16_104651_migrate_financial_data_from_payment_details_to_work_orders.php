<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Migrar dados financeiros da tabela payment_details para work_orders
        $paymentDetails = DB::table('payment_details')
            ->select('work_order_id', 'total_cost', 'discount_amount', 'final_amount', 'payment_due_date')
            ->whereNotNull('total_cost')
            ->get();

        foreach ($paymentDetails as $detail) {
            DB::table('work_orders')
                ->where('id', $detail->work_order_id)
                ->update([
                    'total_cost' => $detail->total_cost,
                    'discount_amount' => $detail->discount_amount,
                    'final_amount' => $detail->final_amount,
                    'payment_due_date' => $detail->payment_due_date,
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Limpar os campos financeiros da tabela work_orders
        DB::table('work_orders')->update([
            'total_cost' => null,
            'discount_amount' => null,
            'final_amount' => null,
            'payment_due_date' => null,
        ]);
    }
};
