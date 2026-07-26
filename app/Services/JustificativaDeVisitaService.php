<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractVisitJustification;
use App\Models\User;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Registro e remoção do motivo de uma data prevista de visita que não virou
 * Ordem de Serviço.
 *
 * O problema que este Service resolve: o painel acusa contrato periódico com
 * data prevista vencida sem OS (pendência de conformidade com a RDC
 * 622/2022), e não havia saída. "Gerar visitas" não resolve, porque
 * `GeracaoDeVisitasService::gerarPara` nunca cria OS com data no passado, por
 * decisão registrada lá: documento datado de um dia em que ninguém foi ao
 * local é pior que a lacuna. Sem saída, o painel só crescia, e painel que só
 * cresce vira ruído que ninguém olha.
 *
 * A saída é justificar. Nada é criado no passado: o que fica gravado é o
 * motivo, quem decidiu e quando. Essa justificativa **é** o documento perante
 * fiscalização, e é por isso que:
 *
 * - o motivo é obrigatório (`ContractVisitJustificationRequest` cuida disso) e
 *   fica gravado por escrito;
 * - uma justificativa cobre uma data. A ação em lote existe para gravar o
 *   mesmo motivo em várias datas escolhidas de uma vez, e continua gravando
 *   uma linha por data. Não existe "justificar o contrato inteiro para
 *   sempre";
 * - só data que é pendência de verdade pode ser justificada. Data futura, data
 *   que já tem OS e data fora do calendário do contrato são recusadas: o que
 *   não é lacuna não precisa de desculpa;
 * - a remoção devolve a pendência ao painel, e o `Auditavel` do model guarda
 *   quem removeu e qual era o motivo apagado.
 *
 * Nenhum método aqui lê o usuário autenticado: o autor chega como parâmetro,
 * vindo do controller.
 */
class JustificativaDeVisitaService
{
    /**
     * Teto de datas por chamada. A ação em lote existe para o contrato com
     * várias lacunas acumuladas, não para varrer o histórico inteiro em um
     * clique. O mesmo teto está em `ContractVisitJustificationRequest`.
     */
    public const MAXIMO_DE_DATAS_POR_CHAMADA = 100;

    public function __construct(
        private readonly GeracaoDeVisitasService $geracaoDeVisitas,
    ) {
    }

    /**
     * Grava o motivo para cada data informada.
     *
     * Tudo ou nada: uma data recusada derruba a chamada inteira, sem gravar
     * nenhuma. A pessoa escolheu aquelas datas na tela; gravar metade e
     * devolver uma lista de erros deixaria o contrato em um estado que ela não
     * pediu e não tem como conferir de imediato.
     *
     * Data que já tinha justificativa tem o motivo atualizado, e não duplicada:
     * o unique (`company_id`, `contract_id`, `expected_date`) garante uma
     * decisão por data no banco, e este método garante o mesmo antes de chegar
     * lá.
     *
     * @param  array<int, string>  $datas  Datas no formato 'Y-m-d'.
     * @return array{success: bool, message: string, data: array{registradas: int, atualizadas: int, recusadas: array<int, string>}}
     */
    public function justificar(Contract $contrato, array $datas, string $motivo, ?User $autor = null): array
    {
        $motivo = trim($motivo);
        $datas = array_values(array_unique($datas));

        if ($datas === []) {
            return $this->recusa('Informe pelo menos uma data prevista para justificar.');
        }

        if (count($datas) > self::MAXIMO_DE_DATAS_POR_CHAMADA) {
            return $this->recusa(sprintf(
                'São no máximo %d datas por vez. Divida em mais de uma ação.',
                self::MAXIMO_DE_DATAS_POR_CHAMADA
            ));
        }

        if ($motivo === '') {
            return $this->recusa('O motivo é obrigatório: é ele que responde à fiscalização por que a visita não aconteceu.');
        }

        $elegiveis = $this->datasElegiveis($contrato);
        $recusadas = array_values(array_filter($datas, fn (string $data) => ! isset($elegiveis[$data])));

        if ($recusadas !== []) {
            return [
                'success' => false,
                'message' => sprintf(
                    'Estas datas não são pendência deste contrato e não podem ser justificadas: %s. '
                    .'Só entra aqui data prevista já vencida e sem Ordem de Serviço. Recarregue a tela: o calendário pode ter mudado.',
                    implode(', ', array_map(
                        fn (string $data) => BusinessDate::paraFusoNegocio($data)?->format('d/m/Y') ?? $data,
                        $recusadas
                    ))
                ),
                'data' => ['registradas' => 0, 'atualizadas' => 0, 'recusadas' => $recusadas],
            ];
        }

        $resumo = DB::transaction(function () use ($contrato, $datas, $motivo, $autor): array {
            $registradas = 0;
            $atualizadas = 0;

            foreach ($datas as $data) {
                $justificativa = ContractVisitJustification::query()
                    ->where('contract_id', $contrato->id)
                    ->whereDate('expected_date', $data)
                    ->first();

                if ($justificativa === null) {
                    ContractVisitJustification::query()->create([
                        'contract_id' => $contrato->id,
                        'expected_date' => $data,
                        'reason' => $motivo,
                        'justified_by' => $autor?->id,
                        'justified_by_name' => $this->nomeDoAutor($autor),
                    ]);

                    $registradas++;

                    continue;
                }

                $justificativa->update([
                    'reason' => $motivo,
                    'justified_by' => $autor?->id,
                    'justified_by_name' => $this->nomeDoAutor($autor),
                ]);

                $atualizadas++;
            }

            return ['registradas' => $registradas, 'atualizadas' => $atualizadas];
        });

        return [
            'success' => true,
            'message' => $this->mensagemDoResultado($resumo['registradas'], $resumo['atualizadas']),
            'data' => [
                'registradas' => $resumo['registradas'],
                'atualizadas' => $resumo['atualizadas'],
                'recusadas' => [],
            ],
        ];
    }

