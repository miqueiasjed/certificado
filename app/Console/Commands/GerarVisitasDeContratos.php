<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperaPorTenant;
use App\Models\Contract;
use App\Services\GeracaoDeVisitasService;
use App\Support\BusinessDate;
use App\Support\RotinasAgendadas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Rotina diária (Task 9.6) que mantém a janela de visitas dos contratos
 * periódicos sempre preenchida, chamando `GeracaoDeVisitasService::gerarDoPeriodo()`
 * (Task 9.4). A geração em si já é idempotente por [contract_id, scheduled_date];
 * este comando cuida do que a idempotência sozinha não cobre: duas execuções
 * ao mesmo tempo.
 */
class GerarVisitasDeContratos extends Command
{
    use OperaPorTenant;

    protected $signature = 'contratos:gerar-visitas
                            {--meses= : Meses à frente da janela de geração (padrão: config(contratos.meses_de_janela))}
                            {--dry-run : Calcula e imprime o que seria gerado, sem gravar nada}';

    protected $description = 'Gera as visitas previstas dos contratos periódicos vigentes dentro da janela configurada, sem duplicar visita já existente.';

    /**
     * Chave do lock atômico que impede duas execuções concorrentes deste
     * comando ao mesmo tempo.
     *
     * O `withoutOverlapping` do agendamento (aplicado em bootstrap/app.php a
     * partir de `RotinasAgendadas::DIARIAS`) só protege execução disparada
     * pelo scheduler contra outra execução do próprio scheduler. Ele não vê
     * uma chamada manual de `php artisan contratos:gerar-visitas` no
     * terminal, que é exatamente o cenário de maior risco aqui: o operador
     * rodando o comando à mão (por exemplo, para reprocessar um contrato)
     * enquanto a rotina das 01:00 está no meio da execução. Sem este lock,
     * as duas leem "não existe visita para esta data" antes de qualquer uma
     * gravar, e a visita é duplicada, porque a checagem de idempotência de
     * `GeracaoDeVisitasService` é uma consulta de existência, sem unique no
     * banco.
     */
    private const CHAVE_DO_LOCK = 'contratos:gerar-visitas:lock';

    /**
     * Sinaliza que ao menos um contrato, em alguma empresa desta execução,
     * terminou com erro. `paraCadaTenant()` só marca a execução como
     * `Command::FAILURE` quando o callback lança uma exceção; contrato com
     * erro não lança, o erro fica dentro do relatório de
     * `GeracaoDeVisitasService::gerarDoPeriodo()`. O código de saída final
     * precisa combinar os dois sinais.
     */
    private bool $algumContratoFalhou = false;

    public function __construct(private readonly GeracaoDeVisitasService $geracao)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $lock = Cache::lock(self::CHAVE_DO_LOCK, RotinasAgendadas::MINUTOS_DE_TRAVA * 60);

        if (! $lock->get()) {
            $this->warn(
                'Já existe uma execução de contratos:gerar-visitas em andamento (lock ativo). '
                . 'Encerrando sem processar nada, para não duplicar visita. Rode novamente em alguns minutos.'
            );

            // Sucesso, não falha: o comando fez exatamente o que devia fazer
            // ao encontrar outra execução em curso, que é não tocar em nada.
            return Command::SUCCESS;
        }

