<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->foreignId('address_id')->constrained()->onDelete('cascade');
            $table->foreignId('technician_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('order_type', ['preventive', 'corrective', 'emergency', 'inspection', 'maintenance', 'other'])->default('preventive');
            $table->enum('priority_level', ['low', 'medium', 'high', 'urgent', 'emergency'])->default('medium');
            $table->date('scheduled_date');
            $table->datetime('start_time')->nullable();
            $table->datetime('end_time')->nullable();
            $table->enum('status', ['pending', 'scheduled', 'in_progress', 'completed', 'cancelled', 'on_hold'])->default('pending');
            $table->text('description')->nullable();
            $table->text('observations')->nullable();
            $table->json('materials_used')->nullable();
            $table->decimal('total_cost', 10, 2)->nullable();
            $table->enum('payment_status', ['pending', 'partial', 'paid', 'overdue', 'cancelled'])->default('pending');
            $table->text('completion_notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            // Índices para performance
            $table->index(['client_id', 'status']);
            $table->index(['address_id', 'status']);
            $table->index(['technician_id', 'status']);
            $table->index(['scheduled_date', 'status']);
            $table->index('priority_level');
            $table->index('order_type');
            $table->index('payment_status');
        });

        // Agora que work_orders existe, adiciona a FK pendente em certificates
        Schema::table('certificates', function (Blueprint $table) {
            if (Schema::hasColumn('certificates', 'work_order_id')) {
                $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            if (Schema::hasColumn('certificates', 'work_order_id')) {
                $table->dropForeign(['work_order_id']);
            }
        });
        Schema::dropIfExists('work_orders');
    }
};
