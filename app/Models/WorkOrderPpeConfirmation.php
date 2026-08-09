<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O que o técnico confirmou vestir numa ordem de serviço (Plano 29, Task 29.1).
 *
 * É o registro de **uso** do EPI, e complementa a ficha de `PpeDelivery`, que é
 * o registro de **entrega** (Plano 28). Entregue e usado são fatos distintos: um
 * respirador cuja troca venceu há oito meses continua pendurado no cinto do
 * técnico, e é a confirmação em campo que torna isso visível.
 *
 * A confirmação é registro, **não trava**: nada aqui impede a execução da OS.
 * Decisão registrada do plano — pendência de EPI é problema de escritório, e
 * travar o técnico em campo por causa dela tira a operação do ar.
 *
 * Dois pontos sustentam este model:
 *
 * - **`confirmado_em` é o instante do aparelho do técnico**, gravado em UTC e
 *   convertido só na exibição, nunca um `date`. Duas OS no mesmo dia precisam de
 *   ordem entre si, e a confirmação acontece no horário da execução. Mesmo
 *   critério de `WorkOrderAdequation::$registrada_em`.
 * - **`justificativa` é obrigatória quando `confirmado` é falso, e essa regra
 *   mora no Service da Task 29.2**, nunca no banco nem aqui. O registro chega
 *   pela fila offline do Plano 12, e recusa vinda do banco em campo vira erro de
 *   sincronização que o técnico não consegue resolver.
 *
 * A idempotência do reenvio é a unique composta
 * `[company_id, work_order_id, personal_protective_equipment_id]` da tabela: o
 * aplicativo reenvia a mesma confirmação quando a conexão cai no meio, e sem ela
 * cada reenvio viraria linha nova.
 */
class WorkOrderPpeConfirmation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'work_order_id',
        'personal_protective_equipment_id',
        'confirmado',
        'justificativa',
        'confirmado_em',
        'user_id',
    ];

    protected $casts = [
        'confirmado' => 'boolean',
        // Instante do aparelho do técnico: gravado em UTC, convertido para o
        // fuso do negócio só na exibição, via BusinessDate. Nunca `date`.
        'confirmado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Ordem de serviço em que a confirmação foi feita.
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Modelo de EPI confirmado.
     */
    public function personalProtectiveEquipment(): BelongsTo
    {
        return $this->belongsTo(PersonalProtectiveEquipment::class);
    }

    /**
     * Quem confirmou.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
