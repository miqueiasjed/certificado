<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relato de problema feito pelo botão "Relatar problema" do painel ou do
 * portal do cliente.
 *
 * Cada relato é repassado ao sistema central de suporte técnico pelo job
 * `App\Jobs\EnviarRelatoParaSistemaJob`, onde vira uma tarefa `bug`. A
 * conversa com quem relatou continua aqui: do sistema central volta só o id e
 * o link da tarefa (`sistema_task_id`, `sistema_task_url`), gravados com
 * `enviado_em` quando ele confirma o recebimento.
 *
 * Não confundir com `MonitoringReport`, que é o relatório técnico de
 * monitoramento entregue ao cliente.
 *
 * Quem relatou é um funcionário (`user_id`) ou um usuário do portal
 * (`client_user_id`), nunca os dois. Os dois vêm do usuário autenticado,
 * resolvidos por `ProblemReportService`, e nunca do corpo da requisição.
 */
class ProblemReport extends Model
{
    use BelongsToCompany;

    public const SITUACAO_NOVO = 'novo';

    public const SITUACAO_EM_ANALISE = 'em_analise';

    public const SITUACAO_RESOLVIDO = 'resolvido';

    /**
     * Situações aceitas, iguais ao enum da coluna `situacao`.
     *
     * @var array<int, string>
     */
    public const SITUACOES = [
        self::SITUACAO_NOVO,
        self::SITUACAO_EM_ANALISE,
        self::SITUACAO_RESOLVIDO,
    ];

    /**
     * Tetos de caracteres das colunas, iguais aos do contrato do sistema
     * central. Referenciados pelo FormRequest e pelo Service para os três
     * nunca divergirem.
     */
    public const LIMITE_TITULO = 200;

    public const LIMITE_URL = 500;

    public const LIMITE_USER_AGENT = 500;

    /**
     * `company_id` nunca entra aqui: a trait BelongsToCompany preenche
     * sozinha, a partir do tenant corrente.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'client_user_id',
        'titulo',
        'descricao',
        'pagina_url',
        'user_agent',
        'situacao',
        'sistema_task_id',
        'sistema_task_url',
        'enviado_em',
    ];

    protected $casts = [
        'sistema_task_id' => 'integer',
        // Instante, gravado em UTC.
        'enviado_em' => 'datetime',
    ];

    /**
     * Funcionário que relatou, quando o relato veio do painel.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Usuário do portal que relatou, quando o relato veio do portal do
     * cliente.
     */
    public function clientUser(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class);
    }

    /**
     * Identificador opaco de quem relatou, no formato que o sistema central
     * aceita em `reporter_ref`.
     *
     * Nunca nome, e-mail ou telefone: o dado pessoal fica neste sistema, e o
     * sistema central recusa esses campos com 422. O prefixo separa os dois
     * tipos de conta, que têm sequências de id independentes.
     */
    public function referenciaDeQuemRelatou(): ?string
    {
        if ($this->user_id !== null) {
            return 'user:'.$this->user_id;
        }

        if ($this->client_user_id !== null) {
            return 'cliente:'.$this->client_user_id;
        }

        return null;
    }

    /**
     * O sistema central já confirmou a tarefa deste relato?
     */
    public function foiEnviado(): bool
    {
        return $this->enviado_em !== null;
    }
}
