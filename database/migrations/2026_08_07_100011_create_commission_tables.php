<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estrutura de comissão do Plano 23: a regra, a comissão apurada por período e
 * o item que a compõe. Três tabelas novas, sem dado existente: um único
 * deploy, sem a divisão em etapas exigida para tabela que já tem dado em
 * produção.
 *
 * Nenhuma regra de cálculo mora aqui. Esta migration só cria a estrutura vazia;
 * apurar, fechar e pagar comissão é regra de negócio da Task 23.2
 * (`CommissionCalculationService`/`CommissionClosingService`).
 *
 * Decisões da modelagem:
 *
 * - **`commission_rules.base` tem `recebido` como padrão, não `vendido`.**
 *   Decisão de negócio deliberada, registrada no INDEX do Plano 23: comissão
 *   paga sobre venda que o cliente nunca pagou é problema conhecido do setor,
 *   e o padrão do sistema protege a empresa. Quem prefere comissionar sobre o
 *   vendido ou sobre o executado configura a regra para isso.
 * - **`commission_rules.user_id` e `technician_id` nullable, e os dois
 *   podem ficar vazios ao mesmo tempo.** Regra com as duas colunas nulas é a
 *   regra geral da empresa (vale para todo mundo do `tipo` dela); regra com
 *   uma delas preenchida é a exceção daquela pessoa. Quem decide qual regra
 *   vale para qual fato é a Task 23.2, escolhendo a mais específica
 *   disponível na data do fato.
 * - **`commission_rules.vigencia_inicio`/`vigencia_fim`.** Mudar o percentual
 *   de uma regra não pode recalcular a comissão de uma competência já
 *   fechada: a regra vale por um intervalo de tempo, e a Task 23.2 sempre
 *   escolhe a regra vigente na data do fato (venda, recebimento ou execução),
 *   nunca a regra de hoje. `vigencia_fim` nulo é regra sem data para acabar.
 * - **`commissions.user_id` e `technician_id` nullable, um preenchido por
 *   vez.** Uma comissão é de um vendedor (`user_id`) ou de um técnico
 *   (`technician_id`), nunca das duas pessoas ao mesmo tempo; qual das duas
 *   colunas vem preenchida é decidido pela apuração (Task 23.2), a partir do
 *   `tipo` da regra aplicada.
 * - **Unique de `commissions` não é uma unique comum do Laravel.** A
 *   especificação pede `[company_id, user_id, technician_id, competencia]`,
 *   mas o MySQL não compara `NULL` como igual a `NULL` dentro de uma unique
 *   composta: como cada comissão preenche só uma das colunas `user_id`/
 *   `technician_id`, deixando a outra `NULL`, duas comissões da MESMA pessoa
 *   na mesma competência passariam pela unique comum sem violação nenhuma
 *   (confirmado empiricamente neste banco antes de escrever esta migration).
 *   Por isso a unique é uma unique funcional (`ALTER TABLE ... ADD UNIQUE
 *   INDEX (...)`, suportada desde o MySQL 8.0.13), com
 *   `COALESCE(user_id, 0)` e `COALESCE(technician_id, 0)` no lugar das duas
 *   colunas: `0` nunca é um id real (ids começam em 1), então o `COALESCE`
 *   só troca "vazio" por um valor que o banco compara de verdade, sem mudar o
 *   dado gravado nem o tipo das colunas. `company_id` continua sendo o
 *   primeiro membro da unique funcional, por isso
 *   `DominioMultiempresa::conferirUniques()` reconhece a unique como composta
 *   com o tenant sem precisar de nenhuma entrada extra em
 *   `UNIQUES_A_COMPOR`/`UNIQUES_GLOBAIS_MANTIDOS` (o Schema do Laravel só
 *   devolve as colunas de verdade da unique via `information_schema`, e
 *   `company_id` é uma delas).
 * - **`commission_items.origem_tipo`/`origem_id` sem chave estrangeira**, no
 *   mesmo padrão de `audit_logs.auditable_type`/`auditable_id`: `origem_id`
 *   aponta para `budgets`, `receivable_installments` ou `work_orders`
 *   dependendo de `origem_tipo`, e uma FK de verdade não pode apontar para
 *   tabelas diferentes. A integridade desse vínculo é responsabilidade de
 *   quem grava o item (Task 23.2), nunca do banco.
 * - **`commission_items.commission_rule_id` com `restrictOnDelete`.** O item
 *   grava o percentual/valor aplicado no momento da apuração (`base_valor`,
 *   `percentual_ou_fixo`, `valor`), e não só a referência à regra, mas a
 *   referência continua existindo para auditoria; apagar uma regra já usada
 *   apagaria o rastro de qual regra gerou aquele item. Regra que não deve
 *   mais valer é desativada (`ativa = false`), nunca apagada.
 * - **`commission_items.commission_id` com `cascadeOnDelete`.** O item não
 *   existe fora da comissão dona, mesmo critério de
 *   `receivable_installments.receivable_id` em relação a `receivables`.
 * - **`commissions.user_id`/`technician_id`/`fechada_por` e
 *   `commission_rules.user_id`/`technician_id`/`service_type_id` com
 *   `nullOnDelete`.** São todas colunas já nullable por natureza (regra geral,
 *   ou a pessoa/categoria não se aplica); perder o vínculo não pode impedir a
 *   exclusão do registro apontado.
 * - **`commissions.payable_id` com `nullOnDelete`.** É a despesa gerada no
 *   Plano 18 quando a comissão é marcada como paga (Task 23.2); a comissão
 *   continua existindo e continua contando a própria história mesmo que o
 *   título a pagar seja excluído depois.
 * - **`fechada_em`/`paga_em` são `timestamp`, não `date`.** São o instante em
 *   que a ação aconteceu (fechamento, pagamento), não um dia de vigência;
 *   `ocorrido_em`, em `commission_items`, é o dia do fato que originou o
 *   item (venda, recebimento ou execução), e por isso fica `date`, mesmo
 *   critério de `vencimento`/`pago_em` em `receivable_installments`.
 * - **`company_id` sem valor padrão**, mesmo critério das demais tabelas de
 *   domínio recentes: nascem depois da trait `BelongsToCompany` já aplicada a
 *   todo model, e todo insert passa por ela.
 */
