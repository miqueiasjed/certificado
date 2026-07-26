<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Support\BusinessDate;
use App\Support\PeriodicidadeDeContrato;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Backfill da periodicidade dos contratos, passo único de dado separado das
 * migrations (Task 9.1 já criou as colunas novas, nullable e sem leitor).
 *
 * `visit_frequency` nasceu `integer` (frequência em meses), virou `string(50)`
 * de texto livre em 2025_11_03_120800_update_contracts_table_for_new_requirements
 * e o formulário atual (Contracts/Create.vue e Edit.vue) só emite
 * weekly|biweekly|monthly. O levantamento da Task 9.1 aponta como domínio
 * provável em produção: essas três palavras em inglês, número puro remanescente
 * de quando a coluna era integer (Edit.vue tem função de compatibilidade que
 * assume "se for número, é mensal") e nulo ou vazio.
 *
 * Este comando mapeia cada valor distinto de `visit_frequency` para
 * [visit_frequency_valor, visit_frequency_unidade], usando o mapa
 * compartilhado `App\Support\PeriodicidadeDeContrato`. Valor não reconhecido
 * nunca é chutado: vira pendência para resolução manual na tela, porque
 * adivinhar a periodicidade gera visita na data errada para um cliente real
 * (Task 9.3 em diante lê estas colunas para calcular datas de visita).
 *
 * `visit_frequency` nunca é alterada ou removida aqui: ela é a rede de
 * segurança do backfill enquanto as colunas novas não têm nenhum leitor.
 *
 * O mesmo mapa é usado pela gravação do contrato (Task 9.7,
 * `ContractService`), para as duas rotinas nunca divergirem sobre o que uma
 * frequência significa. Ver a nota sobre "biweekly" no docblock de
 * `PeriodicidadeDeContrato::MAPA_LITERAL`.
 */
class BackfillPeriodicidade extends Command
{
    /**
     * Tipo de serviço que não precisa de periodicidade. Contrato pontual não
     * entra na lista de pendências mesmo com visit_frequency vazia.
     */
    private const TIPO_SEM_PERIODICIDADE = 'pontual';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'contratos:backfill-periodicidade
                            {--dry-run : Lista o mapeamento e as pendências, sem gravar nada}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Preenche visit_frequency_valor e visit_frequency_unidade a partir do texto livre em visit_frequency, sem alterar a coluna original.';

    public function handle(): int
    {
        $simulacao = (bool) $this->option('dry-run');

        $this->info('Backfill de periodicidade dos contratos');
        $this->line('Preenche visit_frequency_valor e visit_frequency_unidade a partir do texto livre em visit_frequency. A coluna original nunca é alterada.');
        $this->line($simulacao
            ? 'Modo simulação (--dry-run): nada será gravado.'
            : 'Modo aplicação: a gravação exige confirmação antes de começar.');
        $this->newLine();

        $totalAntes = Contract::query()->count();

        $candidatos = Contract::query()
            ->whereNull('visit_frequency_valor')
            ->orderBy('id')
            ->get();

        if ($candidatos->isEmpty()) {
            $this->info('Nenhum contrato pendente de conversão. Todos já têm valor e unidade preenchidos.');
            $this->gravarRelatorio($simulacao, collect(), collect(), collect(), 0, $totalAntes, $totalAntes);

            return Command::SUCCESS;
        }

        $censo = $this->montarCenso($candidatos);
        $this->imprimirCenso($censo);

        $classificacao = $this->classificarContratos($candidatos);

        $this->newLine();
        $this->imprimirPendencias($classificacao['pendentes']);

        $this->newLine();
        $this->imprimirResumoPlanejado($candidatos->count(), $classificacao);

        if ($simulacao) {
            $this->newLine();
            $this->info('Simulação concluída. Nada foi gravado.');
            $this->gravarRelatorio($simulacao, $censo, $classificacao['pendentes'], collect(), 0, $totalAntes, $totalAntes);

            return Command::SUCCESS;
        }

        if ($classificacao['convertiveis']->isEmpty()) {
            $this->newLine();
            $this->warn('Nenhum contrato pode ser convertido automaticamente. Nada foi gravado.');
            $this->gravarRelatorio($simulacao, $censo, $classificacao['pendentes'], collect(), 0, $totalAntes, $totalAntes);

            return Command::SUCCESS;
        }

        $this->newLine();

        if (! $this->confirm(sprintf(
            'Gravar a conversão em %d contrato(s)?',
            $classificacao['convertiveis']->count()
        ), false)) {
            $this->warn('Operação cancelada. Nada foi gravado.');
            $this->gravarRelatorio($simulacao, $censo, $classificacao['pendentes'], collect(), 0, $totalAntes, $totalAntes);

            return Command::SUCCESS;
        }

        $convertidos = $this->aplicarConversoes($classificacao['convertiveis']);
        $totalDepois = Contract::query()->count();

        $this->newLine();
        $this->imprimirResumoFinal($convertidos, $classificacao['pendentes']->count(), $totalAntes, $totalDepois);

        $this->gravarRelatorio($simulacao, $censo, $classificacao['pendentes'], $classificacao['convertiveis'], $convertidos, $totalAntes, $totalDepois);

        return Command::SUCCESS;
    }

