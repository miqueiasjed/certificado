<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Liberação pontual de um módulo para uma empresa, fora do que o plano dela
 * define.
 *
 * Deliberadamente **sem** a trait `BelongsToCompany`, mesmo tendo
 * `company_id`: é gerida pelo super admin, que precisa enxergar e comparar
 * todos os tenants na mesma tela, e o escopo global por empresa filtraria
 * pela "empresa corrente" que não existe nessa tela. A classificação está
 * registrada em `App\Support\DominioMultiempresa::MODELS_FORA_DO_ESCOPO`, e a
 * tabela em `TABELAS_FORA_DO_ESCOPO`; o teste da Task 4.10 cobra as duas
 * coisas.
 *
 * `liberado = false` sobrepõe o plano: é assim que se bloqueia um módulo
 * específico sem trocar o tenant de plano. `liberado_ate` permite liberação
 * temporária (demonstração comercial) que expira sozinha, sem alguém
 * precisar lembrar de revogar.
 */
class CompanyModule extends Model
{
    protected $fillable = [
        'company_id',
        'module_id',
        'liberado',
        'motivo',
        'liberado_ate',
    ];

    protected $casts = [
        'liberado' => 'boolean',
        'liberado_ate' => 'date',
    ];

    /**
     * Empresa que recebe a liberação pontual.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Módulo ao qual a liberação pontual se refere.
     */
    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}
