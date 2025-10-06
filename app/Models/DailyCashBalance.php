<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class DailyCashBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'balance_date',
        'opening_balance',
        'total_entries',
        'total_withdrawals',
        'closing_balance',
        'entries_count',
        'withdrawals_count',
        'notes',
        'is_closed',
        'closed_at',
        'closed_by'
    ];

    protected $casts = [
        'balance_date' => 'date',
        'opening_balance' => 'decimal:2',
        'total_entries' => 'decimal:2',
        'total_withdrawals' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'is_closed' => 'boolean',
        'closed_at' => 'datetime',
    ];

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Scope para buscar por data
     */
    public function scopeByDate($query, $date)
    {
        return $query->where('balance_date', $date);
    }

    /**
     * Scope para buscar por período
     */
    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('balance_date', [$startDate, $endDate]);
    }

    /**
     * Scope para dias fechados
     */
    public function scopeClosed($query)
    {
        return $query->where('is_closed', true);
    }

    /**
     * Scope para dias abertos
     */
    public function scopeOpen($query)
    {
        return $query->where('is_closed', false);
    }

    /**
     * Calcular saldo líquido do dia
     */
    public function getNetBalanceAttribute(): float
    {
        return $this->total_entries - $this->total_withdrawals;
    }

    /**
     * Obter ou criar saldo do dia atual
     */
    public static function getTodayBalance(): self
    {
        $today = now()->toDateString();

        return static::firstOrCreate(
            ['balance_date' => $today],
            [
                'opening_balance' => static::getYesterdayClosingBalance(),
                'total_entries' => 0,
                'total_withdrawals' => 0,
                'closing_balance' => static::getYesterdayClosingBalance(),
                'entries_count' => 0,
                'withdrawals_count' => 0,
                'is_closed' => false,
            ]
        );
    }

    /**
     * Obter saldo de fechamento do dia anterior
     */
    public static function getYesterdayClosingBalance($referenceDate = null): float
    {
        $yesterday = $referenceDate ?
            Carbon::parse($referenceDate)->subDay()->toDateString() :
            now()->subDay()->toDateString();

        $yesterdayBalance = static::byDate($yesterday)->first();

        if ($yesterdayBalance) {
            return $yesterdayBalance->closing_balance;
        }

        // Se não existe saldo do dia anterior, calcular desde o início
        return static::calculateBalanceFromBeginning($yesterday);
    }

    /**
     * Calcular saldo desde o início até uma data específica
     */
    public static function calculateBalanceFromBeginning($untilDate): float
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

    /**
     * Atualizar saldo do dia com base nas entradas financeiras
     */
    public function updateFromFinancialEntries(): void
    {
        $date = $this->balance_date->toDateString();

        // Calcular entradas do dia
        $entries = FinancialEntry::confirmed()
            ->whereIn('type', ['payment', 'manual'])
            ->where('entry_date', $date)
            ->get();

        $this->total_entries = $entries->sum('amount');
        $this->entries_count = $entries->count();

        // Calcular saídas do dia
        $withdrawals = FinancialEntry::confirmed()
            ->where('type', 'withdrawal')
            ->where('entry_date', $date)
            ->get();

        $this->total_withdrawals = $withdrawals->sum('amount');
        $this->withdrawals_count = $withdrawals->count();

        // Calcular saldo de fechamento
        $this->closing_balance = $this->opening_balance + $this->total_entries - $this->total_withdrawals;

        $this->save();
    }

    /**
     * Fechar o dia
     */
    public function closeDay($userId = null, $notes = null): void
    {
        $this->updateFromFinancialEntries();

        $this->update([
            'is_closed' => true,
            'closed_at' => now(),
            'closed_by' => $userId,
            'notes' => $notes,
        ]);

        // Criar saldo do próximo dia automaticamente
        static::getTomorrowBalance();
    }

    /**
     * Obter ou criar saldo do próximo dia
     */
    public static function getTomorrowBalance(): self
    {
        $tomorrow = now()->addDay()->toDateString();

        return static::firstOrCreate(
            ['balance_date' => $tomorrow],
            [
                'opening_balance' => static::getTodayBalance()->closing_balance,
                'total_entries' => 0,
                'total_withdrawals' => 0,
                'closing_balance' => static::getTodayBalance()->closing_balance,
                'entries_count' => 0,
                'withdrawals_count' => 0,
                'is_closed' => false,
            ]
        );
    }
}
