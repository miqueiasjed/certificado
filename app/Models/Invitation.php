<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Convite de um novo usuário para entrar na empresa.
 *
 * O token é o que autoriza o cadastro de quem foi convidado: 64 caracteres
 * gerados com `Str::random(64)`, nunca previsível a partir do e-mail ou do
 * id, e garantido único pela unique do banco. Convite sem prazo seria uma
 * porta de entrada aberta para sempre num e-mail vazado, por isso
 * `expira_em` é obrigatório, comparado por dia no fuso do negócio (ver
 * `estaExpirado()`), e o padrão de 7 dias fica a critério do Service que cria
 * o convite, não deste model.
 *
 * `aceito_em` e `cancelado_em` marcam o desfecho do convite; enquanto os dois
 * estiverem nulos e a data não tiver passado, o convite está pendente (ver
 * `scopePendentes()`). Um mesmo e-mail pode ter mais de um convite ao longo
 * do tempo (reenvio depois de expirar, por exemplo); o que o Service garante
 * é que não exista mais de um convite pendente por e-mail na mesma empresa.
 *
 * Leva `BelongsToCompany`: convite pertence à empresa que convidou.
 */
class Invitation extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'email',
        'nome',
        'papel',
        'token',
        'convidado_por',
        'expira_em',
        'aceito_em',
        'cancelado_em',
    ];

    protected $casts = [
        // Dia sem hora relevante: convite expira por dia no fuso do negócio,
        // nunca por instante. Ver estaExpirado().
        'expira_em' => 'date',
        // Instantes: gravados em UTC, convertidos para o fuso do negócio na
        // exibição.
        'aceito_em' => 'datetime',
        'cancelado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Gera o token do convite ao criar, quando ninguém informou um. Token já
     * preenchido nunca é sobrescrito, para reenvio explícito de um token
     * específico continuar possível.
     */
    protected static function booted(): void
    {
        static::creating(function (self $convite): void {
            if (filled($convite->token)) {
                return;
            }

            $convite->token = Str::random(64);
        });
    }

    /**
     * Usuário que enviou o convite, quando ainda existe.
     */
    public function convidadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'convidado_por');
    }

    /**
     * Convites ainda válidos: não aceitos, não cancelados e não expirados.
     */
    public function scopePendentes(Builder $consulta): Builder
    {
        return $consulta
            ->whereNull('aceito_em')
            ->whereNull('cancelado_em')
            ->where('expira_em', '>=', BusinessDate::hoje()->toDateString());
    }

    /**
     * O convite já expirou? Comparação por dia no fuso do negócio: convite
     * que expira hoje ainda não está expirado, mesmo às 21h de Brasília.
     */
    public function estaExpirado(): bool
    {
        return BusinessDate::estaVencido($this->expira_em);
    }
}
