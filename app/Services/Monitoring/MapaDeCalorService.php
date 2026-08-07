<?php

namespace App\Services\Monitoring;

use App\Models\Address;
use App\Models\DevicePosition;
use App\Models\FloorPlan;
use App\Models\WorkOrderDeviceEvent;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Mapa de calor de ocorrência do endereço (Plano 21, Task 21.3): intensidade
 * de captura por ponto do período, normalizada de 0 a 1 sobre o maior valor,
 * sempre ao lado do número absoluto.
 *
 * `porComodo` não é implementável com o dado estruturado de hoje
 * -----------------------------------------------------------------------
 * A task pede "mapa de calor por cômodo" citando `Room` como contexto porque
 * cômodo é, em tese, a unidade natural de área de um endereço. Conferido o
 * schema atual (migrations, não suposição), essa ligação não existe:
 *
 * - `devices` teve `room_id` removido e substituído por `address_id` direto
 *   em `database/migrations/2026_01_07_085358_change_devices_room_id_to_address_id.php`.
 *   Dispositivo não sabe em qual cômodo está, só em qual endereço.
 * - `work_order_room` teve `device_id` removido em
 *   `database/migrations/2026_01_07_091235_remove_device_id_from_work_order_room_table.php`.
 *   Não existe outra tabela nem pivot que ligue dispositivo a cômodo.
 * - `pest_sightings` (`database/migrations/2025_08_27_110850_create_pest_sightings_table.php`)
 *   só tem `address_id`, sem `room_id`; a localização por cômodo, quando
 *   existe, é texto livre em `location_description`, sem vocabulário fixo.
 *
 * Ou seja: nenhuma foreign key liga hoje um dispositivo (ou uma posição de
 * dispositivo) a um `Room`. Inventar uma aproximação por OS (associar
 * dispositivo a cômodo quando os dois aparecem na mesma `WorkOrder`, via
 * `work_order_device` e `work_order_room`) foi considerado e descartado: um
 * dispositivo pode aparecer em várias OS ao lado de cômodos diferentes ao
 * longo do período, e a mesma OS pode listar vários cômodos e vários
 * dispositivos ao mesmo tempo sem dizer qual dispositivo está em qual
 * cômodo. Qualquer regra de rateio (dividir a intensidade entre os cômodos
 * da OS, ou duplicá-la em cada um) fabricaria uma precisão geográfica que o
 * dado de campo nunca registrou, num relatório que alimenta auditoria de
 * indústria alimentícia. É melhor devolver "não suportado" com o motivo
 * explícito do que uma aproximação que parece dado medido.
 *
 * `porComodo` mantém a assinatura (contrato para as Tasks 21.5/21.8) e
 * devolve `suportado: false` com o motivo, `comodos` vazio. `porPosicao`,
 * que usa `DevicePosition` sobre a `FloorPlan` ativa do endereço (Plano 21,
 * Task 21.1), é o mapa de calor geoespacial real desta task: a planta com
 * posição x/y é o substituto estruturado do que `devices.default_location_note`
 * já tentava dizer em texto livre.
 *
 * Fonte de dado e definição de "ocorrência"
 * -----------------------------------------------------------------------
 * Mesma fonte e mesma definição do `TendenciaService` e do
 * `PontosCriticosService`: `work_order_device_events.pest_found` preenchido
 * conta como uma captura no período. `porPosicao` soma capturas por
 * dispositivo e as posiciona pela `DevicePosition` da planta ativa. Um
 * dispositivo sem nenhuma captura no período entra no mapa com valor
 * absoluto zero (ponto "frio"), não é omitido: omitir apagaria do croqui um
 * ponto que existe e foi de fato monitorado.
 *
 * Escala relativa nunca desacompanhada da absoluta
 * -----------------------------------------------------------------------
 * `intensidade` é o valor absoluto dividido pelo maior valor absoluto do
 * próprio período (0 a 1); quando o maior valor é 0 (nenhuma captura em
 * nenhum ponto), todas as intensidades saem 0, nunca `NAN`. `escala_absoluta_maxima`
 * viaja junto: 2 capturas com máximo do período em 2 pintam o ponto todo em
 * vermelho (intensidade 1.0) tanto quanto 2 capturas com máximo em 40, e sem
 * o número absoluto ao lado essas duas situações pareceriam igualmente
 * graves.
 *
 * Nenhuma conclusão automática
 * -----------------------------------------------------------------------
 * O service entrega número (absoluto e normalizado), nunca leitura como
 * "ponto crítico" ou "sob controle". Isso é o Plano 25.
 *
 * Performance, índice e isolamento por empresa
 * -----------------------------------------------------------------------
 * Mesmo padrão do `TendenciaService`: a soma de capturas por dispositivo é
 * feita em SQL (`SUM(CASE WHEN ...)`), filtrada por `event_date` (índice) e
 * por `devices.address_id`, com `devices.company_id` reforçado
 * explicitamente no `join` cru. `FloorPlan` e `DevicePosition` são
 * consultados por Eloquent puro (sem `join` cru), já nascendo filtrados pela
 * empresa corrente via `BelongsToCompany`.
 */
