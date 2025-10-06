<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PestSightingItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'pest_sighting_id',
        'pest_type',
        'quantity_observed',
        'size_description',
        'behavior_description',
        'damage_description',
        'specific_location',
        'evidence_photos',
        'active',
    ];

    protected $casts = [
        'quantity_observed' => 'integer',
        'active' => 'boolean',
        'evidence_photos' => 'array',
    ];

    /**
     * Get the pest sighting that owns this item.
     */
    public function pestSighting(): BelongsTo
    {
        return $this->belongsTo(PestSighting::class);
    }

    /**
     * Get the pest type as a readable string.
     */
    public function getPestTypeTextAttribute(): string
    {
        return match($this->pest_type) {
            'rats' => 'Ratos',
            'mice' => 'Camundongos',
            'cockroaches' => 'Baratas',
            'ants' => 'Formigas',
            'termites' => 'Cupins',
            'flies' => 'Moscas',
            'fleas' => 'Pulgas',
            'ticks' => 'Carrapatos',
            'scorpions' => 'Escorpiões',
            'spiders' => 'Aranhas',
            'bees' => 'Abelhas',
            'wasps' => 'Vespas',
            'other' => 'Outros',
            default => 'Desconhecido'
        };
    }

    /**
     * Get the quantity observed as a readable string.
     */
    public function getQuantityObservedTextAttribute(): string
    {
        if ($this->quantity_observed === 1) {
            return '1 unidade';
        }

        if ($this->quantity_observed <= 5) {
            return 'Poucas unidades';
        }

        if ($this->quantity_observed <= 20) {
            return 'Várias unidades';
        }

        return 'Muitas unidades';
    }

    /**
     * Get the size description as a readable string.
     */
    public function getSizeDescriptionTextAttribute(): string
    {
        return match($this->size_description) {
            'very_small' => 'Muito pequeno',
            'small' => 'Pequeno',
            'medium' => 'Médio',
            'large' => 'Grande',
            'very_large' => 'Muito grande',
            default => 'N/A'
        };
    }

    /**
     * Scope for active items.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope for items by pest type.
     */
    public function scopeByPestType($query, $type)
    {
        return $query->where('pest_type', $type);
    }

    /**
     * Scope for items by pest sighting.
     */
    public function scopeByPestSighting($query, $pestSightingId)
    {
        return $query->where('pest_sighting_id', $pestSightingId);
    }

    /**
     * Scope for items with photos.
     */
    public function scopeWithPhotos($query)
    {
        return $query->whereNotNull('evidence_photos');
    }

    /**
     * Scope for items by size.
     */
    public function scopeBySize($query, $size)
    {
        return $query->where('size_description', $size);
    }
}
