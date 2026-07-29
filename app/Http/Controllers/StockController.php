<?php

namespace App\Http\Controllers;

use App\Exceptions\EstoqueInsuficienteException;
use App\Exceptions\LoteVencidoException;
use App\Http\Requests\StockDescarteRequest;
use App\Http\Requests\StockEntradaRequest;
use App\Http\Requests\StockTransferenciaRequest;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Services\BatchSelectionService;
use App\Services\StockService;
use App\Support\BusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Saldo de estoque e as três movimentações manuais do painel (Plano 17,
 * Task 17.7): entrada, transferência e descarte.
 *
 * Nenhuma linha deste arquivo escreve saldo. Toda escrita passa pelo
 * `StockService`, que grava movimento e saldo na mesma transação, com
 * `lockForUpdate`: razão e saldo divergentes são impossíveis de reconciliar
 * depois, porque ninguém sabe qual dos dois estava certo. A baixa por aplicação
 * em campo não entra aqui, é a Task 17.4, disparada pela OS.
 *
 * O que o `index` faz, e o que ele não faz
 * ----------------------------------------
 * Ele compõe uma leitura: soma linhas de `stock_balances` por produto e quebra
 * o resultado por local e por lote. Nenhuma regra de saldo é reimplementada, e
 * a situação de validade de cada lote vem inteira do `BatchSelectionService`
 * (Task 17.3), que já sabe comparar por dia no fuso do negócio e já mostra o
 * lote vencido enquanto ele tiver saldo. Duplicar a comparação de vencimento
 * aqui daria, mais cedo ou mais tarde, uma tela dizendo "vencendo" e um
 * `StockService::saida()` recusando por "vencido" no mesmo dia.
 *
 * Isolamento entre empresas
 * -------------------------
 * Todo id que chega pelo corpo da requisição (`product_id`,
 * `stock_location_id`, `product_batch_id`) é resolvido pelo model escopado, que
 * devolve 404 quando o registro é de outra empresa. Concatenar id sem consultar
 * o model não passa pelo escopo global, e é exatamente assim que se movimenta o
 * lote de uma empresa na conta de outra. O `StockService` confere de novo, por
 * conta própria, porque também roda em fila e na sincronização do aplicativo.
 *
 * Recusa de negócio é 422, nunca 500
 * ----------------------------------
 * `EstoqueInsuficienteException` e `LoteVencidoException` carregam a mensagem
 * pronta, com os números e em português. Deixá-las subir como erro de servidor
 * faria o operador tentar de novo sem entender que o problema é o saldo ou a
 * validade.
 */
class StockController extends Controller
{
    /**
     * Janela, em dias, do "vencendo" da tela: o mesmo marco intermediário dos
     * alertas da Task 17.6 (60, 30 e 7). Mais curto que 30 esconderia o lote
     * que ainda dá tempo de usar; mais longo marcaria metade do estoque.
     */
    private const DIAS_DE_ALERTA_DE_VALIDADE = 30;

    /**
     * Mesmas 4 casas das colunas de quantidade, pelo mesmo motivo do
     * `StockService`: sem arredondar, a sobra binária da soma de float faria um
     * saldo zerado aparecer como 0,0000000001 na tela.
     */
    private const CASAS_DECIMAIS = 4;

    public function __construct(
        private readonly StockService $estoque,
        private readonly BatchSelectionService $lotes,
    ) {}

    // -----------------------------------------------------------------
    // Leitura
    // -----------------------------------------------------------------

