<?php

namespace App\Http\Middleware;

use App\Services\ModuleService;
use App\Support\CatalogoDeModulos;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Barra o acesso a uma rota cujo módulo (Plano 6) não está ativo para a
 * empresa corrente, inclusive quando o item de menu está escondido no
 * frontend: digitar a URL direto não pode contornar o bloqueio, e essa é a
 * exigência explícita do PRD (ver `.claude/prd/saas-multitenant.md`).
 *
 * Recebe a chave do módulo como parâmetro da rota (`module:financeiro`) e
 * delega a decisão a `ModuleService::ativo()` (Task 6.3), que resolve o
 * tenant da requisição corrente e já aplica toda a precedência de
 * plano/liberação/bloqueio pontual.
 *
 * Independente do `permission:*` do Plano 2, e os dois precisam passar: quem
 * tem a permissão `financeiro-ver` mas está num tenant sem o módulo financeiro
 * continua barrado. Permissão diz o que o usuário pode fazer; módulo diz o
 * que a empresa contratou.
 */
class EnsureModuleIsActive
{
    public function __construct(
        private readonly ModuleService $moduleService,
    ) {
    }

    /**
     * `$chave` vem do parâmetro da rota (`module:financeiro` -> `$chave`
     * = 'financeiro'). Quando o módulo está ativo, a requisição segue sem
     * nenhum efeito colateral: este middleware não escreve nada, só lê.
     */
    public function handle(Request $request, Closure $next, string $chave): Response
    {
        if ($this->moduleService->ativo($chave)) {
            return $next($request);
        }

        // Requisição da própria SPA buscando dado (fetch/axios, como
        // /financial-entries/stats): 403 seco com JSON, para o interceptor do
        // frontend tratar sem precisar entender uma página HTML.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => sprintf(
                    'O módulo %s não está disponível no seu plano.',
                    $this->nomeDoModulo($chave)
                ),
                'modulo' => $chave,
            ], 403);
        }

        // Navegação de página, seja o primeiro carregamento (digitar a URL
        // direto) ou um clique dentro da própria SPA: redireciona para a
        // página "Módulo indisponível" (o componente Vue é da Task 6.8; a
        // rota já existe desde esta task, fora de qualquer bloqueio de
        // módulo). Redirect comum funciona nos dois casos porque o destino
        // também é uma página Inertia, então não é preciso Inertia::location().
        return redirect()->route('modulo-indisponivel', ['modulo' => $chave]);
    }

    /**
     * Nome de exibição do módulo, para compor a mensagem de erro. Cai para a
     * própria chave só se ela não estiver no catálogo, o que não deveria
     * acontecer: a chave usada em `module:chave` na rota é sempre uma das
     * declaradas em `CatalogoDeModulos`.
     */
    private function nomeDoModulo(string $chave): string
    {
        foreach (CatalogoDeModulos::catalogo() as $modulo) {
            if ($modulo['chave'] === $chave) {
                return $modulo['nome'];
            }
        }

        return $chave;
    }
}
