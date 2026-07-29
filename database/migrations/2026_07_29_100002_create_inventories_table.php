<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventário com ajuste justificado (Plano 17, Task 17.5): `inventories` e
 * `inventory_items`.
 *
 * É o caminho de correção formal do estoque. `StockService::ajustar()`
 * (Task 17.2) já sabe gravar a diferença como movimento com motivo; o que
 * faltava era o processo que gera essa diferença a partir de uma contagem
 * física, com o saldo do sistema congelado no momento da abertura e a
 * justificativa exigida, e é isso que este par de tabelas sustenta.
 *
 * Decisões da modelagem:
 *
 * - **`inventories` é o cabeçalho da contagem, `inventory_items` é uma linha
 *   por combinação de produto e lote encontrada no local.** Um inventário sem
 *   item não conta nada; um item não existe fora de um inventário, e por isso
 *   `inventory_items.inventory_id` é `cascadeOnDelete()`, diferente da
 *   convenção de `stock_movements` (razão imutável, nunca apagado): o item é
 *   o registro de trabalho da contagem, não o razão em si.
 * - **`saldo_sistema` é congelado na abertura, e nunca recalculado.** É a
 *   fotografia do que o sistema achava que tinha quando a contagem começou.
 *   A comparação que decide o ajuste de verdade acontece depois, em
 *   `StockService::ajustar()`, contra o saldo **atual** (com
 *   `lockForUpdate`), porque a operação continua durante a contagem: um
 *   movimento registrado nesse meio-tempo não pode ser silenciosamente
 *   perdido nem contado a mais.
 * - **`saldo_contado`, `diferenca` e `justificativa` nascem nulos.** Um item
 *   sem `saldo_contado` é um item ainda não contado, e é assim que
 *   `InventoryService::finalizar()` (Task 17.5) recusa fechar inventário
 *   incompleto sem precisar de uma coluna de situação própria no item.
 * - **`situacao` de `inventories` como `enum` fechado.** Três estados que não
 *   crescem a cada plano (`aberto`, `finalizado`, `cancelado`), mesmo critério
 *   já usado para `client_requests.situacao` e `stock_movements.tipo`.
 *   `cancelado` entra aqui pela modelagem completa da Task 17.5, ainda que o
 *   fluxo de cancelamento em si fique para tarefa futura.
 * - **`aberto_por` obrigatório, sem `nullOnDelete()`.** Todo inventário nasce
 *   com um responsável; apagar o usuário depois não pode apagar quem abriu a
 *   contagem, mesmo critério de `stock_movements.user_id`.
 * - **`finalizado_por` e `finalizado_em` nullable, com `nullOnDelete()` no
 *   primeiro.** Só existem depois do fechamento, e usuário removido não pode
 *   apagar o histórico de quem fechou, mesmo critério já usado em
 *   `sync_conflicts.resolvido_por` e `client_requests.atendida_por`.
 * - **`stock_location_id` com `restrictOnDelete()`.** Local de estoque não
 *   tem exclusão física (é desativado pelo campo `ativo`), mas a restrição
 *   documenta a intenção: inventário é histórico, e não pode ficar órfão de
 *   local.
 * - **`product_id` e `product_batch_id` de `inventory_items` com
 *   `restrictOnDelete()`**, mesmo critério de `stock_movements`: o item
 *   registra o que foi contado, e produto ou lote com contagem no histórico
 *   deixa de ser excluível, o que é o comportamento desejado.
 * - **Decimais em 12,4**, as mesmas casas de `stock_movements` e
 *   `stock_balances`: fração de mililitro é o dia a dia da aplicação, e casas
 *   diferentes entre as tabelas produziriam arredondamento inconsistente na
 *   comparação.
 * - **A chave estrangeira de `stock_movements.inventory_id` nasce aqui, com
 *   `nullOnDelete()`.** A coluna já existia, nullable e sem restrição, desde a
 *   Task 17.1, à espera desta tabela. `nullOnDelete()` e não `restrictOnDelete()`
 *   nem `cascadeOnDelete()`: o movimento de ajuste é o razão, nunca apagado
 *   nem alterado (a mesma garantia que já vale para todo `stock_movement`), e
 *   apagar o cabeçalho do inventário (hipótese hoje sem rota no sistema) não
 *   pode arrastar com ele o histórico de estoque. O movimento fica, só perde o
 *   vínculo com a contagem que o originou, mesmo critério já usado em
 *   `stock_movements.work_order_id`.
 * - **Uniques compostos com `company_id`**, como manda a skill
 *   `permissoes-e-multitenancy`; aqui nenhuma unique de negócio é necessária
 *   além da chave primária, porque nada nestas duas tabelas identifica um
 *   registro por um valor de negócio (diferente de `stock_locations.nome` ou
 *   `product_batches.lote`).
 *
 * Tabelas novas, sem dado existente: um único deploy, sem a divisão em etapas
 * exigida para tabela que já roda em produção.
 */
