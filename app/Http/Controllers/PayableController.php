<?php

namespace App\Http\Controllers;

use App\Http\Requests\PayableRequest;
use App\Http\Requests\PayableValueRequest;
use App\Http\Requests\ReversalRequest;
use App\Http\Requests\SettlementRequest;
use App\Models\ChartOfAccount;
use App\Models\Payable;
use App\Models\PayableInstallment;
use App\Models\Supplier;
use App\Services\PayableService;
use App\Support\BusinessDate;
use App\Support\Dinheiro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Contas a pagar (Plano 18, Task 18.7): espelha `ReceivableController` do lado
 * de pagar, trocando o cliente pelo fornecedor e acrescentando a recorrência.
 *
 * Nenhuma linha deste arquivo grava título, parcela ou lançamento de caixa:
 * toda escrita passa por `PayableService`, que mantém parcela e caixa na mesma
 * transação, com a parcela travada, e que é o dono da janela de 3 competências
 * da série recorrente (ver o cabeçalho daquele Service).
 *
 * A listagem é de parcelas, não de títulos, pelo mesmo motivo do lado de
 * receber: é a parcela que tem vencimento, saldo e situação, e é ela que o
 * operador paga e estorna.
 *
 * Isolamento entre empresas: `{titulo}` e `{parcela}` chegam por route-model
 * binding com o escopo global ativo (404 para registro de outra empresa);
 * `chart_of_account_id` vindo do corpo é resolvido aqui pelo model escopado, e
 * `supplier_id` é resolvido dentro do próprio `PayableService`.
 */
class PayableController extends Controller
{
    /**
     * Mesmo teto de linhas da tela de contas a receber, pelo mesmo motivo.
     */
    private const LIMITE_DE_LINHAS = 300;

    /**
     * @var array<int, string>
     */
    private const SITUACOES_EM_ABERTO = ['aberta', 'parcial', 'vencida'];

    public function __construct(private readonly PayableService $titulos) {}

    // -----------------------------------------------------------------
    // Leitura
    // -----------------------------------------------------------------

    /**
     * Parcelas a pagar, com os filtros de situação, período (por vencimento),
     * fornecedor e categoria do plano de contas.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $filtros = $this->filtros($request);

        $dados = [
            'filtros' => $filtros,
            'parcelas' => $this->parcelas($filtros),
            'indicadores' => $this->indicadores(),
            'fornecedores' => $this->fornecedoresParaFiltro(),
            'categorias' => $this->categoriasParaFiltro(),
        ];

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Financeiro/ContasAPagar', $dados);
    }

    // -----------------------------------------------------------------
    // Escrita: sempre pelo PayableService
    // -----------------------------------------------------------------

    /**
     * Cria o título a pagar. Título recorrente já sai com a janela de 3
     * competências gerada.
     */
    public function store(PayableRequest $request): JsonResponse|RedirectResponse
    {
        $dados = $request->validated();

        try {
            $titulo = $this->titulos->criar([
                'supplier_id' => $dados['supplier_id'],
                'descricao' => $dados['descricao'],
                'valor' => $dados['valor'],
                'vencimento' => $dados['vencimento'],
                'emitido_em' => $dados['emitido_em'] ?? null,
                'recorrencia' => $dados['recorrencia'] ?? PayableService::RECORRENCIA_NENHUMA,
                'recorrencia_ate' => $dados['recorrencia_ate'] ?? null,
                'chart_of_account_id' => $this->categoriaDaEmpresa($dados['chart_of_account_id'] ?? null)?->getKey(),
            ]);
        } catch (InvalidArgumentException $erro) {
            return $this->recusar($request, $erro->getMessage());
        }

        return $this->confirmar($request, 'Título a pagar criado.', $titulo->getKey());
    }

    /**
     * Registra o pagamento de uma parcela, total ou parcial.
     */
    public function baixar(SettlementRequest $request, PayableInstallment $parcela): JsonResponse|RedirectResponse
    {
        $this->titulos->baixar($parcela, $request->validated() + ['usuario' => Auth::user()]);

        return $this->confirmar($request, 'Pagamento registrado.', $parcela->getKey());
    }

    /**
     * Estorna o pagamento de uma parcela. Rota e permissão próprias
     * (`financeiro-estornar`), separadas da baixa.
     */
    public function estornar(ReversalRequest $request, PayableInstallment $parcela): JsonResponse|RedirectResponse
    {
        $this->titulos->estornar($parcela, (string) $request->validated()['motivo'], Auth::user());

        return $this->confirmar($request, 'Pagamento estornado.', $parcela->getKey());
    }

