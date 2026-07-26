<?php

namespace App\Console\Commands;

use App\Support\BusinessDate;
use App\Support\DominioMultiempresa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 2 da migração multiempresa (Plano 4, Deploy 2): preenche `company_id`
 * nas linhas existentes de todas as tabelas de domínio listadas em
 * `DominioMultiempresa::TABELAS_DE_DOMINIO`.
 *
 * A Task 4.2 já deixou a coluna nullable, sem índice e sem chave estrangeira
 * em todas essas tabelas, e hoje toda linha está com `company_id` nulo, num
 * banco de tenant único. Este comando é o que torna esse estado explícito:
 * grava a empresa informada (`--company`, padrão 1) em cada linha nula.
 *
 * Decisões de segurança que espelham o restante do domínio (ver
 * `PurgeAuditLogs`, `CorrectPendingInstallments` e `BackfillPeriodicidade`):
 *
 * - Sempre lê e imprime a conferência (total, já preenchidos, nulos) por
 *   tabela ANTES de tocar em qualquer dado, mesmo mostrando tabela vazia como
 *   vazia, nunca omitida.
 * - `--dry-run` para exatamente aí: não grava nada, mas ainda assim salva o
 *   relatório em `storage/logs/`, porque é esse relatório que o operador
 *   confere linha a linha antes de rodar de verdade.
 * - Execução real usa `DB::table()` direto, nunca o Eloquent model: não
 *   dispara evento de auditoria (Auditavel) nem passaria pelo escopo global
 *   de empresa, que ainda nem existe (chega na Task 4.6).
 * - Só toca em linha com `company_id` nulo (`whereNull`). Linha que já tem
 *   `company_id`, seja porque uma execução anterior já preencheu ou por
 *   qualquer outro motivo, nunca é sobrescrita: o comando só preenche o que
 *   está vazio. É isso que torna rodar duas vezes seguro.
 * - Atualiza em lotes de `TAMANHO_DO_LOTE` para não travar tabela grande com
 *   um único UPDATE.
 * - Reconfere cada tabela logo depois de atualizá-la: o total de linhas
 *   precisa continuar idêntico (o comando nunca cria nem apaga linha) e não
 *   pode sobrar nulo. Qualquer divergência interrompe a execução na hora,
 *   sem seguir para a próxima tabela, porque essa conferência vale mais que
 *   o preenchimento em si.
 * - `NOT NULL`, índice e chave estrangeira ficam para a Task 4.4, em deploy
 *   separado, só depois deste relatório ser conferido a olho.
 */
class BackfillCompanyId extends Command
{
    /**
     * Linhas atualizadas por iteração do laço de UPDATE, para não travar a
     * tabela com um único UPDATE gigante em produção.
     */
    private const TAMANHO_DO_LOTE = 1000;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'multiempresa:backfill
                            {--dry-run : Mostra a conferência completa e para, sem gravar nada}
                            {--company=1 : ID da empresa (company_id) gravado nas linhas ainda nulas; padrão 1, único tenant hoje}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Preenche company_id nas linhas existentes das tabelas de domínio, com conferência de contagem antes e depois de cada tabela.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $simulacao = (bool) $this->option('dry-run');
        $empresa = $this->resolverEmpresa();

        if ($empresa === null) {
            $this->error('A opção --company precisa ser um número inteiro maior que zero.');

            return Command::FAILURE;
        }

        $this->imprimirCabecalho($simulacao, $empresa);

        $levantamento = $this->levantarConferencia();
        $this->imprimirConferencia($levantamento);

        $pendencias = array_sum(array_column($levantamento, 'nulos_antes'));

        if ($simulacao) {
            $this->newLine();
            $this->info('Simulação concluída. Nada foi gravado.');
            $this->gravarLog($empresa, true, $levantamento, 'simulação (--dry-run): nada foi gravado');

            return Command::SUCCESS;
        }

        if ($pendencias === 0) {
            $this->newLine();
            $this->info('Nenhuma linha pendente de company_id em nenhuma tabela de domínio. Nada a gravar.');
            $this->gravarLog($empresa, false, $levantamento, 'nada a gravar: nenhuma linha pendente');

            return Command::SUCCESS;
        }

        $this->newLine();

