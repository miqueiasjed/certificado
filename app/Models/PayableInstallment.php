<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Parcela de um título a pagar (Plano 18): onde vivem o valor, o vencimento
 * e a situação, um por parcela. Espelha `ReceivableInstallment`, sem
 * `payment_detail_id`: o sistema atual registra só recebimento de cliente,
 * nunca pagamento a fornecedor, então não existe dado legado de pagável para
 * a Task 18.2 amarrar.
 *
 * `financial_entry_id` aponta para o lançamento de caixa (saída) criado pela
 * baixa da Task 18.5 (`PayableService`), pelo mesmo ponto único de
 * integração com o caixa usado do lado de receber.
 *
 * `vencimento` e `pago_em` são dia sem hora relevante, e por isso nunca
 * sofrem conversão de fuso; a comparação de vencido é regra de negócio das
 * Tasks 18.5/18.6, não deste model.
 */
class PayableInstallment extends Model
{
    use BelongsToCompany;

    /**
     * Situações aceitas, iguais ao enum da coluna `situacao`.
     *
     * @var array<int, string>
     */
    public const SITUACOES = ['aberta', 'parcial', 'paga', 'vencida', 'cancelada'];

    protected $fillable = [
        'payable_id',
        'numero',
        'valor',
        'vencimento',
        'valor_pago',
        'pago_em',
        'situacao',
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
    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }

    /**
     * Lançamento de caixa gerado pela baixa desta parcela.
     */
    public function financialEntry(): BelongsTo
    {
        return $this->belongsTo(FinancialEntry::class);
    }
}
