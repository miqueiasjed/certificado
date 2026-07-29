<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChartOfAccountRequest;
use App\Models\ChartOfAccount;
use App\Services\ChartOfAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Plano de contas (Plano 18, Task 18.7): CRUD das categorias de receita e de
 * despesa, com hierarquia por `parent_id`.
 *
 * As regras da árvore (subcategoria com o tipo da mãe, hierarquia sem ciclo,
 * categoria em uso desativada em vez de excluída) vivem em
 * `ChartOfAccountService`. Aqui ficam a composição da árvore para a tela e a
 * resolução escopada da categoria-mãe que chega pelo corpo da requisição:
 * `parent_id` de outra empresa devolve 404, e não 403, porque confirmar a
 * existência do registro já vaza informação entre tenants.
 */
class ChartOfAccountController extends Controller
{
    public function __construct(private readonly ChartOfAccountService $contas) {}

    /**
     * A árvore inteira de categorias, com a marcação de quais já classificam
     * título (as que não podem ser excluídas, só desativadas).
     */
    public function index(Request $request): Response|JsonResponse
    {
        $dados = [
            'contas' => $this->arvore(),
        ];

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Financeiro/PlanoDeContas', $dados);
    }

    public function store(ChartOfAccountRequest $request): JsonResponse|RedirectResponse
    {
        $dados = $request->validated();

        $conta = $this->contas->criar($dados, $this->maeDaEmpresa($dados['parent_id'] ?? null));

        return $this->confirmar($request, 'Categoria criada.', (int) $conta->getKey());
    }

    public function update(ChartOfAccountRequest $request, ChartOfAccount $conta): JsonResponse|RedirectResponse
    {
        $dados = $request->validated();

        $this->contas->atualizar($conta, $dados, $this->maeDaEmpresa($dados['parent_id'] ?? null));

        return $this->confirmar($request, 'Categoria atualizada.', (int) $conta->getKey());
    }

    /**
     * Exclui a categoria. Categoria com subcategoria ou com título vinculado é
     * recusada com 422 e a instrução de desativar: o Service explica qual dos
     * dois casos é.
     */
    public function destroy(Request $request, ChartOfAccount $conta): JsonResponse|RedirectResponse
    {
        $this->contas->excluir($conta);

        return $this->confirmar($request, 'Categoria excluída.');
    }

    // -----------------------------------------------------------------
    // Composição da leitura
    // -----------------------------------------------------------------

    /**
     * Árvore de categorias, montada em memória a partir de uma consulta só:
     * o plano de contas de uma empresa tem dezenas de linhas, não milhares, e
     * uma consulta por nível daria N+1 para desenhar a mesma coisa.
     *
     * @return array<int, array<string, mixed>>
     */
    private function arvore(): array
    {
        $contas = ChartOfAccount::query()
            ->withCount(['receivables', 'payables', 'children'])
            ->orderBy('tipo')
            ->orderBy('codigo')
            ->get();

        $porMae = $contas->groupBy(fn (ChartOfAccount $conta): int => (int) ($conta->parent_id ?? 0));

        return $this->ramos($porMae, 0);
    }

    /**
     * @param  Collection<int, Collection<int, ChartOfAccount>>  $porMae
     * @return array<int, array<string, mixed>>
     */
    private function ramos(Collection $porMae, int $maeId): array
    {
        return collect($porMae->get($maeId, collect()))
            ->map(fn (ChartOfAccount $conta): array => [
                'id' => (int) $conta->getKey(),
                'codigo' => (string) $conta->codigo,
                'nome' => (string) $conta->nome,
                'tipo' => (string) $conta->tipo,
                'ativo' => (bool) $conta->ativo,
                'parent_id' => $conta->parent_id === null ? null : (int) $conta->parent_id,
                'titulos_vinculados' => (int) $conta->receivables_count + (int) $conta->payables_count,
                // A tela precisa saber, antes de oferecer o botão, que esta
                // categoria só pode ser desativada.
                'pode_excluir' => (int) $conta->receivables_count === 0
                    && (int) $conta->payables_count === 0
                    && (int) $conta->children_count === 0,
                'filhos' => $this->ramos($porMae, (int) $conta->getKey()),
            ])
            ->all();
    }

    // -----------------------------------------------------------------
    // Resolução escopada e respostas
    // -----------------------------------------------------------------

    private function maeDaEmpresa(int|string|null $id): ?ChartOfAccount
    {
        if ($id === null || $id === '') {
            return null;
        }

        return ChartOfAccount::query()->findOrFail((int) $id);
    }

    private function confirmar(Request $request, string $mensagem, ?int $id = null): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $mensagem, 'id' => $id]);
        }

        return back()->with('success', $mensagem);
    }
}
