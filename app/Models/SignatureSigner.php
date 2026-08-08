<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Signatário de um pedido de assinatura eletrônica (Plano 26, Task 26.1).
 *
 * `ip`, `user_agent` e `assinado_em` são a trilha de auditoria que dá valor
 * jurídico à assinatura a distância, e são a diferença entre isto e a coleta
 * por toque na tela do Plano 13 (`WorkOrderSignature`), onde o cliente está
 * presente e o que se comprova é o recebimento do serviço. Os três chegam do
 * provedor, no webhook ou na sincronização (Task 26.3), e nunca são inferidos
 * da requisição de quem abre a tela.
 *
 * `ordem` existe porque alguns contratos exigem que a contratada assine antes
 * de o documento chegar ao cliente.
 *
 * Carrega `company_id` apesar de ser filha de `signature_requests`: é
 * consultada direto na sincronização e no webhook, quando o pedido ainda não
 * foi carregado, então o escopo do pai não é atravessado e o escopo global é a
 * única defesa. Mesmo critério já aplicado a `WorkOrderPhoto` e
 * `CommissionItem`.
 */
class SignatureSigner extends Model
{
    use BelongsToCompany;

    /**
     * Papéis aceitos, iguais ao enum da coluna `papel`.
     *
     * @var array<int, string>
     */
    public const PAPEIS = ['contratante', 'contratada', 'testemunha'];

    /**
     * Situações aceitas, iguais ao enum da coluna `situacao`.
     *
     * @var array<int, string>
     */
    public const SITUACOES = ['pendente', 'visualizou', 'assinou', 'recusou'];

    protected $fillable = [
        'signature_request_id',
        'nome',
        'email',
        'documento',
        'papel',
        'ordem',
        'situacao',
        'assinado_em',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'ordem' => 'integer',
        // Instante em que a assinatura foi coletada pelo provedor.
        'assinado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Pedido a que este signatário pertence.
     */
    public function signatureRequest(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class);
    }
}
