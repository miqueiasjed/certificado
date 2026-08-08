<?php

namespace App\Services;

use App\Models\Contract;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Renova contrato gerando um novo, com reajuste aplicado e o anterior
 * preservado como histórico (Plano 23, Task 23.4).
 *
 * Decisão central do domínio, que domina todo o arquivo: renovação SEMPRE
 * cria uma linha nova em `contracts`. Estender `end_date` do contrato
 * existente apagaria o valor e as condições que ele tinha antes, que é
 * exatamente o dado que responde "quanto o cliente pagava antes". O contrato
 * anterior nunca é gravado por este Service além da marcação de renovação
 * (`situacao_renovacao` e `renovado_em`): histórico de contrato é documento,
 * não um registro que se atualiza por cima.
 *
 * Por isso a criação do contrato novo nunca passa por
 * `ContractService::criar()`: aquele método faz `updateOrCreate` por
 * `address_id` (o cadastro comum trata "endereço tem um contrato" como
 * upsert) e sobrescreveria o anterior em vez de preservá-lo.
 * `ContractService::criarNovo()` existe para este caso: sempre
 * `Contract::create`, nunca upsert.
 */
class ContractRenewalService
{
    /**
     * Janela de renovação decidida na Task 23.4: 90 dias antes do fim até 30
     * dias depois. Fora dela, "renovar" um contrato que venceu há meses
     * criaria uma cadeia sem sentido: é recadastro, não renovação.
     */
    private const DIAS_ANTES_DO_FIM = 90;

    private const DIAS_DEPOIS_DO_FIM = 30;

    /**
     * Mesma janela de geração de visitas usada pela rotina agendada do Plano
     * 9 (`GeracaoDeVisitasService::MESES_JANELA_PADRAO`, privada lá, por isso
     * repetida aqui). Gerar o contrato renovado inteiro de uma vez encheria a
     * agenda de OS que a periodicidade ainda pode mudar; a rotina diária
     * estende a janela dia a dia depois disso, do mesmo jeito que faz para
     * qualquer outro contrato vigente.
     */
    private const MESES_JANELA_DE_GERACAO = 3;

    /**
     * Motivo gravado nas visitas futuras canceladas do contrato anterior.
     */
    private const MOTIVO_CANCELAMENTO = 'Contrato renovado';

    /**
     * Lista fechada de motivos de não renovação (Task 23.4), com o rótulo
     * gravado em `motivo_nao_renovacao` quando o motivo não é "outro". Motivo
     * aberto sozinho não serve para a análise de perda de cliente que a Task
     * 23.8 constrói em cima disto; por isso "outro" exige texto livre.
     *
     * @var array<string, string>
     */
    public const MOTIVOS_NAO_RENOVACAO = [
        'preco' => 'Preço',
        'mudou_fornecedor' => 'Mudou de fornecedor',
        'encerrou_atividade' => 'Encerrou a atividade',
        'insatisfacao_servico' => 'Insatisfação com o serviço',
        'outro' => 'Outro',
    ];

    public function __construct(
        private readonly ContractService $contractService,
        private readonly GeracaoDeVisitasService $geracaoDeVisitas,
        private readonly ManutencaoDeVisitasService $manutencaoDeVisitas,
    ) {
    }

