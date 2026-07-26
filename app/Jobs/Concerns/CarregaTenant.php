<?php

namespace App\Jobs\Concerns;

use App\Jobs\Middleware\AplicaTenantDoJob;
use App\Support\TenantAtual;
use Closure;
use RuntimeException;

/**
 * Tenant no payload do job.
 *
 * Um job é enfileirado dentro de uma requisição, que tem empresa, e processado
 * minutos depois em um worker, que não tem nenhuma. Se o tenant for resolvido
 * no consumo, ele será `null` (e o job grava sem empresa) ou, pior, será o que
 * sobrou do job anterior no mesmo processo. Por isso a empresa viaja **no
 * payload**: ela é capturada no momento do despacho e reaplicada no consumo,
 * sem depender de nada do ambiente do worker.
 *
 * Como usar em um job novo:
 *
 * ```php
 * class EnviarCertificadoPorEmail implements ShouldQueue
 * {
 *     use Queueable, CarregaTenant;
 *
 *     public function __construct(public int $certificateId)
 *     {
 *         $this->capturarTenantAtual();
 *     }
 *
 *     public function handle(): void
 *     {
 *         // já roda dentro do tenant do payload
 *     }
 * }
 * ```
 *
 * Duas coisas acontecem sozinhas depois disso:
 *
 * 1. `$companyId` é uma propriedade pública, então entra no `serialize()` do
 *    job e viaja no payload da fila, como qualquer outro dado do construtor.
 * 2. `middleware()` devolve `AplicaTenantDoJob`, e o `CallQueuedHandler` do
 *    Laravel envolve o `handle()` nele. Não é preciso lembrar de embrulhar o
 *    corpo do `handle()`: o embrulho é do middleware, com `try/finally`.
 *
 * O que ainda exige disciplina, e por que:
 *
 * - **Chamar `capturarTenantAtual()` no construtor.** Trait não consegue
 *   interceptar o construtor do job, e capturar o tenant mais tarde seria
 *   capturar o tenant errado. Esquecer não vaza dado: o job explode na
 *   primeira execução com a mensagem de `tenantDoJob()`, porque `$companyId`
 *   fica nulo. Falha alta e visível é o comportamento desejado.
 * - **Despachar com `dispatch()` ou `dispatchSync()`.** `dispatchNow()` e
 *   `Bus::dispatchNow()` não passam pelo middleware de job, e nesse caminho o
 *   `handle()` rodaria no tenant do ambiente. Quando não houver alternativa,
 *   embrulhe o corpo do `handle()` em `$this->noTenantDoJob(fn () => ...)`;
 *   aninhar com o middleware é seguro, `comTenant()` empilha e desempilha.
 *
 * Job antigo, enfileirado antes deste deploy, tem payload sem `companyId`. Ao
 * ser processado depois, a propriedade fica no valor padrão (`null`), o job
 * falha em `tenantDoJob()` com a mensagem explicando o caso e vai para
 * `failed_jobs`, de onde pode ser reenfileirado depois. Rodar no tenant errado
 * seria a alternativa, e é a única que não dá para desfazer.
 */
trait CarregaTenant
{
    /**
     * Empresa dona deste job, capturada no despacho.
     *
     * Pública de propósito: é assim que ela entra no `serialize()` do job e
     * viaja no payload da fila. Nula até `capturarTenantAtual()` ser chamada, e
     * nula em payload antigo, sem a propriedade.
     */
    public ?int $companyId = null;

    /**
     * Fixa no job a empresa corrente. Chamar no construtor, sempre.
     *
     * Usa `exigirId()`, não `id()`: despachar um job sem empresa resolvida é
     * bug, e a exceção acontece no despacho, onde há contexto para entender o
     * que faltou, em vez de horas depois dentro do worker.
     */
    public function capturarTenantAtual(): void
    {
        $this->companyId = TenantAtual::exigirId();
    }

    /**
     * Middlewares do job. O `CallQueuedHandler` chama este método e envolve o
     * `handle()` no que for devolvido aqui.
     *
     * Job que precisar dos próprios middlewares sobrescreve este método e
     * precisa incluir `AplicaTenantDoJob` na lista, ou perde o tenant.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new AplicaTenantDoJob];
    }

    /**
     * Empresa do job, exigindo que ela tenha viajado no payload.
     *
     * @throws RuntimeException quando o payload não trouxe a empresa
     */
    public function tenantDoJob(): int
    {
        if ($this->companyId === null || $this->companyId < 1) {
            throw new RuntimeException(sprintf(
                'O job %s foi processado sem company_id no payload. '
                .'Ou o construtor dele não chama capturarTenantAtual(), ou este payload foi enfileirado '
                .'antes do deploy que passou a gravar a empresa no job. '
                .'O job não roda: executar no tenant errado grava dado de uma empresa dentro de outra.',
                static::class
            ));
        }

        return $this->companyId;
    }

    /**
     * Executa o callback dentro do tenant do payload.
     *
     * O `try/finally` está em `TenantAtual::comTenant()`: o tenant anterior
     * volta mesmo quando o callback lança exceção, e o próximo job do mesmo
     * worker não herda nada deste.
     */
    public function noTenantDoJob(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->tenantDoJob(), $callback);
    }
}
