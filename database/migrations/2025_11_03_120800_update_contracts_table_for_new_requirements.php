<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Verificar e alterar visit_frequency se necessário
        if (Schema::hasColumn('contracts', 'visit_frequency')) {
            // Verificar o tipo atual da coluna
            $columnInfo = DB::select("SHOW COLUMNS FROM contracts WHERE Field = 'visit_frequency'");
            if (!empty($columnInfo)) {
                $currentType = $columnInfo[0]->Type;
                // Se for int, int(11) ou qualquer variação de integer, alterar para string
                if (strpos(strtolower($currentType), 'int') !== false || strpos(strtolower($currentType), 'integer') !== false) {
                    Schema::table('contracts', function (Blueprint $table) {
                        $table->string('visit_frequency', 50)->nullable()->change();
                    });
                }
            }
        }

        // Adicionar visit_count se não existir
        if (!Schema::hasColumn('contracts', 'visit_count')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->integer('visit_count')->nullable()->after('visit_frequency');
            });
        }

        // Remover warranty_period_days se existir
        if (Schema::hasColumn('contracts', 'warranty_period_days')) {
            Schema::table('contracts', function (Blueprint $table) {
                $table->dropColumn('warranty_period_days');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            // Reverter visit_frequency para integer
            $table->integer('visit_frequency')->nullable()->change();

            // Remover visit_count
            $table->dropColumn('visit_count');

            // Adicionar warranty_period_days de volta
            $table->integer('warranty_period_days')->default(90);
        });
    }
};
