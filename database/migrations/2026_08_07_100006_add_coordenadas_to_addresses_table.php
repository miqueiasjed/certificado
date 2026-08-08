<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coordenada geográfica do endereço, para o roteiro do Plano 22.
 *
 * `addresses` já tem dado em produção, então a regra do CLAUDE.md do projeto
 * vale: migration que toca tabela com dado existente é aplicada em etapas
 * (estrutura, backfill conferido, restrição), nunca as três no mesmo deploy.
 * Aqui não há restrição nenhuma a aplicar depois: todas as quatro colunas
 * nascem nullable e ficam assim, porque a geocodificação em lote (task
 * futura do Plano 22) é quem preenche o endereço já existente aos poucos, e
 * o cadastro de endereço sem coordenada continua válido mesmo depois disso
 * (nem todo endereço tem uma coordenada confiável). Não existe aqui a etapa
 * de "restrição" que o CLAUDE.md pede para não misturar no mesmo deploy:
 * ela simplesmente não existe neste caso, de propósito.
 *
 * - **`latitude`/`longitude`**: `decimal(10, 7)`, nullable, mesmo tipo e
 *   precisão de `work_order_signatures.latitude/longitude` (Plano 13, Task
 *   13.1) e de `device_positions` (Plano 21): 7 casas decimais é ~1cm de
 *   precisão, e é o padrão já estabelecido no schema para coordenada.
 * - **`geocodificado_em`**: `datetime` nullable, instante em que a
 *   geocodificação (automática ou manual) gravou a coordenada atual.
 * - **`origem_coordenada`**: `enum('automatica', 'manual')` nullable.
 *   Distingue o que veio de busca automática (provedor de geocodificação) do
 *   que a pessoa corrigiu a mão. Uma geocodificação em lote nunca sobrescreve
 *   `origem_coordenada = manual`: essa regra é do serviço que vai rodar a
 *   geocodificação (task futura), não desta migration.
 * - **`precisao_geocodificacao`**: `enum('exata', 'aproximada', 'cidade')`
 *   nullable. Guarda o nível de confiança devolvido pelo provedor. Precisão
 *   "cidade" não serve para roteiro (o ponto não está no endereço, só na
 *   cidade) e precisa aparecer como pendência nas telas futuras do Plano 22.
 *
 * Nullable sem exceção nas quatro colunas, e sem valor padrão: endereço sem
 * coordenada continua sendo um endereço válido, sem qualquer efeito sobre o
 * cadastro e a operação atual.
 */
return new class extends Migration
{
    /**
     * Executa a migration.
     */
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->decimal('latitude', 10, 7)->nullable()->after('reference');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->dateTime('geocodificado_em')->nullable()->after('longitude');
            $table->enum('origem_coordenada', ['automatica', 'manual'])->nullable()->after('geocodificado_em');
            $table->enum('precisao_geocodificacao', ['exata', 'aproximada', 'cidade'])->nullable()->after('origem_coordenada');
        });
    }

    /**
     * Reverte a migration.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropColumn([
                'latitude',
                'longitude',
                'geocodificado_em',
                'origem_coordenada',
                'precisao_geocodificacao',
            ]);
        });
    }
};
