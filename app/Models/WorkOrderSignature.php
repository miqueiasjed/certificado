<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Assinatura do cliente coletada em campo pelo aplicativo do técnico.
 *
 * Uma linha por OS (unique em `work_order_id`): recoleta numa OS já assinada é
 * correção justificada (`WorkOrderSignatureService::corrigirComJustificativa`,
 * Task 13.3), que atualiza esta mesma linha, nunca insere uma segunda.
 *
 * Leva `Auditavel` de propósito: a recoleta e a correção justificada precisam
 * deixar rastro de quem alterou, quando e qual era a assinatura anterior, e é
 * `audit_logs` que guarda esse histórico.
 */
class WorkOrderSignature extends Model
{
    use Auditavel, BelongsToCompany;

    protected $fillable = [
        'work_order_id',
        'assinante_nome',
        'assinante_documento',
        'assinante_vinculo',
        'imagem_path',
        'coletada_em',
        'latitude',
        'longitude',
        'precisao_metros',
        'user_id',
        'origem',
    ];

    protected $casts = [
        // Instante da coleta, no aparelho do técnico: gravado em UTC e
        // convertido para o fuso do negócio só na exibição, via BusinessDate.
        'coletada_em' => 'datetime',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'precisao_metros' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * OS a que esta assinatura pertence.
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Técnico que coletou a assinatura.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
