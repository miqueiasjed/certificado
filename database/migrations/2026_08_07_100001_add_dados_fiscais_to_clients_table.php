<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->string('inscricao_municipal')->nullable();
            $table->string('inscricao_estadual')->nullable();
            $table->string('codigo_municipio_ibge')->nullable();
            $table->string('regime_tributario')->nullable();
            $table->string('email_nfe')->nullable();
        });

        Schema::table('addresses', function (Blueprint $table): void {
            $table->string('codigo_municipio_ibge')->nullable()->after('zip');
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropColumn('codigo_municipio_ibge');
        });

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn([
                'inscricao_municipal',
                'inscricao_estadual',
                'codigo_municipio_ibge',
                'regime_tributario',
                'email_nfe',
            ]);
        });
    }
};
