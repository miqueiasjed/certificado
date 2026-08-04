<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento recebido do gateway de pagamento, gravado antes de qualquer
 * processamento, inclusive evento de tipo desconhecido.
 *
 * Deliberadamente **sem** a trait `BelongsToCompany`, mesmo tendo, desde o
 * Plano 19 (Task 19.1), `company_id` **nullable**: é a plataforma que
 * processa e concilia o evento, não um tenant específico, mesmo critério já
 * aplicado a `Subscription`/`Invoice`. `company_id` fica `null` para o evento
 * de assinatura da plataforma ao tenant (o corpo do webhook é quem identifica
 * a assinatura ou a fatura envolvida, dentro de `payload`) e preenchido para
 * o evento de cobrança do tenant ao cliente final (Plano 19), que já nasce
 * com o tenant conhecido. A classificação está registrada em `App\Support\
 * DominioMultiempresa::MODELS_FORA_DO_ESCOPO`, e a tabela em
 * `TABELAS_FORA_DO_ESCOPO`; o teste da Task 4.10 cobra as duas coisas.
 *
 * `evento_id` é **unique**: é essa restrição no banco que torna o
 * processamento do webhook idempotente. Sem ela, dois envios simultâneos do
 * mesmo evento processam duas vezes. `payload` guarda o corpo inteiro do
 * evento, o que permite reprocessar depois de um bug sem depender do gateway
 * reenviar.
 */
class GatewayEvent extends Model
{
    protected $fillable = [
        'company_id',
        'gateway',
        'evento_id',
        'tipo',
        'payload',
        'processado_em',
        'erro',
        'tentativas',
    ];

    protected $casts = [
        'payload' => 'array',
        'processado_em' => 'datetime',
        'tentativas' => 'integer',
    ];

    /**
     * Tenant dono do evento, quando o evento é de cobrança do tenant ao
     * cliente final (Plano 19). `null` no evento de assinatura da plataforma.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
