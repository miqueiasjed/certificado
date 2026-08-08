<?php

namespace App\Services\Fleet;

use App\Models\Refueling;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Support\BusinessDate;
use App\Support\Dinheiro;

/**
 * Custo real por quilômetro de um veículo da frota (Plano 27, Task 27.2).
 *
 * É a base de todo o módulo: o consumo apurado aqui é o que transforma o
 * deslocamento em custo dentro da margem da OS (Plano 18), no lugar do valor
 * fixo por visita que existia até esta entrega.
 *
 * Consumo só entre tanques cheios
 * -------------------------------
 * Um intervalo de apuração vai de um abastecimento com `tanque_cheio = true`
 * até o **próximo** abastecimento com `tanque_cheio = true`. Só nesse trecho se
 * sabe quanto combustível havia no tanque nas duas pontas, e por isso só nele a
 * conta fecha. Tomar um abastecimento parcial como ponta produz um número que
 * parece plausível e está errado, e ninguém percebe: o erro não estoura, ele só
 * desloca a margem.
 *
 * Abastecimento parcial no meio do intervalo **não é ignorado**: os litros dele
 * entram na soma do intervalo, porque o tanque estava cheio no começo e voltou
 * a estar cheio no fim, então tudo que foi posto no meio também foi consumido.
 * Descartar esses litros é o que distorceria o cálculo — inflaria o km/l do
 * veículo que abastece parcial com frequência. O que o parcial nunca faz é
 * abrir ou fechar um intervalo.
 *
 * Amostra pequena não vira custo por quilômetro
 * ---------------------------------------------
 * Com menos de `MINIMO_DE_INTERVALOS` intervalos completos, o resultado é
 * ruído: uma viagem de estrada no meio de um mês de cidade dobra o km/l de um
 * único intervalo. Nesse caso o cálculo não é feito e o serviço devolve o
 * `custo_km_padrao` cadastrado no veículo, **marcado como padrão**
 * (`origem => ORIGEM_PADRAO`). Número que parece medido e não é leva a decisão
 * errada com confiança, mesmo critério da margem parcial do Plano 18.
 *
 * Nota sobre o número de abastecimentos: 3 intervalos exigem 4 abastecimentos
 * de tanque cheio, porque o primeiro só abre o primeiro intervalo, sem fechar
 * nenhum.
 *
 * Datas
 * -----
 * `$de` e `$ate` são dias (`Y-m-d`), comparados por dia no fuso do negócio via
 * `App\Support\BusinessDate`, nunca contra `now()` em UTC. `refuelings.data` e
 * `vehicle_maintenances.data` são colunas `date`, que não sofrem conversão de
 * fuso.
 *
 * Empresa
 * -------
 * Sem consulta própria de tenant: o escopo global de `Refueling` e de
 * `VehicleMaintenance` resolve pelo tenant corrente, e quem roda para todas as
 * empresas itera com `TenantAtual::comTenant()`.
 */
class CustoPorKmService
{
    /**
     * Mínimo de intervalos completos de tanque cheio para o consumo ser
     * considerado medido. Abaixo disso é ruído: ver o cabeçalho.
     */
    public const MINIMO_DE_INTERVALOS = 3;

    /**
     * Custo por quilômetro apurado a partir do histórico de abastecimento.
     */
    public const ORIGEM_MEDIDO = 'medido';

    /**
     * Custo por quilômetro vindo de `vehicles.custo_km_padrao`, porque não há
     * histórico suficiente para apurar.
     */
    public const ORIGEM_PADRAO = 'padrao';

    /**
     * Menos de `MINIMO_DE_INTERVALOS` intervalos de tanque cheio no período.
     */
    public const MOTIVO_HISTORICO_INSUFICIENTE = 'historico_insuficiente';

    /**
     * Sem histórico suficiente e sem `custo_km_padrao` cadastrado: não há
     * custo por quilômetro nenhum a devolver.
     */
    public const MOTIVO_SEM_CUSTO_PADRAO = 'sem_custo_km_padrao';

