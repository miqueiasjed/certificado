<?php

namespace Tests\Feature;

use App\Listeners\RegistraExecucaoAgendada;
use App\Models\PaymentDetail;
use App\Models\ScheduledTaskRun;
use App\Support\RotinasAgendadas;
use Carbon\Carbon;
use Database\Factories\CertificateFactory;
use Database\Factories\PaymentDetailFactory;
use Database\Factories\WorkOrderFactory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Critério de aceitação do Plano 1: registro que vence hoje não pode aparecer
 * vencido às 21h de Brasília.
 *
 * O cenário é sempre produzido pelo relógio fixado com Carbon::setTestNow, nunca
 * pelo fuso da máquina que roda a suíte. Às 00:30 de 26/07 em UTC já é dia 26
 * para o servidor, mas ainda é 21:30 de 25/07 para o cliente. Trocar
 * BusinessDate::hoje() por Carbon::today() nas rotinas derruba estes testes.
 */
class RotinasAgendadasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 00:30 de 26/07 em UTC, que é 21:30 de 25/07 em Brasília.
     */
    private const MOMENTO_VIRADA = '2026-07-26 00:30:00';

    /**
     * Dias no fuso do negócio, relativos ao instante acima.
     */
    private const HOJE = '2026-07-25';

    private const ONTEM = '2026-07-24';

    private const AMANHA = '2026-07-26';

    private const FUTURO = '2026-07-30';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::MOMENTO_VIRADA, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Certificados: virada de dia
    // -----------------------------------------------------------------

    /**
     * Teste central do plano. Em UTC já é dia 26 e a garantia é do dia 25, então
     * uma comparação feita em UTC declararia o certificado vencido. No fuso do
     * negócio ainda é dia 25 e a garantia vence hoje, o que não é estar vencido.
     */
    public function test_certificado_que_vence_hoje_continua_ativo_as_21h30_de_brasilia(): void
    {
        // Guarda do próprio teste: sem esta divergência o cenário não exercita
        // a virada de dia e o teste passaria mesmo com o bug de volta.
        $this->assertSame(self::AMANHA, Carbon::now('UTC')->toDateString());

        $certificado = CertificateFactory::new()->create([
            'warranty' => self::HOJE,
            'status' => 'active',
        ]);

        $this->artisan('certificates:update-status')->assertSuccessful();

        $this->assertSame('active', $certificado->fresh()->status);
    }

    public function test_certificado_que_venceu_ontem_vira_vencido(): void
    {
        $certificado = CertificateFactory::new()->create([
            'warranty' => self::ONTEM,
            'status' => 'active',
        ]);

        $this->artisan('certificates:update-status')->assertSuccessful();

        $this->assertSame('expired', $certificado->fresh()->status);
    }

    /**
     * O certificado que a rotina anterior marcou como vencido por comparar em
     * UTC volta a ficar ativo, porque a garantia é de hoje.
     */
    public function test_certificado_marcado_como_vencido_por_engano_volta_a_ficar_ativo(): void
    {
        $certificado = CertificateFactory::new()->create([
            'warranty' => self::HOJE,
            'status' => 'expired',
        ]);

        $this->artisan('certificates:update-status')->assertSuccessful();

        $this->assertSame('active', $certificado->fresh()->status);
    }

    public function test_certificado_cancelado_nao_e_tocado_pela_rotina(): void
    {
        $cancelado = CertificateFactory::new()->cancelado()->create([
            'warranty' => self::ONTEM,
        ]);

        $this->artisan('certificates:update-status')->assertSuccessful();

        $this->assertSame('cancelled', $cancelado->fresh()->status);
    }

    public function test_certificado_sem_garantia_fica_ativo(): void
    {
        $certificado = CertificateFactory::new()->semGarantia()->create([
            'status' => 'expired',
        ]);

        $this->artisan('certificates:update-status')->assertSuccessful();

        $this->assertSame('active', $certificado->fresh()->status);
    }

    public function test_certificado_com_garantia_futura_fica_ativo(): void
    {
        $certificado = CertificateFactory::new()->create([
            'warranty' => self::FUTURO,
            'status' => 'expired',
        ]);

        $this->artisan('certificates:update-status')->assertSuccessful();

        $this->assertSame('active', $certificado->fresh()->status);
    }

    public function test_rotina_de_certificados_em_dry_run_nao_altera_nenhum_registro(): void
    {
        $vencido = CertificateFactory::new()->create([
            'warranty' => self::ONTEM,
            'status' => 'active',
        ]);

        $ativo = CertificateFactory::new()->create([
            'warranty' => self::HOJE,
            'status' => 'expired',
        ]);

        $this->artisan('certificates:update-status', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('active', $vencido->fresh()->status, 'a simulação gravou o certificado vencido');
        $this->assertSame('expired', $ativo->fresh()->status, 'a simulação gravou o certificado ativo');
    }

    // -----------------------------------------------------------------
    // Certificados: accessor do model concorda com a rotina (Task 1.16)
    // -----------------------------------------------------------------

    /**
     * A tela lê o accessor e a rotina grava a coluna. Se os dois usarem fusos
     * diferentes, o usuário vê "Vencido" no mesmo registro que o banco guarda
     * como ativo.
     */
    public function test_accessor_do_certificado_concorda_com_a_rotina_na_virada_do_dia(): void
    {
        $certificado = CertificateFactory::new()->create([
            'warranty' => self::HOJE,
            'status' => 'active',
        ]);

        $this->assertFalse($certificado->is_expired);
        $this->assertSame('active', $certificado->calculated_status);

        $this->artisan('certificates:update-status')->assertSuccessful();

        $recarregado = $certificado->fresh();

        $this->assertSame($recarregado->status, $recarregado->calculated_status);
    }

    public function test_accessor_do_certificado_marca_como_vencido_o_que_venceu_ontem(): void
    {
        $certificado = CertificateFactory::new()->create([
            'warranty' => self::ONTEM,
            'status' => 'active',
        ]);

        $this->assertTrue($certificado->is_expired);
        $this->assertSame('expired', $certificado->calculated_status);
    }

    public function test_certificado_cancelado_nunca_e_reportado_como_vencido_pelo_accessor(): void
    {
        $certificado = CertificateFactory::new()->cancelado()->create([
            'warranty' => self::ONTEM,
        ]);

        $this->assertFalse($certificado->is_expired);
        $this->assertSame('cancelled', $certificado->calculated_status);
    }

    public function test_dias_ate_a_garantia_sao_contados_de_dia_para_dia_no_fuso_do_negocio(): void
    {
        $hoje = CertificateFactory::new()->create(['warranty' => self::HOJE]);
        $amanha = CertificateFactory::new()->create(['warranty' => self::AMANHA]);
        $ontem = CertificateFactory::new()->create(['warranty' => self::ONTEM]);
        $semGarantia = CertificateFactory::new()->semGarantia()->create();

        $this->assertSame(0, $hoje->days_until_expiry, 'garantia que vence hoje deve devolver 0');
        $this->assertSame(1, $amanha->days_until_expiry);
        $this->assertSame(-1, $ontem->days_until_expiry);
        $this->assertSame(0, $semGarantia->days_until_expiry, 'sem garantia deve devolver 0');
    }

    // -----------------------------------------------------------------
    // Pagamentos: virada de dia
    // -----------------------------------------------------------------

    public function test_parcela_que_vence_hoje_nao_fica_vencida_as_21h30_de_brasilia(): void
    {
        $parcela = $this->criarParcela(['payment_due_date' => self::HOJE]);

        $this->artisan('payments:update-statuses')->assertSuccessful();

        $this->assertSame('pending', $this->statusGravado($parcela));
    }

    public function test_parcela_que_venceu_ontem_fica_vencida(): void
    {
        $parcela = $this->criarParcela(['payment_due_date' => self::ONTEM]);

        $this->artisan('payments:update-statuses')->assertSuccessful();

        $this->assertSame('overdue', $this->statusGravado($parcela));
    }

    public function test_ordem_de_servico_com_parcela_que_vence_hoje_nao_fica_vencida(): void
    {
        $parcela = $this->criarParcela(['payment_due_date' => self::HOJE]);

        $this->artisan('payments:update-statuses')->assertSuccessful();

        $this->assertSame('pending', $parcela->workOrder->fresh()->getRawOriginal('payment_status'));
    }

    public function test_ordem_de_servico_com_parcela_vencida_ontem_fica_vencida(): void
    {
        $parcela = $this->criarParcela(['payment_due_date' => self::ONTEM]);

        $this->artisan('payments:update-statuses')->assertSuccessful();

        $this->assertSame('overdue', $parcela->workOrder->fresh()->getRawOriginal('payment_status'));
    }

    public function test_rotina_de_pagamentos_em_dry_run_nao_altera_nenhum_registro(): void
    {
        $parcela = $this->criarParcela(['payment_due_date' => self::ONTEM]);

        $this->artisan('payments:update-statuses', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($this->statusGravado($parcela), 'a simulação gravou o status da parcela');
        $this->assertSame(
            'pending',
            $parcela->workOrder->fresh()->getRawOriginal('payment_status'),
            'a simulação gravou o status da ordem de serviço'
        );
    }

    // -----------------------------------------------------------------
    // Pagamentos: o vencimento decide o atraso (Task 1.17)
    // -----------------------------------------------------------------

    /**
     * Parcela em aberto tem payment_date nula por definição. Decidir o atraso
     * por esse campo marcava a carteira inteira como vencida.
     */
    public function test_parcela_sem_pagamento_com_vencimento_futuro_e_pendente_e_nunca_vencida(): void
    {
        $parcela = $this->criarParcela(['payment_due_date' => self::FUTURO]);

        $this->artisan('payments:update-statuses')->assertSuccessful();

        $this->assertSame('pending', $this->statusGravado($parcela));
        $this->assertNotSame('overdue', $this->statusGravado($parcela));
    }

    public function test_parcela_sem_vencimento_definido_e_pendente(): void
    {
        $parcela = $this->criarParcela(['payment_due_date' => null]);

        $this->artisan('payments:update-statuses')->assertSuccessful();

        $this->assertSame('pending', $this->statusGravado($parcela));
    }

    // -----------------------------------------------------------------
    // Pagamentos: valor sem data de pagamento não é recebimento (Task 1.18)
    // -----------------------------------------------------------------

    /**
     * O sistema cria a parcela do valor restante já com amount_paid preenchido e
     * sem data de pagamento. Classificar por valor declarava recebido dinheiro
     * que não entrou. Este é o teste que protege dinheiro.
     */
    public function test_valor_pago_sem_data_de_pagamento_nao_vira_recebimento_e_segue_o_vencimento(): void
    {
        $noPrazo = $this->criarParcela([
            'payment_due_date' => self::FUTURO,
            'payment_date' => null,
            'amount_paid' => 500.00,
        ]);

        $atrasada = $this->criarParcela([
            'payment_due_date' => self::ONTEM,
            'payment_date' => null,
            'amount_paid' => 500.00,
        ]);

        $parcial = $this->criarParcela([
            'payment_due_date' => self::FUTURO,
            'payment_date' => null,
            'amount_paid' => 200.00,
        ]);

        $this->artisan('payments:update-statuses')->assertSuccessful();

        foreach (['no prazo' => $noPrazo, 'atrasada' => $atrasada, 'parcial' => $parcial] as $rotulo => $parcela) {
            $status = $this->statusGravado($parcela);

            $this->assertNotSame('paid', $status, "parcela {$rotulo} foi declarada paga sem data de pagamento");
            $this->assertNotSame('partial', $status, "parcela {$rotulo} foi declarada parcial sem data de pagamento");
        }

        $this->assertSame('pending', $this->statusGravado($noPrazo));
        $this->assertSame('overdue', $this->statusGravado($atrasada));
        $this->assertSame('pending', $this->statusGravado($parcial));
    }

    /**
     * A ordem também não pode contar como recebido o valor sem data de
     * pagamento: ela soma apenas parcelas com payment_date preenchida.
     */
    public function test_ordem_nao_conta_como_recebido_o_valor_sem_data_de_pagamento(): void
    {
        $parcela = $this->criarParcela([
            'payment_due_date' => self::ONTEM,
            'payment_date' => null,
            'amount_paid' => 500.00,
        ]);

        $this->artisan('payments:update-statuses')->assertSuccessful();

        $ordem = $parcela->workOrder->fresh();

        $this->assertSame('overdue', $ordem->getRawOriginal('payment_status'));
    }

    public function test_parcela_com_data_de_pagamento_e_valor_integral_e_paga(): void
    {
        $parcela = $this->criarParcela([
            'payment_due_date' => self::HOJE,
            'payment_date' => self::ONTEM,
            'amount_paid' => 500.00,
        ]);

        $this->artisan('payments:update-statuses')->assertSuccessful();

        $this->assertSame('paid', $this->statusGravado($parcela));
    }

    public function test_parcela_com_data_de_pagamento_e_valor_menor_e_parcial(): void
    {
        $parcela = $this->criarParcela([
            'payment_due_date' => self::HOJE,
            'payment_date' => self::ONTEM,
            'amount_paid' => 200.00,
        ]);

        $this->artisan('payments:update-statuses')->assertSuccessful();

        $this->assertSame('partial', $this->statusGravado($parcela));
    }

    // -----------------------------------------------------------------
    // Acordo entre a rotina e o accessor do model
    // -----------------------------------------------------------------

    /**
     * Com a coluna payment_status nula, o accessor cai no ramo calculado, que é
     * o estado das parcelas antigas em produção. Para o mesmo registro, o que a
     * tela mostra e o que a rotina grava precisam ser a mesma palavra.
     *
     * Os cenários cobertos são os de parcela em aberto, que é onde nasceram os
     * bugs das tasks 1.17 e 1.18, mais o recebimento integral.
     */
    public function test_status_calculado_pelo_model_coincide_com_o_status_gravado_pela_rotina(): void
    {
        $cenarios = [
            'em aberto, vence no futuro' => ['payment_due_date' => self::FUTURO],
            'em aberto, vence hoje' => ['payment_due_date' => self::HOJE],
            'em aberto, venceu ontem' => ['payment_due_date' => self::ONTEM],
            'em aberto, sem vencimento' => ['payment_due_date' => null],
            'com valor e sem data de pagamento, vence hoje' => [
                'payment_due_date' => self::HOJE,
                'amount_paid' => 500.00,
            ],
            'com valor e sem data de pagamento, venceu ontem' => [
                'payment_due_date' => self::ONTEM,
                'amount_paid' => 500.00,
            ],
            'recebida integralmente' => [
                'payment_due_date' => self::HOJE,
                'payment_date' => self::ONTEM,
                'amount_paid' => 500.00,
            ],
            // Este cenário faltava, e foi por isso que a suíte não pegou o
            // accessor devolvendo 'paid' para parcela recebida pela metade.
            'recebida parcialmente' => [
                'payment_due_date' => self::HOJE,
                'payment_date' => self::ONTEM,
                'amount_paid' => 200.00,
            ],
            'com data de pagamento e valor zerado' => [
                'payment_due_date' => self::ONTEM,
                'payment_date' => self::ONTEM,
                'amount_paid' => 0.00,
            ],
        ];

        $parcelas = [];
        $calculados = [];

        foreach ($cenarios as $rotulo => $atributos) {
            $parcela = $this->criarParcela($atributos);

            $this->assertNull(
                $parcela->getRawOriginal('payment_status'),
                "o cenário '{$rotulo}' precisa nascer com a coluna nula para forçar o ramo calculado"
            );

            $parcelas[$rotulo] = $parcela;
            $calculados[$rotulo] = $parcela->payment_status;
        }

        $this->artisan('payments:update-statuses')->assertSuccessful();

        foreach ($parcelas as $rotulo => $parcela) {
            $this->assertSame(
                $calculados[$rotulo],
                $this->statusGravado($parcela),
                "a rotina e o accessor discordam no cenário '{$rotulo}'"
            );
        }
    }

    // -----------------------------------------------------------------
    // Agendamento e registro das rodadas
    // -----------------------------------------------------------------

    public function test_as_rotinas_diarias_estao_agendadas_no_fuso_do_negocio(): void
    {
        // O agendamento de bootstrap/app.php é aplicado quando o console sobe,
        // e não no boot da aplicação. Rodar schedule:list é o que popula o
        // Schedule, que é um singleton e sobrevive à chamada.
        $this->artisan('schedule:list')->assertSuccessful();

        $eventos = collect($this->app->make(Schedule::class)->events());

        $this->assertCount(15, RotinasAgendadas::DIARIAS, 'a lista de rotinas diárias mudou de tamanho');

        foreach (RotinasAgendadas::DIARIAS as $assinatura => $horario) {
            $agendados = $eventos->filter(
                fn ($evento): bool => is_string($evento->command)
                    && str_contains($evento->command, $assinatura)
            );

            $this->assertCount(1, $agendados, "a rotina {$assinatura} não está agendada exatamente uma vez");

            $evento = $agendados->first();

            $this->assertSame(
                config('app.business_timezone'),
                $evento->timezone,
                "a rotina {$assinatura} não está no fuso do negócio"
            );
            $this->assertSame('America/Sao_Paulo', $evento->timezone);

            [$hora, $minuto] = array_map('intval', explode(':', $horario));
            $campos = explode(' ', $evento->expression);

            $this->assertSame($minuto, (int) $campos[0], "o minuto agendado da rotina {$assinatura} divergiu");
            $this->assertSame($hora, (int) $campos[1], "a hora agendada da rotina {$assinatura} divergiu");
        }
    }

    /**
     * O agendamento chama o comando em um processo separado, e é o gancho
     * before/then, executado neste processo, que grava a auditoria. O comando
     * filho herda DB_DATABASE do phpunit.xml, portanto roda no banco de teste.
     */
    public function test_rodar_uma_rotina_agendada_registra_a_execucao_com_sucesso(): void
    {
        $this->assertSame(0, ScheduledTaskRun::query()->count());

        $this->artisan('schedule:test', ['--name' => 'certificates:update-status'])->assertSuccessful();

        $rodada = ScheduledTaskRun::query()->daTarefa('certificates:update-status')->first();

        $this->assertNotNull($rodada, 'a rodada agendada não foi registrada em scheduled_task_runs');
        $this->assertSame(RegistraExecucaoAgendada::STATUS_SUCESSO, $rodada->status);
        $this->assertNotNull($rodada->started_at);
        $this->assertNotNull($rodada->finished_at);
        $this->assertNotNull($rodada->duration_ms);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Cria a parcela junto com a ordem de serviço dona dela. A rotina ignora
     * parcela órfã, então o vínculo é obrigatório no cenário.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function criarParcela(array $atributos = []): PaymentDetail
    {
        $ordem = WorkOrderFactory::new()->create([
            'total_cost' => 500.00,
            'final_amount' => 500.00,
            'payment_status' => 'pending',
        ]);

        return PaymentDetailFactory::new()->create($atributos + ['work_order_id' => $ordem->id]);
    }

    /**
     * Valor cru da coluna payment_status, sem passar pelo accessor.
     */
    private function statusGravado(PaymentDetail $parcela): ?string
    {
        return $parcela->fresh()->getRawOriginal('payment_status');
    }
}
