<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            // Primeiro, remover a foreign key existente
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
            $table->unsignedBigInteger('work_order_id')->nullable()->change();
        });
    }
};
