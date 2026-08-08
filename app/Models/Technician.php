<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Technician extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'specialty',
        'registration_number',
        'is_active',
        'notes',
        'user_id',
        // Custo/hora (Plano 18, Task 18.6): base do custo de mão de obra na
        // margem por OS. Nullable: sem o valor, WorkOrderMarginService zera o
        // componente e marca a margem como parcial, em vez de inventar custo.
        'custo_hora',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'custo_hora' => 'decimal:2',
    ];

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Ficha de EPI do técnico (Plano 28, Task 28.1), da entrega mais recente
     * para a mais antiga.
     *
     * A ficha sobrevive ao técnico: o desligamento inativa o cadastro
     * (`is_active`), e as entregas continuam consultáveis e extraíveis, porque é
     * delas que sai a prova exigida pela NR-6.
     */
    public function ppeDeliveries(): HasMany
    {
        return $this->hasMany(PpeDelivery::class)
            ->orderByDesc('entregue_em')
            ->orderByDesc('id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBySpecialty($query, $specialty)
    {
        return $query->where('specialty', $specialty);
    }

    public function getFormattedPhoneAttribute(): string
    {
        return $this->phone;
    }

    public function getStatusBadgeAttribute(): string
    {
        return $this->is_active ? 'Ativo' : 'Inativo';
    }

    public function getStatusColorAttribute(): string
    {
        return $this->is_active ? 'green' : 'red';
    }
}
