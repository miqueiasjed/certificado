<?php

namespace App\Http\Controllers;

use App\Http\Requests\OtimizarRotaRequest;
use App\Http\Requests\ReordenarRotaRequest;
use App\Http\Requests\RouteFilterRequest;
use App\Models\Route as RouteModel;
use App\Models\RouteStop;
use App\Models\Technician;
use App\Models\User;
use App\Services\Routing\RouteService;
use App\Support\BusinessDate;
use App\Support\Geo\Coordenada;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use InvalidArgumentException;

/**
 * Roteiro do dia, mapa das paradas e reordenação manual (Plano 22, Task
 * 22.5). Controller fino: toda regra de negócio (montar, sincronizar
 * preservando ordem manual, recalcular distância) vive em `RouteService`
 * (Task 22.3, ajustada nesta task - ver o cabeçalho daquela classe).
 *
 * `use App\Models\Route as RouteModel;` (ver o aviso no cabeçalho de
 * `App\Models\Route`): este controller não declara rota nenhuma, mas o alias
 * evita qualquer ambiguidade para quem for editar este arquivo lendo
 * `routes/web.php` ao lado, onde `Route::get(...)`/`Route::put(...)` é a
 * facade.
 *
 * ## Onde fica a coordenada da sede
 *
 * `App\Models\Company` não tem `latitude`/`longitude` (ver o cabeçalho de
 * `RouteService`, seção "Onde fica o endereço da sede" - decisão já tomada na
 * Task 22.3 de manter isso fora do escopo). `sedeCoordenada()` devolve
 * `null` enquanto isso não existir - NUNCA um ponto fixo como `(0, 0)`
 * (Golfo da Guiné): um valor inventado pareceria uma coordenada real e
 * entraria no cálculo de distância/duração total do roteiro como se fosse
 * (a primeira parada ficaria a milhares de km "de distância" da sede,
 * inflando `distancia_total_km`/`duracao_estimada_min` com um número sem
 * relação nenhuma com o trajeto real do técnico). `RouteService`/
 * `OtimizadorDeRota` já sabem lidar com `$partida === null`: a otimização
 * continua encadeando as paradas normalmente entre si, só sem uma "primeira
 * escolha" baseada em proximidade da base, e o trecho sede -> primeira
 * parada simplesmente não entra no total (mesmo tratamento de uma parada sem
 * coordenada boa).
 */
class RouteController extends Controller
{
    public function __construct(private readonly RouteService $routeService) {}

    /**
     * `GET /roteiros/painel`: casca Inertia da tela de roteiro (Plano 22,
     * Task 22.6). Só isso - nenhum dado do roteiro do dia vem por aqui.
     *
     * ## Por que esta rota não existia (buraco entre a Task 22.5 e a 22.6)
     *
     * A Task 22.5 expôs `GET /roteiros` como endpoint JSON puro
     * (`response()->json(...)`, ver `index()` abaixo) - correto para o
     * contrato dela, mas sem NENHUMA rota Inertia apontando para
     * `Roteiros/Index.vue`, a página fica inalcançável por URL (o mesmo
     * valeria para `Enderecos/PendenciasGeo.vue`, ver
     * `AddressGeoController::painel()`). Renomear `/roteiros` para servir
     * Inertia quando `! $request->wantsJson()` foi cogitado e descartado:
     * misturaria dois contratos diferentes na mesma rota testada e revisada
     * pela Task 22.5 (`RouteEndpointTest` já cobre `/roteiros` como JSON), e
     * o padrão que o resto do sistema já usa para "página com dados
     * carregados via fetch do próprio Vue" é justamente uma rota de página
     * separada da rota de dados (`AgendaController::index()` renderiza
     * `Agenda/Index.vue`, `AgendaController::dados()` devolve o JSON que
     * aquela página busca em seguida) - `/roteiros/painel` segue o mesmo
     * padrão, sem tocar no contrato JSON já validado de `/roteiros`.
     *
     * A lista de técnicos só é enviada para quem PODE escolher técnico
     * (coordenador/administrador - mesmo critério de
     * `usuarioRestritoAoProprioTecnico()`); para o técnico restrito ao
     * próprio roteiro, `Roteiros/Index.vue` usa `technicianIdProprio` direto,
     * sem seletor.
     */
    public function painel(Request $request): InertiaResponse
    {
        $usuario = $request->user();
        $restrito = $this->usuarioRestritoAoProprioTecnico($usuario);

        return Inertia::render('Roteiros/Index', [
            'tecnicos' => $restrito ? [] : Technician::query()->orderBy('name')->get(['id', 'name']),
            'restritoAoProprioTecnico' => $restrito,
            'technicianIdProprio' => $usuario->technician?->id,
            'dataInicial' => BusinessDate::hoje()->toDateString(),
        ]);
    }

