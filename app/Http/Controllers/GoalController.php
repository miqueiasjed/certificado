<?php

namespace App\Http\Controllers;

use App\Http\Requests\GoalLoteRequest;
use App\Http\Requests\GoalRequest;
use App\Models\Goal;
use App\Services\Commercial\GoalService;
use App\Support\BusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Meta por pessoa e por competência (Plano 23, Task 23.6): CRUD (com criação
 * em lote) e o acompanhamento do atingimento, que já vem pronto de
 * `GoalService::acompanhamento()`.
 *
 * Duas permissões diferentes convivem aqui, de propósito:
 *
 * - `meta-gerenciar` protege toda escrita (`store`, `storeLote`, `update`,
 *   `destroy`) e a listagem crua (`index`), porque decidir a meta de outra
 *   pessoa é ação de quem gerencia a equipe comercial.
 * - `acompanhamento()` não exige `meta-gerenciar`: qualquer usuário
 *   autenticado consulta o próprio atingimento (mesma ideia de "minhas
 *   metas" que o docblock de `GoalService::acompanhamento()` já previa, com
 *   o parâmetro `$usuario` opcional), e quem tem `meta-gerenciar` continua
 *   vendo a de todo mundo. Ver `escopoDoUsuario()`.
 */
class GoalController extends Controller
{
    public function __construct(private readonly GoalService $metas) {}

    /**
     * Metas cadastradas da competência informada, sem o cálculo de
     * atingimento (isso é `acompanhamento()`): a listagem crua, para editar
     * ou excluir uma meta.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $competencia = $request->query('competencia');

        $metas = Goal::query()
            ->when(is_string($competencia) && $competencia !== '', fn ($consulta) => $consulta->where('competencia', $competencia))
            ->with('user:id,name')
            ->orderBy('competencia', 'desc')
            ->orderBy('user_id')
            ->get();

        $dados = ['competencia' => $competencia, 'metas' => $metas];

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Goals/Index', $dados);
    }

    /**
     * Acompanhamento do atingimento da competência informada: alvo,
     * realizado, percentual atingido e projeção, por pessoa e por tipo de
     * meta. Sem `meta-gerenciar`, cada um só vê a própria.
     */
    public function acompanhamento(Request $request): JsonResponse
    {
        $competencia = $this->competencia($request);
        $usuario = $request->user();

        $dados = $this->metas->acompanhamento(
            $competencia,
            $usuario->can('meta-gerenciar') ? null : $usuario
        );

        return response()->json($dados);
    }

    public function store(GoalRequest $request): JsonResponse|RedirectResponse
    {
        $dados = $request->validated();

        $meta = Goal::create($dados);

        return $this->confirmar($request, 'Meta criada.', (int) $meta->getKey());
    }

    /**
     * Cria (ou atualiza, em reenvio) uma `Goal` por pessoa informada, todas
     * na mesma competência. `updateOrCreate` em vez de `create`: reenviar o
     * lote para corrigir o alvo de uma única pessoa não pode falhar para o
     * restante por violar o unique das linhas que não mudaram.
     */
    public function storeLote(GoalLoteRequest $request): JsonResponse|RedirectResponse
    {
        $dados = $request->validated();

        foreach ($dados['metas'] as $linha) {
            Goal::query()->updateOrCreate(
                [
                    'user_id' => $linha['user_id'],
                    'tipo' => $linha['tipo'],
                    'competencia' => $dados['competencia'],
                ],
                [
                    'alvo' => $linha['alvo'],
                    'observacao' => $linha['observacao'] ?? null,
                ]
            );
        }

        $quantidade = count($dados['metas']);

        return $this->confirmar(
            $request,
            sprintf('%d meta(s) gravada(s) para a competência %s.', $quantidade, $dados['competencia']),
            null,
            ['quantidade' => $quantidade]
        );
    }

    public function update(GoalRequest $request, Goal $meta): JsonResponse|RedirectResponse
    {
        $meta->update($request->validated());

        return $this->confirmar($request, 'Meta atualizada.', (int) $meta->getKey());
    }

    public function destroy(Request $request, Goal $meta): JsonResponse|RedirectResponse
    {
        $meta->delete();

        return $this->confirmar($request, 'Meta excluída.');
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function competencia(Request $request): string
    {
        $competencia = $request->query('competencia');

        return is_string($competencia) && $competencia !== ''
            ? $competencia
            : BusinessDate::hoje()->format('Y-m');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function confirmar(Request $request, string $mensagem, ?int $id = null, array $extra = []): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(array_merge(['message' => $mensagem, 'id' => $id], $extra));
        }

        return back()->with('success', $mensagem);
    }
}
