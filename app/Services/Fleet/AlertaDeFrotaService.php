<?php

namespace App\Services\Fleet;

use App\Models\Refueling;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\VehicleMaintenance;
use App\Support\BusinessDate;
use Illuminate\Support\Collection;

/**
 * Leituras que sustentam os alertas de frota (Plano 27, Task 27.3): "o que
 * avisar", nunca "avisar".
 *
 * Quem enfileira o aviso pela central de notificações do Plano 14, decide o
 * marco e a cadência de reenvio é `App\Console\Commands\VerificarFrota`. Este
 * Service só devolve os dados, no mesmo critério de `StockAlertService`
 * (Plano 17, Task 17.6).
 *
 * Manutenção: data OU quilometragem, o que vier primeiro
 * ------------------------------------------------------
 * Troca de óleo é "6 meses ou 10 mil quilômetros", e os dois critérios são
 * independentes: nem o tempo prevê a quilometragem nem o contrário, porque o
 * mesmo veículo roda 400 km num mês e 4.000 no seguinte. Por isso
 * `vehicle_maintenances` guarda `proxima_em_data` e `proxima_em_km` separados,
 * e por isso as janelas de alerta são separadas: 30 e 7 dias de um lado, 1.000
 * e 200 km do outro.
 *
 * O que **não** pode acontecer é a mesma manutenção gerar dois avisos
 * simultâneos, um por critério, dizendo a mesma coisa. Quando os dois estão
 * preenchidos, esta classe escolhe **um** critério por passada: o que estiver
 * mais perto de vencer em proporção à própria janela de alerta
 * (`dias_restantes / 30` contra `km_restantes / 1000`). É a tradução literal de
 * "o que vier primeiro dispara". Empate vai para a data, que é o critério que
 * a pessoa consegue conferir no calendário sem depender de o hodômetro estar
 * atualizado.
 *
 * Janelas são cumulativas, e o marco é a janela
 * ----------------------------------------------
 * Mesmo comportamento de `BatchSelectionService::vencendoEm()` (Plano 17): uma
 * manutenção que vence em 5 dias aparece na janela de 30 **e** na de 7, e gera
 * um aviso por marco. Não é repetição: são dois avisos de urgência diferente, e
 * é a chave de idempotência da central (evento + destinatário + registro +
 * marco) que impede o mesmo marco sair duas vezes.
 *
 * Quilometragem desatualizada é problema próprio
 * -----------------------------------------------
 * O alerta por quilometragem compara `proxima_em_km` com `vehicles.km_atual`, e
 * `km_atual` só avança quando alguém registra abastecimento. Veículo sem
 * abastecimento registrado há `DIAS_SEM_ABASTECIMENTO` dias tem `km_atual`
 * velho, e com ele o alerta por quilometragem simplesmente não dispara — em
 * silêncio, que é o pior modo de falhar. Por isso a quilometragem parada tem
 * aviso próprio, em vez de ser tratada como detalhe do alerta de manutenção.
 *
 * Datas
 * -----
 * Todas as comparações são por dia no fuso do negócio, via
 * `App\Support\BusinessDate`, nunca contra `now()` em UTC: um documento que
 * vence hoje não está vencido às 21h de Brasília.
 *
 * Empresa
 * -------
 * Sem consulta própria de tenant: o escopo global dos models resolve pelo
 * tenant corrente, e a rotina itera com `TenantAtual::comTenant()`.
 */
class AlertaDeFrotaService
{
    /**
     * Janelas de alerta de manutenção por data, em dias, da mais distante para
     * a mais urgente.
     *
     * @var array<int, int>
     */
    public const JANELAS_DE_DATA = [30, 7];

    /**
     * Janelas de alerta de manutenção por quilometragem, em quilômetros
     * faltando, da mais distante para a mais urgente.
     *
     * @var array<int, int>
     */
    public const JANELAS_DE_KM = [1000, 200];

    /**
     * Janelas de alerta de validade de documento, em dias.
     *
     * As mesmas 60/30/7 do lote do Plano 17: documento de veículo tem a mesma
     * natureza de prazo legal, e usar outra régua só criaria uma segunda
     * convenção para a mesma coisa.
     *
     * @var array<int, int>
     */
    public const JANELAS_DE_DOCUMENTO = [60, 30, 7];

    /**
     * Dias sem abastecimento registrado a partir dos quais a quilometragem do
     * veículo é considerada desatualizada.
     *
     * Trinta dias: menos que isso acusaria todo veículo reserva e todo veículo
     * de empresa que abastece uma vez por mês; mais que isso deixaria o alerta
     * por quilometragem cego por um período longo demais para uma preventiva
     * de 1.000 km de antecedência.
     */
    public const DIAS_SEM_ABASTECIMENTO = 30;

