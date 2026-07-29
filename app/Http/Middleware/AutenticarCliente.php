<?php

namespace App\Http\Middleware;

use App\Support\TenantAtual;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portão único das rotas protegidas do portal do cliente (Plano 15, Task 15.2).
 *
 * Três responsabilidades, nesta ordem:
 *
 * 1. Exige sessão autenticada no guard `cliente`. Sem ela, redireciona para o
 *    login do portal (ou 401 em JSON), guardando a URL pretendida do mesmo
 *    jeito que o `Authenticate` nativo do Laravel faz para o guard `web`.
 * 2. Recusa acesso quando `ClientUser.ativo` é falso, ou quando o `Client` ao
 *    qual o acesso pertence não existe mais. A sessão é destruída na hora: a
 *    empresa desativou o acesso para que ele parasse *nesta* requisição, não
 *    na próxima vez que o cookie expirasse sozinho.
 * 3. Resolve o tenant a partir de `client_users.company_id` - **nunca** de
 *    parâmetro de URL, de cabeçalho nem de domínio - e o mantém fixado
 *    (`TenantAtual::comTenant()`) durante todo o controller e a montagem da
 *    resposta, restaurando o valor anterior ao sair, inclusive em exceção.
 *    Mesmo padrão de `EnsurePlatformAdmin::handle()`.
 *
 * Este middleware substitui o `auth:cliente` nativo, em vez de empilhar com
 * ele: guard `cliente` != guard `web`, então o `$request->user()` que o resto
 * do sistema usa não enxerga o `ClientUser`, e é `Auth::guard('cliente')` que
 * precisa ser consultado em cada passo daqui.
 */
class AutenticarCliente
{
    public function handle(Request $request, Closure $next): Response
    {
        $clienteUser = Auth::guard('cliente')->user();

        if ($clienteUser === null) {
            return $this->recusarSemAutenticacao($request);
        }

        if (! $clienteUser->ativo || $clienteUser->client === null) {
            return $this->recusarPorAcessoInativo($request);
        }

        return TenantAtual::comTenant(
            (int) $clienteUser->company_id,
            fn () => $next($request)
        );
    }

    private function recusarSemAutenticacao(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Você precisa entrar para acessar o portal.',
            ], 401);
        }

        return redirect()->guest(route('portal.login'));
    }

    /**
     * `ClientUser.ativo = false` (a empresa desativou o acesso) ou o cliente
     * dono do acesso deixou de existir. Os dois casos derrubam a sessão do
     * portal imediatamente: continuar autenticado com o acesso desativado é
     * exatamente o que este middleware existe para impedir.
     */
    private function recusarPorAcessoInativo(Request $request): Response
    {
        Auth::guard('cliente')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $mensagem = 'Seu acesso ao portal foi desativado. Fale com a empresa para mais informações.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $mensagem], 403);
        }

        return redirect()->route('portal.login')->withErrors(['email' => $mensagem]);
    }
}
