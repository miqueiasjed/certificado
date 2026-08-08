<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApurarComissaoRequest;
use App\Http\Requests\MarcarComissaoComoPagaRequest;
use App\Http\Requests\ReabrirComissaoRequest;
use App\Models\Commission;
use App\Models\User;
use App\Services\Commission\CommissionCalculationService;
use App\Services\Commission\CommissionClosingService;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Comissão apurada de vendedor e de técnico (Plano 23, Task 23.6): listagem
 * por competência e as quatro ações que mudam a situação dela. Toda regra de
 * cálculo e de transição de estado já existe em
 * `CommissionCalculationService` e `CommissionClosingService`: este
 * controller só orquestra e devolve a resposta, deixando as duas exceções que
 * os Services lançam (`ValidationException`, `AuthorizationException`)
 * seguirem para o tratamento padrão do Laravel, que já as converte em 422 e
 * 403, mesmo critério de `PayableController`.
 *
 * Regra de acesso ao DADO, não só à rota: comissão é informação sensível
 * entre colegas (quanto o colega ganhou). Por padrão cada pessoa só vê a
 * própria comissão - pelo `user_id` (vendedor) ou pelo técnico vinculado a
 * ela (`User::technician()`) -, e só quem tem `comissoes-ver-todas` recebe a
 * comissão de todo mundo no mesmo payload. Ver `escopoDoUsuario()`.
 */
class CommissionController extends Controller
{
    public function __construct(
        private readonly CommissionCalculationService $apuracao,
        private readonly CommissionClosingService $fechamento,
    ) {}

    /**
     * Comissões da competência informada, com os itens que compõem cada uma.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $competencia = $this->competencia($request);
        $usuario = $request->user();

        $consulta = Commission::query()
            ->where('competencia', $competencia)
            ->with(['items', 'user:id,name', 'technician:id,name']);

        $this->escopoDoUsuario($consulta, $usuario);

        $dados = [
            'competencia' => $competencia,
            've_todas' => $usuario->can('comissoes-ver-todas'),
            'comissoes' => $consulta->orderBy('user_id')->orderBy('technician_id')->get(),
        ];

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Commissions/Index', $dados);
    }

    /**
     * Dispara a apuração manual da competência informada. Competência já
     * fechada ou paga é ignorada silenciosamente pelo Service (nunca
     * recalculada); o retorno informa quantas pessoas foram de fato
     * processadas e quantas comissões fechadas foram ignoradas.
     */
    public function apurar(ApurarComissaoRequest $request): JsonResponse|RedirectResponse
    {
        $resultado = $this->apuracao->apurar($request->validated('competencia'), $request->user());

        return $this->confirmar($request, sprintf(
            'Apuração concluída: %d pessoa(s) processada(s), %d item(ns) gravado(s), %d competência(s) fechada(s) ignorada(s).',
            $resultado['pessoas_processadas'],
            $resultado['itens_gravados'],
            $resultado['competencias_fechadas_ignoradas']
        ), null, $resultado);
    }

    public function fechar(Request $request, Commission $comissao): JsonResponse|RedirectResponse
    {
        $this->fechamento->fechar($comissao, $request->user());

        return $this->confirmar($request, 'Comissão fechada.', (int) $comissao->getKey());
    }

    /**
     * Reabre a comissão. A permissão (`comissoes-reabrir`) e o tamanho mínimo
     * da justificativa já são exigidos dentro do próprio
     * `CommissionClosingService::reabrir()`; a rota exige a mesma permissão
     * de novo, pelo mesmo critério de defesa em profundidade de
     * `financeiro-estornar`/`pagamento-reabrir`.
     */
    public function reabrir(ReabrirComissaoRequest $request, Commission $comissao): JsonResponse|RedirectResponse
    {
        $this->fechamento->reabrir($comissao, $request->user(), $request->validated('justificativa'));

        return $this->confirmar($request, 'Comissão reaberta.', (int) $comissao->getKey());
    }

    /**
     * Marca a comissão fechada como paga, gerando o título a pagar do Plano
     * 18 quando `gerar_titulo` vem `true`.
     */
    public function pagar(MarcarComissaoComoPagaRequest $request, Commission $comissao): JsonResponse|RedirectResponse
    {
        $this->fechamento->marcarComoPaga($comissao, $request->validated());

        return $this->confirmar($request, 'Comissão marcada como paga.', (int) $comissao->getKey());
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Competência da query string, ou o mês corrente quando ausente: a
     * listagem sempre tem uma competência para mostrar, sem obrigar a tela a
     * escolher uma no primeiro carregamento.
     */
    private function competencia(Request $request): string
    {
        $competencia = $request->query('competencia');

        return is_string($competencia) && $competencia !== ''
            ? $competencia
            : BusinessDate::hoje()->format('Y-m');
    }

    /**
     * Restringe a consulta à própria comissão, salvo `comissoes-ver-todas`.
     * "Própria" cobre as duas pontas possíveis do mesmo usuário: a comissão
     * de vendedor (`user_id`) e, quando ele também é um técnico cadastrado
     * (`User::technician()`), a comissão de técnico (`technician_id`) - mesmo
     * padrão de vínculo usuário-técnico que restringe o app do técnico às
     * próprias OS (`WorkOrderAccessService`).
     */
    private function escopoDoUsuario(Builder $consulta, User $usuario): void
    {
        if ($usuario->can('comissoes-ver-todas')) {
            return;
        }

        $tecnicoId = $usuario->technician?->id;

        $consulta->where(function (Builder $query) use ($usuario, $tecnicoId): void {
            $query->where('user_id', $usuario->id);

            if ($tecnicoId !== null) {
                $query->orWhere('technician_id', $tecnicoId);
            }
        });
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
