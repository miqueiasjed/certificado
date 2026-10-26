<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\RelatarProblemaRequest;
use App\Models\ClientUser;
use App\Services\ProblemReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Botão "Relatar problema" do portal do cliente.
 *
 * Mesmo fluxo do painel (`ProblemReportController`), com o autor vindo do
 * guard `cliente`. A empresa do relato é a de `client_users.company_id`,
 * fixada por `AutenticarCliente` antes de chegar aqui.
 */
class PortalProblemReportController extends Controller
{
    public function __construct(
        private readonly ProblemReportService $relatos,
    ) {}

    public function store(RelatarProblemaRequest $request): JsonResponse
    {
        /** @var ClientUser $usuarioDoPortal */
        $usuarioDoPortal = Auth::guard('cliente')->user();

        $this->relatos->registrar(
            $request->validated(),
            null,
            $usuarioDoPortal,
            $request->userAgent(),
        );

        return response()->json([
            'message' => 'Recebemos seu relato. Obrigado!',
        ], 201);
    }
}
