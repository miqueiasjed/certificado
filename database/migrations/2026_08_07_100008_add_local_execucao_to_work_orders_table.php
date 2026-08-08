<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Onde a execução da OS começou e terminou de verdade, para o rastreamento
 * em campo do Plano 22.
 *
 * `work_orders` já tem dado em produção, então a regra do CLAUDE.md do
 * projeto vale: migration que toca tabela com dado existente é aplicada em
 * etapas (estrutura, backfill conferido, restrição), nunca as três no mesmo
 * deploy. Aqui não há restrição nenhuma a aplicar depois: as sete colunas
 * nascem nullable e ficam assim, porque coordenada de início/fim só existe
 * quando o técnico registra em campo (e continuará nula em toda OS que já
 * passou, e em toda OS futura cujo técnico não autorizar a localização no
 * aparelho, mesma fronteira de consentimento já registrada na Task 13.1).
 *
 * - **`inicio_latitude`/`inicio_longitude`/`fim_latitude`/`fim_longitude`**:
 *   `decimal(10, 7)`, nullable. Mesmo tipo e precisão de
 *   `work_order_signatures.latitude/longitude` (Plano 13, Task 13.1) e de
 *   `addresses.latitude/longitude` (Task 22.1, criada nesta mesma
 *   migration): 7 casas decimais é o padrão de coordenada já estabelecido
 *   no schema.
 * - **`inicio_registrado_em`/`fim_registrado_em`**: `datetime` nullable. É o
 *   instante (aparelho do técnico) em que o início e o fim da execução
 *   foram registrados, gravado em UTC e convertido para o fuso do negócio só
 *   na exibição, via `BusinessDate`, mesmo padrão de `assinada_em` e de
 *   `work_order_signatures.coletada_em`.
 * - **`divergencia_local_metros`**: `unsignedInteger` nullable. Distância em
 *   metros entre a coordenada registrada no início da execução e a
 *   coordenada do endereço da OS, quando as duas existem; calculada por um
 *   serviço de comparação (task futura do Plano 22), não por esta migration.
 *   Nula sempre que faltar uma das duas coordenadas para o cálculo.
 *
 * Nullable sem exceção nas sete colunas, e sem valor padrão: OS sem local de
 * execução registrado continua sendo uma OS válida, sem qualquer efeito
 * sobre o cadastro e a operação atual.
 */
return new class extends Migration
{
    /**
     * Executa a migration.
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->decimal('inicio_latitude', 10, 7)->nullable()->after('recusa_registrada_em');
            $table->decimal('inicio_longitude', 10, 7)->nullable()->after('inicio_latitude');
            $table->dateTime('inicio_registrado_em')->nullable()->after('inicio_longitude');

            $table->decimal('fim_latitude', 10, 7)->nullable()->after('inicio_registrado_em');
            $table->decimal('fim_longitude', 10, 7)->nullable()->after('fim_latitude');
            $table->dateTime('fim_registrado_em')->nullable()->after('fim_longitude');

            $table->unsignedInteger('divergencia_local_metros')->nullable()->after('fim_registrado_em');
        });
    }

    /**
     * Reverte a migration.
     */
    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'inicio_latitude',
                'inicio_longitude',
                'inicio_registrado_em',
                'fim_latitude',
                'fim_longitude',
                'fim_registrado_em',
                'divergencia_local_metros',
            ]);
        });
    }
};
