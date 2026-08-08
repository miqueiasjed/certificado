<?php

namespace App\Http\Controllers;

use App\Exceptions\QuilometragemRetroativaException;
use App\Http\Requests\RefuelingRequest;
use App\Models\Refueling;
use App\Models\Vehicle;
use App\Services\Fleet\RefuelingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Abastecimentos de um veículo (Plano 27, Task 27.4).
 *
 * Recusa de negócio é 422, nunca 500
 * ----------------------------------
 * `QuilometragemRetroativaException` carrega a mensagem pronta, em português,
 * com a última quilometragem registrada. Deixá-la subir como erro de servidor
 * faria quem está no posto tentar de novo sem entender que digitou um dígito a
 * menos. Mesmo critério do `StockController` para saldo insuficiente e lote
 * vencido.
 *
 * Título a pagar: oferecido, nunca automático
 * --------------------------------------------
 * `store` só gera o título quando o corpo traz `gerar_titulo` verdadeiro e um
 * fornecedor. Em todos os casos a resposta devolve `oferta_de_titulo`, com
 * descrição, valor e vencimento sugeridos, para a tela conseguir perguntar
 * depois. `gerarTitulo` é o caminho de quem recusou na hora e mudou de ideia.
 */
class RefuelingController extends Controller
{
    public function __construct(
        private readonly RefuelingService $abastecimentos,
    ) {}

    /**
     * Abastecimentos do veículo, do mais recente para o mais antigo.
     */
    public function index(Request $request, Vehicle $vehicle): JsonResponse
    {
        return response()->json([
            'veiculo' => $vehicle,
            'abastecimentos' => $vehicle->refuelings()
                ->with(['user', 'payable'])
                ->orderByDesc('data')
                ->orderByDesc('id')
                ->get()
                ->all(),
        ]);
    }

    public function store(RefuelingRequest $request, Vehicle $vehicle): RedirectResponse|JsonResponse
    {
        try {
            $resultado = $this->abastecimentos->registrar(
                $vehicle,
                $request->validated(),
                Auth::user()
            );
        } catch (QuilometragemRetroativaException $recusa) {
            return $this->recusar($request, $recusa->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'mensagem' => 'Abastecimento registrado.',
                'abastecimento' => $resultado['abastecimento'],
                'titulo' => $resultado['titulo'],
                'oferta_de_titulo' => $resultado['oferta_de_titulo'],
            ], 201);
        }

        return redirect()
            ->route('frota.show', $vehicle)
            ->with('success', 'Abastecimento registrado.');
    }

    /**
     * Gera o título a pagar de um abastecimento já registrado.
     */
    public function gerarTitulo(Request $request, Vehicle $vehicle, Refueling $refueling): JsonResponse
    {
        $dados = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'vencimento' => 'nullable|date',
            'chart_of_account_id' => 'nullable|exists:chart_of_accounts,id',
        ]);

        // O abastecimento precisa ser deste veículo, e não só da mesma
        // empresa: o escopo global separa tenants, nunca um registro do outro
        // dentro do mesmo tenant.
        if ((int) $refueling->vehicle_id !== (int) $vehicle->getKey()) {
            return response()->json(['mensagem' => 'Este abastecimento não pertence ao veículo informado.'], 404);
        }

        try {
            $titulo = $this->abastecimentos->gerarTitulo($refueling, $dados);
        } catch (\RuntimeException $recusa) {
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
}
