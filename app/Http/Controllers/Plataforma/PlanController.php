<?php

namespace App\Http\Controllers\Plataforma;

use App\Http\Controllers\Controller;
use App\Http\Requests\Plataforma\PlanRequest;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD do catálogo de planos comerciais, na área da plataforma.
 *
 * `Plan` é catálogo compartilhado entre tenants (sem `BelongsToCompany`), e
 * não tem regra de negócio complexa o bastante para justificar um Service
 * próprio: criar/atualizar são um `create`/`update` direto, e a única decisão
 * que não é validação de input é a recusa de exclusão de plano vinculado, que
 * fica explícita aqui, no mesmo padrão já usado por outros cadastros simples
 * do sistema (ver `AntidoteController::destroy`).
 */
class PlanController extends Controller
{
    public function index(Request $request): Response
    {
        $planos = Plan::query()
            ->withCount('companies')
            ->when($request->filled('busca'), function ($query) use ($request) {
                $busca = $request->string('busca')->toString();

                $query->where(function ($query) use ($busca) {
                    $query->where('nome', 'like', "%{$busca}%")
                        ->orWhere('slug', 'like', "%{$busca}%");
                });
            })
            ->orderBy('ordem')
            ->orderBy('nome')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Plataforma/Planos/Index', [
            'planos' => $planos,
            'filtros' => $request->only(['busca']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Plataforma/Planos/Create');
    }

    public function store(PlanRequest $request): RedirectResponse
    {
        $plano = Plan::create($request->validated());

        return redirect()->route('plataforma.planos.edit', $plano)
            ->with('success', "Plano \"{$plano->nome}\" criado com sucesso.");
    }

    public function show(Plan $plan): Response
    {
        return Inertia::render('Plataforma/Planos/Show', [
            'plano' => $plan,
            'totalTenants' => $plan->companies()->count(),
        ]);
    }

    public function edit(Plan $plan): Response
    {
        return Inertia::render('Plataforma/Planos/Edit', [
            'plano' => $plan,
        ]);
    }

    public function update(PlanRequest $request, Plan $plan): RedirectResponse
    {
        $plan->update($request->validated());

        return redirect()->route('plataforma.planos.edit', $plan)
            ->with('success', "Plano \"{$plan->nome}\" atualizado com sucesso.");
    }

    /**
     * Recusa a exclusão quando há tenant vinculado, com mensagem clara.
     *
     * A FK de `companies.plan_id` é `nullOnDelete()` (Task 5.1): o banco, por
     * si só, não impediria o `delete()` aqui, só desvincularia os tenants em
     * silêncio. Essa checagem explícita é a regra de negócio real: excluir
     * plano vinculado é recusado, e desativar (`ativo = false`) é o caminho
     * para tirar um plano de circulação sem mexer em quem já o contratou.
     */
    public function destroy(Plan $plan): RedirectResponse
    {
        if ($plan->companies()->exists()) {
            return back()->with(
                'error',
                "O plano \"{$plan->nome}\" não pode ser excluído porque há tenants vinculados a ele. Desative o plano em vez de excluir."
            );
        }

        $plan->delete();

        return redirect()->route('plataforma.planos.index')
            ->with('success', "Plano \"{$plan->nome}\" excluído com sucesso.");
    }
}
