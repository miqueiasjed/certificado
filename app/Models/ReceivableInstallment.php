<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Parcela de um título a receber (Plano 18): onde vivem o valor, o
 * vencimento e a situação, um por parcela.
 *
 * `payment_detail_id` existe só para a Task 18.2 amarrar o dado que o
 * cliente já usa (`payment_details`) à parcela nova, sem apagar nada do
 * atual. `financial_entry_id` aponta para o ÚLTIMO lançamento de caixa
 * gerado por esta parcela: a entrada criada pela baixa (Task 18.4,
 * `SettlementService::baixar()`), a saída criada pelo estorno
 * (`SettlementService::estornar()`) ou o lançamento da própria migração.
 * Guardar o último, e não o primeiro, é o que impede uma parcela sem
 * recebimento nenhum de continuar apontando para uma entrada de caixa; o
 * histórico completo dos lançamentos da parcela vive em `financial_entries`,
 * pelo `reference_number` ("RECEB-<id>-..." e "ESTORNO-<id>-...").
 *
 * `vencimento` e `pago_em` são dia sem hora relevante, e por isso nunca
 * sofrem conversão de fuso; a comparação de vencido é feita por dia no fuso
 * do negócio, via `App\Support\BusinessDate`, nunca com `now()` em UTC (uma
 * parcela que vence hoje não está vencida às 21h de Brasília). Essa
 * comparação é regra de negócio das Tasks 18.4/18.6, não deste model.
 */
class ReceivableInstallment extends Model
{
    use BelongsToCompany;

    /**
     * Situações aceitas, iguais ao enum da coluna `situacao`.
     *
     * @var array<int, string>
     */
    public const SITUACOES = ['aberta', 'parcial', 'paga', 'vencida', 'cancelada'];

    protected $fillable = [
        'receivable_id',
        'numero',
        'valor',
        'vencimento',
        'valor_pago',
        'pago_em',
        'situacao',
        'payment_detail_id',
        'financial_entry_id',
    ];

    protected $casts = [
        'numero' => 'integer',
        'valor' => 'decimal:2',
        'valor_pago' => 'decimal:2',
        // Dias sem hora relevante: nunca sofrem conversão de fuso.
        'vencimento' => 'date',
        'pago_em' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Título a que esta parcela pertence.
     */
    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    /**
     * Registro de pagamento atual (`payment_details`) vinculado pela
     * migração da Task 18.2.
     */
    public function paymentDetail(): BelongsTo
    {
        return $this->belongsTo(PaymentDetail::class);
    }

    /**
     * Lançamento de caixa gerado pela baixa desta parcela.
     */
    public function financialEntry(): BelongsTo
    {
        return $this->belongsTo(FinancialEntry::class);
    }

    /**
     * Cobranças (boleto ou PIX, Plano 19) emitidas para esta parcela ao longo
     * do tempo. Pode haver mais de uma (boleto vencido e reemitido); qual
     * delas está ativa é regra da Task 19.3, não deste relacionamento.
     */
    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }
}
