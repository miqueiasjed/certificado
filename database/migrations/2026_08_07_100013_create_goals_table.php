<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta de uma pessoa em uma competência (Plano 23, Task 23.1): valor vendido,
 * valor recebido, quantidade de OS ou taxa de conversão. Tabela nova, sem
 * dado existente: um único deploy.
 *
 * O acompanhamento do atingimento (quanto já foi realizado frente ao `alvo`) é
 * regra de negócio da Task 23.3, não desta migration.
 *
 * Decisões da modelagem:
 *
 * - **`user_id` obrigatório, com `restrictOnDelete`.** Diferente de
 *   `commissions`/`commission_rules` (Task 23.1, mesma leva), aqui não existe
 *   "meta geral da empresa" nem "meta de técnico": toda meta é de uma pessoa
 *   (`User`), e por isso a coluna não é nullable. Sem a possibilidade de
 *   `NULL`, a unique composta abaixo é uma unique comum do Laravel, sem o
 *   problema de comparação de `NULL` que forçou a unique funcional em
 *   `commissions` (ver o cabeçalho de `create_commission_tables`).
 *   `restrictOnDelete` porque meta é indicador de desempenho já registrado; a
 *   pessoa some do sistema por desativação, nunca por exclusão de `User` com
 *   meta vinculada.
 * - **`tipo` fechado em `enum`.** `valor_vendido`, `valor_recebido`,
 *   `quantidade_os` e `conversao` são os quatro indicadores do painel
 *   comercial (Task 23.3); o banco recusar um valor inventado vale mais que a
 *   facilidade de acrescentar um a mais depois, mesmo critério de
 *   `commission_rules.tipo`/`base`/`forma` nesta mesma leva de tabelas.
 * - **`alvo` é `decimal(12,2)`**, maior que o `decimal(10,2)` comum no
 *   restante do schema: meta de faturamento de uma empresa inteira num mês
 *   pode passar de um milhão, o teto de `decimal(10,2)` (99 999 999,99).
 * - **`observacao` é `text` nullable.** Contexto livre da meta (por que esse
 *   número, o que mudou desde a competência anterior), sem uso pelo cálculo
 *   de atingimento.
 * - **Unique composta `[company_id, user_id, tipo, competencia]`.** A mesma
 *   pessoa não tem duas metas do mesmo tipo na mesma competência.
 * - **`company_id` sem valor padrão**, mesmo critério das demais tabelas de
 *   domínio recentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT);

            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->enum('tipo', ['valor_vendido', 'valor_recebido', 'quantidade_os', 'conversao']);

            // "YYYY-MM".
            $table->string('competencia', 7);

            $table->decimal('alvo', 12, 2);
            $table->text('observacao')->nullable();

            $table->timestamps();

            $table->unique([DominioMultiempresa::COLUNA_TENANT, 'user_id', 'tipo', 'competencia']);

            $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                ->references('id')
                ->on('companies')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
