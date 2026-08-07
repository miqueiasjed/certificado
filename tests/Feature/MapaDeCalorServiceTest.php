<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Client;
use App\Models\Company;
use App\Models\Device;
use App\Models\DevicePosition;
use App\Models\FloorPlan;
use App\Models\WorkOrderDeviceEvent;
use App\Services\Monitoring\MapaDeCalorService;
use App\Support\TenantAtual;
use Closure;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Factories\WorkOrderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 21.3 do Plano 21: mapa de calor por posição na planta.
 *
 * `porComodo` não tem cenário de sucesso porque, hoje, a task não é
 * implementável com dado estruturado (ver o cabeçalho de
 * `MapaDeCalorService`): o teste cobre exatamente essa decisão, não um
 * comportamento que ainda não existe.
 *
 * Teste descartável, no mesmo espírito de `TendenciaServiceTest` e
 * `FloorPlanServiceTest`. Testes formais são escopo da Task 21.9.
 */
class MapaDeCalorServiceTest extends TestCase
{
    use RefreshDatabase;

    private MapaDeCalorService $servico;

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->servico = app(MapaDeCalorService::class);
        $this->empresa = Company::query()->firstOrFail();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // porComodo: documentadamente não suportado, sem inventar dado
    // -----------------------------------------------------------------

    public function test_por_comodo_devolve_nao_suportado_com_motivo_explicito(): void
    {
        $cliente = $this->criarCliente();
        $endereco = $this->criarEndereco($cliente);

        $resultado = $this->comTenant(fn () => $this->servico->porComodo($endereco, '2026-04-01', '2026-04-30'));

        $this->assertFalse($resultado['suportado']);
        $this->assertSame([], $resultado['comodos']);
        $this->assertNotEmpty($resultado['motivo_nao_suportado']);
    }

    // -----------------------------------------------------------------
    // (d) porPosicao devolve intensidade normalizada E valor absoluto
    // -----------------------------------------------------------------

    public function test_por_posicao_normaliza_intensidade_sobre_o_maior_valor_do_periodo_e_mantem_o_absoluto(): void
    {
        [$endereco, $planta, $dispositivoQuente, $dispositivoFrio] = $this->comTenant(function () {
            $cliente = ClientFactory::new()->create();
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);

            $planta = FloorPlan::create([
                'address_id' => $endereco->id,
                'versao' => 1,
                'nome' => 'Térreo',
                'arquivo_path' => 'floor-plans/teste.png',
                'largura_px' => 800,
                'altura_px' => 600,
                'ativa' => true,
            ]);

            $dispositivoQuente = Device::create([
                'address_id' => $endereco->id,
                'label' => 'Quente',
                'number' => 'N-'.uniqid(),
                'active' => true,
            ]);
            $dispositivoFrio = Device::create([
                'address_id' => $endereco->id,
                'label' => 'Frio',
                'number' => 'N-'.uniqid(),
                'active' => true,
            ]);

            DevicePosition::create(['floor_plan_id' => $planta->id, 'device_id' => $dispositivoQuente->id, 'x' => 0.20, 'y' => 0.30]);
            DevicePosition::create(['floor_plan_id' => $planta->id, 'device_id' => $dispositivoFrio->id, 'x' => 0.70, 'y' => 0.80]);

            $ordem = WorkOrderFactory::new()->create(['client_id' => $cliente->id, 'address_id' => $endereco->id]);

            // Dispositivo quente: 4 capturas no período -> vira o máximo.
            foreach (['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04'] as $data) {
                WorkOrderDeviceEvent::create([
                    'work_order_id' => $ordem->id,
                    'device_id' => $dispositivoQuente->id,
                    'event_date' => $data,
                    'pest_found' => 'Baratas',
                ]);
            }

            // Dispositivo frio: 2 capturas no período -> metade do máximo.
            foreach (['2026-08-01', '2026-08-02'] as $data) {
                WorkOrderDeviceEvent::create([
                    'work_order_id' => $ordem->id,
                    'device_id' => $dispositivoFrio->id,
                    'event_date' => $data,
                    'pest_found' => 'Baratas',
                ]);
            }

            return [$endereco, $planta, $dispositivoQuente, $dispositivoFrio];
        });

        $resultado = $this->comTenant(fn () => $this->servico->porPosicao($endereco, '2026-08-01', '2026-08-31'));

        $this->assertTrue($resultado['suportado']);
        $this->assertSame($planta->id, $resultado['planta']['floor_plan_id']);
        $this->assertSame(4, $resultado['escala_absoluta_maxima']);

        $pontos = collect($resultado['pontos'])->keyBy('device_id');

