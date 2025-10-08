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
            // Remover a foreign key existente
            $table->dropForeign(['work_order_id']);

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
