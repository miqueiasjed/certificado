<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\Auditavel;
use App\Models\Concerns\BelongsToCompany;
use App\Support\DominioMultiempresa;
use App\Support\TenantAtual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles, Auditavel, BelongsToCompany;

    /**
     * Campos que nunca vão para o log de auditoria, mesmo que a configuração
     * global mude: senha e token de sessão são o que mais importa blindar aqui.
     *
     * @var array<int, string>
     */
    protected array $naoAuditar = ['password', 'remember_token'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'cpf',
        'address',
        'specialty',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * O escopo global de leitura por empresa NÃO vale para o usuário.
     *
     * É a única exceção do sistema, e o motivo é circular: quem resolve o
     * tenant é `TenantAtual`, que lê o `company_id` do usuário autenticado, e
     * quem carrega o usuário autenticado é uma consulta a `users`. Ligar o
     * escopo aqui faria essa consulta perguntar o tenant antes de existir
     * tenant, e o guard de sessão não protege contra reentrância: resolver o
     * usuário chamaria o escopo, que chamaria `auth()->user()`, que voltaria a
     * resolver o usuário, até estourar a pilha. No login é ainda mais direto:
     * a busca por e-mail acontece antes de existir qualquer usuário
     * autenticado, então filtrar por empresa não teria empresa por onde
     * filtrar.
     *
     * O preenchimento de `company_id` na criação continua valendo: usuário
     * criado dentro de um tenant nasce nele. O que some é só o filtro
     * automático de leitura.
     *
     * A contrapartida é que consulta que LISTA usuários precisa filtrar a
     * empresa à mão, com `daEmpresaAtual()`. Sem isso, a tela de usuários de
     * uma empresa mostraria os usuários da outra.
     */
    public static function aplicaEscopoDeEmpresaNaLeitura(): bool
    {
        return false;
    }

    /**
     * Usuários da empresa corrente.
     *
     * Substitui à mão o escopo global que `aplicaEscopoDeEmpresaNaLeitura()`
     * desliga. Vale para toda consulta que lista ou conta usuários; não vale
     * (e não deve valer) para a autenticação, que é justamente o caso em que
     * ainda não há empresa resolvida.
     *
     * Sem tenant resolvido não filtra nada, igual ao escopo global: comando
     * artisan, seeder e migration continuam enxergando o banco inteiro.
     */
    public function scopeDaEmpresaAtual(Builder $consulta): Builder
    {
        $empresa = TenantAtual::id();

        if ($empresa === null) {
            return $consulta;
        }

        return $consulta->where(
            $this->qualifyColumn(DominioMultiempresa::COLUNA_TENANT),
            $empresa
        );
    }

    /**
     * Escopo por empresa aplicado a TODO route-model binding de `{user}`.
     *
     * Este é o ponto único que substitui, na fronteira da rota, o escopo global
     * que `aplicaEscopoDeEmpresaNaLeitura()` desliga. Sem ele, o binding de
     * `{user}` encontrava usuário de qualquer empresa e cada ação precisava
     * lembrar de conferir `company_id` na entrada; quem esquecesse abria a
     * porta para o administrador de uma empresa editar, desativar ou excluir o
     * administrador da outra.
     *
     * Por que aqui e não em cada controller: o Eloquent passa por este método
     * em toda resolução de binding, tanto do parâmetro solto (`{user}`) quanto
     * do aninhado com `scopeBindings()` (`{company}/{user}`). Rota nova que
     * receba `{user}` nasce protegida, sem depender de alguém repetir a
     * verificação. Usuário de outra empresa não é encontrado, e o Laravel
     * responde 404, que é a resposta da convenção: 403 já confirmaria que o id
     * existe.
     *
     * Por que não recursiona: binding é resolvido pelo middleware
     * `SubstituteBindings`, depois da autenticação, e o guard carrega o usuário
     * da sessão por `retrieveById()`, que não passa por aqui. A reentrância que
     * obriga `User` a viver sem escopo global não existe neste caminho.
     *
     * Sem empresa resolvida, nada é alcançável. Aqui, ao contrário de
     * `daEmpresaAtual()`, "sem tenant" não pode significar "sem filtro":
     * binding só acontece dentro de requisição HTTP, e requisição HTTP sem
     * empresa resolvida não tem por que alcançar usuário nenhum. Seeder,
     * migration e comando artisan não passam por este método e seguem
     * enxergando o banco inteiro.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<covariant static>|\Illuminate\Database\Eloquent\Relations\Relation<*, *, *>  $query
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $consulta = parent::resolveRouteBindingQuery($query, $value, $field);

        $empresa = TenantAtual::id();

        if ($empresa === null) {
            return $consulta->whereRaw('1 = 0');
        }

        return $consulta->where(
            $this->qualifyColumn(DominioMultiempresa::COLUNA_TENANT),
            $empresa
        );
    }

    /**
     * Empresa a que este usuário pertence.
     *
     * É por esta coluna que `App\Support\TenantAtual` resolve o tenant de toda
     * requisição autenticada, e por consequência é ela que `Company::current()`
     * passa a consultar. Usuário sem `company_id` não consegue emitir documento
     * nem abrir tela que dependa dos dados da empresa.
     *
     * A coluna ficou FORA do `$fillable`: quem preenche é a trait
     * `BelongsToCompany`, a partir do tenant corrente. Deixá-la preenchível
     * neste model em especial é o pior lugar possível para uma brecha de
     * atribuição em massa, porque é ela que decide de qual empresa o usuário
     * enxerga tudo. Criação explícita em outro tenant continua possível, e agora
     * fica visível no código: ou `TenantAtual::comTenant($empresa, fn () =>
     * User::create([...]))`, ou atribuir a coluna direto na instância antes de
     * salvar.
     *
     * O método `company()` sobrescreve o da trait para manter o tipo de retorno
     * declarado; o comportamento é o mesmo.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function serviceOrders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class, 'technician_id');
    }

    public function technician(): HasOne
    {
        return $this->hasOne(Technician::class, 'user_id');
    }

    public function ehTecnico(): bool
    {
        return $this->hasRole('tecnico');
    }
}
