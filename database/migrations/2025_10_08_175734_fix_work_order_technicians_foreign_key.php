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
        Schema::table('work_order_technicians', function (Blueprint $table) {
            // Remover a foreign key existente
            $table->dropForeign(['technician_id']);
        });

        Schema::table('work_order_technicians', function (Blueprint $table) {
            // Adicionar nova foreign key apontando para technicians
            $table->foreign('technician_id')->references('id')->on('technicians')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('work_order_technicians', function (Blueprint $table) {
            // Remover a foreign key para technicians
            $table->dropForeign(['technician_id']);
        });

        Schema::table('work_order_technicians', function (Blueprint $table) {
            // Restaurar foreign key para users (revert)
            $table->foreign('technician_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