    /**
     * Cancela o título e as parcelas ainda não pagas. Em série recorrente,
     * cancelar também interrompe a geração das competências seguintes.
     */
    public function cancelar(Request $request, Payable $titulo): JsonResponse|RedirectResponse
    {
        $this->titulos->cancelar($titulo);

        return $this->confirmar($request, 'Título cancelado.', $titulo->getKey());
    }

    /**
     * Altera o valor do título, com o alcance escolhido na tela: só este, ou
     * este e as competências futuras da mesma série.
     */
    public function alterarValor(PayableValueRequest $request, Payable $titulo): JsonResponse|RedirectResponse
    {
        $dados = $request->validated();

        try {
            $alterados = $this->titulos->alterarValor($titulo, (string) $dados['valor'], (string) $dados['alcance']);
        } catch (InvalidArgumentException $erro) {
            return $this->recusar($request, $erro->getMessage());
        }

        return $this->confirmar(
            $request,
            count($alterados) === 1
                ? 'Valor do título alterado.'
                : count($alterados).' títulos da série tiveram o valor alterado.',
            $titulo->getKey()
        );
    }

    // -----------------------------------------------------------------
    // Composição da leitura
    // -----------------------------------------------------------------

    /**
     * @return array{situacao: string|null, de: string|null, ate: string|null, supplier_id: int|null, chart_of_account_id: int|null}
     */
    private function filtros(Request $request): array
    {
        $situacao = $request->query('situacao');

        return [
            'situacao' => in_array($situacao, PayableInstallment::SITUACOES, true) ? $situacao : null,
            'de' => BusinessDate::diaDe($request->query('de')),
            'ate' => BusinessDate::diaDe($request->query('ate')),
            'supplier_id' => $request->integer('supplier_id') ?: null,
            'chart_of_account_id' => $request->integer('chart_of_account_id') ?: null,
        ];
    }

