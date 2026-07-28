<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fatura de um período (`YYYY-MM`) de uma assinatura.
 *
 * Deliberadamente **sem** a trait `BelongsToCompany`, mesmo tendo
 * `company_id`: é administrada pela plataforma, que precisa ver e conciliar a
 * fatura de todos os tenants na mesma tela, mesmo critério já aplicado a
 * `Subscription`. A classificação está registrada em `App\Support\
 * DominioMultiempresa::MODELS_FORA_DO_ESCOPO`, e a tabela em
 * `TABELAS_FORA_DO_ESCOPO`; o teste da Task 4.10 cobra as duas coisas. O
 * tenant vê as próprias faturas por filtro explícito de `company_id`
 * (Task 7.8), não pelo escopo global.
 *
 * `vencimento` e `pago_em` são `date`, nunca `datetime`: pagamento de fatura
 * não tem hora relevante, e comparar por instante é o que faria uma fatura
 * aparecer vencida às 21h de Brasília enquanto ainda é o dia do vencimento em
 * UTC (ver `App\Support\BusinessDate`). `valor` é `decimal:2`, nunca float,
 * porque é dinheiro.
 */
class Invoice extends Model
{
    protected $fillable = [
        'company_id',
        'subscription_id',
        'referencia',
        'valor',
        'situacao',
        'vencimento',
        'pago_em',
        'gateway_invoice_id',
        'link_pagamento',
        'linha_digitavel',
        'qr_code_pix',
    ];

    protected $casts = [
        'valor' => 'decimal:2',
        'vencimento' => 'date',
        'pago_em' => 'date',
    ];

    /**
     * Empresa (tenant) dona desta fatura.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Assinatura que gerou esta fatura.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
