<?php

namespace App\Http\Controllers;

use App\Http\Requests\AtribuicaoTecnicoRequest;
use App\Http\Requests\ReagendamentoRequest;
use App\Http\Requests\TecnicosDisponiveisRequest;
use App\Models\Service;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Services\AgendaService;
use App\Services\DisponibilidadeService;
use App\Services\WorkOrderAccessService;
use App\Services\WorkOrderService;
use App\Support\BusinessDate;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AgendaController extends Controller
{
    protected AgendaService $agendaService;

    protected WorkOrderAccessService $workOrderAccessService;

    protected DisponibilidadeService $disponibilidadeService;

    protected WorkOrderService $workOrderService;

    public function __construct(
        AgendaService $agendaService,
        WorkOrderAccessService $workOrderAccessService,
        DisponibilidadeService $disponibilidadeService,
        WorkOrderService $workOrderService
    ) {
        $this->agendaService = $agendaService;
        $this->workOrderAccessService = $workOrderAccessService;
        $this->disponibilidadeService = $disponibilidadeService;
        $this->workOrderService = $workOrderService;
    }

    /**
     * Restringe a consulta às ordens de serviço visíveis ao usuário
     * autenticado. Mesmo padrão do `WorkOrderController`: usuário com papel
     * `tecnico` só enxerga as próprias ordens de serviço na agenda.
     */
    private function escoparPorUsuario($query)
    {
        $usuario = Auth::user();

        if (! $usuario) {
            return $query;
        }

        return $this->workOrderAccessService->aplicarEscopoDoUsuario($query, $usuario);
    }

    /**
     * Bloqueia o técnico que não está atribuído a esta ordem de serviço.
     *
     * Mesmo método do `WorkOrderController`: o escopo por empresa separa
     * empresas, nunca um técnico do outro, então esta verificação continua
     * obrigatória. Lança `AuthorizationException`, que o Laravel devolve como
     * 403 (JSON quando a requisição pede JSON).
     */
    private function garantirAcesso(WorkOrder $workOrder): void
    {
        $usuario = Auth::user();

        if (! $usuario) {
            return;
        }

        $this->workOrderAccessService->garantirAcesso($workOrder, $usuario);
    }

    /**
     * Página da agenda em calendário, ou a visão do próprio dia para quem
     * tem o papel `tecnico`.
     *
     * A busca das ordens de serviço por período acontece em `dados()`,
     * chamado pelo frontend a cada navegação entre semana/mês, sem recarregar
     * a página inteira. Aqui só vai o período inicial (mês corrente, no fuso
     * do negócio) para a primeira renderização.
     *
     * Técnico cai direto em `Agenda/MeuDia`, sem os filtros de técnico e
     * serviço (não fazem sentido para quem só enxerga as próprias ordens de
     * serviço, por conta do escopo do Plano 2). `MeuDia` busca as OS do dia
     * pelo mesmo endpoint `dados()`, que já devolve só as dele.
     */
    public function index(Request $request)
    {
        if (Auth::user()?->hasRole('tecnico')) {
            return Inertia::render('Agenda/MeuDia', [
                'dataInicial' => BusinessDate::hoje()->toDateString(),
            ]);
        }

        return Inertia::render('Agenda/Index', [
            'periodoInicial' => [
                'inicio' => BusinessDate::hoje()->startOfMonth()->toDateString(),
                'fim' => BusinessDate::hoje()->endOfMonth()->toDateString(),
            ],
            'tecnicos' => Technician::query()->orderBy('name')->get(['id', 'name']),
            'servicos' => Service::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Dados em JSON das ordens de serviço agendadas no período informado,
     * com os filtros de técnico, tipo de serviço, situação e cidade.
     */
    public function dados(Request $request)
    {
        $inicio = (string) $request->query('inicio', '');
        $fim = (string) $request->query('fim', '');

        if (! $this->ehDataValida($inicio) || ! $this->ehDataValida($fim)) {
            return response()->json([
                'message' => 'Informe os parâmetros "inicio" e "fim" no formato Y-m-d.',
            ], 422);
        }

        $filtros = $request->only(['technician_id', 'service_id', 'status', 'cidade']);

        $consulta = $this->escoparPorUsuario(WorkOrder::query());

        $ordens = $this->agendaService->doPeriodo($inicio, $fim, $filtros, $consulta);

        return response()->json([
            'ordens' => $ordens,
            'periodo' => [
                'inicio' => $inicio,
                'fim' => $fim,
            ],
        ]);
    }

    /**
     * Carga de trabalho por técnico no período informado (total de OS, total
     * de horas e distribuição por dia), para o painel `CargaPorTecnico` da
     * Task 10.7.
     *
     * Mesma permissão de `dados()`: é informação de leitura, não altera nada.
     */
    public function carga(Request $request): JsonResponse
    {
        $inicio = (string) $request->query('inicio', '');
        $fim = (string) $request->query('fim', '');

        if (! $this->ehDataValida($inicio) || ! $this->ehDataValida($fim)) {
            return response()->json([
                'message' => 'Informe os parâmetros "inicio" e "fim" no formato Y-m-d.',
            ], 422);
        }

        return response()->json($this->disponibilidadeService->cargaPorTecnico($inicio, $fim));
    }

    /**
     * Reagenda uma ordem de serviço (arrastar e soltar no calendário ou
     * formulário do painel lateral).
     *
     * O horário chega já convertido para o fuso da aplicação pelo frontend,
     * como no formulário de ordem de serviço, e segue direto para a checagem de
     * conflito e para o `WorkOrderService`. Nenhuma conversão de fuso acontece
     * aqui: a regra e o motivo estão no `ReagendamentoRequest`.
     *
     * Conflito bloqueia com 422 e a lista das ordens que colidem. Aviso (ordem
     * do mesmo técnico no mesmo dia, sem horário para comparar) não bloqueia e
     * volta junto da resposta de sucesso.
     *
     * Quem reagendou e quando sai da auditoria do Plano 3: a trait `Auditavel`
     * do model grava autor, instante e valores antes e depois a cada `update()`
     * bem-sucedido, sem nenhum campo novo aqui.
     */
    public function reagendar(ReagendamentoRequest $request, WorkOrder $workOrder): JsonResponse
    {
        $this->garantirAcesso($workOrder);

        $dados = $request->validated();
        $inicio = $dados['start_time'] ?? null;
        $fim = $dados['end_time'] ?? null;

        // A checagem vale para todos os técnicos já atribuídos à ordem, pela
        // coluna e pela pivot: os dois ficam ocupados no horário novo.
        $conferencia = $this->disponibilidadeService->conflitosDeTecnicos(
            $this->disponibilidadeService->tecnicosAtribuidos($workOrder),
            $dados['scheduled_date'],
            $inicio,
            $fim,
            $workOrder->id
        );

        if ($conferencia['tem_conflito']) {
            return $this->respostaDeConflito($conferencia);
        }

        $this->workOrderService->updateWorkOrder($workOrder, [
            'scheduled_date' => $dados['scheduled_date'],
            'start_time' => $inicio,
            'end_time' => $fim,
        ]);

        return $this->respostaDeSucesso(
            $workOrder,
            'Ordem de serviço reagendada com sucesso.',
            $conferencia
        );
    }

    /**
     * Atribui (ou desatribui, com `technician_id` nulo) o técnico responsável
     * pela ordem de serviço, usando a data e o horário que ela já tem.
     *
     * Altera só `work_orders.technician_id`, que é o técnico que a agenda
     * mostra. A equipe da pivot (`technicians[]`) continua sendo assunto do
     * formulário da ordem de serviço: apagar de dentro do calendário um
     * segundo técnico que alguém cadastrou lá seria perda silenciosa de dado,
     * e a pivot nem entra na auditoria do model.
     */
    public function atribuirTecnico(AtribuicaoTecnicoRequest $request, WorkOrder $workOrder): JsonResponse
    {
        $this->garantirAcesso($workOrder);

        $tecnicoId = $request->validated()['technician_id'] ?? null;
        $tecnicoId = $tecnicoId === null ? null : (int) $tecnicoId;

        $conferencia = $this->disponibilidadeService->semConflito();
        $dia = BusinessDate::diaDe($workOrder->scheduled_date);

        // Sem técnico novo não há o que checar, e sem data agendada não existe
        // dia em que procurar conflito.
        if ($tecnicoId !== null && $dia !== null) {
            $conferencia = $this->disponibilidadeService->conflitos(
                $tecnicoId,
                $dia,
                $this->instante($workOrder->start_time),
                $this->instante($workOrder->end_time),
                $workOrder->id
            );

            if ($conferencia['tem_conflito']) {
                return $this->respostaDeConflito($conferencia);
            }
        }

        $this->workOrderService->updateWorkOrder($workOrder, ['technician_id' => $tecnicoId]);

        return $this->respostaDeSucesso(
            $workOrder,
            $tecnicoId === null
                ? 'Técnico removido da ordem de serviço.'
                : 'Técnico atribuído à ordem de serviço.',
            $conferencia
        );
    }

    /**
     * Técnicos ativos sem conflito para a data e o horário informados, para o
     * painel de atribuição do calendário.
     */
    public function tecnicosDisponiveis(TecnicosDisponiveisRequest $request): JsonResponse
    {
        $dados = $request->validated();
        $inicio = $dados['inicio'] ?? null;
        $fim = $dados['fim'] ?? null;

        return response()->json([
            'data' => $dados['data'],
            'inicio' => $inicio,
            'fim' => $fim,
            'tecnicos' => $this->disponibilidadeService->tecnicosDisponiveis($dados['data'], $inicio, $fim),
        ]);
    }

    /**
     * Instante gravado na ordem de serviço, em texto, para o
     * `DisponibilidadeService` reler exatamente o mesmo momento.
     *
     * `toIso8601String()` carrega o deslocamento junto, então não sobra
     * interpretação: nenhuma hora de parede é remontada no caminho.
     */
    private function instante(?DateTimeInterface $valor): ?string
    {
        return $valor?->format(DateTimeInterface::ATOM);
    }

    /**
     * 422 com a lista das ordens que colidem.
     *
     * A mensagem nomeia a ordem conflitante, com número, cliente e horário:
     * "horário indisponível" sem dizer com o quê obriga o usuário a caçar o
     * motivo. `conflito: true` separa esta resposta da 422 de validação, que
     * traz `errors`.
     *
     * @param  array<string, mixed>  $conferencia
     */
    private function respostaDeConflito(array $conferencia): JsonResponse
    {
        return response()->json([
            'message' => $conferencia['mensagem_conflito'],
            'conflito' => true,
            'conflitos' => $conferencia['conflitos'],
        ], 422);
    }

    /**
     * Sucesso com a visita já formatada no mesmo formato da agenda, para o
     * calendário atualizar o cartão sem buscar o período inteiro de novo.
     *
     * @param  array<string, mixed>  $conferencia
     */
    private function respostaDeSucesso(WorkOrder $workOrder, string $mensagem, array $conferencia): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $mensagem,
            'ordem' => $this->agendaService->formatarOrdem($workOrder),
            'tem_aviso' => $conferencia['tem_aviso'],
            'mensagem_aviso' => $conferencia['mensagem_aviso'],
            'avisos' => $conferencia['avisos'],
        ]);
    }

    /**
     * Data no formato Y-m-d e calendário válido (rejeita "2026-02-30", por
     * exemplo). Sem FormRequest de propósito: são dois parâmetros de query
     * simples, e o formato errado aqui é sempre um bug de integração do
     * frontend, não uma entrada de usuário final a validar com regras de
     * negócio.
     */
    private function ehDataValida(string $valor): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1) {
            return false;
        }

        [$ano, $mes, $dia] = array_map('intval', explode('-', $valor));

        return checkdate($mes, $dia, $ano);
    }
}
