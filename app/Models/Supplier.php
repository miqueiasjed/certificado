<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fornecedor (Plano 18): quem a empresa paga.
 *
 * `documento` fica nullable de propósito: nem todo fornecedor cadastrado tem
 * CNPJ ou CPF em mãos no momento do lançamento do título, e travar o cadastro
 * nisso adiaria o registro da despesa.
 */
class Supplier extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'nome',
        'documento',
        'email',
        'telefone',
        'observacao',
        'ativo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Títulos a pagar deste fornecedor.
     */
    public function payables(): HasMany
    {
        return $this->hasMany(Payable::class);
    }

    /**
     * Apenas fornecedores ativos.
     */
    public function scopeAtivos($consulta)
    {
        return $consulta->where('ativo', true);
    }
}
