<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Registro do produto saneante desinfestante no Ministério da Saúde/Anvisa.
 *
 * A RDC nº 622/2022 só admite, na prestação do serviço, produto saneante
 * desinfestante **registrado** na Anvisa. Desde o Plano 24 (Task 24.1) o
 * registro guarda também `validade` e `situacao`, para o sistema poder avisar
 * quando o registro do produto aplicado está vencido ou cancelado (Task 24.4).
 *
 * `validade` nula é "não informado", nunca "vencido": o cadastro existente
 * inteiro nasceu sem a data, e acusar de irregular quem não preencheu
 * destruiria a confiança no checklist.
 */
class OrganRegistration extends Model
{
    use BelongsToCompany;

    public const SITUACAO_ATIVO = 'ativo';

    public const SITUACAO_VENCIDO = 'vencido';

    public const SITUACAO_CANCELADO = 'cancelado';

    /**
     * Situações aceitas, iguais ao enum da coluna `situacao`.
     *
     * `vencido` é derivado de `validade` pela rotina da Task 24.3.
     * `cancelado` só vem de fora (cancelamento publicado pela Anvisa) e por
     * isso não é inferível de data nenhuma.
     *
     * @var array<int, string>
     */
    public const SITUACOES = [
        self::SITUACAO_ATIVO,
        self::SITUACAO_VENCIDO,
        self::SITUACAO_CANCELADO,
    ];

    protected $fillable = [
        'record',
        'validade',
        'situacao',
    ];

    protected $casts = [
        // Dia em que o registro vence, sem hora relevante: `date`.
        'validade' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
