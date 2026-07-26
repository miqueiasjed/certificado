<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Motivo registrado para uma data prevista de visita que não virou Ordem de
 * Serviço.
 *
 * Uma linha cobre uma data de um contrato. É o que tira aquela data do painel
 * de conformidade (ver `GeracaoDeVisitasService::pendenciasDeConformidade`) e o
 * que fica no lugar dela perante fiscalização: a lacuna continua visível na
 * tela do contrato, agora com o motivo, o autor e a data da decisão.
 *
 * Leva `Auditavel` de propósito: remover uma justificativa devolve a pendência
 * ao painel, e quem fez isso, quando e qual era o motivo apagado precisam
 * sobreviver à remoção. O `audit_logs` guarda esse rastro.
 */
class ContractVisitJustification extends Model
{
    use Auditavel, BelongsToCompany;

    protected $fillable = [
        'contract_id',
        'expected_date',
        'reason',
        'justified_by',
        'justified_by_name',
    ];

    protected $casts = [
        // Dia sem hora relevante: a data prevista do calendário do contrato.
        'expected_date' => 'date',
        // Instantes: gravados em UTC, convertidos para o fuso do negócio na
        // exibição, via BusinessDate.
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Usuário que registrou a justificativa, quando ainda existe. O nome fica
     * congelado em `justified_by_name` justamente para o caso de não existir
     * mais.
     */
    public function justifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'justified_by');
    }
}
