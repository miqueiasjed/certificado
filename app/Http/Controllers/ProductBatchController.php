<?php

namespace App\Http\Controllers;

use App\Exceptions\EstoqueInsuficienteException;
use App\Exceptions\LoteVencidoException;
use App\Http\Requests\ProductBatchRequest;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Services\StockService;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Barryvdh\DomPDF\Facade\Pdf as FacadePdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Cadastro de lote e a consulta de rastreabilidade (Plano 17, Task 17.7).
 *
 * O lote é o que dá sentido ao módulo inteiro: é ele que carrega validade e
 * custo, e é por ele que se responde qual produto foi aplicado em qual cliente,
 * a primeira pergunta da fiscalização em caso de incidente.
 *
 * Lote com movimento não é excluído
 * ---------------------------------
 * `destroy` recusa com 422 quando existe qualquer movimento no razão apontando
 * para o lote. Apagar o histórico de aplicação de saneante é o oposto do que
 * este módulo existe para fazer, e o caminho correto para tirar saldo da
 * prateleira é o descarte, sempre com motivo
 * (`StockService::descartar()`). A exclusão continua valendo para o lote
 * cadastrado por engano, que nunca movimentou nada.
 *
 * Entrada inicial no cadastro
 * ---------------------------
 * Informada a quantidade, o lote nasce e o saldo entra na mesma transação, pelo
 * `StockService` (nenhuma escrita de saldo acontece fora dele). Ou os dois
 * acontecem, ou nenhum: lote cadastrado com a entrada recusada deixaria o
 * usuário achando que o saldo entrou.
 *
 * Rastreabilidade é endpoint próprio
 * ----------------------------------
 * Em incidente a informação precisa sair rápido, e por isso ela é uma URL
 * direta, aberta a qualquer usuário com `estoque-ver`, e não um filtro escondido
 * dentro de um relatório.
 */
class ProductBatchController extends Controller
{
    public function __construct(
        private readonly StockService $estoque,
    ) {}

    // -----------------------------------------------------------------
    // Listagem
    // -----------------------------------------------------------------

    /**
     * Lotes da empresa, com produto, validade, custo e saldo somado.
     *
     * Ordem padrão por validade crescente: é a ordem do FEFO e a ordem em que o
     * problema aparece.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $produtoId = $request->integer('product_id') ?: null;

        $lotes = ProductBatch::query()
            ->with('product:id,name,unidade')
            ->when($produtoId, fn ($consulta, $id) => $consulta->where('product_id', $id))
            ->orderBy('validade')
            ->orderBy('id')
            ->get();

        $saldos = $this->saldoDosLotes($lotes->modelKeys());

        $dados = [
            'filtros' => ['product_id' => $produtoId],
            'lotes' => $lotes->map(fn (ProductBatch $lote): array => [
                'id' => (int) $lote->getKey(),
                'product_id' => (int) $lote->product_id,
                'produto' => (string) ($lote->product?->name ?? ''),
                'unidade' => (string) ($lote->product?->unidade ?? 'un'),
                'lote' => (string) $lote->lote,
                'validade' => BusinessDate::diaDe($lote->validade),
                'recebido_em' => BusinessDate::diaDe($lote->recebido_em),
                'custo_unitario' => (float) $lote->custo_unitario,
                'fornecedor' => $lote->fornecedor,
                'nota_fiscal' => $lote->nota_fiscal,
                'saldo' => (float) ($saldos[(int) $lote->getKey()] ?? 0.0),
                'vencido' => BusinessDate::estaVencido($lote->validade),
            ])->all(),
            'produtos' => $this->produtosParaFiltro(),
            'locais' => $this->locaisParaEntradaInicial(),
        ];

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Estoque/Lotes', $dados);
    }

    // -----------------------------------------------------------------
    // Cadastro e edição
    // -----------------------------------------------------------------

    /**
     * Cadastra o lote e, quando informada a quantidade, registra a entrada
     * inicial no local escolhido.
     */
    public function store(ProductBatchRequest $request): JsonResponse|RedirectResponse
    {
        $dados = $request->validated();

        // O produto vem por id no corpo da requisição: resolver pelo model
        // escopado é o que impede cadastrar lote no produto de outra empresa.
        $produto = Product::query()->findOrFail((int) $dados['product_id']);

        $quantidade = isset($dados['quantidade']) ? (float) $dados['quantidade'] : null;
        $local = $quantidade !== null
            ? StockLocation::query()->findOrFail((int) $dados['stock_location_id'])
            : null;

        try {
            $lote = DB::transaction(function () use ($dados, $produto, $local, $quantidade): ProductBatch {
                $lote = new ProductBatch([
                    'product_id' => $produto->getKey(),
                    'lote' => $dados['lote'],
                    'validade' => $dados['validade'],
                    'recebido_em' => $dados['recebido_em'],
                    'custo_unitario' => $dados['custo_unitario'],
                    'fornecedor' => $dados['fornecedor'] ?? null,
                    'nota_fiscal' => $dados['nota_fiscal'] ?? null,
                ]);

                // `company_id` é preenchido pela trait `BelongsToCompany` a
                // partir do tenant corrente, e nunca pelo corpo da requisição.
                $lote->save();

                if ($local !== null && $quantidade !== null) {
                    $this->estoque->entrada(
                        produto: $produto,
                        local: $local,
                        quantidade: $quantidade,
                        usuario: Auth::user(),
                        lote: $lote,
                        motivo: 'Entrada inicial do lote '.$lote->lote
                    );
                }

                return $lote;
            });
        } catch (EstoqueInsuficienteException|LoteVencidoException|InvalidArgumentException $e) {
            return $this->recusar($request, $e->getMessage());
        }

        return $this->confirmar($request, 'Lote cadastrado com sucesso.', $lote);
    }

