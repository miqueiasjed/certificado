<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Certificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'address_id',
        'work_order_id',
        'execution_date',
        'warranty',
        'certificate_number',
        'status',
        'notes',
        'procedure_used',
    ];

    protected $casts = [
        'execution_date' => 'date',
        'warranty' => 'date',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }



    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'certificate_product');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'certificate_service');
    }

    public function procedures(): BelongsToMany
    {
        return $this->belongsToMany(Procedure::class, 'certificate_procedure');
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeActive($query)
    {
        return $this->scopeByStatus($query, 'active');
    }

    public function scopeExpired($query)
    {
        return $this->scopeByStatus($query, 'expired');
    }

    public function scopeCancelled($query)
    {
        return $this->scopeByStatus($query, 'cancelled');
    }



    public function getIsExpiredAttribute(): bool
    {
        return $this->warranty && $this->warranty->isPast() && $this->status !== 'cancelled';
    }

    public function getDaysUntilExpiryAttribute(): int
    {
        if (!$this->warranty) {
            return 0;
        }

        return now()->diffInDays($this->warranty, false);
    }

    public function getCalculatedStatusAttribute(): string
    {
        // Se foi cancelado, mantém o status
        if ($this->status === 'cancelled') {
            return 'cancelled';
        }

        // Se não tem data de garantia, é ativo
        if (!$this->warranty) {
            return 'active';
        }

        // Se a garantia venceu, é vencido
        if ($this->warranty->isPast()) {
            return 'expired';
        }

        // Se a garantia não venceu, é ativo
        return 'active';
    }

    public function getStatusBadgeAttribute(): string
    {
        $status = $this->getCalculatedStatusAttribute();
        return match($status) {
            'active' => 'Ativo',
            'expired' => 'Vencido',
            'cancelled' => 'Cancelado',
            default => 'Não definido'
        };
    }

    public function getStatusTextAttribute(): string
    {
        return $this->getStatusBadgeAttribute();
    }

    public function getStatusColorAttribute(): string
    {
        $status = $this->getCalculatedStatusAttribute();
        return match($status) {
            'active' => 'green',
            'expired' => 'red',
            'cancelled' => 'yellow',
            default => 'gray'
        };
    }
}
