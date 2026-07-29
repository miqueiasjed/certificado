<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lote comprado de um produto (Plano 17): validade, custo de aquisição e
 * origem fiscal.
 *
 * É o que permite responder qual lote foi aplicado em qual cliente, primeira
 * pergunta da fiscalização em caso de incidente, e o que sustenta o FEFO da
 * Task 17.3 (sai primeiro o lote que vence antes, não o que entrou antes).
 *
 * `validade` e `recebido_em` são dias, com cast `date`: a comparação de
 * vencimento é feita por dia no fuso do negócio, por `App\Support\BusinessDate`
 * (Task 17.3), nunca contra `now()` em UTC, que daria o lote como vencido a
 * partir das 21h de Brasília do dia anterior.
 *
 * `custo_unitario` tem 4 casas porque saneante concentrado tem custo por
 * mililitro em fração pequena; com 2 casas o custo do serviço sairia zerado.
 *
 * `fornecedor` e `nota_fiscal` são texto: o vínculo com a conta a pagar do
 * fornecedor é o Plano 18.
 */
class ProductBatch extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'product_id',
        'lote',
        'validade',
        'custo_unitario',
        'fornecedor',
        'nota_fiscal',
        'recebido_em',
    ];

    protected $casts = [
        // Dias sem hora relevante: nunca sofrem conversão de fuso.
        'validade' => 'date',
        'recebido_em' => 'date',
        'custo_unitario' => 'decimal:4',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Produto a que o lote pertence.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Razão de movimentos deste lote.
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Saldo corrente deste lote, uma linha por local.
     */
    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }
}