        try {
            return $this->gerar();
        } finally {
            $lock->release();
        }
    }

    private function gerar(): int
    {
        $meses = $this->resolverMeses();

        if ($meses === null) {
            return Command::INVALID;
        }

        $simulacao = (bool) $this->option('dry-run');
        $hoje = BusinessDate::hoje()->toDateString();

        $this->info('Gerando visitas dos contratos periódicos vigentes...');
        $this->line('Dia de referência (' . BusinessDate::fuso() . '): ' . $hoje);
        $this->line("Janela: {$meses} mês(es) à frente.");
        $this->line($simulacao
            ? 'Modo simulação (--dry-run): nada será gravado.'
            : 'Modo aplicação: as visitas serão gravadas.');

        $codigo = $this->paraCadaTenant(fn (): int => $this->gerarDaEmpresa($meses, $simulacao));

        return $codigo === Command::SUCCESS && $this->algumContratoFalhou ? Command::FAILURE : $codigo;
    }

    /**
     * Uma empresa, já dentro do tenant dela.
     *
     * `GeracaoDeVisitasService::gerarDoPeriodo()` monta e executa o
     * `Contract::query()` dentro dele mesmo, então chamada a partir daqui a
     * consulta já sai escopada pela empresa da volta corrente do laço, assim
     * que a trait `BelongsToCompany` entrar em vigor nos models.
     *
     * @return int quantidade de contratos processados
     */
    private function gerarDaEmpresa(int $meses, bool $simulacao): int
    {
        $relatorio = $simulacao
            ? $this->simular($meses)
            : $this->geracao->gerarDoPeriodo($meses);

        $pulados = $this->contarContratosSemPeriodicidade();

        $this->imprimirRelatorio($relatorio, $pulados, $simulacao);

        if (collect($relatorio)->contains(fn (array $item) => $item['erro'] !== null)) {
            $this->algumContratoFalhou = true;
        }

        return count($relatorio);
    }

    /**
     * Roda a mesma geração real, dentro de uma transação aberta por este
     * método e sempre revertida no final. É a mesma chamada, o mesmo cálculo
     * e a mesma checagem de idempotência da execução real: a contagem do
     * dry-run é garantida bater com a da execução real, porque não existe um
     * segundo caminho de código simulando o resultado.
     *
     * As transações internas de `gerarPara()` (uma por contrato) entram como
     * savepoints dentro desta, e o rollback aqui desfaz todas elas juntas.
     *
     * @return array<int, array{criadas: array<int, \App\Models\WorkOrder>, erro: ?string}>
     */
    private function simular(int $meses): array
    {
        DB::beginTransaction();

        try {
            return $this->geracao->gerarDoPeriodo($meses);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Lê `--meses`, com o padrão vindo de `config('contratos.meses_de_janela')`.
     * Imprime erro e devolve null quando o valor informado não é um inteiro
     * positivo.
     */
    private function resolverMeses(): ?int
    {
        $opcao = $this->option('meses');

        if ($opcao === null) {
            return (int) config('contratos.meses_de_janela', 3);
        }

        if (! ctype_digit((string) $opcao) || (int) $opcao < 1) {
            $this->error("--meses precisa ser um número inteiro maior que zero. Valor recebido: \"{$opcao}\".");

            return null;
        }

        return (int) $opcao;
    }

    /**
     * Contratos dentro da mesma janela de vigência que `gerarDoPeriodo()`
     * usa (`end_date` nulo ou não vencido), mas sem periodicidade
     * reconhecida (`visit_frequency_valor` ou `visit_frequency_unidade`
     * nulos). `GeracaoDeVisitasService` não devolve esses contratos no
     * relatório, porque o filtro deles já os exclui da consulta; sem esta
     * contagem à parte, o contrato pendente de periodicidade some em
     * silêncio em vez de aparecer como pulado.
     */
    private function contarContratosSemPeriodicidade(): int
    {
        return Contract::query()
            ->where(function ($consulta) {
                $consulta->whereNull('end_date')
                    ->orWhere('end_date', '>=', BusinessDate::hoje()->toDateString());
            })
            ->where(function ($consulta) {
                $consulta->whereNull('visit_frequency_valor')
                    ->orWhereNull('visit_frequency_unidade');
            })
            ->count();
    }

    /**
     * @param  array<int, array{criadas: array<int, \App\Models\WorkOrder>, erro: ?string}>  $relatorio
     */
    private function imprimirRelatorio(array $relatorio, int $pulados, bool $simulacao): void
    {
        $totalVisitas = 0;
        $comErro = [];

        foreach ($relatorio as $contractId => $item) {
            $totalVisitas += count($item['criadas']);

            if ($item['erro'] !== null) {
                $comErro[$contractId] = $item['erro'];
            }
        }

        $this->newLine();
        $this->line($simulacao ? 'Resultado da simulação:' : 'Resultado da geração:');
        $this->line('  Contratos processados: ' . count($relatorio));
        $this->line('  Visitas ' . ($simulacao ? 'que seriam criadas' : 'criadas') . ': ' . $totalVisitas);
        $this->line("  Contratos pulados (sem periodicidade reconhecida): {$pulados}");
        $this->line('  Contratos com erro: ' . count($comErro));

        if ($comErro !== []) {
            $this->newLine();
            $this->warn('Contratos com erro nesta execução:');

            foreach ($comErro as $contractId => $erro) {
                $this->line("  Contrato #{$contractId}: {$erro}");
            }
        }

        $this->newLine();
        $this->info($simulacao
            ? 'Simulação concluída. Nenhuma visita foi gravada.'
            : 'Geração concluída.');
    }
}
