<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uso apurado de um tenant em um período (mês), para o painel de uso do
 * super admin e para os limites de plano do Plano 6.
 *
 * Deliberadamente **sem** a trait `BelongsToCompany`: o super admin precisa
 * ver a apuração de todos os tenants na mesma consulta, e o escopo global por
 * empresa bloquearia exatamente essa leitura. A classificação está registrada
 * em `App\Support\DominioMultiempresa::MODELS_FORA_DO_ESCOPO`, e a tabela em
 * `TABELAS_FORA_DO_ESCOPO`; o teste da Task 4.10 cobra as duas coisas. O
 * tenant que quiser ver o próprio uso (Plano 6) filtra explicitamente por
 * `company_id`.
 *
 * `apurado_em` é o instante em que a apuração rodou, gravado em UTC como todo
 * campo de instante do projeto. `referencia` é o período apurado, no formato
 * `YYYY-MM`, calculado no fuso do negócio por quem grava (`App\Support\
 * BusinessDate`). Quem preenche os dois é a rotina de apuração do Plano 6;
 * este model só declara a estrutura.
 */
class TenantUsage extends Model
{
    protected $fillable = [
        'company_id',
        'referencia',
        'usuarios',
        'clientes',
        'ordens_servico',
        'certificados',
        'armazenamento_mb',
        'apurado_em',
    ];

    protected $casts = [
        'usuarios' => 'integer',
        'clientes' => 'integer',
        'ordens_servico' => 'integer',
        'certificados' => 'integer',
        'armazenamento_mb' => 'integer',
        'apurado_em' => 'datetime',
    ];

    /**
     * Empresa (tenant) a que esta apuração pertence.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Apurações de um período específico (`YYYY-MM`), para o painel geral que
     * agrupa a apuração de todos os tenants por período.
     */
    public function scopeDoPeriodo(Builder $consulta, string $referencia): Builder
    {
        return $consulta->where('referencia', $referencia);
    }
}
