<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conflito encontrado ao aplicar uma operação de sincronização.
 *
 * Nasce sempre com `situacao` "aberto": nunca é resolvido automaticamente
 * descartando o lado do técnico. A mudança de estado é ação explícita de quem
 * resolve o conflito.
 *
 * Leva `BelongsToCompany`: o conflito pertence à empresa da operação que o
 * gerou.
 */
class SyncConflict extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'sync_operation_id',
        'work_order_id',
        'motivo',
        'valor_do_aplicativo',
        'valor_do_servidor',
        'situacao',
        'resolvido_por',
        'resolvido_em',
    ];

    protected $casts = [
        'valor_do_aplicativo' => 'array',
        'valor_do_servidor' => 'array',
        'resolvido_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Operação de sincronização que gerou o conflito.
     */
    public function syncOperation(): BelongsTo
    {
        return $this->belongsTo(SyncOperation::class);
    }

    /**
     * Ordem de serviço afetada, quando houver.
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Usuário que resolveu o conflito, quando resolvido.
     */
    public function resolvidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolvido_por');
    }
}