    /**
     * `GET /roteiros?data=&technician_id=`.
     *
     * Regra de negócio inegociável (Task 22.5): uma busca NUNCA reroda o
     * otimizador sobre um roteiro que já existe, para nunca desfazer uma
     * ordem manual por baixo. `RouteService::montar()` só é chamado aqui
     * quando o roteiro do dia ainda não existe (primeira consulta do dia).
     */
    public function index(RouteFilterRequest $request): JsonResponse
    {
        $dados = $request->validated();
        $tecnico = $this->resolverTecnico($request->user(), $dados['technician_id'] ?? null);

        $dia = BusinessDate::paraFusoNegocio($dados['data']);

        if ($dia === null) {
            throw ValidationException::withMessages(['data' => 'Informe uma data válida.']);
        }

        $rota = $this->buscarOuMontarRotaDoDia($tecnico, $dia->toDateString());

        return response()->json($this->apresentarRota($rota));
    }

    /**
     * `POST /roteiros/otimizar`: recalcula e persiste a ordem de verdade,
     * sobrescrevendo qualquer ordenação manual anterior. É a única ação do
     * sistema com esse efeito - pedida explicitamente por quem tem
     * `roteiro-gerenciar`, nunca disparada por uma leitura.
     */
    public function otimizar(OtimizarRotaRequest $request): JsonResponse
    {
        $dados = $request->validated();
        $tecnico = $this->resolverTecnico($request->user(), $dados['technician_id'] ?? null);

        $rota = $this->routeService->montar(
            $tecnico,
            $dados['data'],
            $this->sedeCoordenada(),
            forcarReotimizacao: true,
        );

        return response()->json($this->apresentarRota($rota));
    }

    /**
     * `POST /roteiros/simular`: mesmo corpo e mesma permissão de
     * `otimizar()` (reutiliza `OtimizarRotaRequest` - o formato de entrada é
     * idêntico: `data` obrigatória, `technician_id` conforme o papel de quem
     * pede), mas NUNCA grava nada. Devolve a comparação "ordem atual vs.
     * ordem otimizada" que `Roteiros/Index.vue` mostra antes de o usuário
     * confirmar a otimização de verdade - ver o docblock de
     * `RouteService::simularOtimizacao()` para o motivo desta rota existir
     * separada de `otimizar()`.
     */
    public function simular(OtimizarRotaRequest $request): JsonResponse
    {
        $dados = $request->validated();
        $tecnico = $this->resolverTecnico($request->user(), $dados['technician_id'] ?? null);

        $comparacao = $this->routeService->simularOtimizacao($tecnico, $dados['data'], $this->sedeCoordenada());

        return response()->json($comparacao);
    }

    /**
     * `PUT /roteiros/{route}/ordem`: reordenação manual. Corpo esperado:
     * `{ "work_order_ids": [3, 1, 2] }`, a lista de OS na nova ordem (ver o
     * docblock de `ReordenarRotaRequest` para a decisão de contrato).
     *
     * `$this->sedeCoordenada()` passado explicitamente (Task 22.6): ver o
     * docblock de `RouteService::reordenarManualmente()` para o motivo -
     * sem isso, `distancia_total_km`/`duracao_estimada_min` do roteiro
     * ficavam parados no valor da última otimização automática em vez de
     * refletir a ordem que acabou de ser gravada.
     */
    public function ordem(ReordenarRotaRequest $request, RouteModel $route): JsonResponse
    {
        $this->garantirAcessoAoRoteiro($route, $request->user());

        try {
            $rota = $this->routeService->reordenarManualmente(
                $route,
                $request->validated('work_order_ids'),
                $request->user(),
                $this->sedeCoordenada(),
            );
        } catch (InvalidArgumentException $erro) {
            return response()->json(['message' => $erro->getMessage()], 422);
        }

        return response()->json($this->apresentarRota($rota));
    }

