<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppLoginRequest;
use App\Services\AppSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Autenticação do aplicativo do técnico (Plano 12, Task 12.2).
 *
 * Controller fino: valida via FormRequest, chama o `AppSessionService` e
 * traduz o resultado em resposta JSON. Toda regra de negócio (credencial,
 * conta desativada, técnico vinculado, emissão e revogação de token) vive no
 * Service.
 */
class AppAuthController extends Controller
{
    public function __construct(private readonly AppSessionService $appSessionService)
    {
    }

    /**
     * `POST /api/app/login`. Rota pública, fora do grupo `auth:sanctum`.
     */
    public function login(AppLoginRequest $request): JsonResponse
    {
        $dados = $request->validated();

        $resultado = $this->appSessionService->autenticar(
            $dados['email'],
            $dados['password'],
            $dados['dispositivo']
        );

        if (! $resultado['sucesso']) {
            return response()->json(['message' => $resultado['mensagem']], $resultado['status']);
        }

        return response()->json($resultado['dados']);
    }

    /**
     * `POST /api/app/logout`. Revoga o token com que a requisição chegou.
     */
    public function logout(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $token = $usuario->currentAccessToken();

        if ($token !== null) {
            $this->appSessionService->revogarAtual($usuario, $token);
        }

        return response()->json(['message' => 'Sessão encerrada com sucesso.']);
    }

    /**
     * `GET /api/app/sessoes`. Lista as sessões (tokens) do próprio usuário
     * autenticado, para a empresa ver quais aparelhos estão logados.
     */
    public function sessoes(Request $request): JsonResponse
    {
        $sessoes = $this->appSessionService->listarSessoes($request->user());

        return response()->json(['sessoes' => $sessoes->values()]);
    }

    /**
     * `DELETE /api/app/sessoes/{id}`. Revoga uma sessão do próprio usuário.
     *
     * `{id}` é o id do token, e não um route-model binding: a busca escopada a
     * `$usuario->tokens()` dentro do Service já garante que ninguém revoga
     * sessão alheia. Id que não pertence ao usuário autenticado devolve 404,
     * sem confirmar nem negar que o token existe para outra pessoa.
     */
    public function revogarSessao(Request $request, int $id): JsonResponse
    {
        $revogada = $this->appSessionService->revogarSessao($request->user(), $id);

        if (! $revogada) {
            return response()->json(['message' => 'Sessão não encontrada.'], 404);
        }

        return response()->json(['message' => 'Sessão revogada com sucesso.']);
    }
}
