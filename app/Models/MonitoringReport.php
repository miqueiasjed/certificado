<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Relatório de monitoramento consolidado por período.
 *
 * `dados` guarda o retrato do período no momento da geração e nunca é
 * recalculado na leitura: um relatório já entregue ao cliente não pode mudar
 * depois, com dado corrigido em OS posterior à emissão.
 */
class MonitoringReport extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'client_id',
        'address_id',
        'periodo_inicio',
        'periodo_fim',
        'gerado_em',
        'gerado_por',
        'dados',
        'pdf_path',
        'publicado_no_portal',
    ];

    protected $casts = [
        'periodo_inicio' => 'date',
        'periodo_fim' => 'date',
        'gerado_em' => 'datetime',
        'dados' => 'array',
        'publicado_no_portal' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    /**
     * Usuário que gerou o relatório. FK aponta para `gerado_por`, e não para
     * o padrão `user_id`, porque é este o nome que a especificação da tabela
     * usa.
     */
    public function geradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gerado_por');
    }
}