    /**
     * Renova o contrato: cria um novo copiando endereço (e, por tabela, o
     * cliente dono dele), serviço, periodicidade e cláusulas do anterior, com
     * vigência começando no dia seguinte ao fim do anterior e o reajuste
     * informado aplicado ao valor. Dispara a geração das visitas do contrato
     * novo e cancela as visitas futuras do anterior.
     *
     * Tudo roda em uma única transação: contrato novo criado sem o anterior
     * marcado como renovado (ou vice-versa) deixaria a cadeia inconsistente,
     * pior do que a renovação não ter acontecido.
     *
     * @param  array<string, mixed>  $dados  'percentual_reajuste' (numérico,
     *         opcional, tratado como 0 quando ausente: renovar sem informar
     *         reajuste mantém o valor do contrato anterior), 'indice_reajuste'
     *         (string opcional: nome do índice de referência usado para
     *         chegar ao percentual, ex. "IPCA"; informado pelo usuário nesta
     *         entrega, não buscado de fonte externa), 'end_date' (opcional:
     *         quando ausente, a vigência nova dura os mesmos dias que a
     *         vigência anterior).
     * @return array{success: bool, message: string, data: array}
     */
    public function renovar(Contract $contrato, array $dados): array
    {
        $motivoDeRecusa = $this->motivoDeRecusaDaRenovacao($contrato);

        if ($motivoDeRecusa !== null) {
            return [
                'success' => false,
                'message' => $motivoDeRecusa,
                'data' => [],
            ];
        }

        return DB::transaction(function () use ($contrato, $dados): array {
            $percentual = $this->percentualInformado($dados);
            $indice = $this->indiceInformado($dados);

            $inicioNovo = BusinessDate::paraFusoNegocio($contrato->end_date)->addDay();
            $fimNovo = $this->vigenciaFinalNova($contrato, $inicioNovo, $dados);
            $valorNovo = $this->valorComReajuste($contrato->service_value, $percentual);

            $novo = $this->contractService->criarNovo($contrato->address, [
                'service_type' => $contrato->service_type,
                'visit_frequency' => $contrato->visit_frequency,
                'visit_frequency_valor' => $contrato->visit_frequency_valor,
                'visit_frequency_unidade' => $contrato->visit_frequency_unidade,
                'visit_count' => $contrato->visit_count,
                'pest_target' => $contrato->pest_target,
                'payment_method' => $contrato->payment_method,
                'payment_details' => $contrato->payment_details,
                'additional_clause' => $contrato->additional_clause,
                'jurisdiction' => $contrato->jurisdiction,
                'start_date' => $inicioNovo->toDateString(),
                'end_date' => $fimNovo?->toDateString(),
                'service_value' => $valorNovo,
                'contrato_anterior_id' => $contrato->id,
                'percentual_reajuste' => $percentual,
                'indice_reajuste' => $indice,
            ]);

            $ateAGeracao = BusinessDate::hoje()->addMonthsNoOverflow(self::MESES_JANELA_DE_GERACAO)->toDateString();
            $visitasGeradas = $this->geracaoDeVisitas->gerarPara($novo, $ateAGeracao);

            $cancelamento = $this->manutencaoDeVisitas->cancelarFuturas($contrato, self::MOTIVO_CANCELAMENTO);

            // O anterior não é tocado além desta marcação: nada de
            // `service_value`, vigência ou cláusula muda nele por aqui.
            $contrato->situacao_renovacao = 'renovado';
            $contrato->renovado_em = BusinessDate::agora();
            $contrato->save();

            return [
                'success' => true,
                'message' => sprintf(
                    'Contrato renovado. Novo contrato %s vigente de %s a %s. %d visita(s) gerada(s), %d visita(s) futura(s) do contrato anterior cancelada(s).',
                    $novo->contract_number,
                    $inicioNovo->format('d/m/Y'),
                    $fimNovo !== null ? $fimNovo->format('d/m/Y') : 'sem término definido',
                    count($visitasGeradas),
                    $cancelamento['canceladas']
                ),
                'data' => [
                    'contrato_anterior' => $contrato,
                    'contrato_novo' => $novo,
                    'visitas_geradas' => count($visitasGeradas),
                    'cancelamento' => $cancelamento,
                ],
            ];
        });
    }