    /**
     * Saldo por produto, com a quebra por local e por lote.
     *
     * Filtros aceitos: `product_id`, `stock_location_id`,
     * `validade` (`vencendo` ou `vencido`) e `abaixo_do_minimo`.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $filtros = $this->filtros($request);
        $situacoes = $this->situacaoDeValidadeDosLotes();

        $produtos = $this->saldoPorProduto($filtros, $situacoes);

        $dados = [
            'filtros' => $filtros,
            // Contadores do topo da tela, calculados antes dos filtros de
            // validade e de mínimo: eles são o que o usuário clica para
            // aplicar esses filtros, e mudariam de valor ao serem usados.
            'indicadores' => [
                'abaixo_do_minimo' => $produtos->where('abaixo_do_minimo', true)->count(),
                'lotes_vencendo' => count($situacoes['vencendo']),
                'lotes_vencidos' => count($situacoes['vencidos']),
                'dias_de_alerta' => self::DIAS_DE_ALERTA_DE_VALIDADE,
            ],
            'produtos' => $this->aplicarFiltrosDeSituacao($produtos, $filtros)->values()->all(),
            'locais' => $this->locaisParaFiltro(),
            'produtosDoFiltro' => $this->produtosParaFiltro(),
        ];

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Estoque/Index', $dados);
    }

    // -----------------------------------------------------------------
    // Escrita: sempre pelo StockService
    // -----------------------------------------------------------------

    /**
     * Entrada de estoque: compra recebida, devolução do técnico ou saldo
     * inicial cadastrado depois da contagem física.
     */
    public function entrada(StockEntradaRequest $request): JsonResponse|RedirectResponse
    {
        $dados = $request->validated();

        $produto = $this->produtoDaEmpresa((int) $dados['product_id']);
        $local = $this->localDaEmpresa((int) $dados['stock_location_id']);
        $lote = $this->loteDaEmpresa($dados['product_batch_id'] ?? null);

        try {
            $movimento = $this->estoque->entrada(
                produto: $produto,
                local: $local,
                quantidade: (float) $dados['quantidade'],
                usuario: Auth::user(),
                lote: $lote,
                motivo: $dados['motivo'] ?? null,
                ordemDeServico: null,
                ocorridoEm: $dados['ocorrido_em'] ?? null
            );
        } catch (EstoqueInsuficienteException|LoteVencidoException|InvalidArgumentException $e) {
            return $this->recusar($request, $e->getMessage());
        }

        return $this->confirmar(
            $request,
            $movimento === null
                ? 'Este produto não controla estoque, então nada foi movimentado.'
                : 'Entrada de estoque registrada.',
            $movimento?->getKey()
        );
    }

    /**
     * Transferência entre dois locais: sai de um, entra no outro, na mesma
     * transação e com o mesmo `transferencia_id`.
     */
    public function transferencia(StockTransferenciaRequest $request): JsonResponse|RedirectResponse
    {
        $dados = $request->validated();

        $produto = $this->produtoDaEmpresa((int) $dados['product_id']);
        $origem = $this->localDaEmpresa((int) $dados['origem_id']);
        $destino = $this->localDaEmpresa((int) $dados['destino_id']);
        $lote = $this->loteDaEmpresa($dados['product_batch_id'] ?? null);

        try {
            $transferencia = $this->estoque->transferir(
                produto: $produto,
                origem: $origem,
                destino: $destino,
                quantidade: (float) $dados['quantidade'],
                usuario: Auth::user(),
                lote: $lote,
                motivo: $dados['motivo'] ?? null,
                ocorridoEm: $dados['ocorrido_em'] ?? null
            );
        } catch (EstoqueInsuficienteException|LoteVencidoException|InvalidArgumentException $e) {
            return $this->recusar($request, $e->getMessage());
        }

        return $this->confirmar(
            $request,
            $transferencia === null
                ? 'Este produto não controla estoque, então nada foi movimentado.'
                : 'Transferência registrada.',
            $transferencia !== null ? $transferencia['saida']?->getKey() : null
        );
    }

    /**
     * Descarte: tira do saldo o que foi perdido, quebrado ou venceu. Sempre com
     * motivo, que é o que fecha o ciclo do lote vencido perante fiscalização.
     */
    public function descarte(StockDescarteRequest $request): JsonResponse|RedirectResponse
    {
        $dados = $request->validated();

        $produto = $this->produtoDaEmpresa((int) $dados['product_id']);
        $local = $this->localDaEmpresa((int) $dados['stock_location_id']);
        $lote = $this->loteDaEmpresa($dados['product_batch_id'] ?? null);

        try {
            $movimento = $this->estoque->descartar(
                produto: $produto,
                local: $local,
                quantidade: (float) $dados['quantidade'],
                usuario: Auth::user(),
                motivo: (string) $dados['motivo'],
                lote: $lote,
                ocorridoEm: $dados['ocorrido_em'] ?? null
            );
        } catch (EstoqueInsuficienteException|LoteVencidoException|InvalidArgumentException $e) {
            return $this->recusar($request, $e->getMessage());
        }

        return $this->confirmar(
            $request,
            $movimento === null
                ? 'Este produto não controla estoque, então nada foi movimentado.'
                : 'Descarte registrado.',
            $movimento?->getKey()
        );
    }

