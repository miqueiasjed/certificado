<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mantém o calendário de visitas já materializado em Ordens de Serviço
 * alinhado com o contrato: reprograma quando a periodicidade ou a vigência
 * muda e cancela em cascata quando o contrato é encerrado.
 *
 * Regra que domina todo o arquivo: **visita executada nunca é tocada**. Nada
 * com `status` em 'completed' ou 'in_progress' é movido, cancelado ou
 * renumerado, por nenhum caminho. É histórico, e documento emitido tem valor
 * perante fiscalização. A garantia é dupla:
 *
 * 1. A consulta que monta o conjunto de trabalho (`visitasReprogramaveis`)
 *    filtra por `status` em 'scheduled' ou 'pending'.
 * 2. Todo método que grava passa antes por `garantirQuePodeSerTocada`, que
 *    lança exceção se receber algo fora desse conjunto. Como tudo roda em
 *    transação, um erro de programação aborta a operação inteira em vez de
 *    corromper o histórico em silêncio.
 *
 * Segunda regra: só OS com `origem = 'contrato'` entra na varredura. OS que
 * alguém criou à mão nunca é movida nem cancelada, mesmo apontando para o
 * mesmo contrato.
 *
 * Terceira regra: visita passada não executada também não é movida nem
 * cancelada pela reprogramação. Ela é pendência real de operação, e sumir com
 * ela esconderia a falha em vez de apontá-la (é o que o painel da Task 9.7
 * lista).
 */
class ManutencaoDeVisitasService
{
    /**
     * Motivo gravado nas visitas que deixaram de existir no calendário novo.
     */
    public const MOTIVO_REPROGRAMACAO = 'Reprogramação do calendário de visitas do contrato';

    /**
     * Motivo padrão do cancelamento em cascata ao encerrar o contrato.
     */
    public const MOTIVO_ENCERRAMENTO = 'Encerramento do contrato';

    /**
     * Marca de OS gerada pelo contrato, gravada por
     * `GeracaoDeVisitasService`. OS criada à mão tem `origem` nula.
     */
    private const ORIGEM_CONTRATO = 'contrato';

    /**
     * Visita executada ou em execução: histórico intocável.
     */
    private const STATUS_EXECUTADOS = ['completed', 'in_progress'];

    /**
     * Únicos status que a manutenção pode alterar. 'cancelled' e 'on_hold'
     * ficam de fora de propósito: já foram decididos por alguém, e refazer
     * essa decisão automaticamente não é papel desta rotina.
     */
    private const STATUS_REPROGRAMAVEIS = ['scheduled', 'pending'];

    private const STATUS_CANCELADA = 'cancelled';

    /**
     * Mesma janela de 3 meses decidida no Plano 9 para a geração. Repetida
     * aqui em vez de lida de `GeracaoDeVisitasService` porque lá a constante é
     * privada, e a reprogramação precisa saber até onde o calendário novo vale
     * antes de decidir o que cancelar.
     */
    private const MESES_JANELA_PADRAO = 3;

    public function __construct(
        private readonly GeracaoDeVisitasService $geracao,
    ) {
    }

