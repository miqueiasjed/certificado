<?php

namespace App\Services\Monitoring;

use App\Models\Address;
use App\Models\PestSighting;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Ocorrência por espécie de praga de um endereço (Plano 21, Task 21.3):
 * evolução entre períodos e distribuição por área.
 *
 * Fonte de dado: `PestSighting.pest_type`, não `WorkOrderDeviceEvent.pest_found`
 * -----------------------------------------------------------------------
 * `pest_found` (usado pelo `TendenciaService`/`PontosCriticosService` como
 * indicador booleano de captura) é texto livre digitado pelo técnico, sem
 * vocabulário fixo: não dá para agrupar por espécie de forma confiável.
 * `pest_sightings.pest_type` é o campo estruturado certo, um enum fechado
 * (`rats`, `mice`, `cockroaches`, `ants`, `termites`, `flies`, `fleas`,
 * `ticks`, `scorpions`, `spiders`, `bees`, `wasps`, `other`, ver a migration
 * `database/migrations/2025_08_27_110850_create_pest_sightings_table.php`),
 * obrigatório em toda linha. `PestSightingItem` existe para o detalhamento
 * por item dentro de um mesmo avistamento (espécie e quantidade por item) e
 * não é usado aqui: usar os itens contaria potencialmente mais de uma
 * espécie por avistamento e misturaria "quantidade estimada de indivíduos"
 * com "número de ocorrências registradas", que são métricas diferentes. A
 * unidade de ocorrência deste service é a linha de `pest_sightings`, o mesmo
 * jeito que "captura" no `TendenciaService` é uma linha de evento, não uma
 * quantidade.
 *
 * `sighting_date` é instante, não dia puro
 * -----------------------------------------------------------------------
 * Ao contrário de `work_order_device_events.event_date` (`date`, sem
 * conversão de fuso), `pest_sightings.sighting_date` é `datetime`: grava o
 * instante do celular em UTC (`AplicadorDeAvistamento::instanteDoCelular`).
 * Filtrar esse campo com a data pura do período confundiria o dia em
 * Brasília com o dia em UTC perto da virada. Este service converte os dois
 * limites do intervalo (início do dia e fim do dia, no fuso do negócio) para
 * UTC antes do `WHERE ... BETWEEN`, para o filtro continuar sendo uma
 * comparação de intervalo simples (usa o índice de `sighting_date`) e ainda
 * assim respeitar o dia correto no fuso do negócio.
 *
 * Vocabulário fixo, mesmo quando a espécie não ocorreu no período
 * -----------------------------------------------------------------------
 * `evolucao_por_especie` sempre traz as 13 espécies do enum, com `de`/`para`
 * zerados quando não houve nenhum avistamento: omitir uma espécie sem
 * ocorrência quebraria a categoria fixa que o gráfico de comparação precisa
 * manter entre dois relatórios diferentes.
 *
 * "Área" como sinônimo de endereço
 * -----------------------------------------------------------------------
 * O service é escopado a um único `Address`, no mesmo padrão de
 * `PontosCriticosService::ranking` e `MapaDeCalorService`. "Cômodo" não é
 * uma unidade de área disponível aqui pelo mesmo motivo documentado em
 * `MapaDeCalorService::porComodo` (sem FK dispositivo/avistamento -> cômodo).
 * "Endereço", ao contrário, É a unidade estruturada que `pest_sightings.address_id`
 * suporta (mesmo campo que `ConsolidadorDePeriodo::tendenciaDoEndereco` usa
 * para desenhar a tendência por endereço quando o relatório cobre o cliente
 * inteiro). Por isso `distribuicao_por_area` devolve, para este endereço, o
 * total e o detalhamento por espécie: uma lista de um item só quando chamado
 * para um endereço isolado, pronta para quem monta o relatório do cliente
 * inteiro (Task 21.5, que já itera endereço a endereço em `ConsolidadorDePeriodo::enderecosDoRetrato`)
 * concatenar o item de cada endereço na mesma lista, exatamente como já é
 * feito hoje com `tendencia`.
 *
 * Tendência: comparação com o período anterior de mesma duração
 * -----------------------------------------------------------------------
 * Mesmo cálculo do `PontosCriticosService`: o período anterior tem a mesma
 * duração em dias do período pedido, terminando no dia anterior ao início.
 *
 * Variação a partir de zero
 * -----------------------------------------------------------------------
 * Mesmo formato do `TendenciaService::calcularVariacao`: quando o total do
 * período anterior é zero, `percentual` sai `null` (divisão por zero não é
 * um número) e `a_partir_de_zero` indica se houve crescimento de 0 para N,
 * sem que este service escreva frase alguma.
 *
 * Nenhuma conclusão automática
 * -----------------------------------------------------------------------
 * O service entrega contagem por espécie e variação numérica, nunca leitura
 * interpretativa. Isso é o Plano 25.
 *
 * Performance, índice e isolamento por empresa
 * -----------------------------------------------------------------------
 * As duas consultas (período atual e anterior) filtram `sighting_date` por
 * `WHERE ... BETWEEN` (índice `pest_sightings.sighting_date`) e `address_id`
 * (parte do índice composto `[address_id, work_order_id]`), agrupando por
 * `pest_type` em SQL. Nenhum `join` em SQL cru é feito aqui (diferente de
 * `TendenciaService`/`PontosCriticosService`/`MapaDeCalorService`, que juntam
 * com `devices`): `PestSighting` usa `BelongsToCompany`, então a consulta por
 * Eloquent já nasce filtrada pela empresa corrente sem precisar de filtro
 * manual, mesmo raciocínio já registrado em `ConsolidadorDePeriodo`.
 */
