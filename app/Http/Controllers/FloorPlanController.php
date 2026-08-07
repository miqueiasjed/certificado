<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFloorPlanRequest;
use App\Http\Requests\UpdateDevicePositionsRequest;
use App\Models\Address;
use App\Models\Device;
use App\Models\DevicePosition;
use App\Models\FloorPlan;
use App\Services\FloorPlanService;
use App\Services\Monitoring\RelatorioPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Planta versionada do endereço e posicionamento dos dispositivos sobre ela
 * (Plano 21, Task 21.5 - `FloorPlanService` já pronto desde a Task 21.4).
 *
 * Toda ação que muda algo (`store`, `substituir`, `posicoes`) já é protegida
 * por `planta-gerenciar` dentro do próprio FormRequest (`StoreFloorPlanRequest::authorize()`,
 * `UpdateDevicePositionsRequest::authorize()`, conferido nos dois antes de
 * escrever este controller), então nada aqui repete a checagem de
 * autorização - e a rota, além disso, acumula o mesmo
 * `permission:planta-gerenciar` (defesa em profundidade, mesmo padrão do
 * resto do sistema).
 *
 * Route model binding e o vínculo com o endereço da URL
 * -----------------------------------------------------------------------
 * O escopo global de `BelongsToCompany` já impede um `{floorPlan}`/`{address}`
 * de outra empresa chegar aqui (404 automático do binding). O vínculo lógico
 * "esta planta pertence a este endereço" só importa em `index()`/`store()`,
 * onde `{address}` e a planta aparecem juntos na mesma rota - e ali a
 * consulta já nasce filtrada por `address_id`, então não existe caminho para
 * `store()` gravar a planta presa a um endereço diferente do da URL.
 * `substituir()`, `posicoes()` e `croqui()` recebem só `{floorPlan}`, sem
 * `{address}` na rota para comparar: nada a conferir além do que o escopo
 * global já resolve.
 */
class FloorPlanController extends Controller
{
    public function __construct(
        private readonly FloorPlanService $floorPlans,
        private readonly RelatorioPdfService $relatorioPdf,
    ) {}

    /**
     * `Monitoring/FloorPlans/Index` nunca chegou a ser escrita (nenhuma task
     * do plano listava esse arquivo de frontend - achado ao revisar a
     * integração da Task 21.7). O editor de arrastar-soltar da Task 21.7
     * (`Plantas/Editor.vue`) já cobre listagem por nome, histórico de versão
     * e envio de planta nova, então esta rota apenas encaminha para lá em vez
     * de manter uma segunda página redundante que ninguém pediu.
     */
    public function index(Address $address): RedirectResponse
    {
        return redirect()->route('enderecos.plantas.editor', $address);
    }

    /**
     * Entrada do editor a partir do endereço, sem uma planta específica
     * escolhida ainda: seleciona a versão ativa mais recente (por nome) do
     * endereço, ou renderiza com `planta: null` quando o endereço ainda não
     * tem nenhuma planta - `Plantas/Editor.vue` já sabe lidar com os dois
     * casos (ver o contrato de props documentado no topo daquele arquivo).
     */
    public function editorPorEndereco(Address $address): InertiaResponse
    {
        $plantaInicial = FloorPlan::query()
            ->where('address_id', $address->id)
            ->where('ativa', true)
            ->orderBy('nome')
            ->first();

        return $this->renderizarEditor($address, $plantaInicial);
    }

    /**
     * Entrada do editor a partir de uma planta específica (o seletor
     * "Térreo/Depósito" de `Plantas/Editor.vue` navega para cá ao trocar).
     * Sempre resolve para a versão ATIVA do mesmo nome/endereço - entrar pelo
     * id de uma versão substituída ainda leva ao editor da planta corrente
     * daquele nome, nunca a uma versão presa no passado (edição de posição
     * só faz sentido na versão em uso).
     */
    public function editor(FloorPlan $floorPlan): InertiaResponse
    {
        $plantaAtiva = $floorPlan->ativa
            ? $floorPlan
            : FloorPlan::query()
                ->where('address_id', $floorPlan->address_id)
                ->where('nome', $floorPlan->nome)
                ->where('ativa', true)
                ->first() ?? $floorPlan;

        return $this->renderizarEditor($plantaAtiva->address, $plantaAtiva);
    }

