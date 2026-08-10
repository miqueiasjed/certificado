<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compromisso avulso: visita ou tarefa que entra na agenda e pode virar
 * parada de roteiro sem depender de uma OS já existente (Plano 30).
 *
 * Detalhes completos da decisão em `.claude/prd/operacao-campo.md`, seção
 * "Compromisso avulso, sem OS".
 *
 * Por que existe desacoplado de `WorkOrder`
 * ------------------------------------------
 * O sistema hoje só agenda e roteiriza `work_orders`. Casos reais não cabem
 * nisso: visita de orçamento antes de existir contrato, checagem de garantia
 * fora do ciclo de visitas do contrato, ou qualquer outro compromisso que o
 * técnico precisa ter no dia sem que uma OS nasça só para justificar a
 * entrada na agenda. Por isso `client_id`, `address_id` e `technician_id` são
 * nullable os três: nem todo compromisso tem cliente cadastrado, endereço
 * cadastrado ou técnico definido no momento em que é lançado. Quem decide o
 * que é obrigatório em cada operação concreta é o `FormRequest` da Task 30.3,
 * nunca este model.
 *
 * Por que `work_order_id` é promoção opcional, nunca obrigatória
 * ----------------------------------------------------------------
 * Um orçamento aprovado, por exemplo, pode originar uma OS de verdade; o
 * compromisso continua existindo como estava, só com o vínculo preenchido
 * pelo `CompromissoService` (Task 30.2). `work_order_id` nulo não é estado
 * incompleto, é o estado normal da maior parte dos compromissos: nem todo
 * orçamento vira contrato, nem toda garantia vira nova OS.
 *
 * `criado_por` nunca é preenchido a partir do corpo da requisição: quem
 * resolve isso é o `CompromissoService`, a partir do usuário autenticado,
 * mesmo critério de `PpeDelivery::user_id`.
 */
class Compromisso extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * Tipos aceitos, iguais ao enum da coluna `tipo`.
     *
     * @var array<int, string>
     */
    public const TIPOS = [
        'orcamento',
        'garantia',
        'retorno',
        'outro',
    ];

    /**
     * Situações aceitas, iguais ao enum da coluna `situacao`.
     *
     * O compromisso nasce `agendado` e o dia decide o resto. Não tem o fluxo
     * de confirmação de `AppointmentRequest`, que é fila de pedido do
     * cliente: o compromisso já nasce no calendário.
     *
     * @var array<int, string>
     */
    public const SITUACOES = [
        'agendado',
        'concluido',
        'cancelado',
    ];

    /**
     * `company_id` nunca entra aqui: a trait BelongsToCompany preenche
     * sozinha, a partir do tenant corrente.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'tipo',
        'titulo',
        'observacoes',
        'client_id',
        'address_id',
        'endereco_texto',
        'technician_id',
        'data',
        'hora_inicio',
        'hora_fim',
        'situacao',
        'work_order_id',
        'criado_por',
    ];

    protected $casts = [
        // Dia sem hora relevante: nunca sofre conversão de fuso.
        'data' => 'date',
        // hora_inicio e hora_fim são coluna TIME (hora do dia, sem data).
        // Ficam sem cast de propósito, mesmo critério de
        // ServiceOrder::start_time/end_time: 'datetime' faria o Eloquent
        // fabricar uma data e gravar "Y-m-d H:i:s" numa coluna que só
        // aceita hora.
    ];

    /**
     * Cliente já cadastrado do compromisso, quando existe.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Endereço cadastrado do compromisso, quando informado. Alternativa a
     * `endereco_texto`, para o endereço ainda não cadastrado.
     */
    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    /**
     * Técnico responsável pelo compromisso, quando já definido.
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /**
     * OS gerada a partir da promoção deste compromisso, quando existe.
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Usuário do tenant que lançou o compromisso, quando ainda existe.
     */
    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }
}
