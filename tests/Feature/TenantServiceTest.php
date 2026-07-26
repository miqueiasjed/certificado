<?php

namespace Tests\Feature;

use App\Exceptions\TenantInternoException;
use App\Models\Client;
use App\Models\Company;
use App\Models\Plan;
use App\Models\TenantUsage;
use App\Models\User;
use App\Services\TenantService;
use App\Services\TenantUsageService;
use App\Support\TenantAtual;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 5.12 do Plano 5: as regras de ciclo de vida de um tenant.
 *
 * O teste mais importante do arquivo, e do plano inteiro, é
 * `test_suspender_no_tenant_interno_lanca_excecao_e_nao_altera_a_empresa`. O
 * tenant interno é a empresa que opera o sistema hoje e gera a receita atual
 * do negócio: suspendê-la por um clique errado na área da plataforma
 * derrubaria a operação de um cliente real. A regra vive em
 * `TenantService::suspender()`, antes de qualquer escrita, e não pode ser
 * afrouxada.
 *
 * O caso 8 testa `TenantUsageService::apurar()`, e não `TenantService`. Está
 * aqui porque é o que a Task 5.12 especifica: os dois Services formam o
 * conjunto de regras que a área da plataforma consome, e a apuração de uso é
 * a única delas que grava linha nova a cada rodada.
 *
 * Todos os cenários montam os tenants do zero. A única empresa que já existe
 * no banco de teste é a fundadora, criada pela migration de instalação e
 * marcada como interna pela migration da Task 5.1, e é justamente ela que
 * serve de alvo ao caso 3.
 */
class TenantServiceTest extends TestCase
{
    use RefreshDatabase;

    private TenantService $tenantService;

    private TenantUsageService $tenantUsageService;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->tenantService = app(TenantService::class);
        $this->tenantUsageService = app(TenantUsageService::class);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Caso 1: nascimento do tenant
    // -----------------------------------------------------------------

    public function test_criar_gera_empresa_e_administrador_com_company_id_correto(): void
    {
        $plano = $this->criarPlano('Essencial', 100);

        $empresa = $this->tenantService->criar([
            'name' => 'Dedetizadora Nova',
            'cnpj' => '66.666.666/0001-66',
            'email' => 'contato@nova.test',
            'plan_id' => $plano->id,
            'administrador_nome' => 'Administrador da Nova',
            'administrador_email' => 'admin@nova.test',
        ]);

        $this->assertSame('Dedetizadora Nova', $empresa->name);
        $this->assertSame($plano->id, (int) $empresa->plan_id);
        $this->assertSame(TenantService::SITUACAO_ATIVA, $empresa->situacao, 'tenant sem prazo de avaliação nasce ativo');
        $this->assertFalse((bool) $empresa->is_internal, 'tenant novo nunca nasce interno');

        $administrador = User::query()->where('email', 'admin@nova.test')->first();

        $this->assertNotNull($administrador, 'criar tenant precisa criar o administrador dele');
        $this->assertSame(
            $empresa->id,
            (int) $administrador->company_id,
            'o administrador do tenant novo nasceu na empresa de quem criou, e não no tenant criado'
        );
        $this->assertTrue($administrador->hasRole('administrador'));
        $this->assertTrue((bool) $administrador->is_active);
        $this->assertFalse((bool) $administrador->is_platform_admin, 'administrador de tenant não é super admin');
    }

    /**
     * Com prazo de avaliação, o tenant nasce em `em_avaliacao`. A situação
     * nunca é lida de `$dados`: quem entra em avaliação é quem tem prazo de
     * avaliação.
     */
    public function test_criar_com_prazo_de_avaliacao_nasce_em_avaliacao(): void
    {
        $empresa = $this->tenantService->criar([
            'name' => 'Dedetizadora em Teste',
            'trial_ends_at' => '2026-08-31',
            'situacao' => TenantService::SITUACAO_ATIVA,
            'is_internal' => true,
            'administrador_nome' => 'Administrador do Teste',
            'administrador_email' => 'admin@teste.test',
        ]);

        $this->assertSame(TenantService::SITUACAO_EM_AVALIACAO, $empresa->situacao);
        $this->assertSame('2026-08-31', $empresa->trial_ends_at->toDateString());
        $this->assertFalse(
            (bool) $empresa->is_internal,
            'is_internal veio no formulário e foi aceito: um tenant marcado como interno ficaria imune à suspensão'
        );
    }

