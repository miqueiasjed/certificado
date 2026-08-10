<?php

namespace App\Http\Controllers;

use App\Exceptions\CompromissoNaoPromovelException;
use App\Http\Requests\CompromissoRequest;
use App\Models\Compromisso;
use App\Services\CompromissoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Ciclo de vida do compromisso avulso da Agenda, pelos endpoints de escrita
 * (Plano 30, Task 30.3): criar, atualizar, excluir, cancelar, concluir e
 * promover para uma ordem de serviço de verdade.
 *
 * Controller fino: nenhuma regra mora aqui. Toda decisão de negócio (o que é
 * editável, quando cancelar/concluir é permitido, o que barra a promoção)
 * está em `CompromissoService` (Task 30.2). **Nenhuma escrita em
 * `compromissos` acontece fora dele.**
 *
 * Sem middleware de módulo
 * -------------------------
 * Compromisso é extensão da Agenda (Plano 10), que já não é module-gated no
 * projeto (mesmo critério do comentário em `routes/web.php` sobre a Agenda
 * reaproveitar a permissão de OS). As rotas ficam no grupo autenticado comum,
 * só com `permission:compromisso-gerenciar`.
 *
 * Respostas em JSON puro, sem Inertia: mesmo padrão de `AgendaController`
 * para a escrita pelo calendário (`reagendar`, `atribuirTecnico`). O
 * compromisso ainda não tem página Inertia própria nesta task, e estes
 * endpoints são consumidos por fetch, do modal do calendário.
 *
 * Isolamento entre empresas
 * -------------------------
 * `{compromisso}` chega por route-model binding, e o escopo global do model
 * (trait `BelongsToCompany`) faz o binding não encontrar registro de outra
 * empresa — 404, resposta certa quando revelar a existência já vaza
 * informação (skill `permissoes-e-multitenancy`).
 *
 * `client_id`, `address_id`, `technician_id`, `work_order_id` (em
 * `store`/`update`) e `service_id` (na promoção) chegam pelo **corpo** da
 * requisição, e ali o escopo global não alcança: quem separa as empresas é o
 * `CompromissoRequest`, que compõe `Rule::exists(...)` com
 * `company_id = TenantAtual::exigirId()`, mesmo critério de
 * `PpeDeliveryRequest` (defeito corrigido no commit `d9a3a9c`).
 *
 * Exceção de domínio vira 422 em português, nunca 500
 * ----------------------------------------------------
 * `CompromissoNaoPromovelException` (compromisso já promovido ou cancelado)
 * carrega a mensagem pronta, mesmo padrão do método `recusar()` de
 * `PpeDeliveryController`.
 */
class CompromissoController extends Controller
{
    public function __construct(
        private readonly CompromissoService $compromissos,
    ) {}

    /**
     * Cria o compromisso.
     *
     * `criado_por` nunca vem do corpo da requisição: é sempre o usuário
     * autenticado, mesmo critério de `PpeDelivery::user_id`.
     */
    public function store(CompromissoRequest $request): JsonResponse
    {
        $dados = $request->validated();
        $dados['criado_por'] = Auth::id();

        $compromisso = $this->compromissos->criar($dados);

        return response()->json([
            'mensagem' => 'Compromisso criado.',
            'compromisso' => $compromisso,
        ], 201);
    }

    /**
     * Atualiza os campos editáveis do compromisso.
     *
     * Compromisso já promovido continua editável: editar não mexe na OS
     * gerada, são dois registros independentes a partir da promoção (ver o
     * cabeçalho de `CompromissoService`).
     */
    public function update(CompromissoRequest $request, Compromisso $compromisso): JsonResponse
    {
        $compromisso = $this->compromissos->atualizar($compromisso, $request->validated());

        return response()->json([
            'mensagem' => 'Compromisso atualizado.',
            'compromisso' => $compromisso,
        ]);
    }

    /**
     * Exclui o compromisso.
     *
     * Exclusão de verdade: diferente da entrega de EPI, compromisso não é
     * documento oponível, então apagar o registro é aceitável (Task 30.3).
     */
    public function destroy(Compromisso $compromisso): JsonResponse
    {
        $compromisso->delete();

        return response()->json([
            'mensagem' => 'Compromisso excluído.',
        ]);
    }

    /**
     * Cancela o compromisso. Permitido mesmo já concluído: cancelada nunca
     * some, só muda de situação (ver `CompromissoService::cancelar()`).
     */
    public function cancelar(Compromisso $compromisso): JsonResponse
    {
        $compromisso = $this->compromissos->cancelar($compromisso);

        return response()->json([
            'mensagem' => 'Compromisso cancelado.',
            'compromisso' => $compromisso,
        ]);
    }

    /**
     * Conclui o compromisso.
     */
    public function concluir(Compromisso $compromisso): JsonResponse
    {
        $compromisso = $this->compromissos->concluir($compromisso);

        return response()->json([
            'mensagem' => 'Compromisso concluído.',
            'compromisso' => $compromisso,
        ]);
    }

    /**
     * Promove o compromisso para uma ordem de serviço de verdade.
     *
     * `CompromissoNaoPromovelException` (já promovido ou cancelado) vira 422
     * com a mensagem pronta, mesmo padrão de `PpeDeliveryController::recusar()`.
     */
    public function promoverParaOs(CompromissoRequest $request, Compromisso $compromisso): JsonResponse
    {
        try {
            $ordemDeServico = $this->compromissos->promoverParaOs($compromisso, $request->validated());
        } catch (CompromissoNaoPromovelException $recusa) {
            return response()->json([
                'mensagem' => $recusa->getMessage(),
                'errors' => ['compromisso' => [$recusa->getMessage()]],
            ], 422);
        }

        return response()->json([
            'mensagem' => 'Compromisso promovido para a ordem de serviço.',
            'compromisso' => $compromisso->refresh(),
            'ordem_de_servico' => $ordemDeServico,
        ]);
    }
}
