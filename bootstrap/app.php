<?php

use App\Listeners\RegistraExecucaoAgendada;
use App\Support\RotinasAgendadas;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
    })
    ->withSchedule(function (Schedule $schedule) {
        // Sem `schedule:run` no cron nada disto executa. A linha de cron está
        // documentada na seção "Rotinas agendadas" do README.
        foreach (RotinasAgendadas::DIARIAS as $comando => $horario) {
            $rotina = $schedule->command($comando)
                ->dailyAt($horario)
                // Sem isto, 00:10 seria 00:10 em UTC, ou seja, 21:10 do dia
                // anterior em Brasília, e a rotina viraria o dia errado.
                ->timezone(config('app.business_timezone'))
                ->withoutOverlapping(RotinasAgendadas::MINUTOS_DE_TRAVA)
                // Guarda a saída em arquivo para que ela caia na coluna output.
                ->storeOutput();

            // Rodada curta e o registro depende do resultado, por isso sem
            // runInBackground: em background o exitCode só chega em outro
            // processo, via schedule:finish.
            $rotina->before(function (RegistraExecucaoAgendada $auditoria) use ($rotina) {
                $auditoria->registrarInicio($rotina);
            });

            $rotina->then(function (RegistraExecucaoAgendada $auditoria) use ($rotina) {
                $auditoria->registrarFim($rotina);
            });
        }
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
