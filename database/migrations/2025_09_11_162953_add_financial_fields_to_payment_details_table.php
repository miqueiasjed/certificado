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
        Schema::table('payment_details', function (Blueprint $table) {
            $table->decimal('total_cost', 10, 2)->nullable()->after('work_order_id');
            $table->decimal('discount_amount', 10, 2)->nullable()->after('total_cost');
            $table->decimal('final_amount', 10, 2)->nullable()->after('discount_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_details', function (Blueprint $table) {
            $table->dropColumn(['total_cost', 'discount_amount', 'final_amount']);
        });
    }
};