    /**
     * Registra que o contrato não será renovado, com motivo da lista fechada
     * (Task 23.4). É o dado que responde por que se perde cliente.
     *
     * Sem janela de elegibilidade: ao contrário de `renovar()`, decidir e
     * registrar que não vai renovar pode acontecer a qualquer momento antes
     * ou depois do fim do contrato.
     *
     * @return array{success: bool, message: string, data: array}
     */
    public function registrarNaoRenovacao(Contract $contrato, string $motivo, ?string $motivoLivre = null): array
    {
        if (! array_key_exists($motivo, self::MOTIVOS_NAO_RENOVACAO)) {
            return [
                'success' => false,
                'message' => 'Motivo de não renovação inválido. Escolha um da lista: '
                    . implode(', ', self::MOTIVOS_NAO_RENOVACAO) . '.',
                'data' => [],
            ];
        }

        $motivoLivre = trim((string) $motivoLivre);

        if ($motivo === 'outro' && $motivoLivre === '') {
            return [
                'success' => false,
                'message' => 'Informe o texto do motivo quando escolher "Outro".',
                'data' => [],
            ];
        }

        $contrato->situacao_renovacao = 'nao_renovado';
        $contrato->motivo_nao_renovacao = $motivo === 'outro'
            ? $motivoLivre
            : self::MOTIVOS_NAO_RENOVACAO[$motivo];
        $contrato->save();

        return [
            'success' => true,
            'message' => 'Não renovação registrada.',
            'data' => ['contrato' => $contrato],
        ];
    }

    /**
     * Marca o contrato como pendente de tratativa de renovação, quando ele
     * entra na janela de alerta (Task 23.5) e ainda não tinha nenhuma
     * situação registrada.
     *
     * Sem efeito quando já existe alguma situação (`pendente`,
     * `em_negociacao`, `renovado` ou `nao_renovado`): a rotina de alerta só
     * declara a entrada na janela, nunca sobrescreve uma decisão já tomada ou
     * uma negociação em andamento. Ver o docblock da migration
     * `add_renovacao_to_contracts_table`: contrato nasce com
     * `situacao_renovacao` nulo, e `pendente` é o estado que a rotina de
     * alerta atribui explicitamente ao primeiro aviso, não algo que todo
     * contrato já carrega.
     *
     * Vive aqui, e não em `ContractAlertService`, porque este Service já é o
     * dono de toda escrita em `situacao_renovacao` (`renovar()` grava
     * `renovado`, `registrarNaoRenovacao()` grava `nao_renovado`); manter a
     * quarta escrita possível deste campo em um Service de leitura
     * (`ContractAlertService` só monta consultas, pelo mesmo critério de
     * `StockAlertService`) espalharia a mesma regra em dois lugares.
     */
    public function marcarPendente(Contract $contrato): void
    {
        if ($contrato->situacao_renovacao !== null) {
            return;
        }

        $contrato->situacao_renovacao = 'pendente';
        $contrato->save();
    }

    /**
     * Marca o contrato como em negociação de renovação (Task 23.6): pausa o
     * aviso semanal do alerta de vencimento por 30 dias
     * (`VerificarContratosAVencer`) enquanto a conversa com o cliente ainda
     * está em andamento. `Contract::booted()` carimba `em_negociacao_em`
     * sozinho a partir da mudança de `situacao_renovacao`, então este método
     * não precisa tocar naquela coluna.
     *
     * Sem efeito sobre uma decisão já tomada (`renovado`, `nao_renovado`):
     * mesmo critério de `marcarPendente()`, este Service é o dono de toda
     * escrita em `situacao_renovacao` (ver o docblock daquele método), e por
     * isso o endpoint da Task 23.6 chama este método em vez de gravar o campo
     * direto no controller.
     *
     * @return array{success: bool, message: string, data: array}
     */
    public function marcarEmNegociacao(Contract $contrato): array
    {
        if (in_array($contrato->situacao_renovacao, ['renovado', 'nao_renovado'], true)) {
            return [
                'success' => false,
                'message' => 'Este contrato já teve a renovação decidida e não pode voltar a negociação.',
                'data' => [],
            ];
        }

        $contrato->situacao_renovacao = 'em_negociacao';
        $contrato->save();

        return [
            'success' => true,
            'message' => 'Contrato marcado como em negociação de renovação.',
            'data' => ['contrato' => $contrato],
        ];
    }

