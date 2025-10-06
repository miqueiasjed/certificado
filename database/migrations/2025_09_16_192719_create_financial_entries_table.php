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
        Schema::create('financial_entries', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // 'payment' ou 'manual'
            $table->string('source'); // 'work_order', 'manual', etc.
            $table->decimal('amount', 10, 2);
            $table->string('description');
            $table->date('entry_date');
            $table->string('payment_method')->nullable(); // PIX, cartão, dinheiro, etc.
            $table->string('reference_number')->nullable(); // Número de referência ou comprovante
            $table->text('notes')->nullable();
            $table->string('status')->default('confirmed'); // confirmed, pending, cancelled
            $table->unsignedBigInteger('work_order_id')->nullable(); // Se veio de uma OS
            $table->unsignedBigInteger('payment_detail_id')->nullable(); // Se veio de um pagamento específico
            $table->unsignedBigInteger('created_by'); // Usuário que criou
            $table->timestamps();

            // Índices
            $table->index(['type', 'entry_date']);
            $table->index(['status', 'entry_date']);
            $table->index('work_order_id');
            $table->index('payment_detail_id');
            $table->index('created_by');

            // Foreign keys
            $table->foreign('work_order_id')->references('id')->on('work_orders')->onDelete('set null');
            $table->foreign('payment_detail_id')->references('id')->on('payment_details')->onDelete('set null');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_entries');
    }
};
