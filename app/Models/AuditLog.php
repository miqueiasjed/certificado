<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use BelongsToCompany;

    const UPDATED_AT = null;

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'acao',
        'user_id',
        'autor_nome',
        'valores_antes',
        'valores_depois',
        'ip',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'valores_antes' => 'array',
        'valores_depois' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Registro auditado (polimórfico).
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Usuário responsável pela ação, quando identificado.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Registro de auditoria é imutável: bloqueia exclusão e alteração pela aplicação.
     * O expurgo por retenção usa DB::table() direto, contornando este model.
     */
    protected static function booted(): void
    {
        static::deleting(function () {
            throw new \RuntimeException('Registro de auditoria não pode ser excluído.');
        });

        static::updating(function () {
            throw new \RuntimeException('Registro de auditoria não pode ser alterado.');
        });
    }
}
