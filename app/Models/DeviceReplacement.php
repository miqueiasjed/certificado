<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Troca de um dispositivo por outro no mesmo ponto de monitoramento.
 *
 * Uma linha liga o dispositivo que saiu (`deviceAnterior`) ao que assumiu o
 * ponto (`deviceNovo`), com o motivo, o dia e quem registrou. O dispositivo
 * anterior nunca é apagado: ele é o histórico daquele ponto, com os eventos que
 * gerou, e a continuidade da linha do tempo vem justamente desta cadeia.
 *
 * A regra de negócio da substituição (criar o dispositivo novo, marcar o
 * anterior, montar o histórico do ponto) vive em `DeviceReplacementService`.
 */
class DeviceReplacement extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'device_anterior_id',
        'device_novo_id',
        'motivo',
        'observacao',
        'substituido_em',
        'user_id',
    ];

    protected $casts = [
        // Dia da troca em campo: sem hora relevante, e por isso nunca passa por
        // conversão de fuso.
        'substituido_em' => 'date',
        // Instantes: gravados em UTC, convertidos para o fuso do negócio só na
        // exibição, via BusinessDate.
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Dispositivo que saiu do ponto.
     */
    public function deviceAnterior(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_anterior_id');
    }

    /**
     * Dispositivo que assumiu o ponto.
     */
    public function deviceNovo(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_novo_id');
    }

    /**
     * Usuário que registrou a substituição, quando ainda existe.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
