<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lote, custo congelado e pendência de estoque no registro de produto aplicado
 * da OS (Plano 17, Task 17.4).
 *
 * A tabela é `work_order_product`, o pivot de `work_orders` x `products` com
 * quantidade própria, que é onde o produto aplicado na visita chega hoje (o
 * caminho web por `WorkOrderService` e o caminho de campo do Plano 13). Não é
 * `pest_sighting_items`: aquela tabela guarda a praga avistada, não o produto
 * aplicado.
 *
 * Colunas
 * -------
 * - **`product_batch_id`**: lote de onde saiu o produto. É o que responde qual
 *   lote foi aplicado em qual cliente, primeira pergunta da fiscalização em
 *   caso de incidente. Quando a seleção por FEFO precisa de mais de um lote
 *   para cobrir a quantidade, aqui fica o lote de maior parcela e o detalhe
 *   completo fica no razão (`stock_movements`, uma linha por lote, com
 *   `work_order_id`), que é a fonte de rastreabilidade.
 * - **`custo_unitario_aplicado`**: custo do lote **congelado no momento da
 *   baixa**. O custo do lote pode mudar em compra futura, e o custo da OS de
 *   ontem não pode mudar junto: senão o relatório de margem de um mês já
 *   fechado muda sozinho depois. Mesmas 4 casas de `product_batches.custo_unitario`,
 *   porque saneante concentrado tem custo por mililitro em fração pequena e com
 *   2 casas arredondaria para zero.
 * - **`quantidade_pendente`**: quanto do produto ficou sem baixa por falta de
 *   saldo. Saldo insuficiente **não** impede concluir a OS (o serviço já
 *   aconteceu no mundo real), então a recusa vira pendência visível para o
 *   escritório resolver, no próprio registro do produto aplicado. Nulo ou zero
 *   significa sem pendência. Escolhida coluna aqui, e não tabela nova: a
 *   pendência é um atributo do item aplicado, some junto com ele e é
 *   consultável por OS sem join adicional.
 *
 * Chave estrangeira
 * -----------------
 * `product_batch_id` com `restrictOnDelete`, o mesmo critério de
 * `stock_movements`: lote aplicado em cliente não é apagável, porque apagar
 * levaria embora justamente o vínculo que vale perante fiscalização. A saída
 * para lote que não deveria mais ser usado é zerar o saldo por descarte
 * registrado.
 *
 * Deploy
 * ------
 * A tabela tem dado em produção, e por isso a regra do projeto é dividir
 * estrutura, backfill e restrição em deploys separados. Aqui só existe a
 * primeira etapa: três colunas **nullable**, sem valor padrão, sem backfill e
 * sem restrição nova sobre linha existente. As linhas antigas ficam com nulo,
 * que é a leitura correta ("esta OS é anterior ao controle de estoque"), e
 * nenhum código existente lê estas colunas. Não há etapa 2 nem 3 a fazer
 * depois: nulo continua sendo estado legítimo para sempre, porque produto sem
 * `controla_estoque` nunca recebe lote nem custo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_product', function (Blueprint $table): void {
            $table->foreignId('product_batch_id')
                ->nullable()
                ->after('product_id')
                ->constrained()
                ->restrictOnDelete();

            $table->decimal('custo_unitario_aplicado', 10, 4)
                ->nullable()
                ->after('quantity');

            $table->decimal('quantidade_pendente', 12, 4)
                ->nullable()
                ->after('custo_unitario_aplicado');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_product', function (Blueprint $table): void {
            $table->dropForeign(['product_batch_id']);
            $table->dropColumn(['product_batch_id', 'custo_unitario_aplicado', 'quantidade_pendente']);
        });
    }
};
