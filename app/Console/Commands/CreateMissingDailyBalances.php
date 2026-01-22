<?php

namespace App\Console\Commands;

use App\Models\DailyCashBalance;
use App\Models\FinancialEntry;
use Illuminate\Console\Command;
use Carbon\Carbon;

class CreateMissingDailyBalances extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cash:create-missing-balances {--from=} {--to=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cria registros de saldo diário para dias sem movimento, mantendo a continuidade do saldo';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $fromDate = $this->option('from') ? Carbon::parse($this->option('from')) : Carbon::now()->subMonth();
        $toDate = $this->option('to') ? Carbon::parse($this->option('to')) : Carbon::now();

        $this->info("Criando saldos para dias sem movimento de {$fromDate->format('d/m/Y')} até {$toDate->format('d/m/Y')}");

        $currentDate = $fromDate->copy();
        $createdCount = 0;
        $updatedCount = 0;

        // Obter primeira data com movimento financeiro
        $firstMovementDate = FinancialEntry::confirmed()
            ->orderBy('entry_date')
            ->value('entry_date');

        if (!$firstMovementDate) {
            $this->warn('Nenhuma entrada financeira encontrada no sistema.');
            return;
        }

        // Começar da primeira data com movimento
        $currentDate = Carbon::parse($firstMovementDate);

        while ($currentDate->lte($toDate)) {
            $dateString = $currentDate->toDateString();

            // Verificar se já existe saldo para este dia
            $existingBalance = DailyCashBalance::byDate($dateString)->first();

            if (!$existingBalance) {
                // Criar saldo para o dia
                $openingBalance = $this->getPreviousDayClosingBalance($currentDate);

                DailyCashBalance::create([
                    'balance_date' => $dateString,
                    'opening_balance' => $openingBalance,
                    'total_entries' => 0,
                    'total_withdrawals' => 0,
                    'closing_balance' => $openingBalance, // Saldo permanece igual se não há movimento
                    'entries_count' => 0,
                    'withdrawals_count' => 0,
                    'is_closed' => false,
                ]);

                $createdCount++;
                $this->line("Criado saldo para {$currentDate->format('d/m/Y')}: R$ " . number_format($openingBalance, 2, ',', '.'));
            } else {
                // Atualizar saldo de abertura se necessário
                $correctOpeningBalance = $this->getPreviousDayClosingBalance($currentDate);

                if ($existingBalance->opening_balance != $correctOpeningBalance) {
                    $existingBalance->update([
                        'opening_balance' => $correctOpeningBalance,
                        'closing_balance' => $correctOpeningBalance + $existingBalance->total_entries - $existingBalance->total_withdrawals,
                    ]);

                    $updatedCount++;
                    $this->line("Atualizado saldo para {$currentDate->format('d/m/Y')}: R$ " . number_format($correctOpeningBalance, 2, ',', '.'));
                }
            }

            $currentDate->addDay();
        }

        $this->info("Processo concluído!");
        $this->info("Saldos criados: {$createdCount}");
        $this->info("Saldos atualizados: {$updatedCount}");
    }

    /**
     * Obter saldo de fechamento do dia anterior
     */
    private function getPreviousDayClosingBalance(Carbon $date): float
    {
        $previousDate = $date->copy()->subDay()->toDateString();

        $previousBalance = DailyCashBalance::byDate($previousDate)->first();

        if ($previousBalance) {
            return $previousBalance->closing_balance;
        }

        // Se não existe saldo do dia anterior, calcular desde o início
        return $this->calculateBalanceFromBeginning($previousDate);
    }

    /**
     * Calcular saldo desde o início até uma data específica
     */
    private function calculateBalanceFromBeginning(string $untilDate): float
    {
        $totalEntries = FinancialEntry::confirmed()
            ->whereIn('source', ['work_order', 'manual'])
            ->where('entry_date', '<=', $untilDate)
            ->sum('amount');

        $totalWithdrawals = FinancialEntry::confirmed()
            ->whereIn('source', ['payment_reopen', 'manual_withdrawal'])
            ->where('entry_date', '<=', $untilDate)
            ->sum('amount');

        return $totalEntries - $totalWithdrawals;
    }
}
