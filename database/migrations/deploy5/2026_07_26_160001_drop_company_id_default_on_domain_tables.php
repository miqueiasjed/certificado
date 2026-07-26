<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remoção do valor padrão temporário de `company_id`. Faz parte do **Deploy 5**
 * do Plano 4, e sobe junto com a trait `BelongsToCompany` aplicada aos models
 * (Tasks 4.6 a 4.9). Nunca antes.
 *
 * Por que existe
 * -------------
 * A migration `2026_07_26_160000_make_company_id_required_on_domain_tables`
 * (Deploy 3) tornou `company_id` obrigatório e deixou `DEFAULT 1` na coluna. Sem
 * esse padrão, o código que roda em produção entre o Deploy 3 e o Deploy 5, que
 * ainda não preenche `company_id` em lugar nenhum, quebraria toda criação de
 * registro no banco.
 *
 * Depois que a trait entra, o padrão inverte de proteção para risco: um insert
 * que esquecer `company_id` grava a empresa 1 em silêncio. Com um segundo tenant
 * real, isso é o vazamento entre empresas que o Plano 4 inteiro existe para
 * evitar. Esta migration mata o padrão e mantém o `NOT NULL`, o índice e a chave
 * estrangeira intactos, para que o esquecimento vire erro de banco visível em
 * vez de dado gravado no tenant errado.
 *
 * Ordem de deploy (a inversão quebra produção)
 * --------------------------------------------
 * Subir esta migration **antes** da trait devolve exatamente o problema que o
 * `DEFAULT` resolvia: criação de cliente, ordem de serviço, certificado e
 * lançamento financeiro passa a falhar no banco. Duas travas garantem a ordem:
 *
 * 1. **O arquivo mora fora de `database/migrations/`.** O `migrate` só varre a
 *    raiz daquela pasta (`glob('*_*.php')`, sem recursão), então esta migration
 *    fica pendente e invisível para todo `php artisan migrate` do Deploy 3 em
 *    diante, inclusive para o `RefreshDatabase` da suíte de testes. No Deploy 5,
 *    depois de as Tasks 4.6 a 4.9 estarem no ar, ela é aplicada com o caminho
 *    explícito:
 *
 *        php artisan migrate --path=database/migrations/deploy5
 *
 *    e revertida, se preciso, com:
 *
 *        php artisan migrate:rollback --path=database/migrations/deploy5 --step=1
 *
 *    Depois do Deploy 5, mover o arquivo para `database/migrations/` deixa o
 *    histórico normal de novo, e ele será pulado por já constar como aplicado.
 *
 * 2. **A guarda do `up()`.** Confere se a trait já está aplicada aos models de
 *    domínio e aborta, sem alterar nada, quando não está. É a rede para o caso
 *    de alguém mover o arquivo para a pasta padrão antes da hora.
 */
