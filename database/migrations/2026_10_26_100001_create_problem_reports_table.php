<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relato de problema: o botão "Relatar problema" do painel e do portal do
 * cliente grava aqui, e o job `EnviarRelatoParaSistemaJob` repassa o relato ao
 * sistema central de suporte técnico, onde ele vira tarefa `bug`.
 *
 * Nada a ver com `monitoring_reports`, que é o relatório técnico de
 * monitoramento de pragas entregue ao cliente.
 *
 * Decisões da modelagem:
 *
 * - **`company_id` com escopo global**, como toda tabela de domínio: o relato
 *   nasce dentro de uma empresa. Quem lê todos os tenants juntos é a área da
 *   plataforma, pela saída explícita de `deTodasAsEmpresas()`.
 * - **`user_id` e `client_user_id` nullable, os dois.** Quem relata é um
 *   funcionário (`users`) no painel ou um usuário do portal
 *   (`client_users`). Só um dos dois vem preenchido, e os dois somem com
 *   `nullOnDelete` sem apagar o relato.
 * - **`titulo` com 200 caracteres**, o teto do contrato do sistema central.
 *   Nunca fica vazio: sem título digitado, o Service usa o começo da
 *   descrição.
 * - **`pagina_url` e `user_agent` com 500**, os tetos do contrato.
 * - **`situacao` em `enum`**, catálogo fechado (`novo`, `em_analise`,
 *   `resolvido`), mesmo critério de `compromissos.situacao`.
 * - **`sistema_task_id`, `sistema_task_url` e `enviado_em` nullable**:
 *   preenchidos só quando o sistema central confirma a tarefa (200 ou 201).
 *   `enviado_em` nulo é o relato que ainda não chegou ao suporte.
 * - **Índice em `created_at`**: a lista da plataforma ordena por ele, entre
 *   todos os tenants.
 *
 * Tabela nova, sem dado existente: um deploy só.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('problem_reports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('titulo', 200);
            $table->text('descricao');
            $table->string('pagina_url', 500)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->enum('situacao', ['novo', 'em_analise', 'resolvido'])->default('novo');

            $table->unsignedBigInteger('sistema_task_id')->nullable();
            $table->string('sistema_task_url', 500)->nullable();
            $table->timestamp('enviado_em')->nullable();

            $table->timestamps();

            $table->index('created_at', 'problem_reports_created_at_index');
            $table->index([DominioMultiempresa::COLUNA_TENANT, 'situacao'], 'problem_reports_empresa_situacao_index');

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('problem_reports');
    }
};
