<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'work_order_id',
        'total_cost',
        'discount_amount',
        'final_amount',
        'payment_due_date',
        'payment_date',
        'payment_method',
        'amount_paid',
        'payment_notes',
        'is_partial_payment',
        'payment_status',
        'active',
    ];

    protected $casts = [
        'total_cost' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'payment_due_date' => 'date',
        'payment_date' => 'date',
        'amount_paid' => 'decimal:2',
        'is_partial_payment' => 'boolean',
        'payment_status' => 'string',
        'active' => 'boolean',
    ];

    protected $appends = [
        'payment_method_text',
        'payment_method_color',
        'payment_status_text',
        'payment_status_color',
    ];

    /**
     * Get the work order that owns the payment detail.
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Get the payment method as a readable string.
     */
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

    /**
     * Get the payment method color for badges.
     */
    public function getPaymentMethodColorAttribute(): string
    {
        return match($this->payment_method) {
            'pix' => 'green',
            'credit_card' => 'blue',
            'debit_card' => 'purple',
            'boleto' => 'orange',
            'cash' => 'gray',
            'bank_transfer' => 'indigo',
            default => 'gray'
        };
    }

    /**
     * Get the payment status based on dates and amounts.
     */
    public function getPaymentStatusAttribute(): string
    {
        // Se payment_status foi definido explicitamente no banco, use ele
        if (isset($this->attributes['payment_status']) && !empty($this->attributes['payment_status'])) {
            return $this->attributes['payment_status'];
        }

        // Caso contrário, calcule baseado nas regras antigas
        if (!$this->payment_date) {
            if ($this->payment_due_date && $this->payment_due_date < now()->toDateString()) {
                return 'overdue';
            }
            return 'pending';
        }

        return 'paid';
    }

    /**
     * Get the payment status as a readable string.
     */
    public function getPaymentStatusTextAttribute(): string
    {
        return match($this->payment_status) {
            'pending' => 'Pendente',
            'paid' => 'Pago',
            'partial' => 'Parcial',
            'overdue' => 'Vencido',
            default => 'N/A'
        };
    }

    /**
     * Get the payment status color.
     */
    public function getPaymentStatusColorAttribute(): string
    {
        return match($this->payment_status) {
            'pending' => 'yellow',
            'paid' => 'green',
            'partial' => 'blue',
            'overdue' => 'red',
            default => 'gray'
        };
    }

    /**
     * Scope for active payment details.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope for paid payments.
     */
    public function scopePaid($query)
    {
        return $query->whereNotNull('payment_date');
    }

    /**
     * Scope for pending payments.
     */
    public function scopePending($query)
    {
        return $query->whereNull('payment_date');
    }

    /**
     * Scope for overdue payments.
     */
    public function scopeOverdue($query)
    {
        return $query->whereNull('payment_date')
                    ->where('payment_due_date', '<', now()->toDateString());
    }
}
