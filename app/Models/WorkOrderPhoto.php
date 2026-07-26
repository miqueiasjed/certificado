<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderPhoto extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'work_order_id',
        'entity_type',
        'entity_id',
        'room_id',
        'path',
        'caption',
        'sort_order',
    ];

    protected $appends = ['url'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function getUrlAttribute(): string
    {
        return asset('storage/' . $this->path);
    }
}