    // -----------------------------------------------------------------
    // Composição da leitura
    // -----------------------------------------------------------------

    /**
     * Filtros da listagem, já normalizados.
     *
     * @return array{product_id: int|null, stock_location_id: int|null, validade: string|null, abaixo_do_minimo: bool}
     */
    private function filtros(Request $request): array
    {
        $validade = $request->query('validade');

        return [
            'product_id' => $request->integer('product_id') ?: null,
            'stock_location_id' => $request->integer('stock_location_id') ?: null,
            'validade' => in_array($validade, ['vencendo', 'vencido'], true) ? $validade : null,
            'abaixo_do_minimo' => $request->boolean('abaixo_do_minimo'),
        ];
    }

    /**
     * Ids dos lotes que vencem dentro da janela de alerta e dos que já
     * venceram, os dois **com saldo**.
     *
     * Vem inteiro do `BatchSelectionService`: a regra de "o que é vencido" é
     * dele, e é a mesma que a baixa aplica.
     *
     * @return array{vencendo: array<int, int>, vencidos: array<int, int>}
     */
    private function situacaoDeValidadeDosLotes(): array
    {
        $vencidos = $this->lotes->vencidos()
            ->map(fn (array $linha): int => (int) $linha['lote']->getKey())
            ->all();

        $vencendo = $this->lotes->vencendoEm(self::DIAS_DE_ALERTA_DE_VALIDADE)
            ->map(fn (array $linha): int => (int) $linha['lote']->getKey())
            ->all();

        return ['vencendo' => $vencendo, 'vencidos' => $vencidos];
    }

    /**
     * Uma linha por produto com controle de estoque, com o saldo somado e a
     * quebra por local e por lote.
     *
     * O filtro de local, quando informado, vale para a soma inteira: o total
     * mostrado passa a ser o total naquele local, e não o da empresa com uma
     * quebra parcial, que seria a leitura mais fácil de interpretar errado.
     *
     * @param  array{product_id: int|null, stock_location_id: int|null, validade: string|null, abaixo_do_minimo: bool}  $filtros
     * @param  array{vencendo: array<int, int>, vencidos: array<int, int>}  $situacoes
     * @return Collection<int, array<string, mixed>>
     */
    private function saldoPorProduto(array $filtros, array $situacoes): Collection
    {
        $produtos = Product::query()
            ->where('controla_estoque', true)
            ->when($filtros['product_id'], fn ($consulta, $id) => $consulta->whereKey($id))
            ->orderBy('name')
            ->get();

        if ($produtos->isEmpty()) {
            return collect();
        }

        // Saldo zerado não vira linha da quebra: a combinação continua existindo
        // em `stock_balances` (é assim que o inventário a alcança), mas exibir
        // "0 no depósito" para todo lote já consumido enterraria o que tem
        // saldo de verdade.
        $saldos = StockBalance::query()
            ->whereIn('product_id', $produtos->modelKeys())
            ->when($filtros['stock_location_id'], fn ($consulta, $id) => $consulta->where('stock_location_id', $id))
            ->where('quantidade', '>', 0)
            ->get()
            ->groupBy('product_id');

        $locais = $this->locaisPorId($saldos);
        $lotes = $this->lotesPorId($saldos);

        return $produtos->map(function (Product $produto) use ($saldos, $locais, $lotes, $situacoes): array {
            $linhas = collect($saldos->get($produto->getKey(), collect()));

            $total = $this->normalizar((float) $linhas->sum(fn (StockBalance $linha): float => (float) $linha->quantidade));
            $minimo = $produto->estoque_minimo !== null ? (float) $produto->estoque_minimo : null;

            $porLote = $this->quebraPorLote($linhas, $lotes, $situacoes);

            return [
                'id' => (int) $produto->getKey(),
                'nome' => (string) $produto->name,
                'unidade' => (string) ($produto->unidade ?? 'un'),
                'estoque_minimo' => $minimo,
                'saldo' => $total,
                'abaixo_do_minimo' => $minimo !== null && $total < $minimo,
                'locais' => $this->quebraPorLocal($linhas, $locais),
                'lotes' => $porLote,
                'sem_lote' => $this->normalizar((float) $linhas
                    ->filter(fn (StockBalance $linha): bool => $linha->product_batch_id === null)
                    ->sum(fn (StockBalance $linha): float => (float) $linha->quantidade)),
                'tem_lote_vencendo' => collect($porLote)->contains('situacao', 'vencendo'),
                'tem_lote_vencido' => collect($porLote)->contains('situacao', 'vencido'),
            ];
        });
    }

