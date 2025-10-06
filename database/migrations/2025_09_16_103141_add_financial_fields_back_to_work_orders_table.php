<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->decimal('total_cost', 10, 2)->nullable()->after('materials_used');
            $table->decimal('discount_amount', 10, 2)->nullable()->after('total_cost');
            $table->decimal('final_amount', 10, 2)->nullable()->after('discount_amount');
            $table->date('payment_due_date')->nullable()->after('final_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['total_cost', 'discount_amount', 'final_amount', 'payment_due_date']);
        });
    }
};
