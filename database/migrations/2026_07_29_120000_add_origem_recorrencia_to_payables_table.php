<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculo entre as ocorrências de um título a pagar recorrente (Plano 18,
 * Task 18.5).
 *
 * A Task 18.1 criou `payables` sem nenhuma coluna que ligasse uma competência
 * gerada de volta à série que a originou. Sem esse vínculo, a regra de negócio
 * desta task ("manter sempre as próximas 3 competências geradas") não tem como
 * saber quais títulos pertencem à mesma recorrência nem qual foi o último
 * gerado, e "cancelar a recorrência" não tem como impedir a geração seguinte.
 *
 * `payable_origem_id` aponta para o título raiz da série (o primeiro criado,
 * com `recorrencia` diferente de `nenhuma`) e fica `null` no próprio título
 * raiz. Cada ocorrência gerada por `PayableService` grava o id do raiz aqui,
 * nunca o id da ocorrência anterior: assim a série inteira é encontrada com
 * uma consulta só (`id = raiz OR payable_origem_id = raiz`), sem precisar
 * percorrer uma corrente de referências.
 *
 * `payables` é tabela nova desta mesma leva de tasks (Task 18.1, sem nenhuma
 * tela ainda gravando nela, portanto sem dado de cliente em produção), então a
 * coluna entra direto, nullable, sem o processo de três etapas (estrutura,
 * backfill, restrição) que o `CLAUDE.md` exige para tabela que já tem dado
 * real.
 *
 * `nullOnDelete`: hoje não existe exclusão de título no fluxo do sistema, só
 * cancelamento (`situacao = cancelado`), mas caso o raiz seja apagado por
 * algum caminho futuro, a ocorrência não pode ser arrastada junto; ela só
 * perde o vínculo com a série.
 *
 * Índice em `[payable_origem_id, emitido_em]`, e não unique: a idempotência da
 * geração é conferida em código, comparando ano e mês de `emitido_em` (mesmo
 * critério já usado por `ReceivableService::gerarDoContrato()` com
 * `[contract_id, competência]`), e não por restrição do banco.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payables', function (Blueprint $table): void {
            $table->foreignId('payable_origem_id')
                ->nullable()
                ->after('recorrencia_ate')
                ->constrained('payables')
                ->nullOnDelete();

            $table->index(['payable_origem_id', 'emitido_em']);
        });
    }

    public function down(): void
    {
        Schema::table('payables', function (Blueprint $table): void {
            // A chave estrangeira reaproveita o índice composto abaixo (o
            // MySQL usa o índice cujo prefixo bate com a coluna da FK em vez
            // de criar um segundo). Por isso a ordem importa: a constraint
            // precisa cair antes do índice, senão o banco recusa a exclusão
            // do índice com "needed in a foreign key constraint".
            $table->dropForeign(['payable_origem_id']);
            $table->dropIndex(['payable_origem_id', 'emitido_em']);
            $table->dropColumn('payable_origem_id');
        });
    }
};
