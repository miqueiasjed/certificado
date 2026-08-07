<?php

namespace App\Services\Monitoring;

use App\Models\Address;
use App\Models\Device;
use App\Models\WorkOrderDeviceEvent;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Ranking dos pontos críticos de um endereço (Plano 21, Task 21.3): quais
 * dispositivos concentram a ocorrência no período, normalizado por visita.
 *
 * Por que normalizar por visita
 * -----------------------------------------------------------------------
 * Um dispositivo visitado 12 vezes com 6 capturas está sob controle melhor
 * que um visitado 3 vezes com 3 capturas, mesmo o primeiro tendo o dobro do
 * total: a taxa é 50% contra 100%. Ordenar só pelo total mediria frequência
 * de visita, não infestação, e levaria a empresa a tratar o ponto errado.
 * Por isso o ranking ordena por `media_por_visita` (capturas / visitas) e
 * mostra `capturas_total` ao lado, nunca no lugar.
 *
 * Fonte de dado: `WorkOrderDeviceEvent`, mesma da Task 21.2
 * -----------------------------------------------------------------------
 * Mesmo motivo do `TendenciaService`: é `work_order_device_events` que o
 * aplicativo do técnico grava e que aparece no PDF da OS, não `device_events`
 * (tabela antiga, sem relação com o documento oficial). "Ocorrência" aqui é
 * evento com `pest_found` preenchido, a mesma definição de "captura" que o
 * `TendenciaService` já usa.
 *
 * Tendência: comparação com o período anterior de mesma duração
 * -----------------------------------------------------------------------
 * Se o período pedido tem 30 dias, o período anterior são os 30 dias
 * imediatamente antes (não o mês civil anterior). A comparação usa a mesma
 * média por visita dos dois lados, pelo mesmo motivo da normalização acima:
 * comparar totais brutos entre dois períodos com número de visita diferente
 * distorceria a direção da tendência.
 *
 * Critério de corte entre subindo/estável/caindo
 * -----------------------------------------------------------------------
 * Sem um valor já definido no PRD ou no plano, o corte adotado aqui é
 * `LIMIAR_TENDENCIA_PERCENTUAL` = 10%: variação da média por visita maior ou
 * igual a +10% é "subindo", menor ou igual a -10% é "caindo", e o intervalo
 * entre os dois é "estável" (ruído normal de amostra pequena não deveria
 * acender alarme). Dois casos de fronteira são tratados à parte porque
 * percentual sobre zero não é calculável: se as duas médias são zero,
 * "estável" (nada mudou, nada foi encontrado); se só a média anterior é
 * zero, "subindo" sem percentual numérico (não dá para expressar "a partir
 * de zero" em porcentagem, mesmo raciocínio do `calcularVariacao` do
 * `TendenciaService`). Quando o período anterior não teve nenhuma visita,
 * o estado sai como `sem_comparacao`: não existe base de comparação, e
 * "estável" seria uma afirmação falsa sobre um dado que não existe.
 * `media_anterior` e `percentual` sempre acompanham `estado`, para quem
 * apresenta o dado nunca depender só do rótulo categórico.
 *
 * Nenhuma conclusão automática
 * -----------------------------------------------------------------------
 * O estado da tendência é um rótulo categórico derivado de número, não uma
 * frase interpretativa ("infestação preocupante" etc.). Leitura desse tipo é
 * o Plano 25.
 *
 * Performance e índice
 * -----------------------------------------------------------------------
 * As duas consultas de totais (período atual e período anterior) filtram
 * `event_date` por `WHERE ... BETWEEN` (índice `work_order_device_events`) e
 * `devices.address_id` (índice `devices_address_id_active_index`), com a
 * soma feita em SQL (`COUNT`/`SUM(CASE WHEN ...)`), nunca em PHP.
 *
 * Isolamento por empresa
 * -----------------------------------------------------------------------
 * O `join` com `devices` reforça `devices.company_id = $endereco->company_id`
 * explicitamente, mesmo padrão do `TendenciaService`: um `join` em SQL cru
 * não herda o escopo global de `BelongsToCompany` automaticamente.
 */
class PontosCriticosService
{
    public const TENDENCIA_SUBINDO = 'subindo';

    public const TENDENCIA_ESTAVEL = 'estavel';

    public const TENDENCIA_CAINDO = 'caindo';

    public const TENDENCIA_SEM_COMPARACAO = 'sem_comparacao';

    private const LIMIAR_TENDENCIA_PERCENTUAL = 10.0;

