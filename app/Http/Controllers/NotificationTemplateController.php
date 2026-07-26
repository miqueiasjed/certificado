<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateNotificationTemplateRequest;
use App\Models\NotificationTemplate;
use App\Support\EventosDeNotificacao;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Templates de notificação do tenant: um por (evento, canal), com o texto
 * padrão do sistema (`EventosDeNotificacao`) como base enquanto o tenant não
 * personaliza.
 *
 * Nenhum envio acontece aqui. Salvar um template só muda o texto que o
 * próximo `NotificationService::enfileirar()` vai usar (Task 14.2); apagar o
 * template do tenant volta ao texto padrão do catálogo, sem tocar no
 * histórico já enviado.
 */
class NotificationTemplateController extends Controller
{
    /**
     * GET /notificacoes/templates
     *
     * Um evento por linha, com um item por canal aceito, informando se o
     * texto é do tenant ou o padrão do sistema. É essa distinção que a tela
     * usa para separar personalização de configuração original.
     */
    public function index(): Response
    {
        $doTenant = NotificationTemplate::query()
            ->get()
            ->keyBy(fn (NotificationTemplate $template): string => $template->evento.':'.$template->canal);

        $eventos = collect(EventosDeNotificacao::paraTela())
            ->map(function (array $definicao) use ($doTenant): array {
                $canais = collect($definicao['canais'])
                    ->map(function (string $canal) use ($definicao, $doTenant): array {
                        $template = $doTenant->get($definicao['evento'].':'.$canal);
                        $padrao = EventosDeNotificacao::templatePadrao($definicao['evento'], $canal);

                        return [
                            'canal' => $canal,
                            'origem' => $template !== null ? 'tenant' : 'padrao',
                            'ativo' => $template->ativo ?? true,
                            'assunto' => $template->assunto ?? $padrao['assunto'] ?? null,
                            'corpo' => $template->corpo ?? $padrao['corpo'] ?? '',
                        ];
                    })
                    ->values();

                return [
                    'evento' => $definicao['evento'],
                    'rotulo' => $definicao['rotulo'],
                    'destinatario' => $definicao['destinatario'],
                    'variaveis' => $definicao['variaveis'],
                    'canais' => $canais,
                ];
            })
            ->values();

        return Inertia::render('Notificacoes/Templates', [
            'eventos' => $eventos,
        ]);
    }

    /**
     * PUT /notificacoes/templates/{evento}/{canal}
     *
     * A validação de variável desconhecida já aconteceu em
     * `UpdateNotificationTemplateRequest`, contra o catálogo do próprio
     * evento. Canal WhatsApp nunca grava assunto: só o e-mail usa.
     */
    public function update(UpdateNotificationTemplateRequest $request, string $evento, string $canal): JsonResponse
    {
        $this->validarEventoECanal($evento, $canal);

        $template = NotificationTemplate::updateOrCreate(
            ['evento' => $evento, 'canal' => $canal],
            [
                'assunto' => $canal === EventosDeNotificacao::CANAL_EMAIL ? $request->validated('assunto') : null,
                'corpo' => $request->validated('corpo'),
                'ativo' => $request->boolean('ativo', true),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Template salvo com sucesso.',
            'template' => $template,
        ]);
    }

    /**
     * DELETE /notificacoes/templates/{evento}/{canal}
     *
     * Apaga o template do tenant. O evento volta a usar o texto padrão do
     * sistema no próximo enfileiramento; o histórico já enviado não muda.
     */
    public function destroy(string $evento, string $canal): JsonResponse
    {
        $this->validarEventoECanal($evento, $canal);

        NotificationTemplate::where('evento', $evento)->where('canal', $canal)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Template removido. O evento volta a usar o texto padrão do sistema.',
        ]);
    }

    /**
     * 404 para evento fora do catálogo ou canal que este evento não aceita
     * (por exemplo, WhatsApp em "contrato a vencer", que só sai por
     * e-mail): não existe recurso para editar ou apagar nesses casos.
     */
    private function validarEventoECanal(string $evento, string $canal): void
    {
        if (! EventosDeNotificacao::existe($evento) || ! EventosDeNotificacao::aceitaCanal($evento, $canal)) {
            abort(404);
        }
    }
}
