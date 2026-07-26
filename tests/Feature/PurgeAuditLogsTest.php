<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 3.8: expurgo de auditoria agendado.
 *
 * O corte de retenção usa o dia de hoje no fuso do negócio (BusinessDate::
 * hoje()), mas created_at é gravado em UTC (ver Auditavel::class). Fixar o
 * relógio na virada de fuso, como em RotinasAgendadasTest, é o que pegaria
 * o bug de comparar o corte direto em UTC sem converter: às 00:30 de 26/07
 * em UTC já é outro dia no servidor, mas ainda é 25/07 às 21h30 em Brasília.
 */
class PurgeAuditLogsTest extends TestCase
{
    use RefreshDatabase;

    private const MOMENTO_ATUAL = '2026-07-26 00:30:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::MOMENTO_ATUAL, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_dry_run_conta_sem_apagar_nada(): void
    {
        $this->inserirAuditLog('2020-01-01 00:00:00');
        $this->inserirAccessLog('2020-01-01 00:00:00');

        $this->artisan('auditoria:purge', ['--dry-run' => true, '--dias' => 5])
            ->assertSuccessful();

        $this->assertSame(1, DB::table('audit_logs')->count());
        $this->assertSame(1, DB::table('access_logs')->count());
    }

    /**
     * Com --dias=5, hoje é 25/07 no fuso do negócio, e o corte é 20/07 às
     * 00:00 em Brasília, ou seja, 20/07 às 03:00 em UTC. O registro
     * exatamente no corte não é anterior a ele e por isso sobrevive: só o
     * que é estritamente mais antigo é removido. Se o comando comparasse o
     * corte sem converter para UTC, a fronteira cairia 3 horas mais cedo e
     * este teste pegaria a diferença.
     */
    public function test_expurgo_respeita_a_fronteira_exata_do_corte_convertida_para_utc(): void
    {
        $recente = $this->inserirAuditLog('2026-07-25 00:00:00');
        $noCorteExato = $this->inserirAuditLog('2026-07-20 03:00:00');
        $umSegundoAntesDoCorte = $this->inserirAuditLog('2026-07-20 02:59:59');
        $bemAntigo = $this->inserirAuditLog('2020-01-01 00:00:00');

        $this->artisan('auditoria:purge', ['--dias' => 5])->assertSuccessful();

        $restantes = DB::table('audit_logs')->pluck('id')->all();

        $this->assertContains($recente, $restantes, 'registro recente não deveria ter sido apagado');
        $this->assertContains($noCorteExato, $restantes, 'registro exatamente no corte não deveria ter sido apagado');
        $this->assertNotContains($umSegundoAntesDoCorte, $restantes, 'registro um segundo antes do corte deveria ter sido apagado');
        $this->assertNotContains($bemAntigo, $restantes, 'registro antigo deveria ter sido apagado');
    }

    public function test_expurgo_apaga_das_duas_tabelas(): void
    {
        $this->inserirAuditLog('2020-01-01 00:00:00');
        $this->inserirAccessLog('2020-01-01 00:00:00');

        $this->artisan('auditoria:purge', ['--dias' => 5])->assertSuccessful();

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->assertSame(0, DB::table('access_logs')->count());
    }

    public function test_rodar_duas_vezes_no_mesmo_dia_so_apaga_na_primeira(): void
    {
        $this->inserirAuditLog('2020-01-01 00:00:00');

        $this->artisan('auditoria:purge', ['--dias' => 5])->assertSuccessful();
        $this->assertSame(0, DB::table('audit_logs')->count());

        // Segunda rodada: nada elegível sobrou, então zero remoções, sem erro.
        $this->artisan('auditoria:purge', ['--dias' => 5])->assertSuccessful();
        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_dias_zero_apaga_tudo_que_ja_existia(): void
    {
        $this->inserirAuditLog('2026-07-24 23:59:59');

        $this->artisan('auditoria:purge', ['--dias' => 0])->assertSuccessful();

        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_sem_dias_informado_usa_a_retencao_configurada(): void
    {
        config(['auditoria.dias_de_retencao' => 5]);

        $antigo = $this->inserirAuditLog('2020-01-01 00:00:00');
        $recente = $this->inserirAuditLog('2026-07-25 00:00:00');

        $this->artisan('auditoria:purge')->assertSuccessful();

        $restantes = DB::table('audit_logs')->pluck('id')->all();

        $this->assertNotContains($antigo, $restantes);
        $this->assertContains($recente, $restantes);
    }

    public function test_dias_negativo_recusa_a_execucao_sem_apagar_nada(): void
    {
        $this->inserirAuditLog('2020-01-01 00:00:00');

        $this->artisan('auditoria:purge', ['--dias' => -1])->assertFailed();

        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    /**
     * Exercita o laço de exclusão em lotes de verdade: mais de mil linhas
     * elegíveis, removidas em mais de uma iteração de 1.000.
     */
    public function test_expurgo_apaga_mais_de_mil_linhas_em_lotes(): void
    {
        $linhas = [];

        for ($i = 0; $i < 1500; $i++) {
            $linhas[] = [
                'auditable_type' => 'App\\Models\\Client',
                'auditable_id' => $i + 1,
                'acao' => 'criado',
                'created_at' => '2020-01-01 00:00:00',
            ];
        }

        DB::table('audit_logs')->insert($linhas);

        $this->artisan('auditoria:purge', ['--dias' => 5])->assertSuccessful();

        $this->assertSame(0, DB::table('audit_logs')->count());
    }

    public function test_routines_status_lista_a_rotina_de_expurgo_de_auditoria(): void
    {
        Artisan::call('routines:status');

        $this->assertStringContainsString('auditoria:purge', Artisan::output());
    }

    private function inserirAuditLog(string $criadoEm): int
    {
        return DB::table('audit_logs')->insertGetId([
            'auditable_type' => 'App\\Models\\Client',
            'auditable_id' => 1,
            'acao' => 'criado',
            'created_at' => $criadoEm,
        ]);
    }

    private function inserirAccessLog(string $criadoEm): int
    {
        return DB::table('access_logs')->insertGetId([
            'email' => 'teste@example.com',
            'evento' => 'login',
            'created_at' => $criadoEm,
        ]);
    }
}