    /**
     * Realinha as visitas futuras não executadas com o calendário recalculado
     * do contrato. Chamado depois de mudar periodicidade, `start_date` ou
     * `end_date`.
     *
     * O que acontece, na ordem:
     *
     * 1. Visita futura que cai em data ainda prevista continua onde está e só
     *    tem o `visita_numero` corrigido, se a renumeração mudou.
     * 2. Visita futura que não existe mais no calendário é aproveitada para a
     *    primeira data prevista ainda sem OS, em ordem cronológica, movendo
     *    `scheduled_date` e `visita_numero`. Aproveitar em vez de cancelar e
     *    recriar preserva o que já foi anotado na OS (observações, técnico,
     *    valores).
     * 3. O que sobrou sem destino é cancelado com o motivo gravado.
     * 4. As datas previstas que continuaram sem OS são geradas por
     *    `GeracaoDeVisitasService`, o mesmo caminho da rotina agendada, sem
     *    duplicar a regra de criação.
     *
     * Tudo em uma transação: reprogramação pela metade deixa o contrato com
     * calendário inconsistente, que é pior que não ter reprogramado.
     *
     * A numeração usada nos passos 1, 2 e 4 é a de
     * `GeracaoDeVisitasService::calendarioNumerado`, que salta os números
     * presos em visita executada. Sem isso, a soma de duas regras corretas
     * (visita executada nunca é renumerada, e o calendário novo recomeça em 1
     * no `start_date`) devolvia o mesmo número para dois documentos do mesmo
     * contrato.
     *
     * @return array{canceladas: int, movidas: int, criadas: int, renumeradas: int, executadas_preservadas: int, passadas_preservadas: int}
     */
    public function reprogramar(Contract $contrato): array
    {
        return DB::transaction(function () use ($contrato): array {
            $hoje = BusinessDate::hoje();
            $ate = $this->limiteDaReprogramacao($contrato, $hoje);

            $previstas = $this->datasPrevistasFuturas($contrato, $hoje, $ate);
            $datasOcupadas = $this->datasComOrdemDeServico($contrato);

            $renumeradas = 0;
            $semDestino = [];

            foreach ($this->visitasReprogramaveis($contrato, $hoje) as $visita) {
                $dia = BusinessDate::diaDe($visita->scheduled_date);

                if (array_key_exists($dia, $previstas)) {
                    $renumeradas += $this->renumerar($visita, $contrato, $previstas[$dia]) ? 1 : 0;

                    continue;
                }

                $semDestino[] = $visita;
            }

            // Datas previstas que ainda não têm nenhuma OS deste contrato, em
            // ordem cronológica. Data ocupada por OS criada à mão também conta
            // como ocupada: a visita seria duplicata do que já está na agenda.
            $datasLivres = array_values(array_diff(array_keys($previstas), $datasOcupadas));

            $movidas = 0;

            foreach ($semDestino as $posicao => $visita) {
                if (! isset($datasLivres[$posicao])) {
                    break;
                }

                $destino = $datasLivres[$posicao];
                $this->mover($visita, $contrato, $destino, $previstas[$destino]);
                $movidas++;
            }

            $canceladas = 0;

            foreach (array_slice($semDestino, $movidas) as $visita) {
                $this->cancelar($visita, self::MOTIVO_REPROGRAMACAO);
                $canceladas++;
            }

            $criadas = count($this->geracao->gerarPara($contrato, $ate));

            return [
                'canceladas' => $canceladas,
                'movidas' => $movidas,
                'criadas' => $criadas,
                'renumeradas' => $renumeradas,
                'executadas_preservadas' => $this->contarExecutadas($contrato),
                'passadas_preservadas' => $this->contarPassadasNaoExecutadas($contrato, $hoje),
            ];
        });
    }

    /**
     * Cancela toda visita futura não executada do contrato, gravando o motivo.
     * É o caminho do encerramento ou cancelamento do contrato.
     *
     * Visita passada não executada continua como está: ela é pendência real de
     * operação e some do relatório de conformidade se for cancelada aqui.
     *
     * @return array{canceladas: int, executadas_preservadas: int, passadas_preservadas: int}
     */
    public function cancelarFuturas(Contract $contrato, string $motivo = self::MOTIVO_ENCERRAMENTO): array
    {
        return DB::transaction(function () use ($contrato, $motivo): array {
            $hoje = BusinessDate::hoje();
            $futuras = $this->visitasReprogramaveis($contrato, $hoje);

            foreach ($futuras as $visita) {
                $this->cancelar($visita, $motivo);
            }

            return [
                'canceladas' => $futuras->count(),
                'executadas_preservadas' => $this->contarExecutadas($contrato),
                'passadas_preservadas' => $this->contarPassadasNaoExecutadas($contrato, $hoje),
            ];
        });
    }

