<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pedido de assinatura eletrônica de um contrato (Plano 26, Task 26.1). O
 * envio ao provedor, a evolução da situação e o arquivamento do documento
 * assinado são regra de negócio da Task 26.3, não deste model.
 *
 * Um contrato pode acumular pedidos ao longo do tempo (um cancelado e outro
 * criado depois), mas só um em aberto por vez: dois pedidos abertos do mesmo
 * contrato produziriam duas assinaturas válidas de textos possivelmente
 * diferentes. Essa garantia é validação no Service, com apoio de
 * `SITUACOES_EM_ABERTO`, não restrição de banco.
 *
 * `provedor_documento_id` é único por `[company_id, provedor]`: dois tenants
 * no mesmo provedor podem coincidir no identificador do documento. Ele é nulo
 * enquanto o pedido é `rascunho`, porque só existe depois da resposta do
 * provedor.
 */
class SignatureRequest extends Model
{
    use BelongsToCompany;

    /**
     * Situações aceitas, iguais ao enum da coluna `situacao`.
     *
     * @var array<int, string>
     */
    public const SITUACOES = [
        'rascunho',
        'enviado',
        'visualizado',
        'assinado',
        'recusado',
        'expirado',
        'cancelado',
    ];

    /**
     * Situações em que o pedido ainda está vivo e ocupa o contrato: enquanto
     * uma delas valer, o contrato não aceita um segundo pedido nem edição do
     * texto.
     *
     * @var array<int, string>
     */
    public const SITUACOES_EM_ABERTO = ['rascunho', 'enviado', 'visualizado'];

    /**
     * Situações finais: o pedido não muda mais sozinho e não bloqueia mais o
     * contrato.
     *
     * @var array<int, string>
     */
    public const SITUACOES_FINAIS = ['assinado', 'recusado', 'expirado', 'cancelado'];

    protected $fillable = [
        'contract_id',
        'provedor',
        'provedor_documento_id',
        'situacao',
        'enviado_em',
        'expira_em',
        'concluido_em',
        'arquivo_original_path',
        'arquivo_assinado_path',
        'motivo_recusa',
        'criado_por',
    ];

    protected $casts = [
        // Instantes.
        'enviado_em' => 'datetime',
        'concluido_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        // Dia sem hora relevante: o prazo é contado por dia no fuso do
        // negócio, nunca por instante.
        'expira_em' => 'date',
    ];

    /**
     * Contrato que este pedido põe em assinatura.
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Usuário que disparou o pedido. Parte da trilha de auditoria: a FK é
     * `restrictOnDelete`, então esta relação nunca fica órfã.
     */
    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    /**
     * Signatários do pedido, na ordem em que devem assinar.
     */
    public function signers(): HasMany
    {
        return $this->hasMany(SignatureSigner::class)->orderBy('ordem')->orderBy('id');
    }

    /**
     * O pedido ainda está vivo?
     */
    public function estaEmAberto(): bool
    {
        return in_array($this->situacao, self::SITUACOES_EM_ABERTO, true);
    }
}
