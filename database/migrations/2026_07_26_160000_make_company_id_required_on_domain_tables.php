<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 3 da migração multiempresa (Plano 4, Deploy 3): torna `company_id`
 * obrigatório, indexado e com integridade referencial em todas as tabelas de
 * domínio listadas em `DominioMultiempresa::TABELAS_DE_DOMINIO`.
 *
 * Vem depois da Task 4.2 (coluna nullable, Deploy 1) e da Task 4.3 (backfill
 * conferido, Deploy 2). Rodar fora dessa ordem derruba a migration no meio.
 *
 * Decisões desta etapa:
 *
 * - **Guarda antes de tocar no schema.** O `up()` só começa a alterar tabela
 *   depois de conferir, em todas as tabelas de domínio, que não sobrou
 *   `company_id` nulo nem `company_id` apontando para empresa inexistente. Se
 *   encontrar qualquer um dos dois, aborta listando tabela, contagem e ids
 *   órfãos, sem ter alterado nada. Sem essa guarda o MySQL derrubaria a
 *   migration no meio, com um erro de integridade genérico, deixando parte das
 *   tabelas com restrição e parte sem.
 * - **`restrictOnDelete`, nunca `cascadeOnDelete`.** Apagar um tenant por
 *   engano não pode levar junto a operação inteira de um cliente. Exclusão de
 *   tenant é fluxo de negócio próprio, com regra no Plano 5.
 * - **`DEFAULT 1` temporário, e só temporário.** Quem preenche `company_id`
 *   sozinho é a trait `BelongsToCompany`, que é a Task 4.6 e só entra no
 *   Deploy 5. Entre este deploy e aquele, o código em produção continua
 *   inserindo sem informar a coluna: com `NOT NULL` e sem padrão, toda criação
 *   de cliente, ordem de serviço, certificado e lançamento financeiro passaria
 *   a falhar no banco e derrubaria a operação do cliente atual. O padrão 1
 *   cobre essa janela e grava o tenant certo, porque 1 é o único que existe
 *   hoje. Depois que a trait estiver ativa, esse mesmo padrão vira risco: um
 *   insert que esquecer `company_id` gravaria a empresa 1 em silêncio, que é
 *   exatamente o vazamento entre empresas que o Plano 4 existe para evitar.
 *   Por isso a migration
 *   `database/migrations/deploy5/2026_07_26_160001_drop_company_id_default_on_domain_tables`
 *   remove o padrão, mantendo o `NOT NULL`, e **sobe junto com a trait, nunca
 *   antes**. Ela mora fora de `database/migrations/` de propósito, para não ser
 *   varrida pelo `migrate` deste deploy, e é aplicada no Deploy 5 com
 *   `php artisan migrate --path=database/migrations/deploy5`.
 * - **Idempotente.** Tabela que já está com a coluna NOT NULL, com o padrão
 *   temporário e com a chave estrangeira é pulada, e cada peça
 *   (obrigatoriedade, padrão, índice, chave) é aplicada só se ainda faltar.
 * - **Mede o tempo.** Em MySQL, `MODIFY COLUMN` reescreve a tabela. O relatório
 *   impresso traz o tempo por tabela para dimensionar a janela do deploy real.
 *
 * As uniques globais que precisam virar compostas com `company_id` ficam para a
 * Task 4.5, em deploy separado.
 */
