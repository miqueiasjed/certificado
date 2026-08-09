<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServicePpeRequirementRequest;
use App\Models\PersonalProtectiveEquipment;
use App\Models\ServicePpeRequirement;
use App\Services\Ppe\ExigenciaDeEpiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * EPI exigido por serviço (Plano 29, Task 29.3).
 *
 * É a declaração de escritório — "executar dedetização exige respirador e luva" —
 * e a origem de tudo que o Plano 29 faz depois: é dela que a carga do dia monta a
 * etapa de confirmação no aplicativo do técnico (`AppDayLoadService`), que a
 * `ConfirmacaoDeEpiService` decide o que aceitar da fila offline e que o checklist
 * da RDC 622/2022 (Task 29.4) sabe o que cobrar.
 *
 * Controller fino: nenhuma regra mora aqui. Recusar EPI inativo, tratar o cadastro
 * repetido do mesmo par como atualização e conferir que serviço e EPI são da mesma
 * empresa são todos assunto do `ExigenciaDeEpiService`. **Nenhuma escrita em
 * `service_ppe_requirements` acontece fora dele.**
 *
 * Uma permissão só: `epi-gerenciar`
 * ---------------------------------
 * As quatro rotas, leitura incluída, ficam sob `permission:epi-gerenciar` mais
 * `module:epi`. Nenhuma permissão nova entrou no catálogo nesta task: declarar o
 * que um serviço exige é configuração do mesmo cadastro que `epi-gerenciar` já
 * governa (o modelo de EPI, o número do CA, a validade), e não a leitura
 * operacional que `epi-ver` abre. Quem enxerga a ficha do técnico não decide o que
 * a empresa passa a exigir em campo.
 *
 * Isolamento entre empresas
 * -------------------------
 * `{requirement}` chega por route-model binding, e o escopo global de
 * `ServicePpeRequirement` faz o binding não encontrar exigência de outra empresa —
 * 404, que é a resposta certa quando revelar a existência já vaza informação.
 *
 * `service_id` e `personal_protective_equipment_id` chegam pelo **corpo** da
 * requisição, e ali o escopo global não alcança: quem separa as empresas é o
 * `ServicePpeRequirementRequest`, que compõe `Rule::exists(...)` com
 * `company_id = TenantAtual::exigirId()` e devolve 422. `exists:` cru aceitaria id
 * de outra empresa e ainda responderia se aquele id existe em algum tenant — o
 * defeito corrigido no commit `d9a3a9c`.
 *
 * Nada aqui bloqueia a execução da OS
 * -----------------------------------
 * Serviço sem exigência cadastrada é o estado normal de quem acabou de ligar o
 * módulo, e nunca irregularidade: a etapa de confirmação simplesmente não aparece
 * para aquele serviço. Decisão registrada do plano — o sistema informa, não
 * impede.
 */
class ServicePpeRequirementController extends Controller
{
    public function __construct(
        private readonly ExigenciaDeEpiService $exigencias,
    ) {}

    // -----------------------------------------------------------------
    // Leitura
    // -----------------------------------------------------------------

    /**
     * Exigências cadastradas, opcionalmente as de um serviço só (`?service_id=`).
     *
     * Vem junto a lista dos EPIs **ativos** do cadastro, que é o que a tela do
     * serviço oferece para escolha: EPI inativo não pode virar exigência nova
     * (o Service recusa), e oferecer o que será recusado é a pior ordem das duas.
     *
     * Só `id`, `nome` e `tipo` de cada EPI, o mesmo recorte que vai para o
     * aparelho do técnico: CA, validade e fabricante não têm papel nenhum na
     * decisão de quais EPIs um serviço exige, e são assunto da tela de cadastro
     * do modelo de EPI (Plano 28).
     *
     * Resposta sempre JSON: a tela é a aba de EPI dentro do cadastro de serviço
     * (Task 29.5), que já é uma página Inertia própria e busca esta lista por
     * fetch.
     */
    public function index(ServicePpeRequirementRequest $request): JsonResponse
    {
        $consulta = ServicePpeRequirement::query()
            ->with([
                'service:id,name,category',
                'personalProtectiveEquipment:id,nome,tipo',
            ])
            ->orderBy('service_id')
            ->orderBy('personal_protective_equipment_id');

        $servicoId = $request->validated('service_id');

        if ($servicoId !== null) {
            $consulta->where('service_id', (int) $servicoId);
        }

        return response()->json([
            'exigencias' => $consulta->get(),
            'episDisponiveis' => PersonalProtectiveEquipment::query()
                ->where('ativo', true)
                ->orderBy('nome')
                ->get(['id', 'nome', 'tipo']),
            'filtros' => [
                'service_id' => $servicoId !== null ? (int) $servicoId : null,
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // Escrita: sempre pelo ExigenciaDeEpiService
    // -----------------------------------------------------------------

    /**
     * Cadastra a exigência de um EPI em um serviço.
     *
     * Cadastrar o mesmo par duas vezes não é erro: o Service atualiza a linha
     * existente, e a resposta é a mesma. Quem clicou duas vezes no botão, ou
     * reabriu a tela com o item já cadastrado, não pode receber um 500 de violação
     * de chave única.
     */
    public function store(ServicePpeRequirementRequest $request): RedirectResponse|JsonResponse
    {
        $exigencia = $this->exigencias->registrar($request->validated());

        $exigencia->load(['service:id,name,category', 'personalProtectiveEquipment:id,nome,tipo']);

        if ($request->expectsJson()) {
            return response()->json([
                'mensagem' => 'Exigência de EPI cadastrada para o serviço.',
                'exigencia' => $exigencia,
            ], 201);
        }

        return back()->with('success', 'Exigência de EPI cadastrada para o serviço.');
    }

    /**
     * Alterna a exigência entre obrigatória e recomendada.
     *
     * A diferença decide o aviso ao gestor: só o EPI obrigatório marcado como não
     * usado gera `EXECUCAO_SEM_EPI_EXIGIDO`. O recomendado fica no registro e não
     * vira alarme.
     */
    public function update(
        ServicePpeRequirementRequest $request,
        ServicePpeRequirement $requirement
    ): RedirectResponse|JsonResponse {
        $exigencia = $this->exigencias->atualizar(
            $requirement,
            (bool) $request->validated('obrigatorio')
        );

        $exigencia->load(['service:id,name,category', 'personalProtectiveEquipment:id,nome,tipo']);

        if ($request->expectsJson()) {
            return response()->json([
                'mensagem' => 'Exigência de EPI atualizada.',
                'exigencia' => $exigencia,
            ]);
        }

        return back()->with('success', 'Exigência de EPI atualizada.');
    }

    /**
     * Remove a exigência do serviço.
     *
     * Exclusão de verdade, diferente da entrega de EPI, que só admite estorno: a
     * entrega é documento oponível, e a exigência é cadastro de configuração.
     * As confirmações já registradas nas ordens de serviço continuam intactas —
     * o que o técnico confirmou vestir em campo aconteceu.
     */
    public function destroy(Request $request, ServicePpeRequirement $requirement): RedirectResponse|JsonResponse
    {
        $this->exigencias->remover($requirement);

        if ($request->expectsJson()) {
            return response()->json([
                'mensagem' => 'Exigência de EPI removida do serviço. '
                    .'As confirmações já registradas nas ordens de serviço continuam no histórico.',
            ]);
        }

        return back()->with('success', 'Exigência de EPI removida do serviço.');
    }
}
