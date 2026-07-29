<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extensão de escopo da Task 13.2 (Plano 13): os aplicadores de sincronização
 * do aplicativo do técnico (`AplicadorDeEventoDeDispositivo`,
 * `AplicadorDeAvistamento`, `AplicadorDeAdequacao`) precisam gravar o instante
 * do celular (`registrada_em`) e alguns dados de campo que as três tabelas
 * ainda não tinham coluna para guardar.
 *
 * Todas as colunas nascem nullable e sem valor padrão diferente de `null`:
 * nenhuma delas depende de um backfill, porque nenhuma linha existente tinha
 * como ter esse dado (o recurso é novo). Migration só de estrutura, sem
 * qualquer risco às linhas já gravadas em produção, dentro da regra do
 * CLAUDE.md do projeto para tabela com dado existente.
 *
 * - **`work_order_device_events.registrada_em`**: `event_date` desta tabela é
 *   `date` (só o dia, ver cast do model), então não há onde guardar o
 *   instante exato do celular sem uma coluna nova. `bait_consumption_status`
 *   e `pest_found` guardam, respectivamente, o consumo de isca observado e a
 *   praga encontrada no ponto, que a Task 13.2 pede e que esta tabela também
 *   não tinha.
 * - **`pest_sightings.registrada_em`**: `sighting_date` já é um instante
 *   (`datetime`) e continua sendo a data de exibição do avistamento; a coluna
 *   nova só padroniza o nome do instante do celular entre as três tabelas.
 *   `estimated_quantity`, `applied_product` e `applied_product_quantity`
 *   guardam a quantidade estimada da praga e o produto aplicado, que a
 *   Task 13.2 pede e a tabela não tinha (o campo mais próximo,
 *   `control_measures_applied`, é texto livre e não separa produto de
 *   quantidade).
 * - **`work_order_adequations.registrada_em`**: a tabela não tinha nenhuma
 *   coluna de instante; `deadline` é o prazo sugerido para a adequação, uma
 *   data escolhida pelo técnico, não o momento em que ele registrou a
 *   adequação.
 */
return new class extends Migration
{
    /**
     * Executa a migration.
     */
    public function up(): void
    {
        Schema::table('work_order_device_events', function (Blueprint $table): void {
            $table->dateTime('registrada_em')->nullable()->after('event_observations');
            $table->string('bait_consumption_status')->nullable()->after('registrada_em');
            $table->string('pest_found')->nullable()->after('bait_consumption_status');
        });

        Schema::table('pest_sightings', function (Blueprint $table): void {
            $table->dateTime('registrada_em')->nullable()->after('technician_notes');
            $table->unsignedInteger('estimated_quantity')->nullable()->after('registrada_em');
            $table->string('applied_product')->nullable()->after('estimated_quantity');
            $table->decimal('applied_product_quantity', 8, 2)->nullable()->after('applied_product');
        });

        Schema::table('work_order_adequations', function (Blueprint $table): void {
            $table->dateTime('registrada_em')->nullable()->after('deadline');
        });
    }

    /**
     * Reverte a migration.
     */
    public function down(): void
    {
        Schema::table('work_order_device_events', function (Blueprint $table): void {
            $table->dropColumn(['registrada_em', 'bait_consumption_status', 'pest_found']);
        });

        Schema::table('pest_sightings', function (Blueprint $table): void {
            $table->dropColumn([
                'registrada_em',
                'estimated_quantity',
                'applied_product',
                'applied_product_quantity',
            ]);
        });

        Schema::table('work_order_adequations', function (Blueprint $table): void {
            $table->dropColumn('registrada_em');
        });
    }
};
