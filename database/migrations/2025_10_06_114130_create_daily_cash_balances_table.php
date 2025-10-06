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
        Schema::create('daily_cash_balances', function (Blueprint $table) {
            $table->id();
            $table->date('balance_date')->unique(); // Data única para cada dia
            $table->decimal('opening_balance', 15, 2)->default(0); // Saldo de abertura
            $table->decimal('total_entries', 15, 2)->default(0); // Total de entradas do dia
            $table->decimal('total_withdrawals', 15, 2)->default(0); // Total de saídas do dia
            $table->decimal('closing_balance', 15, 2)->default(0); // Saldo de fechamento
            $table->integer('entries_count')->default(0); // Quantidade de entradas
            $table->integer('withdrawals_count')->default(0); // Quantidade de saídas
            $table->text('notes')->nullable(); // Observações do dia
            $table->boolean('is_closed')->default(false); // Se o dia foi fechado
            $table->timestamp('closed_at')->nullable(); // Quando foi fechado
            $table->foreignId('closed_by')->nullable()->constrained('users'); // Quem fechou
            $table->timestamps();

            $table->index(['balance_date', 'is_closed']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_cash_balances');
    }
};
