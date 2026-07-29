<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReceivableRequest;
use App\Http\Requests\ReversalRequest;
use App\Http\Requests\SettlementRequest;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Receivable;
use App\Models\ReceivableInstallment;
use App\Models\WorkOrder;
use App\Services\ReceivableService;
use App\Services\SettlementService;
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
 * Contas a receber (Plano 18, Task 18.7): a listagem de parcelas com filtros,
 * a criação do título, a baixa, o estorno e o cancelamento.
 *
 * Nenhuma linha deste arquivo grava título, parcela ou lançamento de caixa.
 * Toda escrita passa por `ReceivableService` (criação e cancelamento) e por
 * `SettlementService` (baixa e estorno), que fazem parcela e caixa nascerem na
 * mesma transação, com a parcela travada. Dinheiro no caixa sem parcela
 * quitada, ou o inverso, são os dois estados que ninguém consegue conciliar
 * depois.
 *
 * A listagem é de parcelas, não de títulos
 * ----------------------------------------
 * É a parcela que tem vencimento, saldo e situação, e é ela que o operador
 * cobra, baixa e estorna. Uma listagem de títulos obrigaria a abrir cada um
 * para saber o que vence esta semana, que é a única pergunta que essa tela
 * existe para responder.
 *
 * Isolamento entre empresas
 * -------------------------
 * `{titulo}` e `{parcela}` chegam por route-model binding com o escopo global
 * de empresa ativo: registro de outra empresa não é encontrado e a resposta é
 * 404, nunca 403, porque confirmar a existência já vaza informação entre
 * tenants. Todo id que chega pelo **corpo** da requisição (`client_id`,
 * `work_order_id`, `chart_of_account_id`) é resolvido aqui pelo model
 * escopado, pelo mesmo motivo documentado em `StockController`: concatenar id
 * sem consultar o model não passa pelo escopo global.
 *
 * Recusa de negócio é 422, nunca 500
 * ----------------------------------
 * `ValidationException` dos Services já sai como 422 pelo handler do
 * framework. `InvalidArgumentException` (dado incoerente que a validação de
 * formato não alcança, como parcelamento sem intervalo) é traduzida aqui, com
 * a mensagem pronta em português.
 */
class ReceivableController extends Controller
{
    /**
     * Teto de linhas da listagem. A tela é operacional (o que vence, o que
     * está vencido), não um relatório histórico: sem teto, uma empresa com
     * anos de parcela quitada mandaria dezenas de milhares de linhas para o
     * navegador a cada abertura. Quem precisa de mais estreita o filtro de
     * período.
     */
    private const LIMITE_DE_LINHAS = 300;

    /**
     * Situações de parcela que ainda representam saldo a cobrar, mesma lista
     * de `AgingService` e `CashForecastService`.
     *
     * @var array<int, string>
     */
    private const SITUACOES_EM_ABERTO = ['aberta', 'parcial', 'vencida'];

    public function __construct(
        private readonly ReceivableService $titulos,
        private readonly SettlementService $baixas,
    ) {}

    // -----------------------------------------------------------------
    // Leitura
    // -----------------------------------------------------------------

    /**
     * Parcelas a receber, com os filtros de situação, período (por
     * vencimento), cliente e categoria do plano de contas.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $filtros = $this->filtros($request);

        $dados = [
            'filtros' => $filtros,
            'parcelas' => $this->parcelas($filtros),
            // Contadores do topo da tela, calculados sobre a empresa inteira e
            // não sobre o resultado filtrado: eles são o que o usuário clica
            // para aplicar um filtro, e mudariam de valor ao serem usados.
            'indicadores' => $this->indicadores(),
            'clientes' => $this->clientesParaFiltro(),
            'categorias' => $this->categoriasParaFiltro(),
        ];

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Financeiro/ContasAReceber', $dados);
    }

    // -----------------------------------------------------------------
    // Escrita: sempre pelos Services
    // -----------------------------------------------------------------

    /**
     * Cria o título a receber. Com `work_order_id`, pelo caminho idempotente
     * da OS (`gerarDaOs()`, que devolve o título existente em vez de cobrar o
     * mesmo serviço duas vezes); sem ele, como título avulso (`criar()`).
     */
    public function store(ReceivableRequest $request): JsonResponse|RedirectResponse
    {
        $dados = $request->validated();

        $condicoes = [
            'valor' => $dados['valor'] ?? null,
            'descricao' => $dados['descricao'] ?? null,
            'primeiro_vencimento' => $dados['primeiro_vencimento'] ?? null,
            'emitido_em' => $dados['emitido_em'] ?? null,
            'forma' => $dados['forma'] ?? null,
            'parcelas' => $dados['parcelas'] ?? 1,
            'intervalo_dias' => $dados['intervalo_dias'] ?? null,
            'intervalo_meses' => $dados['intervalo_meses'] ?? null,
            'chart_of_account_id' => $this->categoriaDaEmpresa($dados['chart_of_account_id'] ?? null)?->getKey(),
        ];

        try {
            $titulo = isset($dados['work_order_id'])
                ? $this->titulos->gerarDaOs($this->ordemDaEmpresa((int) $dados['work_order_id']), $condicoes)
                : $this->titulos->criar($condicoes + ['client_id' => $this->clienteDaEmpresa((int) $dados['client_id'])->getKey()]);
        } catch (InvalidArgumentException $erro) {
            return $this->recusar($request, $erro->getMessage());
        }

        return $this->confirmar($request, 'Título a receber criado.', $titulo->getKey());
    }

