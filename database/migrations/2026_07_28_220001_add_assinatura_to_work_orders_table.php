<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Situação de assinatura da OS e travamento (Plano 13).
 *
 * `work_orders` já tem dado em produção, então a regra do CLAUDE.md do projeto
 * vale: migration que toca tabela com dado existente é aplicada em etapas
 * (estrutura, backfill conferido, restrição), nunca as três no mesmo deploy.
 * Aqui não há ambiguidade de valor a resolver por backfill: toda OS existente
 * literalmente não tem assinatura coletada ainda, então o `DEFAULT
 * 'nao_coletada'` da própria coluna já deixa cada linha existente no estado
 * correto, sem precisar de um `UPDATE` posterior. Por isso a coluna nasce
 * nesta única migration, com o padrão fazendo o trabalho do backfill.
 *
 * - **`situacao_assinatura`**: `nao_coletada` (padrão), `assinada` ou
 *   `recusada`. É `WorkOrder::estaTravada()` que lê este campo, e só o valor
 *   `assinada` trava a edição. `recusada` é situação própria, nunca inferida
 *   pela ausência de assinatura: as duas coisas significam coisas diferentes
 *   na hora de cobrar o serviço.
 * - **`assinada_em`**: instante em que a assinatura foi coletada, `datetime`
 *   nullable, gravado em UTC e convertido na exibição.
 * - **`recusa_motivo`**: texto livre do motivo da recusa, nullable porque só
 *   existe quando `situacao_assinatura = recusada`.
 * - **`recusa_registrada_em`**: instante do registro da recusa, `datetime`
 *   nullable, mesmo motivo de `assinada_em`.
 */
return new class extends Migration
{
    /**
     * Executa a migration.
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->enum('situacao_assinatura', ['nao_coletada', 'assinada', 'recusada'])
                ->default('nao_coletada')
                ->after('status');

            $table->dateTime('assinada_em')->nullable()->after('situacao_assinatura');
            $table->text('recusa_motivo')->nullable()->after('assinada_em');
            $table->dateTime('recusa_registrada_em')->nullable()->after('recusa_motivo');
        });
    }

    /**
     * Reverte a migration.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'situacao_assinatura',
                'assinada_em',
                'recusa_motivo',
                'recusa_registrada_em',
            ]);
        });
    }
};
