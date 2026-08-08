<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Meta de uma pessoa em uma competência (Plano 23): valor vendido, valor
 * recebido, quantidade de OS ou taxa de conversão.
 *
 * O acompanhamento do atingimento (o que já foi realizado frente a `alvo`) é
 * regra de negócio da Task 23.3, não deste model.
 *
 * Unique `[company_id, user_id, tipo, competencia]`: a mesma pessoa não tem
 * duas metas do mesmo tipo na mesma competência.
 */
class Goal extends Model
{
    use BelongsToCompany;

    /**
     * Tipos de meta aceitos, iguais ao enum da coluna `tipo`.
     *
     * @var array<int, string>
     */
    public const TIPOS = ['valor_vendido', 'valor_recebido', 'quantidade_os', 'conversao'];

    protected $fillable = [
        'user_id',
        'tipo',
        'competencia',
        'alvo',
        'observacao',
    ];

    protected $casts = [
        'alvo' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Pessoa dona desta meta.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