class MapaDeCalorService
{
    /**
     * Não implementável hoje: sem FK entre dispositivo e cômodo. Ver o
     * cabeçalho da classe para a evidência de schema e a decisão.
     *
     * @return array{
     *     address_id: int,
     *     periodo: array{de: string, ate: string},
     *     suportado: bool,
     *     motivo_nao_suportado: string,
     *     comodos: array<int, array<string, mixed>>
     * }
     */
    public function porComodo(Address $endereco, string $de, string $ate): array
    {
        [$inicio, $fim] = $this->validarIntervalo($de, $ate);

        return [
            'address_id' => $endereco->id,
            'periodo' => ['de' => $inicio->toDateString(), 'ate' => $fim->toDateString()],
            'suportado' => false,
            'motivo_nao_suportado' => 'O schema atual não liga dispositivo a cômodo: "devices" migrou de '
                .'room_id para address_id direto, e "work_order_room" teve device_id removido. Sem essa '
                .'foreign key, não há como apurar mapa de calor por cômodo sem fabricar uma aproximação. '
                .'Use porPosicao(), que usa a posição real do dispositivo na planta do endereço.',
            'comodos' => [],
        ];
    }

    /**
     * Mapa de calor real desta task: intensidade de captura por ponto da
     * planta ativa do endereço.
     *
     * `suportado: false` aqui é uma situação operacional e temporária (o
     * endereço ainda não tem planta enviada, ou nenhuma posição salva),
     * diferente do `motivo_nao_suportado` de `porComodo`, que é uma
     * limitação estrutural do schema. Assim que o endereço tiver planta
     * ativa com posições, a mesma chamada passa a `suportado: true`.
     *
     * @return array{
     *     address_id: int,
     *     periodo: array{de: string, ate: string},
     *     suportado: bool,
     *     motivo_nao_suportado: string|null,
     *     planta: array{floor_plan_id: int, versao: int, nome: string, arquivo_url: string, largura_px: int, altura_px: int}|null,
     *     escala_absoluta_maxima: int,
     *     pontos: list<array{device_id: int, rotulo: string, x: float, y: float, valor_absoluto: int, intensidade: float}>
     * }
     */
    public function porPosicao(Address $endereco, string $de, string $ate): array
    {
        [$inicio, $fim] = $this->validarIntervalo($de, $ate);

        $planta = FloorPlan::query()
            ->where('address_id', $endereco->id)
            ->where('ativa', true)
            ->first();

        if ($planta === null) {
            return [
                'address_id' => $endereco->id,
                'periodo' => ['de' => $inicio->toDateString(), 'ate' => $fim->toDateString()],
                'suportado' => false,
                'motivo_nao_suportado' => 'Este endereço ainda não tem planta ativa enviada (ver FloorPlanService, Task 21.4).',
                'planta' => null,
                'escala_absoluta_maxima' => 0,
                'pontos' => [],
            ];
        }

        $posicoes = DevicePosition::query()
            ->where('floor_plan_id', $planta->id)
            ->with('device:id,label,number')
            ->get();

        if ($posicoes->isEmpty()) {
            return [
                'address_id' => $endereco->id,
                'periodo' => ['de' => $inicio->toDateString(), 'ate' => $fim->toDateString()],
                'suportado' => false,
                'motivo_nao_suportado' => 'A planta ativa deste endereço ainda não tem nenhum dispositivo posicionado.',
                'planta' => $this->descreverPlanta($planta),
                'escala_absoluta_maxima' => 0,
                'pontos' => [],
            ];
        }

        $capturasPorDispositivo = $this->capturasPorDispositivo($endereco, $inicio, $fim);

        $pontosBrutos = $posicoes->map(fn (DevicePosition $posicao): array => [
            'device_id' => $posicao->device_id,
            'rotulo' => $posicao->device?->full_label ?? (string) $posicao->device_id,
            'x' => (float) $posicao->x,
            'y' => (float) $posicao->y,
            'valor_absoluto' => (int) ($capturasPorDispositivo->get($posicao->device_id) ?? 0),
        ]);

        $maximo = (int) $pontosBrutos->max('valor_absoluto');

        $pontos = $pontosBrutos
            ->map(fn (array $ponto): array => [
                ...$ponto,
                'intensidade' => $maximo > 0 ? round($ponto['valor_absoluto'] / $maximo, 4) : 0.0,
            ])
            ->values()
            ->all();

        return [
            'address_id' => $endereco->id,
            'periodo' => ['de' => $inicio->toDateString(), 'ate' => $fim->toDateString()],
            'suportado' => true,
            'motivo_nao_suportado' => null,
            'planta' => $this->descreverPlanta($planta),
            'escala_absoluta_maxima' => $maximo,
            'pontos' => $pontos,
        ];
    }

