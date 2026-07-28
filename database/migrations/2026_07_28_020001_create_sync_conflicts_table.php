<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conflito encontrado ao aplicar uma operação de sincronização (Plano 12,
 * Task 12.1).
 *
 * Nasce quando o servidor recebe uma `sync_operation` do aplicativo e o
 * estado que ela carrega não bate com o estado atual do registro no servidor
 * (alguém alterou ou removeu o registro no meio do tempo em que o técnico
 * ficou offline, a OS foi travada, ou uma regra de negócio impede aplicar
 * direto). O conflito fica visível para resolução manual: nunca é resolvido
 * automaticamente descartando o lado do técnico.
 *
 * Decisões da modelagem:
 *
 * - **`situacao` nasce sempre `aberto`.** Resolver automaticamente
 *   descartando o lado do aplicativo apagaria trabalho de campo do técnico
 *   sem revisão humana. A mudança de estado é ação explícita de quem resolve
 *   o conflito, feita pelo Service da Task 12.4, não por esta migration.
 * - **`motivo` e `situacao` são `string`, não `enum`**, pelo mesmo critério
 *   de `sync_operations.tipo`: o catálogo de motivos de conflito tende a
 *   crescer conforme novas regras de negócio entram no fluxo de campo, e
 *   enum aqui custaria um `ALTER TABLE` a cada uma.
 * - **`sync_operation_id` com `cascadeOnDelete()`.** O conflito só existe
 *   como desdobramento de uma operação; sem a operação, o registro do
 *   conflito não tem para onde apontar.
 * - **`work_order_id` nullable com `nullOnDelete()`**, mesmo critério de
 *   `sync_operations.work_order_id`: apagar a OS depois não pode derrubar o
 *   histórico do conflito.
 * - **`resolvido_por` nullable com `nullOnDelete()`.** Usuário removido não
 *   pode apagar o registro de quem resolveu o conflito; o fato de ter sido
 *   resolvido vale mais que o vínculo com quem resolveu.
 * - **Índice composto `[situacao, company_id]`.** É a consulta da tela de
 *   conflitos pendentes: conflitos em aberto de uma empresa, e um índice em
 *   só uma das duas colunas não serve as duas pontas do filtro.
 * - **`company_id` sem valor padrão**, mesmo critério de `sync_operations`:
 *   tabela nasce depois do Deploy 5, com a trait já ativa preenchendo a
 *   coluna.
 *
 * Tabela nova, sem dado existente: um único deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_conflicts', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('sync_operation_id')->constrained()->cascadeOnDelete();

            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();

            // registro_alterado, registro_removido, os_travada, regra_de_negocio.
            $table->string('motivo');

            $table->json('valor_do_aplicativo');
            $table->json('valor_do_servidor');

            // aberto, resolvido_aplicativo, resolvido_servidor, descartado.
            // Nasce sempre "aberto": ver o cabeçalho desta migration.
            $table->string('situacao');

            $table->foreignId('resolvido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolvido_em')->nullable();

            $table->timestamps();

            // Tela de conflitos pendentes: filtra por situação dentro de uma
            // empresa.
            $table->index(['situacao', DominioMultiempresa::COLUNA_TENANT]);

            $table->index(DominioMultiempresa::COLUNA_TENANT);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_conflicts');
    }
};
