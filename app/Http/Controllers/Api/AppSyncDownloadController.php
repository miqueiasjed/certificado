<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppDayLoadService;
use App\Services\AppSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Carga do dia do aplicativo do técnico (Plano 12, Task 12.3).
 *
 * Controller fino: extrai os filtros da query string, chama
 * `AppDayLoadService` e traduz o resultado em resposta JSON. Toda a regra de
 * negócio (período padrão, escopo por técnico, filtro incremental por
 * `desde`, exclusão de campo financeiro) vive no Service.
 */
class AppSyncDownloadController extends Controller
{
    public function __construct(
        private readonly AppDayLoadService $appDayLoadService,
        private readonly AppSessionService $appSessionService
    ) {}

    /**
     * `GET /api/app/carga`. Dentro do grupo `auth:sanctum` montado na Task
     * 12.2: o usuário autenticado por token já carrega o `company_id`, e o
     * escopo global de `BelongsToCompany` cuida do isolamento entre empresas.
     */
    public function carga(Request $request): JsonResponse
    {
        $usuario = $request->user();

        $resultado = $this->appDayLoadService->carregar($usuario, [
            'de' => $request->query('de'),
            'ate' => $request->query('ate'),
            'desde' => $request->query('desde'),
            'tecnico_id' => $request->query('tecnico_id'),
        ]);

        if (! $resultado['sucesso']) {
            return response()->json(['message' => $resultado['mensagem']], $resultado['status']);
        }

        // Toda requisição autenticada bem-sucedida do aplicativo renova a
        // validade do token, para o técnico ativo nunca ser deslogado no meio
        // do dia (AppSessionService::renovar()).
        $token = $usuario->currentAccessToken();

        if ($token !== null) {
            $this->appSessionService->renovar($token);
        }

        return response()->json($resultado['dados']);
    }
}