    /**
     * Levanta, sem gravar nada, cada valor distinto de visit_frequency entre
     * os candidatos e para o que ele seria convertido (ou a pendência).
     *
     * @param  EloquentCollection<int, Contract>  $candidatos
     * @return Collection<int, array{valor_original: string, quantidade: int, resultado: string}>
     */
    private function montarCenso(EloquentCollection $candidatos): Collection
    {
        return $candidatos
            ->groupBy(fn (Contract $contrato) => $this->chaveDeExibicao($contrato->visit_frequency))
            ->map(function (EloquentCollection $grupo, string $chave) {
                $mapeado = $this->mapearValor($grupo->first()->visit_frequency);

                return [
                    'valor_original' => $chave,
                    'quantidade' => $grupo->count(),
                    'resultado' => $mapeado === null
                        ? 'PENDÊNCIA: não reconhecido'
                        : sprintf('%d %s', $mapeado['valor'], $mapeado['unidade']),
                ];
            })
            ->sortByDesc('quantidade')
            ->values();
    }

    /**
     * Separa os candidatos em convertíveis, pendentes (sem conversão
     * reconhecida) e isentos (contrato pontual, que não precisa de
     * periodicidade e não entra na lista de pendências).
     *
     * @param  EloquentCollection<int, Contract>  $candidatos
     * @return array{convertiveis: Collection<int, array{contrato: Contract, valor: int, unidade: string}>, pendentes: Collection<int, array{contrato: Contract, valor_original: string}>, isentos: int}
     */
    private function classificarContratos(EloquentCollection $candidatos): array
    {
        $convertiveis = collect();
        $pendentes = collect();
        $isentos = 0;

        foreach ($candidatos as $contrato) {
            if ($contrato->service_type === self::TIPO_SEM_PERIODICIDADE) {
                $isentos++;

                continue;
            }

            $mapeado = $this->mapearValor($contrato->visit_frequency);

            if ($mapeado === null) {
                $pendentes->push([
                    'contrato' => $contrato,
                    'valor_original' => $this->chaveDeExibicao($contrato->visit_frequency),
                ]);

                continue;
            }

            $convertiveis->push([
                'contrato' => $contrato,
                'valor' => $mapeado['valor'],
                'unidade' => $mapeado['unidade'],
            ]);
        }

        return compact('convertiveis', 'pendentes', 'isentos');
    }

    /**
     * Grava, contrato a contrato (nunca em massa), valor e unidade dos
     * convertíveis. visit_frequency não é tocada.
     *
     * @param  Collection<int, array{contrato: Contract, valor: int, unidade: string}>  $convertiveis
     */
    private function aplicarConversoes(Collection $convertiveis): int
    {
        $convertidos = 0;

        foreach ($convertiveis as $item) {
            $item['contrato']->update([
                'visit_frequency_valor' => $item['valor'],
                'visit_frequency_unidade' => $item['unidade'],
            ]);

            $convertidos++;
            $this->line(sprintf(
                '  Contrato #%d convertido: "%s" -> %d %s',
                $item['contrato']->id,
                $this->chaveDeExibicao($item['contrato']->visit_frequency),
                $item['valor'],
                $item['unidade']
            ));
        }

        return $convertidos;
    }

    /**
     * Mapeia o texto livre de visit_frequency para [valor, unidade].
     * Devolve null quando o valor está vazio, nulo ou não é reconhecido por
     * nenhuma das regras: nunca chuta.
     *
     * Delega inteiramente para `PeriodicidadeDeContrato::mapear`, a fonte
     * única do mapa (Task 9.7): este comando não guarda mais a própria cópia
     * das regras, para nunca divergir da gravação do contrato sobre o que
     * uma frequência significa.
     *
     * @return array{valor: int, unidade: string}|null
     */
    private function mapearValor(?string $bruto): ?array
    {
        return PeriodicidadeDeContrato::mapear($bruto);
    }

    /**
     * Chave de exibição do valor original, distinguindo nulo de string
     * vazia: os dois acontecem em produção e são causas diferentes.
     */
    private function chaveDeExibicao(?string $valor): string
    {
        if ($valor === null) {
            return '(nulo)';
        }

        if (trim($valor) === '') {
            return '(vazio)';
        }

        return $valor;
    }

    /**
     * @param  Collection<int, array{valor_original: string, quantidade: int, resultado: string}>  $censo
     */
    private function imprimirCenso(Collection $censo): void
    {
        $this->line('Valores distintos de visit_frequency encontrados (conferir contra a produção antes de rodar sem --dry-run):');
        $this->table(
            ['Valor original', 'Contratos', 'Conversão / pendência'],
            $censo->map(fn (array $linha) => [
                $linha['valor_original'],
                $linha['quantidade'],
                $linha['resultado'],
            ])->all()
        );
    }

