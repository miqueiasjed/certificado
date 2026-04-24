<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_adequations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained('work_orders')->cascadeOnDelete();
            $table->enum('type', ['estrutural', 'sanitario', 'higienico', 'quimico', 'outros']);
            $table->text('description');
            $table->enum('priority', ['alta', 'media', 'baixa'])->default('media');
            $table->enum('status', ['pendente', 'concluido'])->default('pendente');
            $table->date('deadline')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_adequations');
    }
};
