<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um fato (venda, recebimento ou execução) que compõe uma `Commission`
 * apurada (Plano 23).
 *
 * `origem_tipo`/`origem_id` apontam para `Budget`, `ReceivableInstallment` ou
 * `WorkOrder`, dependendo de `origem_tipo`, sem chave estrangeira (uma FK de
 * verdade não pode apontar para tabelas diferentes), mesmo padrão de
 * `AuditLog::auditable_type`/`auditable_id`.
 *
 * `base_valor`, `percentual_ou_fixo` e `valor` são gravados no momento da
 * apuração, e não só a referência a `commission_rule_id`. É o que torna a
 * comissão auditável anos depois, mesmo que a regra mude ou seja desativada
 * no meio do caminho.
 */
class CommissionItem extends Model
{
    use BelongsToCompany;

    /**
     * Origens aceitas, iguais ao enum da coluna `origem_tipo`.
     *
     * @var array<int, string>
     */
    public const ORIGENS = ['orcamento', 'parcela', 'ordem_servico'];

    protected $fillable = [
        'commission_id',
        'origem_tipo',
        'origem_id',
        'commission_rule_id',
        'base_valor',
        'percentual_ou_fixo',
        'valor',
        'ocorrido_em',
    ];

    protected $casts = [
        'base_valor' => 'decimal:2',
        'percentual_ou_fixo' => 'decimal:4',
        'valor' => 'decimal:2',
        // Dia sem hora relevante: o dia do fato, nunca um instante.
        'ocorrido_em' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Comissão a que este item pertence.
     */
    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class);
    }

    /**
     * Regra usada para calcular este item, no momento da apuração.
     */
    public function commissionRule(): BelongsTo
    {
        return $this->belongsTo(CommissionRule::class);
    }
}
