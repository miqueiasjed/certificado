<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pest_sightings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('address_id')->constrained()->onDelete('cascade');
            $table->foreignId('work_order_id')->constrained()->onDelete('cascade');
            $table->datetime('sighting_date');
            $table->enum('pest_type', ['rats', 'mice', 'cockroaches', 'ants', 'termites', 'flies', 'fleas', 'ticks', 'scorpions', 'spiders', 'bees', 'wasps', 'other']);
            $table->enum('severity_level', ['low', 'medium', 'high', 'critical']);
            $table->string('location_description');
            $table->text('environmental_conditions')->nullable();
            $table->text('control_measures_applied')->nullable();
            $table->text('technician_notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            // Índices para performance
            $table->index(['address_id', 'work_order_id']);
            $table->index('pest_type');
            $table->index('severity_level');
            $table->index('sighting_date');
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pest_sightings');
    }
};
















