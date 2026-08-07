<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Client;
use App\Models\Company;
use App\Models\Device;
use App\Models\WorkOrder;
use App\Models\WorkOrderDeviceEvent;
use App\Services\Monitoring\PontosCriticosService;
use App\Support\TenantAtual;
use Closure;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Factories\WorkOrderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 21.3 do Plano 21: ranking de pontos críticos, normalizado por visita.
 *
 * Teste descartável, no mesmo espírito de `TendenciaServiceTest` (Task
 * 21.2): valida os cenários mais arriscados da task antes de entregar.
 * Testes formais e mais completos são escopo da Task 21.9.
 */
class PontosCriticosServiceTest extends TestCase
{
    use RefreshDatabase;

    private PontosCriticosService $servico;

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->servico = app(PontosCriticosService::class);
        $this->empresa = Company::query()->firstOrFail();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // (a) e (b) Ranking ordena por média por visita, não por total nem
    // por número de visitas
    // -----------------------------------------------------------------

    public function test_ranking_ordena_por_media_por_visita_e_nao_por_total_ou_por_numero_de_visitas(): void
    {
        $cliente = $this->criarCliente();
        $endereco = $this->criarEndereco($cliente);
        $ordem = $this->criarOrdem($cliente, $endereco);

        // Dispositivo A: 12 visitas, 6 capturas -> média 0.5, total maior.
        $dispositivoA = $this->criarDispositivo($endereco->id, 'Ponto A');
        for ($dia = 1; $dia <= 12; $dia++) {
            $this->criarEvento($ordem, $dispositivoA, sprintf('2026-04-%02d', $dia), $dia <= 6 ? 'Baratas' : null);
        }

        // Dispositivo B: 3 visitas, 3 capturas -> média 1.0, total menor,
        // visitado muito menos vezes.
        $dispositivoB = $this->criarDispositivo($endereco->id, 'Ponto B');
        $this->criarEvento($ordem, $dispositivoB, '2026-04-01', 'Ratos');
        $this->criarEvento($ordem, $dispositivoB, '2026-04-02', 'Ratos');
        $this->criarEvento($ordem, $dispositivoB, '2026-04-03', 'Ratos');

        $resultado = $this->comTenant(fn () => $this->servico->ranking($endereco, '2026-04-01', '2026-04-30'));

        $dispositivos = $resultado['dispositivos'];

        $this->assertSame($dispositivoB->id, $dispositivos[0]['device_id'], 'menos visitado, porém com taxa maior, deveria vir primeiro');
        $this->assertSame($dispositivoA->id, $dispositivos[1]['device_id']);

        $this->assertEqualsWithDelta(1.0, $dispositivos[0]['media_por_visita'], 0.0001);
        $this->assertSame(3, $dispositivos[0]['capturas_total']);
        $this->assertSame(3, $dispositivos[0]['visitas']);

        $this->assertEqualsWithDelta(0.5, $dispositivos[1]['media_por_visita'], 0.0001);
        $this->assertSame(6, $dispositivos[1]['capturas_total'], 'total maior não pode subir o dispositivo no ranking');
        $this->assertSame(12, $dispositivos[1]['visitas']);
    }

    // -----------------------------------------------------------------
    // (c) Tendência compara com o período anterior de mesma duração
    // -----------------------------------------------------------------

