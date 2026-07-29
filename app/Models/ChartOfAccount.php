<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoria do plano de contas (Plano 18): classifica um título a receber ou
 * a pagar como receita ou despesa, com hierarquia opcional (ex.: "Despesas >
 * Aluguel").
 *
 * `codigo` é único por empresa, nunca globalmente: cada empresa tem o próprio
 * plano de contas, e o mesmo código pode existir em duas empresas diferentes.
 *
 * Apagar uma categoria com subcategoria vinculada é bloqueado no banco
 * (`restrictOnDelete` em `parent_id`); a saída para remover uma categoria do
 * uso corrente é desativar (`ativo = false`), não apagar.
 */
class ChartOfAccount extends Model
{
    use BelongsToCompany;

    /**
     * Tipos aceitos, iguais ao enum da coluna `tipo`.
     *
     * @var array<int, string>
     */
    public const TIPOS = ['receita', 'despesa'];

    protected $fillable = [
        'codigo',
        'nome',
        'tipo',
        'parent_id',
        'ativo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Categoria-mãe, quando esta é uma subcategoria.
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'parent_id');
    }

    /**
     * Subcategorias desta categoria.
     */
    public function children(): HasMany
    {
        return $this->hasMany(ChartOfAccount::class, 'parent_id');
    }

    /**
     * Títulos a receber classificados nesta categoria.
     */
    public function receivables(): HasMany
    {
        return $this->hasMany(Receivable::class);
    }

    /**
     * Títulos a pagar classificados nesta categoria.
     */
    public function payables(): HasMany
    {
        return $this->hasMany(Payable::class);
    }

    /**
     * Apenas categorias ativas.
     */
    public function scopeAtivas($consulta)
    {
        return $consulta->where('ativo', true);
    }
}
