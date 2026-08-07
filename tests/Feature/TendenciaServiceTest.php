<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Client;
use App\Models\Company;
use App\Models\Device;
use App\Models\WorkOrder;
use App\Models\WorkOrderDeviceEvent;
use App\Services\Monitoring\TendenciaService;
use App\Support\TenantAtual;
use Closure;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Factories\WorkOrderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 21.2 do Plano 21: evolução por dispositivo e comparação entre
 * períodos.
 *
 * Teste descartável, escrito para validar manualmente os quatro cenários
 * mais arriscados da task antes de entregar (sem visita distinto de zero,
 * granularidade semana/mês, variação a partir de zero e isolamento entre
 * empresas). Testes formais e mais completos são escopo da Task 21.9.
 */
class TendenciaServiceTest extends TestCase
{
    use RefreshDatabase;

    private TendenciaService $servico;

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->servico = app(TendenciaService::class);
        $this->empresa = Company::query()->firstOrFail();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // (a) Evolução por dispositivo, por semana e por mês
    // -----------------------------------------------------------------

    public function test_evolucao_por_dispositivo_agrupa_corretamente_por_semana_e_por_mes(): void
    {
        $cliente = $this->criarCliente();
        $endereco = $this->criarEndereco($cliente);
        $ordem = $this->criarOrdem($cliente, $endereco);
        $dispositivo = $this->criarDispositivo($endereco->id, 'Ponto 01');

        // Semana ISO 2026-W01 (29/12/2025 a 04/01/2026): duas capturas.
        $this->criarEvento($ordem, $dispositivo, '2025-12-30', 'partial', 'Baratas');
        $this->criarEvento($ordem, $dispositivo, '2026-01-02', 'total', 'Baratas');
        // Semana ISO 2026-W05 (26/01 a 01/02/2026): uma captura, mesmo mês
        // (janeiro) do primeiro evento de 2026 acima só que outra semana.
        $this->criarEvento($ordem, $dispositivo, '2026-01-28', 'none', null);

        $porSemana = $this->comTenant(fn () => $this->servico->evolucaoPorDispositivo(
            $endereco,
            '2025-12-29',
            '2026-02-01',
            TendenciaService::GRANULARIDADE_SEMANA
        ));

        $serieSemanal = collect($porSemana['dispositivos'][0]['serie'])->keyBy('chave');

        $this->assertSame(2, $serieSemanal['2026-W01']['visitas']);
        $this->assertSame(2, $serieSemanal['2026-W01']['capturas']);
        $this->assertFalse($serieSemanal['2026-W01']['sem_visita']);

        $this->assertSame(1, $serieSemanal['2026-W05']['visitas']);
        $this->assertSame(0, $serieSemanal['2026-W05']['capturas']);
        $this->assertFalse($serieSemanal['2026-W05']['sem_visita']);

        $porMes = $this->comTenant(fn () => $this->servico->evolucaoPorDispositivo(
            $endereco,
            '2025-12-29',
            '2026-02-01',
            TendenciaService::GRANULARIDADE_MES
        ));

        $serieMensal = collect($porMes['dispositivos'][0]['serie'])->keyBy('chave');

        $this->assertSame(1, $serieMensal['2025-12']['visitas']);
        $this->assertSame(1, $serieMensal['2025-12']['capturas']);

        $this->assertSame(2, $serieMensal['2026-01']['visitas']);
        $this->assertSame(1, $serieMensal['2026-01']['capturas']);
    }

    // -----------------------------------------------------------------
    // (b) Período sem visita é distinto de zero ocorrência
    // -----------------------------------------------------------------

