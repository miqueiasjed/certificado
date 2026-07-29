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
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Rotas do aplicativo do técnico (Plano 12, Task 12.2), sob o prefixo
        // `/api`. Ganham o grupo de middleware `api` (sem sessão, sem CSRF): a
        // autenticação delas é `auth:sanctum` por token, não por cookie.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Portal do cliente (Plano 15, Task 15.2): arquivo de rotas
            // próprio, sob o prefixo `/portal`. Precisa ser registrado aqui, e
            // não como um quarto parâmetro nomeado de withRouting(): só
            // web/api/commands/channels/pages têm esse atalho embutido no
            // framework. O grupo `web` dá a routes/portal.php a mesma sessão,
            // cookies e verificação de CSRF que routes/web.php ganha sozinho
            // pelo parâmetro `web:` acima - o guard muda (`cliente`, não
            // `web`), mas o transporte por trás da sessão é o mesmo.
            Route::middleware('web')
                ->prefix('portal')
                ->name('portal.')
                ->group(base_path('routes/portal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);

        // Webhook do gateway de pagamento (Plano 7, Task 7.6), fora do CSRF.
        //
        // Token CSRF pressupõe sessão e formulário renderizado por nós. Quem
        // chama esta rota é o provedor de pagamento: não tem sessão, não tem
        // token e não teria como obter um. Mantê-la sob a verificação faria toda
        // confirmação de pagamento voltar 419, e a fatura do tenant nunca seria
        // baixada.
        //
        // A troca é consciente: a autorização desta rota não vem do CSRF, vem da
        // assinatura que o provedor manda no cabeçalho, conferida em
        // `GatewayAssinatura::validarWebhook()` antes de qualquer gravação
        // (`GatewayWebhookController`). Requisição sem assinatura válida sai com
        // 401 sem gravar nem o payload.
        //
        // O curinga cobre o `{gateway}` da rota, e só ele: o padrão é comparado
        // com o caminho, então `webhooks/gateway/*` não alcança nenhuma outra
        // rota do sistema.
        $middleware->validateCsrfTokens(except: [
            'webhooks/gateway/*',
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
            // `module:<chave>` barra rota de módulo que o tenant não tem ativo
            // (Plano 6, Task 6.4). Independente de `permission`: um acumula
            // sobre o outro, e os dois precisam passar. Ver EnsureModuleIsActive.
            'module' => \App\Http\Middleware\EnsureModuleIsActive::class,

            // `tenant.ativo` barra o tenant suspenso por falta de pagamento
            // (Plano 7, Task 7.7) e o manda para a página de conta suspensa.
            //
            // É alias aplicado ao grupo autenticado das empresas em
            // `routes/web.php`, e deliberadamente NÃO entra no
            // `$middleware->web(append: [...])` acima. A diferença importa: o
            // append global rodaria também na área `/plataforma` e nas rotas
            // públicas (webhook do gateway, recibo assinado). Na plataforma isso
            // barraria o super admin que assumiu um tenant suspenso, que é
            // exatamente o tenant que ele mais precisa abrir para dar suporte; e
            // no webhook faria a confirmação de pagamento passar por uma
            // verificação de acesso que não tem nada a ver com ela. Preso ao
            // grupo `auth`, o alcance é exatamente o mundo das empresas.
            'tenant.ativo' => \App\Http\Middleware\EnsureTenantIsActive::class,

            // Portão único das rotas protegidas do portal do cliente (Plano
            // 15, Task 15.2). routes/portal.php referencia a classe
            // diretamente; o alias existe só para consistência com o resto
            // desta lista.
            'auth.cliente' => \App\Http\Middleware\AutenticarCliente::class,

            // Módulo `portal_cliente` desligado para a empresa (Plano 15,
            // Task 15.4): mesmo papel de `module`, mas com destino e texto
            // endereçados ao cliente final, nunca ao funcionário (ver
            // EnsurePortalModuleIsActive). routes/portal.php também referencia
            // a classe diretamente; o alias existe só para consistência.
            'module.portal' => \App\Http\Middleware\EnsurePortalModuleIsActive::class,
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
