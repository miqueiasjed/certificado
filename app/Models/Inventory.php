<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cabeçalho de uma contagem física de estoque (Plano 17, Task 17.5).
 *
 * Não guarda nenhum saldo por si só: cada `InventoryItem` é que congela o
 * saldo do sistema no momento da abertura e recebe a contagem. O fechamento
 * (`InventoryService::finalizar()`) gera um `StockMovement` de `ajuste` por
 * item divergente, sempre com justificativa como motivo.
 *
 * Sem a trait `Auditavel`: a auditoria deste plano segue a lista fechada da
 * Task 3.3, e os demais models de estoque (`StockLocation`, `ProductBatch`,
 * `StockMovement`, `StockBalance`) tampouco a usam. O próprio `StockMovement`
 * de ajuste, gerado no fechamento, já é o registro imutável de quem alterou o
 * quê e por quê.
 */
class Inventory extends Model
{
    use BelongsToCompany;

    public const SITUACAO_ABERTO = 'aberto';

    public const SITUACAO_FINALIZADO = 'finalizado';

    public const SITUACAO_CANCELADO = 'cancelado';

    /**
     * Situações aceitas, iguais ao enum da coluna `situacao`.
     *
     * @var array<int, string>
     */
    public const SITUACOES = [
        self::SITUACAO_ABERTO,
        self::SITUACAO_FINALIZADO,
        self::SITUACAO_CANCELADO,
    ];

    protected $fillable = [
        'stock_location_id',
        'situacao',
        'aberto_por',
        'aberto_em',
        'finalizado_por',
        'finalizado_em',
        'observacao',
    ];

    protected $casts = [
        // Instantes: gravados em UTC, convertidos para o fuso do negócio só
        // na exibição.
        'aberto_em' => 'datetime',
        'finalizado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Local de estoque contado.
     */
    public function stockLocation(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    /**
     * Quem abriu a contagem.
     */
    public function abertoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aberto_por');
    }

    /**
     * Quem fechou a contagem, quando já fechada.
     */
    public function finalizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalizado_por');
    }

    /**
     * Itens desta contagem, um por combinação de produto e lote.
     */
    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    /**
     * Movimentos de ajuste gerados pelo fechamento desta contagem.
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * O inventário ainda aceita contagem e fechamento?
     */
    public function estaAberto(): bool
    {
        return $this->situacao === self::SITUACAO_ABERTO;
    }
}
