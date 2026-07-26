<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Certificate extends Model
{
    use HasFactory, Auditavel;

    protected $fillable = [
        'client_id',
        'address_id',
        'work_order_id',
        'service_id',
        'execution_date',
        'warranty',
        'certificate_number',
        'status',
        'notes',
        'procedure_used',
    ];

    protected $casts = [
        // Dia sem hora relevante: execução e garantia, nunca sofrem conversão de fuso
        'execution_date' => 'date',
        'warranty' => 'date',
        // Instante
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'certificate_product')
            ->withPivot(['quantity', 'unit'])
            ->withTimestamps();
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



    /**
     * A garantia é um dia, não um instante. A comparação usa o dia de hoje no
     * fuso do negócio, igual à rotina certificates:update-status, para que a
     * tela não mostre "vencido" enquanto a rotina grava "active".
     */
    public function getIsExpiredAttribute(): bool
    {
        return $this->warranty
            && $this->status !== 'cancelled'
            && BusinessDate::estaVencido($this->warranty);
    }

    /**
     * Dias até a garantia, contados de dia para dia no fuso do negócio.
     * Positivo para garantia futura, 0 para a que vence hoje e negativo para a
     * que já venceu. Sem garantia, devolve 0.
     */
    public function getDaysUntilExpiryAttribute(): int
    {
        if (!$this->warranty) {
            return 0;
        }

        $garantia = BusinessDate::paraFusoNegocio($this->warranty);

        if ($garantia === null) {
            return 0;
        }

        return (int) round(BusinessDate::hoje()->diffInDays($garantia->startOfDay(), false));
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

        // Se a garantia venceu, é vencido. Vencer hoje ainda não é estar vencido.
        if (BusinessDate::estaVencido($this->warranty)) {
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
