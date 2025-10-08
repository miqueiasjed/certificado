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
        Schema::table('service_orders', function (Blueprint $table) {
            // Primeiro, adicionar a nova coluna service_type_id
            $table->foreignId('service_type_id')->nullable()->constrained('service_types')->after('order_date');

            // Depois, remover a coluna service_type antiga (ENUM)
            $table->dropColumn('service_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            // Restaurar a coluna service_type como ENUM
            $table->enum('service_type', [
                'dedetizacao',
                'desinsetizacao',
                'descupinizacao',
                'desratizacao',
                'sanitizacao'
            ])->after('order_date');

            // Remover a foreign key e coluna service_type_id
            $table->dropForeign(['service_type_id']);
            $table->dropColumn('service_type_id');
        });
    }
};
