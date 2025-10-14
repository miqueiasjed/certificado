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
            // Remover a foreign key primeiro
            $table->dropForeign(['service_type_id']);
            // Remover a coluna
            $table->dropColumn('service_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            // Adicionar de volta a coluna
            $table->foreignId('service_type_id')->nullable()->after('client_id')->constrained('service_types')->onDelete('set null');
        });
    }
};