    // -----------------------------------------------------------------
    // Caso 2: transação
    // -----------------------------------------------------------------

    /**
     * `users.email` é unique global de propósito (o login é por e-mail), então
     * repetir o e-mail do administrador estoura no banco. Como empresa e
     * administrador nascem dentro da mesma transação, a empresa criada logo
     * antes precisa ser desfeita junto: um tenant sem ninguém que consiga
     * entrar nele é lixo que ninguém percebe até o cliente reclamar.
     */
    public function test_criar_com_falha_no_usuario_nao_deixa_empresa_orfa(): void
    {
        $existente = TenantAtual::comTenant(
            Company::query()->firstOrFail()->id,
            fn (): User => User::factory()->create(['email' => 'repetido@exemplo.test'])
        );

        $empresasAntes = Company::query()->count();

        try {
            $this->tenantService->criar([
                'name' => 'Dedetizadora Orfa',
                'administrador_nome' => 'Administrador Repetido',
                'administrador_email' => $existente->email,
            ]);

            $this->fail('criar() com e-mail de administrador repetido deveria estourar no unique de users');
        } catch (QueryException) {
            // Esperado: o unique de `users.email` recusa o segundo cadastro.
        }

        $this->assertSame(
            $empresasAntes,
            Company::query()->count(),
            'a empresa criada antes da falha não foi desfeita: a transação de criar() não está segurando'
        );

        $this->assertNull(
            Company::query()->where('name', 'Dedetizadora Orfa')->first(),
            'a empresa órfã ficou no banco depois da falha na criação do administrador'
        );
    }

    // -----------------------------------------------------------------
    // Caso 3: o tenant interno nunca é suspenso
    // -----------------------------------------------------------------

    /**
     * A REGRA MAIS IMPORTANTE DO PLANO 5.
     *
     * A checagem vem antes de qualquer escrita, e a exceção sai com a empresa
     * exatamente como estava. A comparação é sobre a linha crua da tabela:
     * `updated_at` incluído, para pegar até o `update()` que gravasse os
     * mesmos valores.
     */
    public function test_suspender_no_tenant_interno_lanca_excecao_e_nao_altera_a_empresa(): void
    {
        $interno = Company::query()->where('is_internal', true)->firstOrFail();

        $antes = DB::table('companies')->where('id', $interno->id)->first();

        try {
            $this->tenantService->suspender($interno, 'Motivo qualquer');

            $this->fail('suspender() no tenant interno precisa lançar TenantInternoException');
        } catch (TenantInternoException $excecao) {
            $this->assertStringContainsString(
                $interno->name ?? '',
                $excecao->getMessage(),
                'a mensagem da recusa deveria citar a empresa recusada'
            );
        }

        $this->assertEquals(
            $antes,
            DB::table('companies')->where('id', $interno->id)->first(),
            'a suspensão recusada alterou a empresa interna'
        );

        $this->assertSame(TenantService::SITUACAO_ATIVA, $interno->fresh()->situacao);
    }

    /**
     * A mesma recusa quando o `is_internal` está apenas no banco e a instância
     * em memória diz o contrário (linha carregada com `select` parcial, ou
     * instância que passou por `fill()` com dado de formulário). É o motivo de
     * `ehTenantInterno()` conferir o valor persistido, e não só o atributo.
     */
    public function test_suspender_recusa_o_tenant_interno_mesmo_com_a_instancia_dizendo_o_contrario(): void
    {
        $interno = Company::query()->where('is_internal', true)->firstOrFail();
        $interno->is_internal = false;

        $this->expectException(TenantInternoException::class);

        $this->tenantService->suspender($interno, 'Motivo qualquer');
    }

