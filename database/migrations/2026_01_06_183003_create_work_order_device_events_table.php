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
        Schema::create('work_order_device_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->onDelete('cascade');
            $table->foreignId('device_id')->constrained()->onDelete('cascade');
            $table->foreignId('event_type_id')->nullable()->constrained('event_types')->onDelete('set null');
            $table->date('event_date');
            $table->text('event_description')->nullable();
            $table->text('event_observations')->nullable();
            $table->timestamps();

            // Índices para performance
            $table->index(['work_order_id', 'device_id']);
            $table->index('event_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_order_device_events');
    }
};