return new class extends Migration
{
    /**
     * Tabela de tenants referenciada pela chave estrangeira.
     */
    private const TABELA_DE_TENANTS = 'companies';

    /**
     * Valor padrão temporário da coluna de tenant, válido só enquanto a trait
     * `BelongsToCompany` não estiver ativa (Deploy 5). É o único tenant que
     * existe hoje. Removido pela migration
     * `2026_07_26_160001_drop_company_id_default_on_domain_tables`.
     */
    private const TENANT_PADRAO_TEMPORARIO = 1;

    /**
     * Quantos ids órfãos distintos aparecem na mensagem de erro por tabela.
     * O suficiente para o operador entender o problema sem despejar milhares
     * de valores no meio de um deploy.
     */
    private const LIMITE_DE_IDS_NO_RELATORIO = 10;

    /**
     * Executa a migração.
     */
    public function up(): void
    {
        $this->abortarSeODadoNaoSuportaARestricao();

        $inicioGeral = microtime(true);
        $aplicadas = [];
        $puladas = [];

        foreach (DominioMultiempresa::TABELAS_DE_DOMINIO as $tabela) {
            if (! $this->tabelaElegivel($tabela)) {
                $puladas[] = $tabela;

                continue;
            }

            $precisaDoChange = $this->colunaAceitaNulo($tabela) || ! $this->temPadraoTemporario($tabela);

            if (! $precisaDoChange && $this->chaveEstrangeiraDeTenant($tabela) !== null && $this->indiceDeTenant($tabela) !== null) {
                $puladas[] = $tabela;

                continue;
            }

            $inicio = microtime(true);
            $linhas = DB::table($tabela)->count();

            if ($precisaDoChange) {
                Schema::table($tabela, function (Blueprint $table): void {
                    $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT)
                        ->nullable(false)
                        ->default(self::TENANT_PADRAO_TEMPORARIO)
                        ->change();
                });
            }

            if ($this->indiceDeTenant($tabela) === null) {
                Schema::table($tabela, function (Blueprint $table): void {
                    $table->index(DominioMultiempresa::COLUNA_TENANT);
                });
            }

            if ($this->chaveEstrangeiraDeTenant($tabela) === null) {
                Schema::table($tabela, function (Blueprint $table): void {
                    $table->foreign(DominioMultiempresa::COLUNA_TENANT)
                        ->references('id')
                        ->on(self::TABELA_DE_TENANTS)
                        ->restrictOnDelete()
                        ->restrictOnUpdate();
                });
            }

            $aplicadas[$tabela] = ['linhas' => $linhas, 'segundos' => microtime(true) - $inicio];
        }

        $this->imprimirRelatorio($aplicadas, $puladas, microtime(true) - $inicioGeral);
    }

    /**
     * Reverte a migração: remove a chave estrangeira, remove o índice e devolve
     * a coluna para nullable, sem valor padrão (o `change()` reescreve a
     * definição inteira, e o que não é declarado aqui deixa de existir). O dado
     * preenchido permanece, então a etapa pode ser refeita sem perda.
     */
    public function down(): void
    {
        foreach (DominioMultiempresa::TABELAS_DE_DOMINIO as $tabela) {
            if (! $this->tabelaElegivel($tabela)) {
                continue;
            }

            $chave = $this->chaveEstrangeiraDeTenant($tabela);

            if ($chave !== null) {
                Schema::table($tabela, function (Blueprint $table) use ($chave): void {
                    $table->dropForeign($chave);
                });
            }

            $indice = $this->indiceDeTenant($tabela);

            if ($indice !== null) {
                Schema::table($tabela, function (Blueprint $table) use ($indice): void {
                    $table->dropIndex($indice);
                });
            }

            if (! $this->colunaAceitaNulo($tabela)) {
                Schema::table($tabela, function (Blueprint $table): void {
                    $table->unsignedBigInteger(DominioMultiempresa::COLUNA_TENANT)->nullable()->change();
                });
            }
        }
    }

    /**
     * Guarda de integridade: percorre todas as tabelas de domínio e só deixa a
     * migração seguir se nenhuma delas tiver `company_id` nulo ou apontando
     * para empresa que não existe em `companies`.
     *
     * Confere todas as tabelas antes de decidir, em vez de parar na primeira
     * com problema: o operador precisa ver o quadro completo de uma vez para
     * saber o que corrigir antes de repetir o deploy. Nada de schema é alterado
     * até esta conferência passar inteira.
     */
    private function abortarSeODadoNaoSuportaARestricao(): void
    {
        if (! Schema::hasTable(self::TABELA_DE_TENANTS)) {
            throw new RuntimeException(
                'Migração interrompida antes de qualquer alteração: a tabela "'.self::TABELA_DE_TENANTS
                .'" não existe no banco conectado, e sem ela não há como criar a chave estrangeira de tenant.'
            );
        }

        $problemas = [];

        foreach (DominioMultiempresa::TABELAS_DE_DOMINIO as $tabela) {
            if (! $this->tabelaElegivel($tabela)) {
                continue;
            }

            $nulos = DB::table($tabela)->whereNull(DominioMultiempresa::COLUNA_TENANT)->count();

            if ($nulos > 0) {
                $problemas[] = sprintf(
                    '%s: %d linha(s) com %s nulo.',
                    $tabela,
                    $nulos,
                    DominioMultiempresa::COLUNA_TENANT
                );
            }

            $orfaos = $this->orfaosDaTabela($tabela);

            if ($orfaos !== []) {
                $linhasOrfas = array_sum(array_column($orfaos, 'total'));
                $ids = array_column($orfaos, 'id');
                $mostrados = array_slice($ids, 0, self::LIMITE_DE_IDS_NO_RELATORIO);
                $restantes = count($ids) - count($mostrados);

                $problemas[] = sprintf(
                    '%s: %d linha(s) com %s apontando para empresa inexistente em %s (id%s órfão%s: %s%s).',
                    $tabela,
                    $linhasOrfas,
                    DominioMultiempresa::COLUNA_TENANT,
                    self::TABELA_DE_TENANTS,
                    count($ids) === 1 ? '' : 's',
                    count($ids) === 1 ? '' : 's',
                    implode(', ', $mostrados),
                    $restantes > 0 ? " e mais {$restantes}" : ''
                );
            }
        }

        if ($problemas === []) {
            return;
        }

        throw new RuntimeException(implode(PHP_EOL, array_merge(
            [
                'Migração interrompida ANTES de qualquer alteração de schema: o dado atual não suporta '
                .DominioMultiempresa::COLUNA_TENANT.' NOT NULL com chave estrangeira.',
                '',
                'Problemas encontrados:',
            ],
            array_map(static fn (string $problema): string => '  - '.$problema, $problemas),
            [
                '',
                'Nenhuma tabela foi alterada. Como resolver:',
                '  - Linha com '.DominioMultiempresa::COLUNA_TENANT.' nulo: rode `php artisan multiempresa:backfill`',
                '    (Etapa 2, Task 4.3), confira o relatório e repita este deploy.',
                '  - Id órfão: a empresa referenciada não existe em `'.self::TABELA_DE_TENANTS.'`. Confirme se a empresa',
                '    foi apagada por engano e restaure, ou corrija o '.DominioMultiempresa::COLUNA_TENANT.' das linhas',
                '    citadas. Não crie empresa às cegas só para a chave estrangeira passar: isso amarra dado',
                '    de um cliente a um tenant errado.',
            ]
        )));
    }

    /**
     * Valores de `company_id` presentes na tabela que não existem em
     * `companies`, com quantas linhas cada um afeta.
     *
     * @return array<int, array{id: int, total: int}>
     */
    private function orfaosDaTabela(string $tabela): array
    {
        $coluna = DominioMultiempresa::COLUNA_TENANT;

        return DB::table($tabela)
            ->whereNotNull($coluna)
            ->whereNotExists(function ($consulta) use ($tabela, $coluna): void {
                $consulta->selectRaw('1')
                    ->from(self::TABELA_DE_TENANTS)
                    ->whereColumn(self::TABELA_DE_TENANTS.'.id', $tabela.'.'.$coluna);
            })
            ->groupBy($coluna)
            ->orderBy($coluna)
            ->selectRaw("{$coluna} as id, COUNT(*) as total")
            ->get()
            ->map(static fn (object $linha): array => ['id' => (int) $linha->id, 'total' => (int) $linha->total])
            ->all();
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
     * A coluna de tenant ainda aceita nulo?
     */
    private function colunaAceitaNulo(string $tabela): bool
    {
        foreach (Schema::getColumns($tabela) as $coluna) {
            if ($coluna['name'] === DominioMultiempresa::COLUNA_TENANT) {
                return (bool) ($coluna['nullable'] ?? false);
            }
        }

        return false;
    }

    /**
     * A coluna de tenant já está com o valor padrão temporário desta etapa?
     */
    private function temPadraoTemporario(string $tabela): bool
    {
        foreach (Schema::getColumns($tabela) as $coluna) {
            if ($coluna['name'] !== DominioMultiempresa::COLUNA_TENANT) {
                continue;
            }

            $padrao = $coluna['default'] ?? null;

            return $padrao !== null && (int) trim((string) $padrao, "'\"") === self::TENANT_PADRAO_TEMPORARIO;
        }

        return false;
    }

    /**
     * Nome da chave estrangeira que sai da coluna de tenant, ou null se ainda
     * não existir.
     */
    private function chaveEstrangeiraDeTenant(string $tabela): ?string
    {
        foreach (Schema::getForeignKeys($tabela) as $chave) {
            if (($chave['columns'] ?? []) === [DominioMultiempresa::COLUNA_TENANT]) {
                return $chave['name'];
            }
        }

        return null;
    }

    /**
     * Nome do índice simples sobre a coluna de tenant, ou null se ainda não
     * existir. Primária e unique ficam de fora de propósito: a primária nunca é
     * esta coluna, e um unique só em `company_id` seria outro problema, que
     * esta migração não deve criar nem remover.
     */
    private function indiceDeTenant(string $tabela): ?string
    {
        foreach (Schema::getIndexes($tabela) as $indice) {
            if (($indice['primary'] ?? false) || ($indice['unique'] ?? false)) {
                continue;
            }

            if (($indice['columns'] ?? []) === [DominioMultiempresa::COLUNA_TENANT]) {
                return $indice['name'];
            }
        }

        return null;
    }

    /**
     * Relatório do que foi feito, com o tempo por tabela. Em MySQL o
     * `MODIFY COLUMN` reescreve a tabela, então esse tempo é o que dimensiona a
     * janela do deploy em produção.
     *
     * @param  array<string, array{linhas: int, segundos: float}>  $aplicadas
     * @param  array<int, string>  $puladas
     */
    private function imprimirRelatorio(array $aplicadas, array $puladas, float $segundosTotais): void
    {
        $saida = [
            '',
            sprintf(
                '  company_id NOT NULL (com DEFAULT %d temporário) + índice + chave estrangeira: %d tabela(s) alterada(s), %d pulada(s) (já restritas, sem a coluna ou inexistentes).',
                self::TENANT_PADRAO_TEMPORARIO,
                count($aplicadas),
                count($puladas)
            ),
            '  O DEFAULT cobre a janela até a trait BelongsToCompany entrar (Deploy 5) e é removido pela migration 2026_07_26_160001.',
        ];

        if ($aplicadas !== []) {
            $maisLentas = $aplicadas;
            uasort($maisLentas, static fn (array $primeira, array $segunda): int => $segunda['segundos'] <=> $primeira['segundos']);

            $saida[] = '  Tabelas mais demoradas (tempo por tabela, para dimensionar a janela em produção):';

            foreach (array_slice($maisLentas, 0, 5, true) as $tabela => $medida) {
                $saida[] = sprintf('    %-30s %8d linha(s)  %7.3f s', $tabela, $medida['linhas'], $medida['segundos']);
            }
        }

        $saida[] = sprintf('  Tempo total: %.3f s.', $segundosTotais);
        $saida[] = '';

        fwrite(STDOUT, implode(PHP_EOL, $saida).PHP_EOL);
    }
};
