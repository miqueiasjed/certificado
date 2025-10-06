<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Address extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'nickname',
        'street',
        'number',
        'district',
        'city',
        'state',
        'zip',
        'reference',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    // Relacionamentos
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function pestSightings(): HasMany
    {
        return $this->hasMany(PestSighting::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeByClient($query, $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function scopeByCity($query, $city)
    {
        return $query->where('city', 'like', "%{$city}%");
    }

    public function scopeByState($query, $state)
    {
        return $query->where('state', $state);
    }

    // Accessors
    public function getFullAddressAttribute(): string
    {
        return "{$this->street}, {$this->number} - {$this->district}, {$this->city}/{$this->state} - CEP: {$this->zip}";
    }

    public function getShortAddressAttribute(): string
    {
        return "{$this->street}, {$this->number} - {$this->district}";
    }
}