class OcorrenciaPorEspecieService
{
    /**
     * Vocabulário fechado de `pest_sightings.pest_type`, na mesma ordem do
     * enum da migration. Usado só para garantir que toda espécie apareça na
     * evolução mesmo com zero ocorrência; não é rótulo de apresentação (a
     * tradução para português já existe em `PestSighting::getPestTypeTextAttribute`,
     * e formatar para exibição não é responsabilidade deste service).
     *
     * @var list<string>
     */
    private const ESPECIES = [
        'rats', 'mice', 'cockroaches', 'ants', 'termites', 'flies',
        'fleas', 'ticks', 'scorpions', 'spiders', 'bees', 'wasps', 'other',
    ];

    /**
     * @return array{
     *     address_id: int,
     *     periodo: array{de: string, ate: string},
     *     periodo_anterior: array{de: string, ate: string},
     *     evolucao_por_especie: list<array{pest_type: string, de: int, para: int, percentual: float|null, a_partir_de_zero: bool}>,
     *     distribuicao_por_area: list<array{
     *         address_id: int,
     *         endereco: string,
     *         total: int,
     *         por_especie: list<array{pest_type: string, quantidade: int}>
     *     }>
     * }
     */
    public function porPeriodo(Address $endereco, string $de, string $ate): array
    {
        [$inicio, $fim] = $this->validarIntervalo($de, $ate);
        [$inicioAnterior, $fimAnterior] = $this->periodoAnteriorDeMesmaDuracao($inicio, $fim);

        $totaisAtuais = $this->totaisPorEspecie($endereco, $inicio, $fim);
        $totaisAnteriores = $this->totaisPorEspecie($endereco, $inicioAnterior, $fimAnterior);

        $evolucao = array_map(
            fn (string $especie): array => [
                'pest_type' => $especie,
                ...$this->calcularVariacao($totaisAnteriores->get($especie, 0), $totaisAtuais->get($especie, 0)),
            ],
            self::ESPECIES
        );

        $totalDoEndereco = $totaisAtuais->sum();

        $distribuicaoPorArea = [[
            'address_id' => $endereco->id,
            'endereco' => (string) ($endereco->nickname ?: $endereco->short_address),
            'total' => $totalDoEndereco,
            'por_especie' => array_map(
                fn (string $especie): array => [
                    'pest_type' => $especie,
                    'quantidade' => $totaisAtuais->get($especie, 0),
                ],
                self::ESPECIES
            ),
        ]];

        return [
            'address_id' => $endereco->id,
            'periodo' => ['de' => $inicio->toDateString(), 'ate' => $fim->toDateString()],
            'periodo_anterior' => ['de' => $inicioAnterior->toDateString(), 'ate' => $fimAnterior->toDateString()],
            'evolucao_por_especie' => $evolucao,
            'distribuicao_por_area' => $distribuicaoPorArea,
        ];
    }

