<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Adicionar nova coluna status_new
        Schema::table('certificates', function (Blueprint $table) {
            $table->enum('status_new', ['active', 'expired', 'cancelled'])->default('active')->after('status');
        });

        // Copiar dados convertendo os valores
        DB::statement("UPDATE certificates SET status_new = CASE
            WHEN status = 'draft' THEN 'active'
            WHEN status = 'issued' THEN 'active'
            WHEN status = 'expired' THEN 'expired'
            WHEN status = 'cancelled' THEN 'cancelled'
            ELSE 'active'
        END");

        // Remover coluna antiga e renomear a nova
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn('status');
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->renameColumn('status_new', 'status');
        });
    }

    public function down(): void
    {
        // Reverter para os valores antigos
        DB::table('certificates')->where('status', 'active')->update(['status' => 'draft']);

        Schema::table('certificates', function (Blueprint $table) {
            $table->enum('status', ['draft', 'issued', 'expired', 'cancelled'])->default('draft')->change();
        });
    }
};
