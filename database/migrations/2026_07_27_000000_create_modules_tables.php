<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulos controláveis por plano, o vínculo deles com os planos e a liberação
 * pontual por tenant (Plano 6, Task 6.1).
 *
 * As três tabelas nascem na mesma migration porque nenhuma faz sentido sem as
 * outras duas: um módulo sem plano vinculado não libera nada, e uma liberação
 * pontual sem módulo não tem o que liberar.
 *
 * Nenhuma das três é de domínio, e por isso nenhuma leva `company_id` com a
 * trait `BelongsToCompany`, nem passa pelo escopo global por empresa. A
 * classificação fica em `App\Support\DominioMultiempresa::TABELAS_FORA_DO_ESCOPO`,
 * com o motivo de cada uma, e sem ela o `EscopoDeEmpresaTest` (Task 4.10)
 * derruba a suíte.
 *
 * - **`modules`**: catálogo de plataforma, igual para todos os tenants, no
 *   mesmo critério já aplicado a `plans` na Task 5.1. `chave` é o identificador
 *   estável usado pelo código (middleware, service, seeder); `nome` e
 *   `descricao` são o que aparece na tela. `sempre_ativo` existe para módulo
 *   que nenhum plano pode bloquear (clientes, OS, certificados são o produto,
 *   não um extra) e prevalece mesmo sem vínculo explícito com plano nenhum.
 *
 * - **`plan_module`**: pivot simples entre `plans` e `modules`, resolvendo só
 *   "este plano libera este módulo". Sem coluna própria além das duas chaves,
 *   por isso sem timestamps: não há o que auditar num vínculo binário que ou
 *   existe ou não existe.
 *
 * - **`company_modules`**: liberação pontual por tenant, gerida pelo super
 *   admin, que precisa enxergar e comparar todos os tenants na mesma tela.
 *   Mesmo tendo `company_id`, não é de domínio: o escopo global por empresa
 *   filtraria pela "empresa corrente", e não existe empresa corrente para o
 *   super admin nessa tela. `liberado = false` sobrepõe o plano (é assim que
 *   se bloqueia um módulo específico sem trocar o tenant de plano),
 *   `liberado_ate` permite liberação temporária (demonstração comercial) que
 *   expira sozinha, e `motivo` é o texto livre que justifica a exceção nos
 *   dois sentidos.
 *
 * Tabelas novas, sem dado existente: as três nascem no mesmo deploy, sem a
 * dança de três etapas que o CLAUDE.md exige para tabela já povoada em
 * produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table): void {
            $table->id();
            $table->string('chave')->unique();
            $table->string('nome');
            $table->text('descricao')->nullable();
            $table->integer('ordem')->default(0);
            $table->boolean('sempre_ativo')->default(false);
            $table->timestamps();
        });

        Schema::create('plan_module', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();

            $table->unique(['plan_id', 'module_id']);
        });

        Schema::create('company_modules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->boolean('liberado')->default(true);
            $table->string('motivo')->nullable();
            $table->date('liberado_ate')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'module_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_modules');
        Schema::dropIfExists('plan_module');
        Schema::dropIfExists('modules');
    }
};
