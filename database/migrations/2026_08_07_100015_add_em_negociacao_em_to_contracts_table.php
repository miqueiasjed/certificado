<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instante em que um contrato foi marcado `em_negociacao` (Plano 23, Task
 * 23.5), necessário para a pausa de 30 dias corridos do aviso semanal de
 * contrato vencido sem tratativa: sem gravar quando a negociação começou, a
 * rotina não tem como saber quando os 30 dias terminam.
 *
 * Não é `updated_at`, de propósito: `updated_at` muda em qualquer gravação do
 * contrato (corrigir um telefone do endereço, por exemplo, não passa por
 * aqui, mas qualquer outro ajuste no próprio contrato passaria), e usá-lo
 * reiniciaria a pausa por um motivo que não tem nada a ver com a negociação.
 * `App\Models\Contract::booted()` é quem grava esta coluna (na entrada em
 * `em_negociacao`) e quem a limpa (na saída), então nenhum código que grava
 * `situacao_renovacao` precisa lembrar de tocar nela.
 *
 * `contracts` já tem contrato real de cliente em produção; a coluna nasce
 * nullable e sem backfill (nenhum contrato existente está em negociação
 * hoje), então o deploy é estrutural, em uma etapa só, mesmo critério da
 * migration irmã `add_renovacao_to_contracts_table` (Task 23.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->timestamp('em_negociacao_em')->nullable()->after('motivo_nao_renovacao');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropColumn('em_negociacao_em');
        });
    }
};
