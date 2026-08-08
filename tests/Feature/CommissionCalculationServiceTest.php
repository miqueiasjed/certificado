<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\Client;
use App\Models\Commission;
use App\Models\CommissionItem;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\ReceivableInstallment;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Commission\CommissionCalculationService;
use App\Services\Commission\CommissionClosingService;
use App\Services\ReceivableService;
use App\Services\SettlementService;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Closure;
use Database\Factories\ClientFactory;
use Database\Factories\WorkOrderFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Task 23.2 do Plano 23: apuração de comissão, fechamento e histórico.
 *
 * Cobertura mínima desta task, focada nos critérios de aceitação do arquivo
 * da task (`.claude/tasks/23/23.2.md`); a suíte completa e definitiva do
 * módulo de comissão é a Task 23.9, que pode ampliar este arquivo.
 *
 * O relógio da suíte fica fixo em 15/08/2026, bem depois de todas as
 * competências usadas nos cenários (06 e 07/2026 já terminaram; 08/2026 é o
 * mês corrente, usado como "competência atual" para o estorno em mês
 * fechado).
 */
class CommissionCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const AGORA = '2026-08-15 12:00:00';

    private CommissionCalculationService $apuracao;

    private CommissionClosingService $fechamento;

    private ReceivableService $receivableService;

    private SettlementService $settlementService;

    private Company $empresa;

    /**
     * Opera a baixa/estorno de parcela: tem `financeiro-estornar`.
     */
    private User $operadorFinanceiro;

    /**
     * Recebe o catálogo inteiro de permissões (papel administrador), inclusive
     * `comissoes-reabrir`.
     */
    private User $administrador;

    /**
     * Usuário do mesmo tenant sem nenhuma permissão extra.
     */
    private User $semPermissao;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::AGORA);
        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->apuracao = app(CommissionCalculationService::class);
        $this->fechamento = app(CommissionClosingService::class);
        $this->receivableService = app(ReceivableService::class);
        $this->settlementService = app(SettlementService::class);
        $this->empresa = Company::query()->firstOrFail();

        $this->operadorFinanceiro = $this->comTenant(function () {
            $usuario = User::factory()->create(['company_id' => $this->empresa->id]);
            $usuario->givePermissionTo(SettlementService::PERMISSAO_ESTORNO);

            return $usuario;
        });

        $this->administrador = $this->comTenant(function () {
            $usuario = User::factory()->create(['company_id' => $this->empresa->id]);
            $usuario->assignRole('administrador');

            return $usuario;
        });

        $this->semPermissao = $this->comTenant(fn () => User::factory()->create(['company_id' => $this->empresa->id]));
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // As três bases
    // -----------------------------------------------------------------

    public function test_apuracao_calcula_a_comissao_pela_base_vendido(): void
    {
        $vendedor = $this->criarVendedor();
        $cliente = $this->criarCliente();

        $this->criarRegra([
            'user_id' => $vendedor->id,
            'tipo' => 'vendedor',
            'base' => 'vendido',
            'valor' => 10,
            'vigencia_inicio' => '2026-01-01',
        ]);

        $this->criarOrcamentoAprovado($vendedor, $cliente, '2026-07-10', '1000.00');

        $resultado = $this->comTenant(fn () => $this->apuracao->apurar('2026-07'));

        $this->assertSame(1, $resultado['pessoas_processadas']);
        $this->assertSame(1, $resultado['itens_gravados']);

        $comissao = Commission::query()->where('user_id', $vendedor->id)->where('competencia', '2026-07')->firstOrFail();
        $item = $comissao->items()->firstOrFail();

        $this->assertNull($comissao->technician_id, 'comissão de vendedor não pode preencher technician_id');
        $this->assertSame('1000.00', $item->base_valor);
        $this->assertSame('100.00', $item->valor, '10% de 1000,00');
        $this->assertSame('100.00', $comissao->valor_total);
        $this->assertSame('orcamento', $item->origem_tipo);
    }

    public function test_apuracao_calcula_a_comissao_pela_base_recebido(): void
    {
        $vendedor = $this->criarVendedor();
        $cliente = $this->criarCliente();

        $this->criarRegra([
            'user_id' => $vendedor->id,
            'tipo' => 'vendedor',
            'base' => 'recebido',
            'valor' => 8,
            'vigencia_inicio' => '2026-01-01',
        ]);

        // Orçamento aprovado antes do recebimento: é dele que o vendedor é
        // resolvido (ver CommissionCalculationService::resolverVendedorDoRecebimento).
        $this->criarOrcamentoAprovado($vendedor, $cliente, '2026-06-15', '500.00');

        $parcela = $this->criarParcelaPagaDoCliente($cliente, '2026-07-20', 500.00);

        $resultado = $this->comTenant(fn () => $this->apuracao->apurar('2026-07'));

        $this->assertSame(1, $resultado['itens_gravados']);

        $comissao = Commission::query()->where('user_id', $vendedor->id)->where('competencia', '2026-07')->firstOrFail();
        $item = $comissao->items()->firstOrFail();

        $this->assertSame('parcela', $item->origem_tipo);
        $this->assertSame($parcela->id, $item->origem_id);
        $this->assertSame('500.00', $item->base_valor);
        $this->assertSame('40.00', $item->valor, '8% de 500,00');
    }

    public function test_apuracao_calcula_a_comissao_pela_base_executado(): void
    {
        $tecnico = $this->criarTecnico();

        $this->criarRegra([
            'technician_id' => $tecnico->id,
            'tipo' => 'tecnico',
            'base' => 'executado',
            'valor' => 5,
            'vigencia_inicio' => '2026-01-01',
        ]);

        $this->criarOsExecutada($tecnico, '2026-07-12', 800.00);

        $resultado = $this->comTenant(fn () => $this->apuracao->apurar('2026-07'));

        $this->assertSame(1, $resultado['itens_gravados']);

        $comissao = Commission::query()->where('technician_id', $tecnico->id)->where('competencia', '2026-07')->firstOrFail();
        $item = $comissao->items()->firstOrFail();

        $this->assertNull($comissao->user_id);
        $this->assertSame('ordem_servico', $item->origem_tipo);
        $this->assertSame('800.00', $item->base_valor);
        $this->assertSame('40.00', $item->valor, '5% de 800,00');
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas (Task 23.9)
    // -----------------------------------------------------------------

    /**
     * `CommissionCalculationService::apurar()` não recebe o tenant por
     * parâmetro: confia inteiramente no escopo global de `BelongsToCompany`
     * (via `TenantAtual`), o mesmo mecanismo que protege toda consulta do
     * sistema. Este teste prova que a apuração de uma empresa não enxerga
     * orçamento, regra nem vendedor de outra - a falha mais grave possível
     * neste projeto (ver `CLAUDE.md`).
     */
    public function test_apuracao_de_uma_empresa_nao_considera_fato_de_outra_empresa(): void
    {
        $outraEmpresa = Company::create([
            'name' => 'Dedetizadora Vizinha da Comissão',
            'cnpj' => '44.444.444/0001-44',
            'email' => 'contato@vizinha-apuracao.test',
        ]);

        $vendedorAlheio = TenantAtual::comTenant($outraEmpresa->id, function () use ($outraEmpresa) {
            $vendedor = User::factory()->create(['company_id' => $outraEmpresa->id]);
            $cliente = ClientFactory::new()->create();

            CommissionRule::create([
                'user_id' => $vendedor->id,
                'tipo' => 'vendedor',
                'base' => 'vendido',
                'forma' => 'percentual',
                'valor' => '10.0000',
                'vigencia_inicio' => '2026-01-01',
                'vigencia_fim' => null,
                'ativa' => true,
            ]);

            // Relógio movido para a data do fato: `updated_at` é o proxy que
            // `instanteDeAprovacao()` usa quando não há `AuditLog` de
            // transição de status (aqui o orçamento já nasce `approved`,
            // sem passar por `update()`), mesmo critério de
            // `criarOrcamentoAprovado()`.
            Carbon::setTestNow('2026-07-10 09:00:00');

            $orcamento = Budget::create([
                'client_id' => $cliente->id,
                'user_id' => $vendedor->id,
                'date' => '2026-07-10',
                'priority' => 'normal',
                'status' => 'approved',
            ]);

            $servico = Service::create([
                'name' => 'Dedetização (empresa vizinha)',
                'price' => '1000.00',
                'is_active' => true,
            ]);

            $orcamento->services()->attach($servico->id, [
                'quantity' => 1,
                'unit_price' => '1000.00',
                'subtotal' => '1000.00',
            ]);

            Carbon::setTestNow(self::AGORA);

            return $vendedor;
        });

        // A empresa principal da suíte não tem nenhum fato em julho: a
        // apuração dela não pode gravar nada, e muito menos a partir do
        // orçamento da empresa vizinha.
        $resultado = $this->comTenant(fn () => $this->apuracao->apurar('2026-07'));

        $this->assertSame(0, $resultado['pessoas_processadas']);
        $this->assertSame(0, $resultado['itens_gravados']);
        $this->assertSame(0, $this->comTenant(fn () => Commission::query()->count()));

        $comissaoAlheia = TenantAtual::comTenant(
            $outraEmpresa->id,
            fn () => Commission::query()->where('user_id', $vendedorAlheio->id)->first()
        );
        $this->assertNull($comissaoAlheia, 'a apuração da empresa principal não pode ter gravado comissão do vendedor da empresa vizinha');

        // Controle: a apuração da empresa vizinha, na própria competência e
        // no próprio tenant, continua funcionando e enxerga o próprio
        // orçamento normalmente.
        $resultadoAlheio = TenantAtual::comTenant($outraEmpresa->id, fn () => $this->apuracao->apurar('2026-07'));
        $this->assertSame(1, $resultadoAlheio['pessoas_processadas']);

        $comissaoAlheiaAgora = TenantAtual::comTenant(
            $outraEmpresa->id,
            fn () => Commission::query()->where('user_id', $vendedorAlheio->id)->firstOrFail()
        );
        $this->assertSame('100.00', $comissaoAlheiaAgora->valor_total, '10% de 1000,00, apurado dentro do próprio tenant');
    }

    // -----------------------------------------------------------------
    // Regra vigente na data do fato
    // -----------------------------------------------------------------

    public function test_a_regra_vigente_e_a_da_data_do_fato_mesmo_com_regra_mais_nova_ja_ativa(): void
    {
        $vendedor = $this->criarVendedor();
        $cliente = $this->criarCliente();

        // Regra antiga, vigente até maio.
        $this->criarRegra([
            'user_id' => $vendedor->id,
            'tipo' => 'vendedor',
            'base' => 'vendido',
            'valor' => 5,
            'vigencia_inicio' => '2026-01-01',
            'vigencia_fim' => '2026-05-31',
        ]);

        // Regra nova, vigente desde junho - é a "regra de hoje" no momento da
        // apuração (relógio da suíte em agosto), mas não vale para maio.
        $this->criarRegra([
            'user_id' => $vendedor->id,
            'tipo' => 'vendedor',
            'base' => 'vendido',
            'valor' => 12,
            'vigencia_inicio' => '2026-06-01',
        ]);

        $this->criarOrcamentoAprovado($vendedor, $cliente, '2026-05-10', '1000.00');

        $resultado = $this->comTenant(fn () => $this->apuracao->apurar('2026-05'));

        $this->assertSame(1, $resultado['itens_gravados']);

        $item = Commission::query()->where('user_id', $vendedor->id)->where('competencia', '2026-05')->firstOrFail()->items()->firstOrFail();

        $this->assertSame('5.0000', $item->percentual_ou_fixo, 'a regra vigente em maio é a antiga (5%), não a de hoje (12%)');
        $this->assertSame('50.00', $item->valor);
    }

    // -----------------------------------------------------------------
    // Reapurar aberta substitui; fechada é ignorada
    // -----------------------------------------------------------------

    public function test_reapurar_competencia_aberta_substitui_os_itens(): void
    {
        $vendedor = $this->criarVendedor();
        $cliente = $this->criarCliente();

        $regra = $this->criarRegra([
            'user_id' => $vendedor->id,
            'tipo' => 'vendedor',
            'base' => 'vendido',
            'valor' => 10,
            'vigencia_inicio' => '2026-01-01',
        ]);

        $this->criarOrcamentoAprovado($vendedor, $cliente, '2026-07-05', '1000.00');

        $this->comTenant(fn () => $this->apuracao->apurar('2026-07'));

        $comissao = Commission::query()->where('user_id', $vendedor->id)->where('competencia', '2026-07')->firstOrFail();
        $this->assertSame('100.00', $comissao->valor_total);
        $itemOriginalId = $comissao->items()->firstOrFail()->id;

        // Muda o percentual da MESMA regra (ainda vigente na mesma data) e
        // reapura: o item precisa ser substituído, refletindo o novo
        // percentual, não somado ao antigo.
        $this->comTenant(fn () => $regra->update(['valor' => 20]));
        $this->comTenant(fn () => $this->apuracao->apurar('2026-07'));

        $comissao->refresh();
        $itens = $comissao->items()->get();

        $this->assertCount(1, $itens, 'reapurar duplicou o item em vez de substituir');
        $this->assertNotSame($itemOriginalId, $itens->first()->id, 'o item antigo deveria ter sido apagado e um novo criado');
        $this->assertSame('200.00', $itens->first()->valor, '20% de 1000,00');
        $this->assertSame('200.00', $comissao->valor_total);
    }

    public function test_competencia_fechada_nao_e_alterada_pela_reapuracao(): void
    {
        $vendedor = $this->criarVendedor();
        $cliente = $this->criarCliente();

        $this->criarRegra([
            'user_id' => $vendedor->id,
            'tipo' => 'vendedor',
            'base' => 'vendido',
            'valor' => 10,
            'vigencia_inicio' => '2026-01-01',
        ]);

        $this->criarOrcamentoAprovado($vendedor, $cliente, '2026-06-05', '1000.00');
        $this->comTenant(fn () => $this->apuracao->apurar('2026-06'));

        $comissao = Commission::query()->where('user_id', $vendedor->id)->where('competencia', '2026-06')->firstOrFail();
        $this->comTenant(fn () => $this->fechamento->fechar($comissao, $this->administrador));

        // Um segundo orçamento do mesmo vendedor, na mesma competência já
        // fechada: reapurar não pode gerar item nenhum para ele.
        $this->criarOrcamentoAprovado($vendedor, $cliente, '2026-06-20', '2000.00');

        $resultado = $this->comTenant(fn () => $this->apuracao->apurar('2026-06'));

        $this->assertSame(0, $resultado['pessoas_processadas']);
        $this->assertSame(1, $resultado['competencias_fechadas_ignoradas']);

        $comissao->refresh();
        $this->assertCount(1, $comissao->items()->get(), 'a comissão fechada ganhou item novo');
        $this->assertSame('100.00', $comissao->valor_total, 'o total da comissão fechada mudou');
        $this->assertSame('fechada', $comissao->situacao);
    }

    // -----------------------------------------------------------------
    // Fechar, reabrir, pagar
    // -----------------------------------------------------------------

    public function test_fechar_exige_que_a_competencia_ja_tenha_terminado(): void
    {
        $vendedor = $this->criarVendedor();

        // Competência corrente (agosto, relógio da suíte): ainda não terminou.
        $comissaoAberta = $this->comTenant(fn () => Commission::create([
            'user_id' => $vendedor->id,
            'competencia' => '2026-08',
            'situacao' => 'aberta',
            'valor_total' => '0.00',
        ]));

        $this->expectException(ValidationException::class);

        $this->comTenant(fn () => $this->fechamento->fechar($comissaoAberta, $this->administrador));
    }

    public function test_fechar_torna_imutavel_e_reabrir_exige_permissao_e_justificativa(): void
    {
        $vendedor = $this->criarVendedor();

        $comissao = $this->comTenant(fn () => Commission::create([
            'user_id' => $vendedor->id,
            'competencia' => '2026-07',
            'situacao' => 'aberta',
            'valor_total' => '150.00',
        ]));

        $fechada = $this->comTenant(fn () => $this->fechamento->fechar($comissao, $this->administrador));

        $this->assertSame('fechada', $fechada->situacao);
        $this->assertNotNull($fechada->fechada_em);
        $this->assertSame($this->administrador->id, $fechada->fechada_por);

        // Reabrir sem permissão é barrado.
        try {
            $this->comTenant(fn () => $this->fechamento->reabrir($fechada, $this->semPermissao, 'Motivo suficientemente longo para reabrir'));
            $this->fail('reabertura sem permissão precisava ser barrada');
        } catch (AuthorizationException $excecao) {
            $this->assertStringContainsString('permissão', $excecao->getMessage());
        }
        $this->assertSame('fechada', $fechada->fresh()->situacao, 'a tentativa sem permissão alterou a comissão');

        // Reabrir com permissão, mas justificativa curta demais.
        try {
            $this->comTenant(fn () => $this->fechamento->reabrir($fechada, $this->administrador, 'curta'));
            $this->fail('justificativa curta precisava ser recusada');
        } catch (ValidationException $excecao) {
            $this->assertArrayHasKey('justificativa', $excecao->errors());
        }

        // Reabrir com permissão e justificativa válida.
        $reaberta = $this->comTenant(fn () => $this->fechamento->reabrir(
            $fechada->fresh(),
            $this->administrador,
            'Vendedor reclamou de valor errado, reabrindo para corrigir a apuração'
        ));

        $this->assertSame('aberta', $reaberta->situacao);
        $this->assertNull($reaberta->fechada_em);
        $this->assertNull($reaberta->fechada_por);

        $log = AuditLog::query()
            ->where('auditable_type', Commission::class)
            ->where('auditable_id', $comissao->id)
            ->where('acao', 'reaberta')
            ->firstOrFail();

        $this->assertSame($this->administrador->id, $log->user_id);
        $this->assertStringContainsString('Vendedor reclamou', $log->valores_depois['justificativa']);
    }

    public function test_marcar_como_paga_pode_gerar_titulo_a_pagar_vinculado(): void
    {
        $vendedor = $this->criarVendedor();

        $comissao = $this->comTenant(fn () => Commission::create([
            'user_id' => $vendedor->id,
            'competencia' => '2026-07',
            'situacao' => 'fechada',
            'valor_total' => '300.00',
            'fechada_em' => now(),
            'fechada_por' => $this->administrador->id,
        ]));

        $fornecedor = $this->comTenant(fn () => Supplier::create([
            'nome' => 'Vendedor cadastrado como fornecedor para pagamento de comissão',
            'ativo' => true,
        ]));

        $paga = $this->comTenant(fn () => $this->fechamento->marcarComoPaga($comissao, [
            'gerar_titulo' => true,
            'supplier_id' => $fornecedor->id,
            'vencimento' => '2026-08-10',
        ]));

        $this->assertSame('paga', $paga->situacao);
        $this->assertNotNull($paga->paga_em);
        $this->assertNotNull($paga->payable_id);

        $titulo = $paga->payable()->firstOrFail();
        $this->assertSame('300.00', $titulo->valor_total);
        $this->assertSame($fornecedor->id, $titulo->supplier_id);
    }

    // -----------------------------------------------------------------
    // Estorno
    // -----------------------------------------------------------------

    public function test_estorno_em_competencia_aberta_reduz_a_comissao(): void
    {
        $vendedor = $this->criarVendedor();
        $cliente = $this->criarCliente();

        $this->criarRegra([
            'user_id' => $vendedor->id,
            'tipo' => 'vendedor',
            'base' => 'recebido',
            'valor' => 10,
            'vigencia_inicio' => '2026-01-01',
        ]);

        $this->criarOrcamentoAprovado($vendedor, $cliente, '2026-07-01', '500.00');
        $parcela = $this->criarParcelaPagaDoCliente($cliente, '2026-07-15', 500.00);

        // A competência corrente é agosto (relógio da suíte); 2026-07 já
        // terminou, mas a comissão nasce ABERTA até alguém fechar - a
        // apuração em si não fecha nada.
        $this->comTenant(fn () => $this->apuracao->apurar('2026-07'));

        $comissao = Commission::query()->where('user_id', $vendedor->id)->where('competencia', '2026-07')->firstOrFail();
        $this->assertSame('50.00', $comissao->valor_total);
        $this->assertSame('aberta', $comissao->situacao);

        $this->comTenant(fn () => $this->settlementService->estornar(
            $parcela->fresh(),
            'Cliente pediu cancelamento e o valor foi devolvido em dinheiro',
            $this->operadorFinanceiro
        ));

        $comissao->refresh();

        $this->assertSame('0.00', $comissao->valor_total, 'o estorno em competência aberta precisa reduzir o total');
        $this->assertSame(
            0,
            CommissionItem::query()->where('origem_tipo', 'parcela')->where('origem_id', $parcela->id)->count(),
            'o item da parcela estornada deveria ter sido removido'
        );
    }

    public function test_estorno_em_competencia_fechada_gera_item_negativo_na_atual(): void
    {
        $vendedor = $this->criarVendedor();
        $cliente = $this->criarCliente();

        $this->criarRegra([
            'user_id' => $vendedor->id,
            'tipo' => 'vendedor',
            'base' => 'recebido',
            'valor' => 10,
            'vigencia_inicio' => '2026-01-01',
        ]);

        $this->criarOrcamentoAprovado($vendedor, $cliente, '2026-06-01', '500.00');
        $parcela = $this->criarParcelaPagaDoCliente($cliente, '2026-06-15', 500.00);

        $this->comTenant(fn () => $this->apuracao->apurar('2026-06'));

        $comissaoOriginal = Commission::query()->where('user_id', $vendedor->id)->where('competencia', '2026-06')->firstOrFail();
        $this->comTenant(fn () => $this->fechamento->fechar($comissaoOriginal, $this->administrador));

        $this->comTenant(fn () => $this->settlementService->estornar(
            $parcela->fresh(),
            'Estorno depois do fechamento da comissão de junho, por acordo com o cliente',
            $this->operadorFinanceiro
        ));

        // A comissão fechada de junho continua intocada.
        $comissaoOriginal->refresh();
        $this->assertSame('fechada', $comissaoOriginal->situacao);
        $this->assertSame('50.00', $comissaoOriginal->valor_total, 'a comissão fechada não pode mudar');
        $this->assertCount(1, $comissaoOriginal->items()->get());

        // A competência atual (agosto) ganha um item negativo referenciando
        // a mesma parcela.
        $comissaoAtual = Commission::query()->where('user_id', $vendedor->id)->where('competencia', '2026-08')->first();

        $this->assertNotNull($comissaoAtual, 'deveria existir uma comissão aberta na competência atual com o ajuste');
        $this->assertSame('aberta', $comissaoAtual->situacao);
        $this->assertSame('-50.00', $comissaoAtual->valor_total);

        $itemNegativo = $comissaoAtual->items()->where('origem_tipo', 'parcela')->where('origem_id', $parcela->id)->firstOrFail();
        $this->assertSame('-50.00', $itemNegativo->valor);
        $this->assertSame('-500.00', $itemNegativo->base_valor);
    }

    // -----------------------------------------------------------------
    // Rotina agendada
    // -----------------------------------------------------------------

    /**
     * Mesmo padrão de `RotinasAgendadasTest::test_rodar_uma_rotina_agendada_registra_a_execucao_com_sucesso`:
     * `schedule:test` roda o comando em um processo filho (herda DB_DATABASE
     * do phpunit.xml) e os ganchos before/then de `bootstrap/app.php` gravam
     * o início e o fim em `scheduled_task_runs`.
     */
    public function test_rotina_comissoes_apurar_aparece_em_scheduled_task_runs(): void
    {
        $this->assertSame(
            0,
            \App\Models\ScheduledTaskRun::query()->daTarefa('comissoes:apurar')->count()
        );

        $this->artisan('schedule:test', ['--name' => 'comissoes:apurar'])->assertSuccessful();

        $rodada = \App\Models\ScheduledTaskRun::query()->daTarefa('comissoes:apurar')->first();

        $this->assertNotNull($rodada, 'a rodada da rotina não foi registrada em scheduled_task_runs');
        $this->assertSame(\App\Listeners\RegistraExecucaoAgendada::STATUS_SUCESSO, $rodada->status);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function comTenant(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->id, $callback);
    }

    private function criarVendedor(): User
    {
        return $this->comTenant(fn () => User::factory()->create(['company_id' => $this->empresa->id]));
    }

    private function criarTecnico(): Technician
    {
        return $this->comTenant(fn () => Technician::create([
            'name' => 'Técnico de Teste',
            'email' => fake()->unique()->safeEmail(),
            'phone' => '(11) 99999-9999',
            'is_active' => true,
        ]));
    }

    private function criarCliente(): Client
    {
        return $this->comTenant(fn () => ClientFactory::new()->create());
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function criarRegra(array $dados): CommissionRule
    {
        return $this->comTenant(fn () => CommissionRule::create($dados + [
            'forma' => 'percentual',
            'vigencia_fim' => null,
            'ativa' => true,
        ]));
    }

    /**
     * Cria um orçamento aprovado NA DATA INFORMADA: o relógio do teste é
     * movido para lá durante a mudança de status, para o `AuditLog` que
     * `Budget` grava (usado por `CommissionCalculationService` para achar
     * "quando foi aprovado") nascer com aquele `created_at`, e devolvido ao
     * relógio principal da suíte em seguida.
     */
    private function criarOrcamentoAprovado(User $vendedor, Client $cliente, string $dataAprovacao, string $valorServico): Budget
    {
        return $this->comTenant(function () use ($vendedor, $cliente, $dataAprovacao, $valorServico) {
            Carbon::setTestNow($dataAprovacao.' 09:00:00');

            $orcamento = Budget::create([
                'client_id' => $cliente->id,
                'user_id' => $vendedor->id,
                'date' => $dataAprovacao,
                'priority' => 'normal',
                'status' => 'draft',
            ]);

            $servico = Service::create([
                'name' => 'Dedetização de teste',
                'price' => $valorServico,
                'is_active' => true,
            ]);

            $orcamento->services()->attach($servico->id, [
                'quantity' => 1,
                'unit_price' => $valorServico,
                'subtotal' => $valorServico,
            ]);

            $orcamento->update(['status' => 'approved']);

            Carbon::setTestNow(self::AGORA);

            return $orcamento->fresh();
        });
    }

    private function criarOsExecutada(Technician $tecnico, string $dataExecucao, float $valor): WorkOrder
    {
        return $this->comTenant(fn () => WorkOrderFactory::new()->create([
            'technician_id' => $tecnico->id,
            'status' => 'completed',
            'end_time' => $dataExecucao.' 16:00:00',
            'final_amount' => $valor,
            'total_cost' => $valor,
        ]));
    }

    /**
     * Parcela única, quitada na data informada, do cliente informado - é pelo
     * cliente que `CommissionCalculationService::resolverVendedorDoRecebimento()`
     * encontra o orçamento aprovado de origem.
     */
    private function criarParcelaPagaDoCliente(Client $cliente, string $dataRecebimento, float $valor): ReceivableInstallment
    {
        return $this->comTenant(function () use ($cliente, $dataRecebimento, $valor) {
            $os = WorkOrderFactory::new()->create([
                'client_id' => $cliente->id,
                'final_amount' => $valor,
                'total_cost' => $valor,
                'payment_due_date' => $dataRecebimento,
            ]);

            $titulo = $this->receivableService->gerarDaOs($os, [
                'parcelas' => 1,
                'primeiro_vencimento' => $dataRecebimento,
            ]);

            $parcela = $titulo->installments()->firstOrFail();

            return $this->settlementService->baixar($parcela, [
                'valor' => $valor,
                'data' => $dataRecebimento,
                'usuario' => $this->operadorFinanceiro,
            ]);
        });
    }
}
