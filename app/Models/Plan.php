<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plano comercial da plataforma.
 *
 * Deliberadamente **sem** a trait `BelongsToCompany`: o catálogo de planos
 * pertence a quem vende o SaaS e é o mesmo para todos os tenants. A
 * classificação está registrada em
 * `App\Support\DominioMultiempresa::MODELS_FORA_DO_ESCOPO`, e a tabela em
 * `TABELAS_FORA_DO_ESCOPO`; o teste da Task 4.10 cobra as duas coisas.
 *
 * Limite nulo significa ilimitado, nos quatro limites. Quem for consumir isso
 * (Task 5.2 em diante) precisa tratar `null` antes de comparar: `null` em
 * comparação numérica vira zero e transformaria o plano interno, que libera
 * tudo, no plano mais restrito do sistema.
 */
class Plan extends Model
{
    protected $fillable = [
        'nome',
        'slug',
        'valor',
        'periodicidade',
        'limite_usuarios',
        'limite_clientes',
        'limite_os_mes',
        'limite_armazenamento_mb',
        'ativo',
        'ordem',
        'descricao',
    ];

    protected $casts = [
        'valor' => 'decimal:2',
        'ativo' => 'boolean',
        'limite_usuarios' => 'integer',
        'limite_clientes' => 'integer',
        'limite_os_mes' => 'integer',
        'limite_armazenamento_mb' => 'integer',
        'ordem' => 'integer',
    ];

    /**
     * Empresas (tenants) que assinam este plano.
     */
    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    /**
     * Planos disponíveis para contratação, na ordem de exibição.
     *
     * Plano desativado continua existindo e continua valendo para quem já o
     * assina: `ativo = false` tira da vitrine, nunca do contrato em vigor.
     */
    public function scopeAtivos(Builder $consulta): Builder
    {
        return $consulta->where('ativo', true)->orderBy('ordem');
    }
}
