<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O razão do estoque (Plano 17): uma linha para cada alteração de saldo.
 *
 * Nenhuma linha é apagada ou alterada depois de gravada. Correção é movimento
 * de `ajuste` com motivo, e é isso que dá rastreabilidade perante
 * fiscalização.
 *
 * Duas convenções que valem para todo movimento:
 *
 * - **`quantidade` é sempre positiva.** O sinal aplicado ao saldo vem do
 *   `tipo`, nunca do sinal da coluna. Quantidade negativa em algumas linhas e
 *   positiva em outras gera erro de sinal invertido que ninguém encontra
 *   depois, porque o total continua parecendo plausível.
 * - **`saldo_apos` é o saldo do produto/lote naquele local logo depois deste
 *   movimento.** É o que permite auditar a sequência sem recalcular a série
 *   inteira.
 *
 * A escrita é exclusiva do `StockService` (Task 17.2), que grava movimento e
 * saldo na mesma transação. Movimentar por Eloquent direto deixa razão e saldo
 * divergentes, e divergência de estoque é impossível de reconciliar depois.
 *
 * `ocorrido_em` é instante, gravado em UTC e convertido só na exibição: duas
 * baixas do mesmo lote no mesmo dia precisam de ordem.
 *
 * A relação com `Inventory` fica para a Task 17.5, que cria o model e a chave
 * estrangeira de `inventory_id`.
 */
class StockMovement extends Model
{
    use BelongsToCompany;

    /**
     * Tipos aceitos, iguais ao enum da coluna `tipo`.
     *
     * @var array<int, string>
     */
    public const TIPOS = [
        'entrada',
        'saida',
        'transferencia_saida',
        'transferencia_entrada',
        'ajuste',
        'descarte',
    ];

    protected $fillable = [
        'product_id',
        'product_batch_id',
        'stock_location_id',
        'tipo',
        'quantidade',
        'saldo_apos',
        'work_order_id',
        'transferencia_id',
        'inventory_id',
        'motivo',
        'user_id',
        'ocorrido_em',
    ];

    protected $casts = [
        'quantidade' => 'decimal:4',
        'saldo_apos' => 'decimal:4',
        // Instante: gravado em UTC, convertido para o fuso do negócio só na
        // exibição, via BusinessDate.
        'ocorrido_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Produto movimentado.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Lote movimentado, quando o produto tem controle de lote.
     */
    public function productBatch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class);
    }

    /**
     * Local de onde saiu ou para onde entrou.
     */
    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    /**
     * OS que originou a baixa, quando o movimento veio de uma aplicação.
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Quem registrou o movimento.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
