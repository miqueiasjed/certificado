<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pesquisa de satisfação disparada após a OS concluída (Plano 16), respondida
 * pelo cliente sem login através do `token`.
 *
 * Uma linha por OS (`work_order_id` com `unique`): duas pesquisas da mesma
 * visita gerariam média torta no painel de satisfação.
 *
 * O `token` de 64 caracteres é o que permite a resposta sem autenticação, e
 * por isso precisa de espaço grande e validade (`expira_em`): token curto
 * seria adivinhável, e responder a pesquisa de outro cliente contamina o
 * indicador da empresa.
 */
class SatisfactionSurvey extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'work_order_id',
        'client_id',
        'technician_id',
        'service_type_id',
        'token',
        'enviada_em',
        'respondida_em',
        'nota',
        'comentario',
        'expira_em',
        'pendencia_de_contato',
        'contato_feito_em',
    ];

    protected $casts = [
        // Instantes: gravados em UTC, convertidos para o fuso do negócio só
        // na exibição, via BusinessDate.
        'enviada_em' => 'datetime',
        'respondida_em' => 'datetime',
        'contato_feito_em' => 'datetime',
        // Dia sem hora relevante: nunca sofre conversão de fuso.
        'expira_em' => 'date',
        'nota' => 'integer',
        'pendencia_de_contato' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * OS que originou a pesquisa.
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Cliente atendido na visita avaliada.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Técnico que atendeu a visita avaliada, quando ainda existe.
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /**
     * Tipo de serviço da visita avaliada, quando informado.
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }
}
