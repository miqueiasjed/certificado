<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Primeiro, verificar se há certificados sem work_order_id
        $certificatesWithoutWorkOrder = DB::table('certificates')
            ->whereNull('work_order_id')
            ->count();

        if ($certificatesWithoutWorkOrder > 0) {
            // Se há certificados sem work_order_id, vamos deletá-los
            // pois não fazem sentido sem uma ordem de serviço associada
            DB::table('certificates')
                ->whereNull('work_order_id')
                ->delete();

            echo "Removidos {$certificatesWithoutWorkOrder} certificado(s) sem ordem de serviço associada.\n";
        }

        // Agora podemos tornar a coluna obrigatória
        Schema::table('certificates', function (Blueprint $table) {
            // Verificar se a foreign key existe antes de tentar removê-la
            // Lógica específica para MySQL/MariaDB pois usa information_schema
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'])) {
                $foreignKeys = DB::select("
                    SELECT CONSTRAINT_NAME
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'certificates'
                    AND COLUMN_NAME = 'work_order_id'
                    AND CONSTRAINT_NAME != 'PRIMARY'
                ");

                // Remover a foreign key existente se ela existir
                foreach ($foreignKeys as $foreignKey) {
                    $table->dropForeign($foreignKey->CONSTRAINT_NAME);
                }
            }
            // Em ambientes de teste (SQLite), geralmente o banco é recriado ou o change() lida com a redefinição de tabela,
            // então não precisamos forçar o drop da FK manualmente aqui se não formos capazes de identificar o nome.

            // Tornar work_order_id obrigatório
            $table->unsignedBigInteger('work_order_id')->nullable(false)->change();

            // Recriar a foreign key com CASCADE
            $table->foreign('work_order_id')
                ->references('id')
                ->on('work_orders')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            // Voltar work_order_id para nullable
            $table->dropForeign(['work_order_id']);
            $table->unsignedBigInteger('work_order_id')->nullable()->change();
            $table->foreign('work_order_id')
                ->references('id')
                ->on('work_orders')
                ->onDelete('set null');
        });
    }
};
