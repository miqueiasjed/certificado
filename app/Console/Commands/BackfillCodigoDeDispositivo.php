<?php

namespace App\Console\Commands;

use App\Support\CodigoDeDispositivo;
use App\Support\DominioMultiempresa;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deploy 2 do Plano 11: preenche `codigo_publico` nos dispositivos que já
 * existem, um passo de dado separado das migrations.
 *
 * A Task 11.1 criou a coluna nullable e sem unique. Este comando gera o código
 * de cada dispositivo que ainda está sem um. Só depois de conferir o relatório
 * daqui é que a migration
 * `2026_07_26_191200_make_codigo_publico_required_on_devices` entra, num
 * terceiro deploy, para tornar a coluna obrigatória e criar a unique composta
 * `[company_id, codigo_publico]`. Estrutura, backfill e restrição nunca no
 * mesmo deploy: é a regra do projeto para tabela com dado em produção.
 *
 * Decisões que espelham `BackfillCompanyId` e `BackfillAcesso`:
 *
 * - Sempre lê e imprime a conferência por empresa ANTES de tocar em qualquer
 *   dado, inclusive empresa que não tem nenhum dispositivo pendente.
 * - `--dry-run` para aí: nada é gravado, mas os códigos são sorteados de
 *   verdade, para que a simulação exercite a checagem de colisão em vez de só
 *   contar linhas.
 * - Gravação real usa `DB::table()` direto, nunca o Eloquent: não dispara
 *   evento de model e não passa pelo escopo global de empresa, que num comando
 *   de terminal não tem tenant resolvido para filtrar.
 * - Só toca em linha sem código (nula ou string vazia). Código já gravado nunca
 *   é sobrescrito, porque a etiqueta correspondente já pode ter sido impressa e
 *   colada. É isso que torna rodar duas vezes seguro.
 * - Percorre em blocos de `TAMANHO_DO_LOTE` com `chunkById`, que pagina por
 *   `id` crescente. Paginação por deslocamento erraria linhas justamente porque
 *   cada bloco gravado sai do filtro de pendentes.
 * - Reconfere no fim contra o banco: nenhum código nulo pode sobrar, o total de
 *   dispositivos não pode mudar (o comando nunca cria nem apaga linha) e não
 *   pode existir código repetido dentro da mesma empresa. Divergência devolve
 *   código de saída de falha, porque a migration seguinte depende dessas três
 *   condições.
 */
class BackfillCodigoDeDispositivo extends Command
{
    /**
     * Linhas por bloco de leitura. O tamanho combinado na task, e o suficiente
     * para não segurar a tabela inteira em memória numa base grande.
     */
    private const TAMANHO_DO_LOTE = 500;

    /**
     * Tabela alvo. Uma constante para o nome não ficar solto em oito consultas.
     */
    private const TABELA = 'devices';

    /**
     * Coluna preenchida por este backfill.
     */
    private const COLUNA = 'codigo_publico';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dispositivos:backfill-codigo
                            {--dry-run : Mostra a conferência por empresa e para, sem gravar nada}
                            {--force : Grava sem pedir confirmação, para execução não interativa}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gera o código público dos dispositivos que ainda estão sem um, com conferência por empresa antes e depois.';

    /**
     * Códigos já em uso, por empresa, no formato [company_id => [codigo => true]].
     *
     * Carregado uma vez do banco e alimentado a cada código sorteado nesta
     * execução. Sem esse registro em memória, dois dispositivos da mesma
     * empresa sorteados no mesmo lote poderiam receber o mesmo código, e a
     * unique composta da migration seguinte não poderia ser criada.
     *
     * @var array<int, array<string, bool>>
     */
    private array $codigosEmUso = [];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $simulacao = (bool) $this->option('dry-run');

        if (! $this->tabelaElegivel()) {
            return Command::FAILURE;
        }

        $this->imprimirCabecalho($simulacao);

        $levantamento = $this->levantarConferencia();
        $this->imprimirConferencia($levantamento);

        $pendentes = array_sum(array_column($levantamento, 'sem_codigo'));

        if ($pendentes === 0) {
            $this->newLine();
            $this->info('Nenhum dispositivo sem código público. Nada a gerar.');

            return $this->reconferir($levantamento);
        }

        $this->carregarCodigosEmUso();

        if ($simulacao) {
            return $this->simular($levantamento, $pendentes);
        }

        $this->newLine();

        if (! $this->option('force') && ! $this->confirm(
            "Gerar e gravar o código público de {$pendentes} dispositivo(s)?",
            false
        )) {
            $this->warn('Operação cancelada. Nada foi gravado.');

            return Command::SUCCESS;
        }