        if (! $this->confirm(sprintf(
            'Gravar company_id = %d em %d linha(s) pendente(s) das tabelas de domínio?',
            $empresa,
            $pendencias
        ), false)) {
            $this->warn('Operação cancelada. Nada foi gravado.');
            $this->gravarLog($empresa, false, $levantamento, 'operação cancelada pelo operador: nada foi gravado');

            return Command::SUCCESS;
        }

        $this->newLine();
        $aplicacao = $this->aplicarBackfill($levantamento, $empresa);

        $this->newLine();

        if ($aplicacao['erro'] !== null) {
            $this->error($aplicacao['erro']);
            $this->gravarLog($empresa, false, $aplicacao['linhas'], 'INTERROMPIDO: ' . $aplicacao['erro']);

            return Command::FAILURE;
        }

        $this->imprimirRelatorioFinal($aplicacao['linhas']);
        $this->gravarLog($empresa, false, $aplicacao['linhas'], 'concluído com sucesso');

        return Command::SUCCESS;
    }

    /**
     * `--company` vem de opção de linha de comando, sempre string. Só aceita
     * inteiro positivo: rodar com outro tenant no banco atual é erro de
     * operação, mas um valor que nem é número inteiro é erro de digitação, e
     * o comando recusa em vez de tentar adivinhar.
     */
    private function resolverEmpresa(): ?int
    {
        $informada = $this->option('company');

        if (! is_numeric($informada) || (int) $informada != $informada || (int) $informada < 1) {
            return null;
        }

        return (int) $informada;
    }

    private function imprimirCabecalho(bool $simulacao, int $empresa): void
    {
        $this->info('Backfill de company_id nas tabelas de domínio (Plano 4, Etapa 2)');
        $this->line("Empresa alvo: #{$empresa}");
        $this->line($simulacao
            ? 'Modo simulação (--dry-run): nada será gravado.'
            : 'Modo aplicação: cada tabela é atualizada em lotes de ' . self::TAMANHO_DO_LOTE . ' e reconferida antes de seguir para a próxima. A gravação exige confirmação antes de começar.');
        $this->newLine();
    }

    /**
     * Levanta, sem gravar nada, o estado atual de cada tabela de domínio:
     * existe e tem a coluna (elegível), quantas linhas ao todo, quantas já
     * têm company_id e quantas estão nulas. Nenhuma tabela é omitida, nem a
     * que está com zero registros hoje.
     *
     * @return array<int, array{tabela: string, elegivel: bool, motivo_pulada: ?string, total_antes: int, ja_preenchidos_antes: int, nulos_antes: int, atualizados: int, total_depois: int, nulos_depois: int, situacao: string}>
     */
    private function levantarConferencia(): array
    {
        return array_map(function (string $tabela): array {
            if (! Schema::hasTable($tabela)) {
                return $this->linhaPulada($tabela, 'tabela não existe no banco conectado');
            }

            if (! Schema::hasColumn($tabela, DominioMultiempresa::COLUNA_TENANT)) {
                return $this->linhaPulada($tabela, 'tabela não tem a coluna ' . DominioMultiempresa::COLUNA_TENANT);
            }

            $total = DB::table($tabela)->count();
            $nulos = DB::table($tabela)->whereNull(DominioMultiempresa::COLUNA_TENANT)->count();

            return [
                'tabela' => $tabela,
                'elegivel' => true,
                'motivo_pulada' => null,
                'total_antes' => $total,
                'ja_preenchidos_antes' => $total - $nulos,
                'nulos_antes' => $nulos,
                'atualizados' => 0,
                'total_depois' => $total,
                'nulos_depois' => $nulos,
                'situacao' => $this->situacaoInicial($total, $nulos),
            ];
        }, DominioMultiempresa::TABELAS_DE_DOMINIO);
    }

    /**
     * @return array{tabela: string, elegivel: bool, motivo_pulada: ?string, total_antes: int, ja_preenchidos_antes: int, nulos_antes: int, atualizados: int, total_depois: int, nulos_depois: int, situacao: string}
     */
    private function linhaPulada(string $tabela, string $motivo): array
    {
        return [
            'tabela' => $tabela,
            'elegivel' => false,
            'motivo_pulada' => $motivo,
            'total_antes' => 0,
            'ja_preenchidos_antes' => 0,
            'nulos_antes' => 0,
            'atualizados' => 0,
            'total_depois' => 0,
            'nulos_depois' => 0,
            'situacao' => "pulada: {$motivo}",
        ];
    }

    private function situacaoInicial(int $total, int $nulos): string
    {
        if ($total === 0) {
            return 'vazia';
        }

        if ($nulos === 0) {
            return 'já preenchida';
        }

        return "{$nulos} linha(s) pendente(s)";
    }

    /**
     * @param  array<int, array{tabela: string, elegivel: bool, motivo_pulada: ?string, total_antes: int, ja_preenchidos_antes: int, nulos_antes: int, atualizados: int, total_depois: int, nulos_depois: int, situacao: string}>  $linhas
     */
    private function imprimirConferencia(array $linhas): void
    {
        $this->line('Conferência antes de qualquer alteração (uma linha por tabela de domínio, nenhuma omitida):');
        $this->table(
            ['Tabela', 'Total', 'Já têm company_id', 'Nulos', 'Situação'],
            array_map(fn (array $linha) => [
                $linha['tabela'],
                $linha['total_antes'],
                $linha['ja_preenchidos_antes'],
                $linha['nulos_antes'],
                $linha['situacao'],
            ], $linhas)
        );
    }

    /**
     * Atualiza tabela a tabela, na ordem do levantamento. Para na primeira
     * divergência de contagem, sem seguir para a próxima tabela: devolve o
     * erro e as linhas processadas até ali (inclusive as tabelas seguintes,
     * marcadas como não alcançadas, para o log registrar o quadro completo).
     *
     * @param  array<int, array{tabela: string, elegivel: bool, motivo_pulada: ?string, total_antes: int, ja_preenchidos_antes: int, nulos_antes: int, atualizados: int, total_depois: int, nulos_depois: int, situacao: string}>  $levantamento
     * @return array{linhas: array<int, array{tabela: string, elegivel: bool, motivo_pulada: ?string, total_antes: int, ja_preenchidos_antes: int, nulos_antes: int, atualizados: int, total_depois: int, nulos_depois: int, situacao: string}>, erro: ?string}
     */
    private function aplicarBackfill(array $levantamento, int $empresa): array
    {
        $linhas = [];

        foreach ($levantamento as $indice => $linha) {
            if (! $linha['elegivel']) {
                $this->warn("  {$linha['tabela']}: pulada ({$linha['motivo_pulada']}).");
                $linhas[] = $linha;

                continue;
            }

            if ($linha['nulos_antes'] === 0) {
                $this->line('  ' . $linha['tabela'] . ': ' . ($linha['total_antes'] === 0
                    ? 'vazia, nada a fazer.'
                    : 'já estava com company_id preenchido em todas as linhas.'));
                $linhas[] = $linha;

                continue;
            }

            $atualizados = $this->atualizarEmLotes($linha['tabela'], $empresa);

            $totalDepois = DB::table($linha['tabela'])->count();
            $nulosDepois = DB::table($linha['tabela'])->whereNull(DominioMultiempresa::COLUNA_TENANT)->count();

            if ($totalDepois !== $linha['total_antes'] || $nulosDepois !== 0) {
                $linhas[] = array_merge($linha, [
                    'atualizados' => $atualizados,
                    'total_depois' => $totalDepois,
                    'nulos_depois' => $nulosDepois,
                    'situacao' => 'ERRO: divergência de contagem',
                ]);

                foreach (array_slice($levantamento, $indice + 1) as $naoAlcancada) {
                    $linhas[] = array_merge($naoAlcancada, [
                        'situacao' => 'não alcançada: execução interrompida antes desta tabela',
                    ]);
                }

                return [
                    'linhas' => $linhas,
                    'erro' => sprintf(
                        'Divergência de contagem em "%s": total antes = %d, total depois = %d, linha(s) ainda nula(s) = %d. '
                        . 'Execução interrompida: nenhuma tabela depois de "%s" foi tocada.',
                        $linha['tabela'],
                        $linha['total_antes'],
                        $totalDepois,
                        $nulosDepois,
                        $linha['tabela']
                    ),
                ];
            }

            $linhas[] = array_merge($linha, [
                'atualizados' => $atualizados,
                'total_depois' => $totalDepois,
                'nulos_depois' => $nulosDepois,
                'situacao' => 'ok: preenchida',
            ]);

            $this->line("  {$linha['tabela']}: {$atualizados} linha(s) atualizada(s).");
        }

        return ['linhas' => $linhas, 'erro' => null];
    }

    /**
     * Preenche `company_id` em lotes de `TAMANHO_DO_LOTE`, sempre nas linhas
     * ainda nulas (`whereNull`), até não sobrar nenhuma. `DB::table()` puro:
     * sem Eloquent, sem evento de model, sem escopo.
     */
    private function atualizarEmLotes(string $tabela, int $empresa): int
    {
        $totalAtualizado = 0;

        do {
            $atualizadosNoLote = DB::table($tabela)
                ->whereNull(DominioMultiempresa::COLUNA_TENANT)
                ->limit(self::TAMANHO_DO_LOTE)
                ->update([DominioMultiempresa::COLUNA_TENANT => $empresa]);

            $totalAtualizado += $atualizadosNoLote;
        } while ($atualizadosNoLote >= self::TAMANHO_DO_LOTE);

        return $totalAtualizado;
    }

    /**
     * @param  array<int, array{tabela: string, elegivel: bool, motivo_pulada: ?string, total_antes: int, ja_preenchidos_antes: int, nulos_antes: int, atualizados: int, total_depois: int, nulos_depois: int, situacao: string}>  $linhas
     */
    private function imprimirRelatorioFinal(array $linhas): void
    {
        $this->line('Relatório final:');
        $this->table(
            ['Tabela', 'Registros alterados', 'Total antes', 'Total depois', 'Situação'],
            array_map(fn (array $linha) => [
                $linha['tabela'],
                $linha['atualizados'],
                $linha['total_antes'],
                $linha['total_depois'],
                $linha['situacao'],
            ], $linhas)
        );

        $this->newLine();
        $this->info('Total geral de linhas atualizadas: ' . array_sum(array_column($linhas, 'atualizados')) . '.');
    }

    /**
     * Grava em storage/logs/backfill-company-id-<timestamp>.log o mesmo
     * conteúdo impresso no terminal. Escreve em toda saída do comando,
     * inclusive --dry-run, cancelamento e interrupção por divergência,
     * porque o operador precisa do registro do que foi visto e do que
     * aconteceu antes de decidir o próximo passo.
     *
     * @param  array<int, array{tabela: string, elegivel: bool, motivo_pulada: ?string, total_antes: int, ja_preenchidos_antes: int, nulos_antes: int, atualizados: int, total_depois: int, nulos_depois: int, situacao: string}>  $linhas
     */
    private function gravarLog(int $empresa, bool $simulacao, array $linhas, string $resultadoGeral): string
    {
        $agora = BusinessDate::agora();
        $conteudo = [];

        $conteudo[] = 'Backfill de company_id nas tabelas de domínio (multiempresa:backfill)';
        $conteudo[] = 'Executado em: ' . $agora->format('d/m/Y H:i:s') . ' (' . BusinessDate::fuso() . ')';
        $conteudo[] = 'Empresa alvo (company_id): ' . $empresa;
        $conteudo[] = 'Modo: ' . ($simulacao ? 'simulação (--dry-run)' : 'aplicação');
        $conteudo[] = 'Resultado: ' . $resultadoGeral;
        $conteudo[] = '';
        $conteudo[] = sprintf('%-30s %10s %10s %12s %10s  %s', 'Tabela', 'Tot.antes', 'Tot.depois', 'Alteradas', 'Nulos dep.', 'Situação');

        foreach ($linhas as $linha) {
            $conteudo[] = sprintf(
                '%-30s %10d %10d %12d %10d  %s',
                $linha['tabela'],
                $linha['total_antes'],
                $linha['total_depois'],
                $linha['atualizados'],
                $linha['nulos_depois'],
                $linha['situacao']
            );
        }

        $conteudo[] = '';
        $conteudo[] = 'Total geral de linhas atualizadas: ' . array_sum(array_column($linhas, 'atualizados')) . '.';

        $caminho = storage_path('logs/backfill-company-id-' . $agora->format('Y-m-d_His') . '.log');
        File::put($caminho, implode(PHP_EOL, $conteudo) . PHP_EOL);

        $this->newLine();
        $this->line("Relatório gravado em: {$caminho}");

        return $caminho;
    }
}