    /**
     * @param  Collection<int, array{contrato: Contract, valor_original: string}>  $pendentes
     */
    private function imprimirPendencias(Collection $pendentes): void
    {
        $this->line('Contratos sem conversão reconhecida (pendência para resolução manual na tela):');

        if ($pendentes->isEmpty()) {
            $this->line('  nenhuma');

            return;
        }

        $this->table(
            ['ID', 'Tipo de serviço', 'Valor original'],
            $pendentes->map(fn (array $item) => [
                $item['contrato']->id,
                $item['contrato']->service_type ?? '(nulo)',
                $item['valor_original'],
            ])->all()
        );
    }

    /**
     * @param  array{convertiveis: Collection<int, array{contrato: Contract, valor: int, unidade: string}>, pendentes: Collection<int, array{contrato: Contract, valor_original: string}>, isentos: int}  $classificacao
     */
    private function imprimirResumoPlanejado(int $totalCandidatos, array $classificacao): void
    {
        $this->line('Resumo do que será feito:');
        $this->line("  Contratos pendentes de conversão: {$totalCandidatos}");
        $this->line('  Convertíveis automaticamente: ' . $classificacao['convertiveis']->count());
        $this->line('  Pendências (não reconhecido): ' . $classificacao['pendentes']->count());
        $this->line("  Isentos (service_type = pontual): {$classificacao['isentos']}");
    }

    private function imprimirResumoFinal(int $convertidos, int $pendencias, int $totalAntes, int $totalDepois): void
    {
        $this->info('Resumo final:');
        $this->line("  Contratos convertidos: {$convertidos}");
        $this->line("  Pendências restantes: {$pendencias}");
        $this->line("  Total de contratos antes: {$totalAntes}");
        $this->line("  Total de contratos depois: {$totalDepois}");
        $this->line($totalAntes === $totalDepois
            ? '  Contagem confere: nenhum contrato foi criado ou removido.'
            : '  ALERTA: a contagem de contratos mudou durante o backfill. Investigar antes de confiar no resultado.');
    }

    /**
     * Grava em storage/logs/backfill-periodicidade-<timestamp>.log o mesmo
     * conteúdo impresso no terminal, para conferência posterior e para
     * anexar à execução em produção. Escreve tanto em --dry-run quanto na
     * execução real, porque o operador precisa do registro do que viu antes
     * de decidir rodar de verdade.
     *
     * @param  Collection<int, array{valor_original: string, quantidade: int, resultado: string}>  $censo
     * @param  Collection<int, array{contrato: Contract, valor_original: string}>  $pendentes
     * @param  Collection<int, array{contrato: Contract, valor: int, unidade: string}>  $convertidos
     */
    private function gravarRelatorio(
        bool $simulacao,
        Collection $censo,
        Collection $pendentes,
        Collection $convertidos,
        int $totalConvertido,
        int $totalAntes,
        int $totalDepois
    ): void {
        $agora = BusinessDate::agora();
        $linhas = [];

        $linhas[] = 'Backfill de periodicidade dos contratos';
        $linhas[] = 'Executado em: ' . $agora->format('d/m/Y H:i:s') . ' (' . BusinessDate::fuso() . ')';
        $linhas[] = 'Modo: ' . ($simulacao ? 'simulação (--dry-run)' : 'aplicação');
        $linhas[] = '';

        $linhas[] = 'Valores distintos de visit_frequency:';
        if ($censo->isEmpty()) {
            $linhas[] = '  nenhum contrato pendente de conversão';
        } else {
            foreach ($censo as $linha) {
                $linhas[] = sprintf(
                    '  %s | %d contrato(s) | %s',
                    $linha['valor_original'],
                    $linha['quantidade'],
                    $linha['resultado']
                );
            }
        }
        $linhas[] = '';

        $linhas[] = 'Contratos convertidos nesta execução: ' . $totalConvertido;
        foreach ($convertidos as $item) {
            $linhas[] = sprintf(
                '  #%d -> %d %s',
                $item['contrato']->id,
                $item['valor'],
                $item['unidade']
            );
        }
        $linhas[] = '';

        $linhas[] = 'Pendências (sem conversão reconhecida): ' . $pendentes->count();
        foreach ($pendentes as $item) {
            $linhas[] = sprintf(
                '  #%d | service_type=%s | valor original: %s',
                $item['contrato']->id,
                $item['contrato']->service_type ?? '(nulo)',
                $item['valor_original']
            );
        }
        $linhas[] = '';

        $linhas[] = "Total de contratos antes: {$totalAntes}";
        $linhas[] = "Total de contratos depois: {$totalDepois}";

        $caminho = storage_path('logs/backfill-periodicidade-' . $agora->format('Y-m-d_His') . '.log');
        File::put($caminho, implode(PHP_EOL, $linhas) . PHP_EOL);

        $this->newLine();
        $this->line("Relatório gravado em: {$caminho}");
    }
}
