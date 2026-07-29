<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use Auditavel, BelongsToCompany;

    protected $fillable = [
        'name',
        'active_ingredient_id',
        'chemical_group_id',
        'antidote_id',
        'organ_registration_id',
        'controla_estoque',
        'estoque_minimo',
        'unidade',
    ];

    protected $casts = [
        // Nasce false: o produto atual continua sendo ficha técnica, sem exigir
        // saldo. O tenant liga o controle produto por produto (Plano 17).
        'controla_estoque' => 'boolean',
        // Mesmas 4 casas das quantidades de estoque: fração de mililitro é o
        // dia a dia da aplicação. Nulo significa sem ponto de reposição
        // definido, diferente de zero.
        'estoque_minimo' => 'decimal:4',
    ];

    public function activeIngredient(): BelongsTo
    {
        return $this->BelongsTo(ActiveIngredient::class);
    }

    public function chemicalGroup(): BelongsTo
    {
        return $this->BelongsTo(ChemicalGroup::class);
    }

    public function antidote(): BelongsTo
    {
        return $this->BelongsTo(Antidote::class);
    }

    public function organRegistration(): BelongsTo
    {
        return $this->BelongsTo(OrganRegistration::class);
    }

    public function certificates(): BelongsToMany
    {
        return $this->belongsToMany(Certificate::class, 'certificate_product');
    }

    /**
     * Lotes comprados deste produto, com validade e custo (Plano 17).
     */
    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }

    /**
     * Saldo corrente por lote e local.
     */
    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    /**
     * Razão de movimentos deste produto.
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