    /**
     * Critério de alerta pelo calendário.
     */
    public const CRITERIO_DATA = 'data';

    /**
     * Critério de alerta pelo hodômetro.
     */
    public const CRITERIO_KM = 'km';

    /**
     * Manutenções preventivas agendadas que entraram em alguma janela de
     * alerta, uma linha por manutenção e por marco.
     *
     * Só `preventiva` e só `agendada`: corretiva não tem próxima prevista, e
     * manutenção já realizada ou cancelada não tem o que avisar.
     *
     * @return Collection<int, array{
     *     manutencao: VehicleMaintenance,
     *     veiculo: Vehicle,
     *     criterio: string,
     *     janela: int,
     *     dias_para_vencer: ?int,
     *     km_restantes: ?int,
     *     proxima_em_data: ?string,
     *     proxima_em_km: ?int
     * }>
     */
    public function manutencoesAVencer(): Collection
    {
        $hoje = BusinessDate::hoje();

        $manutencoes = VehicleMaintenance::query()
            ->with('vehicle')
            ->where('tipo', 'preventiva')
            ->where('situacao', 'agendada')
            ->where(function ($consulta): void {
                $consulta->whereNotNull('proxima_em_data')->orWhereNotNull('proxima_em_km');
            })
            ->orderBy('id')
            ->get();

        $alertas = collect();

        foreach ($manutencoes as $manutencao) {
            $veiculo = $manutencao->vehicle;

            if ($veiculo === null) {
                continue;
            }

            $dias = $manutencao->proxima_em_data !== null
                ? (int) $hoje->diffInDays(BusinessDate::paraFusoNegocio($manutencao->proxima_em_data)->startOfDay(), false)
                : null;

            $kmRestantes = $manutencao->proxima_em_km !== null
                ? (int) $manutencao->proxima_em_km - (int) $veiculo->km_atual
                : null;

            $criterio = $this->criterioQueVemPrimeiro($dias, $kmRestantes);

            if ($criterio === null) {
                continue;
            }

            $janelas = $criterio === self::CRITERIO_DATA
                ? $this->janelasAlcancadas(self::JANELAS_DE_DATA, $dias)
                : $this->janelasAlcancadas(self::JANELAS_DE_KM, $kmRestantes);

            foreach ($janelas as $janela) {
                $alertas->push([
                    'manutencao' => $manutencao,
                    'veiculo' => $veiculo,
                    'criterio' => $criterio,
                    'janela' => $janela,
                    'dias_para_vencer' => $dias,
                    'km_restantes' => $kmRestantes,
                    'proxima_em_data' => BusinessDate::diaDe($manutencao->proxima_em_data),
                    'proxima_em_km' => $manutencao->proxima_em_km !== null ? (int) $manutencao->proxima_em_km : null,
                ]);
            }
        }

        return $alertas;
    }

    /**
     * Documentos do veículo perto do vencimento, por janela, e os já vencidos.
     *
     * Mesmo formato de `StockAlertService::lotesVencendo()`, para a rotina
     * tratar os dois do mesmo jeito. `dias_para_vencer` vem negativo nos
     * vencidos: é há quantos dias o documento perdeu a validade.
     *
     * @return array{
     *     vencendo: array<int, array{dias: int, itens: Collection<int, array<string, mixed>>}>,
     *     vencidos: Collection<int, array<string, mixed>>
     * }
     */
    public function documentosAVencer(): array
    {
        $hoje = BusinessDate::hoje();

        $vencendo = [];

        foreach (self::JANELAS_DE_DOCUMENTO as $dias) {
            $vencendo[] = [
                'dias' => $dias,
                'itens' => $this->documentosEntre(
                    $hoje->toDateString(),
                    $hoje->addDays($dias)->toDateString()
                ),
            ];
        }

        return [
            'vencendo' => $vencendo,
            'vencidos' => $this->documentosEntre(null, $hoje->subDay()->toDateString()),
        ];
    }