        $this->newLine();
        $gerados = $this->aplicar();

        $this->newLine();
        $this->imprimirRelatorioFinal($levantamento, $gerados);

        return $this->reconferir($this->levantarConferencia());
    }

    /**
     * A tabela existe e tem as colunas de que este backfill depende?
     *
     * Banco sem a coluna é banco que ainda não recebeu o Deploy 1 do Plano 11.
     * Falhar aqui, com a instrução na tela, vale mais do que estourar erro de
     * SQL no meio da execução.
     */
    private function tabelaElegivel(): bool
    {
        if (! Schema::hasTable(self::TABELA)) {
            $this->error('A tabela "'.self::TABELA.'" não existe no banco conectado.');

            return false;
        }

        $faltando = array_values(array_filter(
            [self::COLUNA, DominioMultiempresa::COLUNA_TENANT],
            fn (string $coluna): bool => ! Schema::hasColumn(self::TABELA, $coluna)
        ));

        if ($faltando !== []) {
            $this->error(
                'A tabela "'.self::TABELA.'" não tem a(s) coluna(s): '.implode(', ', $faltando).'. '
                .'Rode as migrations pendentes antes deste backfill.'
            );

            return false;
        }

        return true;
    }

    private function imprimirCabecalho(bool $simulacao): void
    {
        $this->info('Backfill do código público dos dispositivos (Plano 11, Deploy 2)');
        $this->line($simulacao
            ? 'Modo simulação (--dry-run): os códigos são sorteados e conferidos, mas nada é gravado.'
            : 'Modo aplicação: leitura em blocos de '.self::TAMANHO_DO_LOTE.', com reconferência no banco ao final.');
        $this->line('Código já gravado nunca é sobrescrito: a etiqueta correspondente pode já estar impressa.');
        $this->newLine();
    }

    /**
     * Estado atual por empresa, sem gravar nada. Toda empresa com dispositivo
     * aparece, inclusive a que já está inteira preenchida.
     *
     * @return array<int, array{empresa: int, nome: string, total: int, com_codigo: int, sem_codigo: int, situacao: string}>
     */
    private function levantarConferencia(): array
    {
        $tenant = DominioMultiempresa::COLUNA_TENANT;

        $nomes = DB::table('companies')->pluck('name', 'id');

        return DB::table(self::TABELA)
            ->select($tenant)
            ->selectRaw('COUNT(*) as total_de_linhas')
            ->selectRaw('SUM(CASE WHEN '.self::COLUNA.' IS NULL OR '.self::COLUNA." = '' THEN 1 ELSE 0 END) as sem_codigo")
            ->groupBy($tenant)
            ->orderBy($tenant)
            ->get()
            ->map(function (object $linha) use ($tenant, $nomes): array {
                $empresa = (int) ($linha->{$tenant} ?? 0);
                $total = (int) $linha->total_de_linhas;
                $semCodigo = (int) $linha->sem_codigo;

                return [
                    'empresa' => $empresa,
                    'nome' => $this->rotuloDaEmpresa($empresa, $nomes),
                    'total' => $total,
                    'com_codigo' => $total - $semCodigo,
                    'sem_codigo' => $semCodigo,
                    'situacao' => $semCodigo === 0
                        ? 'já preenchida'
                        : "{$semCodigo} dispositivo(s) pendente(s)",
                ];
            })
            ->all();
    }

    /**
     * Rótulo da empresa na saída. Distingue os dois casos que confundiriam o
     * operador: empresa cadastrada sem nome preenchido (o caso da empresa
     * fundadora numa instalação nova) e dispositivo apontando para uma empresa
     * que não existe na tabela `companies`, que seria dado órfão.
     *
     * @param  Collection<int, string|null>  $nomes
     */
    private function rotuloDaEmpresa(int $empresa, Collection $nomes): string
    {
        if (! $nomes->has($empresa)) {
            return 'empresa não cadastrada';
        }

        $nome = trim((string) $nomes->get($empresa));

        return $nome === '' ? 'sem nome' : $nome;
    }

    /**
     * @param  array<int, array{empresa: int, nome: string, total: int, com_codigo: int, sem_codigo: int, situacao: string}>  $linhas
     */
    private function imprimirConferencia(array $linhas): void
    {
        $this->line('Conferência antes de qualquer alteração, uma linha por empresa:');

        if ($linhas === []) {
            $this->line('  nenhum dispositivo cadastrado em nenhuma empresa');

            return;
        }

        $this->table(
            ['Empresa', 'Total de dispositivos', 'Já com código', 'Sem código', 'Situação'],
            array_map(fn (array $linha): array => [
                "#{$linha['empresa']} {$linha['nome']}",
                $linha['total'],
                $linha['com_codigo'],
                $linha['sem_codigo'],
                $linha['situacao'],
            ], $linhas)
        );

        $this->line('  Total geral de dispositivos: '.array_sum(array_column($linhas, 'total')));
        $this->line('  Total geral sem código: '.array_sum(array_column($linhas, 'sem_codigo')));
    }

    /**
     * Carrega em memória todos os códigos já gravados, agrupados por empresa.
     *
     * A base de dispositivos de uma empresa de controle de pragas é de milhares
     * de linhas, não de milhões, então o conjunto cabe folgado na memória e
     * evita uma consulta por sorteio.
     */
    private function carregarCodigosEmUso(): void
    {
        $tenant = DominioMultiempresa::COLUNA_TENANT;
        $this->codigosEmUso = [];

        DB::table(self::TABELA)
            ->select([$tenant, self::COLUNA])
            ->whereNotNull(self::COLUNA)
            ->where(self::COLUNA, '<>', '')
            ->orderBy('id')
            ->chunk(self::TAMANHO_DO_LOTE, function ($linhas) use ($tenant): void {
                foreach ($linhas as $linha) {
                    $this->registrarCodigo((int) ($linha->{$tenant} ?? 0), (string) $linha->{self::COLUNA});
                }
            });
    }

    private function registrarCodigo(int $empresa, string $codigo): void
    {
        $this->codigosEmUso[$empresa][$codigo] = true;
    }

    /**
     * Sorteia um código inédito dentro da empresa e já o reserva em memória,
     * para o próximo dispositivo do mesmo lote não repetir.
     */
    private function sortearCodigo(int $empresa): string
    {
        $codigo = CodigoDeDispositivo::gerarInedito(
            fn (string $candidato): bool => isset($this->codigosEmUso[$empresa][$candidato])
        );

        $this->registrarCodigo($empresa, $codigo);

        return $codigo;
    }

    /**
     * `--dry-run`: sorteia o código de cada pendente, conta por empresa e
     * descarta tudo. Nenhum UPDATE é executado.
     *
     * @param  array<int, array{empresa: int, nome: string, total: int, com_codigo: int, sem_codigo: int, situacao: string}>  $levantamento
     */
    private function simular(array $levantamento, int $pendentes): int
    {
        $simulados = [];
        $exemplos = [];

        $this->percorrerPendentes(function (object $dispositivo) use (&$simulados, &$exemplos): void {
            $empresa = (int) ($dispositivo->{DominioMultiempresa::COLUNA_TENANT} ?? 0);
            $codigo = $this->sortearCodigo($empresa);

            $simulados[$empresa] = ($simulados[$empresa] ?? 0) + 1;

            if (! isset($exemplos[$empresa])) {
                $exemplos[$empresa] = $codigo;
            }
        });

        $this->newLine();
        $this->line('Códigos que seriam gerados:');
        $this->table(
            ['Empresa', 'Códigos a gerar', 'Exemplo de código sorteado'],
            array_map(fn (array $linha): array => [
                "#{$linha['empresa']} {$linha['nome']}",
                $simulados[$linha['empresa']] ?? 0,
                $exemplos[$linha['empresa']] ?? '-',
            ], $levantamento)
        );

        $this->newLine();
        $this->info("Simulação concluída: {$pendentes} código(s) seriam gerados. Nada foi gravado.");
        $this->line('Confira os números acima contra o banco antes de rodar sem --dry-run.');

        return Command::SUCCESS;
    }

    /**
     * Grava o código de cada dispositivo pendente, em blocos.
     *
     * O UPDATE é por linha porque cada linha recebe um valor diferente. Sem
     * `updated_at`: o backfill preenche um dado que sempre pertenceu àquele
     * dispositivo, e mexer no carimbo de alteração faria toda a base parecer
     * editada no dia do deploy.
     *
     * @return array<int, int> Quantidade gerada por empresa.
     */
    private function aplicar(): array
    {
        $gerados = [];

        $this->percorrerPendentes(function (object $dispositivo) use (&$gerados): void {
            $empresa = (int) ($dispositivo->{DominioMultiempresa::COLUNA_TENANT} ?? 0);
            $codigo = $this->sortearCodigo($empresa);

            DB::table(self::TABELA)
                ->where('id', $dispositivo->id)
                ->update([self::COLUNA => $codigo]);

            $gerados[$empresa] = ($gerados[$empresa] ?? 0) + 1;
        });

        return $gerados;
    }

    /**
     * Percorre os dispositivos sem código em blocos de `TAMANHO_DO_LOTE`,
     * paginando por `id` (`chunkById`).
     *
     * Paginar por deslocamento (`chunk`) erraria linhas na gravação: cada bloco
     * gravado deixa de casar com o filtro de pendentes e as linhas seguintes
     * andariam para trás. `chunkById` não tem esse problema porque a próxima
     * página é sempre "id maior que o último visto".
     *
     * @param  callable(object): void  $aoEncontrar
     */
    private function percorrerPendentes(callable $aoEncontrar): void
    {
        $this->consultaDePendentes()
            ->select(['id', DominioMultiempresa::COLUNA_TENANT])
            ->chunkById(self::TAMANHO_DO_LOTE, function ($dispositivos) use ($aoEncontrar): void {
                foreach ($dispositivos as $dispositivo) {
                    $aoEncontrar($dispositivo);
                }
            });
    }

    /**
     * Dispositivos sem código: nulo ou string vazia.
     *
     * String vazia entra junto porque a coluna aceita as duas formas hoje e as
     * duas quebrariam a migration de `NOT NULL` + unique do mesmo jeito, a
     * segunda de forma pior (unique aceita vários nulos, mas nenhuma segunda
     * string vazia na mesma empresa).
     */
    private function consultaDePendentes(): Builder
    {
        return DB::table(self::TABELA)->where(function (Builder $consulta): void {
            $consulta->whereNull(self::COLUNA)->orWhere(self::COLUNA, '');
        });
    }

    /**
     * @param  array<int, array{empresa: int, nome: string, total: int, com_codigo: int, sem_codigo: int, situacao: string}>  $levantamento
     * @param  array<int, int>  $gerados
     */
    private function imprimirRelatorioFinal(array $levantamento, array $gerados): void
    {
        $this->line('Relatório final:');
        $this->table(
            ['Empresa', 'Sem código antes', 'Códigos gerados', 'Total de dispositivos'],
            array_map(fn (array $linha): array => [
                "#{$linha['empresa']} {$linha['nome']}",
                $linha['sem_codigo'],
                $gerados[$linha['empresa']] ?? 0,
                $linha['total'],
            ], $levantamento)
        );

        $this->newLine();
        $this->info('Total geral de códigos gerados: '.array_sum($gerados).'.');
    }

    /**
     * Conferência final contra o banco, e não contra o que o comando achou que
     * fez. As três condições são exatamente as que a migration de `NOT NULL` +
     * unique composta exige para poder ser aplicada.
     *
     * @param  array<int, array{empresa: int, nome: string, total: int, com_codigo: int, sem_codigo: int, situacao: string}>  $levantamento
     */
    private function reconferir(array $levantamento): int
    {
        $pendentes = $this->consultaDePendentes()->count();
        $duplicados = $this->duplicadosPorEmpresa();
        $totalNoBanco = DB::table(self::TABELA)->count();
        $totalEsperado = array_sum(array_column($levantamento, 'total'));

        $problemas = [];

        if ($pendentes > 0) {
            $problemas[] = "{$pendentes} dispositivo(s) continuam sem código público.";
        }

        if ($duplicados !== []) {
            $descricao = array_map(
                static fn (array $item): string => "empresa #{$item['empresa']} código {$item['codigo']} x{$item['total']}",
                array_slice($duplicados, 0, 10)
            );

            $problemas[] = 'Código repetido dentro da mesma empresa: '.implode('; ', $descricao)
                .(count($duplicados) > 10 ? ' e mais '.(count($duplicados) - 10) : '').'.';
        }

        if ($totalNoBanco !== $totalEsperado) {
            $problemas[] = "O total de dispositivos mudou durante a execução: {$totalEsperado} antes, {$totalNoBanco} agora.";
        }

        $this->newLine();

        if ($problemas !== []) {
            $this->error('Conferência final reprovada. NÃO aplique a migration de NOT NULL + unique antes de resolver:');

            foreach ($problemas as $problema) {
                $this->line('  - '.$problema);
            }

            return Command::FAILURE;
        }

        $this->info('Conferência final aprovada: nenhum dispositivo sem código e nenhum código repetido dentro da mesma empresa.');
        $this->line('Próximo passo (deploy separado): php artisan migrate, que aplica NOT NULL e a unique composta [company_id, codigo_publico].');

        return Command::SUCCESS;
    }

    /**
     * Códigos repetidos dentro da mesma empresa. Linha sem código fica de fora:
     * a unique composta aceita vários nulos, então elas não colidem entre si e
     * contá-las aqui daria falso positivo. Quem cobra o nulo é `$pendentes`.
     *
     * @return array<int, array{empresa: int, codigo: string, total: int}>
     */
    private function duplicadosPorEmpresa(): array
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
}
