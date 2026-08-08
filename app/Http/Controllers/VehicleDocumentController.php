<?php

namespace App\Http\Controllers;

use App\Http\Requests\VehicleDocumentRequest;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Support\BusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Documentos do veículo, com validade e arquivo (Plano 27, Task 27.4).
 *
 * Sem Service próprio de propósito: não há regra de negócio aqui além de
 * guardar o arquivo e o dia de validade. Quem decide o que é documento
 * vencendo, o que é documento vencido e com que frequência avisar é o
 * `AlertaDeFrotaService` (Task 27.3), e a comparação de vencimento é sempre por
 * dia no fuso do negócio, via `BusinessDate` — nunca `now()` em UTC.
 *
 * O arquivo vai para o disco `public`, no mesmo padrão da logo e das
 * assinaturas da empresa (`CompanyController`). Trocar o arquivo apaga o
 * anterior: documento de veículo é substituído a cada renovação, e guardar
 * todas as versões antigas sem tela que as mostre só ocuparia disco.
 */
class VehicleDocumentController extends Controller
{
    /**
     * Pasta do disco `public` onde os documentos ficam.
     */
    private const PASTA = 'frota/documentos';

    public function index(Request $request, Vehicle $vehicle): JsonResponse
    {
        return response()->json([
            'veiculo' => $vehicle,
            'documentos' => $vehicle->documents()
                ->orderBy('validade')
                ->get()
                ->map(fn (VehicleDocument $documento): array => [
                    'documento' => $documento,
                    'vencido' => BusinessDate::estaVencido($documento->validade),
                ])
                ->all(),
        ]);
    }

    public function store(VehicleDocumentRequest $request, Vehicle $vehicle): RedirectResponse|JsonResponse
    {
        $dados = $request->validated();
        unset($dados['arquivo']);

        $dados['vehicle_id'] = $vehicle->getKey();
        $dados['validade'] = BusinessDate::diaDe($dados['validade']);
        $dados['arquivo_path'] = $request->hasFile('arquivo')
            ? $request->file('arquivo')->store(self::PASTA, 'public')
            : null;

        $documento = VehicleDocument::create($dados);

        if ($request->expectsJson()) {
            return response()->json([
                'mensagem' => 'Documento cadastrado.',
                'documento' => $documento,
            ], 201);
        }

        return redirect()->route('frota.show', $vehicle)->with('success', 'Documento cadastrado.');
    }

    public function update(
        VehicleDocumentRequest $request,
        Vehicle $vehicle,
        VehicleDocument $document
    ): RedirectResponse|JsonResponse {
        if ((int) $document->vehicle_id !== (int) $vehicle->getKey()) {
            return $this->naoPertence($request);
        }

        $dados = $request->validated();
        unset($dados['arquivo']);

        if (array_key_exists('validade', $dados)) {
            $dados['validade'] = BusinessDate::diaDe($dados['validade']);
        }

        if ($request->hasFile('arquivo')) {
            $anterior = $document->arquivo_path;
            $dados['arquivo_path'] = $request->file('arquivo')->store(self::PASTA, 'public');

            if ($anterior !== null) {
                Storage::disk('public')->delete($anterior);
            }
        }

        $document->update($dados);

        if ($request->expectsJson()) {
            return response()->json([
                'mensagem' => 'Documento atualizado.',
                'documento' => $document->fresh(),
            ]);
        }

        return redirect()->route('frota.show', $vehicle)->with('success', 'Documento atualizado.');
    }

    public function destroy(Request $request, Vehicle $vehicle, VehicleDocument $document): RedirectResponse|JsonResponse
    {
        if ((int) $document->vehicle_id !== (int) $vehicle->getKey()) {
            return $this->naoPertence($request);
        }

        if ($document->arquivo_path !== null) {
            Storage::disk('public')->delete($document->arquivo_path);
        }

        $document->delete();

        if ($request->expectsJson()) {
            return response()->json(['mensagem' => 'Documento excluído.']);
        }

        return redirect()->route('frota.show', $vehicle)->with('success', 'Documento excluído.');
    }

    private function naoPertence(Request $request): RedirectResponse|JsonResponse
    {
        $mensagem = 'Este documento não pertence ao veículo informado.';

        if ($request->expectsJson()) {
            return response()->json(['mensagem' => $mensagem], 404);
        }

        return back()->withErrors(['documento' => $mensagem]);
    }
}
