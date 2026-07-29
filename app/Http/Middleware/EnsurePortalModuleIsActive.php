<?php

namespace App\Http\Middleware;

use App\Services\ModuleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Barra o acesso ao portal do cliente quando a empresa não tem o módulo
 * `portal_cliente` ativo (Plano 6, Task 15.4), mesmo mecanismo de
 * `EnsureModuleIsActive` (Task 6.4): delega a decisão a
 * `ModuleService::ativo()`, independente de qualquer verificação de
 * permissão, e barra até o cliente já convidado.
 *
 * Não reaproveita `EnsureModuleIsActive` diretamente porque o destino dela
 * (`route('modulo-indisponivel', ...)`) mora dentro do grupo `auth` +
 * `tenant.ativo` de `routes/web.php`, protegido pelo guard `web` dos
 * funcionários. Um `ClientUser` autenticado no guard `cliente` nunca passaria
 * por aquele `auth` e cairia, sem sentido nenhum para ele, na tela de login
 * do funcionário. Esta classe existe só para apontar para o destino certo
 * (`portal.modulo-indisponivel`, em `routes/portal.php`) e falar com quem
 * está do outro lado: o cliente final, nunca a empresa que contratou o
 * módulo.
 *
 * Roda depois de `AutenticarCliente` na pilha de middlewares da rota (ver
 * `routes/portal.php`): o tenant já está resolvido em `TenantAtual` quando
 * este middleware chama `ModuleService::ativo()`, que sem empresa explícita
 * cai em `Company::current()`.
 */
class EnsurePortalModuleIsActive
{
    private const MODULO = 'portal_cliente';

    public function __construct(
        private readonly ModuleService $moduleService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->moduleService->ativo(self::MODULO)) {
            return $next($request);
        }

        // Requisição da própria SPA buscando dado (fetch/axios): 403 seco com
        // JSON, mesmo critério de EnsureModuleIsActive.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'O portal do cliente não está disponível no momento. '
                    .'Fale com a empresa para mais informações.',
                'modulo' => self::MODULO,
            ], 403);
        }

        // Navegação de página: redireciona para a página "portal
        // indisponível" do próprio arquivo de rotas do portal, fora deste
        // bloqueio de módulo (ver o comentário em routes/portal.php).
        return redirect()->route('portal.modulo-indisponivel');
    }
}
