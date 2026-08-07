<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use RuntimeException;

/**
 * Nota fiscal de serviço emitida por um tenant.
 *
 * Cancelamento e substituição alteram a situação e preservam a linha. A
 * exclusão pelo model é bloqueada porque a nota tem valor perante o fisco.
 */
class ServiceInvoice extends Model
{
    use Auditavel;
    use BelongsToCompany;

    public const SITUACOES = ['pendente', 'processando', 'emitida', 'cancelamento_pendente', 'cancelada', 'substituida', 'erro'];

    protected $fillable = [
        'client_id',
        'fiscal_config_id',
        'address_id',
        'work_order_id',
        'receivable_id',
        'numero',
        'codigo_verificacao',
        'situacao',
        'valor_servico',
        'valor_iss',
        'valor_liquido',
        'descricao_servico',
        'competencia',
        'emitida_em',
        'cancelada_em',
        'cancelamento_solicitado_em',
        'cancelamento_provedor_id',
        'motivo_cancelamento',
        'motivo_substituicao',
        'substituida_por_id',
        'reprocessada_por_id',
        'provedor_id',
        'referencia_provedor',
        'payload_dps',
        'metadados_substituicao',
        'pdf_path',
        'xml_path',
        'erro_mensagem',
        'erro_temporario',
        'tentativas',
        'proxima_tentativa_em',
        'ultima_tentativa_em',
        'processamento_token',
        'processamento_bloqueado_ate',
    ];

    /** Campos operacionais e mensagens externas não pertencem ao histórico fiscal. */
    protected array $naoAuditar = [
        'erro_mensagem',
        'erro_temporario',
        'proxima_tentativa_em',
        'ultima_tentativa_em',
        'processamento_token',
        'processamento_bloqueado_ate',
        'payload_dps',
        'metadados_substituicao',
    ];

    protected $casts = [
        'valor_servico' => 'decimal:2',
        'valor_iss' => 'decimal:2',
        'valor_liquido' => 'decimal:2',
        'competencia' => 'date',
        'emitida_em' => 'datetime',
        'cancelada_em' => 'datetime',
        'cancelamento_solicitado_em' => 'datetime',
        'tentativas' => 'integer',
        'erro_temporario' => 'boolean',
        'proxima_tentativa_em' => 'datetime',
        'ultima_tentativa_em' => 'datetime',
        'processamento_bloqueado_ate' => 'datetime',
        'payload_dps' => 'array',
        'metadados_substituicao' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new RuntimeException('Nota fiscal de serviço não pode ser excluída.');
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function fiscalConfig(): BelongsTo
    {
        return $this->belongsTo(FiscalConfig::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    /** Nota nova que substituiu esta. */
    public function substituidaPor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'substituida_por_id');
    }

    /** Nota anterior substituída por esta. */
    public function notaSubstituida(): HasOne
    {
        return $this->hasOne(self::class, 'substituida_por_id');
    }

    /** Nota gerada pela nova tentativa técnica desta pendência. */
    public function reprocessadaPor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reprocessada_por_id');
    }

    /** Pendências antigas resolvidas tecnicamente por esta nota. */
    public function pendenciasReprocessadas(): HasMany
    {
        return $this->hasMany(self::class, 'reprocessada_por_id');
    }
}
