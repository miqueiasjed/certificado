<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma parada dentro do roteiro do dia de um técnico (Plano 22), que
 * representa **uma OS ou um compromisso avulso, nunca os dois ao mesmo
 * tempo** (Plano 31).
 *
 * `work_order_id` e `compromisso_id` são mutuamente exclusivos: exatamente
 * um dos dois preenchido por parada. Essa exclusividade é garantida pelo
 * `RouteService` (Task 31.2), não por este Model nem por restrição de banco
 * (`database/migrations/2026_08_10_100002_add_compromisso_to_route_stops_table.php`
 * documenta o porquê de não haver `CHECK` de banco para isso).
 *
 * `distancia_anterior_km`, `duracao_anterior_min` e `chegada_estimada` só
 * existem depois que o roteiro passa pelo motor de otimização (task futura
 * do Plano 22): ficam nulos numa parada recém-adicionada, antes da primeira
 * otimização.
 *
 * Unique `[route_id, work_order_id]` e `[route_id, compromisso_id]` sem
 * `company_id`: `route_id` já pertence a uma empresa só (a mesma empresa do
 * roteiro), então compor com `company_id` não separaria tenant nenhum e só
 * enfraqueceria a restrição que impede a mesma OS ou o mesmo compromisso
 * entrar duas vezes no mesmo roteiro. Mesmo raciocínio já registrado em
 * `DominioMultiempresa::UNIQUES_GLOBAIS_MANTIDOS` para
 * `device_positions.floor_plan_id_device_id_unique` (Plano 21).
 */
class RouteStop extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'route_id',
        'work_order_id',
        'compromisso_id',
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
     * OS que esta parada representa, quando não é parada de compromisso.
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Compromisso avulso que esta parada representa, quando não é parada de
     * OS.
     */
    public function compromisso(): BelongsTo
    {
        return $this->belongsTo(Compromisso::class);
    }

    /**
     * Se esta parada representa um compromisso avulso em vez de uma OS.
     */
    public function eDeCompromisso(): bool
    {
        return $this->compromisso_id !== null;
    }
}
