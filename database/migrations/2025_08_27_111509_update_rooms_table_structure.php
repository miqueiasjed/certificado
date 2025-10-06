<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            // Remover colunas antigas
            $table->dropForeign(['service_order_id']);
            $table->dropColumn(['service_order_id', 'description', 'quantity']);

            // Adicionar novas colunas
            $table->foreignId('address_id')->constrained()->onDelete('cascade');
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);

            // Adicionar índices
            $table->index(['address_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            // Reverter para estrutura antiga
            $table->dropForeign(['address_id']);
            $table->dropIndex(['address_id', 'active']);
            $table->dropColumn(['address_id', 'notes', 'active']);

            $table->foreignId('service_order_id')->constrained()->onDelete('cascade');
            $table->text('description')->nullable();
            $table->integer('quantity')->default(1);
        });
    }
};
