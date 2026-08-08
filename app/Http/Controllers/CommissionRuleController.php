<?php

namespace App\Http\Controllers;

use App\Http\Requests\CommissionRuleRequest;
use App\Models\CommissionRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cadastro de regra de comissão (Plano 23, Task 23.6): percentual ou valor
 * fixo por vendedor, por técnico ou geral da empresa, com vigência.
 *
 * CRUD simples, sem Service dedicado: `CommissionRule` não tem regra de
 * negócio própria além das restrições de campo já cobertas por
 * `CommissionRuleRequest` (tipo x pessoa vinculada, vigência). Mesmo estilo
 * de `ChartOfAccountController` - só criação, edição e exclusão direto no
 * model, sem rota de `create`/`edit` separada (a tela edita por modal sobre a
 * própria listagem).
 *
 * Regra em uso continua alterável e excluível de propósito: diferente de
 * `ChartOfAccount` (que bloqueia exclusão com título vinculado),
 * `CommissionItem` já grava o percentual/valor aplicado no momento da
 * apuração (ver o docblock de `CommissionItem`), então mudar ou apagar a
 * regra depois nunca altera uma comissão já apurada.
 */
class CommissionRuleController extends Controller
{
    /**
     * Todas as regras da empresa corrente, mais recentes primeiro.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $regras = CommissionRule::query()
            ->with(['user:id,name', 'technician:id,name', 'serviceType:id,name'])
            ->orderByDesc('vigencia_inicio')
            ->orderByDesc('id')
            ->get();

        $dados = ['regras' => $regras];

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Commissions/Regras', $dados);
    }

    public function store(CommissionRuleRequest $request): JsonResponse|RedirectResponse
    {
        $regra = CommissionRule::create($request->validated());

        return $this->confirmar($request, 'Regra de comissão criada.', (int) $regra->getKey());
    }

    public function update(CommissionRuleRequest $request, CommissionRule $regra): JsonResponse|RedirectResponse
    {
        $regra->update($request->validated());

        return $this->confirmar($request, 'Regra de comissão atualizada.', (int) $regra->getKey());
    }

    public function destroy(Request $request, CommissionRule $regra): JsonResponse|RedirectResponse
    {
        $regra->delete();

        return $this->confirmar($request, 'Regra de comissão excluída.');
    }

    private function confirmar(Request $request, string $mensagem, ?int $id = null): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $mensagem, 'id' => $id]);
        }

        return back()->with('success', $mensagem);
    }
}
