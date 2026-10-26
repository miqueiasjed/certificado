<?php

namespace App\Http\Controllers;

use App\Http\Requests\RelatarProblemaRequest;
use App\Services\ProblemReportService;
use Illuminate\Http\JsonResponse;

/**
 * Botão "Relatar problema" do painel das empresas.
 *
 * Responde JSON porque o formulário é um modal aberto por cima de qualquer
 * tela: um redirect do Inertia levaria a pessoa para fora do que ela estava
 * fazendo. O par do portal do cliente é `Portal\PortalProblemReportController`.
 */
class ProblemReportController extends Controller
{
    public function __construct(
        private readonly ProblemReportService $relatos,
    ) {}

    public function store(RelatarProblemaRequest $request): JsonResponse
    {
        $this->relatos->registrar(
            $request->validated(),
            $request->user(),
            null,
            $request->userAgent(),
        );

        return response()->json([
            'message' => 'Recebemos seu relato. Obrigado!',
        ], 201);
    }
}
