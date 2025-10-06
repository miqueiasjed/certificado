<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->onDelete('cascade');
            $table->string('nickname'); // Apelido: "Matriz", "Casa"
            $table->string('street');
            $table->string('number');
            $table->string('district');
            $table->string('city');
            $table->string('state', 2);
            $table->string('zip', 9);
            $table->text('reference')->nullable(); // Opcional
            $table->boolean('active')->default(true);
            $table->timestamps();

            // Índices para performance
            $table->index(['client_id', 'active']);
            $table->index(['city', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
