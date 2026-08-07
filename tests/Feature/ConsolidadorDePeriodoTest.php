<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Client;
use App\Models\Company;
use App\Models\WorkOrder;
use App\Services\Monitoring\ConsolidadorDePeriodo;
use App\Support\TenantAtual;
use Closure;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Factories\WorkOrderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 21.9 do Plano 21: cobertura formal de `ConsolidadorDePeriodo::consolidar()`
 * para a parte que a Task 21.2 só validou manualmente por tinker na hora de
 * entregar, sem deixar teste persistido - o número e as datas das visitas do
 * período (chave `visitas` do array consolidado).
 *
 * Por que um arquivo próprio, e não dentro de `TendenciaServiceTest`
 * -----------------------------------------------------------------------
 * `ConsolidadorDePeriodo` é uma classe diferente de `TendenciaService`
 * (injeta `TendenciaService`, `PontosCriticosService`, `MapaDeCalorService` e
 * `OcorrenciaPorEspecieService`, mas a contagem de visitas é lógica própria
 * dela, em `visitasDoPeriodo()`, sem equivalente nos outros services). A
 * nomenclatura `ConsolidadorDePeriodoTest` deixa claro qual classe está sendo
 * testada, no mesmo padrão de um arquivo de teste por Service já usado no
 * restante do Plano 21 (`TendenciaServiceTest`, `PontosCriticosServiceTest`,
 * `MapaDeCalorServiceTest`, `OcorrenciaPorEspecieServiceTest`). Os demais
 * pontos do array consolidado (tendência, ranking, mapa de calor, ocorrência
 * por espécie) já têm cobertura própria e completa nos services que os
 * produzem; este arquivo não os repete, só confere que `consolidar()` os
 * embute (`test_consolidar_traz_as_cinco_partes_do_retrato`) sem duplicar a
 * regra de negócio de cada um.
 */
class ConsolidadorDePeriodoTest extends TestCase
{
    use RefreshDatabase;

    private ConsolidadorDePeriodo $servico;

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->servico = app(ConsolidadorDePeriodo::class);
        $this->empresa = Company::query()->firstOrFail();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // (e) O consolidado traz o número e as datas das visitas do período
    // -----------------------------------------------------------------

    /**
     * "Visita" é ordem de serviço concluída (`status = completed`) e ativa
     * (`active = true`), dentro do intervalo pedido, no endereço pedido.
     * Monta as quatro exclusões que provam que a contagem não é "toda ordem
     * do cliente": pendente, fora do período, de outro endereço do mesmo
     * cliente e inativa (cancelada/anulada) - nenhuma delas pode aparecer em
     * `quantidade` nem em `datas`.
     */
    public function test_visitas_do_periodo_traz_quantidade_e_datas_apenas_das_ordens_concluidas_do_endereco_no_intervalo(): void
    {
        $cliente = $this->criarCliente();
        $enderecoA = $this->criarEndereco($cliente, 'Unidade A');
        $enderecoB = $this->criarEndereco($cliente, 'Unidade B');

        // As três visitas que devem entrar: concluídas, ativas, dentro do
        // período, no endereço A.
        $this->criarOrdem($cliente, $enderecoA, '2026-07-05', 'completed');
        $this->criarOrdem($cliente, $enderecoA, '2026-07-15', 'completed');
        $this->criarOrdem($cliente, $enderecoA, '2026-07-25', 'completed');

        // As quatro que não devem entrar.
        $this->criarOrdem($cliente, $enderecoA, '2026-07-10', 'scheduled'); // não concluída
        $this->criarOrdem($cliente, $enderecoA, '2026-06-30', 'completed'); // fora do período (um dia antes)
        $this->criarOrdem($cliente, $enderecoA, '2026-08-01', 'completed'); // fora do período (um dia depois)
        $this->criarOrdem($cliente, $enderecoB, '2026-07-12', 'completed'); // outro endereço do mesmo cliente
        $ordemInativa = $this->criarOrdem($cliente, $enderecoA, '2026-07-18', 'completed');
        $this->comTenant(fn () => $ordemInativa->update(['active' => false])); // cancelada/anulada

        $consolidado = $this->comTenant(
            fn () => $this->servico->consolidar($cliente, $enderecoA, '2026-07-01', '2026-07-31')
        );

        $this->assertSame(3, $consolidado['visitas']['quantidade']);

        $datas = collect($consolidado['visitas']['datas'])->pluck('data')->sort()->values()->all();
        $this->assertSame(['2026-07-05', '2026-07-15', '2026-07-25'], $datas);

        foreach ($consolidado['visitas']['datas'] as $visita) {
            $this->assertSame($enderecoA->id, $visita['address_id'], 'nenhuma visita do endereço B pode entrar quando o relatório é do endereço A');
            $this->assertIsInt($visita['work_order_id']);
        }
    }