return new class extends Migration
{
    /**
     * Nome curto da trait de escopo por empresa. Comparado pelo nome curto de
     * propósito: se a Task 4.6 mudar o namespace dela, a guarda continua
     * valendo em vez de travar o deploy por um `use` que não bate.
     */
    private const TRAIT_DE_ESCOPO = 'BelongsToCompany';

    /**
     * Executa a migração.
     */
    public function up(): void
    {
        $this->abortarSeATraitAindaNaoEstaAtiva();

        $alteradas = [];
        $puladas = [];

        foreach (DominioMultiempresa::TABELAS_DE_DOMINIO as $tabela) {
            if (! $this->tabelaElegivel($tabela) || ! $this->temValorPadrao($tabela)) {
                $puladas[] = $tabela;

                continue;
            }

            $this->redefinirColuna($tabela, comPadrao: false);
            $alteradas[] = $tabela;
        }

        fwrite(STDOUT, sprintf(
            "\n  DEFAULT de company_id removido: %d tabela(s) alterada(s), %d pulada(s) (já sem padrão, sem a coluna ou inexistentes).\n"
            ."  NOT NULL, índice e chave estrangeira permanecem. A partir daqui, insert sem company_id falha no banco, como deve.\n",
            count($alteradas),
            count($puladas)
        ));
    }

    /**
     * Reverte a migração, devolvendo o valor padrão temporário. Só faz sentido
     * junto com o retorno da trait: sem ela e sem o padrão, a criação de
     * registro quebra.
     */
    public function down(): void
    {
        foreach (DominioMultiempresa::TABELAS_DE_DOMINIO as $tabela) {
            if (! $this->tabelaElegivel($tabela) || $this->temValorPadrao($tabela)) {
                continue;
            }

            $this->redefinirColuna($tabela, comPadrao: true);
        }
    }

    /**
     * Guarda de ordem de deploy: só deixa remover o padrão depois que todos os
     * models de domínio usam a trait de escopo, porque é ela que passa a
     * preencher `company_id` na criação.
     *
     * Aborta antes de alterar qualquer tabela, listando os models que ainda não
     * têm a trait, para o operador entender que o problema é a ordem do deploy
     * e não o schema.
     */
    private function abortarSeATraitAindaNaoEstaAtiva(): void
    {
        $semATrait = array_values(array_filter(
            DominioMultiempresa::MODELS_DE_DOMINIO,
            fn (string $model): bool => ! class_exists($model) || ! $this->usaTraitDeEscopo($model)
        ));

        if ($semATrait === []) {
            return;
        }

        throw new RuntimeException(implode(PHP_EOL, array_merge(
            [
                'Migração interrompida ANTES de qualquer alteração de schema: esta migration é do Deploy 5 do',
                'Plano 4 e só pode rodar junto com a trait '.self::TRAIT_DE_ESCOPO.' já aplicada aos models de domínio',
                '(Tasks 4.6 a 4.9).',
                '',
                'Remover o DEFAULT de '.DominioMultiempresa::COLUNA_TENANT.' sem a trait ativa faz toda criação de registro',
                'falhar no banco: nada no código preenche a coluna hoje, e ela é NOT NULL desde o Deploy 3.',
                '',
                sprintf('Models de domínio ainda sem a trait (%d):', count($semATrait)),
            ],
            array_map(static fn (string $model): string => '  - '.$model, $semATrait),
            [
                '',
                'Nenhuma tabela foi alterada. Conclua as Tasks 4.6 a 4.9 e suba tudo no mesmo release.',
            ]
        )));
    }

    /**
     * O model usa a trait de escopo por empresa, direta ou indiretamente?
     */
    private function usaTraitDeEscopo(string $model): bool
    {
        foreach (class_uses_recursive($model) as $trait) {
            if (class_basename($trait) === self::TRAIT_DE_ESCOPO) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reescreve a definição da coluna de tenant mantendo `NOT NULL`. O
     * `change()` do Laravel reescreve a definição inteira, então o padrão
     * existe apenas quando declarado aqui.
     */
    private function redefinirColuna(string $tabela, bool $comPadrao): void
    {
        Schema::table($tabela, function (Blueprint $table) use ($comPadrao): void {
            $coluna = $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT)->nullable(false);

            if ($comPadrao) {
                $coluna->default(1);
            }

            $coluna->change();
        });
    }

    /**
     * A tabela existe no banco conectado e já tem a coluna de tenant?
     */
    private function tabelaElegivel(string $tabela): bool
    {
        return Schema::hasTable($tabela)
            && Schema::hasColumn($tabela, DominioMultiempresa::COLUNA_TENANT);
    }

    /**
     * A coluna de tenant ainda tem valor padrão?
     */
    private function temValorPadrao(string $tabela): bool
    {
        foreach (Schema::getColumns($tabela) as $coluna) {
            if ($coluna['name'] === DominioMultiempresa::COLUNA_TENANT) {
                return ($coluna['default'] ?? null) !== null;
            }
        }

        return false;
    }
};