    /**
     * Edita os dados do lote. O produto não muda: ver o `ProductBatchRequest`.
     */
    public function update(ProductBatchRequest $request, ProductBatch $lote): JsonResponse|RedirectResponse
    {
        $this->garantirDaEmpresa($lote);

        $lote->update($request->validated());

        return $this->confirmar($request, 'Lote atualizado com sucesso.', $lote->fresh());
    }

    /**
     * Exclui o lote, desde que ele nunca tenha movimentado.
     */
    public function destroy(Request $request, ProductBatch $lote): JsonResponse|RedirectResponse
    {
        $this->garantirDaEmpresa($lote);

        if (StockMovement::query()->where('product_batch_id', $lote->getKey())->exists()) {
            return $this->recusar(
                $request,
                'Este lote já tem movimentação registrada e não pode ser excluído. '
                .'Para tirar da prateleira o que sobrou, registre um descarte com motivo.'
            );
        }

        DB::transaction(function () use ($lote): void {
            // Linhas de saldo sem nenhum movimento por trás são resíduo de
            // conferência (`StockService::recalcularSaldo()` as enxerga e as
            // zera). Sem movimento no razão, elas valem zero por definição, e
            // são apagadas aqui só porque a chave estrangeira impediria a
            // exclusão do lote.
            StockBalance::query()->where('product_batch_id', $lote->getKey())->delete();

            $lote->delete();
        });

        return $this->confirmar($request, 'Lote excluído.');
    }

    // -----------------------------------------------------------------
    // Rastreabilidade
    // -----------------------------------------------------------------

    /**
     * Todas as ordens de serviço que aplicaram este lote, com cliente,
     * endereço, data e quantidade.
     *
     * A consulta é sobre o razão (`stock_movements`), e não sobre os produtos
     * declarados na OS: o razão é o registro imutável do que saiu do estoque, e
     * é ele que sustenta a resposta à fiscalização. Só `saida` com
     * `work_order_id` entra: transferência, descarte e ajuste mexem no saldo
     * sem que nada tenha sido aplicado em cliente nenhum.
     */
    public function rastreabilidade(Request $request, ProductBatch $lote): Response|JsonResponse
    {
        $this->garantirDaEmpresa($lote);

        $dados = $this->dadosDeRastreabilidade($lote);

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Estoque/Rastreabilidade', $dados);
    }