    /**
     * Registra o recebimento de uma parcela, total ou parcial.
     */
    public function baixar(SettlementRequest $request, ReceivableInstallment $parcela): JsonResponse|RedirectResponse
    {
        $this->baixas->baixar($parcela, $request->validated() + ['usuario' => Auth::user()]);

        return $this->confirmar($request, 'Recebimento registrado.', $parcela->getKey());
    }

    /**
     * Estorna o recebimento de uma parcela. Rota e permissão próprias
     * (`financeiro-estornar`), separadas da baixa: é a única ação que altera
     * caixa já fechado.
     */
    public function estornar(ReversalRequest $request, ReceivableInstallment $parcela): JsonResponse|RedirectResponse
    {
        $this->baixas->estornar($parcela, (string) $request->validated()['motivo'], Auth::user());

        return $this->confirmar($request, 'Recebimento estornado.', $parcela->getKey());
    }

    /**
     * Cancela o título e as parcelas que ainda não foram pagas. Parcela paga
     * é mantida como está: o dinheiro entrou e o registro precisa continuar.
     */
    public function cancelar(Request $request, Receivable $titulo): JsonResponse|RedirectResponse
    {
        $this->titulos->cancelar($titulo);

        return $this->confirmar($request, 'Título cancelado.', $titulo->getKey());
    }

    // -----------------------------------------------------------------
    // Composição da leitura
    // -----------------------------------------------------------------

    /**
     * Filtros da listagem, já normalizados.
     *
     * @return array{situacao: string|null, de: string|null, ate: string|null, client_id: int|null, chart_of_account_id: int|null}
     */
    private function filtros(Request $request): array
    {
        $situacao = $request->query('situacao');

        return [
            'situacao' => in_array($situacao, ReceivableInstallment::SITUACOES, true) ? $situacao : null,
            'de' => BusinessDate::diaDe($request->query('de')),
            'ate' => BusinessDate::diaDe($request->query('ate')),
            'client_id' => $request->integer('client_id') ?: null,
            'chart_of_account_id' => $request->integer('chart_of_account_id') ?: null,
        ];
    }

