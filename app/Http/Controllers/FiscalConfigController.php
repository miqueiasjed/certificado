<?php

namespace App\Http\Controllers;

use App\Exceptions\FalhaFiscalException;
use App\Http\Requests\UpdateFiscalConfigRequest;
use App\Models\FiscalConfig;
use App\Services\FiscalConfigService;
use App\Support\Fiscal\MensagemFiscalPublica;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class FiscalConfigController extends Controller
{
    public function __construct(
        private readonly FiscalConfigService $configuracoes,
    ) {}

    public function show(Request $request): Response|JsonResponse
    {
        $dados = ['configuracao' => $this->publica($this->configuracoes->atual())];

        return $request->expectsJson()
            ? response()->json($dados)
            : Inertia::render('Fiscal/Configuracao', $dados);
    }

    public function update(UpdateFiscalConfigRequest $request): JsonResponse|RedirectResponse
    {
        try {
            $configuracao = $this->configuracoes->salvar($request->validated());
        } catch (FalhaFiscalException $falha) {
            throw ValidationException::withMessages(['credenciais' => $falha->getMessage()]);
        } catch (ValidationException $falha) {
            throw $falha;
        } catch (Throwable $falha) {
            throw ValidationException::withMessages([
                'credenciais' => MensagemFiscalPublica::deFalha($falha, [
                    'operacao' => 'endpoint_validar_configuracao_fiscal',
                ]),
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Configuração fiscal validada e salva.',
                'configuracao' => $this->publica($configuracao),
            ]);
        }

        return back()->with('success', 'Configuração fiscal validada e salva.');
    }

    /** @return array<string, mixed>|null */
    private function publica(?FiscalConfig $configuracao): ?array
    {
        if (! $configuracao instanceof FiscalConfig) {
            return null;
        }

        return [
            'id' => (int) $configuracao->id,
            'provedor' => $configuracao->provedor,
            'ambiente' => $configuracao->ambiente,
            'regime_tributario' => $configuracao->regime_tributario,
            'codigo_servico' => $configuracao->codigo_servico,
            'cnae' => $configuracao->cnae,
            'aliquota_iss' => $configuracao->aliquota_iss,
            'iss_retido' => $configuracao->iss_retido,
            'natureza_operacao' => $configuracao->natureza_operacao,
            'serie' => $configuracao->serie,
            'proximo_numero' => $configuracao->proximo_numero,
            'emissao_automatica' => $configuracao->emissao_automatica,
            'gatilho_emissao_automatica' => $configuracao->gatilho_emissao_automatica,
            'exige_inscricao_municipal_tomador' => $configuracao->exige_inscricao_municipal_tomador,
            'ativo' => $configuracao->ativo,
            'possui_credencial' => is_array($configuracao->credenciais) && $configuracao->credenciais !== [],
        ];
    }
}
