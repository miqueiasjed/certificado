<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contract extends Model
{
    protected $fillable = [
        'address_id',
        'contract_number',
        'start_date',
        'end_date',
        'service_value',
        'service_type',
        'visit_frequency',
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
    ];

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function generateContractNumber(): string
    {
        return 'CONT-' . str_pad($this->id, 6, '0', STR_PAD_LEFT) . '-' . date('Ymd');
    }
}
