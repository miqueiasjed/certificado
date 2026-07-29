<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estrutura de estoque com lote, validade e custo (Plano 17, Task 17.1):
 * `stock_locations`, `product_batches`, `stock_movements` e `stock_balances`.
 *
 * Até aqui `products` era só ficha técnica. Estas quatro tabelas respondem
 * duas perguntas que o sistema não responde hoje: quanto tem de cada produto,
 * e qual lote foi aplicado em qual cliente, que é a primeira pergunta da
 * fiscalização em caso de incidente.
 *
 * Decisões da modelagem:
 *
 * - **Razão e saldo em tabelas separadas.** `stock_movements` é o histórico
 *   imutável (o razão) e `stock_balances` é o saldo corrente. Recalcular o
 *   saldo somando movimentos a cada consulta fica lento com o volume de um
 *   ano, e a tela de disponibilidade é consultada o tempo todo. As duas
 *   precisam bater sempre, e é a Task 17.2 que garante isso escrevendo as duas
 *   na mesma transação, com `lockForUpdate` na linha de saldo.
 * - **`quantidade` sempre positiva, com o sinal dado pelo `tipo`.** A coluna
 *   não é `unsigned` (decimal unsigned está descontinuado no MySQL 8), então
 *   quem garante o sinal é o `StockService` da Task 17.2. Guardar quantidade
 *   negativa em algumas linhas e positiva em outras gera erro de sinal
 *   invertido que ninguém encontra depois, porque o total continua parecendo
 *   plausível.
 * - **`saldo_apos` gravado em cada movimento.** É o que permite auditar a
 *   sequência do razão sem recalcular a série inteira, e é como se descobre
 *   em que ponto saldo e razão divergiram, se um dia divergirem.
 * - **`custo_unitario` com 4 casas decimais.** Saneante de dosagem baixa tem
 *   custo por mililitro em fração pequena; com 2 casas o custo unitário
 *   arredondaria para zero e a margem do serviço sairia inflada. Mesmo motivo
 *   para as quantidades: fração de mililitro é o dia a dia da aplicação.
 * - **Uniques todos compostos com `company_id`**, como manda a skill
 *   `permissoes-e-multitenancy`: unique global aqui impediria a segunda
 *   empresa de cadastrar um depósito chamado "Depósito" ou o lote "L-001".
 * - **Cuidado com o unique de `stock_balances` no MySQL:** o banco trata NULL
 *   como valor distinto em índice unique, então a restrição **não** impede
 *   duas linhas de mesmo produto e local quando `product_batch_id` é nulo
 *   (produto sem controle de lote). Quem serializa esse caso é o
 *   `lockForUpdate` da Task 17.2; a unique cobre o caso com lote.
 * - **`transferencia_id` (uuid) liga os dois movimentos de uma
 *   transferência.** `StockService::transferir()` (Task 17.2) gera um valor e
 *   grava nos dois movimentos, na mesma transação; é o que permite conferir
 *   que uma transferência não gravou só metade.
 * - **`inventory_id` sem chave estrangeira nesta migration.** A tabela
 *   `inventories` só nasce na Task 17.5, em deploy posterior. A coluna já fica
 *   aqui, nullable e indexada, para o movimento de ajuste gerado pelo
 *   inventário apontar para a contagem que o originou; a restrição é criada
 *   junto com a tabela, na 17.5.
 * - **Movimento nunca é apagado, e por isso `restrictOnDelete` em
 *   `product_id`, `product_batch_id`, `stock_location_id` e `user_id`.**
 *   Cascatear apagaria justamente o histórico que vale perante fiscalização, e
 *   anular deixaria o razão sem dizer de que produto era. Na prática isso
 *   significa que produto com movimentação não é mais excluível, o que é o
 *   comportamento desejado: a saída é desativar. `ProductController::destroy`
 *   já trata a exceção e devolve a mensagem de erro na tela, sem quebrar.
 * - **`work_order_id` com `nullOnDelete`.** A baixa aconteceu no mundo real
 *   mesmo que a OS seja apagada depois: o movimento fica, só perde o vínculo
 *   com a visita. Mesmo critério já usado em `work_orders.technician_id`.
 * - **`technician_id` em `stock_locations` com `nullOnDelete`.** Técnico
 *   desligado não pode levar embora o saldo que está na van dele: o local
 *   continua existindo, com o saldo visível para ser transferido de volta ao
 *   depósito, só sem dono.
 * - **`validade` e `recebido_em` como `date`.** São dias, não instantes, e
 *   campo `date` nunca sofre conversão de fuso. A comparação de vencido é
 *   feita por dia no fuso do negócio, via `App\Support\BusinessDate`
 *   (Task 17.3), nunca com `now()` em UTC.
 * - **`ocorrido_em` como `datetime`.** Aqui a hora importa (duas baixas do
 *   mesmo lote no mesmo dia precisam de ordem), então é instante, gravado em
 *   UTC como todo campo de instante do projeto e convertido só na exibição.
 * - **`enum` em vez de `string` em `tipo`.** Valores fechados, definidos pelo
 *   fluxo do plano; o banco recusar um valor inventado vale mais que a
 *   facilidade de acrescentar um sétimo depois. Mesmo padrão de
 *   `devices.situacao`.
 * - **`company_id` sem valor padrão**, mesmo critério de
 *   `company_availability_settings`: tabela nasce depois da trait
 *   `BelongsToCompany` já aplicada a todo model de domínio.
 *
 * Tabelas novas, sem dado existente: um único deploy, sem a divisão em etapas
 * exigida para tabela que já tem dado em produção. As colunas novas de
 * `products` vêm na migration seguinte, no mesmo deploy, todas com valor
 * padrão que descreve o estado atual (`controla_estoque = false`).
 */
