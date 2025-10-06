<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bait_types', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Ex: "Granulados", "Estação cola"
            $table->string('brand')->nullable(); // Opcional
            $table->text('notes')->nullable(); // Opcional
            $table->timestamps();

            // Índices para performance
            $table->index('name');
        });

        // Adicionar FK para bait_type_id em devices agora que bait_types existe
        Schema::table('devices', function (Blueprint $table) {
            $table->foreign('bait_type_id')->references('id')->on('bait_types')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropForeign(['bait_type_id']);
        });
        Schema::dropIfExists('bait_types');
    }
};
