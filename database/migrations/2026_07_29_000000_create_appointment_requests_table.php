<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido de horário aberto pelo cliente (Plano 16, Task 16.1): origem tanto do
 * portal do cliente autenticado quanto da página pública de agendamento, que
 * não exige login.
 *
 * Decisões da modelagem:
 *
 * - **`client_id` e `client_user_id` nullable, os dois.** A página pública
 *   recebe pedido de quem ainda não é cliente da empresa. Transformar o
 *   solicitante em cliente cadastrado é decisão da empresa ao confirmar
 *   (Task 16.4), não algo que este pedido resolve sozinho.
 * - **`nome`, `email`, `telefone` como colunas próprias**, e não lidas de
 *   `clients` por relação. Quando `client_id` é nulo (pedido da página
 *   pública) não existe outra fonte para esses dados, e mesmo quando
 *   `client_id` está preenchido, o dado digitado no formulário é o retrato do
 *   pedido no momento em que foi feito, que pode divergir do cadastro atual.
 * - **`endereco_texto` nullable, alternativa a `address_id`.** Quem ainda não
 *   tem endereço cadastrado digita o endereço como texto livre; a empresa
 *   decide se cadastra um `Address` de verdade ao confirmar.
 * - **`situacao` em `enum`.** Catálogo fechado de quatro estados
 *   (`pendente`, `confirmada`, `recusada`, `cancelada`), que não cresce a cada
 *   plano, mesmo critério já usado em `client_requests.situacao`.
 * - **`periodo` em `enum`.** A grade de horários (Task 16.2) trabalha em dois
 *   turnos fixos, `manha` e `tarde`, sem horário exato: quem define o horário
 *   de fato é a empresa ao confirmar.
 * - **`work_order_id` nullable, preenchido só ao confirmar.** Pedido nunca
 *   agenda direto no calendário (regra do Plano 16): a OS nasce depois, na
 *   confirmação, então a coluna começa vazia.
 * - **`motivo_recusa` e `respondida_por`/`respondida_em` nullable.** Só
 *   existem depois que a empresa responde o pedido (Task 16.4); `respondida_por`
 *   segue o mesmo `nullOnDelete()` já usado em `client_requests.atendida_por`,
 *   para que a saída do usuário não apague o histórico de quem respondeu.
 * - **`origem` em `enum`.** Distingue pedido aberto pelo cliente já logado no
 *   portal (`portal`) do pedido aberto por quem ainda não tem conta
 *   (`pagina_publica`), duas telas diferentes descritas no Plano 16.
 * - **Índice em `[company_id, situacao]`**, mesmo critério de
 *   `client_requests`: é o filtro mais comum da fila de pedidos a confirmar.
 * - **Índice em `[company_id, data_preferida]`**: a grade de horários
 *   (Task 16.2) consulta os pedidos de um dia específico para calcular a
 *   capacidade restante, e não pode varrer a tabela inteira a cada consulta.
 * - **`company_id` sem valor padrão**, mesmo critério de `client_requests` e
 *   `work_order_signatures`: tabela nasce depois da trait `BelongsToCompany`
 *   já aplicada a todo model de domínio.
 *
 * Tabela nova, sem dado existente: um único deploy, sem a divisão em etapas
 * exigida para tabela que já tem dado em produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_requests', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('address_id')->nullable()->constrained()->nullOnDelete();

            $table->string('nome');
            $table->string('email');
            $table->string('telefone');
            $table->text('endereco_texto')->nullable();

            $table->foreignId('service_type_id')->nullable()->constrained()->nullOnDelete();

            // Dia sem hora relevante: nunca sofre conversão de fuso.
            $table->date('data_preferida');
            $table->enum('periodo', ['manha', 'tarde']);

            $table->text('observacao')->nullable();

            $table->enum('situacao', ['pendente', 'confirmada', 'recusada', 'cancelada'])
                ->default('pendente');

            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->text('motivo_recusa')->nullable();

            $table->foreignId('respondida_por')->nullable()->constrained('users')->nullOnDelete();
            // Instante da resposta da empresa: gravado em UTC e convertido
            // para o fuso do negócio só na exibição, via BusinessDate.
            $table->timestamp('respondida_em')->nullable();

            $table->enum('origem', ['portal', 'pagina_publica']);

            $table->timestamps();

            $table->index(
                [DominioMultiempresa::COLUNA_TENANT, 'situacao'],
                'appointment_requests_empresa_situacao_index'
            );

            $table->index(
                [DominioMultiempresa::COLUNA_TENANT, 'data_preferida'],
                'appointment_requests_empresa_data_preferida_index'
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
        Schema::dropIfExists('appointment_requests');
    }
};
