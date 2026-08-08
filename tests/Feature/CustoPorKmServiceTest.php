<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Receivable;
use App\Models\Refueling;
use App\Models\Route as RouteModel;
use App\Models\RouteStop;
use App\Models\Technician;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Models\WorkOrder;
use App\Services\Financial\WorkOrderMarginService;
use App\Services\Fleet\CustoPorKmService;
use App\Services\Fleet\RateioDeDeslocamentoService;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Closure;
use Database\Factories\WorkOrderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 27.2 do Plano 27: consumo entre tanques cheios, custo por quilômetro e
 * rateio do deslocamento na OS.
 *
 * O que estes cenários protegem, em ordem de gravidade:
 *
 * 1. **Consumo só entre tanques cheios.** Um abastecimento parcial no meio
 *    entra com os litros dele, mas nunca abre nem fecha um intervalo. Se
 *    algum dia alguém "simplificar" isso, o km/l muda sem nenhum erro
 *    estourar, e a margem inteira do módulo passa a mentir.
 * 2. **Amostra pequena não vira medição.** Com 2 intervalos o serviço devolve
 *    o padrão do veículo, marcado como padrão.
 * 3. **A origem da quilometragem sempre acompanha o resultado.**
 */
class CustoPorKmServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-07-01';

    private CustoPorKmService $servico;

    private RateioDeDeslocamentoService $rateio;

    private Company $empresa;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::HOJE.' 12:00:00');
        TenantAtual::limpar();

        $this->servico = app(CustoPorKmService::class);
        $this->rateio = app(RateioDeDeslocamentoService::class);
        $this->empresa = Company::query()->firstOrFail();
        $this->usuario = $this->comTenant(fn () => User::factory()->create(['is_active' => true]));
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Consumo entre tanques cheios
    // -----------------------------------------------------------------

    public function test_consumo_usa_apenas_intervalos_entre_tanques_cheios(): void
    {
        $veiculo = $this->criarVeiculo();

        $this->abastecer($veiculo, '2026-03-01', km: 1000, litros: 40, valor: 200.00);
        $this->abastecer($veiculo, '2026-03-15', km: 1400, litros: 40, valor: 200.00);
        $this->abastecer($veiculo, '2026-04-01', km: 1800, litros: 40, valor: 200.00);
        $this->abastecer($veiculo, '2026-04-15', km: 2200, litros: 40, valor: 200.00);

        $resultado = $this->comTenant(fn () => $this->servico->consumo($veiculo, '2026-01-01', '2026-07-01'));

        $this->assertSame(3, $resultado['intervalos'], '4 tanques cheios fecham 3 intervalos');
        $this->assertSame(1200, $resultado['km_rodados']);
        $this->assertSame(10.0, $resultado['km_por_litro'], '1200 km / 120 litros');
        $this->assertSame('0.5000', $resultado['custo_combustivel_por_km'], 'R$ 600,00 / 1200 km');
        $this->assertTrue($resultado['suficiente']);
    }

    public function test_abastecimento_parcial_no_meio_nao_distorce_o_calculo(): void
    {
        $veiculo = $this->criarVeiculo();

        // Mesma série do cenário anterior, com um parcial de 20 litros no meio
        // do primeiro intervalo: o tanque cheio seguinte recebe só os 20
        // litros que faltavam. O total consumido e o total gasto são
        // exatamente os mesmos, logo o resultado precisa ser idêntico.
        $this->abastecer($veiculo, '2026-03-01', km: 1000, litros: 40, valor: 200.00);
        $this->abastecer($veiculo, '2026-03-08', km: 1200, litros: 20, valor: 100.00, tanqueCheio: false);
        $this->abastecer($veiculo, '2026-03-15', km: 1400, litros: 20, valor: 100.00);
        $this->abastecer($veiculo, '2026-04-01', km: 1800, litros: 40, valor: 200.00);
        $this->abastecer($veiculo, '2026-04-15', km: 2200, litros: 40, valor: 200.00);

        $resultado = $this->comTenant(fn () => $this->servico->consumo($veiculo, '2026-01-01', '2026-07-01'));

        $this->assertSame(3, $resultado['intervalos'], 'o parcial não abre nem fecha intervalo');
        $this->assertSame(1200, $resultado['km_rodados']);
        $this->assertSame(10.0, $resultado['km_por_litro']);
        $this->assertSame('0.5000', $resultado['custo_combustivel_por_km']);
    }

    // -----------------------------------------------------------------
    // Amostra insuficiente
    // -----------------------------------------------------------------

    public function test_com_dois_intervalos_devolve_historico_insuficiente_e_usa_o_custo_padrao(): void
    {
        $veiculo = $this->criarVeiculo(custoKmPadrao: 0.7500);

        $this->abastecer($veiculo, '2026-03-01', km: 1000, litros: 40, valor: 200.00);
        $this->abastecer($veiculo, '2026-03-15', km: 1400, litros: 40, valor: 200.00);
        $this->abastecer($veiculo, '2026-04-01', km: 1800, litros: 40, valor: 200.00);

        $consumo = $this->comTenant(fn () => $this->servico->consumo($veiculo, '2026-01-01', '2026-07-01'));
        $this->assertSame(2, $consumo['intervalos']);
        $this->assertFalse($consumo['suficiente']);

        $custo = $this->comTenant(fn () => $this->servico->custoTotalPorKm($veiculo, '2026-01-01', '2026-07-01'));

        $this->assertSame('0.7500', $custo['total_por_km']);
        $this->assertSame(CustoPorKmService::ORIGEM_PADRAO, $custo['origem']);
        $this->assertSame(CustoPorKmService::MOTIVO_HISTORICO_INSUFICIENTE, $custo['motivo']);
        $this->assertNull($custo['combustivel_por_km'], 'não dá para separar componentes de um valor digitado');
        $this->assertNull($custo['manutencao_por_km']);
    }

    public function test_sem_historico_e_sem_custo_padrao_nao_devolve_custo_por_km(): void
    {
        $veiculo = $this->criarVeiculo(custoKmPadrao: null);

        $custo = $this->comTenant(fn () => $this->servico->custoTotalPorKm($veiculo, '2026-01-01', '2026-07-01'));

        $this->assertNull($custo['total_por_km']);
        $this->assertSame(CustoPorKmService::MOTIVO_SEM_CUSTO_PADRAO, $custo['motivo']);
    }

    // -----------------------------------------------------------------
    // Custo total: combustível separado de manutenção
    // -----------------------------------------------------------------

    public function test_custo_total_separa_combustivel_de_manutencao(): void
    {
        $veiculo = $this->criarVeiculo();

        $this->abastecer($veiculo, '2026-03-01', km: 1000, litros: 40, valor: 200.00);
        $this->abastecer($veiculo, '2026-03-15', km: 1400, litros: 40, valor: 200.00);
        $this->abastecer($veiculo, '2026-04-01', km: 1800, litros: 40, valor: 200.00);
        $this->abastecer($veiculo, '2026-04-15', km: 2200, litros: 40, valor: 200.00);

        $this->comTenant(fn () => VehicleMaintenance::create([
            'vehicle_id' => $veiculo->id,
            'tipo' => 'preventiva',
            'descricao' => 'Troca de óleo',
            'data' => '2026-04-10',
            'km' => 2000,
            'valor' => 120.00,
            'situacao' => 'realizada',
        ]));

        // Manutenção ainda agendada não entra: não gerou custo nenhum.
        $this->comTenant(fn () => VehicleMaintenance::create([
            'vehicle_id' => $veiculo->id,
            'tipo' => 'preventiva',
            'descricao' => 'Revisão futura',
            'proxima_em_data' => '2026-10-10',
            'valor' => 900.00,
            'situacao' => 'agendada',
        ]));

        $custo = $this->comTenant(fn () => $this->servico->custoTotalPorKm($veiculo, '2026-01-01', '2026-07-01'));

        $this->assertSame(CustoPorKmService::ORIGEM_MEDIDO, $custo['origem']);
        $this->assertSame('0.5000', $custo['combustivel_por_km'], 'R$ 600,00 / 1200 km');
        $this->assertSame('0.1000', $custo['manutencao_por_km'], 'R$ 120,00 / 1200 km');
        $this->assertSame('0.6000', $custo['total_por_km']);
        $this->assertSame('600.00', $custo['custo_combustivel']);
        $this->assertSame('120.00', $custo['custo_manutencao']);
    }

    /**
     * Regressão da revisão do Plano 27: a manutenção do fluxo natural — feita e
     * registrada com a próxima já marcada — é a **mesma** linha que gera o
     * alerta (`AlertaDeFrotaService`) e que entra no custo por quilômetro.
     *
     * Antes, os dois lados discordavam: o alerta só olhava para `agendada` e o
     * custo só somava `realizada`, então era impossível ter as duas coisas ao
     * mesmo tempo. Quem queria ser avisado deixava a manutenção como `agendada`
     * e o custo dela sumia do rateio do deslocamento.
     */
    public function test_manutencao_realizada_com_proxima_prevista_entra_no_custo(): void
    {
        $veiculo = $this->criarVeiculoComHistorico();

        $this->comTenant(fn () => VehicleMaintenance::create([
            'vehicle_id' => $veiculo->id,
            'tipo' => 'preventiva',
            'descricao' => 'Troca de óleo feita, próxima em 10.000 km',
            'data' => '2026-04-10',
            'km' => 2000,
            'valor' => 120.00,
            'proxima_em_data' => '2026-10-10',
            'proxima_em_km' => 12000,
            'situacao' => 'realizada',
        ]));

        $custo = $this->comTenant(fn () => $this->servico->custoTotalPorKm($veiculo, '2026-01-01', '2026-07-01'));

        $this->assertSame('0.1000', $custo['manutencao_por_km'], 'R$ 120,00 / 1200 km');
        $this->assertSame('120.00', $custo['custo_manutencao'], 'ter próxima prevista não tira a manutenção do custo');
        $this->assertSame('0.6000', $custo['total_por_km']);
    }

    // -----------------------------------------------------------------
    // Rateio na OS
    // -----------------------------------------------------------------

    public function test_rateio_usa_a_quilometragem_informada_quando_houver(): void
    {
        $veiculo = $this->criarVeiculoComHistorico();
        $os = $this->criarOs(['vehicle_id' => $veiculo->id, 'km_deslocamento' => 20]);

        $resultado = $this->comTenant(fn () => $this->rateio->daOs($os));

        $this->assertTrue($resultado['aplicavel']);
        $this->assertSame(20.0, $resultado['km']);
        $this->assertSame(RateioDeDeslocamentoService::ORIGEM_KM_INFORMADA, $resultado['origem_km']);
        $this->assertSame('0.5000', $resultado['custo_por_km']);
        $this->assertSame('10.00', $resultado['valor'], '20 km x R$ 0,50/km');
        $this->assertFalse($resultado['estimado'], 'km informado e custo medido');
    }

    public function test_rateio_cai_na_quilometragem_estimada_do_roteiro_e_marca_a_origem(): void
    {
        $veiculo = $this->criarVeiculoComHistorico();
        $os = $this->criarOs(['vehicle_id' => $veiculo->id, 'km_deslocamento' => null]);

        $this->criarParadaDeRoteiro($os, distanciaKm: 15.50);

        $resultado = $this->comTenant(fn () => $this->rateio->daOs($os));

        $this->assertTrue($resultado['aplicavel']);
        $this->assertSame(15.5, $resultado['km']);
        $this->assertSame(RateioDeDeslocamentoService::ORIGEM_KM_ESTIMADA, $resultado['origem_km']);
        $this->assertSame('7.75', $resultado['valor'], '15,5 km x R$ 0,50/km');
        $this->assertTrue($resultado['estimado'], 'quilometragem estimada nunca pode parecer medida');
    }

    public function test_rateio_de_os_sem_veiculo_nao_e_aplicavel(): void
    {
        $os = $this->criarOs(['vehicle_id' => null]);

        $resultado = $this->comTenant(fn () => $this->rateio->daOs($os));

        $this->assertFalse($resultado['aplicavel']);
        $this->assertSame(RateioDeDeslocamentoService::MOTIVO_SEM_VEICULO, $resultado['motivo']);
        $this->assertSame('0.00', $resultado['valor']);
    }

    public function test_rateio_com_veiculo_e_sem_quilometragem_informa_o_motivo(): void
    {
        $veiculo = $this->criarVeiculoComHistorico();
        $os = $this->criarOs(['vehicle_id' => $veiculo->id, 'km_deslocamento' => null]);

        $resultado = $this->comTenant(fn () => $this->rateio->daOs($os));

        $this->assertFalse($resultado['aplicavel']);
        $this->assertSame(RateioDeDeslocamentoService::MOTIVO_SEM_QUILOMETRAGEM, $resultado['motivo']);
    }

    public function test_rateio_com_custo_padrao_marca_o_resultado_como_estimado(): void
    {
        // Sem histórico suficiente: o custo por quilômetro vem do padrão do
        // veículo, e o resultado inteiro passa a ser estimado, mesmo com a
        // quilometragem informada.
        $veiculo = $this->criarVeiculo(custoKmPadrao: 0.8000);
        $os = $this->criarOs(['vehicle_id' => $veiculo->id, 'km_deslocamento' => 10]);

        $resultado = $this->comTenant(fn () => $this->rateio->daOs($os));

        $this->assertTrue($resultado['aplicavel']);
        $this->assertSame(CustoPorKmService::ORIGEM_PADRAO, $resultado['origem_custo_km']);
        $this->assertSame('8.00', $resultado['valor']);
        $this->assertTrue($resultado['estimado']);
    }

    // -----------------------------------------------------------------
    // Margem do Plano 18: o fixo vira reserva, e o rateio entra no lugar
    // -----------------------------------------------------------------

    /**
     * Empresa que não controla frota não perde a margem: sem veículo
     * vinculado, o deslocamento continua sendo o fixo por visita do Plano 18,
     * e a margem **não** fica parcial por isso. Não ter frota não é falta de
     * dado.
     */
    public function test_os_sem_veiculo_mantem_o_deslocamento_fixo_do_plano_18(): void
    {
        $os = $this->criarOsComTitulo(1000.00, ['vehicle_id' => null]);

        $margem = $this->comTenant(fn () => app(WorkOrderMarginService::class)->margem($os));

        $this->assertSame('50.00', $margem['custo_deslocamento']);
        $this->assertSame(WorkOrderMarginService::FONTE_DESLOCAMENTO_FIXA, $margem['deslocamento_fonte']);
        $this->assertTrue($margem['deslocamento_e_estimado']);
        $this->assertNotContains(
            WorkOrderMarginService::MOTIVO_DESLOCAMENTO_SEM_QUILOMETRAGEM,
            $margem['motivos_parcial']
        );
    }

    public function test_os_com_veiculo_usa_o_deslocamento_calculado_pela_frota(): void
    {
        $veiculo = $this->criarVeiculoComHistorico();
        $os = $this->criarOsComTitulo(1000.00, ['vehicle_id' => $veiculo->id, 'km_deslocamento' => 40]);

        $margem = $this->comTenant(fn () => app(WorkOrderMarginService::class)->margem($os));

        $this->assertSame(WorkOrderMarginService::FONTE_DESLOCAMENTO_FROTA, $margem['deslocamento_fonte']);
        $this->assertSame('20.00', $margem['custo_deslocamento'], '40 km x R$ 0,50/km');
        $this->assertSame('0.5000', $margem['deslocamento_custo_por_km']);
        $this->assertSame(RateioDeDeslocamentoService::ORIGEM_KM_INFORMADA, $margem['deslocamento_origem_km']);
        $this->assertFalse($margem['deslocamento_e_estimado'], 'km informado e custo medido');
    }

    /**
     * Aqui a falta de dado é real: a empresa optou pela frota, vinculou o
     * veículo e ainda assim o rateio não fechou. O fixo entra como reserva e a
     * margem sai parcial, com o motivo.
     */
    public function test_os_com_veiculo_e_sem_quilometragem_cai_no_fixo_e_marca_a_margem_como_parcial(): void
    {
        $veiculo = $this->criarVeiculoComHistorico();
        $os = $this->criarOsComTitulo(1000.00, ['vehicle_id' => $veiculo->id, 'km_deslocamento' => null]);

        $margem = $this->comTenant(fn () => app(WorkOrderMarginService::class)->margem($os));

        $this->assertSame('50.00', $margem['custo_deslocamento']);
        $this->assertSame(WorkOrderMarginService::FONTE_DESLOCAMENTO_FIXA, $margem['deslocamento_fonte']);
        $this->assertTrue($margem['parcial']);
        $this->assertContains(
            WorkOrderMarginService::MOTIVO_DESLOCAMENTO_SEM_QUILOMETRAGEM,
            $margem['motivos_parcial']
        );
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas
    // -----------------------------------------------------------------

    public function test_abastecimento_de_outra_empresa_nao_entra_no_consumo(): void
    {
        $outraEmpresa = Company::create(['name' => 'Concorrente '.uniqid(), 'active' => true]);

        $veiculo = $this->criarVeiculo();

        $this->abastecer($veiculo, '2026-03-01', km: 1000, litros: 40, valor: 200.00);
        $this->abastecer($veiculo, '2026-03-15', km: 1400, litros: 40, valor: 200.00);
        $this->abastecer($veiculo, '2026-04-01', km: 1800, litros: 40, valor: 200.00);
        $this->abastecer($veiculo, '2026-04-15', km: 2200, litros: 40, valor: 200.00);

        // Linha plantada dentro do tenant da concorrente, apontando para o
        // mesmo veículo: o escopo global precisa recusá-la na leitura.
        TenantAtual::comTenant($outraEmpresa->id, fn () => Refueling::create([
            'vehicle_id' => $veiculo->id,
            'data' => '2026-04-20',
            'km' => 9999,
            'litros' => 1.000,
            'valor_total' => 1000.00,
            'valor_litro' => 1000.0000,
            'tipo_combustivel' => 'gasolina',
            'tanque_cheio' => true,
            'user_id' => $this->usuario->id,
        ]));

        $resultado = $this->comTenant(fn () => $this->servico->consumo($veiculo, '2026-01-01', '2026-07-01'));

        $this->assertSame(3, $resultado['intervalos']);
        $this->assertSame(1200, $resultado['km_rodados']);
        $this->assertSame('0.5000', $resultado['custo_combustivel_por_km']);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function comTenant(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->id, $callback);
    }

    private function criarVeiculo(?float $custoKmPadrao = null): Vehicle
    {
        return $this->comTenant(fn (): Vehicle => Vehicle::create([
            'placa' => strtoupper(substr(uniqid(), -7)),
            'modelo' => 'Saveiro',
            'marca' => 'Volkswagen',
            'ano' => 2022,
            'tipo' => 'utilitario',
            'km_atual' => 1000,
            'situacao' => 'ativo',
            'custo_km_padrao' => $custoKmPadrao,
        ]));
    }

    /**
     * Veículo com 4 tanques cheios (3 intervalos) a R$ 0,50/km, sem
     * manutenção: o custo total por quilômetro é exatamente o do combustível.
     */
    private function criarVeiculoComHistorico(): Vehicle
    {
        $veiculo = $this->criarVeiculo();

        $this->abastecer($veiculo, '2026-03-01', km: 1000, litros: 40, valor: 200.00);
        $this->abastecer($veiculo, '2026-03-15', km: 1400, litros: 40, valor: 200.00);
        $this->abastecer($veiculo, '2026-04-01', km: 1800, litros: 40, valor: 200.00);
        $this->abastecer($veiculo, '2026-04-15', km: 2200, litros: 40, valor: 200.00);

        return $veiculo;
    }

    private function abastecer(
        Vehicle $veiculo,
        string $data,
        int $km,
        float $litros,
        float $valor,
        bool $tanqueCheio = true,
    ): Refueling {
        return $this->comTenant(fn (): Refueling => Refueling::create([
            'vehicle_id' => $veiculo->id,
            'data' => $data,
            'km' => $km,
            'litros' => $litros,
            'valor_total' => $valor,
            'valor_litro' => round($valor / $litros, 4),
            'tipo_combustivel' => 'gasolina',
            'posto' => 'Posto de teste',
            'tanque_cheio' => $tanqueCheio,
            'user_id' => $this->usuario->id,
        ]));
    }

    /**
     * OS com título a receber gerado, para a margem do Plano 18 ter receita.
     * Uma hora de execução e técnico com custo/hora, para os demais
     * componentes não marcarem a margem como parcial por conta própria.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function criarOsComTitulo(float $valorDoTitulo, array $atributos): WorkOrder
    {
        return $this->comTenant(function () use ($valorDoTitulo, $atributos): WorkOrder {
            $tecnico = Technician::create([
                'name' => 'Técnico da margem',
                'email' => 'margem'.uniqid().'@dedetizadora.test',
                'phone' => '11999990000',
                'is_active' => true,
                'custo_hora' => 40.00,
            ]);

            $os = WorkOrderFactory::new()->create(array_merge([
                'technician_id' => $tecnico->id,
                'scheduled_date' => self::HOJE,
                'start_time' => self::HOJE.' 08:00:00',
                'end_time' => self::HOJE.' 09:00:00',
                'final_amount' => $valorDoTitulo,
            ], $atributos));

            Receivable::create([
                'client_id' => $os->client_id,
                'work_order_id' => $os->id,
                'descricao' => "Ordem de serviço {$os->order_number}",
                'valor_total' => $valorDoTitulo,
                'emitido_em' => self::HOJE,
                'situacao' => 'aberto',
            ]);

            return $os;
        });
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function criarOs(array $atributos): WorkOrder
    {
        return $this->comTenant(fn (): WorkOrder => WorkOrderFactory::new()->create(array_merge([
            'scheduled_date' => self::HOJE,
            'start_time' => self::HOJE.' 08:00:00',
            'end_time' => self::HOJE.' 09:00:00',
        ], $atributos)));
    }

    private function criarParadaDeRoteiro(WorkOrder $os, float $distanciaKm): RouteStop
    {
        return $this->comTenant(function () use ($os, $distanciaKm): RouteStop {
            $tecnico = Technician::create([
                'name' => 'Técnico de teste',
                'email' => 'tecnico'.uniqid().'@dedetizadora.test',
                'phone' => '11999990000',
                'is_active' => true,
            ]);

            $roteiro = RouteModel::create([
                'technician_id' => $tecnico->id,
                'data' => self::HOJE,
                'situacao' => 'planejada',
            ]);

            return RouteStop::create([
                'route_id' => $roteiro->id,
                'work_order_id' => $os->id,
                'ordem' => 1,
                'distancia_anterior_km' => $distanciaKm,
            ]);
        });
    }
}
