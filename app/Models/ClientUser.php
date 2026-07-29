<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Usuário do cliente final: quem acessa o portal do cliente (Plano 15).
 *
 * Tabela SEPARADA de `users`, de propósito, e não um papel a mais no usuário
 * existente: o cliente final não é funcionário da empresa, não recebe papel
 * nem permissão do Spatie Permission (ausência deliberada de `HasRoles`, ao
 * contrário de `App\Models\User`) e não pode aparecer em nenhuma listagem
 * interna de usuários. Compartilhar `users` faria toda consulta interna
 * precisar lembrar de excluir clientes, e a primeira que esquecesse vazaria
 * dado do cliente para dentro da operação do tenant.
 *
 * Estende `Illuminate\Foundation\Auth\User` (a mesma base de `App\Models\User`)
 * porque autentica de verdade: o guard `cliente` (config/auth.php) usa um
 * provider próprio apontando para esta classe.
 *
 * `password` nasce nulo: o acesso é criado por convite (`convite_token`,
 * `convite_expira_em`), e a senha só existe depois que o cliente passa pelo
 * primeiro acesso. O fluxo do convite é da Task 15.2; aqui só a coluna.
 *
 * Ao contrário de `User`, o escopo global de leitura por empresa continua
 * ligado normalmente (não sobrescreve `aplicaEscopoDeEmpresaNaLeitura()`): não
 * existe aqui o problema de reentrância que obriga `User` a abrir mão dele,
 * porque o guard `cliente` resolve o tenant do mesmo jeito que qualquer outro
 * model de domínio. Esse escopo só impede o vazamento entre empresas; toda
 * consulta feita a partir do portal do cliente ainda precisa ser escopada uma
 * segunda vez, pelo `client_id` do cliente autenticado, para não vazar entre
 * clientes da mesma empresa.
 *
 * Unique composto `[company_id, email]`: a mesma pessoa pode ser cliente de
 * duas empresas da plataforma, e isso é normal neste sistema.
 */
class ClientUser extends Authenticatable
{
    use BelongsToCompany;

    protected $fillable = [
        'client_id',
        'nome',
        'email',
        'password',
        'telefone',
        'ativo',
        'convite_token',
        'convite_expira_em',
    ];

    /**
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'convite_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'password' => 'hashed',
            'convite_expira_em' => 'datetime',
            'ultimo_acesso_em' => 'datetime',
            'email_verificado_em' => 'datetime',
        ];
    }

    /**
     * Cliente a que este acesso pertence.
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Solicitações de atendimento abertas por este usuário.
     */
    public function requests(): HasMany
    {
        return $this->hasMany(ClientRequest::class);
    }
}
