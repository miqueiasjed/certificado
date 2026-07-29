<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Receivable;
use App\Models\ReceivableInstallment;
use App\Services\Financial\AgingService;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Closure;
use Database\Factories\ClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 18.6 do Plano 18: aging de inadimplência por cliente.
 *
 * O relógio fica fixo em 2026-07-20 durante a suíte inteira (`HOJE`), para que
 * a classificação por faixa seja contra um dia conhecido, nunca contra o
 * relógio real da máquina que roda o teste.
 */
class AgingServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-07-20';

    private AgingService $servico;

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::HOJE.' 12:00:00');

        TenantAtual::limpar();

        $this->servico = app(AgingService::class);
        $this->empresa = Company::query()->firstOrFail();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Classificação nas cinco faixas
    // -----------------------------------------------------------------

    public function test_classifica_corretamente_nas_cinco_faixas_com_total_por_cliente_e_geral(): void
    {
        $cliente = $this->criarCliente();

        $this->criarParcela($cliente, 100.00, '2026-08-01'); // a vencer (futuro)
        $this->criarParcela($cliente, 50.00, self::HOJE); // a vencer (vence hoje)
        $this->criarParcela($cliente, 200.00, '2026-07-10'); // 10 dias de atraso
        $this->criarParcela($cliente, 300.00, '2026-06-10'); // 40 dias de atraso
        $this->criarParcela($cliente, 400.00, '2026-05-11'); // 70 dias de atraso
        $this->criarParcela($cliente, 500.00, '2026-04-11'); // 100 dias de atraso

        $resultado = $this->comTenant(fn () => $this->servico->porCliente());

        $this->assertCount(1, $resultado['por_cliente']);

        $doCliente = $resultado['por_cliente'][0];

        $this->assertSame($cliente->id, $doCliente['client_id']);
        $this->assertSame('150.00', $doCliente['faixas']['a_vencer']['valor']);
        $this->assertSame(2, $doCliente['faixas']['a_vencer']['quantidade']);
        $this->assertSame('200.00', $doCliente['faixas']['de_1_a_30']['valor']);
        $this->assertSame(1, $doCliente['faixas']['de_1_a_30']['quantidade']);
        $this->assertSame('300.00', $doCliente['faixas']['de_31_a_60']['valor']);
        $this->assertSame('400.00', $doCliente['faixas']['de_61_a_90']['valor']);
        $this->assertSame('500.00', $doCliente['faixas']['acima_de_90']['valor']);
        $this->assertSame(1, $doCliente['faixas']['acima_de_90']['quantidade']);

        $this->assertSame('1550.00', $doCliente['total']['valor']);
        $this->assertSame(6, $doCliente['total']['quantidade']);

        $this->assertSame('1550.00', $resultado['total_geral']['total']['valor']);
        $this->assertSame(6, $resultado['total_geral']['total']['quantidade']);
        $this->assertSame('500.00', $resultado['total_geral']['faixas']['acima_de_90']['valor']);
    }

    public function test_parcela_vencendo_hoje_aparece_em_a_vencer_e_nao_em_atraso(): void
    {
        $cliente = $this->criarCliente();

        $this->criarParcela($cliente, 777.00, self::HOJE);

        $resultado = $this->comTenant(fn () => $this->servico->porCliente());

        $doCliente = $resultado['por_cliente'][0];

        $this->assertSame('777.00', $doCliente['faixas']['a_vencer']['valor']);
        $this->assertSame(1, $doCliente['faixas']['a_vencer']['quantidade']);

        foreach (['de_1_a_30', 'de_31_a_60', 'de_61_a_90', 'acima_de_90'] as $faixaDeAtraso) {
            $this->assertSame(
                '0.00',
                $doCliente['faixas'][$faixaDeAtraso]['valor'],
                "a parcela que vence hoje não pode aparecer na faixa \"{$faixaDeAtraso}\""
            );
        }
    }

    // -----------------------------------------------------------------
    // Contagem de dias no fuso do negócio
    // -----------------------------------------------------------------

    public function test_faixa_e_decidida_pelo_dia_no_fuso_do_negocio_mesmo_com_o_servidor_em_utc(): void
    {
        // 2026-07-20 01:00:00 em UTC (fuso do servidor) já é dia 20, mas em
        // América/São Paulo (UTC-3) ainda são 22h do dia 19: o "hoje" do
        // negócio é 19, não 20. Uma parcela vencendo no dia 19 vence
        // exatamente "hoje" pelo fuso do negócio e precisa aparecer em
        // "a vencer"; contá-la pelo dia do servidor (UTC) a jogaria, errada,
        // para "de_1_a_30" com um dia de atraso que não existe.
        Carbon::setTestNow('2026-07-20 01:00:00');

        $this->assertSame(
            '2026-07-19',
            \App\Support\BusinessDate::hoje()->toDateString(),
            'a premissa do teste não se sustenta: o dia do negócio não ficou um dia atrás do dia em UTC'
        );

        $cliente = $this->criarCliente();
        $this->criarParcela($cliente, 250.00, '2026-07-19');

        $resultado = $this->comTenant(fn () => $this->servico->porCliente());

        $doCliente = $resultado['por_cliente'][0];

        $this->assertSame(
            '250.00',
            $doCliente['faixas']['a_vencer']['valor'],
            'a faixa foi decidida pelo dia do servidor (UTC), não pelo dia no fuso do negócio'
        );
        $this->assertSame(0, $doCliente['faixas']['de_1_a_30']['quantidade']);
    }

    // -----------------------------------------------------------------
    // Agrupamento por cliente
    // -----------------------------------------------------------------

    public function test_agrupa_por_multiplos_clientes_sem_misturar_faixas(): void
    {
        $clienteA = $this->criarCliente();
        $clienteB = $this->criarCliente();

        $this->criarParcela($clienteA, 100.00, '2026-07-10'); // 10 dias de atraso
        $this->criarParcela($clienteB, 900.00, '2026-04-01'); // bem acima de 90 dias

        $resultado = $this->comTenant(fn () => $this->servico->porCliente());

        $this->assertCount(2, $resultado['por_cliente']);

        $porId = collect($resultado['por_cliente'])->keyBy('client_id');

        $this->assertSame('100.00', $porId[$clienteA->id]['faixas']['de_1_a_30']['valor']);
        $this->assertSame('0.00', $porId[$clienteA->id]['faixas']['acima_de_90']['valor']);

        $this->assertSame('900.00', $porId[$clienteB->id]['faixas']['acima_de_90']['valor']);
        $this->assertSame('0.00', $porId[$clienteB->id]['faixas']['de_1_a_30']['valor']);

        $this->assertSame('1000.00', $resultado['total_geral']['total']['valor']);
    }

    public function test_filtro_por_client_id_restringe_o_resultado_a_um_cliente(): void
    {
        $clienteA = $this->criarCliente();
        $clienteB = $this->criarCliente();

        $this->criarParcela($clienteA, 100.00, '2026-07-10');
        $this->criarParcela($clienteB, 200.00, '2026-07-10');

        $resultado = $this->comTenant(fn () => $this->servico->porCliente(['client_id' => $clienteA->id]));

        $this->assertCount(1, $resultado['por_cliente']);
        $this->assertSame($clienteA->id, $resultado['por_cliente'][0]['client_id']);
        $this->assertSame('100.00', $resultado['total_geral']['total']['valor']);
    }

    // -----------------------------------------------------------------
    // Parcela sem saldo em aberto
    // -----------------------------------------------------------------

    public function test_parcela_com_saldo_totalmente_quitado_nao_entra_no_aging(): void
    {
        $cliente = $this->criarCliente();

        // Baixa integral registrada (valor_pago == valor), mesmo que a rotina
        // de status ainda não tenha marcado a situação como "paga": sem saldo
        // devedor não há inadimplência a reportar.
        $this->criarParcela($cliente, 300.00, '2026-07-10', valorPago: 300.00);

        $resultado = $this->comTenant(fn () => $this->servico->porCliente());

        $this->assertSame([], $resultado['por_cliente']);
        $this->assertSame('0.00', $resultado['total_geral']['total']['valor']);
        $this->assertSame(0, $resultado['total_geral']['total']['quantidade']);
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas
    // -----------------------------------------------------------------

    public function test_aging_nao_mistura_parcela_de_outra_empresa(): void
    {
        $cliente = $this->criarCliente();
        $this->criarParcela($cliente, 100.00, '2026-07-10');

        $outraEmpresa = Company::create([
            'name' => 'Dedetizadora Concorrente',
            'cnpj' => '22.222.222/0001-22',
            'email' => 'contato@concorrente.test',
        ]);

        $clienteDaOutra = TenantAtual::comTenant($outraEmpresa->id, fn () => ClientFactory::new()->create());
        TenantAtual::comTenant($outraEmpresa->id, function () use ($clienteDaOutra): void {
            $titulo = Receivable::create([
                'client_id' => $clienteDaOutra->id,
                'descricao' => 'Título da concorrente',
                'valor_total' => 999.00,
                'emitido_em' => self::HOJE,
                'situacao' => 'aberto',
            ]);

            ReceivableInstallment::create([
                'receivable_id' => $titulo->id,
                'numero' => 1,
                'valor' => 999.00,
                'vencimento' => '2026-07-10',
                'situacao' => 'aberta',
            ]);
        });

        $resultado = $this->comTenant(fn () => $this->servico->porCliente());

        $this->assertSame('100.00', $resultado['total_geral']['total']['valor'], 'vazou parcela de outra empresa para o total geral');
        $this->assertCount(1, $resultado['por_cliente']);
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
        return ClientFactory::new()->create();
    }

    private function criarParcela(
        Client $cliente,
        float $valor,
        string $vencimento,
        float $valorPago = 0.0
    ): ReceivableInstallment {
        return $this->comTenant(function () use ($cliente, $valor, $vencimento, $valorPago): ReceivableInstallment {
            $titulo = Receivable::create([
                'client_id' => $cliente->id,
                'descricao' => 'Título de teste',
                'valor_total' => $valor,
                'emitido_em' => self::HOJE,
                'situacao' => 'aberto',
            ]);

            return ReceivableInstallment::create([
                'receivable_id' => $titulo->id,
                'numero' => 1,
                'valor' => $valor,
                'vencimento' => $vencimento,
                'valor_pago' => $valorPago,
                'situacao' => 'aberta',
            ]);
        });
    }
}
