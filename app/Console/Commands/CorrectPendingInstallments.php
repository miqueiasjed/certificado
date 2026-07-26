<?php

namespace App\Console\Commands;

use App\Models\PaymentDetail;
use App\Services\PaymentDetailService;
use App\Support\BusinessDate;
use Illuminate\Console\Command;

/**
 * Backfill das parcelas gravadas com valor a receber na coluna de valor pago.
 *
 * `PaymentDetailController` criava a parcela do valor restante com o valor em
 * `amount_paid` e sem `payment_date`. A origem já está corrigida: este comando
 * acerta os registros que ficaram para trás.
 *
 * Só toca em valor. O `payment_status` continua sendo responsabilidade da
 * rotina `payments:update-statuses`.
 */
class CorrectPendingInstallments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:corrigir-parcelas-pendentes
                            {--dry-run : Lista os registros afetados e o que seria feito, sem gravar nada}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Corrige parcelas com valor registrado em amount_paid sem nenhum pagamento (payment_date nula), movendo o valor para final_amount e zerando amount_paid.';

    public function __construct(
        private readonly PaymentDetailService $paymentDetailService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $simulacao = (bool) $this->option('dry-run');

        $this->info('Parcelas com valor registrado como pago sem nenhum pagamento');
        $this->line('Critério: payment_date nula e amount_paid maior que zero.');
        $this->line($simulacao
            ? 'Modo simulação (--dry-run): nada será gravado.'
            : 'Modo aplicação: a gravação exige confirmação antes de começar.');
        $this->newLine();

        $levantamento = $this->levantarRegistros();

        if ($levantamento['total'] === 0) {
            $this->info('Nenhuma parcela afetada. Nada a corrigir.');

            return Command::SUCCESS;
        }

        $this->imprimirCorrigiveis($levantamento['corrigiveis']);
        $this->imprimirParaConferencia($levantamento['conferencia']);
        $this->imprimirResumo($levantamento);

        if ($simulacao) {
            $this->newLine();
            $this->info('Simulação concluída. Nada foi gravado.');

            return Command::SUCCESS;
        }

        if (count($levantamento['corrigiveis']) === 0) {
            $this->newLine();
            $this->warn('Nenhum registro pode ser corrigido automaticamente. Nada foi gravado.');

            return Command::SUCCESS;
        }

        $this->newLine();

        if (! $this->confirm(sprintf(
            'Gravar a correção em %d parcela(s)?',
            count($levantamento['corrigiveis'])
        ), false)) {
            $this->warn('Operação cancelada. Nada foi gravado.');

            return Command::SUCCESS;
        }

        $corrigidos = $this->aplicarCorrecoes();

        $this->newLine();
        $this->info(sprintf('%d parcela(s) corrigida(s).', $corrigidos));

        if (count($levantamento['conferencia']) > 0) {
            $this->warn(sprintf(
                '%d parcela(s) continuam como estavam e precisam de conferência manual.',
                count($levantamento['conferencia'])
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Varre os registros afetados sem gravar nada, separando o que dá para
     * corrigir do que precisa de conferência.
     *
     * @return array{corrigiveis: list<array<string, string>>, conferencia: list<array<string, string>>, total: int}
     */
    private function levantarRegistros(): array
    {
        $corrigiveis = [];
        $conferencia = [];
        $total = 0;

        $this->paymentDetailService
            ->consultaDeParcelasComValorSemPagamento()
            ->chunkById(200, function ($parcelas) use (&$corrigiveis, &$conferencia, &$total) {
                foreach ($parcelas as $parcela) {
                    $total++;
                    $avaliacao = $this->paymentDetailService->avaliarParcelaComValorSemPagamento($parcela);
                    $linha = $this->montarLinha($parcela, $avaliacao);

                    if ($avaliacao['acao'] === 'corrigir') {
                        $corrigiveis[] = $linha;

                        continue;
                    }

                    $conferencia[] = $linha;
                }
            });

        return [
            'corrigiveis' => $corrigiveis,
            'conferencia' => $conferencia,
            'total' => $total,
        ];
    }

    /**
     * Grava registro a registro. Nunca update em massa: cada parcela é avaliada
     * de novo antes de ser tocada.
     */
    private function aplicarCorrecoes(): int
    {
        $corrigidos = 0;

        $this->paymentDetailService
            ->consultaDeParcelasComValorSemPagamento()
            ->chunkById(200, function ($parcelas) use (&$corrigidos) {
                foreach ($parcelas as $parcela) {
                    if (! $this->paymentDetailService->corrigirParcelaComValorSemPagamento($parcela)) {
                        continue;
                    }

                    $corrigidos++;
                    $this->line(sprintf('  Parcela #%d corrigida.', $parcela->id));
                }
            });

        return $corrigidos;
    }

    /**
     * @param  array{acao: string, valor: float, valorFinalAtual: float|null, motivo: string}  $avaliacao
     * @return array<string, string>
     */
    private function montarLinha(PaymentDetail $parcela, array $avaliacao): array
    {
        return [
            'id' => (string) $parcela->id,
            'ordem' => '#' . $parcela->work_order_id,
            'vencimento' => $this->formatarDia($parcela->payment_due_date),
            'valor' => $this->emReais($avaliacao['valor']),
            'status' => $this->rotuloDeStatus($parcela),
            'final_amount' => $avaliacao['valorFinalAtual'] === null
                ? 'vazio'
                : $this->emReais($avaliacao['valorFinalAtual']),
            'acao' => $this->descreverAcao($avaliacao),
        ];
    }

    /**
     * @param  array{acao: string, valor: float, valorFinalAtual: float|null, motivo: string}  $avaliacao
     */
    private function descreverAcao(array $avaliacao): string
    {
        return match ($avaliacao['motivo']) {
            'final_amount_vazio' => sprintf(
                'gravar %s em final_amount e zerar amount_paid',
                $this->emReais($avaliacao['valor'])
            ),
            'final_amount_igual' => 'final_amount já tem o mesmo valor: apenas zerar amount_paid',
            default => sprintf(
                'pular: final_amount vale %s e amount_paid vale %s',
                $this->emReais((float) $avaliacao['valorFinalAtual']),
                $this->emReais($avaliacao['valor'])
            ),
        };
    }

    /**
     * @param  list<array<string, string>>  $linhas
     */
    private function imprimirCorrigiveis(array $linhas): void
    {
        if (count($linhas) === 0) {
            return;
        }

        $this->line('Parcelas que seriam corrigidas:');
        $this->table(
            ['ID', 'Ordem', 'Vencimento', 'Valor', 'Status atual', 'final_amount', 'O que faria'],
            $linhas
        );
    }

    /**
     * @param  list<array<string, string>>  $linhas
     */
    private function imprimirParaConferencia(array $linhas): void
    {
        if (count($linhas) === 0) {
            return;
        }

        $this->newLine();
        $this->warn('Parcelas que precisam de conferência manual:');
        $this->line('final_amount já tem valor diferente de amount_paid. Os dois campos');
        $this->line('discordam sobre quanto a parcela vale e não há como escolher sem');
        $this->line('conferir a ordem de serviço. Estes registros não são alterados.');
        $this->table(
            ['ID', 'Ordem', 'Vencimento', 'Valor', 'Status atual', 'final_amount', 'O que faria'],
            $linhas
        );
    }

    /**
     * @param  array{corrigiveis: list<array<string, string>>, conferencia: list<array<string, string>>, total: int}  $levantamento
     */
    private function imprimirResumo(array $levantamento): void
    {
        $this->newLine();
        $this->line('Resumo:');
        $this->line('  Parcelas afetadas encontradas: ' . $levantamento['total']);
        $this->line('  Corrigíveis automaticamente:   ' . count($levantamento['corrigiveis']));
        $this->line('  Precisam de conferência:       ' . count($levantamento['conferencia']));
    }

    private function rotuloDeStatus(PaymentDetail $parcela): string
    {
        $status = $parcela->getRawOriginal('payment_status');

        return $status === null || $status === '' ? 'não definido' : (string) $status;
    }

    private function formatarDia(mixed $valor): string
    {
        return BusinessDate::paraFusoNegocio($valor)?->format('d/m/Y') ?? 'sem vencimento';
    }

    private function emReais(float $valor): string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }
}
