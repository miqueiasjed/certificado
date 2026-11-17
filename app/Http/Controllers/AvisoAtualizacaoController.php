<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarcarAvisosLidosRequest;
use App\Services\AvisosAtualizacao\AvisosAtualizacaoService;
use App\Services\AvisosAtualizacao\VersaoDoBuild;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aviso de atualização ao entrar. Qualquer papel da empresa chama; o público
 * sai dos papéis da pessoa no servidor, e a requisição nunca informa público.
 * O super admin da plataforma recebe 403.
 */
class AvisoAtualizacaoController extends Controller
{
    public function __construct(
        private readonly AvisosAtualizacaoService $avisos,
        private readonly VersaoDoBuild $versaoDoBuild,
    ) {}

    public function pendentes(Request $request): JsonResponse
    {
        abort_if($request->user()->is_platform_admin === true, 403);

        return response()->json([
            'dados' => [
                'avisos' => $this->avisos->pendentes($request->user()),
                'versao' => $this->versaoDoBuild->atual(),
            ],
        ]);
    }

    public function marcarLidos(MarcarAvisosLidosRequest $request): JsonResponse
    {
        abort_if($request->user()->is_platform_admin === true, 403);

        $marcados = $this->avisos->marcarLidos($request->user(), $request->validated()['ids']);

        return response()->json(['dados' => ['marcados' => $marcados]]);
    }
}