    /**
     * Veículos ativos cuja quilometragem está parada: sem abastecimento
     * registrado há `DIAS_SEM_ABASTECIMENTO` dias ou mais.
     *
     * Veículo que nunca abasteceu conta a partir do cadastro, e não desde
     * sempre: um veículo cadastrado hoje não está com a quilometragem
     * desatualizada, está só começando.
     *
     * Só veículos `ativo`: veículo `inativo` ou parado em `manutencao` não roda,
     * e cobrar quilometragem dele seria ruído garantido.
     *
     * @return Collection<int, array{
     *     veiculo: Vehicle,
     *     dias_sem_abastecimento: int,
     *     ultimo_abastecimento: ?string
     * }>
     */
    public function quilometragemDesatualizada(): Collection
    {
        $hoje = BusinessDate::hoje();

        $veiculos = Vehicle::query()->ativos()->orderBy('id')->get();

        $desatualizados = collect();

        foreach ($veiculos as $veiculo) {
            $ultimo = Refueling::query()
                ->where('vehicle_id', $veiculo->getKey())
                ->orderByDesc('data')
                ->orderByDesc('id')
                ->first();

            $referencia = $ultimo !== null
                ? BusinessDate::paraFusoNegocio($ultimo->data)
                : BusinessDate::paraFusoNegocio($veiculo->created_at);

            if ($referencia === null) {
                continue;
            }

            $dias = (int) $referencia->startOfDay()->diffInDays($hoje, false);

            if ($dias < self::DIAS_SEM_ABASTECIMENTO) {
                continue;
            }

            $desatualizados->push([
                'veiculo' => $veiculo,
                'dias_sem_abastecimento' => $dias,
                'ultimo_abastecimento' => $ultimo !== null ? BusinessDate::diaDe($ultimo->data) : null,
            ]);
        }

        return $desatualizados;
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Qual dos dois critérios vence primeiro, ou `null` quando nenhum dos dois
     * entrou em janela de alerta.
     *
     * A comparação é proporcional à janela mais larga de cada critério (30 dias
     * e 1.000 km): 15 dias restantes valem 0,5, e 500 km restantes também. É
     * assim que se compara tempo com distância sem inventar uma taxa de
     * conversão entre os dois. Empate vai para a data, pelo motivo no cabeçalho
     * da classe.
     */
    private function criterioQueVemPrimeiro(?int $dias, ?int $kmRestantes): ?string
    {
        $janelaDeData = max(self::JANELAS_DE_DATA);
        $janelaDeKm = max(self::JANELAS_DE_KM);

        $dataAlcancada = $dias !== null && $dias <= $janelaDeData;
        $kmAlcancado = $kmRestantes !== null && $kmRestantes <= $janelaDeKm;

        if (! $dataAlcancada && ! $kmAlcancado) {
            return null;
        }

        if (! $kmAlcancado) {
            return self::CRITERIO_DATA;
        }

        if (! $dataAlcancada) {
            return self::CRITERIO_KM;
        }

        return ($dias / $janelaDeData) <= ($kmRestantes / $janelaDeKm)
            ? self::CRITERIO_DATA
            : self::CRITERIO_KM;
    }

    /**
     * Janelas alcançadas pelo valor restante, da mais distante para a mais
     * urgente.
     *
     * Cumulativas de propósito: valor 5 alcança a janela de 30 e a de 7, e cada
     * uma vira um marco próprio. Valor negativo (já vencido, ou quilometragem
     * já ultrapassada) alcança todas.
     *
     * @param  array<int, int>  $janelas
     * @return array<int, int>
     */
    private function janelasAlcancadas(array $janelas, ?int $restante): array
    {
        if ($restante === null) {
            return [];
        }

        return array_values(array_filter(
            $janelas,
            static fn (int $janela): bool => $restante <= $janela
        ));
    }

    /**
     * Documentos com validade dentro do intervalo informado, já com o veículo
     * carregado e os dias até o vencimento calculados.
     *
     * @return Collection<int, array{
     *     documento: VehicleDocument,
     *     veiculo: Vehicle,
     *     validade: string,
     *     dias_para_vencer: int
     * }>
     */
    private function documentosEntre(?string $de, string $ate): Collection
    {
        $hoje = BusinessDate::hoje();

        $consulta = VehicleDocument::query()
            ->with('vehicle')
            ->where('validade', '<=', $ate)
            ->orderBy('validade')
            ->orderBy('id');

        if ($de !== null) {
            $consulta->where('validade', '>=', $de);
        }

        return $consulta->get()
            ->filter(static fn (VehicleDocument $documento): bool => $documento->vehicle !== null)
            ->map(static function (VehicleDocument $documento) use ($hoje): array {
                $validade = BusinessDate::paraFusoNegocio($documento->validade)->startOfDay();

                return [
                    'documento' => $documento,
                    'veiculo' => $documento->vehicle,
                    'validade' => $validade->toDateString(),
                    'dias_para_vencer' => (int) $hoje->diffInDays($validade, false),
                ];
            })
            ->values();
    }
}
