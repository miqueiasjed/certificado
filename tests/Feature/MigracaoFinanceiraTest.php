<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FinancialEntry;
use App\Models\PaymentDetail;
use App\Models\Receivable;
use App\Models\ReceivableInstallment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Financial\ConferenciaDeTotais;
use App\Services\ReceivableService;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Closure;
use Database\Factories\WorkOrderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 18.2 do Plano 18: migração do financeiro atual (`payment_details` +
 * `financial_entries`) para títulos a receber, com conferência de totais.
 *
 * O que este arquivo protege, em ordem de gravidade:
 *
 * - **Nenhum número que o cliente vê hoje muda.** Todos os totais do retrato
 *   batem depois da migração, e uma divergência de um centavo aborta e desfaz
 *   tudo.
 * - **O mesmo dinheiro não entra duas vezes.** A migração vincula o lançamento
 *   de caixa que já existe e não cria nenhum novo.
 * - **Nada é apagado.** `payment_details` e `financial_entries` continuam com a
 *   mesma contagem e os mesmos valores depois.
 * - **Caso ambíguo não é adivinhado.** Vai para o relatório de exceções e não é
 *   migrado.
 * - **Rodar duas vezes não duplica título.**
 *
 * O relógio fica fixo em 2026-07-20 durante a suíte inteira, para que
 * "vencida" e "em aberto" sejam sempre a mesma coisa em toda execução.
 *
 * Teste mínimo desta task: a suíte definitiva do módulo financeiro é a Task
 * 18.10, que pode substituir este arquivo.
 */
class MigracaoFinanceiraTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Dia em que a suíte "está rodando".
     */
    private const HOJE = '2026-07-20';

    private Company $empresa;

    private User $operador;

    /**
     * Arquivos gravados pela conferência durante o teste, apagados no fim para
     * a suíte não deixar lixo em storage/.
     *
     * @var list<string>
     */
    private array $arquivosGravados = [];

    /**
     * Relatórios que já existiam em storage/logs antes do teste. O teste apaga
     * só o que ele mesmo gravou: apagar o relatório de uma migração de verdade
     * seria destruir justamente o documento que o operador precisa guardar.
     *
     * @var list<string>
     */
    private array $relatoriosAnteriores = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::HOJE.' 12:00:00');

        TenantAtual::limpar();

        $this->empresa = Company::query()->firstOrFail();
        $this->operador = User::factory()->create(['company_id' => $this->empresa->id]);
        $this->relatoriosAnteriores = File::glob(storage_path('logs/migracao-financeira-*.log')) ?: [];
    }

    protected function tearDown(): void
    {
        $relatoriosNovos = array_diff(
            File::glob(storage_path('logs/migracao-financeira-*.log')) ?: [],
            $this->relatoriosAnteriores
        );

        foreach (array_merge($this->arquivosGravados, $relatoriosNovos) as $arquivo) {
            File::delete($arquivo);
        }

        TenantAtual::limpar();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Simulação
    // -----------------------------------------------------------------

    public function test_dry_run_produz_o_relatorio_sem_gravar_nada(): void
    {
        $this->montarBase();

        $this->artisan('financeiro:migrar-titulos', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0, Receivable::query()->count(), 'a simulação gravou título');
        $this->assertSame(0, ReceivableInstallment::query()->count(), 'a simulação gravou parcela');
    }

    public function test_retrato_e_capturado_em_arquivo_json_antes_da_migracao(): void
    {
        $this->montarBase();

        $captura = $this->capturar();

        $this->assertTrue(File::exists($captura['caminho']), 'o retrato não foi gravado em arquivo');

        $lido = json_decode(File::get($captura['caminho']), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(ConferenciaDeTotais::VERSAO, $lido['versao']);
        $this->assertArrayHasKey($this->empresa->id, $lido['empresas']);
        $this->assertArrayHasKey('intocavel', $lido['empresas'][$this->empresa->id]);
        $this->assertArrayHasKey('espelhado', $lido['empresas'][$this->empresa->id]);
    }

    public function test_retrato_traz_os_numeros_que_o_cliente_ve_hoje(): void
    {
        $this->montarBase();

        $totais = $this->capturar()['retrato']['empresas'][$this->empresa->id];

        // Espelhado: o que o modelo novo passa a responder.
        // 350 (resto da parcial) + 400 (vencida) + 1.200 (seis parcelas).
        $this->assertSame(195000, $totais['espelhado']['a_receber']);
        $this->assertSame(75000, $totais['espelhado']['recebido_total']);
        $this->assertSame(75000, $totais['espelhado']['recebido_por_mes']['2026-07']);
        $this->assertSame(50000, $totais['espelhado']['recebido_por_forma']['pix']);
        $this->assertSame(25000, $totais['espelhado']['recebido_por_forma']['cash']);
        $this->assertSame(9, $totais['espelhado']['qtd_parcelas']);
        $this->assertSame(2, $totais['espelhado']['qtd_parcelas_com_recebimento']);
        $this->assertSame(7, $totais['espelhado']['qtd_parcelas_em_aberto']);

        // Intocável: o painel financeiro e o caixa, que a migração não pode
        // encostar. 500 + 250 + 150 de OS, mais 80 de entrada manual.
        $this->assertSame(90000, $totais['intocavel']['painel_resumo']['entradas_de_os']);
        $this->assertSame(8000, $totais['intocavel']['painel_resumo']['entradas_manuais']);
        $this->assertSame(98000, $totais['intocavel']['painel_resumo']['liquido']);
        $this->assertSame(4, $totais['intocavel']['painel_resumo']['qtd_lancamentos_de_entrada']);
        $this->assertSame(50000, $totais['intocavel']['saldo_por_dia']['2026-07-10']['entradas']);
        $this->assertSame(195000, $totais['intocavel']['a_receber_por_os']);
    }

    // -----------------------------------------------------------------
    // Migração completa
    // -----------------------------------------------------------------

    public function test_migracao_preserva_todos_os_totais_do_retrato(): void
    {
        $base = $this->montarBase();

        $retrato = $this->capturar()['retrato'];

        $this->artisan('financeiro:migrar-titulos', ['--force' => true])
            ->assertSuccessful();

        $this->assertSame(
            [],
            app(ConferenciaDeTotais::class)->comparar($retrato),
            'algum total mudou depois da migração'
        );

        // Uma OS, um título: três OS com parcela viram três títulos.
        $this->assertSame(3, Receivable::query()->count());
        $this->assertSame(9, ReceivableInstallment::query()->count());

        // A parcela paga nasce paga, com valor e data copiados exatamente.
        $paga = $this->parcelaMigrada($base['parcela_paga']);

        $this->assertSame('paga', $paga->situacao);
        $this->assertSame('500.00', $paga->valor);
        $this->assertSame('500.00', $paga->valor_pago);
        $this->assertSame('2026-07-10', $paga->pago_em->toDateString());
        $this->assertSame($base['lancamento_da_parcela_paga'], $paga->financial_entry_id);

        // A parcial mantém o que entrou e continua devendo a diferença.
        $parcial = $this->parcelaMigrada($base['parcela_parcial']);

        $this->assertSame('parcial', $parcial->situacao);
        $this->assertSame('600.00', $parcial->valor);
        $this->assertSame('250.00', $parcial->valor_pago);
        $this->assertSame('2026-07-05', $parcial->pago_em->toDateString());

        // A vencida é reconhecida pelo dia no fuso do negócio.
        $vencida = $this->parcelaMigrada($base['parcela_vencida']);

        $this->assertSame('vencida', $vencida->situacao);
        $this->assertSame('0.00', $vencida->valor_pago);
        $this->assertNull($vencida->pago_em);
        $this->assertNull($vencida->financial_entry_id);

        // A OS de seis parcelas vira um título só, com as seis numeradas em
        // ordem de vencimento.
        $parceladaEm6 = Receivable::query()->where('work_order_id', $base['os_parcelada'])->firstOrFail();

        $this->assertSame('1200.00', $parceladaEm6->valor_total);
        $this->assertSame('aberto', $parceladaEm6->situacao);
        $this->assertSame(
            [1, 2, 3, 4, 5, 6],
            $parceladaEm6->installments()->orderBy('numero')->pluck('numero')->all()
        );

        // Situação do título acompanha as parcelas.
        $this->assertSame(
            'quitado',
            Receivable::query()->where('work_order_id', $base['os_a_vista'])->firstOrFail()->situacao
        );
        $this->assertSame(
            'parcial',
            Receivable::query()->where('work_order_id', $base['os_em_duas'])->firstOrFail()->situacao
        );
    }

    public function test_migracao_nao_cria_lancamento_novo_no_caixa(): void
    {
        $this->montarBase();

        $lancamentosAntes = $this->linhasDe('financial_entries');
        $saldosAntes = $this->linhasDe('daily_cash_balances');

        $this->artisan('financeiro:migrar-titulos', ['--force' => true])
            ->assertSuccessful();

        $this->assertEquals(
            $lancamentosAntes,
            $this->linhasDe('financial_entries'),
            'a migração mexeu nos lançamentos de caixa: o mesmo dinheiro entraria duas vezes'
        );
        $this->assertEquals(
            $saldosAntes,
            $this->linhasDe('daily_cash_balances'),
            'a migração mexeu no saldo diário'
        );
    }

    public function test_dado_antigo_continua_com_a_mesma_contagem_e_os_mesmos_valores(): void
    {
        $this->montarBase();

        $parcelasAntes = $this->linhasDe('payment_details');

        $this->artisan('financeiro:migrar-titulos', ['--force' => true])
            ->assertSuccessful();

        $this->assertEquals($parcelasAntes, $this->linhasDe('payment_details'), 'payment_details foi alterada');
        $this->assertSame(count($parcelasAntes), PaymentDetail::query()->count());
    }

    // -----------------------------------------------------------------
    // Divergência
    // -----------------------------------------------------------------

    public function test_divergencia_de_um_centavo_aborta_e_desfaz_tudo(): void
    {
        $this->montarBase();

        $caminho = $this->retratoComUmCentavoADiferenca();

        $this->artisan('financeiro:migrar-titulos', ['--force' => true, '--retrato' => $caminho])
            ->assertFailed();

        $this->assertSame(0, Receivable::query()->count(), 'a migração abortada deixou título gravado');
        $this->assertSame(0, ReceivableInstallment::query()->count(), 'a migração abortada deixou parcela gravada');
    }

    public function test_divergencia_aparece_no_relatorio_com_o_total_que_nao_bateu(): void
    {
        $this->montarBase();

        $retrato = $this->capturar()['retrato'];
        $empresa = (string) $this->empresa->id;

        $retrato['empresas'][$empresa]['espelhado']['a_receber'] += 1;

        $divergencias = $this->comDadoMigrado(
            fn (): array => app(ConferenciaDeTotais::class)->comparar($retrato)
        );

        $this->assertCount(1, $divergencias);
        $this->assertSame($empresa.'.espelhado.a_receber', $divergencias[0]['caminho']);
        $this->assertSame(-1, $divergencias[0]['diferenca']);
    }

    public function test_lancamento_de_caixa_criado_no_meio_do_caminho_e_acusado(): void
    {
        $this->montarBase();

        $retrato = $this->capturar()['retrato'];

        // É o cenário que a migração precisa impedir: alguém lançando de novo,
        // no caixa, dinheiro que já estava lançado.
        $this->comTenant(fn (): FinancialEntry => FinancialEntry::create([
            'source' => 'work_order',
            'amount' => 500.00,
            'description' => 'Mesmo recebimento lançado uma segunda vez',
            'entry_date' => '2026-07-10',
            'payment_method' => 'pix',
            'status' => 'confirmed',
            'created_by' => $this->operador->id,
        ]));

        $divergencias = app(ConferenciaDeTotais::class)->comparar($retrato);

        $this->assertNotEmpty($divergencias, 'lançamento novo no caixa passou pela conferência');
        $this->assertContains(
            $this->empresa->id.'.intocavel.painel_resumo.entradas_de_os',
            array_column($divergencias, 'caminho')
        );
        $this->assertContains(
            $this->empresa->id.'.intocavel.saldo_por_dia.2026-07-10.entradas',
            array_column($divergencias, 'caminho')
        );
    }

    // -----------------------------------------------------------------
    // Exceções
    // -----------------------------------------------------------------

    public function test_casos_ambiguos_vao_para_o_relatorio_e_nao_sao_migrados(): void
    {
        $base = $this->montarBase();
        $ambiguas = $this->montarCasosAmbiguos();

        $retrato = $this->capturar()['retrato'];

        $this->artisan('financeiro:migrar-titulos', ['--force' => true])
            ->assertSuccessful();

        foreach ($ambiguas as $rotulo => $parcelaId) {
            $this->assertSame(
                0,
                ReceivableInstallment::query()->where('payment_detail_id', $parcelaId)->count(),
                "o caso ambíguo \"{$rotulo}\" foi migrado em vez de virar exceção"
            );
        }

        // O que não é ambíguo continua migrando normalmente.
        $this->assertSame(
            1,
            ReceivableInstallment::query()->where('payment_detail_id', $base['parcela_paga'])->count()
        );

        // E os totais continuam batendo: o que ficou de fora continua contando
        // pelo modelo antigo, então nenhum centavo some no caminho.
        $this->assertSame([], app(ConferenciaDeTotais::class)->comparar($retrato));

        $relatorio = $this->ultimoRelatorio();

        foreach (['parcela_sem_valor', 'parcela_sem_vencimento', 'valor_divergente_do_lancamento', 'os_com_titulo_do_fluxo_novo', 'parcela_sem_os'] as $motivo) {
            $this->assertStringContainsString(
                '#'.$ambiguas[$motivo],
                $relatorio,
                "o caso \"{$motivo}\" não aparece no relatório de exceções"
            );
        }

        $this->assertStringContainsString('Lançamentos de recebimento sem parcela correspondente', $relatorio);
        $this->assertStringContainsString('#'.$base['lancamento_orfao'], $relatorio);
    }

    // -----------------------------------------------------------------
    // Reexecução
    // -----------------------------------------------------------------

    public function test_rodar_duas_vezes_nao_duplica_titulo(): void
    {
        $this->montarBase();

        $this->artisan('financeiro:migrar-titulos', ['--force' => true])->assertSuccessful();

        $titulos = Receivable::query()->count();
        $parcelas = ReceivableInstallment::query()->count();

        $this->artisan('financeiro:migrar-titulos', ['--force' => true])->assertSuccessful();

        $this->assertSame($titulos, Receivable::query()->count(), 'a segunda execução duplicou título');
        $this->assertSame($parcelas, ReceivableInstallment::query()->count(), 'a segunda execução duplicou parcela');
    }

    public function test_parcela_criada_depois_da_migracao_entra_no_titulo_que_ja_existe(): void
    {
        $base = $this->montarBase();

        $this->artisan('financeiro:migrar-titulos', ['--force' => true])->assertSuccessful();

        $titulo = Receivable::query()->where('work_order_id', $base['os_a_vista'])->firstOrFail();

        $nova = $this->comTenant(fn (): PaymentDetail => PaymentDetail::create([
            'work_order_id' => $base['os_a_vista'],
            'final_amount' => 120.00,
            'payment_due_date' => '2026-09-01',
        ]));

        $this->artisan('financeiro:migrar-titulos', ['--force' => true])->assertSuccessful();

        $this->assertSame(3, Receivable::query()->count(), 'a parcela nova gerou um segundo título para a mesma OS');
        $this->assertSame(
            $titulo->id,
            $this->parcelaMigrada($nova->id)->receivable_id,
            'a parcela nova não foi anexada ao título já migrado da OS'
        );
        $this->assertSame('620.00', $titulo->refresh()->valor_total, 'o total do título não somou a parcela nova');
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Base de exemplo com os casos que o financeiro real tem hoje: parcela
     * paga com lançamento no caixa, parcela parcial, parcela vencida, OS de
     * parcela única, OS de seis parcelas e um lançamento de recebimento sem
     * parcela correspondente.
     *
     * @return array<string, int>
     */
    private function montarBase(): array
    {
        return $this->comTenant(function (): array {
            // OS à vista, quitada.
            $osAVista = $this->ordem('OS-0001', 500.00);
            $parcelaPaga = PaymentDetail::create([
                'work_order_id' => $osAVista->id,
                'final_amount' => 500.00,
                'payment_due_date' => '2026-07-10',
                'payment_date' => '2026-07-10',
                'payment_method' => 'pix',
                'amount_paid' => 500.00,
                'payment_status' => 'paid',
            ]);
            $lancamentoDaPaga = $this->lancamentoDeRecebimento($parcelaPaga, 500.00, '2026-07-10', 'pix');

            // OS em duas parcelas: uma recebida pela metade, uma vencida.
            $osEmDuas = $this->ordem('OS-0002', 1000.00);
            $parcelaParcial = PaymentDetail::create([
                'work_order_id' => $osEmDuas->id,
                'final_amount' => 600.00,
                'payment_due_date' => '2026-07-05',
                'payment_date' => '2026-07-05',
                'payment_method' => 'cash',
                'amount_paid' => 250.00,
                'payment_status' => 'partial',
                'is_partial_payment' => true,
            ]);
            $this->lancamentoDeRecebimento($parcelaParcial, 250.00, '2026-07-05', 'cash');

            $parcelaVencida = PaymentDetail::create([
                'work_order_id' => $osEmDuas->id,
                'final_amount' => 400.00,
                'payment_due_date' => '2026-07-01',
                'payment_status' => 'overdue',
            ]);

            // OS parcelada em seis, todas em aberto.
            $osParcelada = $this->ordem('OS-0003', 1200.00);

            for ($numero = 0; $numero < 6; $numero++) {
                PaymentDetail::create([
                    'work_order_id' => $osParcelada->id,
                    'final_amount' => 200.00,
                    'payment_due_date' => Carbon::parse('2026-08-01')->addMonthsNoOverflow($numero)->toDateString(),
                ]);
            }

            // Lançamento de recebimento sem parcela correspondente: dinheiro no
            // caixa que nenhuma cobrança explica.
            $orfao = FinancialEntry::create([
                'source' => 'work_order',
                'amount' => 150.00,
                'description' => 'Recebimento avulso lançado à mão',
                'entry_date' => '2026-07-12',
                'payment_method' => 'boleto',
                'status' => 'confirmed',
                'created_by' => $this->operador->id,
            ]);

            // Entrada manual, que aparece no painel financeiro e não tem nada a
            // ver com parcela.
            FinancialEntry::create([
                'source' => 'manual',
                'amount' => 80.00,
                'description' => 'Aporte do sócio',
                'entry_date' => '2026-07-15',
                'payment_method' => 'bank_transfer',
                'status' => 'confirmed',
                'created_by' => $this->operador->id,
            ]);

            return [
                'os_a_vista' => $osAVista->id,
                'os_em_duas' => $osEmDuas->id,
                'os_parcelada' => $osParcelada->id,
                'parcela_paga' => $parcelaPaga->id,
                'parcela_parcial' => $parcelaParcial->id,
                'parcela_vencida' => $parcelaVencida->id,
                'lancamento_da_parcela_paga' => $lancamentoDaPaga->id,
                'lancamento_orfao' => $orfao->id,
            ];
        });
    }

    /**
     * Um caso de cada motivo de exceção, todos válidos como dado antigo e
     * nenhum deles resolvível sem alguém decidir.
     *
     * @return array<string, int>
     */
    private function montarCasosAmbiguos(): array
    {
        return $this->comTenant(function (): array {
            $semValor = PaymentDetail::create([
                'work_order_id' => $this->ordem('OS-9001', 300.00)->id,
                'payment_due_date' => '2026-08-10',
            ]);

            $semVencimento = PaymentDetail::create([
                'work_order_id' => $this->ordem('OS-9002', 300.00)->id,
                'final_amount' => 300.00,
            ]);

            $ordemDivergente = $this->ordem('OS-9003', 300.00);
            $valorDivergente = PaymentDetail::create([
                'work_order_id' => $ordemDivergente->id,
                'final_amount' => 300.00,
                'payment_due_date' => '2026-07-08',
                'payment_date' => '2026-07-08',
                'payment_method' => 'pix',
                'amount_paid' => 300.00,
                'payment_status' => 'paid',
            ]);
            // O lançamento do caixa diz 299,00 e a parcela diz 300,00.
            $this->lancamentoDeRecebimento($valorDivergente, 299.00, '2026-07-08', 'pix');

            // OS que já tem título gerado pelo fluxo novo (Task 18.3).
            $ordemComTitulo = $this->ordem('OS-9004', 300.00, '2026-08-20');
            $comTituloNovo = PaymentDetail::create([
                'work_order_id' => $ordemComTitulo->id,
                'final_amount' => 300.00,
                'payment_due_date' => '2026-08-20',
            ]);
            app(ReceivableService::class)->gerarDaOs($ordemComTitulo, ['forma' => ReceivableService::FORMA_A_VISTA]);

            return [
                'parcela_sem_valor' => $semValor->id,
                'parcela_sem_vencimento' => $semVencimento->id,
                'valor_divergente_do_lancamento' => $valorDivergente->id,
                'os_com_titulo_do_fluxo_novo' => $comTituloNovo->id,
                'parcela_sem_os' => $this->parcelaOrfa(),
            ];
        });
    }

    /**
     * Parcela apontando para uma OS que não existe mais.
     *
     * Em produção esse registro existe (dado anterior à chave estrangeira);
     * aqui ele precisa ser inserido com a checagem de chave desligada, que é a
     * única forma de reproduzir o estado real.
     */
    private function parcelaOrfa(): int
    {
        Schema::disableForeignKeyConstraints();

        $id = DB::table('payment_details')->insertGetId([
            'company_id' => $this->empresa->id,
            'work_order_id' => 999999,
            'final_amount' => 90.00,
            'payment_due_date' => '2026-08-30',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::enableForeignKeyConstraints();

        return $id;
    }

    private function ordem(string $numero, float $valor, string $vencimento = '2026-07-10'): WorkOrder
    {
        return WorkOrderFactory::new()->create([
            'order_number' => $numero,
            'total_cost' => $valor,
            'final_amount' => $valor,
            'payment_due_date' => $vencimento,
        ]);
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
            'created_by' => $this->operador->id,
        ]);
    }

    /**
     * Retrato com um centavo a mais no total a receber: o cenário que a regra
     * do plano manda abortar.
     */
    private function retratoComUmCentavoADiferenca(): string
    {
        $captura = $this->capturar();
        $retrato = $captura['retrato'];
        $empresa = (string) $this->empresa->id;

        $retrato['empresas'][$empresa]['espelhado']['a_receber'] += 1;

        $caminho = storage_path('app/financeiro/migracao/retrato-com-divergencia.json');

        File::ensureDirectoryExists(dirname($caminho));
        File::put($caminho, json_encode($retrato, JSON_THROW_ON_ERROR));

        $this->arquivosGravados[] = $caminho;

        return $caminho;
    }

    /**
     * @return array{retrato: array<string, mixed>, caminho: string}
     */
    private function capturar(): array
    {
        $captura = app(ConferenciaDeTotais::class)->capturar();

        $this->arquivosGravados[] = $captura['caminho'];

        return $captura;
    }

    /**
     * Roda a migração de verdade e devolve o resultado do callback, para
     * conferir o modelo novo já povoado.
     */
    private function comDadoMigrado(Closure $callback): mixed
    {
        $this->artisan('financeiro:migrar-titulos', ['--force' => true])->assertSuccessful();

        return $callback();
    }

    private function parcelaMigrada(int $parcelaAntiga): ReceivableInstallment
    {
        return ReceivableInstallment::query()
            ->where('payment_detail_id', $parcelaAntiga)
            ->firstOrFail();
    }

    /**
     * Conteúdo bruto de uma tabela, para comparar antes e depois.
     *
     * @return array<int, array<string, mixed>>
     */
    private function linhasDe(string $tabela): array
    {
        return DB::table($tabela)->orderBy('id')->get()->map(
            static fn ($linha): array => (array) $linha
        )->all();
    }

    /**
     * Último relatório gravado pelo comando em storage/logs.
     */
    private function ultimoRelatorio(): string
    {
        $arquivos = collect(File::glob(storage_path('logs/migracao-financeira-*.log')))
            ->sort()
            ->values();

        $this->assertNotEmpty($arquivos, 'o comando não gravou relatório');

        return File::get($arquivos->last());
    }

    private function comTenant(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->id, $callback);
    }
}
