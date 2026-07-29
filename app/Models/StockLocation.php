<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Onde o produto está (Plano 17): depósito da empresa, van do técnico ou
 * veículo.
 *
 * Existe porque estoque que só mora no depósito não fecha com a realidade: o
 * produto sai do depósito e fica com o técnico antes de ser aplicado, e a
 * transferência entre os dois é movimento de estoque como qualquer outro.
 *
 * `technician_id` só faz sentido quando o tipo é `tecnico`; a coerência entre
 * os dois é validada na camada de aplicação (Task 17.7), não aqui.
 */
class StockLocation extends Model
{
    use BelongsToCompany;

    /**
     * Tipos aceitos, iguais ao enum da coluna `tipo`.
     *
     * `veiculo` fica sem uso até o Plano 27 (frota).
     *
     * @var array<int, string>
     */
    public const TIPOS = ['deposito', 'tecnico', 'veiculo'];

    protected $fillable = [
        'nome',
        'tipo',
        'technician_id',
        'ativo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Técnico dono do local, quando o tipo é `tecnico`.
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /**
     * Razão de movimentos deste local.
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Saldo corrente por produto e lote neste local.
     */
    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    /**
     * Apenas locais ativos.
     */
    public function scopeAtivos($consulta)
    {
        return $consulta->where('ativo', true);
    }
}
