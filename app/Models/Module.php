<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Módulo controlável do produto (financeiro, estoque, contratos, portal do
 * cliente, app do técnico, notificações, roteirização, NFS-e, laudo por IA,
 * relatórios de monitoramento).
 *
 * Deliberadamente **sem** a trait `BelongsToCompany`: o catálogo de módulos
 * pertence a quem vende o SaaS e é o mesmo para todos os tenants, no mesmo
 * critério já aplicado a `Plan`. A classificação está registrada em
 * `App\Support\DominioMultiempresa::MODELS_FORA_DO_ESCOPO`, e a tabela em
 * `TABELAS_FORA_DO_ESCOPO`; o teste da Task 4.10 cobra as duas coisas.
 *
 * `sempre_ativo` é o módulo que nenhum plano pode bloquear (clientes, OS,
 * certificados são o produto, não um extra). Quem for consumir isso
 * (Task 6.3 em diante) verifica essa coluna antes de olhar plano ou
 * liberação pontual.
 */
class Module extends Model
{
    protected $fillable = [
        'chave',
        'nome',
        'descricao',
        'ordem',
        'sempre_ativo',
    ];

    protected $casts = [
        'ordem' => 'integer',
        'sempre_ativo' => 'boolean',
    ];

    /**
     * Planos que liberam este módulo.
     */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_module');
    }

    /**
     * Empresas com liberação pontual registrada para este módulo, seja para
     * liberar módulo fora do plano, seja para bloquear módulo que o plano
     * libera.
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_modules')
            ->withPivot(['liberado', 'motivo', 'liberado_ate'])
            ->withTimestamps();
    }
}
