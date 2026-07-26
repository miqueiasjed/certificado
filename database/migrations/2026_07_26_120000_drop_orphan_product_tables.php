<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tabelas órfãs a remover. Estão no PLURAL.
     * Criadas por migration e nunca referenciadas pelo código.
     */
    private const TABELAS_ORFAS = [
        'work_order_products',
        'service_order_products',
    ];

    /**
     * Tabelas pivot realmente usadas pelo sistema. Estão no SINGULAR e
     * guardam dados de ordem de serviço em produção. Jamais podem ser
     * tocadas por esta migration.
     */
    private const TABELAS_PROTEGIDAS = [
        'work_order_product',
        'service_order_product',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Trava contra erro de digitação: se alguém trocar plural por singular
        // na lista acima, a migration para antes de qualquer DDL.
        foreach (self::TABELAS_ORFAS as $tabela) {
            if (in_array($tabela, self::TABELAS_PROTEGIDAS, true)) {
                throw new RuntimeException(
                    "Migration abortada: a tabela '{$tabela}' é uma pivot em uso pelo sistema e não pode ser removida."
                );
            }
        }

        // Validação de conteúdo de TODAS as tabelas antes de remover qualquer uma.
        // DDL em MySQL não participa de transação, então uma remoção parcial
        // seguida de exceção seria irreversível.
        foreach (self::TABELAS_ORFAS as $tabela) {
            if (! Schema::hasTable($tabela)) {
                continue;
            }

            $quantidade = DB::table($tabela)->count();

            if ($quantidade > 0) {
                throw new RuntimeException(
                    "Migration abortada: a tabela '{$tabela}' possui {$quantidade} registro(s). "
                    . 'Ela deveria estar vazia por ser órfã. Investigue a origem dos dados antes de remover a tabela.'
                );
            }
        }

        Schema::dropIfExists('work_order_products');
        Schema::dropIfExists('service_order_products');
    }

    /**
     * Reverse the migrations.
     *
     * Recria as duas tabelas com a estrutura original das migrations de criação:
     * 2025_10_08_172533_create_work_order_products_table.php
     * 2025_07_28_231026_create_service_order_products_table.php
     */
    public function down(): void
    {
        if (! Schema::hasTable('work_order_products')) {
            Schema::create('work_order_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('work_order_id')->constrained()->onDelete('cascade');
                $table->foreignId('product_id')->constrained()->onDelete('cascade');
                $table->integer('quantity')->default(1);
                $table->text('observations')->nullable();
                $table->timestamps();

                $table->unique(['work_order_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('service_order_products')) {
            Schema::create('service_order_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_order_id')->constrained()->onDelete('cascade');
                $table->foreignId('product_id')->constrained()->onDelete('cascade');
                $table->integer('quantity')->default(1);
                $table->text('notes')->nullable(); // Observações específicas do produto
                $table->timestamps();
            });
        }
    }
};
