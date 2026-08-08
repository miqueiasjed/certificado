<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Client;
use App\Models\Company;
use App\Models\Service;
use App\Models\User;
use App\Services\Commercial\IndicadoresComerciaisService;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Closure;
use Database\Factories\ClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 23.3 do Plano 23: painel comercial com orçamento enviado, taxa de
 * conversão, ticket médio e tempo médio de fechamento, com evolução mês a
 * mês. Tudo apurado a partir de `budgets` e do histórico de `audit_logs` que
 * a trait `Auditavel` já grava a cada troca de status.
 */
class IndicadoresComerciaisServiceTest extends TestCase
{
    use RefreshDatabase;

    private IndicadoresComerciaisService $servico;

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->servico = app(IndicadoresComerciaisService::class);
        $this->empresa = Company::query()->firstOrFail();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Os quatro indicadores, com a contagem ao lado de toda porcentagem
    // -----------------------------------------------------------------

    public function test_enviados_conversao_e_ticket_medio_do_periodo(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');

        $vendedor = $this->criarUsuario();

        $this->criarOrcamentoComServico($vendedor, 'approved', '2026-07-01', 1000.00);
        $this->criarOrcamentoComServico($vendedor, 'converted', '2026-07-05', 2000.00);
        $this->criarOrcamento($vendedor, 'refused', '2026-07-08');
        $this->criarOrcamento($vendedor, 'negotiating', '2026-07-10');
        // Rascunho não é orçamento enviado: fora da contagem inteira.
        $this->criarOrcamento($vendedor, 'draft', '2026-07-11');

        $resultado = $this->comTenant(fn () => $this->servico->indicadores(['de' => '2026-07-01', 'ate' => '2026-07-31']));

        $geral = $resultado['geral'];

        $this->assertSame(4, $geral['enviados']);
        $this->assertSame(2, $geral['aprovados']);
        $this->assertSame(50.0, $geral['conversao']);
        $this->assertFalse($geral['conversao_omitida']);
        // Ticket médio: (1000 + 2000) / 2 aprovados.
        $this->assertSame(1500.0, $geral['ticket_medio']);
        $this->assertSame(2, $geral['amostra_ticket_medio']);
    }

    public function test_conversao_omitida_com_menos_de_tres_orcamentos_mas_contagem_continua_visivel(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');

        $vendedor = $this->criarUsuario();

        $this->criarOrcamento($vendedor, 'approved', '2026-07-01');
        $this->criarOrcamento($vendedor, 'sent', '2026-07-02');

        $resultado = $this->comTenant(fn () => $this->servico->indicadores(['de' => '2026-07-01', 'ate' => '2026-07-31']));
        $geral = $resultado['geral'];

        $this->assertSame(2, $geral['enviados']);
        $this->assertSame(1, $geral['aprovados']);
        $this->assertNull($geral['conversao']);
        $this->assertTrue($geral['conversao_omitida']);
    }

    public function test_tempo_medio_de_fechamento_vem_do_historico_de_auditoria_do_orcamento(): void
    {
        // Envio e aprovação em instantes controlados, para o tempo médio dar
        // um número exato e não aproximado.
        Carbon::setTestNow('2026-07-01 09:00:00');
        $vendedor = $this->criarUsuario();
        $orcamento = $this->comTenant(fn () => Budget::create([
            'user_id' => $vendedor->id,
            'status' => 'draft',
            'date' => '2026-07-01',
        ]));

        Carbon::setTestNow('2026-07-02 09:00:00');
        $this->comTenant(fn () => $orcamento->update(['status' => 'sent']));

        Carbon::setTestNow('2026-07-05 09:00:00');
        $this->comTenant(fn () => $orcamento->update(['status' => 'approved']));

        Carbon::setTestNow('2026-07-20 12:00:00');
        $resultado = $this->comTenant(fn () => $this->servico->indicadores(['de' => '2026-07-01', 'ate' => '2026-07-31']));

        // 02/07 09h -> 05/07 09h = exatos 3 dias.
        $this->assertSame(3.0, $resultado['geral']['tempo_medio_fechamento_dias']);
        $this->assertSame(1, $resultado['geral']['amostra_tempo_fechamento']);
    }

    public function test_orcamento_aprovado_sem_passar_por_enviado_fica_fora_da_media_de_tempo(): void
    {
        Carbon::setTestNow('2026-07-01 09:00:00');
        $vendedor = $this->criarUsuario();

        // Criado já como aprovado, sem nunca passar por "sent": não há
        // instante de envio para calcular a diferença.
        $this->comTenant(fn () => Budget::create([
            'user_id' => $vendedor->id,
            'status' => 'approved',
            'date' => '2026-07-01',
        ]));

        $resultado = $this->comTenant(fn () => $this->servico->indicadores(['de' => '2026-07-01', 'ate' => '2026-07-31']));

        $this->assertNull($resultado['geral']['tempo_medio_fechamento_dias']);
        $this->assertSame(0, $resultado['geral']['amostra_tempo_fechamento']);
    }

