<?php

namespace App\Jobs\Middleware;

use Closure;
use RuntimeException;

/**
 * Middleware de job que aplica o tenant do payload em volta do `handle()`.
 *
 * É o que evita depender da memória de quem escreve o job: em vez de cada
 * `handle()` precisar lembrar de embrulhar o próprio corpo, o embrulho é feito
 * aqui, uma vez, para todo job que usa `App\Jobs\Concerns\CarregaTenant`.
 *
 * Vale para fila de verdade e para a conexão `sync`: os dois caminhos passam
 * por `CallQueuedHandler::dispatchThroughMiddleware()`. O que não passa é
 * `dispatchNow()`, e isso está documentado na trait.
 *
 * A restauração do tenant é do `comTenant()` chamado por `noTenantDoJob()`, que
 * usa `try/finally`. Job que falha devolve o tenant anterior do mesmo jeito que
 * job que termina bem, e é isso que impede o vazamento para o job seguinte do
 * mesmo worker.
 */
class AplicaTenantDoJob
{
    /**
     * @param  object  $job  instância do job em processamento
     */
    public function handle(object $job, Closure $next): mixed
    {
        if (! method_exists($job, 'noTenantDoJob')) {
            throw new RuntimeException(sprintf(
                'O job %s declara o middleware %s mas não usa a trait %s, '
                .'então não há company_id no payload para aplicar.',
                $job::class,
                self::class,
                \App\Jobs\Concerns\CarregaTenant::class
            ));
        }

        return $job->noTenantDoJob(fn (): mixed => $next($job));
    }
}
