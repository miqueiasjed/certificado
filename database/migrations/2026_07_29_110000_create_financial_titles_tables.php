<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estrutura de contas a receber e a pagar (Plano 18, Task 18.1): seis tabelas
 * novas, nenhuma alteração em tabela existente.
 *
 * `payment_details` e `financial_entries` continuam intactas e são a fonte
 * dos painéis financeiros atuais até a Task 18.7 trocar a leitura. Esta
 * migration só cria estrutura vazia; a Task 18.2 é quem transporta o dado de
 * hoje para cá, com conferência de totais.
 *
 * Decisões da modelagem:
 *
 * - **Título e parcela em tabelas separadas**, tanto do lado de receber quanto
 *   do lado de pagar. Um título com parcela única é caso particular, não
 *   exceção: guardar o vencimento no título forçaria duplicar o título inteiro
 *   a cada parcela de um parcelamento.
 * - **`payables` espelha `receivables`**, trocando `client_id` por
 *   `supplier_id` e acrescentando `recorrencia`/`recorrencia_ate` para
 *   despesa que se repete (aluguel, assinatura). A geração das próximas
 *   competências é regra de negócio da Task 18.5, não desta migration.
 * - **`payment_detail_id` e `financial_entry_id` em `receivable_installments`
 *   existem para a Task 18.2 amarrar o dado de hoje ao título novo sem apagar
 *   nada do que o cliente já usa.** `payable_installments` não repete
 *   `payment_detail_id`: o sistema atual só registra recebimento de cliente,
 *   nunca pagamento a fornecedor, então não existe dado legado de pagável para
 *   amarrar. `financial_entry_id` continua presente nas duas tabelas de
 *   parcela porque as duas precisam apontar para o lançamento de caixa que a
 *   baixa cria (entrada na Task 18.4, saída na Task 18.5), pelo mesmo ponto
 *   único de integração com o caixa.
 * - **`vencimento`, `emitido_em`, `pago_em` e `recorrencia_ate` são `date`.**
 *   Representam um dia, não um instante, e por isso nunca sofrem conversão de
 *   fuso; a comparação de vencido é feita por dia no fuso do negócio, via
 *   `App\Support\BusinessDate`, nunca com `now()` em UTC (um título que vence
 *   hoje não pode aparecer como vencido às 21h de Brasília).
 * - **`enum` nas colunas de tipo e situação fechadas pelo fluxo do plano.** O
 *   banco recusar um valor inventado vale mais que a facilidade de acrescentar
 *   um valor a mais depois, mesmo padrão de `stock_movements.tipo` e
 *   `devices.situacao`.
 * - **`chart_of_accounts.parent_id` com `restrictOnDelete`.** Categoria com
 *   subcategoria vinculada não pode ser apagada em silêncio: quem quiser
 *   apagar precisa primeiro mover ou apagar as subcategorias, para a
 *   hierarquia nunca ficar inconsistente sem que alguém decida isso de
 *   propósito.
 * - **`client_id` e `supplier_id` com `restrictOnDelete`.** Título financeiro
 *   é documento com valor perante fiscalização; cliente ou fornecedor com
 *   título vinculado não pode ser apagado, só desativado.
 * - **`work_order_id`, `contract_id` e `chart_of_account_id` com
 *   `nullOnDelete`.** O título continua existindo mesmo que a OS, o contrato
 *   ou a categoria de origem sejam apagados depois; só perde o vínculo, o
 *   mesmo critério já usado em `stock_movements.work_order_id`.
 * - **`receivable_id` e `payable_id`, em suas respectivas parcelas, com
 *   `cascadeOnDelete`.** A parcela não existe fora do título dono: apagar o
 *   título apaga as parcelas dele.
 * - **`payment_detail_id` e `financial_entry_id`, nas parcelas, com
 *   `nullOnDelete`.** São vínculo de rastreio com o dado legado ou com o
 *   lançamento de caixa; perder o vínculo não pode impedir a exclusão do
 *   registro apontado.
 * - **Uniques compostas com `company_id`**, como manda a skill
 *   `permissoes-e-multitenancy`: `[company_id, codigo]` em
 *   `chart_of_accounts` e `[receivable_id, numero]` /
 *   `[payable_id, numero]` nas parcelas (a parcela já está isolada por
 *   pertencer a um título de uma empresa só, mas o índice cobre também a
 *   consulta pelo título).
 * - **`company_id` sem valor padrão**, mesmo critério de
 *   `stock_locations` e `company_availability_settings`: tabelas que nascem
 *   depois da trait `BelongsToCompany` já aplicada a todo model de domínio.
 *
 * Tabelas novas, sem dado existente: um único deploy, sem a divisão em etapas
 * exigida para tabela que já tem dado em produção.
 */
