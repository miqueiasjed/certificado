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
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            // Será adicionada por migration posterior (work_orders depende de outras tabelas)
            $table->unsignedBigInteger('work_order_id')->nullable();
            $table->date('execution_date')->nullable();
            $table->date('warranty')->nullable();
            $table->string('certificate_number')->nullable();
            $table->enum('status', ['draft', 'issued', 'expired', 'cancelled'])->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
