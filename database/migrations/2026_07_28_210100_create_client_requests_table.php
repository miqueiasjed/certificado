<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitação de atendimento aberta pelo cliente no portal (Plano 15, Task 15.1).
 *
 * Depende de `client_users` já existir (esta migration roda depois dela),
 * porque toda solicitação é aberta por um usuário de cliente autenticado.
 *
 * Decisões da modelagem:
 *
 * - **`client_id` e `client_user_id` juntos**, e não só o segundo: o cliente é
 *   o dono do relacionamento comercial e sobrevive à saída de um usuário de
 *   acesso específico; o usuário é quem de fato abriu a solicitação. Guardar
 *   os dois evita um join a mais para chegar do cliente à solicitação.
 * - **`address_id` nullable**: a solicitação pode não estar amarrada a um
 *   endereço específico (dúvida geral, financeiro), então o vínculo é
 *   opcional.
 * - **`situacao` e `prioridade` como `enum`**, e não string solta: o catálogo
 *   das duas é fechado e não cresce a cada plano (ao contrário de
 *   `notification_templates.evento`, por exemplo), então o enum documenta a
 *   regra no próprio schema.
 * - **Índice em `[company_id, situacao]`**: é o filtro mais comum de uma fila
 *   de atendimento (abertas, em atendimento) e não pode varrer a tabela
 *   inteira a cada consulta.
 * - **`atendida_por` nullable com `nullOnDelete()`**: usuário removido não
 *   apaga o histórico de quem atendeu a solicitação, mesmo critério já usado
 *   em `sync_conflicts.resolvido_por` e `contract_visit_justifications.justified_by`.
 * - **`company_id` sem valor padrão**: mesma decisão de `client_users`, nasce
 *   depois da trait `BelongsToCompany` já aplicada a todo model de domínio.
 *
 * Tabela nova, sem dado existente: um único deploy, sem a divisão em etapas
 * exigida para tabela que já tem dado em produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_requests', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('address_id')->nullable()->constrained()->nullOnDelete();

            $table->string('assunto');
            $table->text('descricao');

            $table->enum('situacao', ['aberta', 'em_atendimento', 'resolvida', 'cancelada'])
                ->default('aberta');

            $table->enum('prioridade', ['baixa', 'normal', 'alta'])
                ->default('normal');

            $table->timestamp('respondida_em')->nullable();
            $table->text('resposta')->nullable();

            $table->foreignId('atendida_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(
                [DominioMultiempresa::COLUNA_TENANT, 'situacao'],
                'client_requests_empresa_situacao_index'
            );

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_requests');
    }
};
