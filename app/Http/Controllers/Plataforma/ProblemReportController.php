<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plataforma\AlterarSituacaoDoRelatoRequest;
use App\Models\ProblemReport;
use App\Services\ProblemReportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Relatos de problema de todos os tenants, na área da plataforma.
 *
 * A tela de detalhe é o `source_url` que o sistema central recebe: é o link
 * que abre o relato a partir da tarefa `bug` criada lá.
 *
 * As consultas usam `deTodasAsEmpresas()` de forma explícita. O middleware
 * `platform.admin` já desliga o escopo por empresa durante a requisição, mas
 * o relato é model de domínio, e a saída do escopo fica escrita aqui para não
 * depender da ordem em que os middlewares rodam. `{relato}` é `int` simples,
 * nunca Model Binding, pelo mesmo motivo: o binding poderia resolver antes de
 * o escopo ser desligado e responder 404 para relato de outro tenant.
 */
class ProblemReportController extends Controller
{
    public function __construct(
        private readonly ProblemReportService $relatos,
    ) {}

    public function index(Request $request): Response
    {
        $situacao = $request->string('situacao')->toString();
        $situacao = in_array($situacao, ProblemReport::SITUACOES, true) ? $situacao : '';

        $relatos = $this->consulta()
            ->when($situacao !== '', fn (Builder $query) => $query->where('situacao', $situacao))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Plataforma/Relatos/Index', [
            'relatos' => $relatos,
            'situacoes' => ProblemReport::SITUACOES,
            'filtros' => ['situacao' => $situacao],
        ]);
    }

    public function show(int $relato): Response
    {
        return Inertia::render('Plataforma/Relatos/Show', [
            'relato' => $this->consulta()->findOrFail($relato),
            'situacoes' => ProblemReport::SITUACOES,
        ]);
    }

    public function alterarSituacao(AlterarSituacaoDoRelatoRequest $request, int $relato): RedirectResponse
    {
        $this->relatos->alterarSituacao(
            ProblemReport::query()->deTodasAsEmpresas()->findOrFail($relato),
            $request->validated('situacao'),
        );

        return back()->with('success', 'Situação do relato atualizada.');
    }

    /**
     * Relatos de todos os tenants, com a empresa e quem relatou.
     *
     * Quem relatou aparece só pelo nome: e-mail e telefone não servem para
     * tratar o relato e ficam de fora da resposta.
     */
    private function consulta(): Builder
    {
        return ProblemReport::query()
            ->deTodasAsEmpresas()
            ->with([
                'company:id,name',
                'user:id,name',
                'clientUser' => fn ($query) => $query->deTodasAsEmpresas()->select(['id', 'nome', 'client_id']),
            ]);
    }
}
