<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Solicitação de atendimento aberta pelo cliente no portal (Plano 15).
 *
 * Guarda tanto `client_id` quanto `client_user_id`: o cliente é o dono do
 * relacionamento comercial e sobrevive à saída de um usuário de acesso
 * específico, e o usuário é quem de fato abriu a solicitação.
 *
 * Leva `BelongsToCompany`: a solicitação pertence à empresa do cliente que a
 * abriu. Isolamento por empresa aqui é só a primeira camada; toda consulta
 * feita a partir do portal do cliente precisa ainda escopar por `client_id`,
 * para um cliente não enxergar a solicitação de outro cliente da mesma
 * empresa.
 */
class ClientRequest extends Model
{
    use BelongsToCompany;

    public const SITUACAO_ABERTA = 'aberta';

    public const SITUACAO_EM_ATENDIMENTO = 'em_atendimento';

    public const SITUACAO_RESOLVIDA = 'resolvida';

    public const SITUACAO_CANCELADA = 'cancelada';

    /**
     * @var array<int, string>
     */
    public const SITUACOES = [
        self::SITUACAO_ABERTA,
        self::SITUACAO_EM_ATENDIMENTO,
        self::SITUACAO_RESOLVIDA,
        self::SITUACAO_CANCELADA,
    ];

    public const PRIORIDADE_BAIXA = 'baixa';

    public const PRIORIDADE_NORMAL = 'normal';

    public const PRIORIDADE_ALTA = 'alta';

    /**
     * @var array<int, string>
     */
    public const PRIORIDADES = [
        self::PRIORIDADE_BAIXA,
        self::PRIORIDADE_NORMAL,
        self::PRIORIDADE_ALTA,
    ];

    protected $fillable = [
        'client_id',
        'client_user_id',
        'address_id',
        'assunto',
        'descricao',
        'situacao',
        'prioridade',
        'respondida_em',
        'resposta',
        'atendida_por',
    ];

    protected $casts = [
        'respondida_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Cliente dono da solicitação.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Usuário do portal que abriu a solicitação.
     */
    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class);
    }

    /**
     * Endereço relacionado, quando informado.
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    /**
     * Usuário do tenant que atendeu a solicitação, quando ainda existe.
     */
    public function atendidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atendida_por');
    }
}