    /**
     * Endereço `null` cobre o cliente inteiro: a contagem soma as visitas de
     * todos os endereços ativos do cliente, não só o primeiro.
     */
    public function test_visitas_do_periodo_com_endereco_nulo_soma_todos_os_enderecos_ativos_do_cliente(): void
    {
        $cliente = $this->criarCliente();
        $enderecoA = $this->criarEndereco($cliente, 'Unidade A');
        $enderecoB = $this->criarEndereco($cliente, 'Unidade B');
        $enderecoInativo = $this->criarEndereco($cliente, 'Unidade Desativada');
        $this->comTenant(fn () => $enderecoInativo->update(['active' => false]));

        $this->criarOrdem($cliente, $enderecoA, '2026-07-03', 'completed');
        $this->criarOrdem($cliente, $enderecoB, '2026-07-20', 'completed');
        // Visita concluída num endereço já desativado: não entra, porque
        // `enderecosDoRetrato()` só itera endereço ativo quando o relatório é
        // do cliente inteiro.
        $this->criarOrdem($cliente, $enderecoInativo, '2026-07-22', 'completed');

        $consolidado = $this->comTenant(
            fn () => $this->servico->consolidar($cliente, null, '2026-07-01', '2026-07-31')
        );

        $this->assertNull($consolidado['address_id']);
        $this->assertSame(2, $consolidado['visitas']['quantidade']);

        $enderecosDasVisitas = collect($consolidado['visitas']['datas'])->pluck('address_id')->sort()->values()->all();
        $this->assertSame([$enderecoA->id, $enderecoB->id], $enderecosDasVisitas);
    }

    /**
     * Período sem nenhuma visita: `quantidade` é `0` e `datas` é uma lista
     * vazia, não `null` nem ausente - mesmo espírito do "sem visita não é
     * zero" do `TendenciaService`, só que aqui o zero é o valor correto (o
     * relatório existe, e de fato não houve nenhuma visita no intervalo).
     */
    public function test_visitas_do_periodo_sem_nenhuma_ordem_devolve_quantidade_zero_e_lista_vazia(): void
    {
        $cliente = $this->criarCliente();
        $endereco = $this->criarEndereco($cliente, 'Unidade sem visita');

        $consolidado = $this->comTenant(
            fn () => $this->servico->consolidar($cliente, $endereco, '2026-07-01', '2026-07-31')
        );

        $this->assertSame(0, $consolidado['visitas']['quantidade']);
        $this->assertSame([], $consolidado['visitas']['datas']);
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas
    // -----------------------------------------------------------------

    public function test_visita_de_outra_empresa_nao_entra_na_contagem_do_consolidado(): void
    {
        $cliente = $this->criarCliente();
        $endereco = $this->criarEndereco($cliente, 'Unidade');
        $this->criarOrdem($cliente, $endereco, '2026-07-10', 'completed');

        $outraEmpresa = Company::create([
            'name' => 'Dedetizadora Concorrente Consolidador',
            'cnpj' => '77.777.777/0001-77',
            'email' => 'contato@concorrente-consolidador.test',
        ]);

        TenantAtual::comTenant($outraEmpresa->id, function () {
            $clienteDaOutra = ClientFactory::new()->create();
            $enderecoDaOutra = AddressFactory::new()->create(['client_id' => $clienteDaOutra->id]);
            WorkOrderFactory::new()->create([
                'client_id' => $clienteDaOutra->id,
                'address_id' => $enderecoDaOutra->id,
                'scheduled_date' => '2026-07-10',
                'status' => 'completed',
            ]);
        });

        $consolidado = $this->comTenant(
            fn () => $this->servico->consolidar($cliente, $endereco, '2026-07-01', '2026-07-31')
        );

        $this->assertSame(1, $consolidado['visitas']['quantidade'], 'a visita da outra empresa não pode somar aqui');
    }

    // -----------------------------------------------------------------
    // Rede: o consolidado embute as outras quatro partes do retrato
    // -----------------------------------------------------------------

    /**
     * Não repete a regra de negócio de cada Service (já testada em
     * `TendenciaServiceTest`, `PontosCriticosServiceTest`,
     * `MapaDeCalorServiceTest` e `OcorrenciaPorEspecieServiceTest`); só
     * confere que `consolidar()` de fato chama os quatro e embute o
     * resultado nas chaves certas, uma entrada por endereço do retrato.
     */
    public function test_consolidar_traz_as_cinco_partes_do_retrato(): void
    {
        $cliente = $this->criarCliente();
        $endereco = $this->criarEndereco($cliente, 'Unidade');
        $this->criarOrdem($cliente, $endereco, '2026-07-10', 'completed');

        $consolidado = $this->comTenant(
            fn () => $this->servico->consolidar($cliente, $endereco, '2026-07-01', '2026-07-31')
        );

        $this->assertSame($cliente->id, $consolidado['client_id']);
        $this->assertSame($endereco->id, $consolidado['address_id']);
        $this->assertSame(['de' => '2026-07-01', 'ate' => '2026-07-31'], $consolidado['periodo']);
        $this->assertNotEmpty($consolidado['gerado_em']);

        $this->assertCount(1, $consolidado['tendencia']);
        $this->assertSame($endereco->id, $consolidado['tendencia'][0]['address_id']);

        $this->assertCount(1, $consolidado['ranking_pontos_criticos']);
        $this->assertCount(1, $consolidado['mapa_de_calor']);
        $this->assertCount(1, $consolidado['ocorrencia_por_especie']);

        // Fora do escopo de 21.2/21.3, preenchida mais adiante no plano.
        $this->assertSame([], $consolidado['adequacoes']);
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

    private function criarEndereco(Client $cliente, string $apelido): Address
    {
        return $this->comTenant(fn () => AddressFactory::new()->create([
            'client_id' => $cliente->id,
            'nickname' => $apelido,
            'active' => true,
        ]));
    }

    private function criarOrdem(Client $cliente, Address $endereco, string $dataAgendada, string $status): WorkOrder
    {
        return $this->comTenant(fn () => WorkOrderFactory::new()->create([
            'client_id' => $cliente->id,
            'address_id' => $endereco->id,
            'scheduled_date' => $dataAgendada,
            'status' => $status,
            'active' => true,
        ]));
    }
}
