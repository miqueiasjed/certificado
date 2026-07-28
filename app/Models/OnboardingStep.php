<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Passo da trilha de configuração inicial do tenant.
 *
 * Uma linha por passo (`chave`) marca o progresso da empresa na trilha:
 * `concluido_em` quando o tenant termina aquele passo, `ignorado_em` quando
 * opta por pular. Os dois nulos significam passo ainda pendente. O catálogo
 * de chaves válidas e a ordem da trilha vivem no Service, não neste model.
 *
 * Leva `BelongsToCompany`: o progresso de onboarding pertence à empresa.
 */
class OnboardingStep extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'chave',
        'concluido_em',
        'ignorado_em',
    ];

    protected $casts = [
        // Instantes: gravados em UTC, convertidos para o fuso do negócio na
        // exibição.
        'concluido_em' => 'datetime',
        'ignorado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
