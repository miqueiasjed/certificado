<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Regra de comissão: percentual ou valor fixo, por vendedor, por técnico ou
 * geral da empresa (quando `user_id` e `technician_id` ficam nulos), com
 * vigência (Plano 23).
 *
 * A regra vigente na data do fato é a que vale na apuração
 * (`CommissionCalculationService`, Task 23.2): alterar `valor` aqui nunca
 * recalcula a comissão de uma competência já fechada, porque o item apurado
 * (`CommissionItem`) grava o percentual/valor aplicado no momento, e não só a
 * referência a esta regra.
 *
 * `base` é `recebido` por padrão. Decisão de negócio deliberada: comissão paga
 * sobre venda que o cliente nunca pagou é problema conhecido do setor, e o
 * padrão do sistema protege a empresa. Quem prefere comissionar sobre o
 * vendido ou sobre o executado configura a regra para isso.
 */
class CommissionRule extends Model
{
    use BelongsToCompany;

    /**
     * Tipos de pessoa comissionada, iguais ao enum da coluna `tipo`.
     *
     * @var array<int, string>
     */
    public const TIPOS = ['vendedor', 'tecnico'];

    /**
     * Bases de cálculo aceitas, iguais ao enum da coluna `base`. `recebido` é
     * o padrão do sistema; ver o cabeçalho desta classe para o motivo.
     *
     * @var array<int, string>
     */
    public const BASES = ['recebido', 'vendido', 'executado'];

    /**
     * Formas de cálculo aceitas, iguais ao enum da coluna `forma`.
     *
     * @var array<int, string>
     */
    public const FORMAS = ['percentual', 'valor_fixo'];

    protected $fillable = [
        'user_id',
        'technician_id',
        'tipo',
        'base',
        'forma',
        'valor',
        'service_type_id',
        'vigencia_inicio',
        'vigencia_fim',
        'ativa',
    ];

    protected $casts = [
        'valor' => 'decimal:4',
        // Dias sem hora relevante: vigência da regra, nunca sofre conversão
        // de fuso.
        'vigencia_inicio' => 'date',
        'vigencia_fim' => 'date',
        'ativa' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Vendedor a que esta regra se aplica, quando a regra não é geral.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Técnico a que esta regra se aplica, quando a regra não é geral.
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /**
     * Tipo de serviço a que esta regra se restringe, quando houver.
     */
    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(ServiceType::class);
    }

    /**
     * Itens de comissão apurados com esta regra, guardados para auditoria
     * mesmo que a regra mude ou seja desativada depois.
     */
    public function commissionItems(): HasMany
    {
        return $this->hasMany(CommissionItem::class);
    }
}
