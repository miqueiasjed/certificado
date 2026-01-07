<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'address_id',
        'name',
        'notes',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    // Relacionamentos
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function serviceOrders(): BelongsToMany
    {
        return $this->belongsToMany(ServiceOrder::class, 'room_service_order')
                    ->withPivot('observation')
                    ->withTimestamps();
    }

    public function workOrders(): BelongsToMany
    {
        return $this->belongsToMany(WorkOrder::class, 'work_order_room')
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


    // Scopes
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeByAddress($query, $addressId)
    {
        return $query->where('address_id', $addressId);
    }

    // Accessors
    public function getActiveBadgeAttribute(): string
    {
        return $this->active ? 'Ativo' : 'Inativo';
    }

    public function getActiveColorAttribute(): string
    {
        return $this->active ? 'green' : 'red';
    }
}
