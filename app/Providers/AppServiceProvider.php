<?php

namespace App\Providers;

use App\Listeners\RegistraAcesso;
use App\Listeners\RegistraExecucaoAgendada;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registrarAuditoriaDasRotinas();
        $this->registrarAuditoriaDeAcesso();
    }

    /**
     * Liga o registro de execução das rotinas agendadas.
     *
     * Um listener só, em vez do mesmo bloco repetido em cada agendamento.
     * Os ganchos before/then de bootstrap/app.php chamam a mesma classe, para
     * cobrir `schedule:test`, que não dispara estes eventos. O registro é
     * idempotente, então os dois caminhos podem coexistir.
     */
    private function registrarAuditoriaDasRotinas(): void
    {
        Event::listen(ScheduledTaskStarting::class, [RegistraExecucaoAgendada::class, 'aoIniciar']);
        Event::listen(ScheduledTaskFinished::class, [RegistraExecucaoAgendada::class, 'aoFinalizar']);
        Event::listen(ScheduledTaskFailed::class, [RegistraExecucaoAgendada::class, 'aoFalhar']);
    }

    /**
     * Liga o registro de login, falha de login e logout em access_logs.
     *
     * AuthController usa Auth::attempt() e Auth::logout(), que já disparam os
     * eventos nativos Login/Failed/Logout, então nenhuma mudança no controller
     * foi necessária: este listener é o único ponto de captura.
     */
    private function registrarAuditoriaDeAcesso(): void
    {
        Event::listen(Login::class, [RegistraAcesso::class, 'aoEntrar']);
        Event::listen(Failed::class, [RegistraAcesso::class, 'aoFalhar']);
        Event::listen(Logout::class, [RegistraAcesso::class, 'aoSair']);
    }
}
