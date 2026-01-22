<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialEntry extends Model
{
    protected $fillable = [
        'source',
        'amount',
        'description',
        'entry_date',
        'payment_method',
        'reference_number',
        'notes',
        'status',
        'work_order_id',
        'payment_detail_id',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'entry_date' => 'date',
        'status' => 'string',
    ];

    protected $appends = [
        'source_text',
        'status_text',
        'status_color',
        'payment_method_text',
        'payment_method_color',
        'formatted_amount',
    ];

    // Relacionamentos
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function paymentDetail(): BelongsTo
    {
        return $this->belongsTo(PaymentDetail::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Accessors
    public function getSourceTextAttribute(): string
    {
        return match($this->source) {
            'work_order' => 'Ordem de Serviço',
            'payment_reopen' => 'Reabertura de Pagamento',
            'manual_withdrawal' => 'Manual',
            'manual' => 'Manual',
            default => 'N/A'
        };
    }

    public function getStatusTextAttribute(): string
    {
        return match($this->status) {
            'confirmed' => 'Confirmado',
            'pending' => 'Pendente',
            'cancelled' => 'Cancelado',
            default => 'N/A'
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'confirmed' => 'green',
            'pending' => 'yellow',
            'cancelled' => 'red',
            default => 'gray'
        };
    }

    public function getPaymentMethodTextAttribute(): string
    {
        return match($this->payment_method) {
            'pix' => 'PIX',
            'credit_card' => 'Cartão de Crédito',
            'debit_card' => 'Cartão de Débito',
            'boleto' => 'Boleto Bancário',
            'cash' => 'Dinheiro',
            'bank_transfer' => 'Transferência Bancária',
            default => 'Não informado'
        };
    }

    public function getPaymentMethodColorAttribute(): string
    {
        return match($this->payment_method) {
            'pix' => 'green',
            'credit_card' => 'blue',
            'debit_card' => 'blue',
            'boleto' => 'orange',
            'cash' => 'green',
            'bank_transfer' => 'blue',
            default => 'gray'
        };
    }

    public function getFormattedAmountAttribute(): string
    {
        return 'R$ ' . number_format($this->amount, 2, ',', '.');
    }

    // Scopes
    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeBySource($query, $source)
    {
        return $query->where('source', $source);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('entry_date', [$startDate, $endDate]);
    }

    /**
     * Boot method to handle model events
     */
    protected static function boot()
    {
        parent::boot();

        // Atualizar saldo diário quando uma entrada for criada
        static::created(function ($entry) {
            if ($entry->status === 'confirmed') {
                $entry->updateDailyBalance();
            }
        });

        // Atualizar saldo diário quando uma entrada for atualizada
        static::updated(function ($entry) {
            if ($entry->status === 'confirmed') {
                $entry->updateDailyBalance();
            }
        });

        // Atualizar saldo diário quando uma entrada for excluída
        static::deleted(function ($entry) {
            $entry->updateDailyBalance();
        });
    }

    /**
     * Atualizar saldo diário relacionado a esta entrada
     */
    public function updateDailyBalance(): void
    {
        try {
            $balance = \App\Models\DailyCashBalance::firstOrCreate(
                ['balance_date' => $this->entry_date],
                [
                    'opening_balance' => \App\Models\DailyCashBalance::getYesterdayClosingBalance($this->entry_date),
                    'total_entries' => 0,
                    'total_withdrawals' => 0,
                    'closing_balance' => \App\Models\DailyCashBalance::getYesterdayClosingBalance($this->entry_date),
                    'entries_count' => 0,
                    'withdrawals_count' => 0,
                    'is_closed' => false,
                ]
            );

            $balance->updateFromFinancialEntries();
        } catch (\Exception $e) {
            // Log do erro mas não interrompe o processo
            Log::error('Erro ao atualizar saldo diário: ' . $e->getMessage());
        }
    }
}