    /**
     * @return array{floor_plan_id: int, versao: int, nome: string, arquivo_url: string, largura_px: int, altura_px: int}
     */
    private function descreverPlanta(FloorPlan $planta): array
    {
        return [
            'floor_plan_id' => $planta->id,
            'versao' => $planta->versao,
            'nome' => $planta->nome,
            'arquivo_url' => $planta->arquivo_url,
            'largura_px' => $planta->largura_px,
            'altura_px' => $planta->altura_px,
        ];
    }

    /**
     * Capturas por dispositivo dentro de `[$inicio, $fim]`, somadas em SQL.
     * Dispositivo sem nenhum evento no intervalo não aparece na coleção; quem
     * chama trata a ausência como zero.
     *
     * @return Collection<int, int>
     */
    private function capturasPorDispositivo(Address $endereco, CarbonImmutable $inicio, CarbonImmutable $fim): Collection
    {
        return WorkOrderDeviceEvent::query()
            ->join('devices', 'devices.id', '=', 'work_order_device_events.device_id')
            ->where('devices.address_id', $endereco->id)
            ->where('devices.company_id', $endereco->company_id)
            ->whereBetween('work_order_device_events.event_date', [$inicio->toDateString(), $fim->toDateString()])
            ->groupBy('work_order_device_events.device_id')
            ->selectRaw('work_order_device_events.device_id as device_id')
            ->selectRaw(
                "SUM(CASE WHEN work_order_device_events.pest_found IS NOT NULL AND work_order_device_events.pest_found <> '' THEN 1 ELSE 0 END) as capturas"
            )
            ->pluck('capturas', 'device_id')
            ->map(static fn (mixed $valor): int => (int) $valor);
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
