<?php

namespace Tests\Feature;

use App\Console\Commands\Concerns\OperaPorTenant;
use App\Jobs\Concerns\CarregaTenant;
use App\Models\Company;
use App\Support\TenantAtual;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 4.9 do Plano 4: tenant explícito fora de requisição HTTP.
 *
 * Dois contextos, o mesmo problema. Comando artisan e worker de fila rodam sem
 * usuário autenticado, então nenhum dos dois tem empresa por conta própria. O
 * comando recebe a empresa por opção de linha de comando e o job a recebe no
 * payload.
 *
 * O que estes testes protegem, em ordem de gravidade:
 *
 * 1. Job processado no tenant do payload, nunca no que estiver valendo no
 *    worker naquele instante.
 * 2. Job que falha não deixa o tenant dele valendo para o job seguinte do mesmo
 *    processo. Esse é o vazamento silencioso mais fácil de acontecer, porque
 *    `TenantAtual` guarda estado estático e worker é processo longo.
 * 3. Erro em uma empresa não impede as outras de serem processadas na rotina
 *    diária.
 */
class TenantForaDaRequisicaoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Empresas em que o comando de teste rodou, na ordem, com o tenant que
     * estava valendo dentro de cada volta.
     *
     * @var array<int, int>
     */
    public static array $tenantsVistos = [];

    /**
     * Empresas que o comando de teste deve fazer estourar.
     *
     * @var array<int, int>
     */
    public static array $empresasQueFalham = [];

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        self::$tenantsVistos = [];
        self::$empresasQueFalham = [];
        JobDeTeste::$tenantsVistos = [];

        $this->app[Kernel::class]->registerCommand(new ComandoDeTesteDeTenant);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();
        self::$tenantsVistos = [];
        self::$empresasQueFalham = [];
        JobDeTeste::$tenantsVistos = [];

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Comando artisan: qual empresa e quantas
    // -----------------------------------------------------------------

    public function test_comando_com_company_roda_apenas_naquela_empresa(): void
    {
        $segunda = $this->criarSegundaEmpresa();

        $this->artisan('teste:opera-por-tenant', ['--company' => $segunda->id])
            ->assertSuccessful();

        $this->assertSame([$segunda->id], self::$tenantsVistos);
    }

    public function test_comando_sem_opcao_percorre_todas_as_empresas(): void
    {
        $segunda = $this->criarSegundaEmpresa();

        $this->artisan('teste:opera-por-tenant')->assertSuccessful();

        $this->assertSame([1, $segunda->id], self::$tenantsVistos);
    }

    public function test_comando_com_todas_percorre_todas_as_empresas(): void
    {
        $segunda = $this->criarSegundaEmpresa();

        $this->artisan('teste:opera-por-tenant', ['--todas' => true])->assertSuccessful();

        $this->assertSame([1, $segunda->id], self::$tenantsVistos);
    }

    public function test_resumo_traz_uma_linha_por_empresa_com_a_quantidade_processada(): void
    {
        $segunda = $this->criarSegundaEmpresa();
        $processadosDaSegunda = 10 * (int) $segunda->id;

        $this->artisan('teste:opera-por-tenant')
            ->expectsOutputToContain('Resumo por empresa:')
            ->expectsOutputToContain('Empresa #1 (Fundadora): 10 registro(s) processado(s)')
            ->expectsOutputToContain("Empresa #{$segunda->id} (Segunda Dedetizadora): {$processadosDaSegunda} registro(s) processado(s)")
            ->expectsOutputToContain('Empresas processadas: 2')
            ->expectsOutputToContain('Empresas com erro: 0')
            ->assertSuccessful();
    }

    // -----------------------------------------------------------------
    // Comando artisan: opções erradas não processam nada
    // -----------------------------------------------------------------

    public function test_company_e_todas_ao_mesmo_tempo_sao_recusadas(): void
    {
        $this->artisan('teste:opera-por-tenant', ['--company' => 1, '--todas' => true])
            ->assertExitCode(Command::INVALID);

        $this->assertSame([], self::$tenantsVistos);
    }

    public function test_company_inexistente_nao_processa_nada(): void
    {
        $this->artisan('teste:opera-por-tenant', ['--company' => 9999])
            ->expectsOutputToContain('Empresa #9999 não existe. Nada foi processado.')
            ->assertExitCode(Command::INVALID);

        $this->assertSame([], self::$tenantsVistos);
    }

    public function test_company_que_nao_e_id_valido_nao_processa_nada(): void
    {
        $this->artisan('teste:opera-por-tenant', ['--company' => 'primeira'])
            ->assertExitCode(Command::INVALID);

        $this->assertSame([], self::$tenantsVistos);
    }

    // -----------------------------------------------------------------
    // Comando artisan: isolamento de erro e do tenant
    // -----------------------------------------------------------------

    public function test_erro_em_uma_empresa_nao_interrompe_as_demais(): void
    {
        $segunda = $this->criarSegundaEmpresa();
        $terceira = Company::create(['name' => 'Terceira']);
        self::$empresasQueFalham = [$segunda->id];

        $this->artisan('teste:opera-por-tenant')
            ->expectsOutputToContain("Falha em Empresa #{$segunda->id} (Segunda Dedetizadora): falha proposital na empresa {$segunda->id}")
            ->expectsOutputToContain('As demais empresas continuam sendo processadas.')
            ->expectsOutputToContain('Empresas com erro: 1')
            ->assertExitCode(Command::FAILURE);

        // A empresa 2 estourou no meio, e mesmo assim a 3 foi processada.
        $this->assertSame([1, $terceira->id], self::$tenantsVistos);
    }

    public function test_tenant_volta_ao_valor_anterior_depois_de_cada_empresa(): void
    {
        $this->criarSegundaEmpresa();
        self::$empresasQueFalham = [1];

        $this->artisan('teste:opera-por-tenant')->assertExitCode(Command::FAILURE);

        // Nem o sucesso da segunda nem a exceção da primeira deixam tenant
        // fixado depois que o comando termina.
        $this->assertNull(TenantAtual::id());
        $this->assertFalse(TenantAtual::definido());
    }

    public function test_tenant_de_dentro_do_laco_nao_vaza_para_fora_do_comando(): void
    {
        $segunda = $this->criarSegundaEmpresa();

        TenantAtual::comTenant(1, function () use ($segunda): void {
            $this->artisan('teste:opera-por-tenant', ['--company' => $segunda->id])->assertSuccessful();

            // O laço rodou na segunda empresa e devolveu o tenant que encontrou.
            $this->assertSame(1, TenantAtual::id());
            $this->assertSame([$segunda->id], self::$tenantsVistos);
        });
    }

    // -----------------------------------------------------------------
    // Job de fila: o tenant viaja no payload
    // -----------------------------------------------------------------

    public function test_construtor_do_job_captura_o_tenant_corrente(): void
    {
        $job = TenantAtual::comTenant(7, fn () => new JobDeTeste);

        $this->assertSame(7, $job->companyId);
    }

    public function test_despachar_job_sem_tenant_resolvido_falha_no_despacho(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nenhuma empresa resolvida para esta operação.');

        new JobDeTeste;
    }

    public function test_company_id_viaja_no_payload_serializado(): void
    {
        $job = TenantAtual::comTenant(7, fn () => new JobDeTeste);

        $restaurado = unserialize(serialize($job));

        $this->assertSame(7, $restaurado->companyId);
    }

    public function test_job_roda_no_tenant_do_payload_e_nao_no_do_ambiente(): void
    {
        // Enfileirado dentro da empresa 7, como aconteceria numa requisição.
        $job = TenantAtual::comTenant(7, fn () => new JobDeTeste);

        // Consumido com outra empresa valendo no processo, que é o cenário do
        // worker que acabou de processar um job de outro tenant.
        TenantAtual::comTenant(3, fn () => dispatch(unserialize(serialize($job))));

        $this->assertSame([7], JobDeTeste::$tenantsVistos);
    }

    public function test_tenant_volta_ao_valor_anterior_depois_do_job(): void
    {
        $job = TenantAtual::comTenant(7, fn () => new JobDeTeste);

        dispatch($job);

        $this->assertNull(TenantAtual::id());
    }

    // -----------------------------------------------------------------
    // Job de fila: exceção não suja o tenant do job seguinte
    // -----------------------------------------------------------------

    /**
     * O cenário exato do risco registrado na Task 4.6: worker é processo longo,
     * `TenantAtual` guarda estado estático, e um job que estoura no meio não
     * pode deixar a empresa dele valendo para o próximo job da fila.
     *
     * A conexão `sync` da suíte processa os dois jobs no mesmo processo PHP, que
     * é justamente o que reproduz o worker.
     */
    public function test_job_que_lanca_excecao_nao_deixa_tenant_sujo_para_o_proximo_job(): void
    {
        $queFalha = TenantAtual::comTenant(2, fn () => new JobDeTeste(falhar: true));
        $seguinte = TenantAtual::comTenant(5, fn () => new JobDeTeste);

        try {
            dispatch($queFalha);
            $this->fail('A exceção do job deveria ter sido propagada.');
        } catch (RuntimeException $erro) {
            $this->assertSame('falha proposital dentro do job', $erro->getMessage());
        }

        // Entre um job e outro, o processo não carrega tenant nenhum.
        $this->assertNull(TenantAtual::id());

        dispatch($seguinte);

        // O job seguinte rodou no tenant dele, não no do job que quebrou.
        $this->assertSame([2, 5], JobDeTeste::$tenantsVistos);
        $this->assertNull(TenantAtual::id());
    }

    /**
     * Rede de segurança para o job que não usa a trait e chama
     * `TenantAtual::definir()` na mão sem limpar depois. O worker zera o tenant
     * antes de buscar o próximo job, e o evento `Looping` é o gancho disso.
     */
    public function test_looping_da_fila_limpa_tenant_deixado_por_job_desleixado(): void
    {
        TenantAtual::definir(4);
        $this->assertSame(4, TenantAtual::id());

        event(new Looping('database', 'default'));

        $this->assertNull(TenantAtual::id());
    }

    // -----------------------------------------------------------------
    // Job de fila: payload antigo, enfileirado antes deste deploy
    // -----------------------------------------------------------------

    /**
     * Job enfileirado antes deste deploy tem payload sem `companyId`. Ao ser
     * processado depois, a propriedade volta no padrão declarado (`null`) e o
     * job precisa falhar alto: rodar no tenant do ambiente gravaria dado de uma
     * empresa dentro de outra, e isso não tem como desfazer.
     */
    public function test_job_com_payload_antigo_sem_company_id_falha_com_mensagem_explicita(): void
    {
        $job = TenantAtual::comTenant(7, fn () => new JobDeTeste);

        // Payload de antes do deploy: a propriedade nem existe no serializado.
        unset($job->companyId);
        $payload = serialize($job);
        $this->assertStringNotContainsString('companyId', $payload);

        $antigo = unserialize($payload);
        $this->assertNull($antigo->companyId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('foi processado sem company_id no payload');

        TenantAtual::comTenant(3, fn () => dispatch($antigo));
    }

    public function test_job_com_payload_antigo_nao_roda_no_tenant_do_ambiente(): void
    {
        $job = TenantAtual::comTenant(7, fn () => new JobDeTeste);
        unset($job->companyId);
        $antigo = unserialize(serialize($job));

        try {
            TenantAtual::comTenant(3, fn () => dispatch($antigo));
        } catch (RuntimeException) {
            // Esperado.
        }

        $this->assertSame([], JobDeTeste::$tenantsVistos, 'o job rodou mesmo sem empresa no payload');
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function criarSegundaEmpresa(): Company
    {
        // A empresa 1 vem da migration de fundação do tenant, sem nome.
        Company::query()->whereKey(1)->update(['name' => 'Fundadora']);

        return Company::create(['name' => 'Segunda Dedetizadora']);
    }
}

/**
 * Comando de mentira, só para exercitar a trait sem depender das rotinas reais.
 *
 * Registra o tenant que estava valendo dentro de cada volta do laço, que é o
 * que prova que o contexto foi aplicado, e devolve uma quantidade diferente por
 * empresa, para o resumo ter o que mostrar.
 */
class ComandoDeTesteDeTenant extends Command
{
    use OperaPorTenant;

    protected $signature = 'teste:opera-por-tenant';

    protected $description = 'Comando de teste da trait OperaPorTenant.';

    public function handle(): int
    {
        return $this->paraCadaTenant(function (Company $empresa): int {
            if (in_array((int) $empresa->id, TenantForaDaRequisicaoTest::$empresasQueFalham, true)) {
                throw new RuntimeException("falha proposital na empresa {$empresa->id}");
            }

            TenantForaDaRequisicaoTest::$tenantsVistos[] = TenantAtual::exigirId();

            return 10 * (int) $empresa->id;
        });
    }
}

/**
 * Job de mentira no formato que todo job do sistema vai ter: `Queueable` do
 * framework mais `CarregaTenant`, com a captura do tenant no construtor.
 */
class JobDeTeste implements ShouldQueue
{
    use CarregaTenant, Queueable;

    /**
     * Tenant que estava valendo dentro de cada `handle()`, na ordem.
     *
     * @var array<int, int>
     */
    public static array $tenantsVistos = [];

    public function __construct(public bool $falhar = false)
    {
        $this->capturarTenantAtual();
    }

    public function handle(): void
    {
        // Lido antes de qualquer decisão: mesmo o job que falha precisa provar
        // que entrou no tenant certo.
        self::$tenantsVistos[] = TenantAtual::exigirId();

        if ($this->falhar) {
            throw new RuntimeException('falha proposital dentro do job');
        }
    }
}
