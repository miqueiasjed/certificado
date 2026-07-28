<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Assinatura do tenant no plano comercial contratado.
 *
 * Deliberadamente **sem** a trait `BelongsToCompany`, mesmo tendo
 * `company_id`: é administrada pela plataforma, que precisa ver e conciliar a
 * assinatura de todos os tenants na mesma tela, e o escopo global por empresa
 * filtraria pela "empresa corrente" que não existe nessa tela. A classificação
 * está registrada em `App\Support\DominioMultiempresa::MODELS_FORA_DO_ESCOPO`,
 * e a tabela em `TABELAS_FORA_DO_ESCOPO`; o teste da Task 4.10 cobra as duas
 * coisas. O tenant vê a própria assinatura por filtro explícito de
 * `company_id` (Task 7.8), não pelo escopo global.
 *
 * `inicio_em` e `proxima_cobranca_em` são `date`: vencimento de cobrança é um
 * dia do calendário, não um instante. `cancelada_em` é `datetime`: o instante
 * do cancelamento importa para auditoria.
 */
class Subscription extends Model
{
    protected $fillable = [
        'company_id',
        'plan_id',
        'situacao',
        'forma_pagamento',
        'gateway',
        'gateway_subscription_id',
        'inicio_em',
        'proxima_cobranca_em',
        'cancelada_em',
        'motivo_cancelamento',
    ];

    protected $casts = [
        'inicio_em' => 'date',
        'proxima_cobranca_em' => 'date',
        'cancelada_em' => 'datetime',
    ];

    /**
     * Empresa (tenant) dona desta assinatura.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Plano comercial contratado.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Faturas geradas por esta assinatura, uma por período.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
