<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uso apurado de cada tenant por período (Plano 5, Task 5.2).
 *
 * Alimenta o painel de uso do super admin e, a partir do Plano 6, a
 * comparação contra os limites do plano contratado (`plans.limite_*`). É
 * apuração derivada, não dado do cliente: por isso `company_id` aqui usa
 * `cascadeOnDelete()`, diferente da regra geral de tabela de domínio, onde
 * apagar a empresa nunca cadeia para as tabelas dela.
 *
 * Deliberadamente **sem** `company_id` como tabela de domínio (sem a coluna
 * escopada pela trait `BelongsToCompany`): o super admin precisa ler a
 * apuração de todos os tenants de uma vez na mesma consulta, e o escopo
 * global por empresa bloquearia exatamente essa leitura. A classificação está
 * registrada em `App\Support\DominioMultiempresa::TABELAS_FORA_DO_ESCOPO`
 * (tabela) e `MODELS_FORA_DO_ESCOPO` (model `App\Models\TenantUsage`), com o
 * motivo escrito nas duas. O tenant que quiser ver o próprio uso (Plano 6)
 * filtra explicitamente por `company_id`.
 *
 * Decisões da modelagem:
 *
 * - **`referencia` é string `YYYY-MM`**, não uma coluna de data: o período
 *   apurado é um mês inteiro, não um dia. Quem grava calcula a referência no
 *   fuso do negócio (`App\Support\BusinessDate`); aqui é só a coluna.
 * - **Unique composto `[company_id, referencia]`**: uma apuração por tenant
 *   por mês. Reapurar sobrescreve a linha existente (update), nunca duplica.
 * - **Índice em `referencia`** (além do que a unique composta já cobre) para
 *   o painel geral do super admin, que agrupa a apuração de todos os tenants
 *   por período.
 * - **`apurado_em` é `timestamp`**, o instante em que a apuração rodou,
 *   gravado em UTC como todo campo de instante do projeto (igual
 *   `created_at`/`updated_at`), nunca a data corrente do fuso do negócio
 *   gravada direto: isso registraria a hora de Brasília como se fosse UTC.
 * - **`armazenamento_mb`** guarda o valor já apurado (soma de arquivos de
 *   foto), o campo mais caro de calcular desta tabela: o painel lê o valor
 *   pronto, nunca recalcula sob demanda.
 *
 * Tabela nova, sem dado existente: cria em um passo só, sem a dança de três
 * etapas que o CLAUDE.md exige para tabela já povoada em produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_usages', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->cascadeOnDelete();

            // Formato YYYY-MM, calculado no fuso do negócio por quem grava.
            $table->string('referencia');

            $table->unsignedInteger('usuarios');
            $table->unsignedInteger('clientes');
            $table->unsignedInteger('ordens_servico');
            $table->unsignedInteger('certificados');
            $table->unsignedInteger('armazenamento_mb');

            // Instante da apuração, gravado em UTC. Ver o cabeçalho.
            $table->timestamp('apurado_em');

            $table->timestamps();

            $table->unique(['company_id', 'referencia']);
            $table->index('referencia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_usages');
    }
};
