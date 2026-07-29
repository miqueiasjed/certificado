<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha de contagem de um `Inventory` (Plano 17, Task 17.5): a
 * combinação de produto e lote que tinha saldo no local quando a contagem foi
 * aberta.
 *
 * `saldo_sistema` é congelado na abertura e nunca recalculado: é a fotografia
 * do que o sistema achava que tinha. A comparação que decide o ajuste de
 * verdade acontece no fechamento, em `StockService::ajustar()`, contra o
 * saldo **atual** (com `lockForUpdate`), porque a operação do local continua
 * durante a contagem.
 *
 * `saldo_contado`, `diferenca` e `justificativa` nascem nulos. Um item sem
 * `saldo_contado` é um item ainda não contado, e é assim que
 * `InventoryService::finalizar()` recusa fechar inventário incompleto sem
 * precisar de uma coluna de situação própria aqui.
 */
class InventoryItem extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'inventory_id',
        'product_id',
        'product_batch_id',
        'saldo_sistema',
        'saldo_contado',
        'diferenca',
        'justificativa',
    ];

    protected $casts = [
        'saldo_sistema' => 'decimal:4',
        'saldo_contado' => 'decimal:4',
        'diferenca' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Inventário a que este item pertence.
     */
    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class);
    }

    /**
     * Produto contado.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Lote contado, quando o produto tem controle de lote.
     */
    public function productBatch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class);
    }

    /**
     * O item já recebeu uma contagem?
     */
    public function foiContado(): bool
    {
        return $this->saldo_contado !== null;
    }

    /**
     * A contagem registrada encontrou diferença em relação ao saldo
     * congelado na abertura?
     */
    public function temDiferenca(): bool
    {
        return $this->diferenca !== null && (float) $this->diferenca !== 0.0;
    }
}
