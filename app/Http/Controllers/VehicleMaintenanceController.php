<?php

namespace App\Http\Controllers;

use App\Exceptions\QuilometragemRetroativaException;
use App\Http\Requests\VehicleMaintenanceRequest;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Services\Fleet\VehicleMaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Manutenções de um veículo (Plano 27, Task 27.4).
 *
 * Mesmas duas convenções do `RefuelingController`: recusa de negócio vira 422
 * com a mensagem pronta, e o título a pagar é oferecido, nunca criado
 * automaticamente.
 */
class VehicleMaintenanceController extends Controller
{
    public function __construct(
        private readonly VehicleMaintenanceService $manutencoes,
    ) {}

    public function index(Request $request, Vehicle $vehicle): JsonResponse
    {
        return response()->json([
            'veiculo' => $vehicle,
            'manutencoes' => $vehicle->maintenances()
                ->with('payable')
                ->orderByRaw('proxima_em_data is null, proxima_em_data')
                ->orderByDesc('id')
                ->get()
                ->all(),
        ]);
    }

    public function store(VehicleMaintenanceRequest $request, Vehicle $vehicle): RedirectResponse|JsonResponse
    {
        try {
            $resultado = $this->manutencoes->registrar($vehicle, $request->validated());
        } catch (QuilometragemRetroativaException $recusa) {
            return $this->recusar($request, $recusa->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'mensagem' => 'Manutenção registrada.',
                'manutencao' => $resultado['manutencao'],
                'titulo' => $resultado['titulo'],
                'oferta_de_titulo' => $resultado['oferta_de_titulo'],
            ], 201);
        }

        return redirect()
            ->route('frota.show', $vehicle)
            ->with('success', 'Manutenção registrada.');
    }

    public function update(
        VehicleMaintenanceRequest $request,
        Vehicle $vehicle,
        VehicleMaintenance $maintenance
    ): RedirectResponse|JsonResponse {
        if ((int) $maintenance->vehicle_id !== (int) $vehicle->getKey()) {
            return $this->naoPertence($request);
        }

        try {
            $manutencao = $this->manutencoes->atualizar($maintenance, $request->validated());
        } catch (QuilometragemRetroativaException $recusa) {
            return $this->recusar($request, $recusa->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'mensagem' => 'Manutenção atualizada.',
                'manutencao' => $manutencao,
            ]);
        }

        return redirect()
            ->route('frota.show', $vehicle)
            ->with('success', 'Manutenção atualizada.');
    }

    /**
     * Gera o título a pagar de uma manutenção já registrada.
     */
    public function gerarTitulo(Request $request, Vehicle $vehicle, VehicleMaintenance $maintenance): JsonResponse
    {
        $dados = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'vencimento' => 'nullable|date',
            'chart_of_account_id' => 'nullable|exists:chart_of_accounts,id',
        ]);

        if ((int) $maintenance->vehicle_id !== (int) $vehicle->getKey()) {
            return response()->json(['mensagem' => 'Esta manutenção não pertence ao veículo informado.'], 404);
        }

        try {
            $titulo = $this->manutencoes->gerarTitulo($maintenance, $dados);
        } catch (RuntimeException $recusa) {
            return response()->json(['mensagem' => $recusa->getMessage()], 422);
        }

        return response()->json([
            'mensagem' => 'Título a pagar gerado.',
            'titulo' => $titulo,
        ], 201);
    }

    private function recusar(Request $request, string $mensagem): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['mensagem' => $mensagem, 'errors' => ['km' => [$mensagem]]], 422);
        }

        return back()->withInput()->withErrors(['km' => $mensagem]);
    }

    private function naoPertence(Request $request): RedirectResponse|JsonResponse
    {
        $mensagem = 'Esta manutenção não pertence ao veículo informado.';

        if ($request->expectsJson()) {
            return response()->json(['mensagem' => $mensagem], 404);
        }

        return back()->withErrors(['manutencao' => $mensagem]);
    }
}
