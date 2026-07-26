<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;

/**
 * Monta o painel de contratos periódicos vigentes com pendência de
 * conformidade (Task 9.7).
 *
 * Contrato periódico sem visita gerada no período é pendência de
 * conformidade com a RDC 622/2022 (que trata de visitas periódicas de
 * inspeção) e não pode passar em silêncio. Este Service é a fonte única do
 * painel que garante isso.
 *
 * Três motivos, avaliados independentemente por contrato (um mesmo contrato
 * pode acumular mais de um):
 *
 * 1. Periodicidade não preenchida: pendência do backfill da Task 9.2, sem a
 *    qual não existe calendário a calcular.
 * 2. Sem visita gerada no período configurado: reaproveita
 *    `GeracaoDeVisitasService::pendenciasDeConformidade` (Task 9.4), a fonte
 *    oficial de "data prevista no passado sem OS correspondente" — não
 *    recalcula esse cálculo por conta própria.
 *    `config('contratos.dias_de_alerta_sem_visita')` dá a folga: só uma data
 *    prevista com mais desse número de dias no passado sem OS vira
 *    pendência, para a rotina diária (Task 9.6) ter chance de cobrir o que
 *    venceu ontem antes do painel soar o alarme.
 *    Data já justificada não aparece aqui, e o filtro fica na fonte, não
 *    neste painel: o motivo está escrito em
 *    `GeracaoDeVisitasService::pendenciasDeConformidade`.
 * 3. Visita vencida não executada: existe OS gerada pelo contrato, mas com
 *    data passada e status ainda em aberto ('scheduled' ou 'pending'). É
 *    falha de operação (a visita foi agendada e ninguém a executou),
 *    diferente do motivo 2 (visita que nunca chegou a ser agendada).
 *
 * Serviço somente leitura: não cria, move nem cancela nada.
 */
class PendenciasDeContratoService
{
    /**
     * Tipo de serviço que entra no painel. Contrato pontual não tem
     * calendário de visitas e não é avaliado aqui.
     */
    private const TIPO_PERIODICO = 'periodico';

    /**
     * Marca de OS gerada pelo contrato, gravada por
     * `GeracaoDeVisitasService`. OS criada à mão tem `origem` nula e não
     * conta para o motivo 3.
     */
    private const ORIGEM_CONTRATO = 'contrato';

    /**
     * Únicos status que contam como "ainda em aberto" para o motivo 3. Mesmo
     * conjunto de `ManutencaoDeVisitasService::STATUS_REPROGRAMAVEIS`
     * (privado lá, por isso repetido aqui): visita executada ou cancelada já
     * teve uma decisão tomada e não é pendência.
     */
    private const STATUS_EM_ABERTO = ['scheduled', 'pending'];

    public function __construct(
        private readonly GeracaoDeVisitasService $geracaoDeVisitas,
    ) {
    }

    /**
     * Contratos periódicos vigentes com pelo menos um dos três motivos, já
     * formatados para exibição (datas em pt-BR, fuso do negócio).
     *
     * "Vigente" aqui é o mesmo critério de `GeracaoDeVisitasService::gerarDoPeriodo`:
     * `end_date` nulo ou maior ou igual a hoje.
     *
     * @return array<int, array{contrato_id: int, contract_number: ?string, cliente: ?string, endereco: ?string, start_date: ?string, end_date: ?string, motivos: array<int, string>, datas_sem_visita: array<int, array{data: string, data_br: string, numero: int}>}>
     */
    public function listar(): array
    {
        $diasDeAlerta = (int) config('contratos.dias_de_alerta_sem_visita');
        $hoje = BusinessDate::hoje();

        $contratosVigentes = Contract::query()
            ->with('address.client')
            ->where('service_type', self::TIPO_PERIODICO)
            ->where(function ($query) use ($hoje) {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', $hoje->toDateString());
            })
            ->orderBy('id')
            ->get();

        $pendencias = [];

        foreach ($contratosVigentes as $contrato) {
            $avaliacao = $this->avaliarContrato($contrato, $diasDeAlerta, $hoje);

            if ($avaliacao['motivos'] === []) {
                continue;
            }

            $pendencias[] = $this->formatarLinha($contrato, $avaliacao['motivos'], $avaliacao['datas_sem_visita']);
        }

        return $pendencias;
    }

