<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Título a pagar (Plano 18): o que a empresa deve a um fornecedor. Espelha
 * `Receivable`, trocando o cliente pelo fornecedor, mais a recorrência para
 * despesa que se repete (aluguel, assinatura).
 *
 * O vencimento e o valor vivem nas parcelas (`PayableInstallment`), nunca
 * aqui, pelo mesmo motivo de `Receivable`: parcela única é caso particular,
 * não exceção.
 *
 * `recorrencia` diferente de `nenhuma` faz a Task 18.5 gerar a competência
 * seguinte até `recorrencia_ate`, mantendo sempre as próximas 3 competências
 * criadas; a geração em si é regra de negócio do `PayableService`, não deste
 * model.
 *
 * `payable_origem_id` (Task 18.5) aponta para o título raiz da série de
 * recorrência e fica `null` no próprio raiz. É o que permite achar a série
 * inteira (`id = raiz OR payable_origem_id = raiz`) sem percorrer uma corrente
 * de referências.
 *
 * `emitido_em` e `recorrencia_ate` são dia sem hora relevante, e por isso
 * nunca sofrem conversão de fuso.
 */
class Payable extends Model
{
    use BelongsToCompany;

    /**
     * Situações aceitas, iguais ao enum da coluna `situacao`.
     *
     * @var array<int, string>
     */
    public const SITUACOES = ['aberto', 'parcial', 'quitado', 'cancelado'];

    /**
     * Recorrências aceitas, iguais ao enum da coluna `recorrencia`.
     *
     * @var array<int, string>
     */
    public const RECORRENCIAS = ['nenhuma', 'mensal', 'trimestral', 'anual'];

    protected $fillable = [
        'supplier_id',
        'work_order_id',
        'contract_id',
        'chart_of_account_id',
        'descricao',
        'valor_total',
        'emitido_em',
        'situacao',
        'recorrencia',
        'recorrencia_ate',
        'payable_origem_id',
    ];

    protected $casts = [
        'valor_total' => 'decimal:2',
        // Dias sem hora relevante: nunca sofrem conversão de fuso.
        'emitido_em' => 'date',
        'recorrencia_ate' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Fornecedor cobrando este título.
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * OS que originou o título, quando houver (ex.: compra de produto do
     * Plano 17 gerando o título a pagar do fornecedor).
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
        return $this->hasMany(PayableInstallment::class);
    }

    /**
     * Título raiz da série de recorrência, quando este título foi gerado a
     * partir de outro (Task 18.5). `null` no próprio título raiz.
     */
    public function origem(): BelongsTo
    {
        return $this->belongsTo(Payable::class, 'payable_origem_id');
    }

    /**
     * Ocorrências geradas a partir deste título, quando ele é a raiz da série
     * de recorrência.
     */
    public function ocorrencias(): HasMany
    {
        return $this->hasMany(Payable::class, 'payable_origem_id');
    }
}
