<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->onDelete('cascade');
            $table->foreignId('work_order_id')->constrained()->onDelete('cascade');
            $table->enum('event_type', ['bait_consumption', 'cleaning', 'bait_change', 'technician_notes']);
            $table->enum('bait_consumption_status', ['partial', 'total', 'none', 'spoiled', 'replacement'])->nullable();
            $table->decimal('bait_consumption_quantity', 8, 2)->nullable();
            $table->boolean('cleaning_done')->default(false);
            $table->text('cleaning_notes')->nullable();
            $table->string('bait_change_type')->nullable();
            $table->string('bait_change_lot')->nullable();
            $table->decimal('bait_change_quantity', 8, 2)->nullable();
            $table->text('technician_notes')->nullable();
            $table->datetime('event_date');
            $table->boolean('active')->default(true);
            $table->timestamps();

            // Índices para performance
            $table->index(['device_id', 'created_at']);
            $table->index(['work_order_id', 'created_at']);
            $table->index('event_type');
            $table->index('event_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_events');
    }
};