    /**
     * Apaga a justificativa, devolvendo a data ao painel de conformidade.
     *
     * A justificativa precisa ser do contrato da rota. O escopo global por
     * empresa já barra a de outro tenant (o binding devolve 404 antes daqui),
     * e esta checagem é a que falta: dentro da mesma empresa, um id de
     * justificativa de outro contrato não pode ser removido pela rota deste.
     * Resposta 404, e não 403, pela convenção do projeto.
     *
     * @return array{success: bool, message: string, data: array{data: string}}
     *
     * @throws ModelNotFoundException Quando a justificativa não é do contrato informado.
     */
    public function remover(Contract $contrato, ContractVisitJustification $justificativa): array
    {
        if ((int) $justificativa->contract_id !== (int) $contrato->id) {
            throw (new ModelNotFoundException)->setModel(ContractVisitJustification::class, [$justificativa->id]);
        }

        $dia = BusinessDate::diaDe($justificativa->expected_date);
        $diaEmTela = BusinessDate::paraFusoNegocio($justificativa->expected_date)?->format('d/m/Y') ?? (string) $dia;

        $justificativa->delete();

        return [
            'success' => true,
            'message' => "Justificativa de {$diaEmTela} removida. A data volta a aparecer como pendência de conformidade.",
            'data' => ['data' => (string) $dia],
        ];
    }

    /**
     * Justificativas do contrato, da data mais antiga para a mais recente, já
     * formatadas para exibição no fuso do negócio.
     *
     * @return array<int, array{id: int, data: string, data_iso: string, motivo: string, autor: ?string, registrada_em: ?string}>
     */
    public function listarDoContrato(Contract $contrato): array
    {
        return ContractVisitJustification::query()
            ->where('contract_id', $contrato->id)
            ->orderBy('expected_date')
            ->get()
            ->map(fn (ContractVisitJustification $justificativa): array => [
                'id' => $justificativa->id,
                'data' => BusinessDate::paraFusoNegocio($justificativa->expected_date)?->format('d/m/Y') ?? '',
                'data_iso' => (string) BusinessDate::diaDe($justificativa->expected_date),
                'motivo' => (string) $justificativa->reason,
                'autor' => $justificativa->justified_by_name,
                'registrada_em' => BusinessDate::paraFusoNegocio($justificativa->created_at)?->format('d/m/Y H:i'),
            ])
            ->all();
    }

    /**
     * Datas ('Y-m-d') que este contrato aceita justificar, indexadas para
     * checagem em O(1).
     *
     * São as pendências de conformidade em aberto mais as datas que já têm
     * justificativa. As já justificadas entram porque `justificar` também
     * serve para corrigir o motivo de uma decisão anterior, e elas saíram da
     * lista de pendências justamente por já terem sido justificadas.
     *
     * @return array<string, true>
     */
    private function datasElegiveis(Contract $contrato): array
    {
        $elegiveis = [];

        foreach ($this->geracaoDeVisitas->pendenciasDeConformidade($contrato) as $pendencia) {
            $elegiveis[$pendencia['data']] = true;
        }

        $jaJustificadas = ContractVisitJustification::query()
            ->where('contract_id', $contrato->id)
            ->pluck('expected_date');

        foreach ($jaJustificadas as $data) {
            $dia = BusinessDate::diaDe($data);

            if ($dia !== null) {
                $elegiveis[$dia] = true;
            }
        }

        return $elegiveis;
    }

    /**
     * Nome congelado de quem justificou, no mesmo padrão de
     * `audit_logs.autor_nome`: sem autor identificado (rotina, comando), fica
     * 'Sistema'.
     */
    private function nomeDoAutor(?User $autor): string
    {
        if ($autor === null) {
            return 'Sistema';
        }

        $nome = trim((string) ($autor->name ?? ''));

        return $nome !== '' ? $nome : 'Usuário #'.$autor->getKey();
    }

    /**
     * @return array{success: bool, message: string, data: array{registradas: int, atualizadas: int, recusadas: array<int, string>}}
     */
    private function recusa(string $mensagem): array
    {
        return [
            'success' => false,
            'message' => $mensagem,
            'data' => ['registradas' => 0, 'atualizadas' => 0, 'recusadas' => []],
        ];
    }

    private function mensagemDoResultado(int $registradas, int $atualizadas): string
    {
        $partes = [];

        if ($registradas > 0) {
            $partes[] = sprintf('%d data(s) justificada(s)', $registradas);
        }

        if ($atualizadas > 0) {
            $partes[] = sprintf('%d motivo(s) atualizado(s)', $atualizadas);
        }

        if ($partes === []) {
            return 'Nada a registrar.';
        }

        return implode(' e ', $partes).'. A(s) data(s) sai(em) do painel de pendências com o motivo gravado.';
    }
}
