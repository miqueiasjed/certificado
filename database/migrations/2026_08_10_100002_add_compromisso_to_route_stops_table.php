<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parada de roteiro passa a aceitar compromisso avulso, além de OS
 * (Plano 31).
 *
 * `route_stops.work_order_id` nasceu `NOT NULL` no Plano 22, quando toda
 * parada de roteiro só podia representar uma OS. O Plano 30 criou o
 * compromisso avulso (`compromissos`), que também precisa poder entrar num
 * roteiro sem que exista OS nenhuma por trás.
 *
 * Decisões da modelagem:
 *
 * - **`work_order_id` vira nullable.** Deixa de ser a única origem possível
 *   de uma parada. A regra de que toda parada tem exatamente um dos dois
 *   vínculos preenchidos (`work_order_id` xor `compromisso_id`) é do
 *   `RouteService`, não do banco: o projeto não usa `CHECK` de banco para
 *   regra de negócio.
 * - **`compromisso_id` nullable com `cascadeOnDelete`.** Mesmo raciocínio já
 *   registrado para `work_order_id` na migration de origem: parada de
 *   roteiro não tem sentido nem consumidor sem o compromisso que ela
 *   representa.
 * - **Unique `[route_id, compromisso_id]`, sem `company_id`.** Mesmo critério
 *   já registrado para `route_stops_route_id_work_order_id_unique`:
 *   `route_id` já pertence a uma empresa só, então compor com `company_id`
 *   não separaria tenant nenhum.
 *
 * Tabela já em produção, mas sem dado que a mudança apague ou quebre: soltar
 * uma restrição `NOT NULL` não descarta linha nenhuma, e a coluna nova nasce
 * vazia para todo mundo. Um deploy só. Reverter exige que nenhuma parada de
 * compromisso já exista: a `down()` volta `work_order_id` a `NOT NULL` e
 * falha se houver linha com a coluna vazia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('route_stops', function (Blueprint $table): void {
            $table->foreignId('work_order_id')->nullable()->change();
        });

        Schema::table('route_stops', function (Blueprint $table): void {
            $table->foreignId('compromisso_id')->nullable()->after('work_order_id')
                ->constrained()->cascadeOnDelete();
            $table->unique(['route_id', 'compromisso_id']);
        });
    }

    public function down(): void
    {
        Schema::table('route_stops', function (Blueprint $table): void {
            $table->dropUnique(['route_id', 'compromisso_id']);
            $table->dropConstrainedForeignId('compromisso_id');
        });

        Schema::table('route_stops', function (Blueprint $table): void {
            $table->foreignId('work_order_id')->nullable(false)->change();
        });
    }
};