    public function test_periodo_sem_visita_aparece_como_sem_dado_distinto_de_zero_ocorrencia(): void
    {
        $cliente = $this->criarCliente();
        $endereco = $this->criarEndereco($cliente);
        $ordem = $this->criarOrdem($cliente, $endereco);
        $dispositivo = $this->criarDispositivo($endereco->id, 'Ponto 01');

        // Visitado na semana 1, sem nenhuma captura e sem consumo: zero
        // ocorrência de verdade, dispositivo limpo.
        $this->criarEvento($ordem, $dispositivo, '2026-03-02', null, null);
        // Nenhum evento na semana seguinte: sem visita, não zero.

        $resultado = $this->comTenant(fn () => $this->servico->evolucaoPorDispositivo(
            $endereco,
            '2026-03-02',
            '2026-03-15',
            TendenciaService::GRANULARIDADE_SEMANA
        ));

        $serie = collect($resultado['dispositivos'][0]['serie'])->keyBy('chave');

        $semanaVisitada = $serie['2026-W10'];
        $this->assertFalse($semanaVisitada['sem_visita']);
        $this->assertSame(0, $semanaVisitada['capturas'], 'visitado sem ocorrência tem que ser 0, não null');
        $this->assertSame([], $semanaVisitada['consumo_de_isca']);

        $semanaSemVisita = $serie['2026-W11'];
        $this->assertTrue($semanaSemVisita['sem_visita']);
        $this->assertNull($semanaSemVisita['capturas'], 'sem visita tem que ser null, não 0');
        $this->assertNull($semanaSemVisita['consumo_de_isca']);
        $this->assertSame(0, $semanaSemVisita['visitas']);
    }

    // -----------------------------------------------------------------
    // (c) Comparação com variação a partir de zero em números absolutos
    // -----------------------------------------------------------------

    public function test_comparar_reporta_variacao_a_partir_de_zero_em_numeros_absolutos(): void
    {
        $cliente = $this->criarCliente();
        $endereco = $this->criarEndereco($cliente);
        $ordem = $this->criarOrdem($cliente, $endereco);
        $dispositivo = $this->criarDispositivo($endereco->id, 'Ponto 01');

        // Período A: nenhum evento (dispositivo existe, mas não foi
        // visitado, ou foi e não houve ocorrência - tanto faz para o total).
        // Período B: três capturas.
        $this->criarEvento($ordem, $dispositivo, '2026-05-05', 'total', 'Ratos');
        $this->criarEvento($ordem, $dispositivo, '2026-05-06', 'total', 'Ratos');
        $this->criarEvento($ordem, $dispositivo, '2026-05-07', 'total', 'Ratos');

        $resultado = $this->comTenant(fn () => $this->servico->comparar(
            $endereco,
            ['de' => '2026-04-01', 'ate' => '2026-04-30'],
            ['de' => '2026-05-01', 'ate' => '2026-05-31'],
        ));

        $capturasDoDispositivo = $resultado['por_dispositivo'][0]['capturas'];

        $this->assertSame(0, $capturasDoDispositivo['de']);
        $this->assertSame(3, $capturasDoDispositivo['para']);
        $this->assertNull($capturasDoDispositivo['percentual'], 'variação a partir de zero não pode virar percentual infinito');
        $this->assertTrue($capturasDoDispositivo['a_partir_de_zero']);

        $capturasDoEndereco = $resultado['total_endereco']['capturas'];
        $this->assertSame(0, $capturasDoEndereco['de']);
        $this->assertSame(3, $capturasDoEndereco['para']);
        $this->assertNull($capturasDoEndereco['percentual']);

        // Contraprova: quando o período A não é zero, o percentual normal é
        // calculado, mesmo quando não há variação nenhuma (aqui, maio e junho
        // têm 3 capturas cada, então 0%, e não "a_partir_de_zero"). O cenário
        // com variação percentual real (subida, queda e arredondamento) está
        // em test_comparar_calcula_a_variacao_percentual_correta_entre_dois_periodos_com_visita(),
        // logo abaixo.
        $ordem2 = $this->criarOrdem($cliente, $endereco);
        $this->criarEvento($ordem2, $dispositivo, '2026-06-01', 'total', 'Ratos');
        $this->criarEvento($ordem2, $dispositivo, '2026-06-02', 'total', 'Ratos');
        $this->criarEvento($ordem2, $dispositivo, '2026-06-03', 'total', 'Ratos');

        $resultadoNormal = $this->comTenant(fn () => $this->servico->comparar(
            $endereco,
            ['de' => '2026-05-01', 'ate' => '2026-05-31'],
            ['de' => '2026-06-01', 'ate' => '2026-06-30'],
        ));

        $capturasNormal = $resultadoNormal['por_dispositivo'][0]['capturas'];
        $this->assertSame(3, $capturasNormal['de']);
        $this->assertSame(3, $capturasNormal['para']);
        $this->assertSame(0.0, $capturasNormal['percentual']);
        $this->assertFalse($capturasNormal['a_partir_de_zero']);
    }