    public function test_tendencia_compara_com_periodo_anterior_de_mesma_duracao(): void
    {
        $cliente = $this->criarCliente();
        $endereco = $this->criarEndereco($cliente);
        $dispositivo = $this->criarDispositivo($endereco->id, 'Ponto 01');

        // Período pedido: 10 dias (2026-05-11 a 2026-05-20). O período
        // anterior de mesma duração é 2026-05-01 a 2026-05-10 (10 dias
        // terminando no dia anterior ao início), não o mês anterior nem
        // qualquer outra janela.
        $ordemAnterior = $this->criarOrdem($cliente, $endereco);
        // Anterior: 2 visitas, 0 captura -> média 0.0.
        $this->criarEvento($ordemAnterior, $dispositivo, '2026-05-01', null);
        $this->criarEvento($ordemAnterior, $dispositivo, '2026-05-10', null);

        $ordemAtual = $this->criarOrdem($cliente, $endereco);
        // Atual: 2 visitas, 2 capturas -> média 1.0. Subida clara a partir
        // de zero.
        $this->criarEvento($ordemAtual, $dispositivo, '2026-05-11', 'Formigas');
        $this->criarEvento($ordemAtual, $dispositivo, '2026-05-20', 'Formigas');

        $resultado = $this->comTenant(fn () => $this->servico->ranking($endereco, '2026-05-11', '2026-05-20'));

        $this->assertSame('2026-05-01', $resultado['periodo_anterior']['de']);
        $this->assertSame('2026-05-10', $resultado['periodo_anterior']['ate']);

        $linha = $resultado['dispositivos'][0];
        $this->assertSame(PontosCriticosService::TENDENCIA_SUBINDO, $linha['tendencia']['estado']);
        $this->assertSame(0.0, $linha['tendencia']['media_anterior']);

        // Contraprova: sem nenhuma visita no período anterior, não existe
        // base de comparação, e o estado não pode fingir "estável".
        $enderecoSemHistorico = $this->criarEndereco($cliente);
        $dispositivoNovo = $this->criarDispositivo($enderecoSemHistorico->id, 'Ponto novo');
        $ordemNova = $this->criarOrdem($cliente, $enderecoSemHistorico);
        $this->criarEvento($ordemNova, $dispositivoNovo, '2026-06-15', 'Cupins');

        $resultadoNovo = $this->comTenant(fn () => $this->servico->ranking($enderecoSemHistorico, '2026-06-01', '2026-06-30'));
        $this->assertSame(
            PontosCriticosService::TENDENCIA_SEM_COMPARACAO,
            $resultadoNovo['dispositivos'][0]['tendencia']['estado']
        );
    }

    // -----------------------------------------------------------------
    // (f) Isolamento entre empresas
    // -----------------------------------------------------------------

    public function test_dispositivo_de_outra_empresa_nao_entra_no_ranking(): void
    {
        $cliente = $this->criarCliente();
        $endereco = $this->criarEndereco($cliente);
        $ordem = $this->criarOrdem($cliente, $endereco);
        $dispositivo = $this->criarDispositivo($endereco->id, 'Ponto 01');
        $this->criarEvento($ordem, $dispositivo, '2026-07-10', 'Baratas');

        $outraEmpresa = Company::create([
            'name' => 'Dedetizadora Concorrente Pontos Críticos',
            'cnpj' => '55.555.555/0001-55',
            'email' => 'contato@concorrente-pontos-criticos.test',
        ]);

        TenantAtual::comTenant($outraEmpresa->id, function () {
            $clienteDaOutra = ClientFactory::new()->create();
            $enderecoDaOutra = AddressFactory::new()->create(['client_id' => $clienteDaOutra->id]);
            $ordemDaOutra = WorkOrderFactory::new()->create([
                'client_id' => $clienteDaOutra->id,
                'address_id' => $enderecoDaOutra->id,
            ]);
            $dispositivoDaOutra = Device::create([
                'address_id' => $enderecoDaOutra->id,
                'label' => 'Ponto da concorrente',
                'number' => 'C-'.uniqid(),
                'active' => true,
            ]);

            WorkOrderDeviceEvent::create([
                'work_order_id' => $ordemDaOutra->id,
                'device_id' => $dispositivoDaOutra->id,
                'event_date' => '2026-07-10',
                'pest_found' => 'Baratas',
            ]);
        });

        $resultado = $this->comTenant(fn () => $this->servico->ranking($endereco, '2026-07-01', '2026-07-31'));

        $this->assertCount(1, $resultado['dispositivos'], 'só o dispositivo da própria empresa pode entrar no ranking');
        $this->assertSame($dispositivo->id, $resultado['dispositivos'][0]['device_id']);
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

    private function criarOrdem(Client $cliente, Address $endereco): WorkOrder
    {
        return $this->comTenant(fn () => WorkOrderFactory::new()->create([
            'client_id' => $cliente->id,
            'address_id' => $endereco->id,
        ]));
    }

    private function criarDispositivo(int $addressId, string $rotulo): Device
    {
        return $this->comTenant(fn () => Device::create([
            'address_id' => $addressId,
            'label' => $rotulo,
            'number' => 'N-'.uniqid(),
            'active' => true,
        ]));
    }

    private function criarEvento(WorkOrder $ordem, Device $dispositivo, string $data, ?string $pestFound): WorkOrderDeviceEvent
    {
        return $this->comTenant(fn () => WorkOrderDeviceEvent::create([
            'work_order_id' => $ordem->id,
            'device_id' => $dispositivo->id,
            'event_date' => $data,
            'pest_found' => $pestFound,
        ]));
    }
}
