<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\Receivable;
use App\Models\ReceivableInstallment;
use App\Models\WorkOrder;
use App\Services\ReceivableService;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Closure;
use Database\Factories\AddressFactory;
use Database\Factories\WorkOrderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Task 18.3 do Plano 18: geração de título a receber a partir da OS e do
 * contrato.
 *
 * Quatro garantias concentram este arquivo:
 *
 * - A soma das parcelas é sempre exatamente o valor do título, inclusive
 *   quando o valor não divide de forma exata entre elas (1000,00 em 3
 *   parcelas, 100,01 em 3 parcelas).
 * - Geração idempotente nas duas origens: OS que já tem título não gera
 *   outro, e contrato não duplica título da mesma competência.
 * - Vencimento em fim de semana não é movido.
 * - Cancelar um título mantém a parcela paga e cancela as demais.
 *
 * Teste mínimo desta task: a suíte definitiva e completa do módulo financeiro
 * é a Task 18.10, que pode substituir este arquivo.
 */
class ReceivableServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReceivableService $servico;

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->servico = app(ReceivableService::class);
        $this->empresa = Company::query()->firstOrFail();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Geração a partir da OS: arredondamento
    // -----------------------------------------------------------------

    public function test_gerar_da_os_parcelado_divide_o_valor_com_soma_exata_mesmo_sem_divisao_inteira(): void
    {
        $os = $this->criarOs(['final_amount' => 1000.00]);

        $titulo = $this->comTenant(fn () => $this->servico->gerarDaOs($os, [
            'parcelas' => 3,
            'primeiro_vencimento' => '2026-08-10',
            'intervalo_meses' => 1,
        ]));

        $this->assertSame('1000.00', $titulo->valor_total);
        $this->assertSame($os->id, $titulo->work_order_id);

        $parcelas = $titulo->installments()->orderBy('numero')->get();

        $this->assertCount(3, $parcelas);
        $this->assertSame(['333.33', '333.33', '333.34'], $parcelas->pluck('valor')->all());
        $this->assertSame(
            '1000.00',
            number_format((float) $parcelas->sum('valor'), 2, '.', ''),
            'a soma das parcelas precisa ser exatamente o valor do título'
        );
        $this->assertSame(
            ['2026-08-10', '2026-09-10', '2026-10-10'],
            $parcelas->map(fn (ReceivableInstallment $p) => BusinessDate::diaDe($p->vencimento))->all()
        );
    }

    public function test_gerar_da_os_com_valor_que_nao_fecha_exato_tambem_soma_certo(): void
    {
        $os = $this->criarOs(['final_amount' => 100.01]);

        $titulo = $this->comTenant(fn () => $this->servico->gerarDaOs($os, [
            'parcelas' => 3,
            'primeiro_vencimento' => '2026-08-10',
            'intervalo_dias' => 30,
        ]));

        $valores = $titulo->installments()->orderBy('numero')->pluck('valor')->all();

        $this->assertSame(['33.33', '33.33', '33.35'], $valores);
        $this->assertSame(
            '100.01',
            number_format(array_sum(array_map('floatval', $valores)), 2, '.', '')
        );
    }

    public function test_gerar_da_os_com_cem_reais_em_sete_parcelas_soma_exata(): void
    {
        $os = $this->criarOs(['final_amount' => 100.00]);

        $titulo = $this->comTenant(fn () => $this->servico->gerarDaOs($os, [
            'parcelas' => 7,
            'primeiro_vencimento' => '2026-08-10',
            'intervalo_dias' => 30,
        ]));

        $valores = $titulo->installments()->orderBy('numero')->pluck('valor')->all();

        $this->assertSame(
            ['14.28', '14.28', '14.28', '14.28', '14.28', '14.28', '14.32'],
            $valores
        );
        $this->assertSame(
            '100.00',
            number_format(array_sum(array_map('floatval', $valores)), 2, '.', ''),
            'a soma das parcelas precisa ser exatamente o valor do título'
        );
    }

    public function test_gerar_da_os_com_cinco_centavos_em_duas_parcelas_poe_a_sobra_na_ultima(): void
    {
        $os = $this->criarOs(['final_amount' => 0.05]);

        $titulo = $this->comTenant(fn () => $this->servico->gerarDaOs($os, [
            'parcelas' => 2,
            'primeiro_vencimento' => '2026-08-10',
            'intervalo_dias' => 30,
        ]));

        $valores = $titulo->installments()->orderBy('numero')->pluck('valor')->all();

        $this->assertSame(
            ['0.02', '0.03'],
            $valores,
            'o centavo ímpar de sobra precisa ir para a última parcela'
        );
        $this->assertSame(
            '0.05',
            number_format(array_sum(array_map('floatval', $valores)), 2, '.', ''),
            'a soma das parcelas precisa ser exatamente o valor do título'
        );
    }

    public function test_gerar_da_os_a_vista_cria_uma_unica_parcela_com_o_valor_total(): void
    {
        $os = $this->criarOs(['final_amount' => 750.00, 'payment_due_date' => '2026-08-15']);

        $titulo = $this->comTenant(fn () => $this->servico->gerarDaOs($os, [
            'forma' => ReceivableService::FORMA_A_VISTA,
            // Ignorado de propósito: à vista força uma única parcela.
            'parcelas' => 5,
        ]));

        $parcelas = $titulo->installments;

        $this->assertCount(1, $parcelas);
        $this->assertSame('750.00', $parcelas->first()->valor);
        $this->assertSame('2026-08-15', BusinessDate::diaDe($parcelas->first()->vencimento));
    }

    // -----------------------------------------------------------------
    // Geração a partir da OS: idempotência e validação
    // -----------------------------------------------------------------

    public function test_os_que_ja_tem_titulo_nao_gera_outro(): void
    {
        $os = $this->criarOs(['final_amount' => 500.00, 'payment_due_date' => '2026-08-01']);

        $primeiro = $this->comTenant(fn () => $this->servico->gerarDaOs($os, ['parcelas' => 1]));
        $segundo = $this->comTenant(fn () => $this->servico->gerarDaOs($os, [
            'parcelas' => 4,
            'intervalo_dias' => 10,
        ]));

        $this->assertSame($primeiro->id, $segundo->id);
        $this->assertDatabaseCount('receivables', 1);
        $this->assertSame(
            1,
            ReceivableInstallment::query()->count(),
            'a segunda chamada criou parcela nova para uma OS que já tinha título'
        );
    }

    public function test_vencimento_em_fim_de_semana_nao_e_movido(): void
    {
        // 2026-08-08 é sábado e 2026-08-09 é domingo.
        $this->assertSame('Saturday', \Carbon\Carbon::parse('2026-08-08')->format('l'));
        $this->assertSame('Sunday', \Carbon\Carbon::parse('2026-08-09')->format('l'));

        $os = $this->criarOs(['final_amount' => 200.00]);

        $titulo = $this->comTenant(fn () => $this->servico->gerarDaOs($os, [
            'parcelas' => 2,
            'primeiro_vencimento' => '2026-08-08',
            'intervalo_dias' => 1,
        ]));

        $vencimentos = $titulo->installments()
            ->orderBy('numero')
            ->get()
            ->map(fn (ReceivableInstallment $p) => BusinessDate::diaDe($p->vencimento))
            ->all();

        $this->assertSame(['2026-08-08', '2026-08-09'], $vencimentos, 'o vencimento em fim de semana foi movido');
    }

    public function test_gerar_da_os_sem_vencimento_nem_payment_due_date_lanca_excecao(): void
    {
        $os = $this->criarOs(['final_amount' => 300.00, 'payment_due_date' => null]);

        $this->expectException(InvalidArgumentException::class);

        $this->comTenant(fn () => $this->servico->gerarDaOs($os, ['parcelas' => 1]));
    }

    // -----------------------------------------------------------------
    // Geração a partir do contrato
    // -----------------------------------------------------------------

    public function test_gerar_do_contrato_e_idempotente_por_contrato_e_competencia(): void
    {
        $contrato = $this->criarContrato(['service_value' => 300.00]);

        $primeiro = $this->comTenant(fn () => $this->servico->gerarDoContrato($contrato, '2026-08'));
        $segundo = $this->comTenant(fn () => $this->servico->gerarDoContrato($contrato, '2026-08'));

        $this->assertSame($primeiro->id, $segundo->id);
        $this->assertDatabaseCount('receivables', 1);
        $this->assertDatabaseCount('receivable_installments', 1);
    }

    public function test_gerar_do_contrato_em_competencias_diferentes_gera_titulos_diferentes(): void
    {
        $contrato = $this->criarContrato(['service_value' => 300.00]);

        $julho = $this->comTenant(fn () => $this->servico->gerarDoContrato($contrato, '2026-07'));
        $agosto = $this->comTenant(fn () => $this->servico->gerarDoContrato($contrato, '2026-08'));

        $this->assertNotSame($julho->id, $agosto->id);
        $this->assertDatabaseCount('receivables', 2);
    }

    public function test_gerar_do_contrato_usa_o_dia_do_start_date_como_vencimento_preso_ao_fim_do_mes(): void
    {
        $contrato = $this->criarContrato(['service_value' => 150.00, 'start_date' => '2026-05-31']);

        // Fevereiro de 2027 (não bissexto) trava o dia 31 no último dia do mês.
        $titulo = $this->comTenant(fn () => $this->servico->gerarDoContrato($contrato, '2027-02'));

        $parcela = $titulo->installments()->firstOrFail();

        $this->assertSame('2027-02-28', BusinessDate::diaDe($parcela->vencimento));
        $this->assertSame('150.00', $parcela->valor);
    }

    // -----------------------------------------------------------------
    // Cancelamento
    // -----------------------------------------------------------------

    public function test_cancelar_mantem_parcelas_pagas_e_cancela_as_demais(): void
    {
        $os = $this->criarOs(['final_amount' => 300.00]);

        $titulo = $this->comTenant(fn () => $this->servico->gerarDaOs($os, [
            'parcelas' => 3,
            'primeiro_vencimento' => '2026-08-01',
            'intervalo_meses' => 1,
        ]));

        $parcelas = $titulo->installments()->orderBy('numero')->get();
        $parcelas[0]->update([
            'situacao' => 'paga',
            'valor_pago' => $parcelas[0]->valor,
            'pago_em' => '2026-08-01',
        ]);

        $cancelado = $this->comTenant(fn () => $this->servico->cancelar($titulo));

        $this->assertSame('cancelado', $cancelado->situacao);
        $this->assertSame('300.00', $cancelado->valor_total, 'o cancelamento não pode alterar o valor do título');

        $recarregadas = $cancelado->installments()->orderBy('numero')->get();

        $this->assertSame('paga', $recarregadas[0]->situacao, 'a parcela paga foi tocada pelo cancelamento');
        $this->assertSame('100.00', $recarregadas[0]->valor_pago, 'o valor pago foi apagado pelo cancelamento');
        $this->assertSame('2026-08-01', BusinessDate::diaDe($recarregadas[0]->pago_em));
        $this->assertSame('cancelada', $recarregadas[1]->situacao);
        $this->assertSame('cancelada', $recarregadas[2]->situacao);
    }

    public function test_cancelar_um_titulo_ja_cancelado_e_idempotente(): void
    {
        $os = $this->criarOs(['final_amount' => 100.00]);

        $titulo = $this->comTenant(fn () => $this->servico->gerarDaOs($os, ['parcelas' => 1]));

        $this->comTenant(fn () => $this->servico->cancelar($titulo));
        $cancelado = $this->comTenant(fn () => $this->servico->cancelar($titulo->fresh()));

        $this->assertSame('cancelado', $cancelado->situacao);
        $this->assertSame('cancelada', $cancelado->installments()->firstOrFail()->situacao);
    }

    // -----------------------------------------------------------------
    // Rotina mensal: financeiro:gerar-titulos-de-contrato
    // -----------------------------------------------------------------

    public function test_comando_gera_titulo_dos_contratos_vigentes_na_competencia_informada(): void
    {
        $vigente = $this->criarContrato(['service_value' => 200.00]);
        $pontual = $this->criarContrato(['service_value' => 200.00, 'service_type' => 'pontual']);
        $encerrado = $this->criarContrato(['service_value' => 200.00, 'end_date' => '2026-06-30']);

        $codigo = Artisan::call('financeiro:gerar-titulos-de-contrato', ['--competencia' => '2026-08']);

        $this->assertSame(0, $codigo);
        $this->assertDatabaseHas('receivables', ['contract_id' => $vigente->id]);
        $this->assertDatabaseMissing('receivables', ['contract_id' => $pontual->id]);
        $this->assertDatabaseMissing('receivables', ['contract_id' => $encerrado->id]);
    }

    public function test_comando_rodado_duas_vezes_na_mesma_competencia_nao_duplica(): void
    {
        $this->criarContrato(['service_value' => 200.00]);

        Artisan::call('financeiro:gerar-titulos-de-contrato', ['--competencia' => '2026-08']);
        $depoisDaPrimeira = Receivable::query()->count();

        Artisan::call('financeiro:gerar-titulos-de-contrato', ['--competencia' => '2026-08']);

        $this->assertSame(1, $depoisDaPrimeira);
        $this->assertSame($depoisDaPrimeira, Receivable::query()->count());
    }

    public function test_comando_com_competencia_invalida_nao_processa_nada(): void
    {
        $this->criarContrato(['service_value' => 200.00]);

        $codigo = Artisan::call('financeiro:gerar-titulos-de-contrato', ['--competencia' => '13/2026']);

        $this->assertSame(2, $codigo, 'competência inválida precisa devolver INVALID');
        $this->assertDatabaseCount('receivables', 0);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function comTenant(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->id, $callback);
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function criarOs(array $atributos = []): WorkOrder
    {
        return WorkOrderFactory::new()->create(array_merge([
            'payment_due_date' => '2026-08-01',
        ], $atributos));
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function criarContrato(array $atributos = []): Contract
    {
        $endereco = AddressFactory::new()->create();

        return Contract::query()->create(array_merge([
            'address_id' => $endereco->id,
            'contract_number' => 'CONT-' . str_pad((string) $endereco->id, 6, '0', STR_PAD_LEFT),
            'service_type' => 'periodico',
            'service_value' => 100.00,
            'start_date' => '2026-01-10',
            'end_date' => null,
        ], $atributos));
    }
}
