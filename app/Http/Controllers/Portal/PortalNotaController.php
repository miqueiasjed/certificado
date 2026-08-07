<?php

namespace App\Http\Controllers\Portal;

use App\Exceptions\FalhaFiscalException;
use App\Http\Controllers\Controller;
use App\Models\ClientUser;
use App\Services\FiscalPanelService;
use App\Services\PortalService;
use App\Support\Fiscal\MensagemFiscalPublica;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class PortalNotaController extends Controller
{
    private readonly PortalService $portal;

    public function __construct(
        private readonly FiscalPanelService $arquivos,
    ) {
        $this->portal = new PortalService($this->clienteAutenticado());
    }

    public function index(Request $request): RedirectResponse|JsonResponse
    {
        $dados = [
            'faturas' => $this->portal->faturas(),
            'notas_fiscais' => $this->portal->notasFiscais(),
            'nfse_ativo' => true,
        ];

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return redirect()->route('portal.faturas');
    }

    public function pdf(int $nota): BinaryFileResponse
    {
        return $this->download($nota, 'pdf');
    }

    public function xml(int $nota): BinaryFileResponse
    {
        return $this->download($nota, 'xml');
    }

    private function download(int $id, string $tipo): BinaryFileResponse
    {
        $nota = $this->portal->notaFiscal($id);

        try {
            $arquivo = $this->arquivos->arquivo($nota, $tipo);
        } catch (FalhaFiscalException $falha) {
            abort(409, $falha->getMessage());
        } catch (Throwable $falha) {
            abort(500, MensagemFiscalPublica::deFalha($falha, [
                'service_invoice_id' => $nota->id,
                'operacao' => "endpoint_portal_download_{$tipo}",
            ]));
        }

        return response()->download(
            $arquivo['caminho'],
            $arquivo['nome'],
            ['Content-Type' => $arquivo['mime']],
        );
    }

    private function clienteAutenticado(): ClientUser
    {
        /** @var ClientUser $cliente */
        $cliente = Auth::guard('cliente')->user();

        return $cliente;
    }
}
