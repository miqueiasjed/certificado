<?php

namespace App\Http\Middleware;

use App\Services\AssumirTenantService;
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
}
