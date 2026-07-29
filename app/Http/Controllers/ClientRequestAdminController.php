<?php

namespace App\Http\Controllers;

use App\Http\Requests\AlterarSituacaoSolicitacaoRequest;
use App\Http\Requests\ResponderSolicitacaoRequest;
use App\Models\ClientRequest;
use App\Models\User;
use App\Services\ClientRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel administrativo das solicitações de atendimento abertas pelo cliente
 * no portal (Plano 15, Task 15.5).
 *
 * Nota de nomenclatura: `App\Models\ClientRequest` (a solicitação do cliente,
 * importada abaixo) não tem relação nenhuma com `App\Http\Requests\ClientRequest`
 * (o FormRequest de criação/edição de `Client`, já existente e usado por
 * `ClientController`). São classes diferentes em namespaces diferentes; este
 * controller nunca importa a segunda.
 *
 * Escopo aqui é só o escopo global de `ClientRequest` (`BelongsToCompany`),
 * o mesmo de qualquer tela interna: quem acessa é funcionário autenticado no
 * guard `web`, dentro da própria empresa, e não existe uma segunda dimensão
 * de "dono" para aplicar - ao contrário do portal, que ainda escopa por
 * `client_id` (`ClientRequestService::minhas()`/`minha()`). Qualquer
 * funcionário com a permissão pode ver e responder qualquer solicitação da
 * própria empresa.
 */
class ClientRequestAdminController extends Controller
{
    public function __construct(
        private readonly ClientRequestService $solicitacoes,
    ) {}

    public function index(Request $request): Response
    {
        $situacao = $request->query('situacao');

        return Inertia::render('Solicitacoes/Index', [
            'solicitacoes' => $this->solicitacoes->listarParaEmpresa(is_string($situacao) ? $situacao : null),
            'situacaoFiltro' => $situacao,
            'situacoes' => ClientRequest::SITUACOES,
        ]);
    }

    public function responder(ResponderSolicitacaoRequest $request, ClientRequest $solicitacao): RedirectResponse
    {
        /** @var User $funcionario */
        $funcionario = Auth::user();

        $this->solicitacoes->responder($solicitacao, (string) $request->validated('resposta'), $funcionario);

        return back()->with('success', 'Resposta enviada ao cliente.');
    }

    public function situacao(AlterarSituacaoSolicitacaoRequest $request, ClientRequest $solicitacao): RedirectResponse
    {
        $this->solicitacoes->mudarSituacao($solicitacao, (string) $request->validated('situacao'));

        return back()->with('success', 'Situação atualizada.');
    }
}
