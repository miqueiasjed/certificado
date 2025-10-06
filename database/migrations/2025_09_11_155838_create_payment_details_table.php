<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->onDelete('cascade');
            $table->date('payment_due_date')->nullable(); // Data de vencimento
            $table->date('payment_date')->nullable(); // Data efetiva do pagamento
            $table->enum('payment_method', ['pix', 'credit_card', 'debit_card', 'boleto', 'cash', 'bank_transfer'])->nullable(); // Forma de pagamento
            $table->decimal('amount_paid', 10, 2)->nullable(); // Valor pago
            $table->text('payment_notes')->nullable(); // Observações do pagamento
            $table->boolean('is_partial_payment')->default(false); // Se é pagamento parcial
            $table->boolean('active')->default(true);
            $table->timestamps();

            // Índices para performance
            $table->index(['work_order_id', 'payment_date']);
            $table->index('payment_due_date');
            $table->index('payment_method');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_details');
    }
};
