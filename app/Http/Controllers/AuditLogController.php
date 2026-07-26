<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\AuditoriaService;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function __construct(
        private AuditoriaService $auditoriaService
    ) {
    }

    /**
     * GET /audit-logs/{tipo}/{id}
     *
     * Devolve o histórico de auditoria do registro informado, paginado e já
     * formatado para exibição. Tipo fora da lista fechada de models auditados
     * devolve 404, nunca instancia classe a partir da URL.
     */
    public function index(Request $request, string $tipo, int $id)
    {
        if (! $this->auditoriaService->tipoValido($tipo)) {
            abort(404);
        }

        $porPagina = (int) $request->integer('por_pagina', 20);

        $historico = $this->auditoriaService->historicoDe($tipo, $id, $porPagina);

        $historico->through(fn (AuditLog $log) => $this->auditoriaService->formatarRegistro($log));

        return response()->json($historico);
    }
}