    /**
     * Motivos do contrato e, junto deles, as datas exatas que sustentam o
     * motivo 2.
     *
     * As datas vão para a linha do painel porque é nelas que a ação de
     * justificar age: sem elas a tela precisaria de uma segunda requisição por
     * contrato só para saber o que oferecer, ou pior, recalcularia o
     * calendário por conta própria.
     *
     * @return array{motivos: array<int, string>, datas_sem_visita: array<int, array{numero: int, data: string}>}
     */
    private function avaliarContrato(Contract $contrato, int $diasDeAlerta, CarbonImmutable $hoje): array
    {
        $motivos = [];

        if (is_null($contrato->visit_frequency_valor) || is_null($contrato->visit_frequency_unidade)) {
            $motivos[] = 'Periodicidade não preenchida: o valor gravado em visit_frequency não foi reconhecido pelo backfill e precisa de resolução manual na tela do contrato.';
        }

        $corte = $hoje->subDays($diasDeAlerta)->toDateString();
        $semVisita = $this->geracaoDeVisitas->pendenciasDeConformidade($contrato, $corte);

        if ($semVisita !== []) {
            $motivos[] = sprintf(
                'Sem visita gerada no período configurado: %d data(s) prevista(s) sem Ordem de Serviço correspondente, vencida(s) há mais de %d dia(s).',
                count($semVisita),
                $diasDeAlerta
            );
        }

        $vencidas = $this->contarVisitasVencidasNaoExecutadas($contrato, $hoje);

        if ($vencidas > 0) {
            $motivos[] = sprintf(
                'Visita vencida não executada: %d Ordem(ns) de Serviço com data passada e status ainda em aberto.',
                $vencidas
            );
        }

        return ['motivos' => $motivos, 'datas_sem_visita' => $semVisita];
    }

    private function contarVisitasVencidasNaoExecutadas(Contract $contrato, CarbonImmutable $hoje): int
    {
        return WorkOrder::query()
            ->where('contract_id', $contrato->id)
            ->where('origem', self::ORIGEM_CONTRATO)
            ->whereIn('status', self::STATUS_EM_ABERTO)
            ->where('scheduled_date', '<', $hoje->toDateString())
            ->count();
    }

    /**
     * @param  array<int, string>  $motivos
     * @param  array<int, array{numero: int, data: string}>  $datasSemVisita
     * @return array{contrato_id: int, contract_number: ?string, cliente: ?string, endereco: ?string, start_date: ?string, end_date: ?string, motivos: array<int, string>, datas_sem_visita: array<int, array{data: string, data_br: string, numero: int}>}
     */
    private function formatarLinha(Contract $contrato, array $motivos, array $datasSemVisita): array
    {
        return [
            'contrato_id' => $contrato->id,
            'contract_number' => $contrato->contract_number,
            'cliente' => $contrato->address?->client?->name,
            'endereco' => $contrato->address?->nickname,
            'start_date' => BusinessDate::paraFusoNegocio($contrato->start_date)?->format('d/m/Y'),
            'end_date' => BusinessDate::paraFusoNegocio($contrato->end_date)?->format('d/m/Y'),
            'motivos' => $motivos,
            // A data crua ('Y-m-d') é o que o endpoint de justificativa
            // recebe; a formatada em pt-BR é o que a tela mostra. As duas vêm
            // do backend, no fuso do negócio, para o frontend nunca precisar
            // converter data por conta própria.
            'datas_sem_visita' => array_map(
                fn (array $visita): array => [
                    'data' => $visita['data'],
                    'data_br' => BusinessDate::paraFusoNegocio($visita['data'])?->format('d/m/Y') ?? $visita['data'],
                    'numero' => $visita['numero'],
                ],
                $datasSemVisita
            ),
        ];
    }
}
