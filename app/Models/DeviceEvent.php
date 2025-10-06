<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'device_id',
        'work_order_id',
        'event_type',
        'bait_consumption_status',
        'bait_consumption_quantity',
        'cleaning_done',
        'cleaning_notes',
        'bait_change_type',
        'bait_change_lot',
        'bait_change_quantity',
        'technician_notes',
        'description',
        'observations',
        'event_date',
        'active',
    ];

    protected $casts = [
        'event_date' => 'datetime',
        'cleaning_done' => 'boolean',
        'active' => 'boolean',
        'bait_consumption_quantity' => 'decimal:2',
        'bait_change_quantity' => 'decimal:2',
    ];

    /**
     * Get the device that owns the event.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Get the work order that owns the event.
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Get the event type as a readable string.
     */
    public function getEventTypeTextAttribute(): string
    {
        return match($this->event_type) {
            'bait_consumption' => 'Consumo de Isca',
            'cleaning' => 'Limpeza',
            'bait_change' => 'Troca de Isca',
            'technician_notes' => 'Observações do Técnico',
            default => 'Desconhecido'
        };
    }

    /**
     * Get the bait consumption status as a readable string.
     */
    public function getBaitConsumptionStatusTextAttribute(): string
    {
        return match($this->bait_consumption_status) {
            'partial' => 'Parcial',
            'total' => 'Total',
            'none' => 'Não houve',
            'spoiled' => 'Estragada',
            'replacement' => 'Reposição',
            default => 'N/A'
        };
    }

    /**
     * Get the cleaning status as a readable string.
     */
    public function getCleaningStatusTextAttribute(): string
    {
        return $this->cleaning_done ? 'Realizada' : 'Não realizada';
    }

    /**
     * Scope for active events.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope for events by type.
     */
    public function scopeByType($query, $type)
    {
        return $query->where('event_type', $type);
    }

    /**
     * Scope for events by device.
     */
    public function scopeByDevice($query, $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    /**
     * Scope for events by work order.
     */
    public function scopeByWorkOrder($query, $workOrderId)
    {
        return $query->where('work_order_id', $workOrderId);
    }
}
