<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\BelongsToCompany;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentDetail extends Model
{
    use HasFactory, Auditavel, BelongsToCompany;

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
        // Dia sem hora relevante: vencimento e pagamento, nunca sofrem conversão de fuso
        'payment_due_date' => 'date',
        'payment_date' => 'date',
        // Instante
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'total_cost' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
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
     *
     * A coluna gravada tem prioridade. Sem ela, o valor é calculado com a mesma
     * regra de statusDaParcela() em UpdatePaymentStatuses, que é a fonte de
     * verdade: a tela e a rotina precisam dizer a mesma palavra sobre a mesma
     * parcela, senão o usuário vê "Pago" até a rodada noturna corrigir.
     */
    public function getPaymentStatusAttribute(): string
    {
        // Se payment_status foi definido explicitamente no banco, use ele
        if (isset($this->attributes['payment_status']) && !empty($this->attributes['payment_status'])) {
            return $this->attributes['payment_status'];
        }

        $valorPago = (float) ($this->amount_paid ?? 0);

        // `payment_date` preenchida é o sinal de pagamento em todo o sistema,
        // o mesmo critério dos scopes paid() e pending(). Valor sozinho não
        // prova recebimento: a parcela do restante nasce com `amount_paid`
        // preenchido e sem data de pagamento, e continua em aberto.
        if (BusinessDate::diaDe($this->payment_date) === null || $valorPago <= 0) {
            // O vencimento é um dia: só está vencido quando é anterior a hoje
            // no fuso do negócio. Parcela que vence hoje continua pendente.
            if ($this->payment_due_date && BusinessDate::estaVencido($this->payment_due_date)) {
                return 'overdue';
            }

            return 'pending';
        }

        // O total cobrado é o da ordem de serviço, não o da parcela: as colunas
        // de valor de payment_details foram migradas para work_orders e hoje
        // vivem lá. Parcela órfã a rotina nem avalia; sem ordem não há total a
        // comparar, e o recebimento registrado quita o que se conhece.
        $ordem = $this->workOrder;
        $valorFinal = (float) ($ordem?->final_amount ?? $ordem?->total_cost ?? 0);

        return $valorPago >= $valorFinal ? 'paid' : 'partial';
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
     *
     * Vencimento estritamente anterior ao dia de hoje no fuso do negócio, o
     * mesmo corte usado pela rotina payments:update-statuses.
     */
    public function scopeOverdue($query)
    {
        return $query->whereNull('payment_date')
                    ->where('payment_due_date', '<', BusinessDate::hoje()->toDateString());
    }
}
