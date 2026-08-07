<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateFiscalClientRequest;
use App\Models\Client;
use App\Services\FiscalClientService;
use Illuminate\Http\JsonResponse;

class FiscalClientController extends Controller
{
    public function __construct(
        private readonly FiscalClientService $clientes,
    ) {}

    public function show(Client $cliente): JsonResponse
    {
        return response()->json([
            'cliente' => $this->clientes->dados($cliente),
        ]);
    }

    public function update(UpdateFiscalClientRequest $request, Client $cliente): JsonResponse
    {
        return response()->json([
            'message' => 'Dados fiscais do cliente atualizados.',
            'cliente' => $this->clientes->salvar($cliente, $request->validated()),
        ]);
    }
}
