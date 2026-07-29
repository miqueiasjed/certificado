<?php

namespace App\Http\Controllers;

use App\Http\Requests\InventoryCountRequest;
use App\Http\Requests\InventoryRequest;
use App\Models\Inventory;
use App\Models\InventoryItem;
use App\Models\StockLocation;
use App\Services\InventoryService;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * O processo de contagem física (Plano 17, Task 17.7): abrir, contar,
 * finalizar e cancelar.
 *
 * Toda a regra vive no `InventoryService` (Task 17.5): a fotografia do saldo na
 * abertura, a justificativa obrigatória em toda diferença, a recusa de fechar
 * contagem incompleta e o ajuste gerado por item divergente, que é o único
 * caminho pelo qual um inventário mexe em saldo, e ainda assim pelo
 * `StockService`. Este controller resolve os models pela rota, chama o Service e
 * transforma a recusa dele em 422.
 *
 * O saldo do sistema vai na resposta, e a tela é que o esconde
 * -----------------------------------------------------------
 * `saldo_sistema` é devolvido em `show`, porque a tela da Task 17.9 precisa dele
 * para o botão "revelar" de cada linha e para o resumo de divergências antes do
 * fechamento. Quem decide não mostrar por padrão é a tela: contar vendo o número
 * esperado tende a confirmar o número esperado, que é o erro mais comum de
 * inventário.
 *
 * Isolamento entre empresas
 * -------------------------
 * `{inventario}` e `{item}` chegam pelo route-model binding, já escopados por
 * empresa. A checagem explícita repete a conta e acrescenta a que o escopo não
 * faz: o item precisa ser **daquele** inventário, senão a contagem de uma rodada
 * entraria em outra.
 */
