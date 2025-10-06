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
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->onDelete('cascade');
            $table->string('label'); // Nome: "Disp. 01"
            $table->string('number'); // Código/etiqueta
            $table->unsignedBigInteger('bait_type_id')->nullable();
            $table->text('default_location_note')->nullable(); // Ex: "atrás da geladeira"
            $table->boolean('active')->default(true);
            $table->timestamps();

            // Índices
            $table->index(['room_id', 'active']);
            $table->index('number');
            $table->index('bait_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
