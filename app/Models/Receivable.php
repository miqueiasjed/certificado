<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Título a receber (Plano 18): o que a empresa tem para cobrar de um
 * cliente, com o vencimento e o valor vivendo nas parcelas
 * (`ReceivableInstallment`), nunca aqui.
 *
 * Um título com parcela única é caso particular, não exceção: guardar o
 * vencimento no título forçaria duplicar o título inteiro a cada parcela de
 * um parcelamento.
 *
 * `work_order_id`, `contract_id` e `chart_of_account_id` são a origem
 * opcional do título (Task 18.3 gera o título a partir da OS ou do
 * contrato); o título sobrevive à exclusão de qualquer um dos três, só perde
 * o vínculo.
 *
 * `emitido_em` é dia sem hora relevante, e por isso nunca sofre conversão de
 * fuso: a comparação de vencimento acontece nas parcelas, por dia no fuso do
 * negócio, via `App\Support\BusinessDate`.
 */
class Receivable extends Model
{
    use BelongsToCompany;

    /**
     * Situações aceitas, iguais ao enum da coluna `situacao`.
     *
     * @var array<int, string>
     */
    public const SITUACOES = ['aberto', 'parcial', 'quitado', 'cancelado'];

    protected $fillable = [
        'client_id',
        'work_order_id',
        'contract_id',
        'chart_of_account_id',
        'descricao',
        'valor_total',
        'emitido_em',
        'situacao',
    ];

    protected $casts = [
        'valor_total' => 'decimal:2',
        // Dia sem hora relevante: nunca sofre conversão de fuso.
        'emitido_em' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Cliente cobrado por este título.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * OS que originou o título, quando houver.
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Contrato que originou o título, quando houver.
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Categoria do plano de contas em que este título é classificado.
     */
    public function chartOfAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class);
    }

    /**
     * Parcelas deste título.
     */
    public function installments(): HasMany
    {
        return $this->hasMany(ReceivableInstallment::class);
    }
}