    /**
     * A recusa também vale pela tela: o endpoint devolve a mensagem de erro e
     * não altera nada. Sem este caso, a regra estaria provada só no Service, e
     * a área da plataforma é por onde o clique errado realmente aconteceria.
     */
    public function test_endpoint_de_suspensao_recusa_o_tenant_interno(): void
    {
        $interno = Company::query()->where('is_internal', true)->firstOrFail();
        $superAdmin = $this->criarSuperAdmin();

        $antes = DB::table('companies')->where('id', $interno->id)->first();

        $resposta = $this->actingAs($superAdmin)
            ->post("/plataforma/tenants/{$interno->id}/suspender", ['motivo' => 'Teste de recusa do tenant interno']);

        $resposta->assertSessionHas('error');

        $this->assertEquals(
            $antes,
            DB::table('companies')->where('id', $interno->id)->first(),
            'o endpoint de suspensão alterou o tenant interno'
        );
    }

    // -----------------------------------------------------------------
    // Caso 4 e 5: suspender e reativar um tenant comum
    // -----------------------------------------------------------------

    public function test_suspender_em_tenant_comum_grava_situacao_instante_e_motivo(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Suspensa', 'admin@suspensa.test');

        $suspensa = $this->tenantService->suspender($empresa, 'Falta de pagamento em julho');

        $this->assertSame(TenantService::SITUACAO_SUSPENSA, $suspensa->situacao);
        $this->assertSame('Falta de pagamento em julho', $suspensa->motivo_suspensao);
        $this->assertNotNull($suspensa->suspensa_em, 'a suspensão precisa gravar o instante');
        $this->assertLessThanOrEqual(
            5,
            abs($suspensa->suspensa_em->diffInSeconds(now())),
            'suspensa_em precisa ser o instante da suspensão, em UTC como created_at'
        );

        // Suspender não apaga nem esconde registro do tenant.
        $this->assertSame(
            1,
            TenantAtual::comTenant($empresa->id, fn (): int => Client::query()->count()),
            'a suspensão apagou ou escondeu registro do tenant'
        );
    }

    public function test_reativar_limpa_motivo_e_instante(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Reativada', 'admin@reativada.test');

        $this->tenantService->suspender($empresa, 'Falta de pagamento em julho');

        $reativada = $this->tenantService->reativar($empresa);

        $this->assertSame(TenantService::SITUACAO_ATIVA, $reativada->situacao);
        $this->assertNull($reativada->motivo_suspensao, 'reativar precisa limpar o motivo da suspensão');
        $this->assertNull($reativada->suspensa_em, 'reativar precisa limpar o instante da suspensão');

        $this->assertSame(
            1,
            TenantAtual::comTenant($empresa->id, fn (): int => Client::query()->count()),
            'o dado do tenant precisa voltar inteiro depois da reativação'
        );
    }

    /**
     * Quem foi suspenso durante a avaliação volta para `ativa`, e não para a
     * situação anterior: devolvê-lo a `em_avaliacao` com o prazo já vencido o
     * deixaria bloqueado de novo assim que o Plano 6 entrar.
     */
    public function test_reativar_volta_para_ativa_mesmo_vindo_da_avaliacao(): void
    {
        $empresa = $this->tenantService->criar([
            'name' => 'Dedetizadora Avaliada',
            'trial_ends_at' => '2026-08-31',
            'administrador_nome' => 'Administrador Avaliado',
            'administrador_email' => 'admin@avaliada.test',
        ]);

        $this->tenantService->suspender($empresa, 'Uso indevido durante a avaliação');

        $this->assertSame(TenantService::SITUACAO_ATIVA, $this->tenantService->reativar($empresa)->situacao);
    }

    // -----------------------------------------------------------------
    // Caso 6: troca de plano
    // -----------------------------------------------------------------

