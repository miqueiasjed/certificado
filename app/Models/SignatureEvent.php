<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento recebido do provedor de assinatura eletrônica por webhook (Plano 26,
 * Task 26.3), gravado antes de qualquer processamento, inclusive evento de
 * tipo desconhecido.
 *
 * `[company_id, evento_id]` é **unique**: é essa restrição no banco que torna
 * o processamento do webhook idempotente. Sem ela, dois envios simultâneos do
 * mesmo evento processam duas vezes. `payload` guarda o corpo inteiro do
 * evento, o que permite reprocessar depois de um bug sem depender de o
 * provedor reenviar.
 *
 * Diferente de `GatewayEvent` (Plano 7/19), este model **leva**
 * `BelongsToCompany`: todo evento de assinatura nasce com o tenant conhecido,
 * resolvido pelo `webhook_token` da URL antes de qualquer leitura do corpo, e
 * é dado que só a empresa dona do contrato pode ver. Ver o cabeçalho da
 * migration `create_signature_events_table` para a comparação inteira.
 */
class SignatureEvent extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'provedor',
        'evento_id',
        'tipo',
        'signature_request_id',
        'payload',
        'processado_em',
        'erro',
        'tentativas',
    ];

    protected $casts = [
        'payload' => 'array',
        'processado_em' => 'datetime',
        'tentativas' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Pedido de assinatura a que este evento se refere, quando ele foi
     * localizado dentro do tenant do token.
     */
    public function signatureRequest(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class);
    }
}
