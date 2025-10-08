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
        Schema::create('service_types', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Nome do tipo de serviço (ex: "Dedetização")
            $table->string('slug')->unique(); // Slug único (ex: "dedetizacao")
            $table->text('description')->nullable(); // Descrição opcional
            $table->boolean('active')->default(true); // Se está ativo
            $table->integer('sort_order')->default(0); // Ordem de exibição
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_types');
    }
};
