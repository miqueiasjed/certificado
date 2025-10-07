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
            // Tornar work_order_id obrigatório
            $table->unsignedBigInteger('work_order_id')->nullable(false)->change();

            // Adicionar foreign key se não existir
            if (!Schema::hasColumn('certificates', 'work_order_id')) {
                $table->foreign('work_order_id')
                    ->references('id')
                    ->on('work_orders')
                    ->onDelete('cascade');
            }
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