return new class extends Migration
{
    /**
     * Tipos de conta do plano de contas.
     *
     * @var array<int, string>
     */
    private const TIPOS_DE_CONTA = ['receita', 'despesa'];

    /**
     * Situação do título (receivable ou payable), igual nos dois lados.
     *
     * @var array<int, string>
     */
    private const SITUACOES_DE_TITULO = ['aberto', 'parcial', 'quitado', 'cancelado'];

    /**
     * Situação da parcela, igual em receivable_installments e
     * payable_installments.
     *
     * @var array<int, string>
     */
    private const SITUACOES_DE_PARCELA = ['aberta', 'parcial', 'paga', 'vencida', 'cancelada'];

    /**
     * Recorrência de um título a pagar.
     *
     * @var array<int, string>
     */
    private const RECORRENCIAS = ['nenhuma', 'mensal', 'trimestral', 'anual'];

    public function up(): void
    {
        $this->criarChartOfAccounts();
        $this->criarSuppliers();
        $this->criarReceivables();
        $this->criarReceivableInstallments();
        $this->criarPayables();
        $this->criarPayableInstallments();
    }

    /**
     * Ordem inversa da criação: parcela e título dependente primeiro, tabela
     * raiz por último.
     */
    public function down(): void
    {
        Schema::dropIfExists('payable_installments');
        Schema::dropIfExists('payables');
        Schema::dropIfExists('receivable_installments');
        Schema::dropIfExists('receivables');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('chart_of_accounts');
    }

    /**
     * Plano de contas: categoria de receita ou despesa, com hierarquia.
     */
    private function criarChartOfAccounts(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->string('codigo');
            $table->string('nome');
            $table->enum('tipo', self::TIPOS_DE_CONTA);

            // Auto-relacionamento para hierarquia (ex.: "Despesas > Aluguel").
            // restrictOnDelete: ver o cabeçalho sobre apagar categoria com
            // subcategoria vinculada.
            $table->foreignId('parent_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();

            $table->boolean('ativo')->default(true);

            $table->timestamps();

            // O mesmo código não se repete dentro da empresa; empresas
            // diferentes podem repetir o código.
            $table->unique([DominioMultiempresa::COLUNA_TENANT, 'codigo']);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Fornecedor: quem a empresa paga.
     */
    private function criarSuppliers(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->string('nome');
            $table->string('documento')->nullable();
            $table->string('email')->nullable();
            $table->string('telefone')->nullable();
            $table->text('observacao')->nullable();
            $table->boolean('ativo')->default(true);

            $table->timestamps();

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Título a receber: o que a empresa tem para cobrar de um cliente.
     */
    private function criarReceivables(): void
    {
        Schema::create('receivables', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            // Título é documento perante fiscalização: cliente com título
            // vinculado não pode ser apagado, só desativado.
            $table->foreignId('client_id')->constrained()->restrictOnDelete();

            // Origem opcional do título. O título sobrevive à exclusão da OS
            // ou do contrato, só perde o vínculo.
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chart_of_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();

            $table->string('descricao');
            $table->decimal('valor_total', 10, 2);

            // Dia sem hora relevante: nunca sofre conversão de fuso.
            $table->date('emitido_em');

            $table->enum('situacao', self::SITUACOES_DE_TITULO)->default('aberto');

            $table->timestamps();

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Parcela do título a receber. `payment_detail_id` e `financial_entry_id`
     * existem para a Task 18.2 amarrar o dado atual ao título novo.
     */
    private function criarReceivableInstallments(): void
    {
        Schema::create('receivable_installments', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            // A parcela não existe fora do título dono.
            $table->foreignId('receivable_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('numero');
            $table->decimal('valor', 10, 2);

            // Dia sem hora relevante: a comparação de vencido é por dia no
            // fuso do negócio, via App\Support\BusinessDate.
            $table->date('vencimento');

            $table->decimal('valor_pago', 10, 2)->default(0);
            $table->date('pago_em')->nullable();

            $table->enum('situacao', self::SITUACOES_DE_PARCELA)->default('aberta');

            // Vínculo com o registro atual, usado só na migração da
            // Task 18.2. nullOnDelete: perder o vínculo não pode impedir a
            // exclusão do registro apontado.
            $table->foreignId('payment_detail_id')->nullable()->constrained()->nullOnDelete();

            // Lançamento de caixa criado pela baixa (Task 18.4) ou pela
            // migração (Task 18.2).
            $table->foreignId('financial_entry_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // Mesmo número de parcela não se repete no mesmo título.
            $table->unique(['receivable_id', 'numero']);

            // Consulta de aging e de previsão de caixa (Task 18.6): parcelas
            // por situação, ordenadas por vencimento.
            $table->index(['situacao', 'vencimento']);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Título a pagar: o que a empresa deve a um fornecedor. Espelha
     * `receivables`, trocando `client_id` por `supplier_id`, mais
     * `recorrencia` e `recorrencia_ate` para despesa que se repete.
     */
    private function criarPayables(): void
    {
        Schema::create('payables', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            // Mesmo critério de client_id em receivables: fornecedor com
            // título vinculado não pode ser apagado, só desativado.
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();

            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chart_of_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();

            $table->string('descricao');
            $table->decimal('valor_total', 10, 2);
            $table->date('emitido_em');
            $table->enum('situacao', self::SITUACOES_DE_TITULO)->default('aberto');

            // Recorrência (Task 18.5): título com recorrencia diferente de
            // "nenhuma" gera a próxima competência até recorrencia_ate.
            $table->enum('recorrencia', self::RECORRENCIAS)->default('nenhuma');
            $table->date('recorrencia_ate')->nullable();

            $table->timestamps();

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Parcela do título a pagar. Sem `payment_detail_id`: o sistema atual não
     * tem dado de pagamento a fornecedor para migrar, só recebimento de
     * cliente. `financial_entry_id` fica, para a baixa da Task 18.5 apontar
     * para a saída de caixa que ela cria, pelo mesmo ponto único de
     * integração com o caixa usado do lado de receber.
     */
    private function criarPayableInstallments(): void
    {
        Schema::create('payable_installments', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('payable_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('numero');
            $table->decimal('valor', 10, 2);
            $table->date('vencimento');
            $table->decimal('valor_pago', 10, 2)->default(0);
            $table->date('pago_em')->nullable();
            $table->enum('situacao', self::SITUACOES_DE_PARCELA)->default('aberta');

            $table->foreignId('financial_entry_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->unique(['payable_id', 'numero']);
            $table->index(['situacao', 'vencimento']);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }
};