class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventarios,
    ) {}

    // -----------------------------------------------------------------
    // Leitura
    // -----------------------------------------------------------------

    /**
     * Inventários da empresa, mais recentes primeiro, com o andamento da
     * contagem de cada um.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $inventarios = Inventory::query()
            ->with(['stockLocation:id,nome,tipo', 'abertoPor:id,name', 'finalizadoPor:id,name'])
            ->withCount([
                'items as itens_total',
                'items as itens_contados' => fn ($consulta) => $consulta->whereNotNull('saldo_contado'),
            ])
            ->when(
                $request->query('situacao'),
                fn ($consulta, $situacao) => $consulta->where('situacao', $situacao)
            )
            ->orderByDesc('aberto_em')
            ->orderByDesc('id')
            ->get();

        $dados = [
            'inventarios' => $inventarios->map(fn (Inventory $inventario): array => $this->resumo($inventario))->all(),
            'locais' => $this->locaisParaAbertura(),
        ];

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Estoque/Inventario', $dados);
    }

    /**
     * Um inventário com os itens a contar.
     */
    public function show(Request $request, Inventory $inventario): Response|JsonResponse
    {
        $this->garantirDaEmpresa($inventario);

        $inventario->loadMissing(['stockLocation:id,nome,tipo', 'abertoPor:id,name', 'finalizadoPor:id,name']);

        $itens = InventoryItem::query()
            ->where('inventory_id', $inventario->getKey())
            ->with(['product:id,name,unidade', 'productBatch:id,lote,validade'])
            ->orderBy('id')
            ->get();

        $dados = [
            'inventario' => $this->resumo($inventario, $itens->count(), $itens->filter->foiContado()->count()),
            'itens' => $itens->map(fn (InventoryItem $item): array => [
                'id' => (int) $item->getKey(),
                'produto' => (string) ($item->product?->name ?? ''),
                'unidade' => (string) ($item->product?->unidade ?? 'un'),
                'lote' => $item->productBatch?->lote,
                'validade' => BusinessDate::diaDe($item->productBatch?->validade),
                'saldo_sistema' => (float) $item->saldo_sistema,
                'saldo_contado' => $item->saldo_contado !== null ? (float) $item->saldo_contado : null,
                'diferenca' => $item->diferenca !== null ? (float) $item->diferenca : null,
                'justificativa' => $item->justificativa,
                'contado' => $item->foiContado(),
            ])->all(),
        ];

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Estoque/Inventario', $dados);
    }

    // -----------------------------------------------------------------
    // Fluxo da contagem
    // -----------------------------------------------------------------

    /**
     * Abre a contagem de um local, congelando o saldo item a item.
     */
    public function store(InventoryRequest $request): JsonResponse|RedirectResponse
    {
        $local = StockLocation::query()->findOrFail((int) $request->validated('stock_location_id'));

        try {
            $inventario = $this->inventarios->abrir($local, Auth::user());
        } catch (InvalidArgumentException $e) {
            return $this->recusar($request, $e->getMessage());
        }

        if ($request->filled('observacao')) {
            $inventario->observacao = $request->validated('observacao');
            $inventario->save();
        }

        return $this->confirmar($request, 'Inventário aberto.', $inventario);
    }

    /**
     * Grava a contagem de um item.
     */
    public function contar(
        InventoryCountRequest $request,
        Inventory $inventario,
        InventoryItem $item
    ): JsonResponse|RedirectResponse {
        $this->garantirDaEmpresa($inventario);
        $this->garantirItemDoInventario($inventario, $item);

        try {
            $this->inventarios->contar(
                $item,
                (float) $request->validated('contado'),
                $request->validated('justificativa')
            );
        } catch (InvalidArgumentException $e) {
            return $this->recusar($request, $e->getMessage());
        }

        return $this->confirmar($request, 'Contagem registrada.', $inventario);
    }

    /**
     * Fecha a contagem e gera o ajuste de cada item divergente.
     */
    public function finalizar(Request $request, Inventory $inventario): JsonResponse|RedirectResponse
    {
        $this->garantirDaEmpresa($inventario);

        try {
            $this->inventarios->finalizar($inventario, Auth::user());
        } catch (InvalidArgumentException $e) {
            return $this->recusar($request, $e->getMessage());
        }

        return $this->confirmar($request, 'Inventário finalizado.', $inventario->fresh());
    }

    /**
     * Descarta a contagem sem tocar no saldo.
     */
    public function cancelar(Request $request, Inventory $inventario): JsonResponse|RedirectResponse
    {
        $this->garantirDaEmpresa($inventario);

        try {
            $this->inventarios->cancelar($inventario, Auth::user());
        } catch (InvalidArgumentException $e) {
            return $this->recusar($request, $e->getMessage());
        }

        return $this->confirmar($request, 'Inventário cancelado.', $inventario->fresh());
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function resumo(Inventory $inventario, ?int $total = null, ?int $contados = null): array
    {
        return [
            'id' => (int) $inventario->getKey(),
            'stock_location_id' => (int) $inventario->stock_location_id,
            'local' => (string) ($inventario->stockLocation?->nome ?? ''),
            'situacao' => (string) $inventario->situacao,
            'aberto_por' => (string) ($inventario->abertoPor?->name ?? ''),
            'aberto_em' => BusinessDate::diaDe($inventario->aberto_em),
            'finalizado_por' => $inventario->finalizadoPor?->name,
            'finalizado_em' => BusinessDate::diaDe($inventario->finalizado_em),
            'observacao' => $inventario->observacao,
            'itens_total' => $total ?? (int) ($inventario->itens_total ?? 0),
            'itens_contados' => $contados ?? (int) ($inventario->itens_contados ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function locaisParaAbertura(): array
    {
        return StockLocation::query()
            ->ativos()
            ->orderBy('nome')
            ->get(['id', 'nome', 'tipo'])
            ->map(fn (StockLocation $local): array => [
                'id' => (int) $local->getKey(),
                'nome' => (string) $local->nome,
                'tipo' => (string) $local->tipo,
            ])
            ->all();
    }

    /**
     * Rede contra inventário de outra empresa chegando pelo route-model
     * binding. Ver o mesmo comentário em `ProductBatchController`.
     */
    private function garantirDaEmpresa(Inventory $inventario): void
    {
        $empresa = TenantAtual::id();

        if ($empresa !== null && (int) $inventario->company_id !== $empresa) {
            abort(404);
        }
    }

    /**
     * O item precisa ser daquele inventário. O escopo por empresa não separa
     * uma rodada de contagem da outra, e gravar a contagem de um item na rodada
     * errada produziria um ajuste que ninguém consegue explicar depois.
     */
    private function garantirItemDoInventario(Inventory $inventario, InventoryItem $item): void
    {
        if ((int) $item->inventory_id !== (int) $inventario->getKey()) {
            abort(404);
        }

        $empresa = TenantAtual::id();

        if ($empresa !== null && (int) $item->company_id !== $empresa) {
            abort(404);
        }
    }

    private function recusar(Request $request, string $mensagem): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $mensagem,
                'errors' => ['inventario' => [$mensagem]],
            ], 422);
        }

        return back()->withInput()->withErrors(['inventario' => $mensagem]);
    }

    private function confirmar(Request $request, string $mensagem, ?Inventory $inventario = null): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $mensagem,
                'inventario' => $inventario !== null ? [
                    'id' => (int) $inventario->getKey(),
                    'situacao' => (string) $inventario->situacao,
                ] : null,
            ]);
        }

        return back()->with('success', $mensagem);
    }
}
