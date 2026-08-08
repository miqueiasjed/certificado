<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha por chamada ao modelo de linguagem, por tenant (Plano 25).
 *
 * Existe para responder, antes de qualquer decisão comercial sobre o recurso,
 * quanto cada empresa custa por mês. O custo é por chamada, então a linha é
 * gravada mesmo quando a chamada falha (`sucesso = false`): tentativa que
 * consumiu token consumiu dinheiro, e teto que ignora falha é teto furado.
 *
 * `tokens_cache_leitura` é contado à parte porque token lido do cache de
 * prompt custa uma fração do token de entrada comum, e é justamente o prefixo
 * de sistema cacheado que torna o recurso viável. Somar os dois esconderia o
 * efeito do cache na conta.
 */
class AiUsage extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'ai_draft_id',
        'tipo',
        'modelo',
        'tokens_entrada',
        'tokens_saida',
        'tokens_cache_leitura',
        'custo_estimado',
        'duracao_ms',
        'sucesso',
        'erro',
    ];

    protected $casts = [
        'tokens_entrada' => 'integer',
        'tokens_saida' => 'integer',
        'tokens_cache_leitura' => 'integer',
        'custo_estimado' => 'decimal:6',
        'duracao_ms' => 'integer',
        'sucesso' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Rascunho produzido pela chamada, quando ela produziu algum.
     */
    public function aiDraft(): BelongsTo
    {
        return $this->belongsTo(AiDraft::class);
    }

    /**
     * Chamadas registradas dentro de um intervalo de instantes.
     */
    public function scopeNoPeriodo(Builder $consulta, mixed $inicio, mixed $fim): Builder
    {
        return $consulta->whereBetween('created_at', [$inicio, $fim]);
    }
}
