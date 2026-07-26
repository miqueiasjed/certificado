<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Texto que a empresa usa para avisar em um evento, por canal.
 *
 * Um registro por (`empresa`, `evento`, `canal`), garantido pelo unique da
 * tabela. Sem template do tenant, o envio cai no texto padrão do sistema
 * (Task 14.2): a ausência de template nunca impede o aviso de sair.
 *
 * Leva `Auditavel` porque o corpo daqui é o que chega ao cliente final assinado
 * pela empresa. Quando um aviso sair com o texto errado, a pergunta será quem
 * mudou e quando, e o `audit_logs` é o que responde.
 */
class NotificationTemplate extends Model
{
    use Auditavel, BelongsToCompany;

    protected $fillable = [
        'evento',
        'canal',
        'assunto',
        'corpo',
        'ativo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        // Instantes: gravados em UTC, convertidos para o fuso do negócio na
        // exibição, via BusinessDate.
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Apenas os templates em uso. Template desligado continua guardado, para a
     * empresa voltar atrás sem reescrever o texto.
     */
    public function scopeAtivos(Builder $consulta): Builder
    {
        return $consulta->where('ativo', true);
    }
}
