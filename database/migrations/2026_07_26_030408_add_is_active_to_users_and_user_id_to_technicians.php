<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Executa a migration.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Default true garante que todo usuário existente continua
            // entrando no sistema depois do deploy.
            $table->boolean('is_active')->default(true)->after('email');
        });

        Schema::table('technicians', function (Blueprint $table) {
            // Nullable porque hoje existem técnicos sem usuário vinculado.
            // Unique garante que um usuário se liga a no máximo um técnico
            // (MySQL permite múltiplos NULL em índice unique).
            $table->foreignId('user_id')
                ->nullable()
                ->unique()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverte a migration.
     */
    public function down(): void
    {
        Schema::table('technicians', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