    // -----------------------------------------------------------------
    // (c, reforço) Comparação calcula a variação percentual certa: subida,
    // queda, e o total do endereço combinando mais de um dispositivo com
    // arredondamento em duas casas.
    //
    // Task 21.9: o teste acima (com "a_partir_de_zero") só exercitava 0% e o
    // caso de partir de zero, nunca uma variação percentual de verdade. Este
    // teste fecha essa lacuna com números que só fecham se
    // `calcularVariacao()` estiver certo: 5→6 visitas (+20%), 4→6 capturas
    // (+50%), 2→4 visitas (+100%), 2→1 capturas (-50%), e os totais do
    // endereço 7→10 visitas (+42.86%) e 6→7 capturas (+16.67%).
    // -----------------------------------------------------------------

    public function test_comparar_calcula_a_variacao_percentual_correta_entre_dois_periodos_com_visita(): void
    {
        $cliente = $this->criarCliente();
        $endereco = $this->criarEndereco($cliente);

        $dispositivoX = $this->criarDispositivo($endereco->id, 'Ponto X');
        $dispositivoY = $this->criarDispositivo($endereco->id, 'Ponto Y');

        $ordemA = $this->criarOrdem($cliente, $endereco);
        // Dispositivo X, período A (agosto): 5 visitas, 4 capturas.
        $this->criarEvento($ordemA, $dispositivoX, '2026-08-01', 'total', 'Baratas');
        $this->criarEvento($ordemA, $dispositivoX, '2026-08-08', 'total', 'Baratas');
        $this->criarEvento($ordemA, $dispositivoX, '2026-08-15', 'total', 'Baratas');
        $this->criarEvento($ordemA, $dispositivoX, '2026-08-22', 'total', 'Baratas');
        $this->criarEvento($ordemA, $dispositivoX, '2026-08-29', null, null);
        // Dispositivo Y, período A: 2 visitas, 2 capturas.
        $this->criarEvento($ordemA, $dispositivoY, '2026-08-05', 'total', 'Ratos');
        $this->criarEvento($ordemA, $dispositivoY, '2026-08-20', 'total', 'Ratos');

        $ordemB = $this->criarOrdem($cliente, $endereco);
        // Dispositivo X, período B (setembro): 6 visitas, 6 capturas.
        foreach (['2026-09-01', '2026-09-06', '2026-09-11', '2026-09-16', '2026-09-21', '2026-09-26'] as $data) {
            $this->criarEvento($ordemB, $dispositivoX, $data, 'total', 'Baratas');
        }
        // Dispositivo Y, período B: 4 visitas, 1 captura.
        $this->criarEvento($ordemB, $dispositivoY, '2026-09-02', 'total', 'Ratos');
        $this->criarEvento($ordemB, $dispositivoY, '2026-09-10', null, null);
        $this->criarEvento($ordemB, $dispositivoY, '2026-09-18', null, null);
        $this->criarEvento($ordemB, $dispositivoY, '2026-09-26', null, null);

        $resultado = $this->comTenant(fn () => $this->servico->comparar(
            $endereco,
            ['de' => '2026-08-01', 'ate' => '2026-08-31'],
            ['de' => '2026-09-01', 'ate' => '2026-09-30'],
        ));

        $porDispositivo = collect($resultado['por_dispositivo'])->keyBy('device_id');

        $linhaX = $porDispositivo[$dispositivoX->id];
        $this->assertSame(5, $linhaX['visitas']['de']);
        $this->assertSame(6, $linhaX['visitas']['para']);
        $this->assertEqualsWithDelta(20.0, $linhaX['visitas']['percentual'], 0.001, 'visitas do ponto X deveriam subir 20%');
        $this->assertSame(4, $linhaX['capturas']['de']);
        $this->assertSame(6, $linhaX['capturas']['para']);
        $this->assertEqualsWithDelta(50.0, $linhaX['capturas']['percentual'], 0.001, 'capturas do ponto X deveriam subir 50%');

        $linhaY = $porDispositivo[$dispositivoY->id];
        $this->assertSame(2, $linhaY['visitas']['de']);
        $this->assertSame(4, $linhaY['visitas']['para']);
        $this->assertEqualsWithDelta(100.0, $linhaY['visitas']['percentual'], 0.001, 'visitas do ponto Y deveriam dobrar');
        $this->assertSame(2, $linhaY['capturas']['de']);
        $this->assertSame(1, $linhaY['capturas']['para']);
        $this->assertEqualsWithDelta(-50.0, $linhaY['capturas']['percentual'], 0.001, 'capturas do ponto Y deveriam cair 50%');

        // Total do endereço soma os dois dispositivos, e o percentual reflete
        // a soma (não a média das duas linhas): 7 → 10 visitas (+42.86%) e
        // 6 → 7 capturas (+16.67%), arredondado em duas casas.
        $totalVisitas = $resultado['total_endereco']['visitas'];
        $this->assertSame(7, $totalVisitas['de']);
        $this->assertSame(10, $totalVisitas['para']);
        $this->assertEqualsWithDelta(42.86, $totalVisitas['percentual'], 0.001);

        $totalCapturas = $resultado['total_endereco']['capturas'];
        $this->assertSame(6, $totalCapturas['de']);
        $this->assertSame(7, $totalCapturas['para']);
        $this->assertEqualsWithDelta(16.67, $totalCapturas['percentual'], 0.001);
    }

