<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
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

    public function getDevicesCountAttribute(): int
    {
        return $this->devices()->count();
    }

    public function getActiveDevicesCountAttribute(): int
    {
        return $this->devices()->where('active', true)->count();
    }
}
