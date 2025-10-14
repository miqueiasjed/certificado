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
        // Dropar a tabela pivot
        Schema::dropIfExists('service_order_services');

        // Adicionar service_id na tabela service_orders
        Schema::table('service_orders', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('id')->constrained('services')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remover service_id da tabela service_orders
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
            $table->dropColumn('service_id');
        });

        // Recriar a tabela pivot
        Schema::create('service_order_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_order_id')->constrained()->onDelete('cascade');
            $table->foreignId('service_id')->constrained()->onDelete('cascade');
            $table->text('observations')->nullable();
            $table->timestamps();

            $table->unique(['service_order_id', 'service_id']);
        });
    }
};