    /**
     * Uma linha por parcela, com o cliente, a origem e o saldo devedor já
     * calculado em centavos.
     *
     * @param  array{situacao: string|null, de: string|null, ate: string|null, client_id: int|null, chart_of_account_id: int|null}  $filtros
     * @return array<int, array<string, mixed>>
     */
    private function parcelas(array $filtros): array
    {
        return ReceivableInstallment::query()
            ->with(['receivable.client', 'receivable.chartOfAccount', 'receivable.workOrder'])
            ->when($filtros['situacao'], fn ($consulta, $situacao) => $consulta->where('situacao', $situacao))
            ->when($filtros['de'], fn ($consulta, $dia) => $consulta->where('vencimento', '>=', $dia))
            ->when($filtros['ate'], fn ($consulta, $dia) => $consulta->where('vencimento', '<=', $dia))
            ->when(
                $filtros['client_id'] || $filtros['chart_of_account_id'],
                fn ($consulta) => $consulta->whereHas(
                    'receivable',
                    function ($titulo) use ($filtros) {
                        $titulo
                            ->when($filtros['client_id'], fn ($q, $id) => $q->where('client_id', $id))
                            ->when($filtros['chart_of_account_id'], fn ($q, $id) => $q->where('chart_of_account_id', $id));
                    }
                )
            )
            ->orderBy('vencimento')
            ->orderBy('id')
            ->limit(self::LIMITE_DE_LINHAS)
            ->get()
            ->map(fn (ReceivableInstallment $parcela): array => $this->linhaDaParcela($parcela))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function linhaDaParcela(ReceivableInstallment $parcela): array
    {
        $titulo = $parcela->receivable;

        return [
            'id' => (int) $parcela->getKey(),
            'receivable_id' => (int) $parcela->receivable_id,
            'numero' => (int) $parcela->numero,
            'client_id' => $titulo?->client_id === null ? null : (int) $titulo->client_id,
            'cliente' => (string) ($titulo?->client?->name ?? ''),
            'descricao' => (string) ($titulo?->descricao ?? ''),
            'categoria' => $titulo?->chartOfAccount?->nome,
            'chart_of_account_id' => $titulo?->chart_of_account_id === null ? null : (int) $titulo->chart_of_account_id,
            'origem' => $this->origemDoTitulo($titulo),
            'ordem_de_servico' => $titulo?->workOrder?->order_number,
            'vencimento' => BusinessDate::diaDe($parcela->vencimento),
            'valor' => (string) $parcela->valor,
            'valor_pago' => (string) $parcela->valor_pago,
            'saldo' => Dinheiro::paraDecimal($this->baixas->saldoDevedorEmCentavos($parcela)),
            'pago_em' => BusinessDate::diaDe($parcela->pago_em),
            'situacao' => (string) $parcela->situacao,
            'situacao_do_titulo' => (string) ($titulo?->situacao ?? ''),
            'vencida' => in_array($parcela->situacao, self::SITUACOES_EM_ABERTO, true)
                && BusinessDate::estaVencido($parcela->vencimento),
        ];
    }

    /**
     * De onde o título veio, para a tela agrupar cobrança de OS, mensalidade
     * de contrato e lançamento avulso sem precisar interpretar a descrição.
     */
    private function origemDoTitulo(?Receivable $titulo): string
    {
        return match (true) {
            $titulo === null => 'avulso',
            $titulo->work_order_id !== null => 'ordem_de_servico',
            $titulo->contract_id !== null => 'contrato',
            default => 'avulso',
        };
    }

    /**
     * Os quatro números do topo da tela, todos em centavos somados com
     * `Dinheiro` e devolvidos como decimal.
     *
     * `recebido_no_mes` é lido do **título** (soma de `valor_pago` das
     * parcelas quitadas no mês), e não do caixa: é o quanto das cobranças
     * desta tela foi liquidado. O "recebido" do painel financeiro
     * (`/financial-dashboard`) continua sendo o do caixa
     * (`financial_entries`), e os dois podem legitimamente diferir enquanto
     * existir recebimento antigo sem lançamento vinculado ou lançamento manual
     * sem título. Ver o cabeçalho de `FinancialDashboardController`.
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

        $emAberto = ReceivableInstallment::query()
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
                // "A vencer no mês" exclui o que vence hoje de propósito: os
                // dois números aparecem lado a lado na tela, e somar o mesmo
                // saldo nos dois faria o total do topo não bater com a lista.
                $aVencerNoMes += $saldo;
            }
        }

        $recebidoNoMes = 0;

        $quitadasNoMes = ReceivableInstallment::query()
            ->whereNotNull('pago_em')
            ->whereBetween('pago_em', [$inicioDoMes, $fimDoMes])
            ->select(['valor_pago']);

        foreach ($quitadasNoMes->cursor() as $parcela) {
            $recebidoNoMes += Dinheiro::centavos($parcela->valor_pago);
        }

        return [
            'vence_hoje' => Dinheiro::paraDecimal($venceHoje),
            'vencido' => Dinheiro::paraDecimal($vencido),
            'a_vencer_no_mes' => Dinheiro::paraDecimal($aVencerNoMes),
            'recebido_no_mes' => Dinheiro::paraDecimal($recebidoNoMes),
            'parcelas_em_aberto' => $quantidadeEmAberto,
        ];
    }

    /**
     * Clientes com título a receber, para o seletor de filtro. Só quem tem
     * título: a lista completa de clientes é outra tela, e trazê-la aqui
     * encheria o seletor de quem nunca aparece na cobrança.
     *
     * @return array<int, array<string, mixed>>
     */
    private function clientesParaFiltro(): array
    {
        return Client::query()
            ->whereHas('receivables')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Client $cliente): array => [
                'id' => (int) $cliente->getKey(),
                'nome' => (string) $cliente->name,
            ])
            ->all();
    }

    /**
     * Categorias de receita ativas, para o seletor de filtro e para o
     * formulário de criação.
     *
     * @return array<int, array<string, mixed>>
     */
    private function categoriasParaFiltro(): array
    {
        return ChartOfAccount::query()
            ->ativas()
            ->where('tipo', 'receita')
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

    /**
     * Cliente da empresa corrente, ou 404. O escopo global por empresa é quem
     * faz a consulta não achar o cliente de outro tenant.
     */
    private function clienteDaEmpresa(int $id): Client
    {
        return Client::query()->findOrFail($id);
    }

    private function ordemDaEmpresa(int $id): WorkOrder
    {
        return WorkOrder::query()->findOrFail($id);
    }

    private function categoriaDaEmpresa(int|string|null $id): ?ChartOfAccount
    {
        if ($id === null || $id === '') {
            return null;
        }

        return ChartOfAccount::query()->findOrFail((int) $id);
    }

    /**
     * Recusa de negócio: 422 com a mensagem pronta para quem chama por fetch,
     * e volta ao formulário com o erro para quem chega pela navegação Inertia.
     */
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
