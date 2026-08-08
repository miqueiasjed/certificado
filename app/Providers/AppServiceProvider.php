<?php

namespace App\Providers;

use App\Contracts\GatewayAssinatura;
use App\Listeners\RegistraAcesso;
use App\Listeners\RegistraExecucaoAgendada;
use App\Models\Company;
use App\Models\OrganRegistration;
use App\Models\WorkOrder;
use App\Observers\ValidadeRegulatoriaObserver;
use App\Observers\WorkOrderNotificationObserver;
use App\Services\Compliance\ReferenciaNormativaService;
use App\Services\Geo\ProvedorDeGeocodificacao;
use App\Support\TenantAtual;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registrarGatewayDeAssinatura();
        $this->registrarProvedorDeGeocodificacao();
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
        $this->registrarLimiteDeSincronizacaoDoAplicativo();
        $this->registrarLimiteDeLoginDoPortal();
        $this->registrarLimitesDoAgendamentoPublico();
        $this->registrarLimitesDaPesquisaPublica();
        $this->registrarReferenciaNormativaNosDocumentos();
    }

    /**
     * Compartilha `$referenciaNormativa` com toda view de `resources/views/pdf`
     * (Plano 24, Task 24.2).
     *
     * Por que um view composer, e não um parâmetro em cada chamada
     * -----------------------------------------------------------
     * As cinco views de documento são carregadas de oito lugares diferentes
     * (`CertificateController`, `ContractController`, `WorkOrderController`
     * duas vezes, `ServiceOrderController`, `PortalDocumentController` quatro
     * vezes, `Notification\DriverDeEmail`). Passar a referência em cada
     * chamada significaria oito pontos onde alguém pode esquecer, e o esquecido
     * sai como documento sem a norma na mão do fiscal — sem erro nenhum na
     * tela. Além disso `pdf.service-order` sequer recebe `$company` hoje, então
     * nem haveria de onde tirar a empresa dentro da view.
     *
     * De qual empresa é a referência
     * ------------------------------
     * Do `$company` já passado à view, quando existe: é ele que manda, porque
     * é dele o cabeçalho impresso no documento (o portal do cliente e o envio
     * por e-mail em fila resolvem a empresa por conta própria, e o tenant
     * corrente pode não ser o mesmo). Sem `$company`, cai no tenant corrente, e
     * sem tenant corrente, na referência padrão da plataforma.
     *
     * O composer nunca lança: `ReferenciaNormativaService::obter()` devolve
     * string vazia em qualquer falha, e é a view que decide não imprimir a
     * linha. Documento que falha de gerar é pior que documento sem a linha.
     */
    private function registrarReferenciaNormativaNosDocumentos(): void
    {
        View::composer('pdf.*', function (ViewContract $view): void {
            $dados = $view->getData();
            $empresa = $dados['company'] ?? null;

            $empresaId = $empresa instanceof Company ? $empresa->id : TenantAtual::id();

            $view->with(
                'referenciaNormativa',
                app(ReferenciaNormativaService::class)->obterParaEmpresaId($empresaId)
            );
        });
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

        // Plano 24, Task 24.3: atualizar a validade de um documento
        // regulatório encerra os avisos de vencimento dele na hora, sem
        // esperar a próxima execução de `conformidade:verificar-validades`.
        // Observer, e não chamada no controller, porque a validade é gravada
        // de mais de um lugar (configurações da empresa, registros em órgão,
        // e amanhã qualquer importação): ver o cabeçalho da classe.
        Company::observe(ValidadeRegulatoriaObserver::class);
        OrganRegistration::observe(ValidadeRegulatoriaObserver::class);
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

    /**
     * Limite de 60 chamadas por minuto por token nas rotas de sincronização
     * do aplicativo do técnico (`/api/app/sincronizar` e `/api/app/fotos`,
     * Plano 12, Task 12.5).
     *
     * A chave é o id do token de acesso pessoal (Sanctum) da requisição, e não
     * o IP: o mesmo IP de rede pode carregar vários aparelhos (Wi-Fi da
     * empresa), e o mesmo aparelho pode trocar de IP no meio do dia (4G para
     * Wi-Fi). Sem limite algum, um bug de repetição no aplicativo (reenvio em
     * laço por timeout) derrubaria o servidor para todos os tenants, não só
     * para o aparelho com o bug.
     */
    private function registrarLimiteDeSincronizacaoDoAplicativo(): void
    {
        RateLimiter::for('app-sincronizacao', function (Request $request) {
            $chave = $request->user()?->currentAccessToken()?->id
                ?? $request->user()?->id
                ?? $request->ip();

            return Limit::perMinute(60)->by((string) $chave);
        });
    }

    /**
     * Limite de 5 tentativas de login por minuto, por e-mail E por IP, no
     * portal do cliente (Plano 15, Task 15.2).
     *
     * As duas chaves valem ao mesmo tempo: `RateLimiter::for()` aceita
     * devolver um array de `Limit`, e o Laravel exige que todos passem. Por
     * e-mail, sozinho, deixaria um IP variar o e-mail tentado e nunca travar;
     * por IP, sozinho, deixaria alguém atrás de um NAT compartilhado (rede de
     * escritório, provedor móvel) travar sem ter feito nada. Juntas, cobrem o
     * caso comum de força bruta (um IP testando muitos e-mails, ou um e-mail
     * sendo testado de muitos IPs) sem punir o vizinho de rede de quem errou a
     * própria senha duas vezes.
     *
     * Mesmo padrão de `registrarLimiteDeSincronizacaoDoAplicativo()`: limiter
     * nomeado, aplicado nas rotas com `throttle:portal-login`.
     */
    private function registrarLimiteDeLoginDoPortal(): void
    {
        RateLimiter::for('portal-login', function (Request $request) {
            $email = mb_strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(5)->by('portal-login-email:'.$email),
                Limit::perMinute(5)->by('portal-login-ip:'.$request->ip()),
            ];
        });
    }

    /**
     * Guarda de entrada da página pública de agendamento (Plano 16, Task 16.3).
     *
     * Dois limitadores de rota, porque ler e escrever têm risco diferente:
     *
     * - `agendamento-publico` (POST do pedido): 10 por minuto e 30 por hora, por
     *   IP. Este é o limite de **requisição**, e o que ele impede é a marretada:
     *   robô mandando POST em laço, cada um deles custando validação e um
     *   cálculo de grade.
     * - `agendamento-publico-leitura` (a página e a grade): 30 por minuto por
     *   IP. Folgado para quem abre a página, troca de mês e recarrega, e
     *   apertado para quem varre slug atrás de tenant.
     *
     * O limite do plano (3 pedidos por hora por IP e 1 por hora por telefone)
     * **não** está aqui, de propósito: middleware de throttle conta requisição,
     * e não pedido gravado. Com o limite apertado aqui, um visitante que errasse
     * o e-mail duas vezes queimaria as três chances da hora, e no caso do
     * telefone (1 por hora) a primeira recusa de validação o deixaria sem
     * nenhuma. Aquela contagem é regra de negócio, conta só pedido que virou
     * linha na tabela e vive em `PublicSchedulingService::registrar()`.
     *
     * Limitação conhecida das duas camadas por IP: o sistema roda com
     * `trustProxies(at: '*')`, então `$request->ip()` sai do cabeçalho
     * `X-Forwarded-For`, que o cliente pode inventar. Quem quer contornar
     * contagem por IP consegue. É por isso que a contagem por telefone existe, e
     * por isso o campo armadilha e o tempo mínimo de preenchimento não são
     * opcionais nesta rota.
     */
    private function registrarLimitesDoAgendamentoPublico(): void
    {
        RateLimiter::for('agendamento-publico', fn (Request $request) => [
            Limit::perMinute(10)->by('agendamento-publico-minuto:'.$request->ip()),
            Limit::perHour(30)->by('agendamento-publico-hora:'.$request->ip()),
        ]);

        RateLimiter::for(
            'agendamento-publico-leitura',
            fn (Request $request) => Limit::perMinute(30)
                ->by('agendamento-publico-leitura:'.$request->ip())
        );
    }

    /**
     * Guarda de entrada da página pública de pesquisa de satisfação (Plano 16,
     * Task 16.5).
     *
     * Mesmo par de limitadores da página de agendamento, e limitadores próprios de
     * propósito: reaproveitar as chaves de lá faria o cliente que responde uma
     * pesquisa consumir a cota de quem está pedindo horário, e a leitura do log
     * mentiria sobre qual página está sendo martelada.
     *
     * - `pesquisa-publica-leitura` (a página): 30 por minuto por IP. Folgado para
     *   quem abre o link e recarrega, e apertado para quem varre token. Varredura
     *   de token já é inviável pelo espaço de 64 caracteres sorteados; o limite
     *   existe para que a tentativa não custe consulta nem banda.
     * - `pesquisa-publica` (o envio da nota): 10 por minuto e 30 por hora por IP.
     *   Uma pessoa responde uma pesquisa uma vez, então o limite só encosta em
     *   robô.
     *
     * Vale a mesma limitação conhecida do agendamento: com `trustProxies(at: '*')`
     * o IP sai de `X-Forwarded-For`, que o cliente pode inventar. Aqui isso pesa
     * menos, porque sem o token não há nada a fazer nesta rota.
     */
    private function registrarLimitesDaPesquisaPublica(): void
    {
        RateLimiter::for('pesquisa-publica', fn (Request $request) => [
            Limit::perMinute(10)->by('pesquisa-publica-minuto:'.$request->ip()),
            Limit::perHour(30)->by('pesquisa-publica-hora:'.$request->ip()),
        ]);

        RateLimiter::for(
            'pesquisa-publica-leitura',
            fn (Request $request) => Limit::perMinute(30)
                ->by('pesquisa-publica-leitura:'.$request->ip())
        );
    }

    /**
     * Liga `App\Contracts\GatewayAssinatura` à implementação escolhida em
     * `config('services.gateway_assinatura')` (Plano 7, Task 7.2).
     *
     * A resolução é por configuração, e não por uma classe fixa aqui, porque
     * essa é a única forma de trocar de provedor de gateway sem tocar em
     * `SubscriptionService`, `InvoiceService` ou em qualquer outro
     * consumidor da interface: eles dependem só do contrato, nunca desta
     * ligação. `$app->make()`, e não `new`, porque a implementação concreta
     * pode ter dependências próprias (cliente HTTP, log) que também
     * precisam passar pelo container.
     */
    private function registrarGatewayDeAssinatura(): void
    {
        $this->app->bind(GatewayAssinatura::class, function ($app) {
            return $app->make(config('services.gateway_assinatura'));
        });
    }

    /**
     * Liga `App\Services\Geo\ProvedorDeGeocodificacao` à implementação
     * escolhida em `config('services.geocodificacao.provedor')` (Plano 22,
     * Task 22.2).
     *
     * Mesmo raciocínio de `registrarGatewayDeAssinatura()`: resolução por
     * configuração, não por uma classe fixa aqui, para trocar de provedor de
     * geocodificação (hoje Nominatim, amanhã talvez Google Maps ou Mapbox)
     * sem tocar em `GeocodificacaoService` nem no comando
     * `enderecos:geocodificar`, que dependem só da interface.
     */
    private function registrarProvedorDeGeocodificacao(): void
    {
        $this->app->bind(ProvedorDeGeocodificacao::class, function ($app) {
            return $app->make(config('services.geocodificacao.provedor'));
        });
    }
}
