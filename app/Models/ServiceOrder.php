<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'technician_id',
        'order_number',
        'order_date',
        'service_type',
        'start_time',
        'end_time',
        'description',
        'observations',
        'special_instructions',
        'status',
    ];

    protected $casts = [
        'order_date' => 'date',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByServiceType($query, $serviceType)
    {
        return $query->where('service_type', $serviceType);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeOverdue($query)
    {
        return $query->where('order_date', '<', now())
                    ->whereNotIn('status', ['completed', 'cancelled']);
    }

    public function getStatusBadgeAttribute(): string
    {
        return match($this->status) {
            'pending' => 'Pendente',
            'in_progress' => 'Em Andamento',
            'completed' => 'Concluída',
            'cancelled' => 'Cancelada',
            default => 'Não definido'
        };
    }

    public function getServiceTypeBadgeAttribute(): string
    {
        return match($this->service_type) {
            'dedetizacao' => 'Dedetização',
            'desinsetizacao' => 'Desinsetização',
            'descupinizacao' => 'Descupinização',
            'desratizacao' => 'Desratização',
            'sanitizacao' => 'Sanitização',
            default => 'Não definido'
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            'pending' => 'yellow',
            'in_progress' => 'blue',
            'completed' => 'green',
            'cancelled' => 'red',
            default => 'gray'
        };
    }

    public function getServiceTypeColorAttribute(): string
    {
        return match($this->service_type) {
            'dedetizacao' => 'red',
            'desinsetizacao' => 'blue',
            'descupinizacao' => 'orange',
            'desratizacao' => 'purple',
            'sanitizacao' => 'green',
            default => 'gray'
        };
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->order_date && $this->order_date->isPast() &&
               !in_array($this->status, ['completed', 'cancelled']);
    }
}