    /**
     * `GET /roteiros/{route}/mapa`: paradas com coordenada, pronta para a
     * tela de mapa da Task 22.6. Mesma apresentação de `index()`/`otimizar()`
     * (já traz `latitude`/`longitude` por parada), sem estrutura própria: não
     * há necessidade de duas formas diferentes de descrever a mesma parada.
     */
    public function mapa(Request $request, RouteModel $route): JsonResponse
    {
        $this->garantirAcessoAoRoteiro($route, $request->user());

        return response()->json($this->apresentarRota($route));
    }

    /**
     * `GET /api/app/roteiro?data=` (Plano 12, grupo `auth:sanctum` do app do
     * técnico, ver `routes/api.php`): o roteiro do técnico autenticado.
     * Sempre o PRÓPRIO técnico vinculado ao usuário logado - o app não tem
     * noção de "escolher outro técnico", diferente do painel web onde
     * coordenador/administrador informam `technician_id`.
     *
     * Sem `data` na querystring, usa o dia de hoje no fuso do negócio (mesmo
     * padrão de período-padrão de `AppDayLoadService`).
     */
    public function appRoteiro(Request $request): JsonResponse
    {
        $tecnico = $request->user()->technician;

        if ($tecnico === null) {
            return response()->json([
                'message' => 'Seu usuário não está vinculado a um cadastro de técnico.',
            ], 422);
        }

        $dataParam = $request->query('data');
        $dia = filled($dataParam) ? BusinessDate::paraFusoNegocio($dataParam) : BusinessDate::hoje();

        if ($dia === null) {
            return response()->json(['message' => 'Informe uma data válida.'], 422);
        }

        $rota = $this->buscarOuMontarRotaDoDia($tecnico, $dia->toDateString());

        return response()->json($this->apresentarRota($rota));
    }

    // -----------------------------------------------------------------
    // Resolução de técnico e controle de acesso
    // -----------------------------------------------------------------

    /**
     * Técnico alvo da consulta/ação. Usuário com o papel `tecnico` (e sem o
     * papel `administrador`) é sempre restrito ao próprio técnico vinculado -
     * um `technician_id` enviado por ele é ignorado, nunca escolhe o roteiro
     * de outro. Coordenador/administrador escolhem via `technician_id`,
     * sempre obrigatório para eles.
     *
     * Mesmo critério de `AppDayLoadService::resolverTecnicoAlvo()` e de
     * `WorkOrderAccessService::precisaDeFiltro()`, duplicado aqui pelo mesmo
     * motivo que `AppDayLoadService` já documenta: são métodos privados, e
     * esta task não altera nenhum dos dois - "replica o padrão em vez de
     * inventar um novo" (instrução desta task) significa reaproveitar a MESMA
     * regra, não necessariamente a mesma classe, porque `Route` não tem o
     * caminho por pivot que `WorkOrder` tem.
     *
     * @throws AuthorizationException Usuário técnico sem cadastro de técnico vinculado.
     * @throws ValidationException Coordenador/administrador sem informar `technician_id`.
     */
    private function resolverTecnico(User $usuario, mixed $technicianIdParam): Technician
    {
        if ($this->usuarioRestritoAoProprioTecnico($usuario)) {
            $tecnico = $usuario->technician;

            if ($tecnico === null) {
                throw new AuthorizationException('Seu usuário não está vinculado a um cadastro de técnico.');
            }

            return $tecnico;
        }

        if (blank($technicianIdParam)) {
            throw ValidationException::withMessages([
                'technician_id' => 'Informe o técnico para consultar o roteiro.',
            ]);
        }

        // Escopado à empresa corrente pelo escopo global de
        // `BelongsToCompany`: técnico de outra empresa nunca é encontrado
        // aqui, e cai no mesmo 404 de um id inventado.
        return Technician::query()->findOrFail((int) $technicianIdParam);
    }

    private function usuarioRestritoAoProprioTecnico(User $usuario): bool
    {
        if ($usuario->hasRole('administrador')) {
            return false;
        }

        return $usuario->hasRole('tecnico');
    }

