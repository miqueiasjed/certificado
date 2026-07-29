<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo corrente de estoque (Plano 17): uma linha por produto, lote e local.
 *
 * Convive com o razão de `StockMovement` de propósito. Somar os movimentos a
 * cada consulta fica lento com o volume de um ano, e a consulta de
 * disponibilidade acontece o tempo todo, inclusive no aplicativo do técnico.
 *
 * As duas fontes precisam bater sempre. Quem garante isso é o `StockService`
 * (Task 17.2), gravando movimento e saldo na mesma transação, com
 * `lockForUpdate` nesta linha: duas baixas simultâneas do mesmo lote são caso
 * real e geram saldo errado sem o bloqueio. `recalcularSaldo()` reconstrói
 * esta tabela a partir do razão, e existe para conferência, nunca em rotina
 * automática: recálculo automático esconderia a divergência em vez de mostrá-la.
 *
 * Saldo negativo nunca é gravado.
 */
class StockBalance extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'product_id',
        'product_batch_id',
        'stock_location_id',
        'quantidade',
    ];

    protected $casts = [
        'quantidade' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Produto do saldo.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Lote do saldo, quando o produto tem controle de lote.
     */
    public function productBatch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class);
    }

    /**
     * Local onde este saldo está.
     */
    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }
}
