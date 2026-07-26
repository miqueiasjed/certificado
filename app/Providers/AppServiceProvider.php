<?php

namespace App\Providers;

use App\Listeners\RegistraExecucaoAgendada;
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
}