    /**
     * Visitas do contrato que a manutenção pode alterar: geradas pelo
     * contrato, ainda não executadas e com data de hoje em diante.
     *
     * Este é o primeiro dos dois filtros que protegem o histórico. A data é
     * comparada como texto 'Y-m-d' contra a coluna `date`, sem instante e sem
     * conversão de fuso, que é o que produziria erro de um dia na virada.
     *
     * @return Collection<int, WorkOrder>
     */
    private function visitasReprogramaveis(Contract $contrato, CarbonImmutable $hoje): Collection
    {
        return WorkOrder::query()
            ->where('contract_id', $contrato->id)
            ->where('origem', self::ORIGEM_CONTRATO)
            ->whereIn('status', self::STATUS_REPROGRAMAVEIS)
            ->where('scheduled_date', '>=', $hoje->toDateString())
            ->orderBy('scheduled_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Datas previstas do calendário novo que são de hoje em diante, indexadas
     * pela data ('Y-m-d') e apontando para o número da visita.
     *
     * O passado do calendário é descartado aqui de propósito: a reprogramação
     * não cria, não move e não cancela nada com data anterior a hoje.
     *
     * O número vem de `GeracaoDeVisitasService::calendarioNumerado`, e não do
     * calendário cru, porque a renumeração precisa saltar os números presos
     * em visita executada. O calendário cru recomeça em 1 no `start_date` a
     * cada recálculo, e usá-lo aqui é o que fazia a reprogramação reemitir um
     * número que já estava impresso em um documento entregue.
     *
     * @return array<string, int>
     */
    private function datasPrevistasFuturas(Contract $contrato, CarbonImmutable $hoje, string $ate): array
    {
        $previstas = [];

        foreach ($this->geracao->calendarioNumerado($contrato, $ate) as $visita) {
            if (BusinessDate::paraFusoNegocio($visita['data'])->lessThan($hoje)) {
                continue;
            }

            $previstas[$visita['data']] = $visita['numero'];
        }

        return $previstas;
    }

    /**
     * Até onde o calendário novo é comparado com o que está gravado.
     *
     * A janela padrão são os mesmos 3 meses da geração, mas se alguma visita
     * já foi gerada além disso (geração sob demanda de um contrato inteiro,
     * por exemplo), o limite acompanha a última visita gerada. Sem isso, tudo
     * que estivesse além da janela seria lido como "não existe mais no
     * calendário" e acabaria cancelado por engano.
     */
    private function limiteDaReprogramacao(Contract $contrato, CarbonImmutable $hoje): string
    {
        $janela = $hoje->addMonthsNoOverflow(self::MESES_JANELA_PADRAO);

        $ultimaGerada = BusinessDate::paraFusoNegocio(
            WorkOrder::query()
                ->where('contract_id', $contrato->id)
                ->where('origem', self::ORIGEM_CONTRATO)
                ->max('scheduled_date')
        );

        if ($ultimaGerada !== null && $ultimaGerada->greaterThan($janela)) {
            return $ultimaGerada->toDateString();
        }

        return $janela->toDateString();
    }

    /**
     * Datas que já têm OS deste contrato, em 'Y-m-d'. Inclui visita executada,
     * cancelada e OS criada à mão vinculada ao contrato: são todas datas onde
     * uma nova visita seria duplicata na agenda do cliente.
     *
     * Mesmo critério de idempotência de `GeracaoDeVisitasService`: o par
     * [contract_id, scheduled_date].
     *
     * @return array<int, string>
     */
    private function datasComOrdemDeServico(Contract $contrato): array
    {
        return WorkOrder::query()
            ->where('contract_id', $contrato->id)
            ->pluck('scheduled_date')
            ->map(fn ($data) => BusinessDate::diaDe($data))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Move a visita para a data prevista informada, ajustando também o número
     * e a descrição.
     */
    private function mover(WorkOrder $visita, Contract $contrato, string $novaData, int $novoNumero): void
    {
        $this->garantirQuePodeSerTocada($visita);

        $visita->scheduled_date = $novaData;
        $visita->visita_numero = $novoNumero;
        $this->sincronizarDescricao($visita, $contrato, $novoNumero);
        $visita->save();
    }

    /**
     * Corrige o número da visita que continua na mesma data. Devolve
     * verdadeiro só quando havia mesmo o que corrigir, para o resumo não
     * contar linha que não mudou.
     */
    private function renumerar(WorkOrder $visita, Contract $contrato, int $novoNumero): bool
    {
        if ((int) $visita->visita_numero === $novoNumero) {
            return false;
        }

        $this->garantirQuePodeSerTocada($visita);

        $visita->visita_numero = $novoNumero;
        $this->sincronizarDescricao($visita, $contrato, $novoNumero);
        $visita->save();

        return true;
    }

    /**
     * Cancela a visita registrando o motivo em `completion_notes`, sem apagar
     * o que já estava anotado lá. Visita que some sem explicação vira
     * discussão com o cliente.
     */
    private function cancelar(WorkOrder $visita, string $motivo): void
    {
        $this->garantirQuePodeSerTocada($visita);

        $visita->status = self::STATUS_CANCELADA;
        $visita->completion_notes = $this->anotarMotivo($visita->completion_notes, $motivo);
        $visita->save();
    }

    /**
     * Segundo filtro de proteção do histórico, e o mais importante: nenhuma
     * gravação acontece sem passar por aqui.
     *
     * Falha alto de propósito. Chegar aqui com visita executada significa que
     * a consulta que monta o conjunto de trabalho tem defeito, e nesse caso
     * abortar a transação inteira é melhor que gravar por cima de um documento
     * que já foi entregue ao cliente.
     */
    private function garantirQuePodeSerTocada(WorkOrder $visita): void
    {
        if ($visita->origem !== self::ORIGEM_CONTRATO) {
            throw new RuntimeException(
                "OS #{$visita->id} não foi gerada por contrato e não pode ser reprogramada nem cancelada em cascata."
            );
        }

        if (in_array($visita->status, self::STATUS_EXECUTADOS, true)) {
            throw new RuntimeException(
                "OS #{$visita->id} já foi executada (status '{$visita->status}') e é histórico: não pode ser movida, renumerada nem cancelada."
            );
        }

        if (! in_array($visita->status, self::STATUS_REPROGRAMAVEIS, true)) {
            throw new RuntimeException(
                "OS #{$visita->id} está com status '{$visita->status}' e está fora do alcance da manutenção de visitas."
            );
        }
    }

    /**
     * Acrescenta o motivo do cancelamento ao histórico de anotações da OS, com
     * data e hora no fuso do negócio. Nunca sobrescreve o que já existia.
     */
    private function anotarMotivo(?string $anotacoes, string $motivo): string
    {
        $registro = sprintf(
            '[%s] Visita cancelada automaticamente. Motivo: %s',
            BusinessDate::agora()->format('d/m/Y H:i'),
            $motivo
        );

        $anteriores = trim((string) $anotacoes);

        return $anteriores === '' ? $registro : $anteriores . PHP_EOL . $registro;
    }

    /**
     * Mantém a descrição coerente com o novo número da visita, porque o texto
     * da OS é impresso no documento entregue ao cliente e "Visita 5" em uma OS
     * renumerada para 3 é erro em documento.
     *
     * Só reescreve o texto no formato que a geração automática produz. Se
     * alguém editou a descrição à mão, ela é preservada: o número correto
     * continua em `visita_numero`.
     *
     * O texto em si vem de `GeracaoDeVisitasService::montarDescricao`, o mesmo
     * método que a geração usa: formato e regra do total impresso têm uma
     * implementação só.
     */
    private function sincronizarDescricao(WorkOrder $visita, Contract $contrato, int $numero): void
    {
        $atual = (string) $visita->description;

        if ($atual !== '' && preg_match('/^Visita \d+( de \d+)? do contrato /u', $atual) !== 1) {
            return;
        }

        $visita->description = $this->geracao->montarDescricao($contrato, $numero);
    }

    /**
     * Quantas visitas do contrato são histórico intocável. Vai no resumo para
     * que quem chamou consiga conferir, contra o banco, que elas continuam lá.
     */
    private function contarExecutadas(Contract $contrato): int
    {
        return WorkOrder::query()
            ->where('contract_id', $contrato->id)
            ->where('origem', self::ORIGEM_CONTRATO)
            ->whereIn('status', self::STATUS_EXECUTADOS)
            ->count();
    }

    /**
     * Visitas vencidas e não executadas, que a manutenção deixa exatamente
     * como estão: são pendência de operação, não sobra de calendário.
     */
    private function contarPassadasNaoExecutadas(Contract $contrato, CarbonImmutable $hoje): int
    {
        return WorkOrder::query()
            ->where('contract_id', $contrato->id)
            ->where('origem', self::ORIGEM_CONTRATO)
            ->whereIn('status', self::STATUS_REPROGRAMAVEIS)
            ->where('scheduled_date', '<', $hoje->toDateString())
            ->count();
    }
}
