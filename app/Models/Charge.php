<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Cobrança (boleto ou PIX) emitida a partir de uma parcela a receber
 * (Plano 19, Task 19.1). A emissão em si (chamada ao gateway) é regra de
 * negócio da Task 19.3, não deste model.
 *
 * Uma parcela pode ter mais de uma cobrança ao longo do tempo (boleto vencido
 * e reemitido), mas só uma ativa por vez; essa garantia é validação no
 * Service da Task 19.3, não restrição de banco aqui.
 *
 * `gateway_charge_id` é único por `[company_id, gateway]`: dois tenants no
 * mesmo gateway podem coincidir no identificador da cobrança.
 *
 * `charge_anterior_id` liga uma cobrança nova à que ela substituiu, gravado
 * por `App\Services\ChargeService::reemitir()` (Task 19.3): é o que permite
 * navegar do boleto vencido para o que o substituiu, e vice-versa.
 */
class Charge extends Model
{
    use BelongsToCompany;

    /**
     * Tipos aceitos, iguais ao enum da coluna `tipo`.
     *
     * @var array<int, string>
     */
    public const TIPOS = ['boleto', 'pix'];

    /**
     * Situações aceitas, iguais ao enum da coluna `situacao`.
     *
     * `estornada` entrou na Task 19.4 (migration
     * `2026_08_04_100002_add_webhook_token_hash_and_estorno_situacao`):
     * diferente de `cancelada` (nunca chegou a ser paga), é a cobrança que
     * foi paga e teve o pagamento revertido pelo provedor. Perder essa
     * distinção custaria a exatidão do documento fiscal (CLAUDE.md).
     *
     * @var array<int, string>
     */
    public const SITUACOES = ['pendente', 'emitida', 'paga', 'vencida', 'cancelada', 'erro', 'estornada'];

    /**
     * Situações que contam como "cobrança ativa" de uma parcela: em aberto,
     * ainda esperando pagamento. Mesma lista de
     * `App\Services\ChargeService::SITUACOES_ATIVAS` (Task 19.3, privada
     * naquela classe); pública aqui porque `App\Services\PortalService`
     * (Task 19.6) também precisa dela para achar a cobrança ativa da parcela
     * a mostrar no portal, sem depender de `ChargeService` para isso.
     *
     * @var array<int, string>
     */
    public const SITUACOES_ATIVAS = ['pendente', 'emitida', 'vencida'];

    protected $fillable = [
        'receivable_installment_id',
        'tipo',
        'gateway',
        'gateway_charge_id',
        'valor',
        'vencimento',
        'situacao',
        'link_pagamento',
        'linha_digitavel',
        'qr_code_pix',
        'pago_em',
        'valor_pago',
        'erro_mensagem',
        'tentativas',
        'charge_anterior_id',
    ];

    protected $casts = [
        'valor' => 'decimal:2',
        'valor_pago' => 'decimal:2',
        // Dias sem hora relevante: nunca sofrem conversão de fuso.
        'vencimento' => 'date',
        'pago_em' => 'date',
        'tentativas' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Parcela a receber que esta cobrança tenta liquidar.
     */
    public function receivableInstallment(): BelongsTo
    {
        return $this->belongsTo(ReceivableInstallment::class);
    }

    /**
     * Cobrança que esta substituiu, quando esta nasceu de uma reemissão.
     */
    public function chargeAnterior(): BelongsTo
    {
        return $this->belongsTo(self::class, 'charge_anterior_id');
    }

    /**
     * Cobrança que substituiu esta, quando ela foi reemitida.
     */
    public function chargeSeguinte(): HasOne
    {
        return $this->hasOne(self::class, 'charge_anterior_id');
    }
}
