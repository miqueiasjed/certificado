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
            $table->foreignId('service_order_id')->constrained()->onDelete('cascade');
            $table->string('name'); // Nome do dispositivo (ex: Ar condicionado, Ventilador)
            $table->text('description')->nullable(); // Descrição do dispositivo
            $table->integer('quantity')->default(1); // Quantidade de dispositivos
            $table->string('brand')->nullable(); // Marca do dispositivo
            $table->string('model')->nullable(); // Modelo do dispositivo
            $table->timestamps();
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
