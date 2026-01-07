<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderDeviceEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'work_order_id',
        'device_id',
        'event_type_id',
        'event_date',
        'event_description',
        'event_observations',
    ];

    protected $casts = [
        'event_date' => 'date',
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
}
