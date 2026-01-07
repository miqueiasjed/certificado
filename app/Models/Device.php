<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'address_id',
        'label',
        'number',
        'bait_type_id',
        'default_location_note',
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

    public function baitType(): BelongsTo
    {
        return $this->belongsTo(BaitType::class);
    }

    public function deviceEvents(): HasMany
    {
        return $this->hasMany(DeviceEvent::class);
    }

    /**
     * Get the work order device events for this device.
     */
    public function workOrderEvents(): HasMany
    {
        return $this->hasMany(WorkOrderDeviceEvent::class);
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

    public function scopeByNumber($query, $number)
    {
        return $query->where('number', 'like', "%{$number}%");
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

    public function getFullLabelAttribute(): string
    {
        return "{$this->label} ({$this->number})";
    }
}
