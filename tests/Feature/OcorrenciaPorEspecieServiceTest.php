<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Client;
use App\Models\Company;
use App\Models\PestSighting;
use App\Models\WorkOrder;
use App\Services\Monitoring\OcorrenciaPorEspecieService;
use App\Support\TenantAtual;
use Closure;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Factories\WorkOrderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 21.3 do Plano 21: ocorrência por espécie, evolução entre períodos e
 * distribuição por área.
 *
 * Teste descartável, no mesmo espírito de `TendenciaServiceTest`. Testes
 * formais são escopo da Task 21.9.
 */
class OcorrenciaPorEspecieServiceTest extends TestCase
{
    use RefreshDatabase;

    private OcorrenciaPorEspecieService $servico;

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->servico = app(OcorrenciaPorEspecieService::class);
        $this->empresa = Company::query()->firstOrFail();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // (e) Evolução entre períodos e distribuição por área
    // -----------------------------------------------------------------

    public function test_traz_evolucao_entre_periodos_e_distribuicao_por_area(): void
    {
        $cliente = $this->criarCliente();
        $endereco = $this->criarEndereco($cliente);
        $ordem = $this->criarOrdem($cliente, $endereco);

        // Período anterior (2026-09-01 a 2026-09-10, mesma duração de 10
        // dias do período pedido): 1 avistamento de barata.
        $this->criarAvistamento($endereco, $ordem, '2026-09-05 10:00:00', 'cockroaches');

        // Período atual (2026-09-11 a 2026-09-20): 3 baratas e 1 rato.
        $this->criarAvistamento($endereco, $ordem, '2026-09-11 08:00:00', 'cockroaches');
        $this->criarAvistamento($endereco, $ordem, '2026-09-15 08:00:00', 'cockroaches');
        $this->criarAvistamento($endereco, $ordem, '2026-09-20 23:59:00', 'cockroaches');
        $this->criarAvistamento($endereco, $ordem, '2026-09-12 08:00:00', 'rats');

        $resultado = $this->comTenant(fn () => $this->servico->porPeriodo($endereco, '2026-09-11', '2026-09-20'));

        $this->assertSame('2026-09-01', $resultado['periodo_anterior']['de']);
        $this->assertSame('2026-09-10', $resultado['periodo_anterior']['ate']);

        $evolucao = collect($resultado['evolucao_por_especie'])->keyBy('pest_type');

        $this->assertSame(1, $evolucao['cockroaches']['de']);
        $this->assertSame(3, $evolucao['cockroaches']['para']);
        $this->assertEqualsWithDelta(200.0, $evolucao['cockroaches']['percentual'], 0.01);

        $this->assertSame(0, $evolucao['rats']['de']);
        $this->assertSame(1, $evolucao['rats']['para']);
        $this->assertNull($evolucao['rats']['percentual'], 'variação a partir de zero não vira percentual infinito');
        $this->assertTrue($evolucao['rats']['a_partir_de_zero']);

        // Espécie sem nenhum avistamento em nenhum período continua
        // presente, com os dois lados zerados: vocabulário fixo.
        $this->assertSame(0, $evolucao['termites']['de']);
        $this->assertSame(0, $evolucao['termites']['para']);
        $this->assertCount(13, $evolucao, 'as 13 espécies do enum têm que aparecer sempre');

        $area = $resultado['distribuicao_por_area'][0];
        $this->assertSame($endereco->id, $area['address_id']);
        $this->assertSame(4, $area['total']);

        $porEspecieDaArea = collect($area['por_especie'])->keyBy('pest_type');
        $this->assertSame(3, $porEspecieDaArea['cockroaches']['quantidade']);
        $this->assertSame(1, $porEspecieDaArea['rats']['quantidade']);
    }

    // -----------------------------------------------------------------
    // Avistamento perto da virada do dia não escorrega para o dia errado
    // -----------------------------------------------------------------

    public function test_avistamento_perto_da_meia_noite_conta_no_dia_certo_do_fuso_do_negocio(): void
    {
        $cliente = $this->criarCliente();
        $endereco = $this->criarEndereco($cliente);
        $ordem = $this->criarOrdem($cliente, $endereco);

        // 23h59 de 10/09 em America/Sao_Paulo é madrugada de 11/09 em UTC.
        // Gravado como instante local (sem sufixo de fuso, a mesma
        // convenção que `AplicadorDeAvistamento` já usa), tem que continuar
        // contando no período que termina em 10/09, não vazar para o
        // período seguinte.
        $this->criarAvistamento($endereco, $ordem, '2026-09-10 23:59:00', 'ants');

        $resultado = $this->comTenant(fn () => $this->servico->porPeriodo($endereco, '2026-09-01', '2026-09-10'));

        $evolucao = collect($resultado['evolucao_por_especie'])->keyBy('pest_type');
        $this->assertSame(1, $evolucao['ants']['para']);
    }

    // -----------------------------------------------------------------
    // (f) Isolamento entre empresas
    // -----------------------------------------------------------------

    public function test_avistamento_de_outra_empresa_nao_entra_na_distribuicao(): void
    {
        $cliente = $this->criarCliente();
        $endereco = $this->criarEndereco($cliente);
        $ordem = $this->criarOrdem($cliente, $endereco);
        $this->criarAvistamento($endereco, $ordem, '2026-10-05 10:00:00', 'spiders');

        $outraEmpresa = Company::create([
            'name' => 'Dedetizadora Concorrente Espécies',
            'cnpj' => '77.777.777/0001-77',
            'email' => 'contato@concorrente-especies.test',
        ]);

        TenantAtual::comTenant($outraEmpresa->id, function () {
            $clienteDaOutra = ClientFactory::new()->create();
            $enderecoDaOutra = AddressFactory::new()->create(['client_id' => $clienteDaOutra->id]);
            $ordemDaOutra = WorkOrderFactory::new()->create([
                'client_id' => $clienteDaOutra->id,
                'address_id' => $enderecoDaOutra->id,
            ]);

            PestSighting::create([
                'address_id' => $enderecoDaOutra->id,
                'work_order_id' => $ordemDaOutra->id,
                'sighting_date' => '2026-10-05 10:00:00',
                'pest_type' => 'spiders',
                'severity_level' => 'low',
                'location_description' => 'Cozinha',
                'active' => true,
            ]);
        });

        $resultado = $this->comTenant(fn () => $this->servico->porPeriodo($endereco, '2026-10-01', '2026-10-31'));

        $area = $resultado['distribuicao_por_area'][0];
        $this->assertSame(1, $area['total'], 'o avistamento da outra empresa não pode somar no total deste endereço');
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

    private function criarAvistamento(Address $endereco, WorkOrder $ordem, string $instante, string $pestType): PestSighting
    {
        return $this->comTenant(fn () => PestSighting::create([
            'address_id' => $endereco->id,
            'work_order_id' => $ordem->id,
            'sighting_date' => $instante,
            'pest_type' => $pestType,
            'severity_level' => 'medium',
            'location_description' => 'Área de teste',
            'active' => true,
        ]));
    }
}
