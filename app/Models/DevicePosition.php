<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Posição de um dispositivo sobre uma versão específica de planta.
 *
 * `x` e `y` são fração de 0 a 1 da largura/altura da imagem, não pixel: a
 * mesma planta é exibida em telas de tamanhos diferentes e impressa em papel,
 * e pixel absoluto quebraria em todas menos uma.
 */
class DevicePosition extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'floor_plan_id',
        'device_id',
        'x',
        'y',
        'rotulo_visivel',
    ];

    protected $casts = [
        'x' => 'decimal:4',
        'y' => 'decimal:4',
        'rotulo_visivel' => 'boolean',
    ];

    public function floorPlan(): BelongsTo
    {
        return $this->belongsTo(FloorPlan::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
