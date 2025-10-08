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
        // Verificar se a coluna work_order_id já é NOT NULL
        $columnInfo = DB::select("
            SELECT IS_NULLABLE, COLUMN_TYPE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'certificates'
            AND COLUMN_NAME = 'work_order_id'
        ");

        if (empty($columnInfo)) {
            echo "Coluna work_order_id não encontrada na tabela certificates.\n";
            return;
        }

        $isNullable = $columnInfo[0]->IS_NULLABLE === 'YES';

        if ($isNullable) {
            echo "Coluna work_order_id ainda é nullable. Tornando obrigatória...\n";

            Schema::table('certificates', function (Blueprint $table) {
                // Verificar se há foreign keys existentes
                $foreignKeys = DB::select("
                    SELECT CONSTRAINT_NAME
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'certificates'
                    AND COLUMN_NAME = 'work_order_id'
                    AND CONSTRAINT_NAME != 'PRIMARY'
                ");

                // Remover foreign keys existentes se houver
                foreach ($foreignKeys as $foreignKey) {
                    try {
                        $table->dropForeign([$foreignKey->CONSTRAINT_NAME]);
                        echo "Removida foreign key: {$foreignKey->CONSTRAINT_NAME}\n";
                    } catch (Exception $e) {
                        echo "Erro ao remover foreign key {$foreignKey->CONSTRAINT_NAME}: " . $e->getMessage() . "\n";
                    }
                }

                // Tornar work_order_id obrigatório
                $table->unsignedBigInteger('work_order_id')->nullable(false)->change();

                // Criar nova foreign key
                $table->foreign('work_order_id')
                    ->references('id')
                    ->on('work_orders')
                    ->onDelete('cascade');

                echo "Foreign key criada com sucesso.\n";
            });
        } else {
            echo "Coluna work_order_id já é obrigatória.\n";

            // Verificar se a foreign key existe
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'certificates'
                AND COLUMN_NAME = 'work_order_id'
                AND CONSTRAINT_NAME != 'PRIMARY'
            ");

            if (empty($foreignKeys)) {
                echo "Criando foreign key...\n";
                Schema::table('certificates', function (Blueprint $table) {
                    $table->foreign('work_order_id')
                        ->references('id')
                        ->on('work_orders')
                        ->onDelete('cascade');
                });
                echo "Foreign key criada com sucesso.\n";
            } else {
                echo "Foreign key já existe.\n";
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            // Remover foreign key
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'certificates'
                AND COLUMN_NAME = 'work_order_id'
                AND CONSTRAINT_NAME != 'PRIMARY'
            ");

            foreach ($foreignKeys as $foreignKey) {
                $table->dropForeign([$foreignKey->CONSTRAINT_NAME]);
            }

            // Voltar para nullable
            $table->unsignedBigInteger('work_order_id')->nullable()->change();
        });
    }
};
