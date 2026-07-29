<?php

namespace App\Http\Controllers;

use App\Exceptions\PedidoDeHorarioJaRespondidoException;
use App\Http\Requests\ConfirmarAppointmentRequestRequest;
use App\Http\Requests\RecusarAppointmentRequestRequest;
use App\Models\AppointmentRequest;
use App\Models\Technician;
use App\Models\User;
use App\Services\AppointmentRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * Painel administrativo dos pedidos de horário do agendamento online (Plano
 * 16, Task 16.4): listar, confirmar (gera OS) e recusar (com motivo).
 *
 * Escopo aqui é só o escopo global de `AppointmentRequest` (`BelongsToCompany`),
 * mesmo critério de `ClientRequestAdminController`: qualquer funcionário com a
 * permissão `agendamento-ver`/`agendamento-responder` vê e responde qualquer
 * pedido da própria empresa - não há uma segunda dimensão de "dono" para
 * aplicar aqui, ao contrário do portal do cliente.
 */
class AppointmentRequestController extends Controller
{
    public function __construct(
        private readonly AppointmentRequestService $pedidos,
    ) {}

    /**
     * GET /solicitacoes-de-horario
     *
     * Renderiza `Agendamentos/Index` (Task 16.7): o nome do componente Vue
     * segue o que a tela pede no menu ("Agendamentos"), independente do nome
     * da rota (`solicitacoes-de-horario.*`, herdado da Task 16.4).
     */
    public function index(Request $request): Response
    {
        $situacao = $request->query('situacao');

        return Inertia::render('Agendamentos/Index', [
            'pedidos' => $this->pedidos->listarParaEmpresa(is_string($situacao) ? $situacao : null),
            'situacaoFiltro' => $situacao,
            'situacoes' => AppointmentRequest::SITUACOES,
            'motivosProntos' => AppointmentRequestService::MOTIVOS_PRONTOS_DE_RECUSA,
            // Técnicos ativos, para o select do modal de confirmação (Task
            // 16.7). Mesmo recorte de campos de `WorkOrderController`
            // (id, name, specialty): o suficiente para identificar o técnico
            // sem carregar dado que a tela não usa.
            'tecnicos' => Technician::query()->active()->orderBy('name')->get(['id', 'name', 'specialty']),
        ]);
    }

    /**
     * POST /solicitacoes-de-horario/{pedido}/confirmar
     *
     * Reconfere a disponibilidade, resolve (ou cria) cliente e endereço, e
     * gera a ordem de serviço. 422 quando o pedido já foi respondido antes
     * (com a situação atual no payload) ou quando o período escolhido não
     * tem mais vaga.
     */
    public function confirmar(ConfirmarAppointmentRequestRequest $request, AppointmentRequest $pedido): JsonResponse
    {
        /** @var User $funcionario */
        $funcionario = Auth::user();

        try {
            $pedido = $this->pedidos->confirmar($pedido, $request->validated(), $funcionario);
        } catch (PedidoDeHorarioJaRespondidoException $erro) {
            return response()->json([
                'success' => false,
                'message' => $erro->getMessage(),
                'situacao' => $erro->situacaoAtual,
            ], 422);
        } catch (InvalidArgumentException|RuntimeException $erro) {
            return response()->json([
                'success' => false,
                'message' => $erro->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pedido confirmado. A ordem de serviço foi criada.',
            'pedido' => $pedido,
        ]);
    }

    /**
     * POST /solicitacoes-de-horario/{pedido}/recusar
     *
     * Motivo sempre obrigatório (`RecusarAppointmentRequestRequest`). 422
     * quando o pedido já foi respondido antes, com a situação atual no
     * payload.
     */
    public function recusar(RecusarAppointmentRequestRequest $request, AppointmentRequest $pedido): JsonResponse
    {
        /** @var User $funcionario */
        $funcionario = Auth::user();

        try {
            $pedido = $this->pedidos->recusar($pedido, (string) $request->validated('motivo'), $funcionario);
        } catch (PedidoDeHorarioJaRespondidoException $erro) {
            return response()->json([
                'success' => false,
                'message' => $erro->getMessage(),
                'situacao' => $erro->situacaoAtual,
            ], 422);
        } catch (InvalidArgumentException $erro) {
            return response()->json([
                'success' => false,
                'message' => $erro->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Pedido recusado. O solicitante foi avisado.',
            'pedido' => $pedido,
        ]);
    }
}
