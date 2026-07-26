<?php

use App\Support\DominioMultiempresa;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 4 da migração multiempresa (Plano 4, Deploy 4): converte as uniques
 * globais listadas em `DominioMultiempresa::UNIQUES_A_COMPOR` em uniques
 * compostas com `company_id`.
 *
 * Vem depois da Task 4.4 (Deploy 3), que deixou `company_id` NOT NULL, indexado
 * e com chave estrangeira em todas as tabelas de domínio. Rodar antes disso
 * criaria unique sobre coluna que ainda aceita nulo, e a restrição não seguraria
 * nada.
 *
 * Por que esta etapa existe, na ordem do risco:
 *
 * - `daily_cash_balances.balance_date` é a mais perigosa. Com a unique global,
 *   o segundo tenant não consegue criar o saldo do dia porque o primeiro já
 *   criou. O código chama `firstOrCreate(['balance_date' => hoje])`, então a
 *   consulta escapada por empresa não acha nada, a inserção bate na unique e o
 *   financeiro do segundo tenant falha **sem erro visível para o usuário**.
 * - `work_orders.order_number` e `service_orders.order_number` colidem na
 *   numeração entre empresas.
 * - `technicians.email` impede o mesmo técnico de existir em duas empresas.
 * - `service_types.slug` impede cada empresa de ter os próprios tipos.
 *
 * `users.email` **não** é tocada aqui: continua unique global por decisão
 * registrada no PRD, porque o login é por e-mail. A lista completa das uniques
 * que continuam globais de propósito, cada uma com o motivo, vive em
 * `DominioMultiempresa::UNIQUES_GLOBAIS_MANTIDOS`.
 *
 * Decisões desta etapa:
 *
 * - **Guarda antes de tocar no schema.** O `up()` confere as cinco tabelas
 *   inteiras antes de alterar qualquer uma. Se houver duplicata de
 *   `(company_id, coluna)`, aborta listando tabela, valores e quantidade, sem
 *   ter alterado nada. Sem a guarda, o MySQL derrubaria a migração no meio, com
 *   erro genérico de chave duplicada, deixando parte das tabelas convertida e
 *   parte não.
 * - **Cria a composta antes de dropar a global.** Assim não existe janela em
 *   que a tabela fica sem nenhuma restrição de unicidade. Com um único tenant a
 *   composta aceita exatamente o mesmo conjunto de linhas que a global, então
 *   essa ordem é sempre segura na ida.
 * - **Um `Schema::table()` por operação.** Em MySQL, criar e dropar índice na
 *   mesma instrução `ALTER TABLE` em tabela que já tem chave estrangeira em
 *   `company_id` esbarra em erro de ordem de operação (1553, "cannot drop index
 *   needed in a foreign key constraint"), porque o servidor avalia o estado
 *   intermediário. Separando em instruções distintas, cada uma vê um estado
 *   completo e válido. A etapa anterior já topou com isso.
 * - **Preserva o índice simples de `company_id`.** Depois da composta ele fica
 *   tecnicamente redundante para leitura, mas é ele que sustenta a chave
 *   estrangeira de tenant. Removê-lo faria a chave passar a depender da unique
 *   composta, e aí o `down()` desta migração não conseguiria mais dropá-la.
 * - **Idempotente.** Tabela que já está com a composta e sem a global é pulada,
 *   e cada peça é aplicada só se ainda faltar.
 *
 * ## Sobre o retorno (`down()`)
 *
 * O `down()` recria a unique global e remove a composta. **Ele só funciona
 * enquanto não houver duplicata entre tenants.** Assim que existir um segundo
 * tenant com saldo diário do mesmo dia, com número de OS repetido ou com o mesmo
 * técnico, voltar a unique global é impossível sem apagar dado de um dos
 * tenants. Por isso o `down()` também confere antes e aborta com a lista dos
 * valores em conflito, em vez de deixar o MySQL falhar no meio: se ele abortar,
 * o retorno desta etapa deixou de existir e a saída passa a ser avançar, nunca
 * voltar. Este é o ponto sem volta que o Deploy 4 marca.
 */
return new class extends Migration
{
    /**
     * Quantos valores duplicados distintos aparecem por tabela na mensagem de
     * erro. O suficiente para o operador entender o problema sem despejar
     * milhares de linhas no meio de um deploy.
     */
    private const LIMITE_DE_VALORES_NO_RELATORIO = 10;

    /**
     * Limite do MySQL para nome de índice.
     */
    private const TAMANHO_MAXIMO_DO_NOME_DE_INDICE = 64;

    /**
     * Executa a migração: as uniques globais viram compostas com `company_id`.
     */
    public function up(): void
    {
        $this->abortarSeHouverDuplicata(sentido: 'ida');

        $convertidas = [];
        $puladas = [];

        foreach (DominioMultiempresa::UNIQUES_A_COMPOR as $tabela => $esperada) {
            if (! $this->tabelaElegivel($tabela, $esperada['colunas'])) {
                $puladas[] = $tabela;

                continue;
            }

            $colunasCompostas = $this->colunasCompostas($esperada['colunas']);
            $nomeComposto = $this->nomeDoIndice($tabela, $colunasCompostas);

            $jaComposta = $this->uniqueComColunas($tabela, $colunasCompostas);
            $global = $this->uniqueComColunas($tabela, $esperada['colunas']);

            if ($jaComposta !== null && $global === null) {
                $puladas[] = $tabela;

                continue;
            }

            if ($jaComposta === null) {
                Schema::table($tabela, function (Blueprint $table) use ($colunasCompostas, $nomeComposto): void {
                    $table->unique($colunasCompostas, $nomeComposto);
                });
            }

            if ($global !== null) {
                $this->garantirIndiceDeTenant($tabela, $global);

                Schema::table($tabela, function (Blueprint $table) use ($global): void {
                    $table->dropUnique($global);
                });
            }

            $convertidas[$tabela] = [
                'de' => $global ?? '(já removida)',
                'para' => $jaComposta ?? $nomeComposto,
            ];
        }

        $this->imprimirRelatorio('Uniques globais convertidas em compostas com '.DominioMultiempresa::COLUNA_TENANT, $convertidas, $puladas);
    }

    /**
     * Reverte a migração: recria a unique global e remove a composta.
     *
     * Só é possível enquanto não existir duplicata entre tenants. A guarda
     * confere isso antes de tocar em qualquer tabela e aborta com a lista dos
     * valores em conflito.
     */
    public function down(): void
    {
        $this->abortarSeHouverDuplicata(sentido: 'volta');

        $revertidas = [];
        $puladas = [];

        foreach (DominioMultiempresa::UNIQUES_A_COMPOR as $tabela => $esperada) {
            if (! $this->tabelaElegivel($tabela, $esperada['colunas'])) {
                $puladas[] = $tabela;

                continue;
            }

            $colunasCompostas = $this->colunasCompostas($esperada['colunas']);
            $nomeGlobal = $esperada['indice'];

            $composta = $this->uniqueComColunas($tabela, $colunasCompostas);
            $global = $this->uniqueComColunas($tabela, $esperada['colunas']);

            if ($global !== null && $composta === null) {
                $puladas[] = $tabela;

                continue;
            }

            if ($global === null) {
                Schema::table($tabela, function (Blueprint $table) use ($esperada, $nomeGlobal): void {
                    $table->unique($esperada['colunas'], $nomeGlobal);
                });
            }

            if ($composta !== null) {
                $this->garantirIndiceDeTenant($tabela, $composta);

                Schema::table($tabela, function (Blueprint $table) use ($composta): void {
                    $table->dropUnique($composta);
                });
            }

            $revertidas[$tabela] = [
                'de' => $composta ?? '(já removida)',
                'para' => $global ?? $nomeGlobal,
            ];
        }

        $this->imprimirRelatorio('Uniques compostas revertidas para globais', $revertidas, $puladas);
    }

    /**
     * Guarda de unicidade: percorre as cinco tabelas e só deixa a migração
     * seguir se nenhuma delas tiver duplicata no conjunto de colunas de destino.
     *
     * Na ida o destino é `(company_id, coluna)`; na volta é só `(coluna)`.
     * Confere todas as tabelas antes de decidir, em vez de parar na primeira com
     * problema: o operador precisa ver o quadro completo de uma vez. Nada de
     * schema é alterado até esta conferência passar inteira.
     */
    private function abortarSeHouverDuplicata(string $sentido): void
    {
        $problemas = [];

        foreach (DominioMultiempresa::UNIQUES_A_COMPOR as $tabela => $esperada) {
            if (! $this->tabelaElegivel($tabela, $esperada['colunas'])) {
                continue;
            }

            $colunas = $sentido === 'ida'
                ? $this->colunasCompostas($esperada['colunas'])
                : $esperada['colunas'];

            $duplicatas = $this->duplicatasDaTabela($tabela, $colunas);

            if ($duplicatas === []) {
                continue;
            }

            $mostradas = array_slice($duplicatas, 0, self::LIMITE_DE_VALORES_NO_RELATORIO);
            $restantes = count($duplicatas) - count($mostradas);

            $descricao = array_map(
                static fn (array $duplicata): string => '('.implode(', ', $duplicata['valores']).') x'.$duplicata['total'],
                $mostradas
            );

            $problemas[] = sprintf(
                '%s: %d valor(es) repetido(s) em (%s): %s%s.',
                $tabela,
                count($duplicatas),
                implode(', ', $colunas),
                implode('; ', $descricao),
                $restantes > 0 ? " e mais {$restantes}" : ''
            );
        }

        if ($problemas === []) {
            return;
        }

        throw new RuntimeException(implode(PHP_EOL, array_merge(
            [
                $sentido === 'ida'
                    ? 'Migração interrompida ANTES de qualquer alteração de schema: o dado atual não suporta unique composta com '.DominioMultiempresa::COLUNA_TENANT.'.'
                    : 'Retorno interrompido ANTES de qualquer alteração de schema: o dado atual não suporta mais a unique global.',
                '',
                'Conflitos encontrados:',
            ],
            array_map(static fn (string $problema): string => '  - '.$problema, $problemas),
            $sentido === 'ida'
                ? [
                    '',
                    'Nenhuma tabela foi alterada. Como resolver: a mesma empresa tem duas linhas com o mesmo',
                    'valor, o que a unique global de hoje já deveria impedir. Confira se o backfill da Etapa 2',
                    '(`php artisan multiempresa:backfill`) atribuiu a empresa certa a cada linha e corrija as',
                    'linhas citadas antes de repetir o deploy.',
                ]
                : [
                    '',
                    'Nenhuma tabela foi alterada. Empresas diferentes já têm o mesmo valor, então voltar à unique',
                    'global exigiria apagar dado de um dos tenants. O retorno desta etapa deixou de existir: este',
                    'é o ponto sem volta do Deploy 4. A saída daqui é avançar, com restauração de backup como',
                    'única alternativa real.',
                ]
        )));
    }

    /**
     * Valores repetidos no conjunto de colunas, com quantas linhas cada um
     * afeta.
     *
     * Linhas com nulo em qualquer das colunas ficam de fora: em MySQL a unique
     * aceita vários nulos, então elas não colidem e contá-las daria falso
     * positivo.
     *
     * @param  array<int, string>  $colunas
     * @return array<int, array{valores: array<int, string>, total: int}>
     */
    private function duplicatasDaTabela(string $tabela, array $colunas): array
    {
        $consulta = DB::table($tabela);

        foreach ($colunas as $coluna) {
            $consulta->whereNotNull($coluna);
        }

        return $consulta
            ->select($colunas)
            ->selectRaw('COUNT(*) as total_de_linhas')
            ->groupBy($colunas)
            ->havingRaw('COUNT(*) > 1')
            ->orderBy($colunas[0])
            ->get()
            ->map(static fn (object $linha): array => [
                'valores' => array_map(
                    static fn (string $coluna): string => (string) ($linha->{$coluna} ?? ''),
                    $colunas
                ),
                'total' => (int) $linha->total_de_linhas,
            ])
            ->all();
    }

    /**
     * A tabela existe no banco conectado e tem a coluna de tenant e todas as
     * colunas da unique?
     *
     * @param  array<int, string>  $colunas
     */
    private function tabelaElegivel(string $tabela, array $colunas): bool
    {
        if (! Schema::hasTable($tabela)) {
            return false;
        }

        return Schema::hasColumns($tabela, $this->colunasCompostas($colunas));
    }

    /**
     * Colunas da unique composta: tenant primeiro, para que o índice também
     * sirva às consultas que filtram só por empresa.
     *
     * @param  array<int, string>  $colunas
     * @return array<int, string>
     */
    private function colunasCompostas(array $colunas): array
    {
        return array_merge([DominioMultiempresa::COLUNA_TENANT], $colunas);
    }

    /**
     * Nome do índice no padrão do Laravel (`tabela_coluna_coluna_unique`).
     *
     * @param  array<int, string>  $colunas
     */
    private function nomeDoIndice(string $tabela, array $colunas): string
    {
        $nome = implode('_', array_merge([$tabela], $colunas)).'_unique';

        if (strlen($nome) > self::TAMANHO_MAXIMO_DO_NOME_DE_INDICE) {
            throw new RuntimeException(
                "Migração interrompida: o nome de índice \"{$nome}\" tem ".strlen($nome)
                .' caracteres e o MySQL aceita no máximo '.self::TAMANHO_MAXIMO_DO_NOME_DE_INDICE
                .'. Defina um nome curto para essa unique antes de seguir.'
            );
        }

        return $nome;
    }

    /**
     * Nome do índice unique cujas colunas são exatamente estas, ou null se não
     * existir. A ordem é ignorada de propósito: para efeito de unicidade
     * `(a, b)` e `(b, a)` restringem o mesmo conjunto de linhas, e um índice
     * criado na ordem invertida por migration antiga precisa ser reconhecido.
     *
     * @param  array<int, string>  $colunas
     */
    private function uniqueComColunas(string $tabela, array $colunas): ?string
    {
        sort($colunas);

        foreach (Schema::getIndexes($tabela) as $indice) {
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
     * Garante que sobra um índice começando por `company_id` para sustentar a
     * chave estrangeira de tenant depois que `$indiceASerRemovido` sair.
     *
     * Em MySQL, dropar o último índice que serve a uma chave estrangeira falha
     * com o erro 1553. Na prática a Etapa 3 já criou `tabela_company_id_index`
     * em todas as tabelas de domínio, então esta função não faz nada. Ela existe
     * para o caso de um banco que chegou aqui por outro caminho, e cria o índice
     * em instrução própria, antes do drop.
     */
    private function garantirIndiceDeTenant(string $tabela, string $indiceASerRemovido): void
    {
        $coluna = DominioMultiempresa::COLUNA_TENANT;

        if (! Schema::hasColumn($tabela, $coluna)) {
            return;
        }

        $temChaveDeTenant = false;

        foreach (Schema::getForeignKeys($tabela) as $chave) {
            if (($chave['columns'] ?? []) === [$coluna]) {
                $temChaveDeTenant = true;

                break;
            }
        }

        if (! $temChaveDeTenant) {
            return;
        }

        foreach (Schema::getIndexes($tabela) as $indice) {
            if ($indice['name'] === $indiceASerRemovido || ($indice['primary'] ?? false)) {
                continue;
            }

            if (($indice['columns'][0] ?? null) === $coluna) {
                return;
            }
        }

        Schema::table($tabela, function (Blueprint $table) use ($coluna): void {
            $table->index($coluna);
        });
    }

    /**
     * Relatório do que foi feito, com o nome real do índice antes e depois.
     *
     * @param  array<string, array{de: string, para: string}>  $alteradas
     * @param  array<int, string>  $puladas
     */
    private function imprimirRelatorio(string $titulo, array $alteradas, array $puladas): void
    {
        $saida = [
            '',
            sprintf('  %s: %d tabela(s) alterada(s), %d pulada(s) (já no estado final ou sem a tabela).', $titulo, count($alteradas), count($puladas)),
        ];

        foreach ($alteradas as $tabela => $indices) {
            $saida[] = sprintf('    %-22s %s  ->  %s', $tabela, $indices['de'], $indices['para']);
        }

        if ($puladas !== []) {
            $saida[] = '    Puladas: '.implode(', ', $puladas);
        }

        $saida[] = '  users.email continua unique GLOBAL de propósito: o login é por e-mail (PRD).';
        $saida[] = '';

        fwrite(STDOUT, implode(PHP_EOL, $saida).PHP_EOL);
    }
};
