<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\DailyCashBalance;
use App\Models\Payable;
use App\Models\PayableInstallment;
use App\Models\Receivable;
use App\Models\ReceivableInstallment;
use App\Models\Supplier;
use App\Services\Financial\CashForecastService;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Closure;
use Database\Factories\ClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Task 18.6 do Plano 18: previsão de caixa juntando o que vence a receber e a
 * pagar, com o saldo acumulado partindo do saldo de caixa atual.
 *
 * O relógio fica fixo em 2026-07-20 (`HOJE`) durante a suíte inteira, para o
 * mês corrente da previsão ser sempre julho de 2026.
 */
class CashForecastServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-07-20';

    private CashForecastService $servico;

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::HOJE.' 12:00:00');

        TenantAtual::limpar();

        $this->servico = app(CashForecastService::class);
        $this->empresa = Company::query()->firstOrFail();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Previsto x realizado, sem misturar
    // -----------------------------------------------------------------

    public function test_previsao_separa_previsto_de_realizado_na_estrutura_do_retorno(): void
    {
        $this->criarSaldoDeCaixa('2026-07-19', 1000.00);
        $this->criarReceivableInstallment(500.00, '2026-07-25');
        $this->criarPayableInstallment(200.00, '2026-07-28');

        $resultado = $this->comTenant(fn () => $this->servico->previsao(1));

        $this->assertArrayHasKey('realizado', $resultado);
        $this->assertArrayHasKey('previsto', $resultado);

        // O realizado é só o saldo de caixa atual, nunca um valor de mês
        // futuro misturado no mesmo número.
        $this->assertSame('1000.00', $resultado['realizado']['saldo_de_caixa_atual']);
        $this->assertArrayNotHasKey('meses', $resultado['realizado']);

        // Cada linha de "previsto" só carrega números de expectativa, nunca o
        // saldo de caixa realizado misturado na mesma linha.
        $mes = $resultado['previsto']['meses'][0];
        $this->assertArrayNotHasKey('saldo_de_caixa_atual', $mes);
        $this->assertSame('2026-07', $mes['competencia']);
        $this->assertSame('500.00', $mes['a_receber']);
        $this->assertSame('200.00', $mes['a_pagar']);
        $this->assertSame('300.00', $mes['resultado']);
    }

    // -----------------------------------------------------------------
    // Saldo acumulado parte do saldo de caixa atual
    // -----------------------------------------------------------------

    public function test_saldo_acumulado_parte_do_saldo_de_caixa_atual(): void
    {
        $this->criarSaldoDeCaixa('2026-07-19', 1000.00);
        $this->criarReceivableInstallment(500.00, '2026-07-25');
        $this->criarPayableInstallment(200.00, '2026-08-05');

        $resultado = $this->comTenant(fn () => $this->servico->previsao(2));

        $meses = $resultado['previsto']['meses'];

        // Julho: 1000 (saldo atual) + 500 (a receber) - 0 (nada a pagar em
        // julho) = 1500.
        $this->assertSame('2026-07', $meses[0]['competencia']);
        $this->assertSame('1500.00', $meses[0]['saldo_acumulado_previsto']);

        // Agosto: 1500 - 200 (a pagar) = 1300.
        $this->assertSame('2026-08', $meses[1]['competencia']);
        $this->assertSame('1300.00', $meses[1]['saldo_acumulado_previsto']);
    }

    public function test_sem_nenhum_saldo_de_caixa_registrado_o_ponto_de_partida_e_zero(): void
    {
        $this->criarReceivableInstallment(100.00, '2026-07-25');

        $resultado = $this->comTenant(fn () => $this->servico->previsao(1));

        $this->assertSame('0.00', $resultado['realizado']['saldo_de_caixa_atual']);
        $this->assertSame('100.00', $resultado['previsto']['meses'][0]['saldo_acumulado_previsto']);
    }

    // -----------------------------------------------------------------
    // Múltiplos meses e parcelas fora da janela
    // -----------------------------------------------------------------

    public function test_agrupa_por_competencia_do_vencimento_ao_longo_dos_meses_pedidos(): void
    {
        $this->criarReceivableInstallment(300.00, '2026-07-05');
        $this->criarReceivableInstallment(700.00, '2026-08-10');
        $this->criarReceivableInstallment(999.00, '2026-10-01'); // fora da janela de 3 meses

        $resultado = $this->comTenant(fn () => $this->servico->previsao(3));

        $meses = collect($resultado['previsto']['meses'])->keyBy('competencia');

        $this->assertSame('300.00', $meses['2026-07']['a_receber']);
        $this->assertSame('700.00', $meses['2026-08']['a_receber']);
        $this->assertSame('0.00', $meses['2026-09']['a_receber']);
        $this->assertCount(3, $meses, 'a previsão devolveu mais meses do que os pedidos');
    }

    public function test_parcela_vencida_de_competencia_anterior_ao_mes_corrente_fica_fora_da_previsao(): void
    {
        // Ainda em aberto, mas o vencimento é de um mês antes do mês
        // corrente: isso é atraso já vencido (aging), não previsão de mês
        // futuro.
        $this->criarReceivableInstallment(1234.00, '2026-05-01');
        $this->criarReceivableInstallment(50.00, '2026-07-25');

        $resultado = $this->comTenant(fn () => $this->servico->previsao(2));

        $this->assertSame('50.00', $resultado['previsto']['meses'][0]['a_receber']);
    }

    public function test_menos_de_um_mes_lanca_excecao(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->comTenant(fn () => $this->servico->previsao(0));
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas
    // -----------------------------------------------------------------

    public function test_previsao_nao_mistura_parcela_nem_saldo_de_outra_empresa(): void
    {
        $this->criarSaldoDeCaixa('2026-07-19', 1000.00);
        $this->criarReceivableInstallment(500.00, '2026-07-25');

        $outraEmpresa = Company::create([
            'name' => 'Dedetizadora Concorrente',
            'cnpj' => '22.222.222/0001-22',
            'email' => 'contato@concorrente.test',
        ]);

        TenantAtual::comTenant($outraEmpresa->id, function (): void {
            DailyCashBalance::create([
                'balance_date' => '2026-07-19',
                'opening_balance' => 0,
                'total_entries' => 50000,
                'total_withdrawals' => 0,
                'closing_balance' => 50000,
                'entries_count' => 1,
                'withdrawals_count' => 0,
                'is_closed' => true,
            ]);

            $cliente = ClientFactory::new()->create();

            $titulo = Receivable::create([
                'client_id' => $cliente->id,
                'descricao' => 'Título da concorrente',
                'valor_total' => 99999.00,
                'emitido_em' => self::HOJE,
                'situacao' => 'aberto',
            ]);

            ReceivableInstallment::create([
                'receivable_id' => $titulo->id,
                'numero' => 1,
                'valor' => 99999.00,
                'vencimento' => '2026-07-25',
                'situacao' => 'aberta',
            ]);
        });

        $resultado = $this->comTenant(fn () => $this->servico->previsao(1));

        $this->assertSame('1000.00', $resultado['realizado']['saldo_de_caixa_atual']);
        $this->assertSame('500.00', $resultado['previsto']['meses'][0]['a_receber']);
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

    private function criarSaldoDeCaixa(string $data, float $fechamento): DailyCashBalance
    {
        return $this->comTenant(fn () => DailyCashBalance::create([
            'balance_date' => $data,
            'opening_balance' => 0,
            'total_entries' => $fechamento,
            'total_withdrawals' => 0,
            'closing_balance' => $fechamento,
            'entries_count' => 1,
            'withdrawals_count' => 0,
            'is_closed' => true,
        ]));
    }

    private function criarReceivableInstallment(float $valor, string $vencimento): ReceivableInstallment
    {
        return $this->comTenant(function () use ($valor, $vencimento): ReceivableInstallment {
            $titulo = Receivable::create([
                'client_id' => $this->criarCliente()->id,
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
                'situacao' => 'aberta',
            ]);
        });
    }

    private function criarPayableInstallment(float $valor, string $vencimento): PayableInstallment
    {
        return $this->comTenant(function () use ($valor, $vencimento): PayableInstallment {
            $fornecedor = Supplier::create([
                'nome' => 'Fornecedor de teste',
            ]);

            $titulo = Payable::create([
                'supplier_id' => $fornecedor->id,
                'descricao' => 'Despesa de teste',
                'valor_total' => $valor,
                'emitido_em' => self::HOJE,
                'situacao' => 'aberto',
            ]);

            return PayableInstallment::create([
                'payable_id' => $titulo->id,
                'numero' => 1,
                'valor' => $valor,
                'vencimento' => $vencimento,
                'situacao' => 'aberta',
            ]);
        });
    }
}
