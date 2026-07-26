<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContractVisitJustificationRequest;
use App\Models\Contract;
use App\Models\ContractVisitJustification;
use App\Services\GeracaoDeVisitasService;
use App\Services\JustificativaDeVisitaService;
use App\Services\PendenciasDeContratoService;
use Inertia\Inertia;

/**
 * Endpoints de leitura e geração sob demanda das visitas previstas de um
 * contrato, do painel de contratos periódicos com pendência de conformidade
 * (Task 9.7) e da justificativa de data prevista que não virou visita.
 *
 * Toda regra de cálculo, de geração e de justificativa já existe em
 * `GeracaoDeVisitasService`, `PendenciasDeContratoService` e
 * `JustificativaDeVisitaService`: este controller só orquestra e devolve a
 * resposta, sem recalcular nada por conta própria.
 */
class ContractVisitController extends Controller
{
    public function __construct(
        private readonly GeracaoDeVisitasService $geracaoDeVisitas,
        private readonly PendenciasDeContratoService $pendenciasDeContrato,
        private readonly JustificativaDeVisitaService $justificativas,
    ) {
    }

    /**
     * Visitas previstas do contrato: número, data, situação e OS vinculada.
     */
    public function index(Contract $contrato)
    {
        return response()->json([
            'visitas' => $this->geracaoDeVisitas->visitasComSituacao($contrato),
        ]);
    }

    /**
     * Geração sob demanda, para o usuário não precisar esperar a rotina
     * diária (Task 9.6). Usa o mesmo `GeracaoDeVisitasService::gerarPara` da
     * rotina: nenhuma regra de criação duplicada aqui, e rodar duas vezes
     * não duplica visita (idempotência é regra do Service, não deste
     * endpoint).
     */
    public function gerar(Contract $contrato)
    {
        $criadas = $this->geracaoDeVisitas->gerarPara($contrato);
        $quantidade = count($criadas);

        return response()->json([
            'success' => true,
            'message' => $quantidade > 0
                ? sprintf('%d visita(s) gerada(s) com sucesso.', $quantidade)
                : 'Nenhuma visita nova a gerar: o calendário do contrato já está atualizado.',
            'criadas' => $quantidade,
            'visitas' => $this->geracaoDeVisitas->visitasComSituacao($contrato),
        ]);
    }

    /**
     * Painel de contratos periódicos vigentes com pendência de conformidade:
     * sem periodicidade preenchida, sem visita gerada no período configurado
     * ou com visita vencida não executada. Contrato periódico sem visita
     * gerada é pendência de conformidade com a RDC 622/2022, e este painel é
     * o que impede isso de passar em silêncio.
     */
    public function pendencias()
    {
        return Inertia::render('Contracts/Pendencias', [
            'pendencias' => $this->pendenciasDeContrato->listar(),
        ]);
    }

    /**
     * Registra o motivo de uma ou mais datas previstas que não viraram visita.
     *
     * É a única saída da pendência "sem visita gerada no período": gerar não
     * resolve, porque a geração nunca cria OS com data no passado. O que sai
     * do painel é a pendência; o que fica é o motivo, com autor e data, na
     * tela do contrato.
     *
     * O autor vem do controller, nunca de dentro do Service.
     */
    public function justificar(ContractVisitJustificationRequest $request, Contract $contrato)
    {
        $resultado = $this->justificativas->justificar(
            $contrato,
            $request->validated('datas'),
            $request->validated('motivo'),
            $request->user()
        );

        return response()->json([
            'success' => $resultado['success'],
            'message' => $resultado['message'],
            'registradas' => $resultado['data']['registradas'],
            'atualizadas' => $resultado['data']['atualizadas'],
            'visitas' => $this->geracaoDeVisitas->visitasComSituacao($contrato),
        ], $resultado['success'] ? 200 : 422);
    }

    /**
     * Remove a justificativa, devolvendo a data ao painel de conformidade.
     *
     * O Service confere que a justificativa é do contrato da rota e devolve
     * 404 quando não é: o escopo por empresa barra o tenant vizinho, mas não
     * separa um contrato do outro dentro da mesma empresa.
     */
    public function removerJustificativa(Contract $contrato, ContractVisitJustification $justificativa)
    {
        $resultado = $this->justificativas->remover($contrato, $justificativa);

        return response()->json([
            'success' => $resultado['success'],
            'message' => $resultado['message'],
            'visitas' => $this->geracaoDeVisitas->visitasComSituacao($contrato),
        ]);
    }
}
