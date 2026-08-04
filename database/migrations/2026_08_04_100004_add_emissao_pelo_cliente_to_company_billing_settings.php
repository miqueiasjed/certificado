<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acrescenta a `company_billing_settings` a opção, por tenant, de o próprio
 * cliente final gerar a cobrança da própria parcela pelo portal (Plano 19,
 * Task 19.6, `POST /portal/faturas/{parcela}/cobranca`).
 *
 * `default(false)`: emissão pelo cliente é opt-in, nunca padrão ligado (regra
 * de negócio explícita da Task 19.6) - nasce desligada para toda empresa,
 * inclusive a que já tem linha em `company_billing_settings` desde a Task
 * 19.5, até que alguém ligue de propósito na tela de configuração (Task
 * 19.7). Coluna nova com valor padrão em tabela que já existe desde a task
 * anterior deste mesmo plano, sem dado de produção ainda: um único passo,
 * sem a divisão em etapas do CLAUDE.md (que vale para tabela com dado real).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_billing_settings', function (Blueprint $table): void {
            $table->boolean('emissao_pelo_cliente_habilitada')->default(false)->after('marcos_ativos');
        });
    }

    public function down(): void
    {
        Schema::table('company_billing_settings', function (Blueprint $table): void {
            $table->dropColumn('emissao_pelo_cliente_habilitada');
        });
    }
};