    /**
     * Bloqueia o acesso do técnico a um roteiro de outro técnico. Réplica do
     * padrão de `WorkOrderAccessService::garantirAcesso()`: 403, não 404
     * (dentro da mesma empresa a existência do roteiro não é segredo, mesmo
     * critério já usado para ordem de serviço), adaptada para `Route` -
     * aqui o vínculo é uma coluna direta (`technician_id`), sem o caminho por
     * pivot que `WorkOrder` tem.
     *
     * @throws AuthorizationException
     */
    private function garantirAcessoAoRoteiro(RouteModel $rota, User $usuario): void
    {
        if (! $this->usuarioRestritoAoProprioTecnico($usuario)) {
            return;
        }

        $tecnicoId = $usuario->technician?->id;

        if ($tecnicoId === null || (int) $rota->technician_id !== $tecnicoId) {
            throw new AuthorizationException('Você não tem permissão para acessar este roteiro.');
        }
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Busca a `Route` já montada para `[technician_id, data]`, ou monta uma
     * nova quando ainda não existe nenhuma para o dia - a única situação em
     * que uma leitura chama `RouteService::montar()` (sem forçar
     * reotimização: se por acaso já existir uma ordenação manual, ela é
     * respeitada por `montar()` mesmo assim, ver o cabeçalho daquela classe).
     */
    private function buscarOuMontarRotaDoDia(Technician $tecnico, string $dataNormalizada): RouteModel
    {
        $rota = RouteModel::query()
            ->where('technician_id', $tecnico->id)
            ->where('data', $dataNormalizada)
            ->first();

        if ($rota === null) {
            $rota = $this->routeService->montar($tecnico, $dataNormalizada, $this->sedeCoordenada());
        }

        return $rota;
    }

    /**
     * Ver o docblock da classe ("Onde fica a coordenada da sede") para a
     * limitação documentada deste valor fixo.
     */
    /**
     * `null` até `companies` ganhar coordenada própria (ver o cabeçalho
     * desta classe): nunca um ponto fixo inventado.
     */
    private function sedeCoordenada(): ?Coordenada
    {
        return null;
    }

    /**
     * Formato de resposta comum a `index()`, `otimizar()`, `ordem()`,
     * `mapa()` e `appRoteiro()`: uma única forma de descrever um roteiro,
     * para o frontend (painel e app) nunca precisar entender duas.
     *
     * Recalcula a chegada estimada antes de montar a resposta
     * (`RouteService::chegadaEstimada()`): é um campo derivado, que não
     * altera `ordem` nem `ordenacao_manual`, então recalculá-lo a cada
     * leitura não contradiz a regra "leitura nunca desfaz ordem manual" -
     * só mantém o horário estimado de cada parada consistente com a ordem
     * atual, que pode ter mudado por reordenação manual ou por sincronização
     * desde a última vez que foi calculada.
     */
    private function apresentarRota(RouteModel $rota): array
    {
        $rota = $this->routeService->chegadaEstimada($rota);
        $rota->loadMissing(['stops.workOrder.address', 'stops.workOrder.client', 'reordenadaPor:id,name']);

        return [
            'id' => $rota->id,
            'technician_id' => $rota->technician_id,
            'data' => $rota->data->toDateString(),
            'situacao' => $rota->situacao,
            'distancia_total_km' => $rota->distancia_total_km !== null ? (float) $rota->distancia_total_km : null,
            'duracao_estimada_min' => $rota->duracao_estimada_min,
            'otimizada_em' => optional($rota->otimizada_em)->toIso8601String(),
            'ordenacao_manual' => (bool) $rota->ordenacao_manual,
            'reordenada_por' => $rota->reordenadaPor?->name,
            'reordenada_em' => optional($rota->reordenada_em)->toIso8601String(),
            'paradas' => $rota->stops->map(function (RouteStop $parada): array {
                $ordem = $parada->workOrder;
                $endereco = $ordem?->address;

                return [
                    'route_stop_id' => $parada->id,
                    'work_order_id' => $parada->work_order_id,
                    'ordem' => $parada->ordem,
                    'chegada_estimada' => $parada->chegada_estimada,
                    'distancia_anterior_km' => $parada->distancia_anterior_km !== null ? (float) $parada->distancia_anterior_km : null,
                    'duracao_anterior_min' => $parada->duracao_anterior_min,
                    'cliente' => $ordem?->client?->name,
                    'endereco' => $endereco?->full_address,
                    'latitude' => $endereco?->latitude !== null ? (float) $endereco->latitude : null,
                    'longitude' => $endereco?->longitude !== null ? (float) $endereco->longitude : null,
                    'precisao_geocodificacao' => $endereco?->precisao_geocodificacao,
                ];
            })->values()->all(),
        ];
    }
}