    /**
     * Motivo pelo qual a renovação deve ser recusada, ou `null` quando pode
     * prosseguir. Centraliza as duas checagens de elegibilidade: contrato já
     * renovado antes, e fora da janela dos 90 dias antes / 30 dias depois do
     * fim.
     *
     * Público (Task 23.6): é a mesma checagem que `GET
     * /contracts/{contract}/renovar/previa` expõe para a tela confirmar a
     * elegibilidade antes de o usuário decidir renovar, sem duplicar a regra.
     */
    public function motivoDeRecusaDaRenovacao(Contract $contrato): ?string
    {
        if ($contrato->situacao_renovacao === 'renovado') {
            return 'Este contrato já foi renovado. A renovação em vigor é o contrato mais novo da cadeia.';
        }

        if ($contrato->end_date === null) {
            return 'Contrato sem data de término definida não pode ser renovado.';
        }

        $hoje = BusinessDate::hoje();
        $fim = BusinessDate::paraFusoNegocio($contrato->end_date);

        $inicioDaJanela = $fim->subDays(self::DIAS_ANTES_DO_FIM);
        $fimDaJanela = $fim->addDays(self::DIAS_DEPOIS_DO_FIM);

        if ($hoje->lessThan($inicioDaJanela) || $hoje->greaterThan($fimDaJanela)) {
            return sprintf(
                'Contrato fora da janela de renovação: só pode ser renovado entre %s e %s '
                    . '(90 dias antes até 30 dias depois do fim, em %s). Fora desse período '
                    . 'o caminho é um recadastro, não uma renovação.',
                $inicioDaJanela->format('d/m/Y'),
                $fimDaJanela->format('d/m/Y'),
                $fim->format('d/m/Y')
            );
        }

        return null;
    }

    /**
     * Percentual de reajuste informado. Ausente ou vazio vira 0: renovar sem
     * informar reajuste mantém o valor do contrato anterior.
     */
    private function percentualInformado(array $dados): float
    {
        return isset($dados['percentual_reajuste']) && $dados['percentual_reajuste'] !== ''
            ? (float) $dados['percentual_reajuste']
            : 0.0;
    }

    /**
     * Nome do índice de referência informado (ex. "IPCA", "IGP-M"), ou `null`
     * quando o reajuste foi por percentual direto, sem referência a índice.
     */
    private function indiceInformado(array $dados): ?string
    {
        $indice = $dados['indice_reajuste'] ?? null;

        return is_string($indice) && trim($indice) !== '' ? trim($indice) : null;
    }

    /**
     * Valor do contrato novo, com o percentual aplicado sobre o valor do
     * anterior. Sem valor anterior definido, não há o que reajustar.
     */
    private function valorComReajuste(?string $valorAnterior, float $percentual): ?string
    {
        if ($valorAnterior === null) {
            return null;
        }

        return number_format(((float) $valorAnterior) * (1 + $percentual / 100), 2, '.', '');
    }

    /**
     * Fim da vigência nova. Usa `end_date` informado em `$dados` quando
     * presente (a duração pode ser renegociada na renovação); sem ele, a
     * vigência nova dura exatamente os mesmos dias que a vigência anterior,
     * para não mudar silenciosamente o prazo do contrato.
     */
    private function vigenciaFinalNova(Contract $contrato, CarbonImmutable $inicioNovo, array $dados): ?CarbonImmutable
    {
        if (! empty($dados['end_date'])) {
            return BusinessDate::paraFusoNegocio($dados['end_date']);
        }

        $inicioAnterior = BusinessDate::paraFusoNegocio($contrato->start_date);

        if ($inicioAnterior === null) {
            return null;
        }

        $fimAnterior = BusinessDate::paraFusoNegocio($contrato->end_date);
        $duracaoEmDias = $inicioAnterior->diffInDays($fimAnterior);

        return $inicioNovo->addDays($duracaoEmDias);
    }
}
