<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Client;
use App\Models\Company;
use App\Models\Goal;
use App\Models\Receivable;
use App\Models\ReceivableInstallment;
use App\Models\Service;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Commercial\GoalService;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Closure;
use Database\Factories\ClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Task 23.3 do Plano 23: acompanhamento de meta por pessoa e por tipo, com
 * projeção a partir do quinto dia útil da competência.
 *
 * Julho de 2026 é o mês de referência da suíte porque o dia 1 cai numa
 * quarta-feira: o quinto dia útil (segunda a sexta) é 07/07/2026, o que dá
 * uma data redonda para testar os dois lados do corte (06/07 ainda sem
 * projeção, 07/07 já com projeção).
 */
class GoalServiceTest extends TestCase
{
    use RefreshDatabase;

    private const COMPETENCIA = '2026-07';

    private GoalService $servico;

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->servico = app(GoalService::class);
        $this->empresa = Company::query()->firstOrFail();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Alvo, realizado e percentual, por pessoa
    // -----------------------------------------------------------------

    public function test_valor_vendido_soma_orcamentos_aprovados_e_convertidos_do_vendedor_no_periodo(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');

        $vendedor = $this->criarUsuario();
        $outroVendedor = $this->criarUsuario();

        $this->criarOrcamentoComServico($vendedor, 'approved', '2026-07-05', 700.00);
        $this->criarOrcamentoComServico($vendedor, 'converted', '2026-07-10', 300.00);
        // Rascunho não é tentativa de venda, e orçamento de fora do mês não
        // conta: os dois ficam fora da soma.
        $this->criarOrcamentoComServico($vendedor, 'draft', '2026-07-12', 999.00);
        $this->criarOrcamentoComServico($vendedor, 'approved', '2026-06-30', 999.00);
        // Orçamento de outro vendedor não pode inflar o realizado deste.
        $this->criarOrcamentoComServico($outroVendedor, 'approved', '2026-07-05', 500.00);

        $this->criarMeta($vendedor, 'valor_vendido', self::COMPETENCIA, 2000.00);

        $resultado = $this->comTenant(fn () => $this->servico->acompanhamento(self::COMPETENCIA, $vendedor));

        $this->assertCount(1, $resultado['metas']);
        $linha = $resultado['metas'][0];

        $this->assertSame($vendedor->id, $linha['user_id']);
        $this->assertSame(1000.0, $linha['realizado']);
        $this->assertSame(50.0, $linha['percentual_atingido']);
    }

    public function test_desconto_e_descontado_uma_vez_por_orcamento_mesmo_com_varios_servicos(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');

        $vendedor = $this->criarUsuario();

        $this->comTenant(function () use ($vendedor): void {
            $budget = Budget::create([
                'user_id' => $vendedor->id,
                'status' => 'approved',
                'date' => '2026-07-05',
                'discount' => 100.00,
            ]);

            $servicoA = Service::create(['description' => 'Serviço A', 'name' => 'Serviço A', 'price' => 400]);
            $servicoB = Service::create(['description' => 'Serviço B', 'name' => 'Serviço B', 'price' => 600]);

            $budget->services()->attach($servicoA->id, ['quantity' => 1, 'unit_price' => 400, 'subtotal' => 400]);
            $budget->services()->attach($servicoB->id, ['quantity' => 1, 'unit_price' => 600, 'subtotal' => 600]);
        });

        $this->criarMeta($vendedor, 'valor_vendido', self::COMPETENCIA, 1000.00);

        $resultado = $this->comTenant(fn () => $this->servico->acompanhamento(self::COMPETENCIA, $vendedor));

        // 400 + 600 - 100 (desconto do orçamento, uma vez só) = 900.
        $this->assertSame(900.0, $resultado['metas'][0]['realizado']);
    }

