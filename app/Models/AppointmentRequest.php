<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pedido de horário aberto pelo cliente (Plano 16): origem tanto do portal do
 * cliente autenticado quanto da página pública de agendamento, que não exige
 * login.
 *
 * `client_id` e `client_user_id` nascem nulos quando o pedido vem da página
 * pública, de quem ainda não é cliente da empresa. Transformar o solicitante
 * em cliente cadastrado é decisão da empresa ao confirmar (Task 16.4), não
 * algo que este model resolve sozinho.
 *
 * Pedido nunca agenda direto no calendário: `work_order_id` só é preenchido
 * quando a empresa confirma, porque só ela sabe se tem técnico disponível na
 * região naquele dia.
 */
class AppointmentRequest extends Model
{
    use BelongsToCompany;

    public const SITUACAO_PENDENTE = 'pendente';

    public const SITUACAO_CONFIRMADA = 'confirmada';

    public const SITUACAO_RECUSADA = 'recusada';

    public const SITUACAO_CANCELADA = 'cancelada';

    /**
     * @var array<int, string>
     */
    public const SITUACOES = [
        self::SITUACAO_PENDENTE,
        self::SITUACAO_CONFIRMADA,
        self::SITUACAO_RECUSADA,
        self::SITUACAO_CANCELADA,
    ];

    public const PERIODO_MANHA = 'manha';

    public const PERIODO_TARDE = 'tarde';

    /**
     * @var array<int, string>
     */
    public const PERIODOS = [
        self::PERIODO_MANHA,
        self::PERIODO_TARDE,
    ];

    public const ORIGEM_PORTAL = 'portal';

    public const ORIGEM_PAGINA_PUBLICA = 'pagina_publica';

    /**
     * @var array<int, string>
     */
    public const ORIGENS = [
        self::ORIGEM_PORTAL,
        self::ORIGEM_PAGINA_PUBLICA,
    ];

    protected $fillable = [
        'client_id',
        'client_user_id',
        'address_id',
        'nome',
        'email',
        'telefone',
        'endereco_texto',
        'service_type_id',
        'data_preferida',
        'periodo',
        'observacao',
        'situacao',
        'work_order_id',
        'motivo_recusa',
        'respondida_por',
        'respondida_em',
        'origem',
    ];

    protected $casts = [
        // Dia sem hora relevante: nunca sofre conversão de fuso.
        'data_preferida' => 'date',
        // Instante da resposta da empresa: gravado em UTC, convertido para o
        // fuso do negócio só na exibição, via BusinessDate.
        'respondida_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Cliente já cadastrado que fez o pedido, quando existe.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Usuário do portal que fez o pedido, quando o pedido veio do portal.
     */
    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class);
    }

    /**
     * Endereço cadastrado do pedido, quando informado.
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    /**
     * Tipo de serviço solicitado, quando informado.
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    /**
     * OS gerada ao confirmar o pedido.
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Usuário do tenant que confirmou ou recusou o pedido, quando ainda
     * existe.
     */
    public function respondidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'respondida_por');
    }
}
