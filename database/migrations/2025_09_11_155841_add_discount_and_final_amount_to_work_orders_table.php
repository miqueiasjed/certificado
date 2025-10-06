<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->decimal('discount_amount', 10, 2)->nullable()->after('total_cost'); // Desconto aplicado
            $table->decimal('final_amount', 10, 2)->nullable()->after('discount_amount'); // Valor final após desconto
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['discount_amount', 'final_amount']);
        });
    }
};