    public function test_quantidade_de_os_concluida_conta_por_tecnico_com_fallback_para_data_agendada(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');

        $usuarioTecnico = $this->criarUsuario();
        $tecnico = $this->criarTecnico($usuarioTecnico);

        $this->criarOsConcluida($tecnico, ['end_time' => '2026-07-03 15:00:00']);
        // Sem end_time: cai no fallback de scheduled_date, mesmo critério de
        // SatisfactionSurveyService.
        $this->criarOsConcluida($tecnico, ['end_time' => null, 'scheduled_date' => '2026-07-08']);
        // Fora da competência e fora do status: nenhuma das duas conta.
        $this->criarOsConcluida($tecnico, ['end_time' => '2026-06-30 15:00:00']);
        $this->criarOsConcluida($tecnico, ['end_time' => '2026-07-04 15:00:00', 'status' => 'scheduled']);

        $this->criarMeta($usuarioTecnico, 'quantidade_os', self::COMPETENCIA, 5.00);

        $resultado = $this->comTenant(fn () => $this->servico->acompanhamento(self::COMPETENCIA, $usuarioTecnico));

        $this->assertSame(2.0, $resultado['metas'][0]['realizado']);
    }

    // -----------------------------------------------------------------
    // Valor recebido: atribuído ao vendedor do orçamento mais recente do cliente
    // -----------------------------------------------------------------

    public function test_valor_recebido_e_atribuido_ao_vendedor_do_orcamento_aprovado_mais_recente_do_cliente(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');

        $vendedorAntigo = $this->criarUsuario();
        $vendedorRecente = $this->criarUsuario();
        $cliente = $this->criarCliente();

        // O mesmo cliente teve orçamento aprovado de dois vendedores em
        // momentos diferentes: quem "é dono" do cliente para efeito de valor
        // recebido é o mais recente.
        $this->criarOrcamentoComServico($vendedorAntigo, 'approved', '2026-05-01', 100.00, $cliente);
        $this->criarOrcamentoComServico($vendedorRecente, 'converted', '2026-06-01', 200.00, $cliente);

        $this->criarParcelaBaixada($cliente, 1500.00, '2026-07-10');

        $this->criarMeta($vendedorAntigo, 'valor_recebido', self::COMPETENCIA, 1000.00);
        $this->criarMeta($vendedorRecente, 'valor_recebido', self::COMPETENCIA, 1000.00);

        $resultado = $this->comTenant(fn () => $this->servico->acompanhamento(self::COMPETENCIA));

        $linhas = collect($resultado['metas'])->keyBy('user_id');

        $this->assertSame(0.0, $linhas[$vendedorAntigo->id]['realizado']);
        $this->assertSame(1500.0, $linhas[$vendedorRecente->id]['realizado']);
    }

    public function test_valor_recebido_ignora_parcela_ainda_nao_baixada(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');

        $vendedor = $this->criarUsuario();
        $cliente = $this->criarCliente();

        $this->criarOrcamentoComServico($vendedor, 'approved', '2026-06-01', 100.00, $cliente);

        $this->comTenant(function () use ($cliente): void {
            $titulo = Receivable::create([
                'client_id' => $cliente->id,
                'descricao' => 'Título em aberto',
                'valor_total' => 500.00,
                'emitido_em' => '2026-07-01',
                'situacao' => 'aberto',
            ]);

            ReceivableInstallment::create([
                'receivable_id' => $titulo->id,
                'numero' => 1,
                'valor' => 500.00,
                'vencimento' => '2026-07-15',
                'situacao' => 'aberta',
                // Sem pago_em nem valor_pago: ainda não foi recebida.
            ]);
        });

        $this->criarMeta($vendedor, 'valor_recebido', self::COMPETENCIA, 1000.00);

        $resultado = $this->comTenant(fn () => $this->servico->acompanhamento(self::COMPETENCIA, $vendedor));

        $this->assertSame(0.0, $resultado['metas'][0]['realizado']);
    }

    // -----------------------------------------------------------------
    // Conversão: sempre com a contagem, taxa omitida com amostra pequena
    // -----------------------------------------------------------------