    /**
     * Rebaixar plano troca só o vínculo. O tenant que sai do plano de 500
     * clientes para o de 1 continua com os clientes que tem: apagar dado por
     * decisão comercial seria perda irreversível.
     */
    public function test_trocar_plano_para_menor_nao_apaga_registro_do_tenant(): void
    {
        $grande = $this->criarPlano('Avancado', 500);
        $pequeno = $this->criarPlano('Basico', 1);

        $empresa = $this->criarTenantComum('Dedetizadora Rebaixada', 'admin@rebaixada.test', $grande);

        TenantAtual::comTenant($empresa->id, function (): void {
            Client::create([
                'name' => 'Segundo cliente',
                'email' => 'segundo@rebaixada.test',
                'phone' => '11900000002',
                'cnpj' => '77.777.777/0002-77',
            ]);
            Client::create([
                'name' => 'Terceiro cliente',
                'email' => 'terceiro@rebaixada.test',
                'phone' => '11900000003',
                'cnpj' => '77.777.777/0003-77',
            ]);
        });

        $antes = TenantAtual::comTenant($empresa->id, fn (): array => Client::query()->orderBy('id')->pluck('id')->all());

        $this->assertCount(3, $antes, 'o cenário precisa de mais clientes do que o plano pequeno permite');

        $rebaixada = $this->tenantService->trocarPlano($empresa, $pequeno);

        $this->assertSame($pequeno->id, (int) $rebaixada->plan_id);
        $this->assertSame(TenantService::SITUACAO_ATIVA, $rebaixada->situacao, 'trocar plano não muda situação');

        $this->assertSame(
            $antes,
            TenantAtual::comTenant($empresa->id, fn (): array => Client::query()->orderBy('id')->pluck('id')->all()),
            'rebaixar o plano apagou registro do tenant'
        );

        $this->assertSame(
            1,
            User::query()->where('company_id', $empresa->id)->count(),
            'rebaixar o plano apagou o administrador do tenant'
        );
    }

    // -----------------------------------------------------------------
    // Caso 7: números do painel geral
    // -----------------------------------------------------------------

    public function test_estatisticas_da_plataforma_contam_cada_situacao(): void
    {
        // A fundadora já existe e está ativa. As demais nascem aqui, uma por
        // situação, para a contagem ter o que separar.
        $ativa = $this->criarTenantComum('Tenant Ativo', 'admin@ativo.test');
        $suspensa = $this->criarTenantComum('Tenant Suspenso', 'admin@suspenso.test');
        $cancelada = $this->criarTenantComum('Tenant Cancelado', 'admin@cancelado.test');

        $this->tenantService->criar([
            'name' => 'Tenant em Avaliacao',
            'trial_ends_at' => '2026-08-31',
            'administrador_nome' => 'Administrador em Avaliacao',
            'administrador_email' => 'admin@avaliacao.test',
        ]);

        $this->tenantService->suspender($suspensa, 'Inadimplência');
        $cancelada->update(['situacao' => TenantService::SITUACAO_CANCELADA]);

        $estatisticas = $this->tenantService->estatisticasDaPlataforma();

        $this->assertSame(5, $estatisticas['total'], 'o total conta todas as situações, inclusive a cancelada');
        $this->assertSame(2, $estatisticas['ativos'], 'a fundadora e o tenant ativo');
        $this->assertSame(1, $estatisticas['suspensos']);
        $this->assertSame(1, $estatisticas['em_avaliacao']);
        $this->assertSame(5, $estatisticas['criados_ultimos_30_dias'], 'todos os tenants do cenário nasceram agora');

        $this->assertSame($ativa->id, Company::query()->where('name', 'Tenant Ativo')->value('id'));

        // Um tenant velho não entra na janela de 30 dias corridos.
        DB::table('companies')->where('id', $cancelada->id)->update(['created_at' => now()->subDays(45)]);

        $this->assertSame(
            4,
            $this->tenantService->estatisticasDaPlataforma()['criados_ultimos_30_dias'],
            'a janela de 30 dias contou um tenant criado antes dela'
        );
    }

    // -----------------------------------------------------------------
    // Caso 8: apuração de uso
    // -----------------------------------------------------------------

