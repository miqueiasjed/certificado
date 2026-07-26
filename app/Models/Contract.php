<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contract extends Model
{
    use Auditavel, BelongsToCompany;

    protected $fillable = [
        'address_id',
        'contract_number',
        'start_date',
        'end_date',
        'service_value',
        'service_type',
        'visit_frequency',
        'visit_frequency_valor',
        'visit_frequency_unidade',
        'visit_count',
        'pest_target',
        'payment_method',
        'payment_details',
        'additional_clause',
        'jurisdiction',
    ];

    protected $casts = [
        // Dia sem hora relevante: vigência do contrato, nunca sofre conversão de fuso
        'start_date' => 'date',
        'end_date' => 'date',
        // Instante
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'service_value' => 'decimal:2',
        'visit_frequency_valor' => 'integer',
    ];

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function generateContractNumber(): string
    {
        return 'CONT-' . str_pad($this->id, 6, '0', STR_PAD_LEFT) . '-' . date('Ymd');
    }

    /**
     * Converte a periodicidade (valor + unidade) para uma quantidade aproximada
     * de dias. Uso auxiliar (ex.: ordenação, exibição): o cálculo real das datas
     * de visita (Task 9.3) usa Carbon com a unidade explícita, nunca esta
     * aproximação, para não sofrer o desvio de meses com dias diferentes.
     */
    public function periodicidadeEmDias(): ?int
    {
        if (is_null($this->visit_frequency_valor) || is_null($this->visit_frequency_unidade)) {
            return null;
        }

        return match ($this->visit_frequency_unidade) {
            'dias' => $this->visit_frequency_valor,
            'semanas' => $this->visit_frequency_valor * 7,
            'meses' => $this->visit_frequency_valor * 30,
            default => null,
        };
    }
}
