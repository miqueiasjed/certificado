<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uuid da adequação registrada em campo (Plano 13, Task 13.8 - correção de
 * lacuna encontrada na implementação do frontend).
 *
 * O técnico gera este uuid no aparelho, antes de capturar qualquer foto da
 * adequação, e o envia dentro do `payload` da própria operação `adequacao`
 * (`AplicadorDeAdequacao`). É o mesmo uuid que a foto associada leva em
 * `entity_id` (operação `foto`, Plano 12): a adequação ainda não tem `id` no
 * servidor no instante em que o técnico tira a foto, offline, então o uuid é
 * a única chave estável que os dois lados (adequação e foto) compartilham
 * antes da sincronização.
 *
 * `AplicadorDeFoto::aplicar()` resolve `entity_id` de foto de adequação por
 * este uuid quando o valor recebido não é um id numérico, convertendo para o
 * `id` real antes de gravar `work_order_photos.entity_id` (que é inteiro).
 * Sem esta coluna, a foto seria gravada com um uuid dentro de uma FK
 * numérica e a associação nunca fecharia.
 *
 * Nullable, sem backfill: adequação já existente nunca teve um técnico
 * gerando esse uuid, e não precisa de um agora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_adequations', function (Blueprint $table): void {
            $table->uuid('uuid')->nullable()->after('id');

            $table->index('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_adequations', function (Blueprint $table): void {
            $table->dropIndex(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
