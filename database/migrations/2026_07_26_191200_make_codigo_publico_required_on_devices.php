<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deploy 3 do Plano 11: `devices.codigo_publico` passa a ser obrigatório e
 * único dentro da empresa.
 *
 * ## Ordem de aplicação em produção (não é opcional)
 *
 * 1. Deploy 1 (Task 11.1): a coluna nasceu nullable, sem unique.
 * 2. Deploy 2: `php artisan dispositivos:backfill-codigo --dry-run`, conferir o
 *    relatório por empresa, e então `php artisan dispositivos:backfill-codigo`.
 * 3. Deploy 3: esta migration.
 *
 * O backfill **não** é chamado daqui de propósito. Migration que gera dado
 * esconde do operador o momento de conferir, e é justamente a conferência que
 * separa os três deploys. Rodar esta migration antes do backfill é erro de
 * operação, e o `up()` recusa com a instrução na tela em vez de deixar o MySQL
 * derrubar o `ALTER TABLE` com erro genérico no meio do deploy.
 *
 * ## Decisões desta etapa
 *
 * - **Guarda antes de tocar no schema.** Confere as duas condições (nenhum
 *   código vazio, nenhum código repetido dentro da mesma empresa) antes de
 *   alterar qualquer coisa. Sem a guarda, a coluna poderia virar `NOT NULL` e a
 *   criação da unique falhar em seguida, deixando a tabela num estado
 *   intermediário no meio de um deploy.
 * - **Unique composta com `company_id`, nunca global.** Duas empresas podem
 *   sortear o mesmo código sem se enxergarem, e o código só identifica um
 *   dispositivo dentro da empresa dona dele. Unique global recusaria um código
 *   legítimo de outra empresa e vazaria a informação de que ele existe.
 * - **`company_id` primeiro nas colunas do índice.** Mesma ordem das demais
 *   uniques compostas do projeto (`compose_uniques_with_company_id`): o índice
 *   também serve às consultas que filtram só por empresa.
 * - **O índice simples de `codigo_publico` é preservado.** Ele é o que sustenta
 *   a leitura pública do QR code, que chega com o código e sem a empresa. A
 *   unique composta começa por `company_id` e não serve a essa busca.
 * - **Idempotente.** Coluna já obrigatória ou unique já existente são puladas.
 *
 * ## Sobre o retorno (`down()`)
 *
 * Reversível de verdade: remove a unique composta e devolve a coluna para
 * nullable, sem apagar nenhum código já gravado. O índice simples de
 * `codigo_publico` e o de `company_id` continuam de pé, então a chave
 * estrangeira de tenant nunca fica sem índice que a sustente.
 */