return new class extends Migration
{
    /**
     * Situações do inventário.
     *
     * @var array<int, string>
     */
    private const SITUACOES = ['aberto', 'finalizado', 'cancelado'];

    public function up(): void
    {
        $this->criarInventarios();
        $this->criarItens();
        $this->adicionarChaveEstrangeiraDoMovimento();
    }

    /**
     * Ordem inversa da criação: a chave estrangeira de `stock_movements` sai
     * antes de `inventories` poder ser apagada, e os itens saem antes do
     * cabeçalho.
     */
    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropForeign(['inventory_id']);
        });

        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('inventories');
    }

    /**
     * O cabeçalho da contagem: um por local, por rodada de inventário.
     */
    private function criarInventarios(): void
    {
        Schema::create('inventories', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('stock_location_id')->constrained()->restrictOnDelete();

            $table->enum('situacao', self::SITUACOES)->default('aberto');

            // Quem abriu a contagem. Sem nullOnDelete: todo inventário tem um
            // responsável, mesmo critério de stock_movements.user_id.
            $table->foreignId('aberto_por')->constrained('users')->restrictOnDelete();
            $table->dateTime('aberto_em');

            // Só existem depois do fechamento.
            $table->foreignId('finalizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('finalizado_em')->nullable();

            $table->text('observacao')->nullable();

            $table->timestamps();

            // Consulta mais comum da tela de inventário: os inventários de um
            // local, mais recentes primeiro.
            $table->index([DominioMultiempresa::COLUNA_TENANT, 'stock_location_id']);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Uma linha por combinação de produto e lote que tinha saldo no local
     * quando o inventário foi aberto.
     */
    private function criarItens(): void
    {
        Schema::create('inventory_items', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            // O item não existe fora do inventário: apagar o cabeçalho
            // arrasta os itens, diferente do razão de stock_movements.
            $table->foreignId('inventory_id')->constrained()->cascadeOnDelete();

            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_batch_id')->nullable()->constrained()->restrictOnDelete();

            // Congelado na abertura: a fotografia do que o sistema achava que
            // tinha quando a contagem começou.
            $table->decimal('saldo_sistema', 12, 4);

            // Nulos até a contagem acontecer. Item sem saldo_contado é item
            // ainda não contado, e é assim que o fechamento sabe recusar
            // inventário incompleto.
            $table->decimal('saldo_contado', 12, 4)->nullable();
            $table->decimal('diferenca', 12, 4)->nullable();
            $table->text('justificativa')->nullable();

            $table->timestamps();

            // Consulta mais comum: todos os itens de um inventário.
            $table->index([DominioMultiempresa::COLUNA_TENANT, 'inventory_id']);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * A restrição que a Task 17.1 deixou pendente: `stock_movements.inventory_id`
     * aponta para o inventário que originou o ajuste.
     *
     * `nullOnDelete()`: ver a decisão detalhada no cabeçalho do arquivo. Em
     * resumo, o movimento é razão imutável e não pode ser arrastado pela
     * exclusão do cabeçalho do inventário.
     */
    private function adicionarChaveEstrangeiraDoMovimento(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreign('inventory_id')
                ->references('id')
                ->on('inventories')
                ->nullOnDelete();
        });
    }
};
