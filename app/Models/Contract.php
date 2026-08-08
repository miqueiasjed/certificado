<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\BelongsToCompany;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Contract extends Model
{
    use Auditavel, BelongsToCompany;

    protected $fillable = [
        'address_id',
        'contract_number',
        'start_date',
        'end_date',
        'service_value',
        'service_type',
        'visit_frequency',
        'visit_frequency_valor',
        'visit_frequency_unidade',
        'visit_count',
        'pest_target',
        'payment_method',
        'payment_details',
        'additional_clause',
        'jurisdiction',
        // Renovação (Task 23.1/23.4). Ver docblock da migration
        // `add_renovacao_to_contracts_table` para a decisão de cada coluna.
        'contrato_anterior_id',
        'renovado_em',
        'indice_reajuste',
        'percentual_reajuste',
        'situacao_renovacao',
        'motivo_nao_renovacao',
        // Alertas de vencimento (Task 23.5). Ver docblock da migration
        // `add_em_negociacao_em_to_contracts_table`.
        'em_negociacao_em',
    ];

    protected $casts = [
        // Dia sem hora relevante: vigência do contrato, nunca sofre conversão de fuso
        'start_date' => 'date',
        'end_date' => 'date',
        // Instante
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        // Instante em que a renovação foi processada, não um dia de vigência.
        'renovado_em' => 'datetime',
        // Idem: instante em que a negociação começou, não um dia de vigência.
        'em_negociacao_em' => 'datetime',
        'service_value' => 'decimal:2',
        'visit_frequency_valor' => 'integer',
        'percentual_reajuste' => 'decimal:2',
    ];

    /**
     * Mantém `em_negociacao_em` coerente com `situacao_renovacao` no ponto
     * único de gravação (Task 23.5), mesmo critério já usado em
     * `TechnicianTrackingSetting::booted()` e `FiscalConfig::booted()` para
     * invariante de dado que não pode depender só da disciplina de quem
     * chama: `ContractRenewalService`, os futuros endpoints da Task 23.6 e
     * qualquer código que venha a gravar `situacao_renovacao` direto não
     * precisam lembrar de tocar nesta coluna.
     *
     * - Entrando em `em_negociacao` sem a coluna já informada: carimba agora,
     *   no fuso do negócio. É a partir daqui que a pausa de 30 dias do aviso
     *   semanal (`VerificarContratosAVencer`) é contada.
     * - Saindo de `em_negociacao` para qualquer outro estado (`renovado`,
     *   `nao_renovado`, `pendente` ou de volta a `null`): limpa a coluna. Uma
     *   negociação encerrada não deixa resquício que confundisse uma
     *   negociação futura do mesmo contrato.
     */
    protected static function booted(): void
    {
        static::saving(function (self $contrato): void {
            if (! $contrato->isDirty('situacao_renovacao')) {
                return;
            }

            if ($contrato->situacao_renovacao === 'em_negociacao') {
                if ($contrato->em_negociacao_em === null) {
                    $contrato->em_negociacao_em = BusinessDate::agora();
                }

                return;
            }

            $contrato->em_negociacao_em = null;
        });
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /**
     * Contrato do qual este nasceu, quando este é resultado de uma renovação
     * (Task 23.4). É o passo de trás na cadeia de renovações; encadear
     * `contratoAnterior` sucessivamente navega do mais novo ao mais antigo.
     */
    public function contratoAnterior(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'contrato_anterior_id');
    }

    /**
     * Contrato que nasceu da renovação deste, quando ele já foi renovado. No
     * máximo um, porque cada contrato só é renovado uma vez: quando isso
     * acontece, `situacao_renovacao` vira `renovado` e uma nova renovação
     * dele é recusada (`ContractRenewalService::renovar`).
     */
    public function renovacao(): HasOne
    {
        return $this->hasOne(Contract::class, 'contrato_anterior_id');
    }

    public function generateContractNumber(): string
    {
        return 'CONT-' . str_pad($this->id, 6, '0', STR_PAD_LEFT) . '-' . date('Ymd');
    }

    /**
     * Converte a periodicidade (valor + unidade) para uma quantidade aproximada
     * de dias. Uso auxiliar (ex.: ordenação, exibição): o cálculo real das datas
     * de visita (Task 9.3) usa Carbon com a unidade explícita, nunca esta
     * aproximação, para não sofrer o desvio de meses com dias diferentes.
     */
    public function periodicidadeEmDias(): ?int
    {
        if (is_null($this->visit_frequency_valor) || is_null($this->visit_frequency_unidade)) {
            return null;
        }

        return match ($this->visit_frequency_unidade) {
            'dias' => $this->visit_frequency_valor,
            'semanas' => $this->visit_frequency_valor * 7,
            'meses' => $this->visit_frequency_valor * 30,
            default => null,
        };
    }
}