    public function test_meta_de_conversao_traz_enviados_e_aprovados_junto_com_a_taxa(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');

        $vendedor = $this->criarUsuario();

        $this->criarOrcamento($vendedor, 'approved', '2026-07-01');
        $this->criarOrcamento($vendedor, 'approved', '2026-07-02');
        $this->criarOrcamento($vendedor, 'refused', '2026-07-03');
        $this->criarOrcamento($vendedor, 'negotiating', '2026-07-04');

        $this->criarMeta($vendedor, 'conversao', self::COMPETENCIA, 50.00);

        $resultado = $this->comTenant(fn () => $this->servico->acompanhamento(self::COMPETENCIA, $vendedor));
        $linha = $resultado['metas'][0];

        $this->assertSame(4, $linha['enviados']);
        $this->assertSame(2, $linha['aprovados']);
        $this->assertFalse($linha['amostra_insuficiente']);
        $this->assertSame(50.0, $linha['realizado']);
        $this->assertSame(100.0, $linha['percentual_atingido']);
        $this->assertNull($linha['projecao'], 'meta de conversão não recebe projeção linear');
    }

    public function test_meta_de_conversao_com_menos_de_tres_orcamentos_omite_a_taxa_mas_mostra_a_contagem(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');

        $vendedor = $this->criarUsuario();

        $this->criarOrcamento($vendedor, 'approved', '2026-07-01');
        $this->criarOrcamento($vendedor, 'sent', '2026-07-02');

        $this->criarMeta($vendedor, 'conversao', self::COMPETENCIA, 50.00);

        $resultado = $this->comTenant(fn () => $this->servico->acompanhamento(self::COMPETENCIA, $vendedor));
        $linha = $resultado['metas'][0];

        $this->assertSame(2, $linha['enviados']);
        $this->assertSame(1, $linha['aprovados']);
        $this->assertTrue($linha['amostra_insuficiente']);
        $this->assertNull($linha['realizado']);
        $this->assertNull($linha['percentual_atingido']);
    }

    // -----------------------------------------------------------------
    // Projeção só a partir do quinto dia útil
    // -----------------------------------------------------------------

    public function test_projecao_fica_indisponivel_antes_do_quinto_dia_util(): void
    {
        // 06/07/2026 é o quarto dia útil de julho (1qua,2qui,3sex,6seg).
        Carbon::setTestNow('2026-07-06 09:00:00');

        $vendedor = $this->criarUsuario();
        $this->criarOrcamentoComServico($vendedor, 'approved', '2026-07-01', 100.00);
        $this->criarMeta($vendedor, 'valor_vendido', self::COMPETENCIA, 3100.00);

        $resultado = $this->comTenant(fn () => $this->servico->acompanhamento(self::COMPETENCIA, $vendedor));

        $this->assertFalse($resultado['projecao_disponivel']);
        $this->assertNull($resultado['metas'][0]['projecao']);
    }

    public function test_projecao_aparece_a_partir_do_quinto_dia_util(): void
    {
        // 07/07/2026 é o quinto dia útil de julho.
        Carbon::setTestNow('2026-07-07 09:00:00');

        $vendedor = $this->criarUsuario();
        // 700 realizados em 7 dias corridos (01 a 07/07) projeta 700/7*31 = 3100,
        // com julho tendo 31 dias.
        $this->criarOrcamentoComServico($vendedor, 'approved', '2026-07-02', 700.00);
        $this->criarMeta($vendedor, 'valor_vendido', self::COMPETENCIA, 3100.00);

        $resultado = $this->comTenant(fn () => $this->servico->acompanhamento(self::COMPETENCIA, $vendedor));

        $this->assertTrue($resultado['projecao_disponivel']);
        $this->assertSame(3100.0, $resultado['metas'][0]['projecao']);
    }

