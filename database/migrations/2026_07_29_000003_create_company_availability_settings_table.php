<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuração de agendamento online por empresa (Plano 16, Task 16.2): dias
 * de atendimento, teto de visitas por período por técnico, antecedência
 * mínima e janela máxima que `App\Services\AvailabilityService` usa para
 * montar a grade de horários da página pública.
 *
 * Decisões da modelagem:
 *
 * - **Uma linha por empresa, `company_id` com `unique()`.** Não é uma tabela
 *   de histórico nem de múltiplos perfis: é a configuração corrente da
 *   empresa, e por isso a chave é a própria empresa. `AvailabilityService`
 *   trata a ausência de linha como "usa o padrão do sistema", então a
 *   unique não precisa vir acompanhada de `NOT NULL` com valor padrão em
 *   cada coluna: a empresa que nunca configurou nada simplesmente não tem
 *   linha aqui.
 * - **`dias_da_semana` em `json`.** Lista de dias no padrão ISO-8601 que o
 *   Carbon usa em `dayOfWeekIso` (1 = segunda, ..., 7 = domingo), para não
 *   introduzir uma segunda convenção de numeração de dia da semana no
 *   projeto. Padrão de segunda a sexta é `[1, 2, 3, 4, 5]`.
 * - **`visitas_por_periodo`.** Teto de OS por técnico por período (manhã ou
 *   tarde), não teto de OS da empresa inteira: a grade soma capacidade por
 *   técnico ativo, e um dia com mais técnicos livres aceita mais pedidos.
 * - **`antecedencia_minima_dias` e `janela_maxima_dias` com `default`.** Ao
 *   contrário de `dias_da_semana` e `visitas_por_periodo` (que só existem
 *   quando a linha existe), estas duas colunas levam valor padrão direto no
 *   banco, mesmo critério de "não depender só da aplicação lembrar de
 *   preencher" já usado em `client_requests` e tabelas semelhantes.
 * - **`aceita_agendamento_online` com `default(false)`.** A página pública de
 *   agendamento (Task 16.3) só fica visível depois que a empresa liga esta
 *   chave de propósito: nascer desligada evita que uma empresa recém-criada,
 *   sem grade configurada, receba pedido de cliente antes de estar pronta
 *   para atender.
 * - **`company_id` sem valor padrão**, mesmo critério de `appointment_requests`
 *   e `satisfaction_surveys`: tabela nasce depois da trait `BelongsToCompany`
 *   já aplicada a todo model de domínio.
 *
 * Tabela nova, sem dado existente: um único deploy, sem a divisão em etapas
 * exigida para tabela que já tem dado em produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_availability_settings', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT)->unique();

            $table->json('dias_da_semana');
            $table->unsignedTinyInteger('visitas_por_periodo');
            $table->unsignedTinyInteger('antecedencia_minima_dias')->default(2);
            $table->unsignedSmallInteger('janela_maxima_dias')->default(60);
            $table->boolean('aceita_agendamento_online')->default(false);

            $table->timestamps();

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_availability_settings');
    }
};