    /**
     * @return array{id: int, nickname: ?string, street: ?string, number: ?string}
     */
    private function renderizarEditor(Address $address, ?FloorPlan $plantaSelecionada): InertiaResponse
    {
        $versoes = $plantaSelecionada === null ? collect() : FloorPlan::query()
            ->where('address_id', $address->id)
            ->where('nome', $plantaSelecionada->nome)
            ->withCount('devicePositions')
            ->orderByDesc('versao')
            ->get();

        $plantasDoEndereco = FloorPlan::query()
            ->where('address_id', $address->id)
            ->where('ativa', true)
            ->orderBy('nome')
            ->get(['id', 'nome', 'versao'])
            ->map(fn (FloorPlan $planta): array => [
                'nome' => $planta->nome,
                'floorPlanId' => $planta->id,
                'versao' => $planta->versao,
            ]);

        $posicoes = $plantaSelecionada === null ? collect() : $plantaSelecionada
            ->devicePositions()
            ->with('device:id,label,number,codigo_publico')
            ->get()
            ->map(fn (DevicePosition $posicao): array => [
                'deviceId' => $posicao->device_id,
                'x' => (float) $posicao->x,
                'y' => (float) $posicao->y,
                'rotuloVisivel' => $posicao->rotulo_visivel,
                'device' => $posicao->device === null ? null : [
                    'id' => $posicao->device->id,
                    'label' => $posicao->device->label,
                    'number' => $posicao->device->number,
                    'codigo_publico' => $posicao->device->codigo_publico,
                ],
            ]);

        $naoPosicionados = $plantaSelecionada === null
            ? Device::query()->active()->byAddress($address->id)->get(['id', 'label', 'number', 'codigo_publico'])
            : $this->floorPlans->dispositivosNaoPosicionados($plantaSelecionada)->map->only(['id', 'label', 'number', 'codigo_publico']);

        return Inertia::render('Plantas/Editor', [
            'endereco' => $address->only(['id', 'nickname', 'street', 'number']),
            'planta' => $plantaSelecionada === null ? null : [
                'id' => $plantaSelecionada->id,
                'nome' => $plantaSelecionada->nome,
                'versao' => $plantaSelecionada->versao,
                'arquivo_url' => $plantaSelecionada->arquivo_url,
                'largura_px' => $plantaSelecionada->largura_px,
                'altura_px' => $plantaSelecionada->altura_px,
                'ativa' => $plantaSelecionada->ativa,
                'created_at' => $plantaSelecionada->created_at,
                'observacao' => $plantaSelecionada->observacao,
            ],
            'versoes' => $versoes,
            'plantasDoEndereco' => $plantasDoEndereco,
            'posicoes' => $posicoes,
            'naoPosicionados' => $naoPosicionados,
            'totalDispositivosAtivos' => Device::query()->active()->byAddress($address->id)->count(),
        ]);
    }

    public function store(StoreFloorPlanRequest $request, Address $address): RedirectResponse
    {
        $planta = $this->floorPlans->enviar($address, $request->file('arquivo'), $request->validated());

        return redirect()
            ->route('enderecos.plantas.index', $address)
            ->with('success', "Planta \"{$planta->nome}\" enviada (versão {$planta->versao}).");
    }

    public function substituir(StoreFloorPlanRequest $request, FloorPlan $floorPlan): RedirectResponse
    {
        $novaVersao = $this->floorPlans->substituir($floorPlan, $request->file('arquivo'));

        return redirect()
            ->route('enderecos.plantas.index', $novaVersao->address_id)
            ->with('success', "Planta \"{$novaVersao->nome}\" substituída (agora na versão {$novaVersao->versao}).");
    }

    /**
     * `JsonResponse`, e não redirect: o editor de arrastar-soltar (Task 21.7)
     * chama esta rota a cada 1s de inatividade (salvamento automático), e
     * seguir um redirect a cada chamada navegaria o usuário para fora do
     * editor toda vez que ele parasse de mexer num ponto. Mesmo critério já
     * usado por `AgendaController::reagendar()` para o arrastar-e-soltar da
     * agenda.
     */
    public function posicoes(UpdateDevicePositionsRequest $request, FloorPlan $floorPlan): JsonResponse
    {
        $this->floorPlans->salvarPosicoes($floorPlan, $request->validated()['posicoes']);

        return response()->json(['success' => true, 'mensagem' => 'Posições salvas.']);
    }

    /**
     * Remove a posição de um dispositivo desta versão da planta. Também
     * `JsonResponse`, pelo mesmo motivo de `posicoes()`: o botão "remover"
     * do popover do editor não pode navegar para fora da tela de edição.
     * Dispositivo sem posição não é erro (`FloorPlanService::removerPosicao()`
     * documenta o motivo), a resposta é sempre `success: true`.
     */
    public function removerPosicao(FloorPlan $floorPlan, Device $device): JsonResponse
    {
        $this->floorPlans->removerPosicao($floorPlan, $device);

        return response()->json(['success' => true]);
    }

    /**
     * Impressão da planta desta versão específica (Plano 21, Task 21.6): a
     * planta ao fundo com os pontos numerados sobre ela e a legenda ao lado,
     * em duas versões possíveis - `?versao=tecnico` (rótulo completo e
     * código público de cada dispositivo, padrão desta rota de uso interno)
     * ou `?versao=cliente` (numeração, sem o código do dispositivo).
     *
     * Usa sempre a posição ATUAL de cada dispositivo na planta que a URL
     * pediu: `{floorPlan}` já é uma versão específica por causa do route
     * model binding, então não há "planta vigente no fim do período" a
     * resolver aqui - essa regra vale só para o croqui embutido num
     * `MonitoringReport` já gerado (`RelatorioPdfService::dados()`), que lê a
     * versão congelada em `dados.mapa_de_calor[].planta`, nunca esta rota.
     */
    public function croqui(Request $request, FloorPlan $floorPlan): Response
    {
        $versao = $request->query('versao', RelatorioPdfService::VERSAO_TECNICO);

        if (! in_array($versao, [RelatorioPdfService::VERSAO_TECNICO, RelatorioPdfService::VERSAO_CLIENTE], true)) {
            $versao = RelatorioPdfService::VERSAO_TECNICO;
        }

        return $this->relatorioPdf
            ->croqui($floorPlan, $versao)
            ->download("Croqui-{$floorPlan->nome}-v{$floorPlan->versao}.pdf");
    }
}
