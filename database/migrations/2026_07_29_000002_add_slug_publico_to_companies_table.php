<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identificador estável do tenant na URL da página pública de agendamento
 * (Plano 16, Task 16.1): uma coluna nova, aditiva e opcional, sem afetar
 * nenhuma linha existente.
 *
 * `slug_publico` nasce nulo para toda empresa. Ele só é preenchido quando o
 * tenant ativa a página pública (tela de configuração de uma task futura do
 * Plano 16), e enquanto nulo a página pública simplesmente não existe para
 * aquela empresa. `nullable` com `unique` convive bem com isso: MySQL aceita
 * qualquer quantidade de linhas com `NULL` num índice único, e só passa a
 * exigir unicidade real a partir do primeiro slug preenchido.
 *
 * Nenhum formulário grava aqui ainda, mesma ressalva já registrada em
 * `add_cores_de_marca_to_companies_table`: adicionar a coluna a
 * `Company::$fillable` não abre brecha no autoatendimento existente porque
 * `CompanyController::update()` valida com whitelist explícita.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('slug_publico')->nullable()->unique()->after('cor_destaque');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('slug_publico');
        });
    }
};