        $pontoQuente = $pontos[$dispositivoQuente->id];
        $this->assertSame(4, $pontoQuente['valor_absoluto']);
        $this->assertEqualsWithDelta(1.0, $pontoQuente['intensidade'], 0.0001, 'o ponto no máximo do período tem que ter intensidade 1.0');

        $pontoFrio = $pontos[$dispositivoFrio->id];
        $this->assertSame(2, $pontoFrio['valor_absoluto']);
        $this->assertEqualsWithDelta(0.5, $pontoFrio['intensidade'], 0.0001, 'metade do valor absoluto máximo tem que ser intensidade 0.5');
    }

    public function test_por_posicao_sem_planta_ativa_devolve_nao_suportado_sem_quebrar(): void
    {
        $cliente = $this->criarCliente();
        $endereco = $this->criarEndereco($cliente);

        $resultado = $this->comTenant(fn () => $this->servico->porPosicao($endereco, '2026-04-01', '2026-04-30'));

        $this->assertFalse($resultado['suportado']);
        $this->assertNull($resultado['planta']);
        $this->assertSame([], $resultado['pontos']);
        $this->assertSame(0, $resultado['escala_absoluta_maxima']);
    }

    // -----------------------------------------------------------------
    // (f) Isolamento entre empresas
    // -----------------------------------------------------------------

    public function test_captura_de_dispositivo_de_outra_empresa_nao_entra_no_mapa_de_calor(): void
    {
        [$endereco, $planta, $dispositivo] = $this->comTenant(function () {
            $cliente = ClientFactory::new()->create();
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);
            $planta = FloorPlan::create([
                'address_id' => $endereco->id,
                'versao' => 1,
                'nome' => 'Térreo',
                'arquivo_path' => 'floor-plans/teste.png',
                'largura_px' => 800,
                'altura_px' => 600,
                'ativa' => true,
            ]);
            $dispositivo = Device::create([
                'address_id' => $endereco->id,
                'label' => 'Ponto 01',
                'number' => 'N-'.uniqid(),
                'active' => true,
            ]);
            DevicePosition::create(['floor_plan_id' => $planta->id, 'device_id' => $dispositivo->id, 'x' => 0.5, 'y' => 0.5]);

            $ordem = WorkOrderFactory::new()->create(['client_id' => $cliente->id, 'address_id' => $endereco->id]);
            WorkOrderDeviceEvent::create([
                'work_order_id' => $ordem->id,
                'device_id' => $dispositivo->id,
                'event_date' => '2026-09-05',
                'pest_found' => 'Ratos',
            ]);

            return [$endereco, $planta, $dispositivo];
        });

        $outraEmpresa = Company::create([
            'name' => 'Dedetizadora Concorrente Mapa de Calor',
            'cnpj' => '66.666.666/0001-66',
            'email' => 'contato@concorrente-mapa-calor.test',
        ]);

        TenantAtual::comTenant($outraEmpresa->id, function () {
            $clienteDaOutra = ClientFactory::new()->create();
            $enderecoDaOutra = AddressFactory::new()->create(['client_id' => $clienteDaOutra->id]);
            $dispositivoDaOutra = Device::create([
                'address_id' => $enderecoDaOutra->id,
                'label' => 'Ponto da concorrente',
                'number' => 'C-'.uniqid(),
                'active' => true,
            ]);
            $ordemDaOutra = WorkOrderFactory::new()->create([
                'client_id' => $clienteDaOutra->id,
                'address_id' => $enderecoDaOutra->id,
            ]);

            // Mesma data, mesmo dia de captura, empresa diferente: se o
            // isolamento vazar, esta captura infla o máximo do período.
            for ($i = 0; $i < 10; $i++) {
                WorkOrderDeviceEvent::create([
                    'work_order_id' => $ordemDaOutra->id,
                    'device_id' => $dispositivoDaOutra->id,
                    'event_date' => '2026-09-05',
                    'pest_found' => 'Ratos',
                ]);
            }
        });

        $resultado = $this->comTenant(fn () => $this->servico->porPosicao($endereco, '2026-09-01', '2026-09-30'));

        $this->assertSame(1, $resultado['escala_absoluta_maxima'], 'a captura da outra empresa não pode inflar o máximo do período');
        $this->assertSame(1, $resultado['pontos'][0]['valor_absoluto']);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function comTenant(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->id, $callback);
    }

    private function criarCliente(): Client
    {
        return $this->comTenant(fn () => ClientFactory::new()->create());
    }

    private function criarEndereco(Client $cliente): Address
    {
        return $this->comTenant(fn () => AddressFactory::new()->create(['client_id' => $cliente->id]));
    }
}
