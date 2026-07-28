<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\AssumirTenantService;
use App\Services\LimitesDoPlanoService;
use App\Services\ModuleService;
use App\Services\OnboardingService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Defines the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'papeis' => $request->user()->getRoleNames(),
                    'permissoes' => $request->user()->getAllPermissions()->pluck('name'),
                    'ehTecnico' => $request->user()->hasRole('tecnico'),
                ] : null,
            ],
            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'error' => fn () => $request->session()->get('error'),
                'success' => fn () => $request->session()->get('success'),
            ],
            'suporte' => $this->estadoDoSuporte($request),
            'avisos' => fn () => $this->avisosDeLimite($request),
            'modulos' => $this->modulosAtivos($request),
            'onboarding' => fn () => $this->onboardingDoTenant($request),
        ]);
    }

    /**
     * Estado do modo suporte, para a faixa de aviso da Task 5.9.
     *
     * Vai em toda resposta, sem closure: a faixa precisa aparecer em qualquer
     * tela e some se a prop faltar num recarregamento parcial. O custo é uma
     * leitura de sessão por requisição.
     *
     * A condição repete, de propósito, a mesma regra de `TenantAtual`: só é
     * suporte ativo quando o usuário tem `is_platform_admin` E existe empresa
     * assumida na sessão. Se a faixa saísse só da chave de sessão, um usuário
     * comum com a sessão adulterada veria "você está operando como Empresa X"
     * enquanto seguiria, corretamente, dentro do próprio tenant. O dado seria
     * inofensivo e a leitura, assustadora.
     *
     * @return array{ativo: bool, empresa: string|null}
     */
    private function estadoDoSuporte(Request $request): array
    {
        $usuario = $request->user();

        if ($usuario === null || ($usuario->is_platform_admin ?? null) !== true) {
            return ['ativo' => false, 'empresa' => null];
        }

        $assumido = app(AssumirTenantService::class)->assumidoAtualmente();

        return [
            'ativo' => $assumido !== null,
            'empresa' => $assumido['nome'] ?? null,
        ];
    }

    /**
     * Avisos de limite quantitativo do plano (Task 6.5), para a faixa de
     * aviso do frontend.
     *
     * A prop inteira é uma closure (`fn () => ...`), no mesmo padrão de
     * `flash` acima: é o que torna a prop "preguiçosa" para a Inertia. Numa
     * recarga parcial que não pede `avisos` no `only`, a Inertia nem chama
     * este método, e a consulta de uso por trás dele (a mais cara do
     * sistema, ver o cabeçalho de `LimitesDoPlanoService`) simplesmente não
     * roda. Quem está longe do teto, e o tenant interno, não pagam esse
     * custo em toda requisição.
     *
     * Sem usuário autenticado (tela de login) ou sem tenant resolvido,
     * devolve vazio: não há empresa para calcular limite nenhum.
     *
     * @return array<int, string>
     */
    private function avisosDeLimite(Request $request): array
    {
        if ($request->user() === null) {
            return [];
        }

        try {
            $empresa = Company::current();
        } catch (\Throwable) {
            return [];
        }

        return app(LimitesDoPlanoService::class)->avisos($empresa);
    }

    /**
     * Chaves dos módulos ativos do tenant corrente (Task 6.3), para o menu
     * lateral esconder item de módulo indisponível (Task 6.6).
     *
     * Diferente de `avisos`, esta prop não é uma closure: o `Sidebar` precisa
     * dela em toda renderização, inclusive em recarga parcial, e
     * `ModuleService::ativosPara()` já memoiza por empresa dentro da mesma
     * requisição, então repetir a leitura aqui não repete a consulta.
     *
     * Sem usuário autenticado (tela de login) ou sem tenant resolvido,
     * devolve vazio: não há empresa para calcular módulo nenhum.
     *
     * @return array<int, string>
     */
    private function modulosAtivos(Request $request): array
    {
        if ($request->user() === null) {
            return [];
        }

        try {
            $empresa = Company::current();
        } catch (\Throwable) {
            return [];
        }

        return app(ModuleService::class)->ativosPara($empresa);
    }

    /**
     * Trilha de primeiros passos do tenant novo (Task 8.5), para o bloco do
     * dashboard da Task 8.9.
     *
     * Fica em closure, no mesmo padrão de `avisos`: só é calculada em
     * navegação completa (ou parcial que peça `onboarding` no `only`), nunca
     * em toda requisição. Some de vez (devolve `null`) assim que a trilha
     * está concluída, e a partir daí para de custar até a consulta de
     * existência de `OnboardingService::concluida()` já paga aqui: o
     * `avaliar()`, mais caro, só roda enquanto sobrar passo pendente.
     *
     * Sem usuário autenticado (tela de login) ou sem tenant resolvido,
     * devolve nulo: não há empresa para calcular trilha nenhuma.
     */
    private function onboardingDoTenant(Request $request): ?array
    {
        if ($request->user() === null) {
            return null;
        }

        try {
            $empresa = Company::current();
        } catch (\Throwable) {
            return null;
        }

        $onboarding = app(OnboardingService::class);

        if ($onboarding->concluida($empresa)) {
            return null;
        }

        $onboarding->avaliar($empresa);

        return $onboarding->situacao($empresa);
    }
}
