<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderAdequation extends Model
{
    use HasFactory;

    protected $fillable = [
        'work_order_id',
        'type',
        'description',
        'priority',
        'status',
        'deadline',
    ];

    protected $casts = [
        'deadline' => 'date',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function photos()
    {
        return $this->hasMany(WorkOrderPhoto::class, 'entity_id')
            ->where('entity_type', 'adequation')
            ->orderBy('sort_order');
    }
}
