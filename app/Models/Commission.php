<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Comissão apurada de uma pessoa em uma competência (Plano 23). É de um
 * vendedor (`user_id`) ou de um técnico (`technician_id`), nunca das duas
 * pessoas na mesma linha; qual delas vem preenchida decide a apuração
 * (`CommissionCalculationService`, Task 23.2), a partir do `tipo` da regra
 * aplicada.
 *
 * `situacao` some por `aberta` (ainda recebe reapuração), `fechada` (imutável,
 * só reabre com permissão própria e justificativa auditada) e `paga` (gerou ou
 * recebeu o vínculo com o título a pagar do Plano 18, em `payable_id`).
 *
 * Unique `[company_id, user_id, technician_id, competencia]` composta por uma
 * unique funcional no banco, não por uma unique comum: o motivo completo está
 * no cabeçalho da migration `create_commission_tables`.
 */
class Commission extends Model
{
    use BelongsToCompany;

    /**
     * Situações aceitas, iguais ao enum da coluna `situacao`.
     *
     * @var array<int, string>
     */
    public const SITUACOES = ['aberta', 'fechada', 'paga'];

    protected $fillable = [
        'user_id',
        'technician_id',
        'competencia',
        'situacao',
        'valor_total',
        'fechada_em',
        'fechada_por',
        'paga_em',
        'payable_id',
    ];

    protected $casts = [
        'valor_total' => 'decimal:2',
        // Instantes: o momento em que o fechamento e o pagamento aconteceram.
        'fechada_em' => 'datetime',
        'paga_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Vendedor desta comissão, quando é uma comissão de vendedor.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Técnico desta comissão, quando é uma comissão de técnico.
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /**
     * Usuário que fechou esta comissão.
     */
    public function fechadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fechada_por');
    }

    /**
     * Título a pagar (Plano 18) gerado quando esta comissão foi marcada como
     * paga, quando houver.
     */
    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }

    /**
     * Itens que compõem o valor total desta comissão.
     */
    public function items(): HasMany
    {
        return $this->hasMany(CommissionItem::class);
    }
}