    // -----------------------------------------------------------------
    // (d) Isolamento entre empresas
    // -----------------------------------------------------------------

    public function test_evento_de_outra_empresa_nao_entra_na_evolucao_nem_na_comparacao(): void
    {
        $cliente = $this->criarCliente();
        $endereco = $this->criarEndereco($cliente);
        $ordem = $this->criarOrdem($cliente, $endereco);
        $dispositivo = $this->criarDispositivo($endereco->id, 'Ponto 01');

        $this->criarEvento($ordem, $dispositivo, '2026-07-10', 'total', 'Baratas');

        $outraEmpresa = Company::create([
            'name' => 'Dedetizadora Concorrente',
            'cnpj' => '33.333.333/0001-33',
            'email' => 'contato@concorrente-monitoramento.test',
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
                'bait_consumption_status' => 'total',
                'pest_found' => 'Baratas',
            ]);
        });

        $resultado = $this->comTenant(fn () => $this->servico->evolucaoPorDispositivo(
            $endereco,
            '2026-07-01',
            '2026-07-31',
            TendenciaService::GRANULARIDADE_MES
        ));

        $this->assertCount(1, $resultado['dispositivos'], 'só o dispositivo da própria empresa pode aparecer');
        $this->assertSame($dispositivo->id, $resultado['dispositivos'][0]['device_id']);

        $serie = collect($resultado['dispositivos'][0]['serie'])->keyBy('chave');
        $this->assertSame(1, $serie['2026-07']['capturas'], 'a captura da outra empresa não pode somar aqui');

        // A comparação, pelo mesmo motivo, não pode contar a captura da
        // outra empresa no total do endereço.
        $comparacao = $this->comTenant(fn () => $this->servico->comparar(
            $endereco,
            ['de' => '2026-06-01', 'ate' => '2026-06-30'],
            ['de' => '2026-07-01', 'ate' => '2026-07-31'],
        ));

        $this->assertSame(1, $comparacao['total_endereco']['capturas']['para'], 'vazou captura de outra empresa para o total do endereço');
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

    private function criarEvento(
        WorkOrder $ordem,
        Device $dispositivo,
        string $data,
        ?string $baitConsumptionStatus,
        ?string $pestFound
    ): WorkOrderDeviceEvent {
        return $this->comTenant(fn () => WorkOrderDeviceEvent::create([
            'work_order_id' => $ordem->id,
            'device_id' => $dispositivo->id,
            'event_date' => $data,
            'bait_consumption_status' => $baitConsumptionStatus,
            'pest_found' => $pestFound,
        ]));
    }
}
