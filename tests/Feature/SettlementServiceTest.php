<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DailyCashBalance;
use App\Models\FinancialEntry;
use App\Models\Receivable;
use App\Models\ReceivableInstallment;
use App\Models\User;
use App\Services\ReceivableService;
use App\Services\SettlementService;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Closure;
use Database\Factories\WorkOrderFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Task 18.4 do Plano 18: baixa total e parcial de parcela a receber,
 * integrada ao caixa que já existe.
 *
 * O que este arquivo protege, em ordem de gravidade:
 *
 * - O mesmo dinheiro não entra duas vezes: cada baixa cria exatamente um
 *   lançamento, e o saldo diário bate com a soma dos lançamentos.
 * - Parcela e caixa nunca ficam dessincronizados: a recusa de uma baixa não
 *   deixa lançamento solto, e o estorno não apaga o lançamento original.
 * - A data que vale é a informada, não a de hoje.
 * - Estorno exige permissão e motivo.
 *
 * O relógio fica fixo em 2026-07-20 durante a suíte inteira, para que "hoje"
 * seja um dia conhecido e a diferença entre a data informada e a data de hoje
 * apareça nas asserções.
 *
 * Teste mínimo desta task: a suíte definitiva do módulo financeiro é a Task
 * 18.10, que pode substituir este arquivo.
 */
class SettlementServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Dia em que a suíte "está rodando". Vencimento das parcelas criadas nos
     * cenários fica depois dele, para o estorno devolver a parcela para
     * `aberta` e não para `vencida`.
     */
    private const HOJE = '2026-07-20';

    /**
     * Dia do recebimento usado nos cenários: antes de "hoje", como acontece
     * quando o usuário lança o depósito dias depois.
     */
    private const DIA_DO_RECEBIMENTO = '2026-07-10';

    private SettlementService $servico;

    private ReceivableService $receivableService;

    private Company $empresa;

    /**
     * Quem lança a baixa e tem permissão de estorno.
     */
    private User $operador;

    /**
     * Usuário do mesmo tenant, sem `financeiro-estornar`.
     */
    private User $semPermissao;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::HOJE.' 12:00:00');

        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->servico = app(SettlementService::class);
        $this->receivableService = app(ReceivableService::class);
        $this->empresa = Company::query()->firstOrFail();

        $this->operador = User::factory()->create(['company_id' => $this->empresa->id]);
        $this->operador->givePermissionTo(SettlementService::PERMISSAO_ESTORNO);

        $this->semPermissao = User::factory()->create(['company_id' => $this->empresa->id]);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Baixa total
    // -----------------------------------------------------------------

    public function test_baixa_total_marca_paga_e_cria_a_entrada_no_caixa(): void
    {
        $parcela = $this->parcelaUnica(500.00);

        $baixada = $this->comTenant(fn () => $this->servico->baixar($parcela, [
            'valor' => 500.00,
            'data' => self::DIA_DO_RECEBIMENTO,
            'forma_pagamento' => 'pix',
            'observacao' => 'Depósito conferido no extrato',
            'usuario' => $this->operador,
        ]));

        $this->assertSame('paga', $baixada->situacao);
        $this->assertSame('500.00', $baixada->valor_pago);
        $this->assertSame(self::DIA_DO_RECEBIMENTO, BusinessDate::diaDe($baixada->pago_em));
        $this->assertNotNull($baixada->financial_entry_id);

        $this->assertSame(1, FinancialEntry::query()->count(), 'a baixa criou mais de um lançamento de caixa');

        $lancamento = FinancialEntry::query()->findOrFail($baixada->financial_entry_id);

        $this->assertSame('500.00', $lancamento->amount);
        $this->assertSame(self::DIA_DO_RECEBIMENTO, BusinessDate::diaDe($lancamento->entry_date));
        $this->assertSame('confirmed', $lancamento->status);
        $this->assertSame('pix', $lancamento->payment_method);
        $this->assertSame($this->operador->id, $lancamento->created_by);
        $this->assertContains(
            $lancamento->source,
            ['work_order', 'manual'],
            'o lançamento precisa ter origem que o caixa atual classifica como entrada'
        );

        $this->assertSame(
            'quitado',
            $baixada->receivable()->firstOrFail()->situacao,
            'com a única parcela paga, o título precisa ficar quitado'
        );
    }

    public function test_baixa_registra_o_lancamento_no_saldo_diario_do_dia_informado(): void
    {
        $parcela = $this->parcelaUnica(320.00);

        $this->comTenant(fn () => $this->servico->baixar($parcela, [
            'valor' => 320.00,
            'data' => self::DIA_DO_RECEBIMENTO,
            'usuario' => $this->operador,
        ]));

        $saldo = DailyCashBalance::query()
            ->whereDate('balance_date', self::DIA_DO_RECEBIMENTO)
            ->firstOrFail();

        $this->assertSame('320.00', $saldo->total_entries);
        $this->assertSame(1, $saldo->entries_count);
    }

    // -----------------------------------------------------------------
    // Baixa parcial
    // -----------------------------------------------------------------

    public function test_duas_baixas_parciais_somam_e_a_segunda_marca_paga(): void
    {
        $parcela = $this->parcelaUnica(500.00);

        $primeira = $this->comTenant(fn () => $this->servico->baixar($parcela, [
            'valor' => 200.00,
            'data' => self::DIA_DO_RECEBIMENTO,
            'usuario' => $this->operador,
        ]));

        $this->assertSame('parcial', $primeira->situacao, 'baixa parcial precisa manter a parcela aberta');
        $this->assertSame('200.00', $primeira->valor_pago);
        $this->assertNull($primeira->pago_em, 'parcela ainda não quitada não tem data de quitação');
        $this->assertSame(
            'parcial',
            $primeira->receivable()->firstOrFail()->situacao,
            'título com parcela parcialmente recebida fica parcial'
        );

        $segunda = $this->comTenant(fn () => $this->servico->baixar($primeira, [
            'valor' => 300.00,
            'data' => self::DIA_DO_RECEBIMENTO,
            'usuario' => $this->operador,
        ]));

        $this->assertSame('paga', $segunda->situacao);
        $this->assertSame('500.00', $segunda->valor_pago, 'as duas baixas parciais precisam somar');
        $this->assertSame(self::DIA_DO_RECEBIMENTO, BusinessDate::diaDe($segunda->pago_em));

        $lancamentos = FinancialEntry::query()->get();

        $this->assertCount(2, $lancamentos, 'cada baixa cria um lançamento, nem a mais nem a menos');
        $this->assertSame(
            '500.00',
            number_format((float) $lancamentos->sum('amount'), 2, '.', ''),
            'a soma dos lançamentos precisa ser o valor da parcela'
        );
        $this->assertSame('quitado', $segunda->receivable()->firstOrFail()->situacao);
    }

    public function test_tres_baixas_parciais_de_valor_quebrado_fecham_a_parcela_no_centavo(): void
    {
        // 333,33 + 333,33 + 333,34: a divisão que o ReceivableService gera e
        // que uma soma em ponto flutuante fecharia em 999,99 ou 1000,01.
        $parcela = $this->parcelaUnica(1000.00);

        foreach (['333.33', '333.33', '333.34'] as $valor) {
            $parcela = $this->comTenant(fn () => $this->servico->baixar($parcela, [
                'valor' => $valor,
                'data' => self::DIA_DO_RECEBIMENTO,
                'usuario' => $this->operador,
            ]));
        }

        $this->assertSame('paga', $parcela->situacao);
        $this->assertSame('1000.00', $parcela->valor_pago);
    }

    // -----------------------------------------------------------------
    // Baixa acima do saldo devedor
    // -----------------------------------------------------------------

    public function test_baixa_acima_do_saldo_devedor_e_recusada_com_o_valor_na_mensagem(): void
    {
        $parcela = $this->parcelaUnica(500.00);

        $parcela = $this->comTenant(fn () => $this->servico->baixar($parcela, [
            'valor' => 250.00,
            'data' => self::DIA_DO_RECEBIMENTO,
            'usuario' => $this->operador,
        ]));

        try {
            $this->comTenant(fn () => $this->servico->baixar($parcela, [
                'valor' => 300.00,
                'data' => self::DIA_DO_RECEBIMENTO,
                'usuario' => $this->operador,
            ]));

            $this->fail('a baixa acima do saldo devedor precisava ser recusada');
        } catch (ValidationException $excecao) {
            $mensagem = $excecao->errors()['valor'][0] ?? '';

            $this->assertStringContainsString('R$ 250,00', $mensagem, 'a mensagem precisa dizer o saldo devedor');
            $this->assertStringContainsString('R$ 300,00', $mensagem, 'a mensagem precisa dizer o valor recusado');
        }

        $parcela->refresh();

        $this->assertSame('250.00', $parcela->valor_pago, 'a recusa não pode ter somado nada');
        $this->assertSame('parcial', $parcela->situacao);
        $this->assertSame(1, FinancialEntry::query()->count(), 'a recusa deixou lançamento solto no caixa');
    }

    public function test_baixa_em_parcela_ja_quitada_e_recusada(): void
    {
        $parcela = $this->parcelaUnica(150.00);

        $parcela = $this->comTenant(fn () => $this->servico->baixar($parcela, [
            'valor' => 150.00,
            'data' => self::DIA_DO_RECEBIMENTO,
            'usuario' => $this->operador,
        ]));

        $this->expectException(ValidationException::class);

        $this->comTenant(fn () => $this->servico->baixar($parcela, [
            'valor' => 10.00,
            'data' => self::DIA_DO_RECEBIMENTO,
            'usuario' => $this->operador,
        ]));
    }

    // -----------------------------------------------------------------
    // Data informada
    // -----------------------------------------------------------------

    public function test_a_data_usada_e_a_informada_e_nao_a_de_hoje(): void
    {
        $parcela = $this->parcelaUnica(400.00);

        $baixada = $this->comTenant(fn () => $this->servico->baixar($parcela, [
            'valor' => 400.00,
            'data' => self::DIA_DO_RECEBIMENTO,
            'usuario' => $this->operador,
        ]));

        $lancamento = FinancialEntry::query()->findOrFail($baixada->financial_entry_id);

        $this->assertSame(self::HOJE, BusinessDate::hoje()->toDateString(), 'o relógio do teste não está fixo');
        $this->assertSame(self::DIA_DO_RECEBIMENTO, BusinessDate::diaDe($lancamento->entry_date));
        $this->assertSame(self::DIA_DO_RECEBIMENTO, BusinessDate::diaDe($baixada->pago_em));
        $this->assertNotSame(
            self::HOJE,
            BusinessDate::diaDe($lancamento->entry_date),
            'a competência do caixa virou o dia de hoje em vez do dia informado'
        );
    }

    public function test_baixa_sem_data_e_recusada_em_vez_de_assumir_hoje(): void
    {
        $parcela = $this->parcelaUnica(100.00);

        $this->expectException(ValidationException::class);

        $this->comTenant(fn () => $this->servico->baixar($parcela, [
            'valor' => 100.00,
            'usuario' => $this->operador,
        ]));
    }

    // -----------------------------------------------------------------
    // Estorno
    // -----------------------------------------------------------------

    public function test_estorno_cria_lancamento_de_reversao_e_mantem_o_original(): void
    {
        $parcela = $this->parcelaUnica(500.00);

        $baixada = $this->comTenant(fn () => $this->servico->baixar($parcela, [
            'valor' => 500.00,
            'data' => self::DIA_DO_RECEBIMENTO,
            'forma_pagamento' => 'pix',
            'usuario' => $this->operador,
        ]));

        $original = FinancialEntry::query()->findOrFail($baixada->financial_entry_id);

        $estornada = $this->comTenant(fn () => $this->servico->estornar(
            $baixada,
            'Depósito devolvido pelo banco por divergência de titularidade',
            $this->operador
        ));

        // O original continua exatamente onde estava.
        $this->assertDatabaseHas('financial_entries', [
            'id' => $original->id,
            'amount' => '500.00',
            'status' => 'confirmed',
        ]);
        $this->assertSame(
            self::DIA_DO_RECEBIMENTO,
            BusinessDate::diaDe($original->fresh()->entry_date),
            'o estorno alterou a competência do lançamento original'
        );

        // E o estorno é um lançamento novo, de saída, no dia de hoje.
        $this->assertSame(2, FinancialEntry::query()->count());

        $estorno = FinancialEntry::query()->findOrFail($estornada->financial_entry_id);

        $this->assertNotSame($original->id, $estorno->id, 'o estorno precisa ser um lançamento novo');
        $this->assertSame('500.00', $estorno->amount);
        $this->assertSame(self::HOJE, BusinessDate::diaDe($estorno->entry_date), 'o estorno acontece no dia de hoje');
        $this->assertContains(
            $estorno->source,
            ['payment_reopen', 'manual_withdrawal'],
            'o estorno precisa ter origem que o caixa atual classifica como saída'
        );
        $this->assertStringContainsString('divergência de titularidade', (string) $estorno->notes);

        // A parcela volta a ser devida.
        $this->assertSame('aberta', $estornada->situacao);
        $this->assertSame('0.00', $estornada->valor_pago);
        $this->assertNull($estornada->pago_em);
        $this->assertSame('aberto', $estornada->receivable()->firstOrFail()->situacao);

        // E o caixa do dia do recebimento não muda; o do dia do estorno é que
        // recebe a saída.
        $saldoDoRecebimento = DailyCashBalance::query()
            ->whereDate('balance_date', self::DIA_DO_RECEBIMENTO)
            ->firstOrFail();
        $saldoDoEstorno = DailyCashBalance::query()
            ->whereDate('balance_date', self::HOJE)
            ->firstOrFail();

        $this->assertSame('500.00', $saldoDoRecebimento->total_entries);
        $this->assertSame('0.00', $saldoDoRecebimento->total_withdrawals);
        $this->assertSame('500.00', $saldoDoEstorno->total_withdrawals);
    }

    public function test_estorno_sem_permissao_devolve_403_e_nao_toca_em_nada(): void
    {
        $parcela = $this->parcelaUnica(500.00);

        $baixada = $this->comTenant(fn () => $this->servico->baixar($parcela, [
            'valor' => 500.00,
            'data' => self::DIA_DO_RECEBIMENTO,
            'usuario' => $this->operador,
        ]));

        try {
            $this->comTenant(fn () => $this->servico->estornar(
                $baixada,
                'Motivo suficientemente longo para passar na validação',
                $this->semPermissao
            ));

            $this->fail('o estorno sem permissão precisava ser barrado');
        } catch (AuthorizationException $excecao) {
            $resposta = app(ExceptionHandler::class)->render(
                Request::create('/', 'POST', server: ['HTTP_ACCEPT' => 'application/json']),
                $excecao
            );

            $this->assertSame(403, $resposta->getStatusCode());
        }

        $baixada->refresh();

        $this->assertSame('paga', $baixada->situacao, 'a parcela foi alterada por um estorno barrado');
        $this->assertSame('500.00', $baixada->valor_pago);
        $this->assertSame(1, FinancialEntry::query()->count(), 'o estorno barrado criou lançamento no caixa');
    }

    public function test_estorno_sem_motivo_de_verdade_e_recusado(): void
    {
        $parcela = $this->parcelaUnica(500.00);

        $baixada = $this->comTenant(fn () => $this->servico->baixar($parcela, [
            'valor' => 500.00,
            'data' => self::DIA_DO_RECEBIMENTO,
            'usuario' => $this->operador,
        ]));

        try {
            $this->comTenant(fn () => $this->servico->estornar($baixada, 'erro', $this->operador));

            $this->fail('o estorno sem motivo precisava ser recusado');
        } catch (ValidationException $excecao) {
            $this->assertArrayHasKey('motivo', $excecao->errors());
        }

        $this->assertSame(1, FinancialEntry::query()->count());
        $this->assertSame('paga', $baixada->refresh()->situacao);
    }

    public function test_estorno_de_baixas_parciais_reverte_tudo_o_que_entrou(): void
    {
        $parcela = $this->parcelaUnica(500.00);

        foreach ([200.00, 150.00] as $valor) {
            $parcela = $this->comTenant(fn () => $this->servico->baixar($parcela, [
                'valor' => $valor,
                'data' => self::DIA_DO_RECEBIMENTO,
                'usuario' => $this->operador,
            ]));
        }

        $estornada = $this->comTenant(fn () => $this->servico->estornar(
            $parcela,
            'Cliente pediu cancelamento e o valor foi devolvido',
            $this->operador
        ));

        $this->assertSame('0.00', $estornada->valor_pago);
        $this->assertSame('aberta', $estornada->situacao);

        $estorno = FinancialEntry::query()->findOrFail($estornada->financial_entry_id);

        $this->assertSame('350.00', $estorno->amount, 'o estorno precisa reverter tudo o que foi recebido');
    }

    // -----------------------------------------------------------------
    // Caixa: saldo diário depois de muitas baixas
    // -----------------------------------------------------------------

    public function test_saldo_diario_bate_com_a_soma_dos_lancamentos_depois_de_vinte_baixas(): void
    {
        $titulo = $this->tituloComParcelas(2000.00, 20);

        $parcelas = $titulo->installments()->orderBy('numero')->get();

        $this->assertCount(20, $parcelas);

        foreach ($parcelas as $parcela) {
            $this->comTenant(fn () => $this->servico->baixar($parcela, [
                'valor' => $parcela->valor,
                'data' => self::DIA_DO_RECEBIMENTO,
                'usuario' => $this->operador,
            ]));
        }

        $lancamentos = FinancialEntry::query()
            ->whereDate('entry_date', self::DIA_DO_RECEBIMENTO)
            ->get();

        $saldo = DailyCashBalance::query()
            ->whereDate('balance_date', self::DIA_DO_RECEBIMENTO)
            ->firstOrFail();

        $this->assertCount(20, $lancamentos, 'cada baixa precisa ter criado exatamente um lançamento');
        $this->assertSame(
            number_format((float) $lancamentos->sum('amount'), 2, '.', ''),
            $saldo->total_entries,
            'o saldo diário divergiu da soma dos lançamentos do dia'
        );
        $this->assertSame('2000.00', $saldo->total_entries);
        $this->assertSame(20, $saldo->entries_count);
        $this->assertSame('2000.00', $saldo->closing_balance);
        $this->assertSame('quitado', $titulo->refresh()->situacao);
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas
    // -----------------------------------------------------------------

    public function test_parcela_de_outra_empresa_nao_e_baixada(): void
    {
        $parcela = $this->parcelaUnica(500.00);

        $outraEmpresa = Company::create([
            'name' => 'Dedetizadora Concorrente',
            'cnpj' => '22.222.222/0001-22',
            'email' => 'contato@concorrente.test',
        ]);

        $this->expectException(ModelNotFoundException::class);

        TenantAtual::comTenant($outraEmpresa->id, fn () => $this->servico->baixar($parcela, [
            'valor' => 500.00,
            'data' => self::DIA_DO_RECEBIMENTO,
            'usuario' => $this->operador,
        ]));
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function comTenant(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->id, $callback);
    }

    /**
     * Título de uma parcela só, no valor informado, vencendo depois de "hoje".
     */
    private function parcelaUnica(float $valor): ReceivableInstallment
    {
        return $this->tituloComParcelas($valor, 1)->installments()->firstOrFail();
    }

    /**
     * Título gerado pelo caminho real (`ReceivableService::gerarDaOs()`), para
     * as parcelas chegarem aqui no mesmo estado em que a Task 18.3 as cria.
     */
    private function tituloComParcelas(float $valor, int $parcelas): Receivable
    {
        $os = WorkOrderFactory::new()->create([
            'final_amount' => $valor,
            'total_cost' => $valor,
            'payment_due_date' => '2026-08-01',
        ]);

        return $this->comTenant(fn () => $this->receivableService->gerarDaOs($os, [
            'parcelas' => $parcelas,
            'primeiro_vencimento' => '2026-08-01',
            'intervalo_meses' => 1,
        ]));
    }
}
