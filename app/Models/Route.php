<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Roteiro de um técnico em um dia (Plano 22).
 *
 * Nasce `planejada`, muda de situação conforme o técnico avança pelo dia, e
 * carrega a distância e a duração totais devolvidas pelo motor de otimização
 * (task futura do Plano 22) depois de otimizado. `stops()` é a lista das OS
 * que compõem o roteiro, na ordem em que vão ser visitadas.
 *
 * Unique `[company_id, technician_id, data]`: um técnico tem um roteiro por
 * dia. A unique já nasce composta com `company_id` nesta migration (Task
 * 22.1), diferente do padrão mais comum de compor unique de FK que já
 * pertence a uma empresa só; o raciocínio completo está no cabeçalho da
 * migration `create_routes_table`.
 *
 * ATENÇÃO — colisão de nome com `Illuminate\Support\Facades\Route`: todo
 * arquivo de rota (`routes/web.php`, `routes/api.php`) usa a facade
 * `Route::get(...)`/`Route::post(...)` etc. Um controller ou uma rota que
 * precisar dos dois ao mesmo tempo (por exemplo, a Task 22.5, que expõe
 * endpoints de roteiro) precisa de alias no import, por exemplo:
 *
 *     use App\Models\Route as RouteModel;
 *
 * Sem o alias, `use App\Models\Route;` num arquivo que também chama
 * `Route::get(...)` da facade quebra a resolução do nome (o `use` do model
 * vence e `Route::get(...)` deixa de apontar para a facade).
 */
class Route extends Model
{
    use BelongsToCompany;

    protected $table = 'routes';

    protected $fillable = [
        'technician_id',
        'data',
        'situacao',
        'distancia_total_km',
        'duracao_estimada_min',
        'otimizada_em',
        // Ordem manual (Task 22.5): ver o cabeçalho da migration
        // `add_ordenacao_manual_to_routes_table` para o raciocínio completo.
        // `RouteService` é quem grava estas três colunas (via `update()`,
        // dentro de transação), nunca um controller diretamente.
        'ordenacao_manual',
        'reordenada_por',
        'reordenada_em',
    ];

    protected $casts = [
        // Dia sem hora relevante: nunca sofre conversão de fuso.
        'data' => 'date',
        'distancia_total_km' => 'decimal:2',
        'duracao_estimada_min' => 'integer',
        // Instante da última otimização bem-sucedida: gravado em UTC e
        // convertido para o fuso do negócio só na exibição, via BusinessDate.
        'otimizada_em' => 'datetime',
        'ordenacao_manual' => 'boolean',
        'reordenada_em' => 'datetime',
    ];

    /**
     * Técnico dono deste roteiro.
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /**
     * Usuário que fez a última reordenação manual (Task 22.5), ou `null`
     * quando o roteiro nunca foi reordenado manualmente.
     */
    public function reordenadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reordenada_por');
    }

    /**
     * Paradas do roteiro, na ordem de visita (`ordem`).
     */
    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class)->orderBy('ordem');
    }
}