return new class extends Migration
{
    /**
     * Tipos de local de estoque.
     *
     * `veiculo` já entra aqui e fica sem uso até o Plano 27 (frota): acrescentar
     * valor a enum depois é alteração de tabela, e este é o momento barato.
     *
     * @var array<int, string>
     */
    private const TIPOS_DE_LOCAL = ['deposito', 'tecnico', 'veiculo'];

    /**
     * Tipos de movimento. O sinal aplicado ao saldo vem daqui, nunca do sinal
     * da coluna `quantidade`.
     *
     * @var array<int, string>
     */
    private const TIPOS_DE_MOVIMENTO = [
        'entrada',
        'saida',
        'transferencia_saida',
        'transferencia_entrada',
        'ajuste',
        'descarte',
    ];

    public function up(): void
    {
        $this->criarLocais();
        $this->criarLotes();
        $this->criarMovimentos();
        $this->criarSaldos();
    }

    /**
     * Ordem inversa da criação: saldo e razão apontam para lotes e locais.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('product_batches');
        Schema::dropIfExists('stock_locations');
    }

    /**
     * Onde o produto está: depósito da empresa, van do técnico ou veículo.
     *
     * Estoque que só existe no depósito não fecha com a realidade: o produto
     * sai do depósito e fica com o técnico antes de ser aplicado.
     */
    private function criarLocais(): void
    {
        Schema::create('stock_locations', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->string('nome');
            $table->enum('tipo', self::TIPOS_DE_LOCAL);

            // Dono do local quando o tipo é `tecnico`. Ver o cabeçalho sobre
            // o nullOnDelete.
            $table->foreignId('technician_id')->nullable()->constrained()->nullOnDelete();

            $table->boolean('ativo')->default(true);

            $table->timestamps();

            // Cobre também a busca por empresa: company_id é a coluna à
            // esquerda do índice.
            $table->unique([DominioMultiempresa::COLUNA_TENANT, 'nome']);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * Lote comprado: validade, custo de aquisição e origem fiscal.
     *
     * `fornecedor` e `nota_fiscal` ficam como texto de propósito. O vínculo
     * com a conta a pagar do fornecedor é o Plano 18; até lá, o que importa é
     * conseguir rastrear de onde veio o lote.
     */
    private function criarLotes(): void
    {
        Schema::create('product_batches', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            $table->string('lote');

            // Dias, não instantes: campo `date` nunca sofre conversão de fuso.
            $table->date('validade');
            $table->date('recebido_em');

            // 4 casas: custo por mililitro de saneante concentrado é fração
            // pequena, e 2 casas arredondariam para zero.
            $table->decimal('custo_unitario', 10, 4);

            $table->string('fornecedor')->nullable();
            $table->string('nota_fiscal')->nullable();

            $table->timestamps();

            // O mesmo lote não entra duas vezes para o mesmo produto na mesma
            // empresa; empresas diferentes podem repetir o código de lote.
            $table->unique([DominioMultiempresa::COLUNA_TENANT, 'product_id', 'lote']);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * O razão: toda alteração de saldo vira uma linha aqui, e nenhuma linha é
     * apagada ou alterada depois. Correção é movimento de `ajuste` com motivo.
     */
    private function criarMovimentos(): void
    {
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // Nulo em produto sem controle de lote. O índice pedido pela
            // especificação vem da própria chave estrangeira: declarar
            // `index('product_batch_id')` criaria um segundo índice idêntico.
            $table->foreignId('product_batch_id')->nullable()->constrained()->restrictOnDelete();

            $table->foreignId('stock_location_id')->constrained()->restrictOnDelete();

            $table->enum('tipo', self::TIPOS_DE_MOVIMENTO);

            // Sempre positiva: o sinal aplicado ao saldo vem do `tipo`.
            $table->decimal('quantidade', 12, 4);

            // Saldo do produto/lote no local logo depois deste movimento.
            // Permite auditar a sequência sem recalcular a série inteira.
            $table->decimal('saldo_apos', 12, 4);

            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();

            // Liga os dois lados de uma transferência (`transferencia_saida` e
            // `transferencia_entrada`): mesmo valor nos dois movimentos,
            // gerado pelo `StockService::transferir()` (Task 17.2). Nulo em
            // todo outro tipo de movimento.
            $table->uuid('transferencia_id')->nullable();

            // Sem chave estrangeira aqui: `inventories` nasce na Task 17.5, em
            // deploy posterior, e é lá que a restrição é criada.
            $table->unsignedBigInteger('inventory_id')->nullable();

            $table->text('motivo')->nullable();

            // Quem movimentou. Sem nullOnDelete porque o razão precisa dizer
            // quem deu a baixa: usuário que movimentou estoque é desativado,
            // não apagado.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // Instante, gravado em UTC: duas baixas do mesmo lote no mesmo dia
            // precisam de ordem.
            $table->dateTime('ocorrido_em');

            $table->timestamps();

            // Consulta mais frequente do razão: extrato de um produto em um
            // local.
            $table->index(['product_id', 'stock_location_id']);

            // Movimentos gerados pelo fechamento de um inventário (Task 17.5).
            // A rastreabilidade por lote usa o índice que a chave estrangeira
            // de `product_batch_id` já cria.
            $table->index('inventory_id');

            // Localiza o par de uma transferência para conferir se os dois
            // lados foram gravados.
            $table->index('transferencia_id');

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    /**
     * O saldo corrente, uma linha por produto, lote e local.
     *
     * Derivada do razão, mantida na mesma transação que ele (Task 17.2), e
     * reconstruível por `StockService::recalcularSaldo()` em caso de
     * conferência.
     */
    private function criarSaldos(): void
    {
        Schema::create('stock_balances', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_batch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('stock_location_id')->constrained()->restrictOnDelete();

            $table->decimal('quantidade', 12, 4)->default(0);

            $table->timestamps();

            // Uma linha por combinação. Atenção ao NULL de product_batch_id no
            // MySQL: ver o cabeçalho.
            $table->unique(
                [DominioMultiempresa::COLUNA_TENANT, 'product_id', 'product_batch_id', 'stock_location_id'],
                'stock_balances_empresa_produto_lote_local_unique'
            );

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }
};