    /**
     * Casas decimais do custo por quilômetro, iguais às de
     * `vehicles.custo_km_padrao`. Custo por quilômetro vive na terceira e na
     * quarta casa: arredondar para centavo aqui desloca o rateio de forma
     * invisível, porque o erro é multiplicado pela quilometragem.
     */
    private const CASAS_DO_CUSTO_POR_KM = 4;

    /**
     * Consumo médio do veículo no período, apurado só entre tanques cheios.
     *
     * `km_por_litro` e `custo_combustivel_por_km` vêm nulos quando não há
     * intervalo completo nenhum; `suficiente` diz se a amostra chegou ao
     * mínimo. Quem decide o que fazer com amostra pequena é
     * `custoTotalPorKm()`, não este método: aqui o retorno é a medição crua,
     * com a quantidade de intervalos sempre à vista.
     *
     * @return array{
     *     km_por_litro: ?float,
     *     custo_combustivel_por_km: ?string,
     *     custo_combustivel: string,
     *     km_rodados: int,
     *     litros: float,
     *     intervalos: int,
     *     suficiente: bool
     * }
     */
    public function consumo(Vehicle $veiculo, string $de, string $ate): array
    {
        $intervalosApurados = $this->intervalos($veiculo, $de, $ate);

        $kmRodados = 0;
        $litros = 0.0;
        $custoCentavos = 0;

        foreach ($intervalosApurados as $intervalo) {
            $kmRodados += $intervalo['distancia_km'];
            $litros += $intervalo['litros'];
            $custoCentavos += $intervalo['custo_centavos'];
        }

        $intervalos = count($intervalosApurados);

        return [
            'km_por_litro' => $litros > 0.0 ? round($kmRodados / $litros, 2) : null,
            'custo_combustivel_por_km' => $kmRodados > 0 ? $this->porKm($custoCentavos, $kmRodados) : null,
            'custo_combustivel' => Dinheiro::paraDecimal($custoCentavos),
            'km_rodados' => $kmRodados,
            'litros' => round($litros, 3),
            'intervalos' => $intervalos,
            'suficiente' => $intervalos >= self::MINIMO_DE_INTERVALOS,
        ];
    }

    /**
     * Custo por quilômetro do veículo no período: combustível mais manutenção,
     * com os componentes separados para a empresa entender de onde vem o
     * número.
     *
     * Com amostra menor que `MINIMO_DE_INTERVALOS`, cai em
     * `vehicles.custo_km_padrao` e devolve `origem => ORIGEM_PADRAO`, com os
     * dois componentes nulos: não há como separar combustível de manutenção
     * dentro de um valor que foi digitado, e fingir a separação seria pior que
     * não tê-la.
     *
     * @return array{
     *     total_por_km: ?string,
     *     combustivel_por_km: ?string,
     *     manutencao_por_km: ?string,
     *     custo_combustivel: ?string,
     *     custo_manutencao: ?string,
     *     km_rodados: int,
     *     km_por_litro: ?float,
     *     intervalos: int,
     *     origem: string,
     *     motivo: ?string
     * }
     */
    public function custoTotalPorKm(Vehicle $veiculo, string $de, string $ate): array
    {
        $consumo = $this->consumo($veiculo, $de, $ate);

        if (! $consumo['suficiente'] || $consumo['km_rodados'] <= 0) {
            return $this->custoPadrao($veiculo, $consumo['intervalos']);
        }

        $kmRodados = $consumo['km_rodados'];

        $manutencaoCentavos = $this->custoDeManutencao($veiculo, $de, $ate);
        $combustivelCentavos = Dinheiro::centavos($consumo['custo_combustivel']);

        return [
            'total_por_km' => $this->porKm($combustivelCentavos + $manutencaoCentavos, $kmRodados),
            'combustivel_por_km' => $this->porKm($combustivelCentavos, $kmRodados),
            'manutencao_por_km' => $this->porKm($manutencaoCentavos, $kmRodados),
            'custo_combustivel' => Dinheiro::paraDecimal($combustivelCentavos),
            'custo_manutencao' => Dinheiro::paraDecimal($manutencaoCentavos),
            'km_rodados' => $kmRodados,
            'km_por_litro' => $consumo['km_por_litro'],
            'intervalos' => $consumo['intervalos'],
            'origem' => self::ORIGEM_MEDIDO,
            'motivo' => null,
        ];
    }

