<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Operação de sincronização enviada pelo aplicativo do técnico.
 *
 * `operacao_uuid` é gerado no celular e é a chave da idempotência: reenviar a
 * mesma operação (porque a resposta se perdeu na rede) não duplica o efeito
 * dela, porque o banco recusa a segunda inserção com o mesmo uuid. `registrada_em`
 * guarda o instante do celular; `created_at` guarda o instante do servidor, e
 * os dois importam para explicar operação chegando fora de ordem.
 *
 * Leva `BelongsToCompany`: a operação pertence à empresa do técnico que a
 * enviou.
 */
class SyncOperation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'user_id',
        'operacao_uuid',
        'tipo',
        'work_order_id',
        'payload',
        'situacao',
        'registrada_em',
        'aplicada_em',
        'erro',
    ];

    protected $casts = [
        'payload' => 'array',
        // Instante do celular.
        'registrada_em' => 'datetime',
        // Instante do servidor em que a operação foi aplicada.
        'aplicada_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Usuário que enviou a operação pelo aplicativo.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Ordem de serviço afetada pela operação, quando houver.
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Conflitos gerados ao tentar aplicar esta operação.
     */
    public function conflicts(): HasMany
    {
        return $this->hasMany(SyncConflict::class);
    }
}
