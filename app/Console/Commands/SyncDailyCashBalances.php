<?php

namespace App\Console\Commands;

use App\Models\DailyCashBalance;
use App\Models\FinancialEntry;
use Illuminate\Console\Command;
use Carbon\Carbon;

class SyncDailyCashBalances extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cash:sync-daily-balances {--from=} {--to=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sincroniza os saldos diários de caixa com base nas entradas financeiras';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $fromDate = $this->option('from') ? Carbon::parse($this->option('from')) : Carbon::now()->subMonth();
        $toDate = $this->option('to') ? Carbon::parse($this->option('to')) : Carbon::now();

        $this->info("Sincronizando saldos diários de {$fromDate->format('d/m/Y')} até {$toDate->format('d/m/Y')}");

        $currentDate = $fromDate->copy();
        $processedDays = 0;
        $errors = 0;

        while ($currentDate->lte($toDate)) {
            try {
                $this->syncDayBalance($currentDate);
                $processedDays++;

                if ($processedDays % 10 == 0) {
                    $this->info("Processados {$processedDays} dias...");
                }
            } catch (\Exception $e) {
                $this->error("Erro ao processar {$currentDate->format('d/m/Y')}: " . $e->getMessage());
                $errors++;
            }

            $currentDate->addDay();
        }

        $this->info("Sincronização concluída!");
        $this->info("Dias processados: {$processedDays}");
        if ($errors > 0) {
            $this->warn("Erros encontrados: {$errors}");
        }
    }

    private function syncDayBalance(Carbon $date): void
    {
        $dateString = $date->toDateString();

        // Obter ou criar saldo do dia
        $balance = DailyCashBalance::firstOrCreate(
            ['balance_date' => $dateString],
            [
                'opening_balance' => $this->getPreviousDayClosingBalance($date),
                'total_entries' => 0,
                'total_withdrawals' => 0,
                'closing_balance' => $this->getPreviousDayClosingBalance($date),
                'entries_count' => 0,
                'withdrawals_count' => 0,
                'is_closed' => false,
            ]
        );

        // Calcular entradas do dia
        $entries = FinancialEntry::confirmed()
            ->whereIn('type', ['payment', 'manual'])
            ->where('entry_date', $dateString)
            ->get();

        $totalEntries = $entries->sum('amount');
        $entriesCount = $entries->count();

        // Calcular saídas do dia
        $withdrawals = FinancialEntry::confirmed()
            ->where('type', 'withdrawal')
            ->where('entry_date', $dateString)
            ->get();

        $totalWithdrawals = $withdrawals->sum('amount');
        $withdrawalsCount = $withdrawals->count();

        // Atualizar saldo
        $balance->update([
            'total_entries' => $totalEntries,
            'total_withdrawals' => $totalWithdrawals,
            'entries_count' => $entriesCount,
            'withdrawals_count' => $withdrawalsCount,
            'closing_balance' => $balance->opening_balance + $totalEntries - $totalWithdrawals,
        ]);
    }

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

    private function calculateBalanceFromBeginning(string $untilDate): float
    {
        $totalEntries = FinancialEntry::confirmed()
            ->whereIn('type', ['payment', 'manual'])
            ->where('entry_date', '<=', $untilDate)
            ->sum('amount');

        $totalWithdrawals = FinancialEntry::confirmed()
            ->where('type', 'withdrawal')
            ->where('entry_date', '<=', $untilDate)
            ->sum('amount');

        return $totalEntries - $totalWithdrawals;
    }
}
