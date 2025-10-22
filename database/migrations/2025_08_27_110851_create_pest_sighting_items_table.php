<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pest_sighting_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pest_sighting_id')->constrained()->onDelete('cascade');
            $table->string('item_type'); // Tipo do item (ex: "adulto", "ninho", "fezes", etc.)
            $table->integer('quantity')->default(1);
            $table->string('size')->nullable(); // Tamanho (ex: "pequeno", "médio", "grande")
            $table->string('condition')->nullable(); // Estado (ex: "vivo", "morto", "doente")
            $table->text('description')->nullable();
            $table->text('location_details')->nullable(); // Detalhes específicos da localização
            $table->boolean('active')->default(true);
            $table->timestamps();

            // Índices para performance
            $table->index(['pest_sighting_id', 'item_type']);
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pest_sighting_items');
    }
};


