    /**
     * O mesmo relatório de `rastreabilidade()`, em PDF: o formato que a
     * fiscalização recebe (Task 17.9). Cabeçalho da empresa, os dados do lote
     * e a lista completa de aplicações, sem paginação nem filtro: é o
     * documento inteiro, ou nada.
     */
    public function rastreabilidadePdf(ProductBatch $lote): HttpResponse
    {
        $this->garantirDaEmpresa($lote);

        $dados = $this->dadosDeRastreabilidade($lote);
        $empresa = Company::current();

        $pdf = FacadePdf::loadView('pdf.lote-rastreabilidade', [
            'lote' => $dados['lote'],
            'aplicacoes' => $dados['aplicacoes'],
            'totalAplicado' => $dados['total_aplicado'],
            'company' => $empresa,
            'logoSrc' => $this->convertStorageFileToBase64($empresa->logo_path),
            'geradoEm' => now()->format('d/m/Y H:i'),
        ])->setPaper('A4', 'portrait');

        $nomeArquivo = 'rastreabilidade-lote-'.$dados['lote']['lote'].'.pdf';

        return $pdf->stream($nomeArquivo);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * A consulta por trás da rastreabilidade, em tela ou em PDF: só `saida`
     * com `work_order_id` entra (transferência, descarte e ajuste mexem no
     * saldo sem que nada tenha sido aplicado em cliente nenhum). Ver o
     * comentário de classe sobre por que a consulta é sobre o razão, e não
     * sobre os produtos declarados na OS.
     *
     * @return array<string, mixed>
     */
    private function dadosDeRastreabilidade(ProductBatch $lote): array
    {
        $lote->loadMissing('product');

        $movimentos = StockMovement::query()
            ->where('product_batch_id', $lote->getKey())
            ->where('tipo', StockService::TIPO_SAIDA)
            ->whereNotNull('work_order_id')
            ->with([
                'workOrder:id,order_number,client_id,address_id,technician_id,scheduled_date',
                'workOrder.client:id,name',
                'workOrder.address:id,nickname,street,number,district,city,state',
                'workOrder.technician:id,name',
                'stockLocation:id,nome,tipo',
                'user:id,name',
            ])
            ->orderBy('ocorrido_em')
            ->orderBy('id')
            ->get();

        $aplicacoes = $movimentos->map(function (StockMovement $movimento): array {
            $os = $movimento->workOrder;

            return [
                'stock_movement_id' => (int) $movimento->getKey(),
                'work_order_id' => $os !== null ? (int) $os->getKey() : null,
                'numero' => (string) ($os->order_number ?? ''),
                'data' => BusinessDate::diaDe($movimento->ocorrido_em),
                'cliente' => (string) ($os?->client?->name ?? ''),
                'endereco' => $this->enderecoLegivel($os?->address),
                'tecnico' => $os?->technician?->name,
                'local' => (string) ($movimento->stockLocation?->nome ?? ''),
                'quantidade' => (float) $movimento->quantidade,
                'registrado_por' => (string) ($movimento->user?->name ?? ''),
            ];
        })->all();

        return [
            'lote' => [
                'id' => (int) $lote->getKey(),
                'lote' => (string) $lote->lote,
                'produto' => (string) ($lote->product?->name ?? ''),
                'unidade' => (string) ($lote->product?->unidade ?? 'un'),
                'validade' => BusinessDate::diaDe($lote->validade),
                'recebido_em' => BusinessDate::diaDe($lote->recebido_em),
                'custo_unitario' => (float) $lote->custo_unitario,
                'fornecedor' => $lote->fornecedor,
                'nota_fiscal' => $lote->nota_fiscal,
                'vencido' => BusinessDate::estaVencido($lote->validade),
                'saldo' => $lote->product !== null
                    ? $this->estoque->saldo($lote->product, null, $lote)
                    : 0.0,
            ],
            'aplicacoes' => $aplicacoes,
            'total_aplicado' => round((float) $movimentos->sum(fn (StockMovement $m): float => (float) $m->quantidade), 4),
        ];
    }

    /**
     * Converte a imagem gravada no disco em Base64, para o dompdf conseguir
     * embutir a logo no PDF sem depender de acesso HTTP ao storage. Mesmo
     * padrão já usado em `WorkOrderService`, `CertificateService`,
     * `ContractService` e `BudgetService`.
     */
    private function convertStorageFileToBase64(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $fullPath = storage_path('app/public/'.$path);

        if (! file_exists($fullPath)) {
            return null;
        }

        $extensao = pathinfo($fullPath, PATHINFO_EXTENSION);
        $dados = file_get_contents($fullPath);

        $mime = match (strtolower($extensao)) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        return 'data:'.$mime.';base64,'.base64_encode($dados);
    }

    /**
     * Saldo somado de cada lote, em todos os locais.
     *
     * @param  array<int, int>  $loteIds
     * @return array<int, float>
     */
    private function saldoDosLotes(array $loteIds): array
    {
        if ($loteIds === []) {
            return [];
        }

        return StockBalance::query()
            ->whereIn('product_batch_id', $loteIds)
            ->selectRaw('product_batch_id, SUM(quantidade) as total')
            ->groupBy('product_batch_id')
            ->pluck('total', 'product_batch_id')
            ->map(fn ($total): float => round((float) $total, 4))
            ->all();
    }

    /**
     * Endereço em uma linha, no formato que a fiscalização espera ler.
     */
    private function enderecoLegivel(mixed $endereco): string
    {
        if ($endereco === null) {
            return '';
        }

        $partes = array_filter([
            trim(($endereco->street ?? '').' '.($endereco->number ?? '')),
            $endereco->district ?? null,
            trim(($endereco->city ?? '').($endereco->state ? '/'.$endereco->state : '')),
        ]);

        return implode(', ', $partes);
    }

    /**
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function locaisParaEntradaInicial(): array
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
     * Rede contra registro de outra empresa chegando pelo route-model binding.
     *
     * O escopo global por empresa já faz o binding não encontrar o lote de
     * outro tenant (e responder 404 sozinho). Esta checagem é a segunda
     * tranca, para o caso de o escopo não ter tenant resolvido: 404, e não 403,
     * porque confirmar a existência do registro já vaza informação entre
     * empresas concorrentes.
     */
    private function garantirDaEmpresa(ProductBatch $lote): void
    {
        $empresa = TenantAtual::id();

        if ($empresa !== null && (int) $lote->company_id !== $empresa) {
            abort(404);
        }
    }

    private function recusar(Request $request, string $mensagem): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $mensagem,
                'errors' => ['lote' => [$mensagem]],
            ], 422);
        }

        return back()->withInput()->withErrors(['lote' => $mensagem]);
    }

    private function confirmar(Request $request, string $mensagem, ?ProductBatch $lote = null): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $mensagem,
                'lote' => $lote?->only(['id', 'product_id', 'lote', 'validade', 'custo_unitario']),
            ]);
        }

        return back()->with('success', $mensagem);
    }
}
