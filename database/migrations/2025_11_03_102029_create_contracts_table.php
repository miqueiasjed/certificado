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
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('address_id')->constrained('addresses')->onDelete('cascade');
            $table->text('contract_number')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('service_value', 10, 2)->nullable();
            $table->string('service_type')->nullable(); // 'pontual' ou 'periodico'
            $table->integer('visit_frequency')->nullable(); // frequência em meses para contratos periódicos
            $table->string('pest_target')->nullable(); // pragas-alvo do tratamento
            $table->text('payment_method')->nullable(); // forma de pagamento
            $table->text('payment_details')->nullable(); // detalhes bancários
            $table->integer('warranty_period_days')->default(90); // período de garantia em dias
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
