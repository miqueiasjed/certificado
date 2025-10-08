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
            // Adicionar a nova coluna service_type_id
            $table->foreignId('service_type_id')->nullable()->constrained('service_types')->after('technician_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            // Remover a foreign key e coluna service_type_id
            $table->dropForeign(['service_type_id']);
            $table->dropColumn('service_type_id');
        });
    }
};
