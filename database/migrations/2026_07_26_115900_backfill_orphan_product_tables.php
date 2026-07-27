<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preserva o dado de produção que ficou nas tabelas de produtos
     * abandonadas em 14/10/2025 (troca de pivot sem migração de dado: o
     * relacionamento passou de `work_order_products`/`service_order_products`
     * para tabelas novas criadas do zero, `work_order_product`/
     * `service_order_product`, sem nenhum `Schema::rename` nem cópia),
     * antes de `2026_07_26_120000_drop_orphan_product_tables` remover essas
     * tabelas. Sem isto, a migration de remoção aborta (como já aconteceu em
     * produção) ao encontrar linha na tabela que deveria estar vazia -
     * corretamente, porque a tabela não estava vazia de verdade.
     *
     * Não duplica: uma OS/produto que já tem linha na tabela em uso (o
     * técnico pode ter readicionado manualmente o produto depois de
     * 14/10/2025) mantém só a linha nova, e a linha órfã correspondente é
     * descartada. Cada linha órfã migrada ou descartada é registrada em log,
     * porque `attach`/`sync`/`detach` de pivot não passa pela trait
     * `Auditavel` (que só audita eventos do próprio model), e esta migration
     * é a única trilha que vai sobrar do que existia nessas tabelas.
     *
     * Cada linha processada (copiada ou descartada) é removida da tabela
     * órfã na hora, uma por uma: é o que deixa a tabela vazia ao final,
     * condição que `2026_07_26_120000_drop_orphan_product_tables` exige para
     * deixar de abortar e seguir com o `dropIfExists`.
     */
    public function up(): void
    {
        $this->migrarProdutosDeOrdemDeServico();
        $this->migrarProdutosDeOrdemDeServicoAgendada();
    }

    /**
     * `work_order_products` (plural, órfã) -> `work_order_product`
     * (singular, em uso por `WorkOrder::products()`).
     */
    private function migrarProdutosDeOrdemDeServico(): void
    {
        if (! Schema::hasTable('work_order_products') || ! Schema::hasTable('work_order_product')) {
            return;
        }

        foreach (DB::table('work_order_products')->get() as $linha) {
            $jaExiste = DB::table('work_order_product')
                ->where('work_order_id', $linha->work_order_id)
                ->where('product_id', $linha->product_id)
                ->exists();

            if ($jaExiste) {
                Log::warning('[backfill_orphan_product_tables] Linha órfã de work_order_products descartada: já existe em work_order_product.', [
                    'work_order_products_id' => $linha->id,
                    'work_order_id' => $linha->work_order_id,
                    'product_id' => $linha->product_id,
                ]);

                DB::table('work_order_products')->where('id', $linha->id)->delete();

                continue;
            }

            DB::table('work_order_product')->insert([
                'work_order_id' => $linha->work_order_id,
                'product_id' => $linha->product_id,
                'quantity' => $linha->quantity,
                'unit' => null,
                'observations' => $linha->observations,
                'created_at' => $linha->created_at,
                'updated_at' => $linha->updated_at,
            ]);

            Log::info('[backfill_orphan_product_tables] Linha órfã de work_order_products migrada para work_order_product.', [
                'work_order_products_id' => $linha->id,
                'work_order_id' => $linha->work_order_id,
                'product_id' => $linha->product_id,
            ]);

            DB::table('work_order_products')->where('id', $linha->id)->delete();
        }
    }

    /**
     * `service_order_products` (plural, órfã) -> `service_order_product`
     * (singular, em uso). A singular não tem coluna de observação: a
     * migration `2025_10_14_175755_add_unit_and_quantity_to_service_order_product_table`
     * recriou a tabela do zero sem trazer `notes`. Acrescenta a coluna antes
     * de copiar, só quando a tabela órfã de fato tiver dado (não força a
     * coluna em instalações onde a tabela órfã já estava vazia).
     */
    private function migrarProdutosDeOrdemDeServicoAgendada(): void
    {
        if (! Schema::hasTable('service_order_products') || ! Schema::hasTable('service_order_product')) {
            return;
        }

        $linhas = DB::table('service_order_products')->get();

        if ($linhas->isEmpty()) {
            return;
        }

        if (! Schema::hasColumn('service_order_product', 'notes')) {
            Schema::table('service_order_product', function (Blueprint $table): void {
                $table->text('notes')->nullable()->after('unit');
            });
        }

        foreach ($linhas as $linha) {
            $jaExiste = DB::table('service_order_product')
                ->where('service_order_id', $linha->service_order_id)
                ->where('product_id', $linha->product_id)
                ->exists();

            if ($jaExiste) {
                Log::warning('[backfill_orphan_product_tables] Linha órfã de service_order_products descartada: já existe em service_order_product.', [
                    'service_order_products_id' => $linha->id,
                    'service_order_id' => $linha->service_order_id,
                    'product_id' => $linha->product_id,
                ]);

                DB::table('service_order_products')->where('id', $linha->id)->delete();

                continue;
            }

            DB::table('service_order_product')->insert([
                'service_order_id' => $linha->service_order_id,
                'product_id' => $linha->product_id,
                'quantity' => $linha->quantity,
                'unit' => null,
                'notes' => $linha->notes,
                'created_at' => $linha->created_at,
                'updated_at' => $linha->updated_at,
            ]);

            Log::info('[backfill_orphan_product_tables] Linha órfã de service_order_products migrada para service_order_product.', [
                'service_order_products_id' => $linha->id,
                'service_order_id' => $linha->service_order_id,
                'product_id' => $linha->product_id,
            ]);

            DB::table('service_order_products')->where('id', $linha->id)->delete();
        }
    }

    /**
     * Não reversível de propósito: reverter apagaria dado já consolidado na
     * tabela em uso, sem saber se ele já foi alterado desde a migração. Quem
     * precisar desfazer usa backup de banco, não `migrate:rollback`.
     */
    public function down(): void
    {
        // Intencionalmente vazio: ver o comentário acima.
    }
};