    /**
     * @return array{
     *     address_id: int,
     *     periodo: array{de: string, ate: string},
     *     periodo_anterior: array{de: string, ate: string},
     *     limite: int,
     *     dispositivos: list<array{
     *         device_id: int,
     *         rotulo: string,
     *         codigo_publico: string|null,
     *         visitas: int,
     *         capturas_total: int,
     *         media_por_visita: float,
     *         tendencia: array{estado: string, media_anterior: float|null, percentual: float|null}
     *     }>
     * }
     */
    public function ranking(Address $endereco, string $de, string $ate, int $limite = 10): array
    {
        [$inicio, $fim] = $this->validarIntervalo($de, $ate);
        $limite = max(1, $limite);

        [$inicioAnterior, $fimAnterior] = $this->periodoAnteriorDeMesmaDuracao($inicio, $fim);

        $totaisAtuais = $this->totaisPorDispositivo($endereco, $inicio, $fim);
        $totaisAnteriores = $this->totaisPorDispositivo($endereco, $inicioAnterior, $fimAnterior);

        $dispositivos = Device::query()
            ->where('address_id', $endereco->id)
            ->whereIn('id', $totaisAtuais->keys())
            ->get(['id', 'label', 'number', 'codigo_publico'])
            ->keyBy('id');

        $linhas = $totaisAtuais
            ->filter(fn (array $totais): bool => $totais['visitas'] > 0)
            ->map(function (array $totais, int $deviceId) use ($totaisAnteriores, $dispositivos): array {
                // Mesmo cast do numerador em `tendencia()`, pelo mesmo
                // motivo: divisão exata de dois inteiros devolve int no
                // PHP, e este valor circula por comparação estrita adiante.
                $mediaPorVisita = ((float) $totais['capturas']) / $totais['visitas'];
                /** @var Device|null $dispositivo */
                $dispositivo = $dispositivos->get($deviceId);

                return [
                    'device_id' => $deviceId,
                    'rotulo' => $dispositivo?->full_label ?? (string) $deviceId,
                    'codigo_publico' => $dispositivo?->codigo_publico,
                    'visitas' => $totais['visitas'],
                    'capturas_total' => $totais['capturas'],
                    'media_por_visita' => round($mediaPorVisita, 4),
                    'tendencia' => $this->tendencia($mediaPorVisita, $totaisAnteriores->get($deviceId)),
                ];
            })
            ->values();

        $ordenados = $linhas
            ->sort(function (array $a, array $b): int {
                $porMedia = $b['media_por_visita'] <=> $a['media_por_visita'];

                if ($porMedia !== 0) {
                    return $porMedia;
                }

                $porTotal = $b['capturas_total'] <=> $a['capturas_total'];

                return $porTotal !== 0 ? $porTotal : $a['device_id'] <=> $b['device_id'];
            })
            ->values()
            ->take($limite)
            ->all();

        return [
            'address_id' => $endereco->id,
            'periodo' => ['de' => $inicio->toDateString(), 'ate' => $fim->toDateString()],
            'periodo_anterior' => ['de' => $inicioAnterior->toDateString(), 'ate' => $fimAnterior->toDateString()],
            'limite' => $limite,
            'dispositivos' => $ordenados,
        ];
    }

    /**
     * Estado da tendência de um dispositivo, comparando a média por visita
     * do período atual com a do período anterior. Ver critério de corte no
     * cabeçalho da classe.
     *
     * @param  array{visitas: int, capturas: int}|null  $anterior
     * @return array{estado: string, media_anterior: float|null, percentual: float|null}
     */
    private function tendencia(float $mediaAtual, ?array $anterior): array
    {
        if ($anterior === null || $anterior['visitas'] === 0) {
            return [
                'estado' => self::TENDENCIA_SEM_COMPARACAO,
                'media_anterior' => null,
                'percentual' => null,
            ];
        }

        // Divisão de dois inteiros no PHP devolve int quando é exata (ex.:
        // 0 / 2 é o int 0, não o float 0.0), e as comparações abaixo
        // dependem de sempre comparar float com float. O cast no numerador
        // força divisão em ponto flutuante mesmo quando o resultado é
        // "redondo", e evita comparação estrita (0 === 0.0 é falso em PHP)
        // classificar errado o caso "duas médias zeradas".
        $mediaAnterior = ((float) $anterior['capturas']) / $anterior['visitas'];

        if ($mediaAnterior === 0.0 && $mediaAtual === 0.0) {
            return [
                'estado' => self::TENDENCIA_ESTAVEL,
                'media_anterior' => 0.0,
                'percentual' => 0.0,
            ];
        }

        if ($mediaAnterior === 0.0) {
            return [
                'estado' => self::TENDENCIA_SUBINDO,
                'media_anterior' => 0.0,
                'percentual' => null,
            ];
        }

        $percentual = (($mediaAtual - $mediaAnterior) / $mediaAnterior) * 100;

        $estado = match (true) {
            $percentual >= self::LIMIAR_TENDENCIA_PERCENTUAL => self::TENDENCIA_SUBINDO,
            $percentual <= -self::LIMIAR_TENDENCIA_PERCENTUAL => self::TENDENCIA_CAINDO,
            default => self::TENDENCIA_ESTAVEL,
        };

        return [
            'estado' => $estado,
            'media_anterior' => round($mediaAnterior, 4),
            'percentual' => round($percentual, 2),
        ];
    }

    /**
     * O período anterior de mesma duração, imediatamente antes de `$inicio`.
     * Período de 30 dias tem como anterior os 30 dias que terminam no dia
     * anterior a `$inicio`, não o mês civil anterior.
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
     * Totais por dispositivo dentro de `[$inicio, $fim]`, somados em SQL.
     * Dispositivo sem nenhum evento no intervalo não aparece na coleção.
     *
     * @return Collection<int, array{visitas: int, capturas: int}>
     */
    private function totaisPorDispositivo(Address $endereco, CarbonImmutable $inicio, CarbonImmutable $fim): Collection
    {
        return WorkOrderDeviceEvent::query()
            ->join('devices', 'devices.id', '=', 'work_order_device_events.device_id')
            ->where('devices.address_id', $endereco->id)
            ->where('devices.company_id', $endereco->company_id)
            ->whereBetween('work_order_device_events.event_date', [$inicio->toDateString(), $fim->toDateString()])
            ->groupBy('work_order_device_events.device_id')
            ->selectRaw('work_order_device_events.device_id as device_id')
            ->selectRaw('COUNT(*) as visitas')
            ->selectRaw(
                "SUM(CASE WHEN work_order_device_events.pest_found IS NOT NULL AND work_order_device_events.pest_found <> '' THEN 1 ELSE 0 END) as capturas"
            )
            ->get()
            ->keyBy(static fn (object $linha): int => (int) $linha->device_id)
            ->map(static fn (object $linha): array => [
                'visitas' => (int) $linha->visitas,
                'capturas' => (int) $linha->capturas,
            ]);
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
