<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Plan;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\LimitesDoPlanoService;
use App\Services\TenantService;
use App\Support\TenantAtual;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 6.9 do Plano 6: os seis casos de `LimitesDoPlanoService` (Task 6.5).
 *
 * A regra central, repetida no docblock da classe testada e repetida aqui
 * porque é fácil de esquecer: estourar limite nunca apaga nem esconde dado.
 * Cliente e OS acima do teto continuam sendo criados; só `usuarios` tem
 * recusa dura, porque dar acesso a mais uma pessoa é ato administrativo
 * consciente, diferente do fluxo de trabalho diário.
 */
class LimitesDoPlanoTest extends TestCase
{
    use RefreshDatabase;

    private LimitesDoPlanoService $limitesDoPlanoService;

    private TenantService $tenantService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // Task 6.2 vincula o tenant fundador (id 1) ao Plano Interno, usado
        // pelo Caso 5. Os demais tenants desta suíte nascem à parte, com
        // planos comerciais próprios.
        $this->seed(ModulesSeeder::class);

        $this->limitesDoPlanoService = app(LimitesDoPlanoService::class);
        $this->tenantService = app(TenantService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Caso 1: ok, proximo (a partir de 80%) e estourado
    // -----------------------------------------------------------------

    public function test_situacao_devolve_ok_proximo_e_estourado_corretamente(): void
    {
        $plano = $this->criarPlano('Plano Dez Clientes', limiteClientes: 10);
        [$empresa] = $this->criarTenant('Tenant Situacao', 'admin-situacao@exemplo.test', $plano);

        // 5 de 10: 50%, estado ok.
        $this->criarClientes($empresa, 5);
        $situacao = $this->limitesDoPlanoService->situacao($empresa->fresh());
        $this->assertSame('ok', $situacao['clientes']['estado']);
        $this->assertSame(50, $situacao['clientes']['percentual']);

        // Mais 3, total 8 de 10: 80%, vira proximo, o piso exato da faixa.
        $this->criarClientes($empresa, 3);
        $situacao = $this->limitesDoPlanoService->situacao($empresa->fresh());
        $this->assertSame('proximo', $situacao['clientes']['estado']);
        $this->assertSame(80, $situacao['clientes']['percentual']);

        // Mais 3, total 11 de 10: 110%, estourado.
        $this->criarClientes($empresa, 3);
        $situacao = $this->limitesDoPlanoService->situacao($empresa->fresh());
        $this->assertSame('estourado', $situacao['clientes']['estado']);
        $this->assertSame(110, $situacao['clientes']['percentual']);
        $this->assertSame(11, $situacao['clientes']['atual']);
    }

    // -----------------------------------------------------------------
    // Caso 2: limite nulo é ilimitado, sem aviso
    // -----------------------------------------------------------------

    public function test_limite_nulo_e_ilimitado_sem_aviso(): void
    {
        $plano = $this->criarPlano('Plano Sem Nenhum Limite');
        [$empresa] = $this->criarTenant('Tenant Sem Limite', 'admin-sem-limite@exemplo.test', $plano);

        // Volume alto de propósito: mesmo bem acima de qualquer teto comum,
        // teto nulo não gera estado diferente de ok.
        $this->criarClientes($empresa, 50);

        $situacao = $this->limitesDoPlanoService->situacao($empresa->fresh());

        foreach ($situacao as $recurso => $dados) {
            $this->assertSame('ok', $dados['estado'], "o recurso '{$recurso}' deveria ser ok com limite nulo");
            $this->assertNull($dados['teto'], "o recurso '{$recurso}' deveria ter teto nulo");
            $this->assertNull($dados['percentual'], "o recurso '{$recurso}' deveria ter percentual nulo");
        }

        $this->assertSame(
            [],
            $this->limitesDoPlanoService->avisos($empresa->fresh()),
            'plano sem limite nenhum não deveria gerar aviso algum'
        );
    }

    // -----------------------------------------------------------------
    // Caso 3: criar cliente acima do teto funciona e gera aviso
    // -----------------------------------------------------------------

    public function test_criar_cliente_acima_do_teto_funciona_e_gera_aviso(): void
    {
        $plano = $this->criarPlano('Plano Um Cliente', limiteClientes: 1);
        [$empresa, $administrador] = $this->criarTenant('Tenant Cliente Estourado', 'admin-cliente-estourado@exemplo.test', $plano);

        foreach ([1, 2] as $numero) {
            $resposta = $this->actingAs($administrador)->post('/clients', [
                'name' => "Cliente {$numero}",
                'email' => "cliente-estourado-{$numero}@exemplo.test",
                'phone' => '11999990000',
                'cnpj' => sprintf('%014d', $numero),
            ]);

            $resposta->assertSessionHasNoErrors();
        }

        $quantidade = TenantAtual::comTenant($empresa->id, fn () => Client::query()->count());

        $this->assertSame(
            2,
            $quantidade,
            'os dois clientes deveriam ter sido criados normalmente, mesmo o segundo estourando o teto do plano'
        );

        $avisos = $this->limitesDoPlanoService->avisos($empresa->fresh());

        $this->assertNotEmpty($avisos, 'deveria existir ao menos um aviso depois de estourar o teto de clientes');
        $this->assertTrue(
            collect($avisos)->contains(fn (string $aviso): bool => str_contains($aviso, 'clientes')),
            'o aviso deveria mencionar o limite de clientes'
        );
    }

    // -----------------------------------------------------------------
    // Caso 4: criar usuário acima do teto é recusado com mensagem
    // -----------------------------------------------------------------

    public function test_criar_usuario_acima_do_teto_e_recusado_com_mensagem(): void
    {
        $plano = $this->criarPlano('Plano Um Usuario', limiteUsuarios: 1);

        // O próprio administrador criado por `criarTenant()` já ocupa a
        // única vaga do plano.
        [$empresa, $administrador] = $this->criarTenant('Tenant Usuario Estourado', 'admin-usuario-estourado@exemplo.test', $plano);

        $resposta = $this->actingAs($administrador)->post('/settings/users', [
            'name' => 'Segundo usuário',
            'email' => 'segundo-usuario-estourado@exemplo.test',
            'password' => 'senha12345',
            'role' => 'leitura',
        ]);

        $resposta->assertSessionHasErrors('error');

        $quantidade = TenantAtual::comTenant($empresa->id, fn () => User::query()->count());

        $this->assertSame(
            1,
            $quantidade,
            'nenhum usuário novo deveria ter sido criado acima do teto do plano'
        );
    }

    // -----------------------------------------------------------------
    // Caso 5: tenant interno não recebe aviso algum
    // -----------------------------------------------------------------

    public function test_tenant_interno_nao_recebe_aviso_algum(): void
    {
        // O tenant fundador (id 1) nasce vinculado ao Plano Interno pelo
        // `ModulesSeeder`, com os quatro limites nulos (Task 6.2). Não é por
        // checar `is_internal` que ele fica sem aviso: é porque o próprio
        // plano dele nasce sem teto nenhum, o mesmo caminho do Caso 2.
        $empresa = Company::query()->firstOrFail();

        TenantAtual::comTenant($empresa->id, function (): void {
            for ($numero = 1; $numero <= 20; $numero++) {
                Client::create([
                    'name' => "Cliente interno {$numero}",
                    'email' => "cliente-interno-{$numero}@exemplo.test",
                    'phone' => '11999990000',
                    'cnpj' => sprintf('%014d', $numero),
                ]);
            }
        });

        $avisos = $this->limitesDoPlanoService->avisos($empresa->fresh());

        $this->assertSame([], $avisos, 'o tenant interno não deveria receber aviso de limite algum');
    }

    // -----------------------------------------------------------------
    // Caso 6: OS do mês conta pelo mês no fuso do negócio, na virada
    // -----------------------------------------------------------------

    /**
     * 23h50 do último dia do mês em Brasília já é 1º do mês seguinte em UTC
     * (America/Sao_Paulo é UTC-3 o ano inteiro, sem horário de verão desde
     * 2019): 2026-01-31 23:50 -03:00 = 2026-02-01 02:50 UTC. Se a contagem
     * comparasse `created_at` contra o calendário UTC, esta OS cairia em
     * fevereiro; `TenantUsageService::limitesDoMes()` converte a virada do
     * mês a partir do fuso do negócio antes de comparar, e é isso que este
     * teste prende.
     */
    public function test_os_do_mes_conta_pelo_mes_no_fuso_do_negocio_na_virada(): void
    {
        $plano = $this->criarPlano('Plano Dez OS Por Mes', limiteOsMes: 10);
        [$empresa] = $this->criarTenant('Tenant Virada De Mes', 'admin-virada-de-mes@exemplo.test', $plano);

        $instanteDaVirada = Carbon::create(2026, 1, 31, 23, 50, 0, 'America/Sao_Paulo');
        Carbon::setTestNow($instanteDaVirada);

        // Pré-condição do próprio cenário: o instante congelado precisa cair
        // em janeiro no fuso do negócio, mesmo já sendo fevereiro em UTC.
        $this->assertSame('2026-01', \App\Support\BusinessDate::agora()->format('Y-m'));
        $this->assertSame('2026-02-01', $instanteDaVirada->clone()->utc()->toDateString());

        TenantAtual::comTenant($empresa->id, fn () => WorkOrder::factory()->create());

        $situacao = $this->limitesDoPlanoService->situacao($empresa->fresh());

        $this->assertSame(
            1,
            $situacao['os_mes']['atual'],
            'a OS criada às 23h50 do dia 31/01 em Brasília deveria contar no mês de janeiro, não no de fevereiro'
        );
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function criarPlano(
        string $nome,
        ?int $limiteUsuarios = null,
        ?int $limiteClientes = null,
        ?int $limiteOsMes = null,
        ?int $limiteArmazenamentoMb = null
    ): Plan {
        return Plan::create([
            'nome' => $nome,
            'slug' => Str::slug($nome),
            'valor' => 100,
            'periodicidade' => 'mensal',
            'limite_usuarios' => $limiteUsuarios,
            'limite_clientes' => $limiteClientes,
            'limite_os_mes' => $limiteOsMes,
            'limite_armazenamento_mb' => $limiteArmazenamentoMb,
            'ativo' => true,
        ]);
    }

    /**
     * Tenant novo, com administrador, no plano informado (ou sem plano
     * nenhum quando `null`).
     *
     * @return array{0: Company, 1: User}
     */
    private function criarTenant(string $nome, string $emailAdministrador, ?Plan $plano): array
    {
        $empresa = $this->tenantService->criar([
            'name' => $nome,
            'plan_id' => $plano?->id,
            'administrador_nome' => 'Administrador de '.$nome,
            'administrador_email' => $emailAdministrador,
        ]);

        $administrador = User::where('email', $emailAdministrador)->firstOrFail();

        return [$empresa->fresh(), $administrador];
    }

    /**
     * Cria `$quantidade` clientes a mais dentro do tenant informado, cada um
     * com e-mail e CNPJ únicos (a unique de domínio é composta com
     * `company_id`, mas a numeração aqui já evita colisão mesmo assim).
     */
    private function criarClientes(Company $empresa, int $quantidade): void
    {
        TenantAtual::comTenant($empresa->id, function () use ($quantidade): void {
            $existentes = Client::query()->count();

            for ($i = 1; $i <= $quantidade; $i++) {
                $numero = $existentes + $i;

                Client::create([
                    'name' => "Cliente {$numero}",
                    'email' => "cliente-situacao-{$numero}@exemplo.test",
                    'phone' => '11999990000',
                    'cnpj' => sprintf('%014d', $numero),
                ]);
            }
        });
    }
}
