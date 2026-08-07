<?php

namespace App\Services\Monitoring;

use App\Models\Address;
use App\Models\Client;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Monta o retrato consolidado de um período, o array que vira
 * `monitoring_reports.dados` (Plano 21, Task 21.2).
 *
 * Retrato congelado, não view
 * -----------------------------------------------------------------------
 * `consolidar()` é chamado uma vez, no momento da geração do relatório, e o
 * array devolvido é gravado como está em `monitoring_reports.dados`. Nunca é
 * recalculado na leitura: um relatório já entregue ao cliente não pode
 * mudar depois porque uma OS antiga foi corrigida. Quem decide gravar de
 * novo (regenerar) é o service de relatório da Task 21.5, não este.
 *
 * Integração dos serviços da Task 21.3 (feita na Task 21.5)
 * -----------------------------------------------------------------------
 * O retrato completo do Plano 21 tem cinco partes: tendência, ranking de
 * pontos críticos, mapa de calor, ocorrência por espécie e adequações em
 * aberto. `ranking_pontos_criticos`, `mapa_de_calor` e `ocorrencia_por_especie`
 * seguem o mesmo formato de `tendencia`: uma lista com um item por endereço do
 * retrato (`enderecosDoRetrato()`), cada item já trazendo o próprio
 * `address_id` (`PontosCriticosService::ranking()`,
 * `MapaDeCalorService::porPosicao()` e `OcorrenciaPorEspecieService::porPeriodo()`
 * recebem sempre um `Address` só, nunca uma coleção). Nenhuma lógica de
 * ranking, de mapa de calor ou de ocorrência por espécie é inventada aqui: é
 * escopo exclusivo da 21.3, este método só itera endereço a endereço e
 * concatena o resultado de cada chamada na mesma lista - exatamente a mesma
 * mecânica que `tendenciaDoEndereco()` já usa para `tendencia`.
 *
 * `mapa_de_calor` usa só `porPosicao()`, o mapa de calor geoespacial real
 * desta task (`porComodo()` sempre devolve `suportado: false` por limitação
 * de schema, documentado no cabeçalho de `MapaDeCalorService`; não há motivo
 * para carregar essa chamada vazia em todo relatório gerado).
 *
 * `ocorrencia_por_especie` traz o retorno inteiro de `porPeriodo()` por
 * endereço, inclusive o `distribuicao_por_area` já aninhado (uma lista de um
 * item só, o próprio endereço) - quem quiser a distribuição do cliente
 * inteiro concatenada numa lista só (o caso de uso que o cabeçalho de
 * `OcorrenciaPorEspecieService` antecipa) faz um `flatMap('distribuicao_por_area')`
 * sobre esta lista; nenhuma achatamento é feito aqui para não perder, no
 * relatório de um cliente com vários endereços, a `evolucao_por_especie`
 * (que é sempre por endereço, sem equivalente "do cliente inteiro" pedido
 * pelo plano).
 *
 * `adequacoes` fica fora do alcance de 21.2, 21.3 e desta integração, e é
 * preenchida mais adiante no plano (ver "Adequações em aberto e prazo de
 * atendimento" no PRD do Plano 21).
 *
 * "Visita" é ordem de serviço concluída
 * -----------------------------------------------------------------------
 * `visitas` conta `WorkOrder` com `status = completed`: é a definição já
 * usada pelo resto do sistema (`WorkOrder::scopeCompleted()`) para "o
 * atendimento aconteceu". OS pendente, agendada, cancelada ou em espera não
 * vira visita aqui, porque nenhuma delas produziu, de fato, dado de campo
 * para o período.
 *
 * Endereço opcional
 * -----------------------------------------------------------------------
 * Quando `$endereco` é `null`, o retrato cobre todos os endereços ativos do
 * cliente: `monitoring_reports.address_id` é nullable exatamente para esse
 * caso (relatório de todos os pontos do cliente, não de um endereço só). A
 * tendência então sai como uma lista, um item por endereço.
 *
 * Isolamento por empresa
 * -----------------------------------------------------------------------
 * `Client`, `Address` e `WorkOrder` usam `BelongsToCompany`: toda consulta
 * feita aqui por Eloquent já nasce filtrada pela empresa corrente. Este
 * service não adiciona nenhum filtro manual de `company_id` porque não faz
 * nenhum `join` em SQL cru (diferente de `TendenciaService`, que faz e por
 * isso reforça o filtro).
 */
class ConsolidadorDePeriodo
{
    public function __construct(
        private readonly TendenciaService $tendenciaService,
        private readonly PontosCriticosService $pontosCriticosService,
        private readonly MapaDeCalorService $mapaDeCalorService,
        private readonly OcorrenciaPorEspecieService $ocorrenciaPorEspecieService,
    ) {}

    /**
     * @return array{
     *     client_id: int,
     *     address_id: int|null,
     *     periodo: array{de: string, ate: string},
     *     gerado_em: string,
     *     visitas: array{quantidade: int, datas: list<array{work_order_id: int, address_id: int, data: string|null}>},
     *     tendencia: list<array{address_id: int, endereco: string, evolucao_semanal: array<string, mixed>, evolucao_mensal: array<string, mixed>}>,
     *     ranking_pontos_criticos: list<array<string, mixed>>,
     *     mapa_de_calor: list<array<string, mixed>>,
     *     ocorrencia_por_especie: list<array<string, mixed>>,
     *     adequacoes: array<string, mixed>
     * }
     */
    public function consolidar(Client $cliente, ?Address $endereco, string $de, string $ate): array
    {
        $this->confirmarEnderecoDoCliente($cliente, $endereco);

        $inicio = $this->validarData($de, 'de');
        $fim = $this->validarData($ate, 'ate');

        if ($fim->lessThan($inicio)) {
            throw new InvalidArgumentException('A data final não pode ser anterior à data inicial.');
        }

        $enderecos = $this->enderecosDoRetrato($cliente, $endereco);
        $de = $inicio->toDateString();
        $ate = $fim->toDateString();

        return [
            'client_id' => $cliente->id,
            'address_id' => $endereco?->id,
            'periodo' => [
                'de' => $de,
                'ate' => $ate,
            ],
            // Instante da montagem deste array, não o de gravação em
            // `monitoring_reports` (essa data é a coluna `gerado_em` do
            // model, preenchida por quem chama este service). Guardar aqui
            // também torna o JSON autodescritivo mesmo isolado da linha do
            // banco, por exemplo num export.
            'gerado_em' => BusinessDate::agora()->toIso8601String(),
            'visitas' => $this->visitasDoPeriodo($cliente, $enderecos, $inicio, $fim),
            'tendencia' => $enderecos
                ->map(fn (Address $enderecoDoRetrato): array => $this->tendenciaDoEndereco($enderecoDoRetrato, $inicio, $fim))
                ->values()
                ->all(),
            'ranking_pontos_criticos' => $enderecos
                ->map(fn (Address $enderecoDoRetrato): array => $this->pontosCriticosService->ranking($enderecoDoRetrato, $de, $ate))
                ->values()
                ->all(),
            'mapa_de_calor' => $enderecos
                ->map(fn (Address $enderecoDoRetrato): array => $this->mapaDeCalorService->porPosicao($enderecoDoRetrato, $de, $ate))
                ->values()
                ->all(),
            'ocorrencia_por_especie' => $enderecos
                ->map(fn (Address $enderecoDoRetrato): array => $this->ocorrenciaPorEspecieService->porPeriodo($enderecoDoRetrato, $de, $ate))
                ->values()
                ->all(),
            // Fora do escopo de 21.2 e 21.3; ver Plano 21, "Adequações em
            // aberto e prazo de atendimento".
            'adequacoes' => [],
        ];
    }

    /**
     * Endereços que entram no retrato: só `$endereco`, quando informado, ou
     * todos os endereços ativos do cliente, quando o relatório é do cliente
     * inteiro.
     *
     * @return Collection<int, Address>
     */
    private function enderecosDoRetrato(Client $cliente, ?Address $endereco): Collection
    {
        if ($endereco !== null) {
            return collect([$endereco]);
        }

        return $cliente->addresses()->active()->orderBy('nickname')->get();
    }

    /**
     * @return array{address_id: int, endereco: string, evolucao_semanal: array<string, mixed>, evolucao_mensal: array<string, mixed>}
     */
    private function tendenciaDoEndereco(Address $endereco, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $de = $inicio->toDateString();
        $ate = $fim->toDateString();

        return [
            'address_id' => $endereco->id,
            'endereco' => (string) ($endereco->nickname ?: $endereco->short_address),
            'evolucao_semanal' => $this->tendenciaService->evolucaoPorDispositivo(
                $endereco,
                $de,
                $ate,
                TendenciaService::GRANULARIDADE_SEMANA
            ),
            'evolucao_mensal' => $this->tendenciaService->evolucaoPorDispositivo(
                $endereco,
                $de,
                $ate,
                TendenciaService::GRANULARIDADE_MES
            ),
        ];
    }

    /**
     * Número de visitas do período e a data de cada uma, restrito aos mesmos
     * endereços de `$enderecos` (o retorno de `enderecosDoRetrato()`: só
     * `$endereco`, quando informado, ou todos os endereços ATIVOS do
     * cliente). Contar por `client_id` sozinho, sem essa restrição, incluiria
     * a visita de um endereço já desativado que nenhuma outra parte do
     * retrato (tendência, ranking, mapa de calor, ocorrência por espécie)
     * cobre, e o total de visitas do relatório deixaria de bater com a soma
     * do que as outras quatro partes documentam.
     *
     * @param  Collection<int, Address>  $enderecos
     * @return array{quantidade: int, datas: list<array{work_order_id: int, address_id: int, data: string|null}>}
     */
    private function visitasDoPeriodo(Client $cliente, Collection $enderecos, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $consulta = WorkOrder::query()
            ->where('client_id', $cliente->id)
            ->whereIn('address_id', $enderecos->pluck('id'))
            ->completed()
            ->active()
            ->whereBetween('scheduled_date', [$inicio->toDateString(), $fim->toDateString()])
            ->orderBy('scheduled_date')
            ->select(['id', 'address_id', 'scheduled_date']);

        $visitas = [];

        foreach ($consulta->cursor() as $ordem) {
            $visitas[] = [
                'work_order_id' => $ordem->id,
                'address_id' => $ordem->address_id,
                'data' => BusinessDate::diaDe($ordem->scheduled_date),
            ];
        }

        return [
            'quantidade' => count($visitas),
            'datas' => $visitas,
        ];
    }

    private function confirmarEnderecoDoCliente(Client $cliente, ?Address $endereco): void
    {
        if ($endereco !== null && (int) $endereco->client_id !== (int) $cliente->id) {
            throw new InvalidArgumentException('O endereço informado não pertence a este cliente.');
        }
    }

    private function validarData(string $valor, string $campo): CarbonImmutable
    {
        $data = BusinessDate::paraFusoNegocio($valor)?->startOfDay();

        if ($data === null) {
            throw new InvalidArgumentException(sprintf('Informe uma data válida para "%s".', $campo));
        }

        return $data;
    }
}