    /**
     * `apurar()` usa `updateOrCreate` na chave `[company_id, referencia]`, e a
     * mesma unique composta está no banco: rodar duas vezes no mesmo mês
     * sobrescreve, nunca duplica. Sem isso, a rotina diária do Plano 6 criaria
     * uma linha por dia e o histórico do tenant viraria ruído.
     */
    public function test_apurar_uso_grava_uma_linha_e_reapurar_sobrescreve(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Apurada', 'admin@apurada.test');
        $referencia = now()->format('Y-m');

        $primeira = $this->tenantUsageService->apurar($empresa, $referencia);

        $this->assertSame(1, TenantUsage::query()->where('company_id', $empresa->id)->count());
        $this->assertSame(1, $primeira->clientes, 'o tenant do cenário nasce com um cliente');
        $this->assertSame(1, $primeira->usuarios, 'o tenant do cenário nasce com um administrador ativo');
        $this->assertSame(0, $primeira->armazenamento_mb);

        TenantAtual::comTenant($empresa->id, fn (): Client => Client::create([
            'name' => 'Cliente que entrou depois',
            'email' => 'depois@apurada.test',
            'phone' => '11900000009',
            'cnpj' => '88.888.888/0001-88',
        ]));

        $segunda = $this->tenantUsageService->apurar($empresa, $referencia);

        $this->assertSame(
            1,
            TenantUsage::query()->where('company_id', $empresa->id)->count(),
            'reapurar o mesmo mês duplicou a linha em tenant_usages'
        );
        $this->assertSame($primeira->id, $segunda->id, 'a reapuração precisa sobrescrever a mesma linha');
        $this->assertSame(2, $segunda->clientes, 'a reapuração não atualizou a contagem');
    }

    /**
     * A apuração é por tenant: a contagem de uma empresa não soma o dado da
     * outra, nem quando as duas são apuradas na mesma rodada.
     */
    public function test_apuracao_de_um_tenant_nao_soma_o_dado_do_outro(): void
    {
        $primeiro = $this->criarTenantComum('Tenant Apurado Um', 'admin@apurado-um.test');
        $segundo = $this->criarTenantComum('Tenant Apurado Dois', 'admin@apurado-dois.test');

        TenantAtual::comTenant($segundo->id, fn (): Client => Client::create([
            'name' => 'Cliente extra do segundo',
            'email' => 'extra@apurado-dois.test',
            'phone' => '11900000010',
            'cnpj' => '99.999.999/0001-99',
        ]));

        $referencia = now()->format('Y-m');

        $this->assertSame(1, $this->tenantUsageService->apurar($primeiro, $referencia)->clientes);
        $this->assertSame(2, $this->tenantUsageService->apurar($segundo, $referencia)->clientes);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function criarPlano(string $nome, int $limiteDeClientes): Plan
    {
        return Plan::create([
            'nome' => $nome,
            'slug' => strtolower($nome),
            'valor' => 100,
            'periodicidade' => 'mensal',
            'limite_clientes' => $limiteDeClientes,
            'ativo' => true,
        ]);
    }

    /**
     * Tenant comum (nunca interno) já com um cliente dentro, para os testes
     * que precisam provar que nenhum registro se perde.
     */
    private function criarTenantComum(string $nome, string $emailDoAdministrador, ?Plan $plano = null): Company
    {
        $empresa = $this->tenantService->criar([
            'name' => $nome,
            'plan_id' => $plano?->id,
            'administrador_nome' => 'Administrador de '.$nome,
            'administrador_email' => $emailDoAdministrador,
        ]);

        TenantAtual::comTenant($empresa->id, fn (): Client => Client::create([
            'name' => 'Cliente de '.$nome,
            'email' => 'cliente-'.$emailDoAdministrador,
            'phone' => '11900000001',
            'cnpj' => '77.777.777/0001-'.str_pad((string) $empresa->id, 2, '0', STR_PAD_LEFT),
        ]));

        return $empresa->fresh();
    }

    /**
     * Super admin para os testes que passam pela área da plataforma.
     *
     * `is_platform_admin` fica fora do `$fillable` de `User` de propósito (é o
     * único flag que abre a área onde a consulta roda sem escopo de empresa),
     * então a promoção é atribuição direta na instância, como no resto do
     * sistema.
     */
    private function criarSuperAdmin(): User
    {
        $empresa = Company::query()->firstOrFail();

        $usuario = TenantAtual::comTenant($empresa->id, fn (): User => User::factory()->create([
            'name' => 'Super admin',
            'email' => 'super-admin@plataforma.test',
            'is_active' => true,
        ]));

        $usuario->assignRole('administrador');
        $usuario->is_platform_admin = true;
        $usuario->save();

        return $usuario->fresh();
    }
}