    // -----------------------------------------------------------------
    // Por pessoa
    // -----------------------------------------------------------------

    public function test_por_pessoa_separa_os_indicadores_de_cada_vendedor(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');

        $vendedorA = $this->criarUsuario();
        $vendedorB = $this->criarUsuario();

        $this->criarOrcamento($vendedorA, 'approved', '2026-07-01');
        $this->criarOrcamento($vendedorA, 'approved', '2026-07-02');
        $this->criarOrcamento($vendedorA, 'refused', '2026-07-03');

        $this->criarOrcamento($vendedorB, 'approved', '2026-07-01');
        $this->criarOrcamento($vendedorB, 'sent', '2026-07-02');

        $resultado = $this->comTenant(fn () => $this->servico->indicadores(['de' => '2026-07-01', 'ate' => '2026-07-31']));

        $porPessoa = collect($resultado['por_pessoa'])->keyBy('user_id');

        $this->assertSame(3, $porPessoa[$vendedorA->id]['enviados']);
        $this->assertSame(2, $porPessoa[$vendedorA->id]['aprovados']);
        $this->assertFalse($porPessoa[$vendedorA->id]['conversao_omitida']);

        $this->assertSame(2, $porPessoa[$vendedorB->id]['enviados']);
        $this->assertTrue($porPessoa[$vendedorB->id]['conversao_omitida']);
    }

    // -----------------------------------------------------------------
    // Evolução mês a mês
    // -----------------------------------------------------------------

    public function test_evolucao_mensal_agrupa_por_mes_do_orcamento(): void
    {
        Carbon::setTestNow('2026-08-20 12:00:00');

        $vendedor = $this->criarUsuario();

        $this->criarOrcamento($vendedor, 'approved', '2026-06-05');
        $this->criarOrcamento($vendedor, 'approved', '2026-07-05');
        $this->criarOrcamento($vendedor, 'sent', '2026-07-06');

        $resultado = $this->comTenant(fn () => $this->servico->indicadores(['de' => '2026-06-01', 'ate' => '2026-08-31']));

        $meses = collect($resultado['evolucao_mensal'])->keyBy('periodo');

        $this->assertSame(['2026-06', '2026-07'], $meses->keys()->all());
        $this->assertSame(1, $meses['2026-06']['enviados']);
        $this->assertSame(2, $meses['2026-07']['enviados']);
        $this->assertSame(1, $meses['2026-07']['aprovados']);
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas
    // -----------------------------------------------------------------

    public function test_indicadores_nao_misturam_orcamento_de_outra_empresa(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');

        $vendedor = $this->criarUsuario();
        $this->criarOrcamento($vendedor, 'approved', '2026-07-01');

        $outraEmpresa = Company::create([
            'name' => 'Dedetizadora Concorrente',
            'cnpj' => '33.333.333/0001-33',
            'email' => 'concorrente2@teste.test',
        ]);

        TenantAtual::comTenant($outraEmpresa->id, function (): void {
            $outroVendedor = User::factory()->create();
            Budget::create([
                'user_id' => $outroVendedor->id,
                'status' => 'approved',
                'date' => '2026-07-01',
            ]);
            Budget::create([
                'user_id' => $outroVendedor->id,
                'status' => 'sent',
                'date' => '2026-07-02',
            ]);
        });

        $resultado = $this->comTenant(fn () => $this->servico->indicadores(['de' => '2026-07-01', 'ate' => '2026-07-31']));

        $this->assertSame(1, $resultado['geral']['enviados']);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function comTenant(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->id, $callback);
    }

    private function criarUsuario(): User
    {
        return $this->comTenant(fn () => User::factory()->create());
    }

    private function criarCliente(): Client
    {
        return $this->comTenant(fn () => ClientFactory::new()->create());
    }

    private function criarOrcamento(User $vendedor, string $status, string $data): Budget
    {
        return $this->comTenant(fn () => Budget::create([
            'user_id' => $vendedor->id,
            'status' => $status,
            'date' => $data,
        ]));
    }

    private function criarOrcamentoComServico(User $vendedor, string $status, string $data, float $valorServico): Budget
    {
        return $this->comTenant(function () use ($vendedor, $status, $data, $valorServico): Budget {
            $budget = Budget::create([
                'user_id' => $vendedor->id,
                'status' => $status,
                'date' => $data,
            ]);

            $servico = Service::create([
                'description' => 'Serviço de teste',
                'name' => 'Serviço de teste',
                'price' => $valorServico,
            ]);

            $budget->services()->attach($servico->id, [
                'quantity' => 1,
                'unit_price' => $valorServico,
                'subtotal' => $valorServico,
            ]);

            return $budget;
        });
    }
}
