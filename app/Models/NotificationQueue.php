<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Item da fila de notificações: uma mensagem já montada, esperando a hora de
 * sair.
 *
 * O que protege contra aviso repetido é `chave_idempotencia`, unique no banco.
 * Ela é montada pelo Service (Task 14.2) a partir do evento, do destinatário e
 * da referência, nunca do instante, para que a rotina rodando de novo encontre a
 * chave que já existe em vez de criar um segundo aviso igual.
 *
 * Sem `Auditavel`, de propósito: o histórico deste registro é
 * `notification_logs`, que guarda tentativa por tentativa com o motivo da falha.
 * A rotina de despacho altera a situação e o contador de tentativas várias vezes
 * por item, a cada 5 minutos; auditar isso encheria `audit_logs` de linhas
 * escritas por "Sistema" que não respondem nenhuma pergunta que o log de
 * notificação já não responda melhor. É o mesmo critério de `AccessLog` e de
 * `ScheduledTaskRun`.
 *
 * O nome da tabela é singular (`notification_queue`), então precisa ser
 * declarado: o Eloquent procuraria `notification_queues`.
 */
class NotificationQueue extends Model
{
    use BelongsToCompany;

    protected $table = 'notification_queue';

    /** Enfileirada, esperando a hora de sair. */
    public const SITUACAO_PENDENTE = 'pendente';

    /** Em posse do despachante. Item preso aqui volta para pendente (Task 14.3). */
    public const SITUACAO_ENVIANDO = 'enviando';

    /** Entregue ao provedor com sucesso. */
    public const SITUACAO_ENVIADA = 'enviada';

    /** Esgotou as tentativas ou falhou de forma permanente. */
    public const SITUACAO_FALHA = 'falha';

    /** Cancelada porque o fato que a gerou deixou de valer. */
    public const SITUACAO_CANCELADA = 'cancelada';

    /** Barrada pela preferência do cliente: o canal está desligado para ele. */
    public const SITUACAO_RECUSADA = 'recusada';

    /**
     * Situações possíveis, na ordem em que aparecem no ciclo de vida.
     *
     * @var array<int, string>
     */
    public const SITUACOES = [
        self::SITUACAO_PENDENTE,
        self::SITUACAO_ENVIANDO,
        self::SITUACAO_ENVIADA,
        self::SITUACAO_FALHA,
        self::SITUACAO_CANCELADA,
        self::SITUACAO_RECUSADA,
    ];

    public const DESTINATARIO_CLIENTE = 'cliente';

    public const DESTINATARIO_EMPRESA = 'empresa';

    public const DESTINATARIO_USUARIO = 'usuario';

    /**
     * @var array<int, string>
     */
    public const DESTINATARIOS = [
        self::DESTINATARIO_CLIENTE,
        self::DESTINATARIO_EMPRESA,
        self::DESTINATARIO_USUARIO,
    ];

    protected $fillable = [
        'evento',
        'canal',
        'chave_idempotencia',
        'destinatario_tipo',
        'destinatario_id',
        'destino',
        'assunto',
        'corpo',
        'contexto',
        'situacao',
        'agendada_para',
        'tentativas',
        'proxima_tentativa_em',
    ];

    protected $casts = [
        'contexto' => 'array',
        'tentativas' => 'integer',
        // Instantes, não dias: `agendada_para` combina a data calculada no fuso
        // do negócio com a hora do disparo, e é comparada com "agora" pela
        // rotina de despacho.
        'agendada_para' => 'datetime',
        'proxima_tentativa_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Tentativas de envio deste item, da mais recente para a mais antiga.
     *
     * O id desempata tentativas gravadas no mesmo segundo.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(NotificationLog::class, 'notification_queue_id')
            ->orderByDesc('ocorrida_em')
            ->orderByDesc('id');
    }
}
