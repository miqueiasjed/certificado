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
        Schema::table('contracts', function (Blueprint $table) {
            // Periodicidade com unidade (dias, semanas, meses). Colunas nullable, sem
            // default e sem backfill: nada no sistema as lê ainda neste deploy.
            // `visit_frequency` (string livre) permanece intocada: é a fonte do
            // backfill da Task 9.2 e a rede de segurança se algo der errado.
            $table->unsignedInteger('visit_frequency_valor')->nullable()->after('visit_frequency');
            $table->string('visit_frequency_unidade', 10)->nullable()->after('visit_frequency_valor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['visit_frequency_valor', 'visit_frequency_unidade']);
        });
    }
};
