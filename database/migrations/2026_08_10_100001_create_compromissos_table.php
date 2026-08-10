<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compromisso avulso: visita ou tarefa que entra na agenda e pode virar
 * parada de roteiro sem depender de uma OS já existente (Plano 30).
 *
 * O sistema hoje só agenda e roteiriza `work_orders`. Casos reais não cabem
 * nisso: visita de orçamento antes de existir contrato, checagem de garantia
 * fora do ciclo de visitas do contrato, ou qualquer outro compromisso que o
 * técnico precisa ter no dia sem que uma OS nasça para justificar.
 * `Compromisso` é essa entidade nova, desacoplada de `WorkOrder` desde a raiz.
 *
 * Decisões da modelagem:
 *
 * - **`client_id`, `address_id` e `technician_id` nullable, os três.** Nem
 *   todo compromisso tem cliente cadastrado (orçamento para quem ainda não é
 *   cliente), nem endereço cadastrado, nem técnico já definido no momento em
 *   que é lançado. Mesmo critério de `appointment_requests` (Plano 16).
 * - **`endereco_texto` nullable, alternativa a `address_id`.** Quem lança um
 *   compromisso para um endereço ainda não cadastrado digita como texto
 *   livre, mesma solução de `appointment_requests.endereco_texto`.
 * - **`tipo` em `enum`.** Catálogo fechado (`orcamento`, `garantia`,
 *   `retorno`, `outro`), mesmo critério das colunas enum já usadas no projeto
 *   (`appointment_requests.situacao`, `ppe_deliveries.motivo`).
 * - **`titulo` obrigatório.** É o texto que identifica o compromisso na
 *   agenda quando não há cliente vinculado, então não pode ficar em branco.
 * - **`situacao` em `enum`, só três estados.** Compromisso nasce agendado, o
 *   dia decide o resto: `agendado` (padrão), `concluido`, `cancelado`. Não
 *   tem o fluxo de confirmação de `appointment_requests`, que é fila de
 *   pedido do cliente: o compromisso já nasce no calendário.
 * - **`work_order_id` nullable, preenchido só se o compromisso virar OS de
 *   verdade.** Um orçamento aprovado, por exemplo, pode originar uma OS; o
 *   compromisso continua existindo como estava, com o vínculo preenchido.
 *   Mesmo padrão de `appointment_requests.work_order_id` (Plano 16), mas
 *   aqui a promoção é opcional o tempo todo, nunca obrigatória para o
 *   compromisso aparecer na agenda ou entrar numa rota.
 * - **`criado_por` nullable com `nullOnDelete`.** Quem lançou o compromisso;
 *   sobrevive à saída do usuário, mesmo critério de
 *   `appointment_requests.respondida_por`.
 * - **Índice em `[company_id, data]`.** É o filtro da Agenda por período,
 *   mesmo critério de `appointment_requests.data_preferida`.
 * - **Índice em `[company_id, technician_id, data]`.** É a consulta do
 *   roteiro do dia por técnico (Plano 22/31), aplicada aqui do mesmo jeito
 *   que `RouteService` já consulta `work_orders`.
 * - **`company_id` sem valor padrão**, mesmo critério das demais tabelas de
 *   domínio recentes: a trait `BelongsToCompany` preenche a coluna na
 *   criação.
 *
 * Tabela nova, sem dado existente: um deploy só, sem a divisão em etapas
 * exigida para tabela que já tem dado em produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compromissos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->enum('tipo', ['orcamento', 'garantia', 'retorno', 'outro']);
            $table->string('titulo');
            $table->text('observacoes')->nullable();

            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('address_id')->nullable()->constrained()->nullOnDelete();
            $table->text('endereco_texto')->nullable();
            $table->foreignId('technician_id')->nullable()->constrained()->nullOnDelete();

            $table->date('data');
            $table->time('hora_inicio')->nullable();
            $table->time('hora_fim')->nullable();

            $table->enum('situacao', ['agendado', 'concluido', 'cancelado'])->default('agendado');

            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index([DominioMultiempresa::COLUNA_TENANT, 'data'], 'compromissos_empresa_data_index');
            $table->index(
                [DominioMultiempresa::COLUNA_TENANT, 'technician_id', 'data'],
                'compromissos_empresa_tecnico_data_index'
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
        Schema::dropIfExists('compromissos');
    }
};
