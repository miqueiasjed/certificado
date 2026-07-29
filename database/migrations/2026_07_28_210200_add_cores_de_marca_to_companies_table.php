<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identidade visual do tenant para o portal do cliente (Plano 15, Task 15.6):
 * duas colunas novas, aditivas e opcionais, sem afetar nenhuma linha
 * existente.
 *
 * `cor_primaria` e `cor_destaque` guardam a cor em hexadecimal (ex.:
 * `#16a34a`), sem validação de formato no banco. Quem garante que só uma cor
 * hexadecimal válida vira variável CSS é o frontend
 * (`resources/js/utils/corDoPortal.js`, usado por `PortalLayout.vue`): o valor
 * cru nunca é interpolado direto em `<style>`. Empresa sem cor configurada (as
 * duas colunas nascem `nullable`, sem backfill) cai no verde padrão do
 * sistema.
 *
 * Nenhum formulário grava nestas colunas ainda:
 * `CompanyController::update()` valida com whitelist explícita de campos e não
 * inclui as duas novas, então adicioná-las a `Company::$fillable` não abre
 * brecha nenhuma no autoatendimento existente. Uma tela de configuração de
 * marca fica para uma task futura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('cor_primaria')->nullable()->after('logo_path');
            $table->string('cor_destaque')->nullable()->after('cor_primaria');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['cor_primaria', 'cor_destaque']);
        });
    }
};
