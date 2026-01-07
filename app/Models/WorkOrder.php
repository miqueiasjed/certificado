<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'address_id',
        'technician_id',
        'service_id',
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
    ];

    protected $casts = [
        'scheduled_date' => 'date',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'active' => 'boolean',
        'materials_used' => 'array',
        'total_cost' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'final_amount' => 'decimal:2',
        'payment_due_date' => 'date',
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
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'work_order_product', 'work_order_id', 'product_id')
            ->withPivot(['quantity', 'unit', 'observations'])
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
                        'pest_observation'
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


    /**
     * Get the order type as a readable string.
     */
    public function getOrderTypeTextAttribute(): string
    {
        return match($this->order_type) {
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
        return match($this->priority_level) {
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
        return match($this->priority_level) {
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
        return match($this->status) {
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
        return match($this->status) {
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
        return match($this->payment_status) {
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
        return match($this->payment_status) {
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
        if (!$this->start_time || !$this->end_time) {
            return 0;
        }

        return $this->start_time->diffInHours($this->end_time, true);
    }

    /**
     * Get the duration as a readable string.
     */
    public function getDurationTextAttribute(): string
    {
        $hours = $this->duration_hours;

        if ($hours < 1) {
            return 'Menos de 1 hora';
        }

        if ($hours === 1) {
            return '1 hora';
        }

        return number_format($hours, 1) . ' horas';
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
            now()->endOfWeek()
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