return new class extends Migration
{
    /**
     * Nome da unique funcional de `commissions`, para `up()` e `down()` não
     * repetirem a string.
     */
    private const COMMISSIONS_UNIQUE = 'commissions_pessoa_competencia_unique';

    public function up(): void
    {
        $this->criarCommissionRules();
        $this->criarCommissions();
        $this->criarCommissionItems();
    }

    /**
     * Ordem inversa da criação: tabela filha primeiro, tabela raiz por
     * último. `Schema::dropIfExists()` já derruba a unique funcional de
     * `commissions` junto com a tabela, sem passo manual.
     */
    public function down(): void
    {
        Schema::dropIfExists('commission_items');
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('commission_rules');
    }

    /**
     * Regra de comissão: percentual ou valor fixo, por vendedor, por técnico
     * ou geral da empresa, com vigência.
     */
    private function criarCommissionRules(): void
    {
        Schema::create('commission_rules', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            // Nulo nas duas: regra geral da empresa para o `tipo`. Preenchido
            // em uma: regra específica daquela pessoa.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('tipo', ['vendedor', 'tecnico']);

            // Padrão "recebido": ver o cabeçalho desta migration para o
            // raciocínio completo da decisão de negócio.
            $table->enum('base', ['recebido', 'vendido', 'executado'])->default('recebido');

            $table->enum('forma', ['percentual', 'valor_fixo']);
            $table->decimal('valor', 10, 4);

            // Regra por tipo de serviço, opcional.
            $table->foreignId('service_type_id')->nullable()->constrained()->nullOnDelete();

            // Vigência: a regra usada na apuração é a que vale na data do
            // fato, nunca a regra de hoje.
            $table->date('vigencia_inicio');
            $table->date('vigencia_fim')->nullable();

            $table->boolean('ativa')->default(true);

            $table->timestamps();

            // Apoio à busca da "regra vigente" por pessoa (Task 23.2).
            $table->index(['user_id', 'technician_id', 'ativa'], 'commission_rules_pessoa_ativa_index');

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Comissão apurada de uma pessoa (vendedor ou técnico) em uma
     * competência.
     */
    private function criarCommissions(): void
    {
        Schema::create('commissions', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            // Uma comissão é de um vendedor OU de um técnico: só uma das duas
            // colunas vem preenchida. Ver o cabeçalho desta migration sobre a
            // unique funcional que isso exige.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('technician_id')->nullable()->constrained()->nullOnDelete();

            // "YYYY-MM".
            $table->string('competencia', 7);

            $table->enum('situacao', ['aberta', 'fechada', 'paga'])->default('aberta');
            $table->decimal('valor_total', 10, 2)->default(0);

            $table->timestamp('fechada_em')->nullable();
            $table->foreignId('fechada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paga_em')->nullable();

            // Despesa gerada no Plano 18 quando a comissão é marcada como
            // paga (Task 23.2).
            $table->foreignId('payable_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // Apoio ao fechamento por competência (Task 23.2).
            $table->index(['situacao', 'competencia'], 'commissions_situacao_competencia_index');

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        // Unique funcional: ver o cabeçalho desta migration para o
        // raciocínio completo sobre por que uma unique comum não bastava.
        DB::statement(
            'ALTER TABLE commissions ADD UNIQUE INDEX '.self::COMMISSIONS_UNIQUE.' '
            .'(company_id, (COALESCE(user_id, 0)), (COALESCE(technician_id, 0)), competencia)'
        );
    }

    /**
     * Item que compõe uma comissão apurada: um fato (venda, recebimento ou
     * execução) com o percentual/valor aplicado gravado no momento da
     * apuração, para a comissão continuar auditável mesmo que a regra mude
     * depois.
     */
    private function criarCommissionItems(): void
    {
        Schema::create('commission_items', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('commission_id')->constrained()->cascadeOnDelete();

            // Sem chave estrangeira de propósito: origem_id aponta para
            // budgets, receivable_installments ou work_orders dependendo de
            // origem_tipo. Mesmo padrão de audit_logs.auditable_type/id.
            $table->enum('origem_tipo', ['orcamento', 'parcela', 'ordem_servico']);
            $table->unsignedBigInteger('origem_id');

            // restrictOnDelete: a regra usada precisa continuar existindo
            // para auditoria, mesmo com os valores já duplicados abaixo.
            $table->foreignId('commission_rule_id')->constrained()->restrictOnDelete();

            $table->decimal('base_valor', 10, 2);
            $table->decimal('percentual_ou_fixo', 10, 4);
            $table->decimal('valor', 10, 2);

            // Dia sem hora relevante: o dia do fato (venda, recebimento ou
            // execução), nunca um instante.
            $table->date('ocorrido_em');

            $table->timestamps();

            $table->index(['origem_tipo', 'origem_id'], 'commission_items_origem_index');

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }
};
