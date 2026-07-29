<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\AbrirSolicitacaoRequest;
use App\Models\ClientUser;
use App\Services\ClientRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Solicitação de atendimento do cliente pelo portal (Plano 15, Task 15.5):
 * abrir, listar as próprias e ver uma específica.
 *
 * Mesma regra de `PortalController` (Task 15.4): nenhuma consulta Eloquent
 * direta aqui, tudo passa por `ClientRequestService`, que já resolve o
 * escopo duplo (empresa + cliente).
 */
class ClientRequestController extends Controller
{
    public function __construct(
        private readonly ClientRequestService $solicitacoes,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Portal/Solicitacoes/Index', [
            'solicitacoes' => $this->solicitacoes->minhas($this->clienteAutenticado()),
        ]);
    }

    /**
     * O cliente nunca escolhe prioridade: `AbrirSolicitacaoRequest` nem
     * valida esse campo, e `ClientRequestService::abrir()` sempre grava
     * `normal`. O único erro de negócio esperado aqui é o teto de solicitações
     * abertas (`RuntimeException`), devolvido como mensagem clara no
     * formulário.
     */
    public function store(AbrirSolicitacaoRequest $request): RedirectResponse
    {
        try {
            $this->solicitacoes->abrir($this->clienteAutenticado(), $request->validated());
        } catch (RuntimeException $erro) {
            return back()->withErrors(['limite' => $erro->getMessage()])->withInput();
        }

        return redirect()->route('portal.solicitacoes.index')
            ->with('success', 'Solicitação enviada. A empresa vai responder por aqui.');
    }

    /**
     * Solicitação inexistente ou de outro cliente: `ClientRequestService::minha()`
     * lança `ModelNotFoundException`, que o Laravel converte em 404 sozinho,
     * sem tratamento nenhum aqui (mesmo padrão de `PortalController::visita()`).
     */
    public function show(int $id): Response
    {
        return Inertia::render('Portal/Solicitacoes/Show', [
            'solicitacao' => $this->solicitacoes->minha($this->clienteAutenticado(), $id),
        ]);
    }

    private function clienteAutenticado(): ClientUser
    {
        /** @var ClientUser $clientUser */
        $clientUser = Auth::guard('cliente')->user();

        return $clientUser;
    }
}