return new class extends Migration
{
    private const TABELA = 'devices';

    private const COLUNA = 'codigo_publico';

    /**
     * Mesmo tamanho declarado no Deploy 1. Repetido aqui porque o `change()` do
     * Laravel reescreve a definição inteira da coluna: omitir o tamanho a
     * encolheria para o padrão de 255... ou para o que o driver decidir.
     */
    private const TAMANHO_DA_COLUNA = 12;

    /**
     * Quantos códigos repetidos aparecem na mensagem de erro. O bastante para o
     * operador entender o problema sem despejar milhares de linhas no meio de
     * um deploy.
     */
    private const LIMITE_DE_VALORES_NO_RELATORIO = 10;

    /**
     * Executa a migração: coluna obrigatória e unique composta com a empresa.
     */
    public function up(): void
    {
        if (! $this->tabelaElegivel()) {
            return;
        }

        $this->abortarSeODadoNaoSuportar();

        if ($this->colunaAceitaNulo()) {
            Schema::table(self::TABELA, function (Blueprint $table): void {
                $table->string(self::COLUNA, self::TAMANHO_DA_COLUNA)->nullable(false)->change();
            });
        }

        $colunasDaUnique = $this->colunasDaUnique();

        if ($this->indiceUniqueComColunas($colunasDaUnique) === null) {
            Schema::table(self::TABELA, function (Blueprint $table) use ($colunasDaUnique): void {
                $table->unique($colunasDaUnique, $this->nomeDaUnique());
            });
        }

        $this->imprimir(
            'devices.'.self::COLUNA.' agora é NOT NULL e único por empresa ('
            .implode(', ', $colunasDaUnique).').'
        );
    }

    /**
     * Reverte a migração: some a unique composta e a coluna volta a aceitar
     * nulo. Nenhum código gravado é apagado.
     */
    public function down(): void
    {
        if (! $this->tabelaElegivel()) {
            return;
        }

        $unique = $this->indiceUniqueComColunas($this->colunasDaUnique());

        if ($unique !== null) {
            Schema::table(self::TABELA, function (Blueprint $table) use ($unique): void {
                $table->dropUnique($unique);
            });
        }

        if (! $this->colunaAceitaNulo()) {
            Schema::table(self::TABELA, function (Blueprint $table): void {
                $table->string(self::COLUNA, self::TAMANHO_DA_COLUNA)->nullable()->change();
            });
        }

        $this->garantirIndiceSimples();

        $this->imprimir('devices.'.self::COLUNA.' voltou a aceitar nulo e a unique composta foi removida. Nenhum código foi apagado.');
    }

    /**
     * Guarda de dado: as duas condições que o `ALTER TABLE` exige, conferidas
     * antes de qualquer alteração de schema.
     */
    private function abortarSeODadoNaoSuportar(): void
    {
        $problemas = [];

        $semCodigo = DB::table(self::TABELA)
            ->where(function ($consulta): void {
                $consulta->whereNull(self::COLUNA)->orWhere(self::COLUNA, '');
            })
            ->count();

        if ($semCodigo > 0) {
            $problemas[] = "{$semCodigo} dispositivo(s) estão sem código público (nulo ou vazio).";
        }

        $duplicados = $this->codigosRepetidosNaMesmaEmpresa();

        if ($duplicados !== []) {
            $mostrados = array_slice($duplicados, 0, self::LIMITE_DE_VALORES_NO_RELATORIO);
            $restantes = count($duplicados) - count($mostrados);

            $problemas[] = 'Código repetido dentro da mesma empresa: '.implode('; ', array_map(
                static fn (array $item): string => "empresa #{$item['empresa']} código {$item['codigo']} x{$item['total']}",
                $mostrados
            )).($restantes > 0 ? " e mais {$restantes}" : '').'.';
        }

        if ($problemas === []) {
            return;
        }

        throw new RuntimeException(implode(PHP_EOL, array_merge(
            [
                'Migração interrompida ANTES de qualquer alteração de schema: o dado atual não suporta '
                .self::COLUNA.' obrigatório e único por empresa.',
                '',
                'Problemas encontrados:',
            ],
            array_map(static fn (string $problema): string => '  - '.$problema, $problemas),
            [
                '',
                'Nada foi alterado. Como resolver: rode o backfill do Deploy 2 e confira o relatório dele',
                'antes de repetir este deploy.',
                '',
                '  php artisan dispositivos:backfill-codigo --dry-run',
                '  php artisan dispositivos:backfill-codigo',
                '',
                'Código repetido dentro da mesma empresa não é resolvido pelo backfill, porque ele nunca',
                'sobrescreve código já gravado: nesse caso confira as linhas citadas a olho antes de seguir.',
            ]
        )));
    }

    /**
     * Códigos que aparecem mais de uma vez na mesma empresa.
     *
     * Linha com código nulo fica de fora: em MySQL a unique aceita vários
     * nulos, então elas não colidem entre si. Quem cobra o nulo é a outra
     * conferência, e ela vem da exigência de `NOT NULL`, não da unique.
     *
     * @return array<int, array{empresa: int, codigo: string, total: int}>
     */
    private function codigosRepetidosNaMesmaEmpresa(): array
    {
        $tenant = DominioMultiempresa::COLUNA_TENANT;

        return DB::table(self::TABELA)
            ->select([$tenant, self::COLUNA])
            ->selectRaw('COUNT(*) as total_de_linhas')
            ->whereNotNull(self::COLUNA)
            ->groupBy($tenant, self::COLUNA)
            ->havingRaw('COUNT(*) > 1')
            ->orderBy($tenant)
            ->get()
            ->map(static fn (object $linha): array => [
                'empresa' => (int) ($linha->{$tenant} ?? 0),
                'codigo' => (string) $linha->{self::COLUNA},
                'total' => (int) $linha->total_de_linhas,
            ])
            ->all();
    }

    /**
     * A tabela existe no banco conectado e tem as duas colunas envolvidas?
     */
    private function tabelaElegivel(): bool
    {
        return Schema::hasTable(self::TABELA)
            && Schema::hasColumns(self::TABELA, $this->colunasDaUnique());
    }

    /**
     * Colunas da unique, com a empresa primeiro.
     *
     * @return array<int, string>
     */
    private function colunasDaUnique(): array
    {
        return [DominioMultiempresa::COLUNA_TENANT, self::COLUNA];
    }

    /**
     * Nome do índice no padrão do Laravel.
     */
    private function nomeDaUnique(): string
    {
        return implode('_', array_merge([self::TABELA], $this->colunasDaUnique())).'_unique';
    }

    private function colunaAceitaNulo(): bool
    {
        foreach (Schema::getColumns(self::TABELA) as $coluna) {
            if (($coluna['name'] ?? null) === self::COLUNA) {
                return (bool) ($coluna['nullable'] ?? false);
            }
        }

        return false;
    }

    /**
     * Nome do índice unique cujas colunas são exatamente estas, ou `null` se
     * não existir. A ordem é ignorada: para efeito de unicidade `(a, b)` e
     * `(b, a)` restringem o mesmo conjunto de linhas.
     *
     * @param  array<int, string>  $colunas
     */
    private function indiceUniqueComColunas(array $colunas): ?string
    {
        sort($colunas);

        foreach (Schema::getIndexes(self::TABELA) as $indice) {
            if (! ($indice['unique'] ?? false) || ($indice['primary'] ?? false)) {
                continue;
            }

            $doIndice = $indice['columns'] ?? [];
            sort($doIndice);

            if ($doIndice === $colunas) {
                return $indice['name'];
            }
        }

        return null;
    }

    /**
     * Garante que o índice simples de `codigo_publico` continua existindo
     * depois da reversão.
     *
     * Na prática ele nunca sai, porque o Deploy 1 o criou e nada aqui o remove.
     * A checagem existe para um banco que tenha chegado a este ponto por outro
     * caminho: sem esse índice, a leitura pública do QR code varreria a tabela.
     */
    private function garantirIndiceSimples(): void
    {
        foreach (Schema::getIndexes(self::TABELA) as $indice) {
            if (($indice['columns'] ?? []) === [self::COLUNA]) {
                return;
            }
        }

        Schema::table(self::TABELA, function (Blueprint $table): void {
            $table->index(self::COLUNA);
        });
    }

    private function imprimir(string $mensagem): void
    {
        fwrite(STDOUT, PHP_EOL.'  '.$mensagem.PHP_EOL.PHP_EOL);
    }
};