    /**
     * Quebra do saldo do produto por local, somando os lotes de cada um.
     *
     * @param  Collection<int, StockBalance>  $linhas
     * @param  Collection<int, StockLocation>  $locais
     * @return array<int, array<string, mixed>>
     */
    private function quebraPorLocal(Collection $linhas, Collection $locais): array
    {
        return $linhas
            ->groupBy('stock_location_id')
            ->map(function (Collection $doLocal, $localId) use ($locais): array {
                $local = $locais->get((int) $localId);

                return [
                    'stock_location_id' => (int) $localId,
                    'nome' => (string) ($local?->nome ?? ''),
                    'tipo' => (string) ($local?->tipo ?? ''),
                    'quantidade' => $this->normalizar((float) $doLocal->sum(fn (StockBalance $l): float => (float) $l->quantidade)),
                ];
            })
            ->sortBy('nome')
            ->values()
            ->all();
    }

    /**
     * Quebra do saldo do produto por lote, com validade e situação.
     *
     * A ordem é por validade crescente, que é a ordem do FEFO e a ordem em que
     * o problema aparece.
     *
     * @param  Collection<int, StockBalance>  $linhas
     * @param  Collection<int, ProductBatch>  $lotes
     * @param  array{vencendo: array<int, int>, vencidos: array<int, int>}  $situacoes
     * @return array<int, array<string, mixed>>
     */
    private function quebraPorLote(Collection $linhas, Collection $lotes, array $situacoes): array
    {
        return $linhas
            ->filter(fn (StockBalance $linha): bool => $linha->product_batch_id !== null)
            ->groupBy('product_batch_id')
            ->map(function (Collection $doLote, $loteId) use ($lotes, $situacoes): array {
                $loteId = (int) $loteId;
                $lote = $lotes->get($loteId);

                return [
                    'product_batch_id' => $loteId,
                    'lote' => (string) ($lote?->lote ?? ''),
                    'validade' => BusinessDate::diaDe($lote?->validade),
                    'custo_unitario' => $lote !== null ? (float) $lote->custo_unitario : null,
                    'situacao' => $this->situacaoDoLote($loteId, $situacoes),
                    'quantidade' => $this->normalizar((float) $doLote->sum(fn (StockBalance $l): float => (float) $l->quantidade)),
                ];
            })
            ->sortBy('validade')
            ->values()
            ->all();
    }

    /**
     * `vencido`, `vencendo` ou `normal`, sempre com texto além da cor na tela:
     * a marcação por cor sozinha não é lida por quem não distingue as duas.
     *
     * @param  array{vencendo: array<int, int>, vencidos: array<int, int>}  $situacoes
     */
    private function situacaoDoLote(int $loteId, array $situacoes): string
    {
        if (in_array($loteId, $situacoes['vencidos'], true)) {
            return 'vencido';
        }

        if (in_array($loteId, $situacoes['vencendo'], true)) {
            return 'vencendo';
        }

        return 'normal';
    }

