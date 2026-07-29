<?php

namespace Tests\Feature;

use App\Console\Commands\SyncPermissions;
use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\Company;
use App\Models\FinancialEntry;
use App\Models\Payable;
use App\Models\PayableInstallment;
use App\Models\PaymentDetail;
use App\Models\Receivable;
use App\Models\ReceivableInstallment;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Dinheiro;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Closure;
use Database\Factories\ClientFactory;
use Database\Factories\WorkOrderFactory;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as RotasRegistradas;
use Tests\TestCase;

/**
 * Task 18.7 do Plano 18: a camada HTTP do financeiro novo, e a prova de que o
 * painel que o cliente abre todo dia continua com os mesmos números.
 *
 * O comportamento interno já está coberto por `ReceivableServiceTest` (geração
 * e divisão em centavos), `SettlementServiceTest` (baixa e estorno),
 * `PayableServiceTest` (recorrência), `AgingServiceTest` e
 * `CashForecastServiceTest`. O que este arquivo prende é o que só existe na
 * borda: rota, permissão, módulo, FormRequest, filtro de listagem, isolamento
 * entre empresas - e a regressão dos painéis financeiros.
 *
 * Os blocos, um por critério de aceitação da task:
 *
 * 1. CRUD de contas a receber, com filtros.
 * 2. CRUD de contas a pagar, com filtros e recorrência.
 * 3. Baixa e estorno pelos endpoints, dos dois lados.
 * 4. Inadimplência (aging) e previsão de caixa respondendo.
 * 5. Plano de contas com hierarquia, e categoria em uso desativada em vez de
 *    excluída.
 * 6. Usuário sem permissão financeira recebe 403 em todas as rotas novas.
 * 7. Tenant sem o módulo `financeiro` vê a página de indisponível.
 * 8. Um tenant não alcança título do outro.
 * 9. **Regressão dos painéis**: nenhum total do painel financeiro mudou, e a
 *    prova, com número, de por que nenhum painel teve a fonte trocada para o
 *    modelo de títulos (ver o cabeçalho de `FinancialDashboardController`).
 *
 * O relógio fica fixo em 2026-07-20 durante a suíte inteira, para que "hoje",
 * "vencido" e "a vencer no mês" sejam sempre a mesma coisa em toda execução.
 */
class FinancialEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Dia em que a suíte "está rodando".
     */
    private const HOJE = '2026-07-20';

    private const INICIO_DO_MES = '2026-07-01';

    private const FIM_DO_MES = '2026-07-31';

    private Company $empresa;

    private User $administrador;

    /**
     * Relatórios que a migração financeira grava em storage/logs durante o
     * bloco 9. Apagados no fim, para a suíte não deixar lixo.
     *
     * @var list<string>
     */
    private array $relatoriosAnteriores = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::HOJE.' 12:00:00');

        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ModulesSeeder::class);

        $this->empresa = Company::query()->firstOrFail();
        $this->administrador = $this->criarUsuario('administrador');

        $this->relatoriosAnteriores = File::glob(storage_path('logs/migracao-financeira-*.log')) ?: [];
    }

    protected function tearDown(): void
    {
        foreach (array_diff(
            File::glob(storage_path('logs/migracao-financeira-*.log')) ?: [],
            $this->relatoriosAnteriores
        ) as $relatorio) {
            File::delete($relatorio);
        }

        foreach (File::glob(storage_path('app/financeiro/migracao/retrato-totais-*.json')) ?: [] as $retrato) {
            File::delete($retrato);
        }

        TenantAtual::limpar();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // 1. Contas a receber: listagem com filtros e criação
    // -----------------------------------------------------------------

    public function test_lista_de_contas_a_receber_filtra_por_situacao_periodo_cliente_e_categoria(): void
    {
        $cenario = $this->cenarioDeContasAReceber();

        $completa = $this->actingAs($this->administrador)->getJson('/contas-a-receber');
        $completa->assertOk();

        // Ordenada por vencimento: a parcial de 10/07, a que vence hoje e a de
        // agosto. É a ordem em que a cobrança acontece.
        $this->assertSame(
            ['Padaria Alfa', 'Mercado Beta', 'Padaria Alfa'],
            array_column($completa->json('parcelas'), 'cliente')
        );

        // A listagem é de parcelas, e cada linha traz o saldo devedor pronto.
        $this->assertSame(
            ['100.00', '250.00', '400.00'],
            array_column($completa->json('parcelas'), 'saldo')
        );

        // Filtro por situação.
        $pagas = $this->actingAs($this->administrador)->getJson('/contas-a-receber?situacao=parcial');
        $pagas->assertOk();
        $this->assertSame(['100.00'], array_column($pagas->json('parcelas'), 'saldo'));

        // Filtro por período, pelo vencimento.
        $doPeriodo = $this->actingAs($this->administrador)
            ->getJson('/contas-a-receber?de=2026-08-01&ate=2026-08-31');
        $doPeriodo->assertOk();
        $this->assertSame(['400.00'], array_column($doPeriodo->json('parcelas'), 'saldo'));

        // Filtro por cliente.
        $doCliente = $this->actingAs($this->administrador)
            ->getJson('/contas-a-receber?client_id='.$cenario['beta']->id);
        $doCliente->assertOk();
        $this->assertSame(['Mercado Beta'], array_column($doCliente->json('parcelas'), 'cliente'));

        // Filtro por categoria do plano de contas.
        $daCategoria = $this->actingAs($this->administrador)
            ->getJson('/contas-a-receber?chart_of_account_id='.$cenario['categoria']->id);
        $daCategoria->assertOk();
        $this->assertSame(['Mercado Beta'], array_column($daCategoria->json('parcelas'), 'cliente'));

        // Indicadores do topo, sempre sobre a empresa inteira: 100 vencido
        // (parcela parcial de 10/07), 400 a vencer em agosto (fora do mês, não
        // entra) e 250 vencendo hoje.
        $this->assertSame('250.00', $completa->json('indicadores.vence_hoje'));
        $this->assertSame('100.00', $completa->json('indicadores.vencido'));
        $this->assertSame('0.00', $completa->json('indicadores.a_vencer_no_mes'));
        $this->assertSame(3, $completa->json('indicadores.parcelas_em_aberto'));
    }

    public function test_titulo_avulso_parcelado_divide_o_valor_em_centavos(): void
    {
        $cliente = $this->criarCliente('Padaria Alfa');

        $resposta = $this->actingAs($this->administrador)->postJson('/contas-a-receber', [
            'client_id' => $cliente->id,
            'descricao' => 'Acordo de dívida antiga',
            'valor' => 1000.00,
            'primeiro_vencimento' => '2026-08-01',
            'parcelas' => 3,
            'intervalo_meses' => 1,
        ]);

        $resposta->assertOk();

        $titulo = Receivable::query()->sole();
        $parcelas = $titulo->installments()->orderBy('numero')->get();

        $this->assertSame($this->empresa->id, (int) $titulo->company_id);
        $this->assertSame(['333.33', '333.33', '333.34'], $parcelas->pluck('valor')->all());
        $this->assertSame(
            ['2026-08-01', '2026-09-01', '2026-10-01'],
            $parcelas->pluck('vencimento')->map(fn ($dia) => $dia->toDateString())->all()
        );
    }

    public function test_titulo_a_partir_da_ordem_de_servico_e_idempotente(): void
    {
        $ordem = $this->criarOrdem('OS-7001', 800.00);

        foreach ([1, 2] as $tentativa) {
            $this->actingAs($this->administrador)->postJson('/contas-a-receber', [
                'work_order_id' => $ordem->id,
                'forma' => 'a_vista',
            ])->assertOk();
        }

        $this->assertSame(1, Receivable::query()->count(), 'a segunda tentativa cobrou a mesma OS duas vezes');
        $this->assertSame('800.00', Receivable::query()->sole()->valor_total);
    }

    public function test_parcelamento_sem_intervalo_e_recusado_com_422(): void
    {
        $cliente = $this->criarCliente('Padaria Alfa');

        $resposta = $this->actingAs($this->administrador)->postJson('/contas-a-receber', [
            'client_id' => $cliente->id,
            'descricao' => 'Acordo sem intervalo informado',
            'valor' => 900.00,
            'primeiro_vencimento' => '2026-08-01',
            'parcelas' => 3,
        ]);

        $resposta->assertStatus(422);
        $this->assertStringContainsString('intervalo', (string) $resposta->json('message'));
        $this->assertSame(0, Receivable::query()->count());
    }

    public function test_cancelar_titulo_mantem_a_parcela_paga_e_cancela_o_resto(): void
    {
        $titulo = $this->tituloComDuasParcelas();
        $primeira = $titulo->installments()->orderBy('numero')->first();

        $this->baixar($primeira, 250.00, '2026-07-15');

        $this->actingAs($this->administrador)
            ->postJson("/contas-a-receber/{$titulo->id}/cancelar")
            ->assertOk();

        $parcelas = $titulo->fresh()->installments()->orderBy('numero')->get();

        $this->assertSame('cancelado', $titulo->fresh()->situacao);
        $this->assertSame('paga', $parcelas[0]->situacao, 'o dinheiro entrou: a parcela paga não pode ser cancelada');
        $this->assertSame('250.00', $parcelas[0]->valor_pago);
        $this->assertSame('cancelada', $parcelas[1]->situacao);
    }

    // -----------------------------------------------------------------
    // 2. Contas a pagar: listagem com filtros, criação e recorrência
    // -----------------------------------------------------------------

    public function test_lista_de_contas_a_pagar_filtra_por_situacao_periodo_fornecedor_e_categoria(): void
    {
        $cenario = $this->cenarioDeContasAPagar();

        $completa = $this->actingAs($this->administrador)->getJson('/contas-a-pagar');
        $completa->assertOk();

        $this->assertSame(
            ['Imobiliária Central', 'Distribuidora Química'],
            array_column($completa->json('parcelas'), 'fornecedor')
        );

        $porFornecedor = $this->actingAs($this->administrador)
            ->getJson('/contas-a-pagar?supplier_id='.$cenario['quimica']->id);
        $porFornecedor->assertOk();
        $this->assertSame(['Distribuidora Química'], array_column($porFornecedor->json('parcelas'), 'fornecedor'));

        $doPeriodo = $this->actingAs($this->administrador)
            ->getJson('/contas-a-pagar?de=2026-07-01&ate=2026-07-25');
        $doPeriodo->assertOk();
        $this->assertSame(['Imobiliária Central'], array_column($doPeriodo->json('parcelas'), 'fornecedor'));

        $daCategoria = $this->actingAs($this->administrador)
            ->getJson('/contas-a-pagar?chart_of_account_id='.$cenario['categoria']->id);
        $daCategoria->assertOk();
        $this->assertSame(['Imobiliária Central'], array_column($daCategoria->json('parcelas'), 'fornecedor'));

        $emAberto = $this->actingAs($this->administrador)->getJson('/contas-a-pagar?situacao=aberta');
        $emAberto->assertOk();
        $this->assertCount(2, $emAberto->json('parcelas'));

        $this->assertSame('1200.00', $completa->json('indicadores.vence_hoje'));
        $this->assertSame('0.00', $completa->json('indicadores.vencido'));
        $this->assertSame('340.00', $completa->json('indicadores.a_vencer_no_mes'));
    }

    public function test_titulo_a_pagar_recorrente_nasce_com_a_janela_de_tres_competencias(): void
    {
        $fornecedor = $this->criarFornecedor('Imobiliária Central');

        $resposta = $this->actingAs($this->administrador)->postJson('/contas-a-pagar', [
            'supplier_id' => $fornecedor->id,
            'descricao' => 'Aluguel da sede',
            'valor' => 3000.00,
            'vencimento' => '2026-07-25',
            'recorrencia' => 'mensal',
        ]);

        $resposta->assertOk();

        $this->assertSame(3, Payable::query()->count(), 'a janela de competências da série recorrente mudou');
        $this->assertSame(
            ['2026-07', '2026-08', '2026-09'],
            Payable::query()->orderBy('emitido_em')->get()
                ->map(fn (Payable $titulo): string => $titulo->emitido_em->format('Y-m'))
                ->all()
        );
    }

    public function test_alterar_valor_de_recorrente_alcanca_apenas_este_ou_os_futuros(): void
    {
        $fornecedor = $this->criarFornecedor('Imobiliária Central');

        $this->actingAs($this->administrador)->postJson('/contas-a-pagar', [
            'supplier_id' => $fornecedor->id,
            'descricao' => 'Aluguel da sede',
            'valor' => 3000.00,
            'vencimento' => '2026-07-25',
            'recorrencia' => 'mensal',
        ])->assertOk();

        $raiz = Payable::query()->orderBy('emitido_em')->firstOrFail();

        $this->actingAs($this->administrador)
            ->postJson("/contas-a-pagar/{$raiz->id}/valor", ['valor' => 3200.00, 'alcance' => 'este_e_futuros'])
            ->assertOk();

        $this->assertSame(
            ['3200.00', '3200.00', '3200.00'],
            Payable::query()->orderBy('emitido_em')->pluck('valor_total')->all()
        );

        $segunda = Payable::query()->orderBy('emitido_em')->skip(1)->firstOrFail();

        $this->actingAs($this->administrador)
            ->postJson("/contas-a-pagar/{$segunda->id}/valor", ['valor' => 3500.00, 'alcance' => 'apenas_este'])
            ->assertOk();

        $this->assertSame(
            ['3200.00', '3500.00', '3200.00'],
            Payable::query()->orderBy('emitido_em')->pluck('valor_total')->all()
        );
    }

    public function test_alterar_valor_sem_alcance_e_recusado_pela_validacao(): void
    {
        $titulo = $this->tituloAPagar('Manutenção do carro', 500.00, '2026-07-30');

        $resposta = $this->actingAs($this->administrador)
            ->postJson("/contas-a-pagar/{$titulo->id}/valor", ['valor' => 600.00]);

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('alcance');
    }

    public function test_cancelar_titulo_a_pagar_cancela_as_parcelas_em_aberto(): void
    {
        $titulo = $this->tituloAPagar('Manutenção do carro', 500.00, '2026-07-30');

        $this->actingAs($this->administrador)
            ->postJson("/contas-a-pagar/{$titulo->id}/cancelar")
            ->assertOk();

        $this->assertSame('cancelado', $titulo->fresh()->situacao);
        $this->assertSame('cancelada', $titulo->installments()->sole()->situacao);
    }

    // -----------------------------------------------------------------
    // 3. Baixa e estorno pelos endpoints
    // -----------------------------------------------------------------

    public function test_baixa_e_estorno_de_parcela_a_receber_pelos_endpoints(): void
    {
        $parcela = $this->tituloComDuasParcelas()->installments()->orderBy('numero')->firstOrFail();

        $baixa = $this->actingAs($this->administrador)
            ->postJson("/contas-a-receber/parcelas/{$parcela->id}/baixar", [
                'valor' => 250.00,
                'data' => '2026-07-15',
                'forma_pagamento' => 'pix',
                'observacao' => 'Depósito conferido no extrato',
            ]);

        $baixa->assertOk();

        $parcela->refresh();

        $this->assertSame('paga', $parcela->situacao);
        $this->assertSame('250.00', $parcela->valor_pago);
        $this->assertSame('2026-07-15', $parcela->pago_em->toDateString());

        $lancamento = FinancialEntry::query()->findOrFail($parcela->financial_entry_id);

        $this->assertSame('work_order', $lancamento->source);
        $this->assertSame('250.00', $lancamento->amount);
        $this->assertSame('2026-07-15', $lancamento->entry_date->toDateString());

        $estorno = $this->actingAs($this->administrador)
            ->postJson("/contas-a-receber/parcelas/{$parcela->id}/estornar", [
                'motivo' => 'Depósito era de outro cliente, valor devolvido',
            ]);

        $estorno->assertOk();

        $parcela->refresh();

        $this->assertSame('aberta', $parcela->situacao);
        $this->assertSame('0.00', $parcela->valor_pago);
        $this->assertNull($parcela->pago_em);

        // O lançamento original continua onde estava: o estorno é lançamento
        // novo, em sentido contrário.
        $this->assertSame(2, FinancialEntry::query()->count());
        $this->assertSame('payment_reopen', FinancialEntry::query()->findOrFail($parcela->financial_entry_id)->source);
        $this->assertSame(1, FinancialEntry::query()->where('id', $lancamento->id)->count());
    }

    public function test_baixa_parcial_mantem_a_parcela_aberta_pelo_saldo(): void
    {
        $parcela = $this->tituloComDuasParcelas()->installments()->orderBy('numero')->firstOrFail();

        $this->actingAs($this->administrador)
            ->postJson("/contas-a-receber/parcelas/{$parcela->id}/baixar", [
                'valor' => 100.00,
                'data' => '2026-07-15',
            ])->assertOk();

        $parcela->refresh();

        $this->assertSame('parcial', $parcela->situacao);
        $this->assertSame('100.00', $parcela->valor_pago);
        $this->assertNull($parcela->pago_em, 'pago_em só existe quando a parcela termina de ser paga');

        $lista = $this->actingAs($this->administrador)->getJson('/contas-a-receber');
        $this->assertSame('150.00', $lista->json('parcelas.0.saldo'));
    }

    public function test_baixa_acima_do_saldo_devedor_e_recusada_com_422(): void
    {
        $parcela = $this->tituloComDuasParcelas()->installments()->orderBy('numero')->firstOrFail();

        $resposta = $this->actingAs($this->administrador)
            ->postJson("/contas-a-receber/parcelas/{$parcela->id}/baixar", [
                'valor' => 900.00,
                'data' => '2026-07-15',
            ]);

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('valor');

        $this->assertSame('aberta', $parcela->fresh()->situacao);
        $this->assertSame(0, FinancialEntry::query()->count(), 'a baixa recusada deixou lançamento solto no caixa');
    }

    public function test_estorno_sem_motivo_de_verdade_e_recusado(): void
    {
        $parcela = $this->tituloComDuasParcelas()->installments()->orderBy('numero')->firstOrFail();

        $this->baixar($parcela, 250.00, '2026-07-15');

        $resposta = $this->actingAs($this->administrador)
            ->postJson("/contas-a-receber/parcelas/{$parcela->id}/estornar", ['motivo' => 'erro']);

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('motivo');

        $this->assertSame('paga', $parcela->fresh()->situacao);
        $this->assertSame(1, FinancialEntry::query()->count());
    }

    public function test_baixa_e_estorno_de_parcela_a_pagar_pelos_endpoints(): void
    {
        $parcela = $this->tituloAPagar('Aluguel da sede', 1200.00, '2026-07-25')
            ->installments()
            ->sole();

        $this->actingAs($this->administrador)
            ->postJson("/contas-a-pagar/parcelas/{$parcela->id}/baixar", [
                'valor' => 1200.00,
                'data' => '2026-07-18',
                'forma_pagamento' => 'boleto',
            ])->assertOk();

        $parcela->refresh();

        $this->assertSame('paga', $parcela->situacao);
        $this->assertSame('1200.00', $parcela->valor_pago);

        // Pagamento a fornecedor é saída de caixa.
        $lancamento = FinancialEntry::query()->findOrFail($parcela->financial_entry_id);
        $this->assertSame('payment_reopen', $lancamento->source);

        $this->actingAs($this->administrador)
            ->postJson("/contas-a-pagar/parcelas/{$parcela->id}/estornar", [
                'motivo' => 'Boleto pago em duplicidade, valor devolvido pelo fornecedor',
            ])->assertOk();

        $parcela->refresh();

        $this->assertSame('aberta', $parcela->situacao);
        $this->assertSame('0.00', $parcela->valor_pago);
        $this->assertSame(2, FinancialEntry::query()->count());
        // O estorno de um pagamento é entrada: o dinheiro volta.
        $this->assertSame('work_order', FinancialEntry::query()->findOrFail($parcela->financial_entry_id)->source);
    }

    // -----------------------------------------------------------------
    // 4. Inadimplência e previsão
    // -----------------------------------------------------------------

    public function test_inadimplencia_responde_com_as_cinco_faixas_por_cliente(): void
    {
        $this->cenarioDeInadimplencia();

        $resposta = $this->actingAs($this->administrador)->getJson('/financeiro/inadimplencia');

        $resposta->assertOk();
        $resposta->assertJsonPath('referencia', self::HOJE);

        $porCliente = $resposta->json('aging.por_cliente');

        $this->assertCount(2, $porCliente);
        $this->assertSame(['Padaria Alfa', 'Mercado Beta'], array_column($porCliente, 'cliente'));

        $alfa = $porCliente[0];

        $this->assertSame('100.00', $alfa['faixas']['a_vencer']['valor']);
        $this->assertSame('200.00', $alfa['faixas']['de_1_a_30']['valor']);
        $this->assertSame('400.00', $alfa['faixas']['acima_de_90']['valor']);
        $this->assertSame('700.00', $alfa['total']['valor']);

        $this->assertSame('1000.00', $resposta->json('aging.total_geral.total.valor'));

        // Filtro por cliente.
        $filtrada = $this->actingAs($this->administrador)
            ->getJson('/financeiro/inadimplencia?client_id='.$alfa['client_id']);

        $filtrada->assertOk();
        $this->assertCount(1, $filtrada->json('aging.por_cliente'));
        $this->assertSame('700.00', $filtrada->json('aging.total_geral.total.valor'));
    }

    public function test_previsao_de_caixa_responde_nos_tres_horizontes(): void
    {
        $this->cenarioDePrevisao();

        $padrao = $this->actingAs($this->administrador)->getJson('/financeiro/previsao');

        $padrao->assertOk();
        $padrao->assertJsonPath('filtros.meses', 6);
        $this->assertCount(6, $padrao->json('previsao.previsto.meses'));

        $mesCorrente = $padrao->json('previsao.previsto.meses.0');

        $this->assertSame('2026-07', $mesCorrente['competencia']);
        $this->assertSame('900.00', $mesCorrente['a_receber']);
        $this->assertSame('500.00', $mesCorrente['a_pagar']);
        $this->assertSame('400.00', $mesCorrente['resultado']);

        foreach ([3, 12] as $horizonte) {
            $resposta = $this->actingAs($this->administrador)
                ->getJson('/financeiro/previsao?meses='.$horizonte);

            $resposta->assertOk();
            $this->assertCount($horizonte, $resposta->json('previsao.previsto.meses'));
        }

        // Horizonte fora da lista cai no padrão, em vez de devolver erro.
        $estranho = $this->actingAs($this->administrador)->getJson('/financeiro/previsao?meses=99');
        $estranho->assertOk();
        $this->assertCount(6, $estranho->json('previsao.previsto.meses'));
    }

    // -----------------------------------------------------------------
    // 5. Plano de contas
    // -----------------------------------------------------------------

    public function test_plano_de_contas_cria_hierarquia_e_devolve_a_arvore(): void
    {
        $this->actingAs($this->administrador)->postJson('/contas', [
            'codigo' => '3',
            'nome' => 'Despesas',
            'tipo' => 'despesa',
        ])->assertOk();

        $mae = ChartOfAccount::query()->where('codigo', '3')->sole();

        $this->actingAs($this->administrador)->postJson('/contas', [
            'codigo' => '3.01',
            'nome' => 'Aluguel',
            'tipo' => 'despesa',
            'parent_id' => $mae->id,
        ])->assertOk();

        $arvore = $this->actingAs($this->administrador)->getJson('/contas');

        $arvore->assertOk();
        $arvore->assertJsonPath('contas.0.codigo', '3');
        $arvore->assertJsonPath('contas.0.filhos.0.codigo', '3.01');
        $arvore->assertJsonPath('contas.0.filhos.0.pode_excluir', true);
        // A mãe tem subcategoria, então não pode ser excluída, só desativada.
        $arvore->assertJsonPath('contas.0.pode_excluir', false);
    }

    public function test_subcategoria_de_tipo_diferente_da_mae_e_recusada(): void
    {
        $mae = $this->criarCategoria('3', 'Despesas', 'despesa');

        $resposta = $this->actingAs($this->administrador)->postJson('/contas', [
            'codigo' => '3.02',
            'nome' => 'Serviços prestados',
            'tipo' => 'receita',
            'parent_id' => $mae->id,
        ]);

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('parent_id');
    }

    public function test_categoria_nao_pode_ser_a_propria_mae(): void
    {
        $conta = $this->criarCategoria('3', 'Despesas', 'despesa');

        $resposta = $this->actingAs($this->administrador)->putJson("/contas/{$conta->id}", [
            'codigo' => '3',
            'nome' => 'Despesas',
            'tipo' => 'despesa',
            'parent_id' => $conta->id,
        ]);

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('parent_id');
    }

    public function test_codigo_repetido_na_mesma_empresa_e_recusado_mas_existe_em_outra(): void
    {
        $this->criarCategoria('3', 'Despesas', 'despesa');

        $repetido = $this->actingAs($this->administrador)->postJson('/contas', [
            'codigo' => '3',
            'nome' => 'Outra despesa',
            'tipo' => 'despesa',
        ]);

        $repetido->assertStatus(422);
        $repetido->assertJsonValidationErrors('codigo');

        // O mesmo código na empresa vizinha é o caso normal, não o excepcional.
        $vizinha = $this->empresaVizinha();

        $conta = TenantAtual::comTenant($vizinha->id, fn (): ChartOfAccount => ChartOfAccount::create([
            'codigo' => '3',
            'nome' => 'Despesas da vizinha',
            'tipo' => 'despesa',
            'ativo' => true,
        ]));

        $this->assertSame($vizinha->id, (int) $conta->company_id);
    }

    public function test_categoria_com_titulo_vinculado_e_desativada_nunca_excluida(): void
    {
        $categoria = $this->criarCategoria('3.01', 'Aluguel', 'despesa');
        $fornecedor = $this->criarFornecedor('Imobiliária Central');

        $this->actingAs($this->administrador)->postJson('/contas-a-pagar', [
            'supplier_id' => $fornecedor->id,
            'chart_of_account_id' => $categoria->id,
            'descricao' => 'Aluguel da sede',
            'valor' => 1200.00,
            'vencimento' => '2026-07-25',
        ])->assertOk();

        $recusada = $this->actingAs($this->administrador)->deleteJson("/contas/{$categoria->id}");

        $recusada->assertStatus(422);
        $this->assertStringContainsString('Desative', (string) $recusada->json('message'));
        $this->assertDatabaseHas('chart_of_accounts', ['id' => $categoria->id]);

        // O caminho que existe é desativar.
        $this->actingAs($this->administrador)->putJson("/contas/{$categoria->id}", [
            'codigo' => '3.01',
            'nome' => 'Aluguel',
            'tipo' => 'despesa',
            'ativo' => false,
        ])->assertOk();

        $this->assertFalse((bool) $categoria->fresh()->ativo);

        // Categoria sem uso continua excluível.
        $semUso = $this->criarCategoria('3.99', 'Categoria criada por engano', 'despesa');

        $this->actingAs($this->administrador)->deleteJson("/contas/{$semUso->id}")->assertOk();
        $this->assertDatabaseMissing('chart_of_accounts', ['id' => $semUso->id]);
    }

    // -----------------------------------------------------------------
    // 6. Permissão
    // -----------------------------------------------------------------

    public function test_usuario_sem_permissao_financeira_recebe_403_em_todas_as_rotas_novas(): void
    {
        $tecnico = $this->criarUsuario('tecnico');
        $titulo = $this->tituloComDuasParcelas();
        $parcela = $titulo->installments()->orderBy('numero')->firstOrFail();
        $aPagar = $this->tituloAPagar('Aluguel da sede', 1200.00, '2026-07-25');
        $parcelaAPagar = $aPagar->installments()->sole();
        $categoria = $this->criarCategoria('3', 'Despesas', 'despesa');

        $leituras = ['/contas-a-receber', '/contas-a-pagar', '/financeiro/inadimplencia', '/financeiro/previsao', '/contas'];

        foreach ($leituras as $uri) {
            $this->actingAs($tecnico)->getJson($uri)->assertForbidden();
        }

        $escritas = [
            ['post', '/contas-a-receber'],
            ['post', "/contas-a-receber/{$titulo->id}/cancelar"],
            ['post', "/contas-a-receber/parcelas/{$parcela->id}/baixar"],
            ['post', "/contas-a-receber/parcelas/{$parcela->id}/estornar"],
            ['post', '/contas-a-pagar'],
            ['post', "/contas-a-pagar/{$aPagar->id}/cancelar"],
            ['post', "/contas-a-pagar/{$aPagar->id}/valor"],
            ['post', "/contas-a-pagar/parcelas/{$parcelaAPagar->id}/baixar"],
            ['post', "/contas-a-pagar/parcelas/{$parcelaAPagar->id}/estornar"],
            ['post', '/contas'],
            ['put', "/contas/{$categoria->id}"],
            ['delete', "/contas/{$categoria->id}"],
        ];

        foreach ($escritas as [$metodo, $uri]) {
            $this->actingAs($tecnico)->{$metodo.'Json'}($uri, [])->assertForbidden();
        }

        $this->assertSame(0, FinancialEntry::query()->count(), 'uma escrita barrada mexeu no caixa');
    }

    public function test_papel_financeiro_alcanca_as_rotas_novas(): void
    {
        $financeiro = $this->criarUsuario('financeiro');

        foreach (['/contas-a-receber', '/contas-a-pagar', '/financeiro/inadimplencia', '/financeiro/previsao', '/contas'] as $uri) {
            $this->actingAs($financeiro)->getJson($uri)->assertOk();
        }
    }

    public function test_quem_baixa_nao_estorna_sem_a_permissao_de_estorno(): void
    {
        $parcela = $this->tituloComDuasParcelas()->installments()->orderBy('numero')->firstOrFail();

        $operador = $this->criarUsuario();
        $operador->givePermissionTo(['financeiro-titulos', 'financeiro-baixar']);
        $operador = $operador->fresh();

        $this->actingAs($operador)
            ->postJson("/contas-a-receber/parcelas/{$parcela->id}/baixar", [
                'valor' => 250.00,
                'data' => '2026-07-15',
            ])->assertOk();

        $this->actingAs($operador)
            ->postJson("/contas-a-receber/parcelas/{$parcela->id}/estornar", [
                'motivo' => 'Tentativa de estorno sem a permissão própria',
            ])->assertForbidden();

        $this->assertSame('paga', $parcela->fresh()->situacao);
        $this->assertSame(1, FinancialEntry::query()->count(), 'o estorno barrado criou lançamento no caixa');
    }

    public function test_as_permissoes_novas_estao_no_catalogo(): void
    {
        $catalogo = collect(SyncPermissions::catalogo())->flatten()->all();

        $this->assertContains('financeiro-titulos', $catalogo);
        $this->assertContains('financeiro-baixar', $catalogo);
        $this->assertContains('financeiro-plano-de-contas', $catalogo);
        // Reaproveitada da Task 18.4, nunca duplicada.
        $this->assertContains('financeiro-estornar', $catalogo);
        $this->assertSame(
            1,
            collect($catalogo)->filter(fn (string $nome): bool => str_contains($nome, 'estornar'))->count(),
            'existe mais de uma permissão de estorno no catálogo'
        );
    }

    // -----------------------------------------------------------------
    // 7. Módulo financeiro
    // -----------------------------------------------------------------

    public function test_tenant_sem_o_modulo_financeiro_ve_a_pagina_de_indisponivel(): void
    {
        $administradorSemModulo = $this->administradorDeTenantSemPlano();

        $destino = route('modulo-indisponivel', ['modulo' => 'financeiro']);

        foreach (['/contas-a-receber', '/contas-a-pagar', '/financeiro/inadimplencia', '/financeiro/previsao', '/contas'] as $uri) {
            $this->actingAs($administradorSemModulo)->get($uri)->assertRedirect($destino);
        }

        // A mesma rota buscada por fetch responde 403 com JSON, para o
        // interceptor do frontend tratar sem precisar entender um HTML.
        $json = $this->actingAs($administradorSemModulo)->getJson('/contas-a-receber');

        $json->assertStatus(403);
        $json->assertJson(['modulo' => 'financeiro']);
    }

    public function test_toda_rota_nova_carrega_o_modulo_e_uma_permissao_financeira(): void
    {
        $rotas = collect(RotasRegistradas::getRoutes())
            ->filter(fn ($rota): bool => (bool) preg_match(
                '#^(contas-a-receber|contas-a-pagar|contas|financeiro)(/|$)#',
                $rota->uri()
            ));

        $this->assertGreaterThanOrEqual(15, $rotas->count(), 'a varredura encontrou rotas de menos');

        foreach ($rotas as $rota) {
            $middlewares = $rota->gatherMiddleware();

            $this->assertContains(
                'module:financeiro',
                $middlewares,
                "a rota '{$rota->uri()}' [".implode(',', $rota->methods()).'] não tem o middleware module:financeiro'
            );

            $this->assertTrue(
                collect($middlewares)->contains(fn ($middleware): bool => is_string($middleware)
                    && str_starts_with($middleware, 'permission:financeiro-')),
                "a rota '{$rota->uri()}' [".implode(',', $rota->methods()).'] não tem permissão financeira'
            );
        }
    }

    public function test_endpoints_antigos_continuam_respondendo(): void
    {
        // Esta task não remove nada: o fluxo de pagamento antigo e os
        // lançamentos manuais continuam servindo a tela que o cliente usa hoje.
        $ordem = $this->criarOrdem('OS-7100', 500.00);

        $this->actingAs($this->administrador)
            ->getJson("/work-orders/{$ordem->id}/payment-details")
            ->assertOk();

        $this->actingAs($this->administrador)->getJson('/financial-entries')->assertOk();
        $this->actingAs($this->administrador)->getJson('/financial-entries/stats')->assertOk();
        $this->actingAs($this->administrador)->get('/financial-dashboard')->assertOk();
        $this->actingAs($this->administrador)->get('/cash-flow')->assertOk();
    }

    // -----------------------------------------------------------------
    // 8. Isolamento entre empresas
    // -----------------------------------------------------------------

    public function test_um_tenant_nao_alcanca_titulo_do_outro(): void
    {
        $outra = $this->cenarioDeOutraEmpresa();

        $lista = $this->actingAs($this->administrador)->getJson('/contas-a-receber');
        $lista->assertOk();
        $this->assertSame([], $lista->json('parcelas'));
        $this->assertStringNotContainsString('Cliente da vizinha', $lista->getContent());

        $aPagar = $this->actingAs($this->administrador)->getJson('/contas-a-pagar');
        $aPagar->assertOk();
        $this->assertSame([], $aPagar->json('parcelas'));

        // Acesso direto por id: 404 em tudo, e não 403, porque confirmar a
        // existência do registro já vaza informação entre empresas.
        $this->actingAs($this->administrador)
            ->postJson("/contas-a-receber/{$outra['titulo']->id}/cancelar")
            ->assertNotFound();

        $this->actingAs($this->administrador)
            ->postJson("/contas-a-receber/parcelas/{$outra['parcela']->id}/baixar", [
                'valor' => 100.00,
                'data' => '2026-07-15',
            ])->assertNotFound();

        $this->actingAs($this->administrador)
            ->postJson("/contas-a-pagar/parcelas/{$outra['parcelaAPagar']->id}/baixar", [
                'valor' => 100.00,
                'data' => '2026-07-15',
            ])->assertNotFound();

        $this->actingAs($this->administrador)
            ->deleteJson("/contas/{$outra['categoria']->id}")
            ->assertNotFound();

        // Id da outra empresa vindo pelo corpo da requisição: mesmo tratamento.
        $this->actingAs($this->administrador)->postJson('/contas-a-receber', [
            'client_id' => $outra['cliente']->id,
            'descricao' => 'Cobrança criada na conta da vizinha',
            'valor' => 100.00,
            'primeiro_vencimento' => '2026-08-01',
        ])->assertNotFound();

        $this->actingAs($this->administrador)->postJson('/contas-a-pagar', [
            'supplier_id' => $outra['fornecedor']->id,
            'descricao' => 'Despesa criada na conta da vizinha',
            'valor' => 100.00,
            'vencimento' => '2026-08-01',
        ])->assertStatus(422);

        // Nada da vizinha mudou.
        $this->assertSame('aberto', $outra['titulo']->fresh()->situacao);
        $this->assertSame('0.00', $outra['parcela']->fresh()->valor_pago);
        $this->assertSame(0, FinancialEntry::query()->deTodasAsEmpresas()->count());
    }

    // -----------------------------------------------------------------
    // 9. Regressão dos painéis financeiros
    // -----------------------------------------------------------------

    /**
     * O critério mais importante desta task: nenhum número que o cliente vê
     * hoje mudou.
     *
     * O teste recalcula, por fora do controller e direto no banco, exatamente
     * a definição que cada painel tinha **antes** da Task 18.7 (soma de
     * `financial_entries` confirmadas no período, por origem) e exige que a
     * resposta do endpoint continue igual, centavo a centavo, com o modelo
     * novo já povoado pela migração da Task 18.2 e com baixas do fluxo novo
     * lançadas por cima.
     *
     * Enquanto os painéis continuarem lendo o caixa, este teste passa por
     * construção. Ele existe para o dia em que alguém trocar a fonte de um
     * deles: no minuto em que a troca mudar um centavo, é aqui que aparece.
     */
    public function test_nenhum_total_dos_paineis_financeiros_mudou(): void
    {
        $this->cenarioDeCaixaComMigracao();

        $paineis = $this->paineisDoDashboard();
        $esperado = $this->paineisPeloModeloAntigo();

        // Guarda contra a comparação vazia: painel sem número nenhum bateria
        // com qualquer coisa.
        $this->assertNotEmpty($paineis['por_forma'], 'o gráfico por forma de pagamento veio vazio');
        $this->assertNotEmpty($paineis['por_mes'], 'a evolução mensal veio vazia');

        $this->assertSame($esperado['stats'], $paineis['stats'], 'o resumo do painel financeiro mudou');
        $this->assertSame($esperado['por_tipo'], $paineis['por_tipo'], 'o gráfico por tipo mudou');
        $this->assertSame($esperado['por_forma'], $paineis['por_forma'], 'o gráfico por forma de pagamento mudou');
        $this->assertSame($esperado['por_mes'], $paineis['por_mes'], 'a evolução mensal mudou');

        // E os números continuam sendo os do caixa, com a conta à mão:
        // 500 + 300 + 300 (recebimentos de OS, incluindo a reabertura) de
        // entrada de OS, 80 de entrada manual, 300 (reabertura) + 50 (saída
        // manual) de saída.
        $this->assertSame('1100.00', $paineis['stats']['payment_amount']);
        $this->assertSame('80.00', $paineis['stats']['manual_amount']);
        $this->assertSame('350.00', $paineis['stats']['withdrawal_amount']);
        $this->assertSame('830.00', $paineis['stats']['total_amount']);
    }

    /**
     * A prova de por que nenhum painel teve a fonte trocada para o modelo de
     * títulos (ver o cabeçalho de `FinancialDashboardController`).
     *
     * Os dois números medem coisas diferentes, e a diferença não é ruído: são
     * os três casos documentados, cada um com o valor exato que ele desloca.
     */
    public function test_a_leitura_pelo_titulo_diverge_do_caixa_e_por_isso_a_troca_foi_recusada(): void
    {
        $this->cenarioDeCaixaComMigracao();

        $peloCaixa = $this->recebimentoDeOsPeloCaixa();
        $peloTitulo = $this->recebimentoPeloTitulo();

        $this->assertSame('1100.00', $peloCaixa);
        $this->assertSame('1000.00', $peloTitulo);
        $this->assertNotSame(
            $peloCaixa,
            $peloTitulo,
            'se os dois passarem a bater, a troca de fonte volta a ser possível e este teste precisa ser revisto'
        );

        // Caso 1: parcela reaberta e paga de novo. O caixa tem dois
        // recebimentos de 300; a parcela guarda um `valor_pago` só e aponta
        // para o lançamento mais recente. Lida pelo título, a primeira entrada
        // de 300 desaparece do painel.
        $this->assertSame(
            2,
            FinancialEntry::query()
                ->where('source', 'work_order')
                ->where('amount', '300.00')
                ->count()
        );
        $this->assertSame(
            '300.00',
            ReceivableInstallment::query()->where('valor_pago', '300.00')->sole()->valor_pago
        );

        // Caso 2: recebimento antigo sem lançamento vinculado. O título diz
        // que 200 foram recebidos e o caixa não tem esse dinheiro. Lido pelo
        // título, o painel mostraria dinheiro que nunca entrou.
        $semLancamento = ReceivableInstallment::query()
            ->whereNull('financial_entry_id')
            ->where('valor_pago', '>', 0)
            ->sole();

        $this->assertSame('200.00', $semLancamento->valor_pago);

        // Caso 3: entrada manual e saída manual, que não têm título nenhum.
        // Lidas pelo título, sumiriam do resumo e dos três gráficos.
        $this->assertSame(
            2,
            FinancialEntry::query()->whereIn('source', ['manual', 'manual_withdrawal'])->count()
        );
        $this->assertSame(0, Receivable::query()->whereNull('work_order_id')->count());
    }

    // -----------------------------------------------------------------
    // Apoio: cenários
    // -----------------------------------------------------------------

    /**
     * Dois clientes, três parcelas: uma parcial vencida, uma a vencer em
     * agosto e uma vencendo hoje, esta última classificada em uma categoria do
     * plano de contas.
     *
     * @return array<string, mixed>
     */
    private function cenarioDeContasAReceber(): array
    {
        $alfa = $this->criarCliente('Padaria Alfa');
        $beta = $this->criarCliente('Mercado Beta');
        $categoria = $this->criarCategoria('1.01', 'Serviços de dedetização', 'receita');

        $tituloAlfa = $this->titulo($alfa, 'Dedetização trimestral', [
            ['valor' => 250.00, 'vencimento' => '2026-07-10', 'valor_pago' => 150.00, 'situacao' => 'parcial'],
            ['valor' => 400.00, 'vencimento' => '2026-08-10'],
        ]);

        $tituloBeta = $this->titulo($beta, 'Desinsetização da cozinha', [
            ['valor' => 250.00, 'vencimento' => self::HOJE],
        ], $categoria);

        return compact('alfa', 'beta', 'categoria', 'tituloAlfa', 'tituloBeta');
    }

    /**
     * Dois fornecedores, duas parcelas: o aluguel vencendo hoje e a compra de
     * produto vencendo no fim do mês.
     *
     * @return array<string, mixed>
     */
    private function cenarioDeContasAPagar(): array
    {
        $categoria = $this->criarCategoria('3.01', 'Aluguel', 'despesa');
        $imobiliaria = $this->criarFornecedor('Imobiliária Central');
        $quimica = $this->criarFornecedor('Distribuidora Química');

        $this->tituloAPagar('Aluguel da sede', 1200.00, self::HOJE, $imobiliaria, $categoria);
        $this->tituloAPagar('Compra de isca', 340.00, '2026-07-30', $quimica);

        return compact('categoria', 'imobiliaria', 'quimica');
    }

    /**
     * Parcelas em aberto espalhadas pelas faixas de atraso do aging.
     */
    private function cenarioDeInadimplencia(): void
    {
        $alfa = $this->criarCliente('Padaria Alfa');
        $beta = $this->criarCliente('Mercado Beta');

        $this->titulo($alfa, 'Cobranças em atraso', [
            ['valor' => 100.00, 'vencimento' => '2026-08-15'],
            ['valor' => 200.00, 'vencimento' => '2026-07-01'],
            ['valor' => 400.00, 'vencimento' => '2026-01-05'],
        ]);

        $this->titulo($beta, 'Cobrança em atraso', [
            ['valor' => 300.00, 'vencimento' => '2026-06-10'],
        ]);
    }

    /**
     * Parcelas a receber e a pagar dentro da janela da previsão de caixa.
     */
    private function cenarioDePrevisao(): void
    {
        $cliente = $this->criarCliente('Padaria Alfa');

        $this->titulo($cliente, 'Serviços do mês', [
            ['valor' => 900.00, 'vencimento' => '2026-07-28'],
            ['valor' => 600.00, 'vencimento' => '2026-08-28'],
        ]);

        $this->tituloAPagar('Aluguel da sede', 500.00, '2026-07-25');
    }

    /**
     * O cenário do bloco 9: o caixa como ele existe hoje em produção, com os
     * três casos que impedem a troca de fonte, migrado para o modelo de
     * títulos pela Task 18.2.
     *
     * - OS-8001: parcela de 500 recebida em 10/07, com lançamento vinculado.
     * - OS-8002: parcela de 300 recebida em 12/07, reaberta em 14/07
     *   (saída `payment_reopen` de 300) e recebida de novo no mesmo dia.
     * - OS-8003: parcela de 200 recebida em 15/07 **sem** lançamento de caixa
     *   vinculado.
     * - Entrada manual de 80 e saída manual de 50, sem título nenhum.
     */
    private function cenarioDeCaixaComMigracao(): void
    {
        $this->comTenant(function (): void {
            $aVista = $this->criarOrdem('OS-8001', 500.00);
            $parcelaPaga = PaymentDetail::create([
                'work_order_id' => $aVista->id,
                'final_amount' => 500.00,
                'payment_due_date' => '2026-07-10',
                'payment_date' => '2026-07-10',
                'payment_method' => 'pix',
                'amount_paid' => 500.00,
                'payment_status' => 'paid',
            ]);
            $this->lancamentoDeRecebimento($parcelaPaga, 500.00, '2026-07-10', 'pix');

            // Reabertura: a entrada original, a saída que a compensa e a
            // entrada nova. A parcela fica com um `amount_paid` só.
            $reaberta = $this->criarOrdem('OS-8002', 300.00);
            $parcelaReaberta = PaymentDetail::create([
                'work_order_id' => $reaberta->id,
                'final_amount' => 300.00,
                'payment_due_date' => '2026-07-12',
                'payment_date' => '2026-07-14',
                'payment_method' => 'cash',
                'amount_paid' => 300.00,
                'payment_status' => 'paid',
            ]);
            $this->lancamentoDeRecebimento($parcelaReaberta, 300.00, '2026-07-12', 'cash');
            FinancialEntry::create([
                'source' => 'payment_reopen',
                'amount' => 300.00,
                'description' => 'Reabertura de pagamento - OS #'.$reaberta->id,
                'entry_date' => '2026-07-14',
                'payment_method' => 'cash',
                'status' => 'confirmed',
                'work_order_id' => $reaberta->id,
                'created_by' => $this->administrador->id,
            ]);
            $this->lancamentoDeRecebimento($parcelaReaberta, 300.00, '2026-07-14', 'cash');

            // Recebimento antigo sem lançamento de caixa vinculado.
            $semLancamento = $this->criarOrdem('OS-8003', 200.00);
            PaymentDetail::create([
                'work_order_id' => $semLancamento->id,
                'final_amount' => 200.00,
                'payment_due_date' => '2026-07-15',
                'payment_date' => '2026-07-15',
                'payment_method' => 'boleto',
                'amount_paid' => 200.00,
                'payment_status' => 'paid',
            ]);

            FinancialEntry::create([
                'source' => 'manual',
                'amount' => 80.00,
                'description' => 'Aporte do sócio',
                'entry_date' => '2026-07-15',
                'payment_method' => 'bank_transfer',
                'status' => 'confirmed',
                'created_by' => $this->administrador->id,
            ]);

            FinancialEntry::create([
                'source' => 'manual_withdrawal',
                'amount' => 50.00,
                'description' => 'Combustível da van',
                'entry_date' => '2026-07-16',
                'payment_method' => 'cash',
                'status' => 'confirmed',
                'created_by' => $this->administrador->id,
            ]);
        });

        $this->artisan('financeiro:migrar-titulos', ['--force' => true])->assertSuccessful();
    }

    /**
     * Empresa vizinha com cliente, título, parcela, fornecedor e categoria
     * próprios.
     *
     * @return array<string, mixed>
     */
    private function cenarioDeOutraEmpresa(): array
    {
        $empresa = $this->empresaVizinha();

        return TenantAtual::comTenant($empresa->id, function () use ($empresa): array {
            $cliente = Client::create([
                'name' => 'Cliente da vizinha',
                'email' => 'cliente-vizinha@exemplo.test',
                'phone' => '11999990000',
                'cnpj' => '55.666.777/0001-88',
            ]);

            $titulo = Receivable::create([
                'client_id' => $cliente->id,
                'descricao' => 'Cobrança da vizinha',
                'valor_total' => 100.00,
                'emitido_em' => self::HOJE,
                'situacao' => 'aberto',
            ]);

            $parcela = ReceivableInstallment::create([
                'receivable_id' => $titulo->id,
                'numero' => 1,
                'valor' => 100.00,
                'vencimento' => '2026-08-01',
                'situacao' => 'aberta',
            ]);

            $fornecedor = Supplier::create(['nome' => 'Fornecedor da vizinha', 'ativo' => true]);

            $aPagar = Payable::create([
                'supplier_id' => $fornecedor->id,
                'descricao' => 'Despesa da vizinha',
                'valor_total' => 100.00,
                'emitido_em' => self::HOJE,
                'situacao' => 'aberto',
                'recorrencia' => 'nenhuma',
            ]);

            $parcelaAPagar = PayableInstallment::create([
                'payable_id' => $aPagar->id,
                'numero' => 1,
                'valor' => 100.00,
                'vencimento' => '2026-08-01',
                'situacao' => 'aberta',
            ]);

            $categoria = ChartOfAccount::create([
                'codigo' => '9.99',
                'nome' => 'Categoria da vizinha',
                'tipo' => 'despesa',
                'ativo' => true,
            ]);

            return compact('empresa', 'cliente', 'titulo', 'parcela', 'fornecedor', 'aPagar', 'parcelaAPagar', 'categoria');
        });
    }

    private function empresaVizinha(): Company
    {
        return Company::create([
            'name' => 'Dedetizadora Vizinha',
            'cnpj' => '99.999.999/0001-99',
            'email' => 'contato@vizinha.test',
        ]);
    }

    /**
     * Administrador de um tenant novo, sem plano nenhum e portanto sem o
     * módulo `financeiro` por nenhuma via.
     */
    private function administradorDeTenantSemPlano(): User
    {
        $empresa = Company::create([
            'name' => 'Dedetizadora Sem Financeiro',
            'cnpj' => '88.888.888/0001-88',
            'email' => 'contato@sem-financeiro.test',
        ]);

        $usuario = TenantAtual::comTenant($empresa->id, fn () => User::factory()->create([
            'name' => 'Administrador sem financeiro',
            'email' => 'admin-sem-financeiro@exemplo.test',
        ]));

        $usuario->assignRole('administrador');

        return $usuario->fresh();
    }

    // -----------------------------------------------------------------
    // Apoio: painéis
    // -----------------------------------------------------------------

    /**
     * Os painéis como o endpoint os devolve hoje, normalizados para
     * comparação.
     *
     * @return array<string, mixed>
     */
    private function paineisDoDashboard(): array
    {
        $resposta = $this->actingAs($this->administrador)
            ->get('/financial-dashboard?start_date='.self::INICIO_DO_MES.'&end_date='.self::FIM_DO_MES);

        $resposta->assertOk();

        $props = $resposta->viewData('page')['props'];

        $porTipo = [];

        foreach (['work_order', 'manual'] as $indice => $origem) {
            $porTipo[$origem] = $this->normalizar($props['chartData']['typeChart']['datasets'][0]['data'][$indice]);
        }

        $porForma = [];

        foreach ($props['chartData']['methodChart']['labels'] as $indice => $rotulo) {
            $porForma[$rotulo] = $this->normalizar($props['chartData']['methodChart']['datasets'][0]['data'][$indice]);
        }

        $porMes = [];

        foreach ($props['chartData']['monthlyChart']['labels'] as $indice => $rotulo) {
            $porMes[$rotulo] = $this->normalizar($props['chartData']['monthlyChart']['datasets'][0]['data'][$indice]);
        }

        return [
            'stats' => [
                'total_amount' => $this->normalizar($props['stats']['total_amount']),
                'payment_amount' => $this->normalizar($props['stats']['payment_amount']),
                'manual_amount' => $this->normalizar($props['stats']['manual_amount']),
                'withdrawal_amount' => $this->normalizar($props['stats']['withdrawal_amount']),
                'total_entries' => (int) $props['stats']['total_entries'],
            ],
            'por_tipo' => $porTipo,
            'por_forma' => $porForma,
            'por_mes' => $porMes,
        ];
    }

    /**
     * Os mesmos painéis recalculados direto no banco, pela definição que eles
     * tinham antes desta task: soma de `financial_entries` confirmadas no
     * período, classificadas por `source`.
     *
     * Reimplementação de propósito: comparar o controller com ele mesmo não
     * provaria nada.
     *
     * @return array<string, mixed>
     */
    private function paineisPeloModeloAntigo(): array
    {
        $porOrigem = fn (array $origens): int => $this->somaDoCaixa($origens);

        $entradasDeOs = $porOrigem(['work_order']);
        $entradasManuais = $porOrigem(['manual']);
        $saidas = $porOrigem(['payment_reopen', 'manual_withdrawal']);

        $porForma = [];

        $consultaPorForma = DB::table('financial_entries')
            ->where('status', 'confirmed')
            ->whereIn('source', ['work_order', 'manual'])
            ->whereNotNull('payment_method')
            ->whereBetween('entry_date', [self::INICIO_DO_MES, self::FIM_DO_MES])
            ->select(['payment_method', 'amount']);

        foreach ($consultaPorForma->get() as $lancamento) {
            $rotulo = $this->rotuloDaForma((string) $lancamento->payment_method);
            $porForma[$rotulo] = ($porForma[$rotulo] ?? 0) + Dinheiro::centavos($lancamento->amount);
        }

        $porMes = [];

        $consultaPorMes = DB::table('financial_entries')
            ->where('status', 'confirmed')
            ->whereBetween('entry_date', [self::INICIO_DO_MES, self::FIM_DO_MES])
            ->select(['source', 'amount', 'entry_date']);

        foreach ($consultaPorMes->get() as $lancamento) {
            $mes = Carbon::parse($lancamento->entry_date)->format('M/Y');
            $centavos = Dinheiro::centavos($lancamento->amount);

            $porMes[$mes] = ($porMes[$mes] ?? 0) + match (true) {
                in_array($lancamento->source, ['work_order', 'manual'], true) => $centavos,
                in_array($lancamento->source, ['payment_reopen', 'manual_withdrawal'], true) => -$centavos,
                default => 0,
            };
        }

        return [
            'stats' => [
                'total_amount' => Dinheiro::paraDecimal($entradasDeOs + $entradasManuais - $saidas),
                'payment_amount' => Dinheiro::paraDecimal($entradasDeOs),
                'manual_amount' => Dinheiro::paraDecimal($entradasManuais),
                'withdrawal_amount' => Dinheiro::paraDecimal($saidas),
                'total_entries' => DB::table('financial_entries')
                    ->where('status', 'confirmed')
                    ->whereIn('source', ['work_order', 'manual'])
                    ->whereBetween('entry_date', [self::INICIO_DO_MES, self::FIM_DO_MES])
                    ->count(),
            ],
            'por_tipo' => [
                'work_order' => Dinheiro::paraDecimal($entradasDeOs),
                'manual' => Dinheiro::paraDecimal($entradasManuais),
            ],
            'por_forma' => array_map(fn (int $centavos): string => Dinheiro::paraDecimal($centavos), $porForma),
            'por_mes' => array_map(fn (int $centavos): string => Dinheiro::paraDecimal($centavos), $porMes),
        ];
    }

    /**
     * Quanto de recebimento de OS entrou no **caixa** no período.
     */
    private function recebimentoDeOsPeloCaixa(): string
    {
        return Dinheiro::paraDecimal($this->somaDoCaixa(['work_order']));
    }

    /**
     * Quanto de recebimento os **títulos** dizem ter sido liquidado no
     * período, pelo dia em que a parcela terminou de ser paga.
     */
    private function recebimentoPeloTitulo(): string
    {
        $total = 0;

        $parcelas = ReceivableInstallment::query()
            ->whereNotNull('pago_em')
            ->whereBetween('pago_em', [self::INICIO_DO_MES, self::FIM_DO_MES])
            ->select(['valor_pago']);

        foreach ($parcelas->cursor() as $parcela) {
            $total += Dinheiro::centavos($parcela->valor_pago);
        }

        return Dinheiro::paraDecimal($total);
    }

    /**
     * @param  array<int, string>  $origens
     */
    private function somaDoCaixa(array $origens): int
    {
        $total = 0;

        $consulta = DB::table('financial_entries')
            ->where('status', 'confirmed')
            ->whereIn('source', $origens)
            ->whereBetween('entry_date', [self::INICIO_DO_MES, self::FIM_DO_MES])
            ->select(['amount']);

        foreach ($consulta->get() as $lancamento) {
            $total += Dinheiro::centavos($lancamento->amount);
        }

        return $total;
    }

    /**
     * O mesmo rótulo que o painel usa no gráfico por forma de pagamento.
     */
    private function rotuloDaForma(string $forma): string
    {
        return match ($forma) {
            'pix' => 'PIX',
            'credit_card' => 'Cartão de Crédito',
            'debit_card' => 'Cartão de Débito',
            'boleto' => 'Boleto',
            'cash' => 'Dinheiro',
            'bank_transfer' => 'Transferência',
            default => 'Outros',
        };
    }

    private function normalizar(mixed $valor): string
    {
        return Dinheiro::paraDecimal(Dinheiro::centavos($valor === null ? 0 : $valor));
    }

    // -----------------------------------------------------------------
    // Apoio: fixtures
    // -----------------------------------------------------------------

    private function comTenant(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->id, $callback);
    }

    private function criarUsuario(?string $papel = null): User
    {
        $usuario = TenantAtual::comTenant($this->empresa->id, fn () => User::factory()->create([
            'name' => 'Usuário '.($papel ?? 'sem papel'),
            'email' => ($papel ?? 'usuario').'-'.uniqid().'@exemplo.test',
            'is_active' => true,
        ]));

        if ($papel !== null) {
            $usuario->assignRole($papel);
        }

        return $usuario->fresh();
    }

    private function criarCliente(string $nome): Client
    {
        return $this->comTenant(fn (): Client => ClientFactory::new()->create(['name' => $nome]));
    }

    private function criarFornecedor(string $nome): Supplier
    {
        return $this->comTenant(fn (): Supplier => Supplier::create(['nome' => $nome, 'ativo' => true]));
    }

    private function criarCategoria(string $codigo, string $nome, string $tipo): ChartOfAccount
    {
        return $this->comTenant(fn (): ChartOfAccount => ChartOfAccount::create([
            'codigo' => $codigo,
            'nome' => $nome,
            'tipo' => $tipo,
            'ativo' => true,
        ]));
    }

    private function criarOrdem(string $numero, float $valor): WorkOrder
    {
        return $this->comTenant(fn (): WorkOrder => WorkOrderFactory::new()->create([
            'order_number' => $numero,
            'total_cost' => $valor,
            'final_amount' => $valor,
            'payment_due_date' => '2026-08-01',
        ]));
    }

    /**
     * Título a receber com as parcelas informadas, gravado direto pelos
     * models: o cenário precisa de parcelas em situações que a geração normal
     * não produz de uma vez (parcial, vencida, a vencer).
     *
     * @param  list<array<string, mixed>>  $parcelas
     */
    private function titulo(Client $cliente, string $descricao, array $parcelas, ?ChartOfAccount $categoria = null): Receivable
    {
        return $this->comTenant(function () use ($cliente, $descricao, $parcelas, $categoria): Receivable {
            $titulo = Receivable::create([
                'client_id' => $cliente->id,
                'chart_of_account_id' => $categoria?->id,
                'descricao' => $descricao,
                'valor_total' => array_sum(array_column($parcelas, 'valor')),
                'emitido_em' => self::INICIO_DO_MES,
                'situacao' => 'aberto',
            ]);

            foreach ($parcelas as $indice => $parcela) {
                ReceivableInstallment::create([
                    'receivable_id' => $titulo->id,
                    'numero' => $indice + 1,
                    'valor' => $parcela['valor'],
                    'vencimento' => $parcela['vencimento'],
                    'valor_pago' => $parcela['valor_pago'] ?? 0,
                    'situacao' => $parcela['situacao'] ?? 'aberta',
                ]);
            }

            return $titulo->fresh(['installments']);
        });
    }

    private function tituloComDuasParcelas(): Receivable
    {
        return $this->titulo($this->criarCliente('Padaria Alfa'), 'Dedetização trimestral', [
            ['valor' => 250.00, 'vencimento' => '2026-08-10'],
            ['valor' => 250.00, 'vencimento' => '2026-09-10'],
        ]);
    }

    private function tituloAPagar(
        string $descricao,
        float $valor,
        string $vencimento,
        ?Supplier $fornecedor = null,
        ?ChartOfAccount $categoria = null,
    ): Payable {
        $fornecedor ??= $this->criarFornecedor('Fornecedor '.uniqid());

        return $this->comTenant(function () use ($descricao, $valor, $vencimento, $fornecedor, $categoria): Payable {
            $titulo = Payable::create([
                'supplier_id' => $fornecedor->id,
                'chart_of_account_id' => $categoria?->id,
                'descricao' => $descricao,
                'valor_total' => $valor,
                'emitido_em' => self::INICIO_DO_MES,
                'situacao' => 'aberto',
                'recorrencia' => 'nenhuma',
            ]);

            PayableInstallment::create([
                'payable_id' => $titulo->id,
                'numero' => 1,
                'valor' => $valor,
                'vencimento' => $vencimento,
                'situacao' => 'aberta',
            ]);

            return $titulo->fresh(['installments']);
        });
    }

    private function lancamentoDeRecebimento(PaymentDetail $parcela, float $valor, string $dia, string $forma): FinancialEntry
    {
        return FinancialEntry::create([
            'source' => 'work_order',
            'amount' => $valor,
            'description' => 'Pagamento recebido - OS #'.$parcela->work_order_id,
            'entry_date' => $dia,
            'payment_method' => $forma,
            'reference_number' => 'PAY-'.$parcela->id,
            'status' => 'confirmed',
            'work_order_id' => $parcela->work_order_id,
            'payment_detail_id' => $parcela->id,
            'created_by' => $this->administrador->id,
        ]);
    }

    private function baixar(ReceivableInstallment $parcela, float $valor, string $dia): void
    {
        $this->actingAs($this->administrador)
            ->postJson("/contas-a-receber/parcelas/{$parcela->id}/baixar", [
                'valor' => $valor,
                'data' => $dia,
            ])->assertOk();
    }
}
