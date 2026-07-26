<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\DominioMultiempresa;
use App\Support\TenantAtual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isolamento por empresa no Eloquent.
 *
 * Faz duas coisas, e as duas são obrigatórias em todo model de domínio (a lista
 * fica em `App\Support\DominioMultiempresa::MODELS_DE_DOMINIO`):
 *
 * 1. **Escopo global de leitura**: toda consulta ganha
 *    `where <tabela>.company_id = <tenant corrente>`.
 * 2. **Preenchimento na criação**: `company_id` é preenchido com o tenant
 *    corrente quando o atributo não veio informado.
 *
 * Quem é o tenant corrente é decidido por `App\Support\TenantAtual`, nunca por
 * este arquivo.
 *
 * Quando o escopo não filtra
 * --------------------------
 * - Sem tenant resolvido (`TenantAtual::id()` devolve `null`): nada é filtrado.
 *   Deliberado, para migration, seeder e teste continuarem enxergando o banco.
 * - Dentro de `TenantAtual::semEscopo()`: a porta de emergência do super admin
 *   e das rotinas de plataforma. Está documentada lá.
 * - Em consulta que chama `deTodasAsEmpresas()`: saída explícita, registro a
 *   registro de código, para caso raro e justificado.
 *
 * Prefixo da coluna
 * -----------------
 * O filtro usa `qualifyColumn()`, ou seja, sempre `tabela.company_id`. Sem o
 * prefixo, qualquer `join` com outra tabela que também tenha `company_id`
 * quebraria com "column ambiguous", e como quase toda tabela de domínio tem a
 * coluna, isso apareceria em produção na primeira listagem com join.
 *
 * Model que não leva o escopo de leitura
 * --------------------------------------
 * `User` é a exceção declarada: o escopo global não pode valer para ele, porque
 * a resolução do tenant depende justamente do usuário autenticado. Filtrar
 * `users` por empresa antes de existir empresa impediria o próprio login de
 * encontrar o usuário, e o super admin do Plano 5 não pertence a empresa
 * nenhuma. Nesses casos o model sobrescreve
 * `aplicaEscopoDeEmpresaNaLeitura()` devolvendo `false` e continua recebendo o
 * preenchimento automático de `company_id` na criação. A aplicação da trait aos
 * models é a Task 4.8.
 *
 * Momento em que o escopo é avaliado
 * ----------------------------------
 * O Eloquent aplica escopo global no momento em que a consulta é executada, e
 * não quando o builder é criado. Na prática, o tenant lido é o que estiver
 * valendo na execução, o que é o que se espera dentro de
 * `TenantAtual::comTenant()`.
 */
trait BelongsToCompany
{
    /**
     * Identificador do escopo global. Referenciado por
     * `withoutGlobalScope(Modelo::ESCOPO_DE_EMPRESA)` fora daqui, para ninguém
     * repetir a string solta.
     */
    public const ESCOPO_DE_EMPRESA = 'company';

    /**
     * Liga o escopo global e o preenchimento automático assim que o model é
     * inicializado.
     */
    public static function bootBelongsToCompany(): void
    {
        if (static::aplicaEscopoDeEmpresaNaLeitura()) {
            static::addGlobalScope(self::ESCOPO_DE_EMPRESA, static function (Builder $consulta): void {
                if (! TenantAtual::escopoAtivo()) {
                    return;
                }

                $empresa = TenantAtual::id();

                if ($empresa === null) {
                    return;
                }

                $consulta->where(
                    $consulta->getModel()->qualifyColumn(DominioMultiempresa::COLUNA_TENANT),
                    $empresa
                );
            });
        }

        static::creating(static function (Model $model): void {
            $model->preencherEmpresaDoTenantAtual();
        });
    }

    /**
     * O escopo global de leitura vale para este model?
     *
     * Devolve `true` para todo model de domínio. Sobrescrever com `false` é
     * exceção declarada, e hoje só existe uma: `User`, pelo motivo explicado no
     * cabeçalho desta trait. Model novo que sobrescrever isto precisa do motivo
     * escrito no próprio método.
     */
    public static function aplicaEscopoDeEmpresaNaLeitura(): bool
    {
        return true;
    }

    /**
     * Empresa dona do registro.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, DominioMultiempresa::COLUNA_TENANT);
    }

    /**
     * Consulta sem o escopo por empresa, registro a registro de código.
     *
     * Uso explícito e raro, sempre com comentário justificando na chamada. Para
     * desligar o isolamento em um bloco inteiro, quem serve é
     * `TenantAtual::semEscopo()`.
     *
     * Exemplo: `Client::deTodasAsEmpresas()->count()`.
     */
    public function scopeDeTodasAsEmpresas(Builder $consulta): Builder
    {
        return $consulta->withoutGlobalScope(self::ESCOPO_DE_EMPRESA);
    }

    /**
     * Preenche `company_id` com o tenant corrente quando o atributo não veio.
     *
     * Duas decisões que importam:
     *
     * - `company_id` já informado é respeitado. Quem gravou de propósito no
     *   tenant X (backfill, importação, rotina de plataforma) manda mais que o
     *   contexto.
     * - Sem tenant resolvido, o atributo é deixado em paz em vez de gravar
     *   `null`. Antes do Deploy 5 a coluna ainda tem `DEFAULT 1` e o banco
     *   resolve; depois do Deploy 5 o insert falha, alto e visível, que é o
     *   comportamento desejado. Gravar `null` explícito quebraria os dois
     *   momentos, inclusive a janela em que o sistema roda em produção com o
     *   default vivo.
     */
    public function preencherEmpresaDoTenantAtual(): void
    {
        if (! blank($this->getAttribute(DominioMultiempresa::COLUNA_TENANT))) {
            return;
        }

        $empresa = TenantAtual::id();

        if ($empresa === null) {
            return;
        }

        $this->setAttribute(DominioMultiempresa::COLUNA_TENANT, $empresa);
    }
}