    /**
     * Aplica os filtros que dependem da composição pronta: situação de validade
     * e "abaixo do mínimo".
     *
     * @param  Collection<int, array<string, mixed>>  $produtos
     * @param  array{product_id: int|null, stock_location_id: int|null, validade: string|null, abaixo_do_minimo: bool}  $filtros
     * @return Collection<int, array<string, mixed>>
     */
    private function aplicarFiltrosDeSituacao(Collection $produtos, array $filtros): Collection
    {
        return $produtos
            // Filtrado por local, a lista responde "o que tem nesta van": o
            // produto sem nenhum saldo ali sai, em vez de aparecer zerado no
            // meio do que está lá de verdade.
            ->when(
                $filtros['stock_location_id'] !== null,
                fn (Collection $lista) => $lista->filter(fn (array $produto): bool => $produto['locais'] !== [])
            )
            ->when(
                $filtros['validade'] === 'vencido',
                fn (Collection $lista) => $lista->where('tem_lote_vencido', true)
            )
            ->when(
                $filtros['validade'] === 'vencendo',
                fn (Collection $lista) => $lista->where('tem_lote_vencendo', true)
            )
            ->when(
                $filtros['abaixo_do_minimo'],
                fn (Collection $lista) => $lista->where('abaixo_do_minimo', true)
            );
    }

    /**
     * @param  Collection<int, Collection<int, StockBalance>>  $saldos
     * @return Collection<int, StockLocation>
     */
    private function locaisPorId(Collection $saldos): Collection
    {
        $ids = $saldos->flatten()->pluck('stock_location_id')->unique()->all();

        if ($ids === []) {
            return collect();
        }

        return StockLocation::query()
            ->whereKey($ids)
            ->get()
            ->keyBy(fn (StockLocation $local): int => (int) $local->getKey());
    }

    /**
     * @param  Collection<int, Collection<int, StockBalance>>  $saldos
     * @return Collection<int, ProductBatch>
     */
    private function lotesPorId(Collection $saldos): Collection
    {
        $ids = $saldos->flatten()->pluck('product_batch_id')->filter()->unique()->all();

        if ($ids === []) {
            return collect();
        }

        return ProductBatch::query()
            ->whereKey($ids)
            ->get()
            ->keyBy(fn (ProductBatch $lote): int => (int) $lote->getKey());
    }

    /**
     * Locais ativos, para o seletor de filtro e para os formulários de
     * movimentação.
     *
     * @return array<int, array<string, mixed>>
     */
    private function locaisParaFiltro(): array
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
     * Produtos que controlam estoque, para o seletor de filtro.
     *
     * @return array<int, array<string, mixed>>
     */
    private function produtosParaFiltro(): array
    {
        return Product::query()
            ->where('controla_estoque', true)
            ->orderBy('name')
            ->get(['id', 'name', 'unidade'])
            ->map(fn (Product $produto): array => [
                'id' => (int) $produto->getKey(),
                'nome' => (string) $produto->name,
                'unidade' => (string) ($produto->unidade ?? 'un'),
            ])
            ->all();
    }

    // -----------------------------------------------------------------
    // Resolução escopada e respostas
    // -----------------------------------------------------------------

    /**
     * Produto da empresa corrente, ou 404.
     *
     * O escopo global por empresa é quem faz a consulta não achar o produto de
     * outro tenant; 404, e não 403, porque confirmar a existência do registro
     * já é informação que não pode atravessar a fronteira entre empresas.
     */
    private function produtoDaEmpresa(int $id): Product
    {
        return Product::query()->findOrFail($id);
    }

    private function localDaEmpresa(int $id): StockLocation
    {
        return StockLocation::query()->findOrFail($id);
    }

    private function loteDaEmpresa(int|string|null $id): ?ProductBatch
    {
        if ($id === null || $id === '') {
            return null;
        }

        return ProductBatch::query()->findOrFail((int) $id);
    }

    /**
     * Recusa de negócio: 422 com a mensagem pronta da exceção para quem chama
     * por fetch, e volta ao formulário com o erro no campo para quem chega pela
     * navegação Inertia.
     */
    private function recusar(Request $request, string $mensagem): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $mensagem,
                'errors' => ['estoque' => [$mensagem]],
            ], 422);
        }

        return back()->withInput()->withErrors(['estoque' => $mensagem]);
    }

    private function confirmar(Request $request, string $mensagem, ?int $movimentoId = null): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $mensagem,
                'stock_movement_id' => $movimentoId,
            ]);
        }

        return back()->with('success', $mensagem);
    }

    private function normalizar(float $valor): float
    {
        return round($valor, self::CASAS_DECIMAIS);
    }
}
