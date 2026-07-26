<?php

namespace App\Console\Commands;

use App\Models\PaymentDetail;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use Illuminate\Console\Command;

class UpdatePaymentStatuses extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:update-statuses
                            {--dry-run : Simula a execução e mostra as transições, sem gravar nada}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Atualiza o status de pagamento das parcelas e das ordens de serviço comparando o vencimento com o dia de hoje no fuso do negócio (America/Sao_Paulo). Parcela que vence hoje não fica vencida.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $simulacao = (bool) $this->option('dry-run');
        $detalhado = $this->getOutput()->isVerbose();
        $hoje = BusinessDate::hoje()->toDateString();

        $this->info('Atualizando status de pagamento...');
        $this->line('Dia de referência (' . BusinessDate::fuso() . '): ' . $hoje);
        $this->line($simulacao
            ? 'Modo simulação (--dry-run): nada será gravado.'
            : 'Modo aplicação: os status serão gravados.');

        $parcelas = $this->processarParcelas($hoje, $simulacao, $detalhado);
        $ordens = $this->processarOrdens($hoje, $simulacao, $detalhado);

        $this->imprimirResumo('Parcelas', $parcelas, $simulacao);
        $this->imprimirResumo('Ordens de serviço', $ordens, $simulacao);

        $this->info($simulacao
            ? 'Simulação concluída. Nada foi gravado.'
            : 'Status atualizados com sucesso!');

        return Command::SUCCESS;
    }

    /**
     * @return array{transicoes: array<string, int>, semAlteracao: int, total: int, valorEmAberto: int}
     */
    private function processarParcelas(string $hoje, bool $simulacao, bool $detalhado): array
    {
        $resumo = $this->resumoVazio();
        $resumo['valorEmAberto'] = 0;

        if ($detalhado) {
            $this->newLine();
            $this->line('Detalhe por parcela:');
        }

        PaymentDetail::query()
            ->with('workOrder')
            ->chunkById(200, function ($parcelas) use ($hoje, $simulacao, $detalhado, &$resumo) {
                foreach ($parcelas as $parcela) {
                    $ordem = $parcela->workOrder;

                    if (! $ordem) {
                        continue;
                    }

                    $statusAtual = $parcela->getRawOriginal('payment_status');
                    $novoStatus = $this->statusDaParcela($parcela, $ordem, $hoje);
                    $valorEmAberto = $this->ehValorEmAberto($parcela);

                    $this->registrar($resumo, $statusAtual, $novoStatus);

                    if ($valorEmAberto) {
                        $resumo['valorEmAberto']++;
                    }

                    if ($detalhado) {
                        $this->line(sprintf(
                            '  Parcela #%d: %s -> %s%s',
                            $parcela->id,
                            $this->rotulo($statusAtual),
                            $novoStatus,
                            $valorEmAberto ? ' (valor sem data de pagamento, mantida em aberto)' : ''
                        ));
                    }

                    if (! $simulacao && $statusAtual !== $novoStatus) {
                        $parcela->update(['payment_status' => $novoStatus]);
                    }
                }
            });

        return $resumo;
    }

    /**
     * @return array{transicoes: array<string, int>, semAlteracao: int, total: int}
     */
    private function processarOrdens(string $hoje, bool $simulacao, bool $detalhado): array
    {
        $resumo = $this->resumoVazio();

        if ($detalhado) {
            $this->newLine();
            $this->line('Detalhe por ordem de serviço:');
        }

        WorkOrder::query()
            ->chunkById(200, function ($ordens) use ($hoje, $simulacao, $detalhado, &$resumo) {
                foreach ($ordens as $ordem) {
                    $statusAtual = $ordem->getRawOriginal('payment_status');
                    $novoStatus = $this->statusDaOrdem($ordem, $hoje);

                    $this->registrar($resumo, $statusAtual, $novoStatus);

                    if ($detalhado) {
                        $this->line(sprintf(
                            '  Ordem #%d: %s -> %s',
                            $ordem->id,
                            $this->rotulo($statusAtual),
                            $novoStatus
                        ));
                    }

                    if (! $simulacao && $statusAtual !== $novoStatus) {
                        $ordem->update(['payment_status' => $novoStatus]);
                    }
                }
            });

        return $resumo;
    }

    /**
     * `payment_date` preenchida é o sinal de pagamento em todo o sistema:
     * `statusDaOrdem()` soma apenas parcelas com essa data, e os scopes
     * `scopePaid()` e `scopePending()` do model decidem pelo mesmo campo.
     * A rotina segue a mesma convenção.
     *
     * `amount_paid` sozinho não prova recebimento: a parcela do valor restante
     * nasce pendente com esse campo já preenchido e sem data de pagamento.
     * Classificá-la por valor declarava recebido dinheiro que não entrou.
     *
     * Sem pagamento confirmado, quem decide é o vencimento, nunca a data em que
     * a parcela foi paga: parcela em aberto tem `payment_date` nulo e olhar para
     * ela marcava a carteira inteira como vencida.
     *
     * A comparação é dia contra dia, no fuso do negócio, o mesmo corte de
     * `statusDaOrdem()`, de `PaymentDetail::scopeOverdue()` e de
     * `BusinessDate::estaVencido()`, usado pelo accessor do model:
     *
     * - vencimento anterior a hoje: vencida
     * - vencimento hoje ou no futuro: pendente, ainda está no prazo
     * - sem vencimento definido: pendente, não há atraso a afirmar
     */
    private function statusDaParcela(PaymentDetail $parcela, WorkOrder $ordem, string $hoje): string
    {
        $valorPago = (float) ($parcela->amount_paid ?? 0);
        $valorFinal = (float) ($ordem->final_amount ?? $ordem->total_cost ?? 0);
        $temPagamento = BusinessDate::diaDe($parcela->payment_date) !== null;

        if (! $temPagamento || $valorPago <= 0) {
            $vencimento = BusinessDate::diaDe($parcela->payment_due_date);

            return $vencimento !== null && $vencimento < $hoje ? 'overdue' : 'pending';
        }

        return $valorPago >= $valorFinal ? 'paid' : 'partial';
    }

    /**
     * Parcela com valor preenchido e sem data de pagamento: dinheiro a receber
     * que a regra antiga classificava como recebido. Contada no resumo para
     * medir o alcance desta correção.
     */
    private function ehValorEmAberto(PaymentDetail $parcela): bool
    {
        return (float) ($parcela->amount_paid ?? 0) > 0
            && BusinessDate::diaDe($parcela->payment_date) === null;
    }

    /**
     * A ordem só fica vencida quando tem parcela sem pagamento com vencimento
     * anterior a hoje. Vencimento igual a hoje ainda está no prazo.
     */
    private function statusDaOrdem(WorkOrder $ordem, string $hoje): string
    {
        $valorFinal = (float) ($ordem->final_amount ?? $ordem->total_cost ?? 0);

        if ($valorFinal <= 0) {
            return 'pending';
        }

        $totalPago = (float) $ordem->paymentDetails()
            ->whereNotNull('payment_date')
            ->sum('amount_paid');

        if ($totalPago >= $valorFinal) {
            return 'paid';
        }

        if ($totalPago > 0) {
            return 'partial';
        }

        $temParcelaVencida = $ordem->paymentDetails()
            ->whereNull('payment_date')
            ->where('payment_due_date', '<', $hoje)
            ->exists();

        return $temParcelaVencida ? 'overdue' : 'pending';
    }

    /**
     * @return array{transicoes: array<string, int>, semAlteracao: int, total: int}
     */
    private function resumoVazio(): array
    {
        return ['transicoes' => [], 'semAlteracao' => 0, 'total' => 0];
    }

    /**
     * @param  array{transicoes: array<string, int>, semAlteracao: int, total: int}  $resumo
     */
    private function registrar(array &$resumo, ?string $origem, string $destino): void
    {
        $resumo['total']++;

        if ($origem === $destino) {
            $resumo['semAlteracao']++;

            return;
        }

        $chave = $this->rotulo($origem) . ' -> ' . $destino;
        $resumo['transicoes'][$chave] = ($resumo['transicoes'][$chave] ?? 0) + 1;
    }

    private function rotulo(?string $status): string
    {
        return $status === null || $status === '' ? 'sem status' : $status;
    }

    /**
     * @param  array{transicoes: array<string, int>, semAlteracao: int, total: int, valorEmAberto?: int}  $resumo
     */
    private function imprimirResumo(string $titulo, array $resumo, bool $simulacao): void
    {
        $this->newLine();
        $this->line($simulacao
            ? "{$titulo}, transições que seriam aplicadas:"
            : "{$titulo}, transições aplicadas:");

        if ($resumo['transicoes'] === []) {
            $this->line('  nenhuma');
        }

        foreach ($resumo['transicoes'] as $rotulo => $total) {
            $this->line("  {$rotulo}: {$total}");
        }

        $this->line("  Sem alteração: {$resumo['semAlteracao']}");
        $this->line("  Total avaliado: {$resumo['total']}");

        if (array_key_exists('valorEmAberto', $resumo)) {
            $this->line("  Com valor e sem data de pagamento, mantidas em aberto: {$resumo['valorEmAberto']}");
        }
    }
}
