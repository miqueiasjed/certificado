<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Receivable;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Financial\WorkOrderMarginService;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Closure;
use Database\Factories\WorkOrderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 18.6 do Plano 18: margem por OS, com receita do título, custo de
 * produto (Plano 17), custo de mão de obra (horas x custo/hora do técnico) e
 * custo de deslocamento (fixo por visita nesta entrega).
 *
 * Nenhum destes cenários anexa produto à OS: o custo de produto sai zero em
 * todos eles (comportamento já coberto por `WorkOrderStockServiceTest`), e o
 * que este arquivo protege é a composição da margem em si e a marcação de
 * `parcial`.
 */
class WorkOrderMarginServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-07-20';

    private WorkOrderMarginService $servico;

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::HOJE.' 12:00:00');

        TenantAtual::limpar();

        $this->servico = app(WorkOrderMarginService::class);
        $this->empresa = Company::query()->firstOrFail();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Componentes separados
    // -----------------------------------------------------------------

    public function test_margem_devolve_os_componentes_separados_e_o_total(): void
    {
        $tecnico = $this->criarTecnico(custoHora: 40.00);
        $os = $this->criarOsComTitulo(valorDoTitulo: 1000.00, technicianId: $tecnico->id, horas: 2.0);

        $resultado = $this->comTenant(fn () => $this->servico->margem($os));

        $this->assertSame($os->id, $resultado['work_order_id']);
        $this->assertSame('1000.00', $resultado['receita']);
        $this->assertSame('0.00', $resultado['custo_produto'], 'a OS não tem produto aplicado');
        $this->assertSame('80.00', $resultado['custo_mao_de_obra'], '2 horas x R$ 40,00/hora');
        $this->assertSame('50.00', $resultado['custo_deslocamento']);
        $this->assertSame('130.00', $resultado['custo_total']);
        $this->assertSame('870.00', $resultado['margem'], '1000,00 - 130,00');
        $this->assertTrue($resultado['deslocamento_e_estimado']);
        $this->assertFalse($resultado['parcial']);
        $this->assertSame([], $resultado['motivos_parcial']);
    }

    public function test_deslocamento_e_estimado_e_sempre_verdadeiro_mesmo_com_margem_completa(): void
    {
        $tecnico = $this->criarTecnico(custoHora: 10.00);
        $os = $this->criarOsComTitulo(valorDoTitulo: 500.00, technicianId: $tecnico->id, horas: 1.0);

        $resultado = $this->comTenant(fn () => $this->servico->margem($os));

        $this->assertTrue($resultado['deslocamento_e_estimado']);
    }

    // -----------------------------------------------------------------
    // Técnico sem custo_hora: mão de obra zerada e margem parcial
    // -----------------------------------------------------------------

    public function test_tecnico_sem_custo_hora_zera_a_mao_de_obra_e_marca_a_margem_como_parcial(): void
    {
        $tecnico = $this->criarTecnico(custoHora: null);
        $os = $this->criarOsComTitulo(valorDoTitulo: 1000.00, technicianId: $tecnico->id, horas: 3.0);

        $resultado = $this->comTenant(fn () => $this->servico->margem($os));

        $this->assertSame('0.00', $resultado['custo_mao_de_obra']);
        $this->assertTrue($resultado['parcial']);
        $this->assertContains(WorkOrderMarginService::MOTIVO_TECNICO_SEM_CUSTO_HORA, $resultado['motivos_parcial']);
        // Sem custo de mão de obra, o total só soma o deslocamento fixo.
        $this->assertSame('50.00', $resultado['custo_total']);
        $this->assertSame('950.00', $resultado['margem']);
    }

    public function test_os_sem_tecnico_vinculado_zera_a_mao_de_obra_e_marca_a_margem_como_parcial(): void
    {
        $os = $this->criarOsComTitulo(valorDoTitulo: 400.00, technicianId: null, horas: 0.0);

        $resultado = $this->comTenant(fn () => $this->servico->margem($os));

        $this->assertSame('0.00', $resultado['custo_mao_de_obra']);
        $this->assertNull($resultado['custo_hora_tecnico']);
        $this->assertTrue($resultado['parcial']);
        $this->assertContains(WorkOrderMarginService::MOTIVO_SEM_TECNICO, $resultado['motivos_parcial']);
    }

    // -----------------------------------------------------------------
    // OS sem título gerado
    // -----------------------------------------------------------------

    public function test_os_sem_titulo_gerado_zera_a_receita_e_marca_a_margem_como_parcial(): void
    {
        $tecnico = $this->criarTecnico(custoHora: 20.00);

        $os = $this->comTenant(fn () => WorkOrderFactory::new()->create([
            'technician_id' => $tecnico->id,
            'start_time' => '2026-07-20 08:00:00',
            'end_time' => '2026-07-20 09:00:00',
        ]));

        $resultado = $this->comTenant(fn () => $this->servico->margem($os));

        $this->assertSame('0.00', $resultado['receita']);
        $this->assertTrue($resultado['parcial']);
        $this->assertContains(WorkOrderMarginService::MOTIVO_SEM_TITULO, $resultado['motivos_parcial']);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function comTenant(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->id, $callback);
    }

    private function criarTecnico(?float $custoHora): Technician
    {
        return $this->comTenant(function () use ($custoHora): Technician {
            $usuario = User::factory()->create(['is_active' => true]);

            return Technician::create([
                'name' => 'Técnico de teste',
                'email' => 'tecnico'.uniqid().'@dedetizadora.test',
                'phone' => '11999990000',
                'is_active' => true,
                'user_id' => $usuario->id,
                'custo_hora' => $custoHora,
            ]);
        });
    }

    /**
     * OS com horário de execução fixado (para `duration_hours` dar
     * exatamente `$horas`) e um título a receber gerado no valor informado.
     */
    private function criarOsComTitulo(float $valorDoTitulo, ?int $technicianId, float $horas): WorkOrder
    {
        return $this->comTenant(function () use ($valorDoTitulo, $technicianId, $horas): WorkOrder {
            $inicio = '2026-07-20 08:00:00';
            $fim = Carbon::parse($inicio)->addMinutes((int) round($horas * 60))->format('Y-m-d H:i:s');

            $os = WorkOrderFactory::new()->create([
                'technician_id' => $technicianId,
                'final_amount' => $valorDoTitulo,
                'start_time' => $inicio,
                'end_time' => $fim,
            ]);

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
}
