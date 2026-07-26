<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDeviceReplacementRequest;
use App\Models\Device;
use App\Services\DeviceReplacementService;

/**
 * Substituição de um dispositivo por outro no mesmo ponto de monitoramento.
 *
 * Toda a regra vive em `DeviceReplacementService`: este controller orquestra e
 * devolve a resposta. O `Device` chega por Model Binding, e o escopo global por
 * empresa já garante que um id de outro tenant devolve 404 antes de qualquer
 * código daqui rodar, sem checagem manual.
 *
 * A resposta é JSON, e não redirect Inertia, por causa do 422: a recusa de
 * substituir um dispositivo que já saiu do ponto precisa chegar ao modal da
 * Task 11.8 com código de erro, e não como um flash de sessão em um 302.
 */
class DeviceReplacementController extends Controller
{
    public function __construct(
        private readonly DeviceReplacementService $substituicoes,
    ) {}

    /**
     * Registra a troca e devolve o dispositivo novo com o histórico do ponto.
     *
     * O autor vem do request, nunca de dentro do Service.
     */
    public function store(StoreDeviceReplacementRequest $request, Device $device)
    {
        $resultado = $this->substituicoes->substituir(
            $device,
            $request->validated(),
            $request->user()
        );

        if (! $resultado['success']) {
            return response()->json([
                'success' => false,
                'message' => $resultado['message'],
            ], 422);
        }

        $novo = $resultado['data']['novo'];

        return response()->json([
            'success' => true,
            'message' => $resultado['message'],
            'dispositivo_novo' => $novo->load(['address.client', 'baitType']),
            'historico' => $this->substituicoes->historicoParaTela($this->substituicoes->historicoDoPonto($novo)),
        ]);
    }
}
