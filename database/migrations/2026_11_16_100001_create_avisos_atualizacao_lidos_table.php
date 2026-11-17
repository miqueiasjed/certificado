<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos de atualização lidos: uma linha por pessoa e aviso lido.
 *
 * O aviso em si mora no arquivo JSON de `resources/avisos-atualizacao/`, por
 * isso `aviso_id` é texto (nome do arquivo sem `.json`) e não chave
 * estrangeira. Tabela nova, sem dado existente: estrutura e restrição podem
 * ir no mesmo deploy. A unique é composta com `company_id`, como todo unique
 * de domínio deste sistema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avisos_atualizacao_lidos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('aviso_id', 120);
            $table->timestamp('lido_em');

            $table->unique(['company_id', 'user_id', 'aviso_id'], 'avisos_lidos_company_user_aviso_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avisos_atualizacao_lidos');
    }
};