    public function test_competencia_com_formato_invalido_lanca_excecao(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->comTenant(fn () => $this->servico->acompanhamento('07-2026', null));
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas
    // -----------------------------------------------------------------

    public function test_acompanhamento_nao_mistura_meta_nem_orcamento_de_outra_empresa(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');

        $vendedor = $this->criarUsuario();
        $this->criarOrcamentoComServico($vendedor, 'approved', '2026-07-05', 500.00);
        $this->criarMeta($vendedor, 'valor_vendido', self::COMPETENCIA, 1000.00);

        $outraEmpresa = Company::create([
            'name' => 'Dedetizadora Concorrente',
            'cnpj' => '22.222.222/0001-22',
            'email' => 'concorrente@teste.test',
        ]);

        TenantAtual::comTenant($outraEmpresa->id, function () use ($vendedor): void {
            $outroVendedor = User::factory()->create();
            $budget = Budget::create([
                'user_id' => $outroVendedor->id,
                'status' => 'approved',
                'date' => '2026-07-05',
            ]);
            $servico = Service::create(['description' => 'Serviço concorrente', 'name' => 'Serviço concorrente', 'price' => 99999]);
            $budget->services()->attach($servico->id, ['quantity' => 1, 'unit_price' => 99999, 'subtotal' => 99999]);

            // Meta do mesmo user_id, mas em outra empresa: não pode aparecer
            // na consulta da empresa original.
            Goal::create([
                'user_id' => $vendedor->id,
                'tipo' => 'valor_vendido',
                'competencia' => self::COMPETENCIA,
                'alvo' => 1.00,
            ]);
        });

        $resultado = $this->comTenant(fn () => $this->servico->acompanhamento(self::COMPETENCIA));

        $this->assertCount(1, $resultado['metas']);
        $this->assertSame(500.0, $resultado['metas'][0]['realizado']);
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

    private function criarMeta(User $usuario, string $tipo, string $competencia, float $alvo): Goal
    {
        return $this->comTenant(fn () => Goal::create([
            'user_id' => $usuario->id,
            'tipo' => $tipo,
            'competencia' => $competencia,
            'alvo' => $alvo,
        ]));
    }

    private function criarOrcamento(User $vendedor, string $status, string $data, ?Client $cliente = null): Budget
    {
        return $this->comTenant(fn () => Budget::create([
            'client_id' => $cliente?->id,
            'user_id' => $vendedor->id,
            'status' => $status,
            'date' => $data,
        ]));
    }

    private function criarOrcamentoComServico(
        User $vendedor,
        string $status,
        string $data,
        float $valorServico,
        ?Client $cliente = null
    ): Budget {
        return $this->comTenant(function () use ($vendedor, $status, $data, $valorServico, $cliente): Budget {
            $budget = Budget::create([
                'client_id' => $cliente?->id,
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

    private function criarTecnico(User $usuario): Technician
    {
        return $this->comTenant(fn () => Technician::create([
            'name' => $usuario->name,
            'email' => 'tecnico'.uniqid().'@teste.test',
            'phone' => '(11) 90000-0000',
            'user_id' => $usuario->id,
        ]));
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function criarOsConcluida(Technician $tecnico, array $atributos = []): WorkOrder
    {
        return $this->comTenant(fn () => WorkOrder::factory()->create(array_merge([
            'technician_id' => $tecnico->id,
            'status' => 'completed',
        ], $atributos)));
    }

    private function criarParcelaBaixada(Client $cliente, float $valor, string $pagoEm): ReceivableInstallment
    {
        return $this->comTenant(function () use ($cliente, $valor, $pagoEm): ReceivableInstallment {
            $titulo = Receivable::create([
                'client_id' => $cliente->id,
                'descricao' => 'Título de teste',
                'valor_total' => $valor,
                'emitido_em' => $pagoEm,
                'situacao' => 'quitado',
            ]);

            return ReceivableInstallment::create([
                'receivable_id' => $titulo->id,
                'numero' => 1,
                'valor' => $valor,
                'vencimento' => $pagoEm,
                'valor_pago' => $valor,
                'pago_em' => $pagoEm,
                'situacao' => 'paga',
            ]);
        });
    }
}
