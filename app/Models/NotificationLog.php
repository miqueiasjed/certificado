<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma tentativa de envio de um item da fila, com o que o provedor respondeu.
 *
 * Toda tentativa gera uma linha, inclusive a que falhou. É este histórico que
 * responde "por que o cliente não recebeu", e é a distinção entre
 * `falha_temporaria` e `falha_permanente` que decide se o despachante repete ou
 * desiste (Task 14.3): repetir e-mail para endereço inexistente queima a
 * reputação do remetente do tenant.
 *
 * Sem `Auditavel`: é a própria trilha, gravada uma vez e nunca alterada.
 * Auditar um log seria logar o log, pelo mesmo motivo que `AuditLog` e
 * `AccessLog` também não levam a trait.
 */
class NotificationLog extends Model
{
    use BelongsToCompany;

    /** Provedor aceitou a mensagem. */
    public const RESULTADO_SUCESSO = 'sucesso';

    /** Tempo limite, 5xx ou limite de taxa: cabe repetir mais tarde. */
    public const RESULTADO_FALHA_TEMPORARIA = 'falha_temporaria';

    /** Endereço inválido, caixa inexistente ou 4xx: repetir só piora. */
    public const RESULTADO_FALHA_PERMANENTE = 'falha_permanente';

    /**
     * @var array<int, string>
     */
    public const RESULTADOS = [
        self::RESULTADO_SUCESSO,
        self::RESULTADO_FALHA_TEMPORARIA,
        self::RESULTADO_FALHA_PERMANENTE,
    ];

    protected $fillable = [
        'notification_queue_id',
        'tentativa',
        'resultado',
        'mensagem',
        'resposta_bruta',
        'ocorrida_em',
    ];

    protected $casts = [
        'tentativa' => 'integer',
        'resposta_bruta' => 'array',
        // Instante da tentativa, gravado em UTC como todo instante do projeto e
        // convertido para o fuso do negócio na exibição, via BusinessDate.
        'ocorrida_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Item da fila a que esta tentativa pertence.
     */
    public function notificationQueue(): BelongsTo
    {
        return $this->belongsTo(NotificationQueue::class, 'notification_queue_id');
    }
}
