<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos de renovação em `contracts` (Plano 23, Task 23.1). `end_date` já
 * existe desde sempre e nunca teve processo de renovação: o contrato vence e
 * ninguém é avisado, perda de receita recorrente silenciosa. Esta migration só
 * cria a estrutura; gerar o contrato novo, aplicar o reajuste e cancelar as
 * visitas futuras do anterior é regra de negócio da Task 23.4
 * (`ContractRenewalService`).
 *
 * `contracts` já tem contrato real de cliente em produção. Todas as colunas
 * aqui são novas e nullable, então o deploy é estrutural, sem backfill e sem
 * restrição: nenhum contrato existente muda de estado ao rodar esta migration,
 * e nenhuma tela atual lê ou grava estas colunas.
 *
 * Decisões da modelagem:
 *
 * - **`contrato_anterior_id`, auto-relacionamento nullable com
 *   `nullOnDelete`.** Preserva a cadeia de renovações inteira: o contrato
 *   novo aponta para o anterior, nunca o contrário, então a cadeia inteira é
 *   navegável do primeiro ao último seguindo esta coluna para trás (mesmo
 *   critério do vínculo de série usado em `payables.payable_origem_id`,
 *   trocando "aponta para o raiz" por "aponta para o imediatamente
 *   anterior", porque aqui o histórico de valor de CADA renovação individual
 *   importa, não só a série como um todo). `nullOnDelete`: hoje não existe
 *   exclusão de contrato no fluxo do sistema, só encerramento, mas caso o
 *   anterior seja apagado por algum caminho futuro, o novo não pode ser
 *   arrastado junto, só perde o vínculo.
 * - **`renovado_em` é `timestamp`, não `date`.** É o instante em que a
 *   renovação foi processada, não um dia de vigência do contrato (que já é
 *   `start_date`/`end_date`, ambos `date`).
 * - **`indice_reajuste` é `string` solta, não enum.** Guarda o nome do índice
 *   usado (IGPM, IPCA, INPC, ou nenhum quando o reajuste é por percentual
 *   fixo); o catálogo de índices econômicos não é fechado nem controlado por
 *   este sistema, mesmo critério já usado em `onboarding_steps.chave` e
 *   `notification_templates.evento`.
 * - **`percentual_reajuste` é `decimal(5,2)`**, suficiente para qualquer
 *   percentual de reajuste plausível (até 999,99%), no mesmo padrão de
 *   `budgets.discount`.
 * - **`motivo_nao_renovacao` é `text`.** Texto livre de por que o cliente não
 *   renovou, para o negócio entender depois por que perde cliente (Task
 *   23.4/23.8), não uma lista fechada de motivos.
 * - **`situacao_renovacao` é `enum` nullable, sem valor padrão.** Contrato
 *   sem nenhuma tratativa de renovação ainda fica `null`, e não `pendente`:
 *   `pendente` (Task 23.5) é um estado que a rotina de alerta atribui
 *   explicitamente quando o contrato entra na janela de vencimento, não algo
 *   que todo contrato já nasce carregando.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            $table->foreignId('contrato_anterior_id')
                ->nullable()
                ->after('end_date')
                ->constrained('contracts')
                ->nullOnDelete();

            $table->timestamp('renovado_em')->nullable()->after('contrato_anterior_id');
            $table->string('indice_reajuste')->nullable()->after('renovado_em');
            $table->decimal('percentual_reajuste', 5, 2)->nullable()->after('indice_reajuste');
            $table->enum('situacao_renovacao', ['pendente', 'renovado', 'nao_renovado', 'em_negociacao'])
                ->nullable()
                ->after('percentual_reajuste');
            $table->text('motivo_nao_renovacao')->nullable()->after('situacao_renovacao');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table): void {
            // A FK precisa cair antes da coluna, senão o banco recusa a
            // exclusão da coluna com "needed in a foreign key constraint".
            $table->dropForeign(['contrato_anterior_id']);

            $table->dropColumn([
                'contrato_anterior_id',
                'renovado_em',
                'indice_reajuste',
                'percentual_reajuste',
                'situacao_renovacao',
                'motivo_nao_renovacao',
            ]);
        });
    }
};
