<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderDeviceEvent extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'work_order_id',
        'device_id',
        'event_type_id',
        'event_date',
        'event_description',
        'event_observations',
        // Plano 13, Task 13.2: consumo de isca, praga encontrada e instante do
        // celular, gravados pelo aplicativo do técnico via sincronização.
        'bait_consumption_status',
        'pest_found',
        'registrada_em',
    ];

    protected $casts = [
        // Dia sem hora relevante: nunca sofre conversão de fuso.
        'event_date' => 'date',
        // Instante do celular: gravado em UTC e convertido na exibição.
        'registrada_em' => 'datetime',
    ];

    /**
     * Get the work order that owns this event.
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Get the device that owns this event.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Get the event type for this event.
     */
    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(WorkOrderPhoto::class, 'entity_id')
            ->where('entity_type', 'device_event')
            ->orderBy('sort_order');
    }
}