    /**
     * Quantidade de avistamentos por espécie dentro de `[$inicio, $fim]`
     * (dias no fuso do negócio, convertidos para UTC antes da consulta),
     * somada em SQL. Espécie sem nenhum avistamento não aparece na coleção;
     * quem chama trata a ausência como zero.
     *
     * @return Collection<string, int>
     */
    private function totaisPorEspecie(Address $endereco, CarbonImmutable $inicio, CarbonImmutable $fim): Collection
    {
        [$inicioUtc, $fimUtc] = $this->limitesUtcDoIntervalo($inicio, $fim);

        return PestSighting::query()
            ->where('address_id', $endereco->id)
            ->whereBetween('sighting_date', [$inicioUtc, $fimUtc])
            ->groupBy('pest_type')
            ->selectRaw('pest_type, COUNT(*) as quantidade')
            ->pluck('quantidade', 'pest_type')
            ->map(static fn (mixed $valor): int => (int) $valor);
    }

    /**
     * Variação entre dois totais, no mesmo formato de
     * `TendenciaService::calcularVariacao`: total inicial zero não produz
     * percentual (divisão por zero), e `a_partir_de_zero` sinaliza quando
     * houve crescimento de 0 para N.
     *
     * @return array{de: int, para: int, percentual: float|null, a_partir_de_zero: bool}
     */
    private function calcularVariacao(int $totalPeriodoA, int $totalPeriodoB): array
    {
        if ($totalPeriodoA === 0) {
            return [
                'de' => $totalPeriodoA,
                'para' => $totalPeriodoB,
                'percentual' => $totalPeriodoB === 0 ? 0.0 : null,
                'a_partir_de_zero' => $totalPeriodoB > 0,
            ];
        }

        return [
            'de' => $totalPeriodoA,
            'para' => $totalPeriodoB,
            'percentual' => round((($totalPeriodoB - $totalPeriodoA) / $totalPeriodoA) * 100, 2),
            'a_partir_de_zero' => false,
        ];
    }

    /**
     * Início e fim do dia (fuso do negócio) convertidos para UTC, para
     * comparar contra `sighting_date` (instante, gravado em UTC) sem perder
     * o índice de intervalo simples.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function limitesUtcDoIntervalo(CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        return [
            $inicio->startOfDay()->setTimezone('UTC'),
            $fim->endOfDay()->setTimezone('UTC'),
        ];
    }

    /**
     * O período anterior de mesma duração, imediatamente antes de `$inicio`.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function periodoAnteriorDeMesmaDuracao(CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $duracaoEmDias = $inicio->diffInDays($fim) + 1;

        $fimAnterior = $inicio->subDay();
        $inicioAnterior = $fimAnterior->subDays($duracaoEmDias - 1);

        return [$inicioAnterior, $fimAnterior];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function validarIntervalo(string $de, string $ate): array
    {
        $inicio = BusinessDate::paraFusoNegocio($de)?->startOfDay();
        $fim = BusinessDate::paraFusoNegocio($ate)?->startOfDay();

        if ($inicio === null || $fim === null) {
            throw new InvalidArgumentException('Informe as duas datas do intervalo, "de" e "até".');
        }

        if ($fim->lessThan($inicio)) {
            throw new InvalidArgumentException('A data final não pode ser anterior à data inicial.');
        }

        return [$inicio, $fim];
    }
}
