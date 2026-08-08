<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma parada (uma OS) dentro do roteiro do dia de um técnico (Plano 22).
 *
 * `distancia_anterior_km`, `duracao_anterior_min` e `chegada_estimada` só
 * existem depois que o roteiro passa pelo motor de otimização (task futura
 * do Plano 22): ficam nulos numa parada recém-adicionada, antes da primeira
 * otimização.
 *
 * Unique `[route_id, work_order_id]` sem `company_id`: `route_id` já
 * pertence a uma empresa só (a mesma empresa do roteiro), então compor com
 * `company_id` não separaria tenant nenhum e só enfraqueceria a restrição
 * que impede a mesma OS entrar duas vezes no mesmo roteiro. Mesmo raciocínio
 * já registrado em `DominioMultiempresa::UNIQUES_GLOBAIS_MANTIDOS` para
 * `device_positions.floor_plan_id_device_id_unique` (Plano 21).
 */
class RouteStop extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'route_id',
        'work_order_id',
        'ordem',
        'distancia_anterior_km',
        'duracao_anterior_min',
        'chegada_estimada',
    ];

    protected $casts = [
        'ordem' => 'integer',
        'distancia_anterior_km' => 'decimal:2',
        'duracao_anterior_min' => 'integer',
    ];

    /**
     * Roteiro a que esta parada pertence.
     */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    /**
     * OS que esta parada representa.
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
