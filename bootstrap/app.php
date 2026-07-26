<?php

use App\Listeners\RegistraExecucaoAgendada;
use App\Support\RotinasAgendadas;
use Illuminate\Console\Scheduling\Event as TarefaAgendada;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        // Aliases dos middlewares do Spatie Permission. O pacote não os
        // registra sozinho no Laravel 11, e sem isto "permission:financeiro-ver"
        // na rota estoura como classe inexistente em vez de barrar o acesso.
        //
        // `platform.admin` não é do Spatie e não é permissão de empresa: ele
        // guarda o prefixo `/plataforma`, onde a consulta roda sem o escopo por
        // empresa e enxerga todos os tenants. Ver EnsurePlatformAdmin.
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'platform.admin' => \App\Http\Middleware\EnsurePlatformAdmin::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        // Pendura em uma rodada os ganchos que gravam a linha de
        // scheduled_task_runs.
        //
        // Rodada curta e o registro depende do resultado, por isso sem
        // runInBackground: em background o exitCode só chega em outro
        // processo, via schedule:finish.
        $auditar = function (TarefaAgendada $rotina): void {
            $rotina->before(function (RegistraExecucaoAgendada $auditoria) use ($rotina) {
                $auditoria->registrarInicio($rotina);
            });

            $rotina->then(function (RegistraExecucaoAgendada $auditoria) use ($rotina) {
                $auditoria->registrarFim($rotina);
            });
        };

        // Sem `schedule:run` no cron nada disto executa. A linha de cron está
        // documentada na seção "Rotinas agendadas" do README.
        foreach (RotinasAgendadas::DIARIAS as $comando => $horario) {
            $auditar($schedule->command($comando)
                ->dailyAt($horario)
                // Sem isto, 00:10 seria 00:10 em UTC, ou seja, 21:10 do dia
                // anterior em Brasília, e a rotina viraria o dia errado.
                ->timezone(config('app.business_timezone'))
                ->withoutOverlapping(RotinasAgendadas::MINUTOS_DE_TRAVA)
                // Guarda a saída em arquivo para que ela caia na coluna output.
                ->storeOutput());
        }

        // Rotinas por intervalo (Plano 14): o despacho da fila de notificações,
        // de 5 em 5 minutos, e a verificação de rotina parada, de hora em hora.
        // Sem `timezone()` aqui de propósito: a expressão só tem minutos, então
        // não existe hora para cair no fuso errado, e declarar um fuso sugeriria
        // uma janela do dia que estas rotinas não têm. A trava é a curta: com a
        // rotina rodando de 5 em 5 minutos, os 30 minutos das diárias
        // bloqueariam seis passadas seguidas se um processo morresse sem
        // liberar o mutex.
        foreach (RotinasAgendadas::POR_INTERVALO as $comando => $minutos) {
            $auditar($schedule->command($comando)
                ->cron("*/{$minutos} * * * *")
                ->withoutOverlapping(RotinasAgendadas::MINUTOS_DE_TRAVA_CURTA)
                ->storeOutput());
        }
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Link assinado inválido ou vencido.
        //
        // O middleware `signed` lança esta exceção, e o tratamento padrão do
        // Laravel devolve a página crua de 403 com o texto "Invalid signature".
        // Quem cai aqui é o cliente final abrindo o link do recibo que a
        // empresa mandou, então a resposta precisa dizer, em português, o que
        // aconteceu e o que fazer.
        $exceptions->renderable(function (InvalidSignatureException $e, Request $request) {
            $mensagem = 'Este link não é mais válido. Links de documento têm prazo de validade e '
                .'deixam de funcionar depois dele. Peça um link novo à empresa que emitiu o documento.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $mensagem], 403);
            }

            return response()->view('publico.aviso', [
                'titulo' => 'Link expirado ou inválido',
                'mensagem' => $mensagem,
            ], 403);
        });
    })->create();
