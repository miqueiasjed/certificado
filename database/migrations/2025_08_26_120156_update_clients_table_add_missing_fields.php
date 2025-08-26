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
        Schema::table('clients', function (Blueprint $table) {
            // Adicionar novos campos
            $table->string('state', 2)->nullable()->after('city');
            $table->string('zip_code', 9)->nullable()->after('state');
            $table->text('notes')->nullable()->after('address');

            // Remover campos antigos que não são mais usados
            $table->dropColumn(['date_of_birth', 'neighborhood', 'number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Reverter mudanças
            $table->date('date_of_birth')->after('cnpj');
            $table->string('neighborhood')->after('address');
            $table->string('number')->after('neighborhood');

            $table->dropColumn(['state', 'zip_code', 'notes']);
        });
    }
};
