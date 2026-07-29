<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PestSighting extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'address_id',
        'work_order_id',
        'sighting_date',
        'pest_type',
        'severity_level',
        'location_description',
        'description',
        'observations',
        'environmental_conditions',
        'control_measures_applied',
        'technician_notes',
        'active',
        // Plano 13, Task 13.2: quantidade estimada da praga, produto aplicado
        // e instante do celular, gravados pelo aplicativo do técnico via
        // sincronização.
        'estimated_quantity',
        'applied_product',
        'applied_product_quantity',
        'registrada_em',
    ];

    protected $casts = [
        'sighting_date' => 'datetime',
        'active' => 'boolean',
        // Instante do celular: gravado em UTC e convertido na exibição.
        'registrada_em' => 'datetime',
        'estimated_quantity' => 'integer',
        'applied_product_quantity' => 'decimal:2',
    ];

    /**
     * Get the address where the pest was sighted.
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    /**
     * Get the work order related to this sighting.
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Get the pest sighting items.
     */
    public function items(): HasMany
    {
        return $this->hasMany(PestSightingItem::class);
    }

    /**
     * Get the pest type as a readable string.
     */
    public function getPestTypeTextAttribute(): string
    {
        return match ($this->pest_type) {
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
     * Get the severity level as a readable string.
     */
    public function getSeverityLevelTextAttribute(): string
    {
        return match ($this->severity_level) {
            'low' => 'Baixa',
            'medium' => 'Média',
            'high' => 'Alta',
            'critical' => 'Crítica',
            default => 'N/A'
        };
    }

    /**
     * Get the severity level color.
     */
    public function getSeverityLevelColorAttribute(): string
    {
        return match ($this->severity_level) {
            'low' => 'green',
            'medium' => 'yellow',
            'high' => 'orange',
            'critical' => 'red',
            default => 'gray'
        };
    }

    /**
     * Scope for active sightings.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope for sightings by pest type.
     */
    public function scopeByPestType($query, $type)
    {
        return $query->where('pest_type', $type);
    }

    /**
     * Scope for sightings by severity level.
     */
    public function scopeBySeverityLevel($query, $level)
    {
        return $query->where('severity_level', $level);
    }

    /**
     * Scope for sightings by address.
     */
    public function scopeByAddress($query, $addressId)
    {
        return $query->where('address_id', $addressId);
    }

    /**
     * Scope for sightings by work order.
     */
    public function scopeByWorkOrder($query, $workOrderId)
    {
        return $query->where('work_order_id', $workOrderId);
    }

    /**
     * Scope for recent sightings.
     */
    public function scopeRecent($query, $days = 30)
    {
        return $query->where('sighting_date', '>=', now()->subDays($days));
    }
}
