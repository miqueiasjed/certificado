<?php

namespace App\Http\Controllers;

use App\Models\NotificationQueue;
use App\Services\NotificationService;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Fila e histórico de notificações do tenant: consulta filtrada, reenvio e
 * cancelamento. Nenhum envio acontece aqui, de propósito: quem envia é o
 * despachante da Task 14.3, e "reenviar" significa recolocar o item na fila
 * dele, nunca despachar na hora, para o envio ter um caminho único e o
 * histórico bater com o que realmente saiu.
 *
 * O escopo por empresa (`BelongsToCompany`) garante que o tenant só enxerga a
 * própria fila, tanto na listagem quanto no route-model binding de `{item}`:
 * item de outra empresa devolve 404 antes de chegar a qualquer método daqui.
 */
class NotificationQueueController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {
    }

    /**
     * GET /notificacoes
     *
     * Filtros: evento, situação, período (`data_inicio`/`data_fim`, dias no
     * fuso do negócio) e cliente (`client_id`, quando o destinatário é um
     * cliente). Cada item carrega as tentativas (`logs`), mais recente
     * primeiro.
     */
    public function index(Request $request): Response
    {
        $consulta = NotificationQueue::query()->with('logs');

        if ($request->filled('evento')) {
            $consulta->where('evento', $request->input('evento'));
        }

        if ($request->filled('situacao')) {
            $consulta->where('situacao', $request->input('situacao'));
        }

        if ($request->filled('client_id')) {
            $consulta->where('destinatario_tipo', NotificationQueue::DESTINATARIO_CLIENTE)
                ->where('destinatario_id', $request->integer('client_id'));
        }

        $this->aplicarFiltroDePeriodo($consulta, $request);

        $itens = $consulta->orderByDesc('agendada_para')->paginate(20)->withQueryString();

        return Inertia::render('Notificacoes/Index', [
            'itens' => $itens,
            'filtros' => $request->only(['evento', 'situacao', 'client_id', 'data_inicio', 'data_fim']),
            'eventos' => EventosDeNotificacao::paraTela(),
            'situacoes' => NotificationQueue::SITUACOES,
        ]);
    }

    /**
     * POST /notificacoes/{item}/reenviar
     *
     * Recoloca o item em pendente e zera as tentativas e a próxima tentativa
     * agendada, para o despachante pegá-lo na próxima passada.
     */
    public function reenviar(NotificationQueue $item): JsonResponse
    {
        $item->update([
            'situacao' => NotificationQueue::SITUACAO_PENDENTE,
            'tentativas' => 0,
            'proxima_tentativa_em' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Aviso recolocado na fila.',
            'item' => $item->fresh('logs'),
        ]);
    }

    /**
     * POST /notificacoes/{item}/cancelar
     *
     * Só vale para item ainda pendente. Item enviado, já cancelado ou
     * recusado é histórico e não muda de situação por aqui.
     */
    public function cancelar(NotificationQueue $item): JsonResponse
    {
        if ($item->situacao !== NotificationQueue::SITUACAO_PENDENTE) {
            return response()->json([
                'success' => false,
                'message' => 'Só é possível cancelar um aviso que ainda está pendente.',
            ], 422);
        }

        $item->update(['situacao' => NotificationQueue::SITUACAO_CANCELADA]);

        return response()->json([
            'success' => true,
            'message' => 'Aviso cancelado.',
            'item' => $item->fresh('logs'),
        ]);
    }

    /**
     * GET /notificacoes/{item}/whatsapp
     *
     * Link `wa.me` com a mensagem do item pronta, para a tela abrir em nova
     * aba. Não envia nada: o clique manual é o que faz as vezes do driver de
     * WhatsApp nesta entrega.
     */
    public function whatsapp(NotificationQueue $item): JsonResponse
    {
        try {
            $link = $this->notificationService->linkWhatsapp($item);
        } catch (InvalidArgumentException $excecao) {
            return response()->json([
                'success' => false,
                'message' => $excecao->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'link' => $link,
        ]);
    }

    /**
     * `data_inicio` e `data_fim` chegam como dia no fuso do negócio.
     * `agendada_para` é gravado como instante no fuso da aplicação
     * (`NotificationService::instanteDeEnvio`), então o início e o fim do dia
     * são convertidos antes de entrar na consulta: comparar a string crua
     * compararia dia de Brasília com instante em outro fuso, e um item
     * agendado à noite entraria ou sairia do filtro no dia errado.
     */
    private function aplicarFiltroDePeriodo(Builder $consulta, Request $request): void
    {
        $fusoDaAplicacao = config('app.timezone') ?: 'UTC';

        if ($request->filled('data_inicio')) {
            $inicio = BusinessDate::paraFusoNegocio($request->input('data_inicio'))?->startOfDay();

            if ($inicio !== null) {
                $consulta->where('agendada_para', '>=', $inicio->setTimezone($fusoDaAplicacao));
            }
        }

        if ($request->filled('data_fim')) {
            $fim = BusinessDate::paraFusoNegocio($request->input('data_fim'))?->endOfDay();

            if ($fim !== null) {
                $consulta->where('agendada_para', '<=', $fim->setTimezone($fusoDaAplicacao));
            }
        }
    }
}