    /**
     * @param  array{situacao: string|null, de: string|null, ate: string|null, supplier_id: int|null, chart_of_account_id: int|null}  $filtros
     * @return array<int, array<string, mixed>>
     */
    private function parcelas(array $filtros): array
    {
        return PayableInstallment::query()
            ->with(['payable.supplier', 'payable.chartOfAccount'])
            ->when($filtros['situacao'], fn ($consulta, $situacao) => $consulta->where('situacao', $situacao))
            ->when($filtros['de'], fn ($consulta, $dia) => $consulta->where('vencimento', '>=', $dia))
            ->when($filtros['ate'], fn ($consulta, $dia) => $consulta->where('vencimento', '<=', $dia))
            ->when(
                $filtros['supplier_id'] || $filtros['chart_of_account_id'],
                fn ($consulta) => $consulta->whereHas(
                    'payable',
                    function ($titulo) use ($filtros) {
                        $titulo
                            ->when($filtros['supplier_id'], fn ($q, $id) => $q->where('supplier_id', $id))
                            ->when($filtros['chart_of_account_id'], fn ($q, $id) => $q->where('chart_of_account_id', $id));
                    }
                )
            )
            ->orderBy('vencimento')
            ->orderBy('id')
            ->limit(self::LIMITE_DE_LINHAS)
            ->get()
            ->map(fn (PayableInstallment $parcela): array => $this->linhaDaParcela($parcela))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function linhaDaParcela(PayableInstallment $parcela): array
    {
        $titulo = $parcela->payable;
        $saldo = Dinheiro::centavos($parcela->valor) - Dinheiro::centavos($parcela->valor_pago);

        return [
            'id' => (int) $parcela->getKey(),
            'payable_id' => (int) $parcela->payable_id,
            'numero' => (int) $parcela->numero,
            'supplier_id' => $titulo?->supplier_id === null ? null : (int) $titulo->supplier_id,
            'fornecedor' => (string) ($titulo?->supplier?->nome ?? ''),
            'descricao' => (string) ($titulo?->descricao ?? ''),
            'categoria' => $titulo?->chartOfAccount?->nome,
            'chart_of_account_id' => $titulo?->chart_of_account_id === null ? null : (int) $titulo->chart_of_account_id,
            'recorrencia' => (string) ($titulo?->recorrencia ?? PayableService::RECORRENCIA_NENHUMA),
            'recorrente' => ($titulo?->recorrencia ?? PayableService::RECORRENCIA_NENHUMA) !== PayableService::RECORRENCIA_NENHUMA,
            'emitido_em' => BusinessDate::diaDe($titulo?->emitido_em),
            'vencimento' => BusinessDate::diaDe($parcela->vencimento),
            'valor' => (string) $parcela->valor,
            'valor_pago' => (string) $parcela->valor_pago,
            'saldo' => Dinheiro::paraDecimal(max($saldo, 0)),
            'pago_em' => BusinessDate::diaDe($parcela->pago_em),
            'situacao' => (string) $parcela->situacao,
            'situacao_do_titulo' => (string) ($titulo?->situacao ?? ''),
            'vencida' => in_array($parcela->situacao, self::SITUACOES_EM_ABERTO, true)
                && BusinessDate::estaVencido($parcela->vencimento),
        ];
    }

    /**
     * Os quatro números do topo da tela, espelhando os do lado de receber:
     * vence hoje, vencido, a vencer no mês e pago no mês.
     *
     * @return array<string, string|int>
     */
    private function indicadores(): array
    {
        $hoje = BusinessDate::hoje();
        $fimDoMes = $hoje->endOfMonth()->toDateString();
        $inicioDoMes = $hoje->startOfMonth()->toDateString();

        $venceHoje = 0;
        $vencido = 0;
        $aVencerNoMes = 0;
        $quantidadeEmAberto = 0;

        $emAberto = PayableInstallment::query()
            ->whereIn('situacao', self::SITUACOES_EM_ABERTO)
            ->select(['valor', 'valor_pago', 'vencimento']);

        foreach ($emAberto->cursor() as $parcela) {
            $saldo = Dinheiro::centavos($parcela->valor) - Dinheiro::centavos($parcela->valor_pago);

            if ($saldo <= 0) {
                continue;
            }

            $quantidadeEmAberto++;
            $dia = BusinessDate::diaDe($parcela->vencimento);

            if ($dia === null) {
                continue;
            }

            if ($dia === $hoje->toDateString()) {
                $venceHoje += $saldo;
            } elseif ($dia < $hoje->toDateString()) {
                $vencido += $saldo;
            } elseif ($dia <= $fimDoMes) {
                $aVencerNoMes += $saldo;
            }
        }

        $pagoNoMes = 0;

        $quitadasNoMes = PayableInstallment::query()
            ->whereNotNull('pago_em')
            ->whereBetween('pago_em', [$inicioDoMes, $fimDoMes])
            ->select(['valor_pago']);

        foreach ($quitadasNoMes->cursor() as $parcela) {
            $pagoNoMes += Dinheiro::centavos($parcela->valor_pago);
        }

        return [
            'vence_hoje' => Dinheiro::paraDecimal($venceHoje),
            'vencido' => Dinheiro::paraDecimal($vencido),
            'a_vencer_no_mes' => Dinheiro::paraDecimal($aVencerNoMes),
            'pago_no_mes' => Dinheiro::paraDecimal($pagoNoMes),
            'parcelas_em_aberto' => $quantidadeEmAberto,
        ];
    }

    /**
     * Fornecedores ativos, para o seletor de filtro e para o formulário.
     * Diferente do lado de receber (que lista só cliente com título), aqui a
     * lista completa de ativos é a lista útil: fornecedor é cadastro pequeno e
     * o formulário de criação precisa dele inteiro.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fornecedoresParaFiltro(): array
    {
        return Supplier::query()
            ->ativos()
            ->orderBy('nome')
            ->get(['id', 'nome'])
            ->map(fn (Supplier $fornecedor): array => [
                'id' => (int) $fornecedor->getKey(),
                'nome' => (string) $fornecedor->nome,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function categoriasParaFiltro(): array
    {
        return ChartOfAccount::query()
            ->ativas()
            ->where('tipo', 'despesa')
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'nome'])
            ->map(fn (ChartOfAccount $conta): array => [
                'id' => (int) $conta->getKey(),
                'codigo' => (string) $conta->codigo,
                'nome' => (string) $conta->nome,
            ])
            ->all();
    }

    // -----------------------------------------------------------------
    // Resolução escopada e respostas
    // -----------------------------------------------------------------

    private function categoriaDaEmpresa(int|string|null $id): ?ChartOfAccount
    {
        if ($id === null || $id === '') {
            return null;
        }

        return ChartOfAccount::query()->findOrFail((int) $id);
    }

    private function recusar(Request $request, string $mensagem): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $mensagem,
                'errors' => ['titulo' => [$mensagem]],
            ], 422);
        }

        return back()->withInput()->withErrors(['titulo' => $mensagem]);
    }

    private function confirmar(Request $request, string $mensagem, ?int $id = null): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $mensagem, 'id' => $id]);
        }

        return back()->with('success', $mensagem);
    }
}
