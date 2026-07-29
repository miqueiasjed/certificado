<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkOrderAdequation extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = [
        'work_order_id',
        'type',
        'description',
        'priority',
        'status',
        'deadline',
        // Plano 13, Task 13.2: instante do celular em que o técnico registrou
        // a adequação, gravado pelo aplicativo via sincronização.
        'registrada_em',
        // Plano 13, Task 13.8: uuid gerado no aparelho, usado para ligar a
        // foto capturada em campo a esta adequação antes de ela ter um id no
        // servidor (ver AplicadorDeFoto::resolverEntityIdDeAdequacao()).
        'uuid',
    ];

    protected $casts = [
        // Prazo sugerido: um dia, sem hora relevante, nunca sofre conversão de fuso.
        'deadline' => 'date',
        // Instante do celular: gravado em UTC e convertido na exibição.
        'registrada_em' => 'datetime',
    ];

    public function workOrder()
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function photos()
    {
        return $this->hasMany(WorkOrderPhoto::class, 'entity_id')
            ->where('entity_type', 'adequation')
            ->orderBy('sort_order');
    }
}
