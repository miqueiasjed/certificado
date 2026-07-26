<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'email',
        'evento',
        'ip',
        'user_agent',
        'detalhes',
        'created_at',
    ];

    protected $casts = [
        'detalhes' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Usuário associado ao evento, quando identificado.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Registro de acesso é imutável: bloqueia exclusão e alteração pela aplicação.
     * O expurgo por retenção usa DB::table() direto, contornando este model.
     */
    protected static function booted(): void
    {
        static::deleting(function () {
            throw new \RuntimeException('Registro de acesso não pode ser excluído.');
        });

        static::updating(function () {
            throw new \RuntimeException('Registro de acesso não pode ser alterado.');
        });
    }
}
