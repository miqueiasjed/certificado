<?php

namespace App\Providers;

use App\Listeners\RegistraAcesso;
use App\Listeners\RegistraExecucaoAgendada;
use App\Models\WorkOrder;
use App\Observers\WorkOrderNotificationObserver;
use App\Support\TenantAtual;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
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
        $this->registrarObservadoresDeNotificacao();
        $this->limparTenantEntreJobsDaFila();
    }

    /**
     * Liga os avisos que nascem de uma ação sobre a ordem de serviço: visita
     * agendada, técnico a caminho e OS concluída (Task 14.4).
     *
     * O observer fica registrado aqui, e não em bootstrap/app.php, para manter
     * aquele arquivo no que ele já cuida (rotas, middleware, agendamento e
     * tratamento de exceção). O agendamento da rotina diária de avisos continua
     * lá, pela lista de `RotinasAgendadas`.
     */
    private function registrarObservadoresDeNotificacao(): void
    {
        WorkOrder::observe(WorkOrderNotificationObserver::class);
    }

    /**
     * Zera o tenant explícito antes de o worker buscar cada job.
     *
     * `TenantAtual` guarda o tenant em propriedade estática, com vida útil do
     * processo. Em requisição HTTP isso dura um ciclo e morre; em worker de
     * fila, que é um processo longo, o que um job deixar para trás vale para o
     * próximo. Um job que chame `TenantAtual::definir()` e não limpe faria o
     * job seguinte gravar dentro da empresa errada, sem erro nenhum na saída.
     *
     * O caminho normal já é seguro: `App\Jobs\Concerns\CarregaTenant` usa
     * `comTenant()`, que restaura o valor anterior no `finally`, inclusive
     * quando o job estoura. Este listener é a rede embaixo disso, para o job
     * que não usa a trait, para código de terceiro e para o dia em que alguém
     * chamar `definir()` direto dentro de um `handle()`.
     *
     * O evento `Looping` é disparado a cada volta do `queue:work`, antes de
     * buscar o próximo job, que é exatamente onde a limpeza precisa acontecer.
     */
    private function limparTenantEntreJobsDaFila(): void
    {
        Queue::looping(static function (): void {
            TenantAtual::limpar();
        });
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
