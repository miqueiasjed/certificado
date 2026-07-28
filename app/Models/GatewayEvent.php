<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Evento recebido do gateway de pagamento, gravado antes de qualquer
 * processamento, inclusive evento de tipo desconhecido.
 *
 * Deliberadamente **sem** a trait `BelongsToCompany` e sem `company_id`: o
 * evento chega do gateway antes de qualquer resolução de tenant (o corpo do
 * webhook é quem eventualmente identifica a assinatura ou a fatura envolvida,
 * dentro de `payload`), e é a plataforma que processa e concilia o evento, não
 * um tenant específico. A classificação está registrada em `App\Support\
 * DominioMultiempresa::MODELS_FORA_DO_ESCOPO`, e a tabela em
 * `TABELAS_FORA_DO_ESCOPO`; o teste da Task 4.10 cobra as duas coisas.
 *
 * `evento_id` é **unique**: é essa restrição no banco que torna o
 * processamento do webhook idempotente. Sem ela, dois envios simultâneos do
 * mesmo evento processam duas vezes. `payload` guarda o corpo inteiro do
 * evento, o que permite reprocessar depois de um bug sem depender do gateway
 * reenviar.
 */
class GatewayEvent extends Model
{
    protected $fillable = [
        'gateway',
        'evento_id',
        'tipo',
        'payload',
        'processado_em',
        'erro',
        'tentativas',
    ];

    protected $casts = [
        'payload' => 'array',
        'processado_em' => 'datetime',
        'tentativas' => 'integer',
    ];
}
