<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campos que a plataforma precisa para administrar tenants (Plano 5, Task 5.1).
 *
 * Duas frentes, na mesma migration porque uma não faz sentido sem a outra: o que
 * o super admin sabe sobre cada empresa (`companies`) e quem é super admin
 * (`users`).
 *
 * ## Decisão sobre o super admin e `users.company_id`
 *
 * A Task 5.1 apresentava duas saídas para "o super admin não pertence a
 * nenhuma empresa": afrouxar `users.company_id` para nullable de novo, ou
 * manter a coluna NOT NULL e apontar o super admin para a empresa 1, marcado
 * por `is_platform_admin`. **Fica a segunda.** Voltar `company_id` a nullable
 * desfaria a restrição criada no Plano 4
 * (`2026_07_26_160000_make_company_id_required_on_domain_tables`), que existe
 * justamente para impedir usuário órfão de empresa, e abriria de novo o caso
 * "usuário sem tenant" que `TenantAtual` não sabe resolver. Trocar uma garantia
 * de banco por uma conveniência de um punhado de linhas é péssimo negócio num
 * sistema em que vazamento entre tenants é a falha mais grave possível.
 *
 * O custo da escolha é conhecido e aceito: o super admin fica formalmente
 * lotado na empresa 1, e é `is_platform_admin` (nunca `company_id`) que decide
 * o acesso à área de plataforma. A Task 5.5 é que dá a ele a capacidade de
 * assumir outro tenant, por sessão.
 *
 * ## Por que `is_platform_admin` fica fora do `$fillable` de `User`
 *
 * Mesmo padrão já documentado para `company_id` no model `User`: é o único flag
 * que concede acesso a `/plataforma`, onde a consulta roda sem escopo de
 * empresa e enxerga o banco inteiro. Um `User::create($request->all())` em
 * qualquer canto do sistema, hoje ou daqui a um ano, não pode ser capaz de
 * fabricar um super admin. Quem promove alguém atribui a coluna direto na
 * instância. Não existe tela para isso nesta task: o primeiro super admin nasce
 * de um `update users set is_platform_admin = 1 where id = ?` feito à mão, e é
 * assim mesmo que a Task 5.1 pede.
 *
 * ## Sobre `situacao`
 *
 * String com valores conhecidos (`ativa`, `suspensa`, `em_avaliacao`,
 * `cancelada`), não enum de banco: mudar enum em MySQL é ALTER TABLE com
 * reescrita da tabela, e a lista de situações vai crescer com o Plano 7
 * (inadimplência). A validação dos valores vive no FormRequest.
 *
 * ## Backfill embutido
 *
 * `companies` tem uma linha em produção. Marcá-la com `is_internal = true` é o
 * que a torna imune a cobrança e a bloqueio por inadimplência (Plano 7): sem
 * isso, a régua de inadimplência derrubaria a operação do cliente atual, que é
 * a receita do negócio. Uma linha em uma tabela pequena, com valor determinado
 * e sem ambiguidade, é seguro no mesmo deploy da estrutura; a regra de três
 * etapas do CLAUDE.md protege contra backfill longo em tabela grande, que não é
 * este caso. A contagem afetada vai para a saída do `migrate` para conferência.
 */
return new class extends Migration
{
    /**
     * Id do tenant fundador, o mesmo criado por
     * `2026_07_26_155000_seed_founding_company_for_fresh_installs`.
     */
    private const TENANT_FUNDADOR = 1;

    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            // Plano nulo = tenant ainda sem plano definido (o caso da empresa 1
            // até o Plano 7 semear o plano interno). `nullOnDelete` para que
            // apagar um plano do catálogo nunca apague empresa nenhuma: o
            // tenant volta a ficar sem plano e o super admin resolve depois.
            $table->foreignId('plan_id')
                ->nullable()
                ->after('id')
                ->constrained('plans')
                ->nullOnDelete();

            $table->string('situacao')->default('ativa')->after('plan_id');
            $table->boolean('is_internal')->default(false)->after('situacao');

            // Data sem hora: fim do período de avaliação é um dia do
            // calendário, e converter fuso em campo `date` é o que produz o
            // erro de um dia.
            $table->date('trial_ends_at')->nullable()->after('is_internal');

            $table->timestamp('suspensa_em')->nullable()->after('trial_ends_at');
            $table->text('motivo_suspensao')->nullable()->after('suspensa_em');
            $table->timestamp('ultimo_acesso_em')->nullable()->after('motivo_suspensao');
        });

        Schema::table('users', function (Blueprint $table): void {
            // Indexado porque a área de plataforma lista e conta super admins, e
            // porque a coluna é quase toda `false`: o índice serve para achar o
            // punhado de linhas verdadeiras sem varrer a tabela.
            $table->boolean('is_platform_admin')->default(false)->index()->after('is_active');
        });

        $afetadas = DB::table('companies')
            ->where('id', self::TENANT_FUNDADOR)
            ->update([
                'is_internal' => true,
                'situacao' => 'ativa',
            ]);

        fwrite(STDOUT, sprintf(
            "\n  Campos de plataforma: empresa #%d marcada como interna (is_internal = true, situacao = 'ativa'). "
            ."%d linha(s) afetada(s).\n",
            self::TENANT_FUNDADOR,
            $afetadas
        ));
    }

    /**
     * Reverte as duas alterações.
     *
     * A perda é só das colunas que esta migration criou (plano do tenant,
     * situação, marcas de suspensão e o flag de super admin). Nenhum dado
     * anterior a ela é tocado, e a restrição NOT NULL de `users.company_id`
     * criada no Plano 4 continua onde estava, porque esta migration nunca
     * mexeu nela.
     *
     * A chave estrangeira de `plan_id` cai antes da coluna: no MySQL, remover a
     * coluna com a FK ainda apontando para `plans` falha.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['is_platform_admin']);
            $table->dropColumn('is_platform_admin');
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->dropForeign(['plan_id']);
            $table->dropColumn([
                'plan_id',
                'situacao',
                'is_internal',
                'trial_ends_at',
                'suspensa_em',
                'motivo_suspensao',
                'ultimo_acesso_em',
            ]);
        });
    }
};
