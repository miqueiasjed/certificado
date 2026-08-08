<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContractNaoRenovarRequest;
use App\Http\Requests\ContractRenovarRequest;
use App\Models\Contract;
use App\Services\ContractAlertService;
use App\Services\ContractRenewalService;
use App\Services\ContractService;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel de contratos a vencer e as três ações de renovação (Plano 23, Task
 * 23.6). Toda leitura vem de `ContractAlertService` (Task 23.5) e toda
 * escrita de `ContractRenewalService` (Task 23.4): este controller só
 * orquestra.
 *
 * URIs em inglês (`/contracts/...`), e não em português como o texto da task
 * sugere (`/contratos/...`): `Contract` é um recurso antigo do sistema, e
 * toda rota existente dele já é `/contracts/...`
 * (`contracts.pendencias`, `contracts.encerrar`, `contracts.visitas.*`).
 * Nascer esta com prefixo diferente criaria duas convenções para o mesmo
 * recurso; a inconsistência importa mais que seguir a grafia literal do
 * enunciado.
 *
 * Renovar é uma ação que já aplica tudo de uma vez (cria o contrato novo,
 * gera visitas, cancela as futuras do anterior) dentro de uma única
 * transação - `ContractRenewalService::renovar()` não tem um modo "simular
 * sem gravar". Para a tela confirmar os efeitos ANTES de aplicar sem duplicar
 * a lógica de cálculo do Service (que é privada, e duplicá-la arriscaria
 * divergência), `previa()` expõe só os dois fatos que já existem prontos em
 * outro lugar sem gravar nada: a elegibilidade (o mesmo método que `renovar()`
 * usa, `motivoDeRecusaDaRenovacao()`, tornado público) e a quantidade de
 * visitas futuras que seriam canceladas (`ContractService::contarVisitasFuturas()`,
 * o mesmo método que `ContractController::index()` já usa para o botão
 * "Encerrar"). A quantidade de visitas que seriam GERADAS não entra na
 * prévia: geração de verdade depende do calendário inteiro calculado dentro
 * da transação do `GeracaoDeVisitasService`, e simular isso fora dela
 * duplicaria a única fonte de verdade desse cálculo. A resposta do `POST
 * .../renovar`, que já aplica a renovação de fato, informa a contagem final
 * de visitas geradas e canceladas, para a tela mostrar o resumo pós-ação.
 */
class ContractRenewalController extends Controller
{
    public function __construct(
        private readonly ContractAlertService $alertas,
        private readonly ContractRenewalService $renovacao,
        private readonly ContractService $contratos,
    ) {}

    /**
     * Painel com os contratos a vencer em cada marco configurado da empresa
     * e os contratos vencidos sem tratativa.
     */
    public function aVencer(): Response|JsonResponse
    {
        $marcos = $this->alertas->marcos();

        $porMarco = collect($marcos)
            ->mapWithKeys(fn (int $dias): array => [$dias => $this->paraOPainel($this->alertas->aVencer($dias))])
            ->all();

        $dados = [
            'marcos' => $marcos,
            'a_vencer' => $porMarco,
            'vencidos_sem_tratativa' => $this->paraOPainel($this->alertas->vencidosSemTratativa()),
        ];

        if (request()->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Contracts/AVencer', $dados);
    }

    /**
     * Fatos calculáveis sem gravar nada, para a tela confirmar antes de
     * aplicar a renovação. Ver o docblock da classe para a decisão de não
     * simular a geração de visita aqui.
     */
    public function previa(Contract $contract): JsonResponse
    {
        $motivo = $this->renovacao->motivoDeRecusaDaRenovacao($contract);

        return response()->json([
            'elegivel' => $motivo === null,
            'motivo_recusa' => $motivo,
            'visitas_futuras_a_cancelar' => $this->contratos->contarVisitasFuturas($contract),
            'inicio_do_novo_contrato' => $motivo === null
                ? BusinessDate::paraFusoNegocio($contract->end_date)?->addDay()->toDateString()
                : null,
            'valor_atual' => $contract->service_value,
        ]);
    }

    /**
     * Renova de fato: cria o contrato novo, gera as visitas dele e cancela as
     * futuras do anterior, tudo em uma transação só. A resposta descreve os
     * efeitos aplicados (quantidade gerada e cancelada) na própria mensagem e
     * em `data`.
     */
    public function renovar(ContractRenovarRequest $request, Contract $contract): JsonResponse
    {
        $resultado = $this->renovacao->renovar($contract, $request->validated());

        return response()->json($resultado, $resultado['success'] ? 200 : 422);
    }

    public function naoRenovar(ContractNaoRenovarRequest $request, Contract $contract): JsonResponse
    {
        $resultado = $this->renovacao->registrarNaoRenovacao(
            $contract,
            $request->validated('motivo'),
            $request->validated('motivo_livre')
        );

        return response()->json($resultado, $resultado['success'] ? 200 : 422);
    }

    /**
     * Marca o contrato como em negociação de renovação. A escrita em
     * `situacao_renovacao` fica em `ContractRenewalService::marcarEmNegociacao()`,
     * e não aqui: aquele Service já é o dono de toda escrita deste campo (ver
     * o docblock de `marcarPendente()`), e o controller só chamaria a mesma
     * atribuição que o Service já faz.
     */
    public function emNegociacao(Contract $contract): JsonResponse
    {
        $resultado = $this->renovacao->marcarEmNegociacao($contract);

        return response()->json($resultado, $resultado['success'] ? 200 : 422);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * @param  Collection<int, Contract>  $contratos
     * @return array<int, array<string, mixed>>
     */
    private function paraOPainel(Collection $contratos): array
    {
        return $contratos
            ->map(fn (Contract $contrato): array => [
                'id' => (int) $contrato->getKey(),
                'contract_number' => $contrato->contract_number,
                'end_date' => BusinessDate::diaDe($contrato->end_date),
                'situacao_renovacao' => $contrato->situacao_renovacao,
                'cliente' => $contrato->address?->client?->name,
                'endereco' => $contrato->address?->nickname,
            ])
            ->values()
            ->all();
    }
}
