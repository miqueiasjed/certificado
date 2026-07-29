<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WorkOrder extends Model
{
    use Auditavel, BelongsToCompany, HasFactory;

    protected $fillable = [
        'client_id',
        'address_id',
        'technician_id',
        'service_id',
        'contract_id',
        'origem',
        'visita_numero',
        'order_number',
        'priority_level',
        'scheduled_date',
        'start_time',
        'end_time',
        'status',
        'description',
        'observations',
        'materials_used',
        'total_cost',
        'discount_amount',
        'final_amount',
        'payment_due_date',
        'payment_status',
        'completion_notes',
        'active',
        'situacao_assinatura',
        'assinada_em',
        'recusa_motivo',
        'recusa_registrada_em',
    ];

    protected $casts = [
        // Dia sem hora relevante: nunca sofre conversão de fuso
        'scheduled_date' => 'date',
        'payment_due_date' => 'date',
        // Instante: gravado em UTC e convertido na exibição
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'active' => 'boolean',
        'materials_used' => 'array',
        'total_cost' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        // Instantes da assinatura: gravados em UTC, convertidos para o fuso
        // do negócio só na exibição, via BusinessDate.
        'assinada_em' => 'datetime',
        'recusa_registrada_em' => 'datetime',
    ];

    protected $appends = [
        'total_paid',
        'remaining_amount',
        'effective_amount',
        'order_type_text',
        'status_text',
        'priority_level_text',
        'payment_status_text',
        'payment_status_color',
        'duration_text',
    ];

    /**
     * Get the client that owns the work order.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Get the address where the work will be performed.
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    /**
     * Get the technician assigned to this work order.
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class, 'technician_id');
    }

    /**
     * Get the service for this work order.
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(Technician::class, 'work_order_technicians', 'work_order_id', 'technician_id')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    /**
     * Get the products used in this work order.
     *
     * `product_batch_id`, `custo_unitario_aplicado` e `quantidade_pendente`
     * entram no pivot a partir do Plano 17 (Task 17.4): são o lote de onde o
     * produto saiu, o custo de aquisição congelado no momento da baixa e o que
     * ficou sem baixa por falta de saldo. Quem escreve neles é
     * `WorkOrderStockService`, no fechamento da OS; ficam nulos em produto sem
     * `controla_estoque` e nas ordens anteriores ao controle de estoque.
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'work_order_product', 'work_order_id', 'product_id')
            ->withPivot([
                'quantity',
                'unit',
                'observations',
                'product_batch_id',
                'custo_unitario_aplicado',
                'quantidade_pendente',
            ])
            ->withTimestamps();
    }

    /**
     * Get the services performed in this work order.
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'work_order_services', 'work_order_id', 'service_id')
            ->withPivot('observations')
            ->withTimestamps();
    }

    /**
     * Get the service type for this work order.
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    /**
     * Get the device events for this work order.
     */
    public function deviceEvents(): HasMany
    {
        return $this->hasMany(DeviceEvent::class);
    }

    /**
     * Get the pest sightings for this work order.
     */
    public function pestSightings(): HasMany
    {
        return $this->hasMany(PestSighting::class);
    }

    /**
     * Get the payment details for this work order.
     */
    public function paymentDetails(): HasMany
    {
        return $this->hasMany(PaymentDetail::class);
    }

    /**
     * Get the rooms attended in this work order.
     */
    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'work_order_room')
            ->withPivot([
                'observation',
                'event_type_id',
                'event_date',
                'event_description',
                'event_observations',
                'pest_type',
                'pest_sighting_date',
                'pest_location',
                'pest_quantity',
                'pest_observation',
            ])
            ->withTimestamps();
    }

    /**
     * Get the devices for this work order.
     */
    public function devices(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'work_order_device', 'work_order_id', 'device_id')
            ->withPivot('observation')
            ->withTimestamps();
    }

    /**
     * Get the device events for this work order.
     */
    public function workOrderDeviceEvents(): HasMany
    {
        return $this->hasMany(WorkOrderDeviceEvent::class);
    }

    public function adequations(): HasMany
    {
        return $this->hasMany(WorkOrderAdequation::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(WorkOrderPhoto::class);
    }

    /**
     * Assinatura do cliente coletada em campo para esta OS (Plano 13).
     */
    public function signature(): HasOne
    {
        return $this->hasOne(WorkOrderSignature::class);
    }

    /**
     * A OS está travada para edição pelo caminho comum?
     *
     * Verdadeiro só quando a assinatura foi coletada (`situacao_assinatura ===
     * 'assinada'`). OS com assinatura recusada não entra aqui: recusa é
     * situação própria, o serviço foi prestado e o documento ainda precisa ser
     * fechado pelo escritório (ver `WorkOrderSignatureService::registrarRecusa`,
     * Task 13.3).
     */
    public function estaTravada(): bool
    {
        return $this->situacao_assinatura === 'assinada';
    }

    /**
     * A OS pode ser editada pelo caminho comum de atualização?
     *
     * É o oposto lógico de `estaTravada()`: falso só com assinatura coletada.
     * Decisão registrada aqui porque não é óbvia à primeira vista: OS com
     * assinatura **recusada** continua editável de propósito, mesmo já tendo
     * passado pela tentativa de coleta, porque a recusa não trava (a mesma
     * regra de `estaTravada()`) e o escritório ainda precisa fechar o
     * atendimento. Não existe nuance adicional além dessa: nenhum outro campo
     * (status, active, etc.) participa desta decisão, porque o travamento
     * desta task é especificamente o da assinatura.
     *
     * A liberação de edição de uma OS assinada existe, mas não é esta: é o
     * caminho de correção com justificativa
     * (`WorkOrderSignatureService::corrigirComJustificativa`, Task 13.3), que
     * grava na auditoria quem alterou, o antes, o depois e o motivo. Este
     * acessor reflete só o caminho comum de edição, não o de correção.
     */
    public function getPodeSerEditadaAttribute(): bool
    {
        return ! $this->estaTravada();
    }

    /**
     * Get the order type as a readable string.
     */
    public function getOrderTypeTextAttribute(): string
    {
        return match ($this->order_type) {
            'preventive' => 'Preventiva',
            'corrective' => 'Corretiva',
            'emergency' => 'Emergência',
            'inspection' => 'Inspeção',
            'maintenance' => 'Manutenção',
            'other' => 'Outros',
            default => 'Desconhecido'
        };
    }

    /**
     * Get the priority level as a readable string.
     */
    public function getPriorityLevelTextAttribute(): string
    {
        return match ($this->priority_level) {
            'low' => 'Baixa',
            'medium' => 'Média',
            'high' => 'Alta',
            'urgent' => 'Urgente',
            'emergency' => 'Emergência',
            default => 'N/A'
        };
    }

    /**
     * Get the priority level color.
     */
    public function getPriorityLevelColorAttribute(): string
    {
        return match ($this->priority_level) {
            'low' => 'green',
            'medium' => 'yellow',
            'high' => 'orange',
            'urgent' => 'red',
            'emergency' => 'purple',
            default => 'gray'
        };
    }

    /**
     * Get the status as a readable string.
     */
    public function getStatusTextAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'Pendente',
            'scheduled' => 'Agendada',
            'in_progress' => 'Em Andamento',
            'completed' => 'Concluída',
            'cancelled' => 'Cancelada',
            'on_hold' => 'Em Espera',
            default => 'Desconhecido'
        };
    }

    /**
     * Get the status color.
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'pending' => 'gray',
            'scheduled' => 'blue',
            'in_progress' => 'yellow',
            'completed' => 'green',
            'cancelled' => 'red',
            'on_hold' => 'orange',
            default => 'gray'
        };
    }

    /**
     * Get the payment status as a readable string.
     */
    public function getPaymentStatusTextAttribute(): string
    {
        return match ($this->payment_status) {
            'pending' => 'Pendente',
            'partial' => 'Parcial',
            'paid' => 'Pago',
            'overdue' => 'Vencido',
            'cancelled' => 'Cancelado',
            default => 'N/A'
        };
    }

    /**
     * Get the payment status color.
     */
    public function getPaymentStatusColorAttribute(): string
    {
        return match ($this->payment_status) {
            'pending' => 'yellow',
            'partial' => 'orange',
            'paid' => 'green',
            'overdue' => 'red',
            'cancelled' => 'gray',
            default => 'gray'
        };
    }

    /**
     * Get the duration in hours.
     */
    public function getDurationHoursAttribute(): float
    {
        if (! $this->start_time || ! $this->end_time) {
            return 0;
        }

        // Garantir que são objetos Carbon
        $start = \Carbon\Carbon::parse($this->start_time);
        $end = \Carbon\Carbon::parse($this->end_time);

        // Se start_time for maior que end_time, retornar 0
        if ($start->gt($end)) {
            return 0;
        }

        // Calcular diferença em minutos e converter para horas para maior precisão
        $minutes = $start->diffInMinutes($end, true);

        return round($minutes / 60, 2);
    }

    /**
     * Get the duration as a readable string.
     */
    public function getDurationTextAttribute(): string
    {
        if (! $this->start_time || ! $this->end_time) {
            return 'Não informado';
        }

        $hours = $this->duration_hours;

        if ($hours <= 0) {
            return 'Não informado';
        }

        if ($hours < 1) {
            // Se for menos de 1 hora, mostrar em minutos
            $start = \Carbon\Carbon::parse($this->start_time);
            $end = \Carbon\Carbon::parse($this->end_time);
            $minutes = $start->diffInMinutes($end, true);

            if ($minutes == 1) {
                return '1 minuto';
            }

            return $minutes.' minutos';
        }

        // Se for exatamente 1 hora
        if ($hours == 1) {
            return '1 hora';
        }

        // Se for mais de 1 hora, mostrar horas e minutos
        $start = \Carbon\Carbon::parse($this->start_time);
        $end = \Carbon\Carbon::parse($this->end_time);
        $totalMinutes = $start->diffInMinutes($end, true);

        $hoursInt = floor($totalMinutes / 60);
        $minutesInt = $totalMinutes % 60;

        if ($minutesInt == 0) {
            return $hoursInt.' horas';
        }

        if ($hoursInt == 1) {
            return '1 hora e '.$minutesInt.' minuto'.($minutesInt > 1 ? 's' : '');
        }

        return $hoursInt.' horas e '.$minutesInt.' minuto'.($minutesInt > 1 ? 's' : '');
    }

    // Accessors removidos - agora os campos total_cost, discount_amount e final_amount
    // vêm diretamente da tabela work_orders

    /**
     * Get the total amount paid from all payment details.
     */
    public function getTotalPaidAttribute(): float
    {
        // Se os paymentDetails já foram carregados, usar eles
        if ($this->relationLoaded('paymentDetails')) {
            return $this->paymentDetails->whereNotNull('payment_date')->sum('amount_paid');
        }

        // Caso contrário, fazer query otimizada
        return $this->paymentDetails()->whereNotNull('payment_date')->sum('amount_paid');
    }

    /**
     * Get the remaining amount to be paid.
     */
    public function getRemainingAmountAttribute(): float
    {
        $finalAmount = (float) ($this->final_amount ?? 0);
        $totalPaid = (float) $this->total_paid;

        return max(0, $finalAmount - $totalPaid);
    }

    /**
     * Get the effective amount (final amount or total cost if final amount is not set).
     */
    public function getEffectiveAmountAttribute(): float
    {
        return (float) ($this->final_amount ?? 0);
    }

    /**
     * Scope for active work orders.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope for work orders by status.
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope for work orders by priority.
     */
    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority_level', $priority);
    }

    /**
     * Scope for work orders by client.
     */
    public function scopeByClient($query, $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Scope for work orders by technician.
     */
    public function scopeByTechnician($query, $technicianId)
    {
        return $query->where('technician_id', $technicianId);
    }

    /**
     * Scope for work orders by address.
     */
    public function scopeByAddress($query, $addressId)
    {
        return $query->where('address_id', $addressId);
    }

    /**
     * Scope for pending work orders.
     */
    public function scopePending($query)
    {
        return $query->whereIn('status', ['pending', 'scheduled']);
    }

    /**
     * Scope for completed work orders.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope for overdue work orders.
     */
    public function scopeOverdue($query)
    {
        return $query->where('scheduled_date', '<', now())
            ->whereIn('status', ['pending', 'scheduled']);
    }

    /**
     * Scope for today's work orders.
     */
    public function scopeToday($query)
    {
        return $query->whereDate('scheduled_date', today());
    }

    /**
     * Scope for this week's work orders.
     */
    public function scopeThisWeek($query)
    {
        return $query->whereBetween('scheduled_date', [
            now()->startOfWeek(),
            now()->endOfWeek(),
        ]);
    }

    /**
     * Scope for this month's work orders.
     */
    public function scopeThisMonth($query)
    {
        return $query->whereMonth('scheduled_date', now()->month)
            ->whereYear('scheduled_date', now()->year);
    }
}
