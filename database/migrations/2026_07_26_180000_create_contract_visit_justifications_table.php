<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Justificativa de data prevista de visita que não virou Ordem de Serviço.
 *
 * O painel de conformidade acusa contrato periódico com data prevista vencida
 * sem OS (RDC 622/2022). Hoje não existe saída dessa pendência: a geração
 * nunca cria OS com data no passado, por decisão registrada em
 * `GeracaoDeVisitasService::gerarPara`, então o contrato ficava no painel para
 * sempre. Esta tabela é a saída: o usuário registra o motivo daquela lacuna, a
 * pendência sai do painel e o motivo fica gravado como o documento de que a
 * empresa sabia da falha e por que ela existiu.
 *
 * Decisões da modelagem:
 *
 * - **Uma linha por data prevista**, nunca por contrato. Contrato com três
 *   lacunas exige três decisões conscientes (a ação em lote grava o mesmo
 *   motivo em várias datas de uma vez, e continua gravando uma linha por
 *   data). "Justificar o contrato inteiro para sempre" seria um cheque em
 *   branco perante fiscalização.
 * - **Unique composto (`company_id`, `contract_id`, `expected_date`)**: uma
 *   data tem uma justificativa. Segunda tentativa na mesma data é atualização
 *   de motivo, nunca duplicata. O `company_id` entra no unique pela regra do
 *   projeto (todo unique de domínio é composto com ele), mesmo com
 *   `contract_id` já implicando a empresa.
 * - **`reason` obrigatório**, com 500 caracteres. Justificativa vazia não
 *   serve para nada perante fiscalização, e é a validação de
 *   `ContractVisitJustificationRequest` que garante o conteúdo mínimo.
 * - **`justified_by_name` congelado** junto do `justified_by`, no mesmo
 *   padrão de `audit_logs.autor_nome`: usuário desativado ou removido não
 *   pode apagar quem assinou a justificativa.
 * - **`created_at` é o "quando"**, sem uma coluna `justified_at` paralela.
 *   Duas colunas para o mesmo instante só criam a chance de divergirem. O
 *   `updated_at` continua marcando a última alteração do motivo.
 * - **`contract_id` com cascadeOnDelete**: justificativa de contrato apagado
 *   não tem sentido nem consumidor. Contrato com histórico de visita executada
 *   já é protegido de exclusão em `ContractService::excluir()`.
 * - **`company_id` com `DEFAULT 1` temporário e FK RESTRICT**, igual às demais
 *   tabelas de domínio (ver
 *   `2026_07_26_160000_make_company_id_required_on_domain_tables`). O padrão
 *   temporário cai junto com o das outras, na migration de Deploy 5.
 *
 * Tabela nova, sem dado existente: é um passo só, sem a dança de três etapas
 * exigida para tabela em produção.
 */
return new class extends Migration
{
    /**
     * Valor padrão temporário da coluna de tenant. Mesmo motivo e mesmo destino
     * do declarado em `2026_07_26_160000_make_company_id_required_on_domain_tables`.
     */
    private const TENANT_PADRAO_TEMPORARIO = 1;

    public function up(): void
    {
        Schema::create('contract_visit_justifications', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT)
                ->default(self::TENANT_PADRAO_TEMPORARIO);

            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();

            // Dia sem hora relevante: é a data prevista do calendário do
            // contrato, e converter fuso em campo `date` é o que produz o erro
            // de um dia.
            $table->date('expected_date');

            $table->string('reason', 500);

            $table->foreignId('justified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('justified_by_name')->nullable();

            $table->timestamps();

            $table->unique(
                [DominioMultiempresa::COLUNA_TENANT, 'contract_id', 'expected_date'],
                'contract_visit_justifications_empresa_contrato_data_unique'
            );

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
        Schema::dropIfExists('contract_visit_justifications');
    }
};
