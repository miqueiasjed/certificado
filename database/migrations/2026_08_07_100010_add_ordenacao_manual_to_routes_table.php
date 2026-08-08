<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ordem manual do roteiro (Plano 22, Task 22.5).
 *
 * Fecha um buraco de arquitetura entre a Task 22.3 e esta: `RouteService::montar()`
 * nascia sempre rodando o otimizador e sobrescrevendo a ordem de toda parada,
 * toda vez que era chamado - o que contradiz a regra de negócio inegociável
 * desta task ("a ordem manual prevalece... até nova otimização explícita, e o
 * sistema não pode desfazer a decisão do usuário sozinho").
 *
 * Três colunas novas, só estrutura (sem backfill, sem restrição): `routes`
 * nasceu na Task 22.1 sem nenhuma linha em produção ainda (Plano 22 inteiro
 * em desenvolvimento), então não há o cuidado de dado existente que outras
 * migrations deste projeto documentam.
 *
 * - `ordenacao_manual` (`boolean`, default `false`): `true` quando a última
 *   ordem gravada veio de `PUT /roteiros/{id}/ordem` (reordenação manual), e
 *   não do otimizador. `RouteService::montar()` passa a checar esta coluna
 *   antes de sobrescrever a ordem (ver o cabeçalho da classe).
 * - `reordenada_por` (FK `users`, nullable, `nullOnDelete`): quem fez a
 *   última reordenação manual. Nullable porque nem toda `Route` passou por
 *   reordenação manual (a maioria continua só com a ordem do otimizador).
 *   `nullOnDelete`, e não `restrictOnDelete` como as demais FKs de domínio
 *   recentes deste plano: é só um dado de auditoria de "quem fez", perder o
 *   usuário não pode travar a exclusão de um usuário desligado da empresa.
 * - `reordenada_em` (`datetime`, nullable): quando a última reordenação
 *   manual aconteceu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routes', function (Blueprint $table): void {
            $table->boolean('ordenacao_manual')->default(false)->after('situacao');
            $table->foreignId('reordenada_por')->nullable()->after('otimizada_em')->constrained('users')->nullOnDelete();
            $table->dateTime('reordenada_em')->nullable()->after('reordenada_por');
        });
    }

    public function down(): void
    {
        Schema::table('routes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reordenada_por');
            $table->dropColumn(['ordenacao_manual', 'reordenada_em']);
        });
    }
};