    /**
     * Série de consumo, um ponto por intervalo de tanque cheio, na ordem em que
     * aconteceram (Task 27.5).
     *
     * É o que a ficha do veículo desenha: queda de rendimento aparece no
     * gráfico semanas antes de virar problema mecânico, e a média do período
     * sozinha esconde exatamente isso.
     *
     * Cada ponto é um intervalo, nunca um abastecimento: km/l de um
     * abastecimento isolado não existe (não se sabe quanto do tanque anterior
     * ainda estava lá).
     *
     * @return array<int, array{
     *     data: ?string,
     *     km: int,
     *     distancia_km: int,
     *     litros: float,
     *     km_por_litro: float,
     *     custo_por_km: string
     * }>
     */
    public function serieDeConsumo(Vehicle $veiculo, string $de, string $ate): array
    {
        return array_map(static fn (array $intervalo): array => [
            'data' => $intervalo['data'],
            'km' => $intervalo['km_final'],
            'distancia_km' => $intervalo['distancia_km'],
            'litros' => round($intervalo['litros'], 3),
            'km_por_litro' => $intervalo['km_por_litro'],
            'custo_por_km' => $intervalo['custo_por_km'],
        ], $this->intervalos($veiculo, $de, $ate));
    }

    /**
     * Intervalos completos de tanque cheio no período, na ordem do hodômetro.
     *
     * É o coração do módulo, e existe uma vez só: `consumo()` agrega estes
     * intervalos e `serieDeConsumo()` os devolve ponto a ponto. Duas
     * implementações da mesma travessia acabariam divergindo, e a divergência
     * apareceria como um gráfico que não bate com a média ao lado dele.
     *
     * Um intervalo vai de um abastecimento com `tanque_cheio` até o próximo com
     * `tanque_cheio`; os litros de todo abastecimento no meio (parcial
     * incluído) entram na conta, porque o tanque estava cheio nas duas pontas.
     *
     * @return array<int, array{
     *     data: ?string,
     *     km_inicial: int,
     *     km_final: int,
     *     distancia_km: int,
     *     litros: float,
     *     custo_centavos: int,
     *     km_por_litro: float,
     *     custo_por_km: string
     * }>
     */
    private function intervalos(Vehicle $veiculo, string $de, string $ate): array
    {
        $abastecimentos = $this->abastecimentosDoPeriodo($veiculo, $de, $ate);

        // Índices dos abastecimentos de tanque cheio dentro da série completa.
        $pontas = [];
        foreach ($abastecimentos as $indice => $abastecimento) {
            if ((bool) $abastecimento->tanque_cheio) {
                $pontas[] = $indice;
            }
        }

        $intervalos = [];

        for ($i = 0; $i < count($pontas) - 1; $i++) {
            $inicio = $pontas[$i];
            $fim = $pontas[$i + 1];

            $distancia = (int) $abastecimentos[$fim]->km - (int) $abastecimentos[$inicio]->km;

            // Duas leituras iguais de hodômetro não formam intervalo: dividir
            // por zero aqui produziria INF e contaminaria a média inteira.
            if ($distancia <= 0) {
                continue;
            }

            $litros = 0.0;
            $custoCentavos = 0;

            for ($j = $inicio + 1; $j <= $fim; $j++) {
                $litros += (float) $abastecimentos[$j]->litros;
                $custoCentavos += Dinheiro::centavos($abastecimentos[$j]->valor_total);
            }

            if ($litros <= 0.0) {
                continue;
            }

            $intervalos[] = [
                'data' => BusinessDate::diaDe($abastecimentos[$fim]->data),
                'km_inicial' => (int) $abastecimentos[$inicio]->km,
                'km_final' => (int) $abastecimentos[$fim]->km,
                'distancia_km' => $distancia,
                'litros' => $litros,
                'custo_centavos' => $custoCentavos,
                'km_por_litro' => round($distancia / $litros, 2),
                'custo_por_km' => $this->porKm($custoCentavos, $distancia),
            ];
        }

        return $intervalos;
    }

