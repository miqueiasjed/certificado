<?php

namespace App\Models;

use App\Support\DominioMultiempresa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Referência normativa citada nos documentos emitidos (Plano 24, Task 24.1).
 *
 * A RDC nº 52/2009 foi substituída pela RDC nº 622/2022, e a próxima resolução
 * virá. Manter o texto legal em constante de código exigiria alteração de
 * sistema a cada publicação da Anvisa, então ele é dado: a plataforma mantém a
 * referência padrão e o tenant pode sobrescrevê-la.
 *
 * Deliberadamente **sem** a trait `BelongsToCompany`
 * -------------------------------------------------
 * `company_id` é **nullable** aqui, e `null` não é falha de preenchimento: é a
 * referência padrão da plataforma, a que vale para todo tenant que não
 * cadastrou a própria. O escopo global da trait filtra
 * `company_id = <tenant corrente>`, o que esconderia exatamente essa linha e
 * deixaria o documento emitido sem referência normativa nenhuma — o oposto do
 * que este plano existe para resolver.
 *
 * Mesmo critério já registrado para `GatewayEvent`/`Invoice`/`Subscription`: a
 * classificação está em `App\Support\DominioMultiempresa::MODELS_FORA_DO_ESCOPO`
 * e a tabela em `TABELAS_FORA_DO_ESCOPO`, e o teste da Task 4.10 cobra as duas
 * listas.
 *
 * Sem o escopo global, o isolamento entre empresas passa a depender de quem
 * consulta, e por isso **toda leitura deste model é feita por
 * `daEmpresa()`/`resolver()`**, que filtram explicitamente pelo tenant
 * informado mais o padrão da plataforma. Não há consulta a `NormativeReference`
 * sem um desses dois no caminho.
 */
class NormativeReference extends Model
{
    /**
     * Chave da referência principal: a resolução da Anvisa que rege o
     * funcionamento das empresas especializadas em controle de vetores e
     * pragas urbanas, citada no certificado, na OS, no contrato e no recibo.
     */
    public const CHAVE_PRINCIPAL = 'rdc_principal';

    protected $fillable = [
        'company_id',
        'chave',
        'texto',
        'texto_curto',
        'vigente_desde',
        'ativo',
    ];

    protected $casts = [
        // Dia em que a resolução passou a valer: campo `date`, sem hora.
        'vigente_desde' => 'date',
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Tenant dono desta referência. Nulo na referência padrão da plataforma.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, DominioMultiempresa::COLUNA_TENANT);
    }

    /**
     * Referências visíveis a uma empresa: as dela mais as da plataforma.
     *
     * Este scope é o substituto explícito do escopo global que o model não
     * tem. `$empresa` nulo devolve apenas as da plataforma, que é o que faz
     * sentido em comando artisan e seeder sem tenant resolvido.
     */
    public function scopeDaEmpresa(Builder $consulta, ?int $empresa): Builder
    {
        return $consulta->where(function (Builder $filtro) use ($empresa): void {
            $filtro->whereNull(DominioMultiempresa::COLUNA_TENANT);

            if ($empresa !== null) {
                $filtro->orWhere(DominioMultiempresa::COLUNA_TENANT, $empresa);
            }
        });
    }

    /**
     * Referência vigente de uma chave para uma empresa.
     *
     * A do tenant tem prioridade sobre a da plataforma: a ordenação coloca
     * `company_id` preenchido antes do nulo, e o `first()` fica com a primeira.
     * Só considera as ativas, para que a resolução revogada possa continuar
     * guardada ao lado da vigente sem ser escolhida.
     *
     * Devolve `null` quando não existe referência alguma, inclusive a da
     * plataforma. Quem imprime documento trata esse caso omitindo a linha, e
     * nunca imprimindo texto legal chutado.
     */
    public static function resolver(?int $empresa, string $chave = self::CHAVE_PRINCIPAL): ?self
    {
        return static::query()
            ->daEmpresa($empresa)
            ->where('chave', $chave)
            ->where('ativo', true)
            ->orderByRaw(DominioMultiempresa::COLUNA_TENANT.' is null')
            ->first();
    }
}
