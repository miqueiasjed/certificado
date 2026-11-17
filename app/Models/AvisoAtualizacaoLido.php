<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de que uma pessoa leu um aviso de atualização.
 * O aviso em si vem do catálogo em `CatalogoAvisosAtualizacao`.
 */
class AvisoAtualizacaoLido extends Model
{
    use BelongsToCompany;

    protected $table = 'avisos_atualizacao_lidos';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'aviso_id',
        'lido_em',
    ];

    protected $casts = [
        // Instante
        'lido_em' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