    /**
     * Abastecimentos do veículo no período, do mais antigo para o mais
     * recente pela leitura do hodômetro.
     *
     * A ordenação é por `km` e, em empate, por `data` e `id`: `km` é o eixo do
     * cálculo (a distância é a diferença entre duas leituras), e dois
     * abastecimentos no mesmo dia precisam de uma ordem estável.
     *
     * @return array<int, Refueling>
     */
    private function abastecimentosDoPeriodo(Vehicle $veiculo, string $de, string $ate): array
    {
        return Refueling::query()
            ->where('vehicle_id', $veiculo->getKey())
            ->whereBetween('data', [BusinessDate::diaDe($de), BusinessDate::diaDe($ate)])
            ->orderBy('km')
            ->orderBy('data')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Soma das manutenções realizadas no período, em centavos.
     *
     * Só `realizada` entra: manutenção `agendada` ainda não gerou custo e
     * `cancelada` nunca vai gerar. Manutenção realizada sem `valor` entra como
     * zero — o serviço foi feito, mas o quanto custou ninguém registrou, e
     * inventar o valor sujaria o custo por quilômetro.
     */
    private function custoDeManutencao(Vehicle $veiculo, string $de, string $ate): int
    {
        $manutencoes = VehicleMaintenance::query()
            ->where('vehicle_id', $veiculo->getKey())
            ->where('situacao', 'realizada')
            ->whereNotNull('data')
            ->whereBetween('data', [BusinessDate::diaDe($de), BusinessDate::diaDe($ate)])
            ->get();

        $total = 0;

        foreach ($manutencoes as $manutencao) {
            $total += Dinheiro::centavos($manutencao->valor);
        }

        return $total;
    }

    /**
     * Retorno de amostra insuficiente: o `custo_km_padrao` do veículo, marcado
     * como padrão. Sem o padrão cadastrado, não há custo por quilômetro
     * nenhum, e quem chama precisa saber disso em vez de receber zero.
     *
     * @return array{
     *     total_por_km: ?string,
     *     combustivel_por_km: ?string,
     *     manutencao_por_km: ?string,
     *     custo_combustivel: ?string,
     *     custo_manutencao: ?string,
     *     km_rodados: int,
     *     km_por_litro: ?float,
     *     intervalos: int,
     *     origem: string,
     *     motivo: ?string
     * }
     */
    private function custoPadrao(Vehicle $veiculo, int $intervalos): array
    {
        $padrao = $veiculo->custo_km_padrao;

        return [
            'total_por_km' => $padrao !== null
                ? number_format((float) $padrao, self::CASAS_DO_CUSTO_POR_KM, '.', '')
                : null,
            'combustivel_por_km' => null,
            'manutencao_por_km' => null,
            'custo_combustivel' => null,
            'custo_manutencao' => null,
            'km_rodados' => 0,
            'km_por_litro' => null,
            'intervalos' => $intervalos,
            'origem' => self::ORIGEM_PADRAO,
            'motivo' => $padrao !== null
                ? self::MOTIVO_HISTORICO_INSUFICIENTE
                : self::MOTIVO_SEM_CUSTO_PADRAO,
        ];
    }

    /**
     * Custo em centavos dividido pela quilometragem, devolvido em reais com 4
     * casas decimais.
     *
     * A divisão é feita sobre centavos (inteiro) e só depois convertida para
     * reais, no mesmo critério de `App\Support\Dinheiro`: o resultado é uma
     * **taxa**, não um valor monetário, e quem transforma taxa em dinheiro
     * (o rateio da OS) volta para centavos inteiros antes de somar.
     */
    private function porKm(int $centavos, int $km): string
    {
        if ($km <= 0) {
            return number_format(0, self::CASAS_DO_CUSTO_POR_KM, '.', '');
        }

        return number_format($centavos / $km / 100, self::CASAS_DO_CUSTO_POR_KM, '.', '');
    }
}
