<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePlatformAdmin;
use App\Models\AccessLog;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Support\TenantAtual;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Task 5.12 do Plano 5: o portão da área de plataforma e o modo suporte.
 *
 * O cenário é o mesmo do teste de vazamento do Plano 4, reduzido ao que
 * importa aqui: duas empresas montadas do zero, cada uma com um cliente
 * carimbado com a própria marca, e três usuários com privilégios diferentes.
 *
 * - `superAdmin`: `is_platform_admin = true`, formalmente lotado na empresa 1
 *   (decisão registrada na migration da Task 5.1).
 * - `usuarioComum`: administrador da empresa 1, com todas as permissões do
 *   papel `administrador` e nenhum acesso de plataforma.
 * - `administradorDois`: administrador da empresa 2, para a empresa 2 ter dono.
 *
 * O caso mais importante do arquivo é
 * `test_usuario_comum_com_tenant_assumido_forjado_na_sessao_continua_no_proprio_tenant`.
 * Ele prova a única coisa que separa este sistema de um vazamento total: a
 * chave `tenant_assumido_id` na sessão é texto inerte para quem não é super
 * admin. Sessão é dado que chega do cliente, e se a presença da chave bastasse
 * para trocar de tenant, qualquer usuário de uma dedetizadora leria o banco da
 * concorrente escrevendo um número na própria sessão.
 */
class PlataformaAcessoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Carimbo de cada tenant, no mesmo espírito do `VazamentoEntreEmpresasTest`:
     * nenhum é substring do outro e nenhum tem acento, para a busca textual no
     * corpo da resposta não confundir um com o outro nem esbarrar no escape
     * `\uXXXX` do JSON.
     */
    private const MARCA_UM = 'PLATAFORMAT1';

    private const MARCA_DOIS = 'PLATAFORMAT2';

    /**
     * Telas da área de plataforma que o teste de acesso percorre.
     *
     * @var array<int, string>
     */
    private const TELAS_DA_PLATAFORMA = [
        '/plataforma',
        '/plataforma/tenants',
        '/plataforma/planos',
    ];

    private Company $empresaUm;

    private Company $empresaDois;

    private User $superAdmin;

    private User $usuarioComum;

    private Client $clienteUm;

    private Client $clienteDois;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        // Sem página de depuração: ela imprime o código-fonte da pilha,
        // inclusive o deste arquivo, e as marcas dos dois tenants apareceriam
        // em qualquer 500 como falso positivo. Produção roda com debug
        // desligado, que é o cenário que interessa.
        config(['app.debug' => false]);

        $this->seed(RolesAndPermissionsSeeder::class);

        // A empresa 1 é a fundadora, criada pela migration de instalação. A
        // empresa 2 nasce aqui, como qualquer tenant novo nasceria.
        $this->empresaUm = Company::query()->firstOrFail();
        $this->empresaUm->update([
            'name' => 'Dedetizadora '.self::MARCA_UM,
            'cnpj' => '11.111.111/0001-11',
        ]);
        $this->empresaUm = $this->empresaUm->fresh();

        $this->empresaDois = Company::create([
            'name' => 'Dedetizadora '.self::MARCA_DOIS,
            'cnpj' => '22.222.222/0001-22',
            'email' => 'contato@'.strtolower(self::MARCA_DOIS).'.test',
        ]);

        $this->superAdmin = $this->criarUsuario($this->empresaUm->id, 'super-admin');
        $this->superAdmin->is_platform_admin = true;
        $this->superAdmin->save();
        $this->superAdmin = $this->superAdmin->fresh();

        $this->usuarioComum = $this->criarUsuario($this->empresaUm->id, 'comum-'.strtolower(self::MARCA_UM));
        $this->criarUsuario($this->empresaDois->id, 'comum-'.strtolower(self::MARCA_DOIS));

        $this->clienteUm = $this->criarCliente($this->empresaUm->id, self::MARCA_UM, '33.333.333/0001-11');
        $this->clienteDois = $this->criarCliente($this->empresaDois->id, self::MARCA_DOIS, '33.333.333/0001-22');

        TenantAtual::limpar();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Caso 1: quem não é super admin não entra
    // -----------------------------------------------------------------

    /**
     * Usuário comum recebe 403 nas três telas da plataforma, e cada tentativa
     * fica registrada em `access_logs` como `plataforma_negada`, na empresa
     * dele.
     *
     * Este é o caso que a prova de mutação da Task 5.12 derruba: comentar a
     * checagem de `is_platform_admin` em `EnsurePlatformAdmin` faz as três
     * telas responderem 200 e este teste falhar na primeira asserção.
     */
    public function test_usuario_comum_recebe_403_nas_telas_da_plataforma(): void
    {
        foreach (self::TELAS_DA_PLATAFORMA as $tela) {
            $resposta = $this->actingAs($this->usuarioComum)->get($tela);

            $this->assertSame(
                403,
                $resposta->status(),
                "GET {$tela} devolveu {$resposta->status()} para usuário comum: a área de plataforma abriu para quem não é super admin"
            );
        }

        $negadas = AccessLog::query()
            ->deTodasAsEmpresas()
            ->where('evento', 'plataforma_negada')
            ->get();

        $this->assertCount(
            count(self::TELAS_DA_PLATAFORMA),
            $negadas,
            'cada tentativa recusada na área de plataforma precisa virar um registro em access_logs'
        );

        $this->assertSame(
            [$this->empresaUm->id],
            $negadas->pluck('company_id')->unique()->map(fn ($id): int => (int) $id)->all(),
            'o registro da tentativa recusada pertence à empresa de quem tentou, não à plataforma'
        );

        $this->assertSame(
            ['plataforma', 'plataforma/tenants', 'plataforma/planos'],
            $negadas->pluck('detalhes.rota')->all(),
            'sem a rota tentada, o registro não separa curiosidade de varredura'
        );
    }

    /**
     * Visitante sem login nenhum também não entra. A rota tem `auth` antes de
     * `platform.admin`, então a resposta é o redirecionamento para o login, e
     * nunca a tela da plataforma.
     */
    public function test_visitante_sem_login_nao_alcanca_a_area_de_plataforma(): void
    {
        foreach (self::TELAS_DA_PLATAFORMA as $tela) {
            $resposta = $this->get($tela);

            $this->assertContains(
                $resposta->status(),
                [302, 401, 403],
                "GET {$tela} respondeu {$resposta->status()} para visitante sem login"
            );

            $this->assertStringNotContainsString(
                self::MARCA_DOIS,
                (string) $resposta->baseResponse->getContent(),
                "GET {$tela} devolveu dado de tenant para visitante sem login"
            );
        }
    }

    // -----------------------------------------------------------------
    // Caso 2: o super admin entra e enxerga todos os tenants
    // -----------------------------------------------------------------

    public function test_super_admin_acessa_a_plataforma_e_a_listagem_traz_tenants_de_todas_as_empresas(): void
    {
        foreach (self::TELAS_DA_PLATAFORMA as $tela) {
            $this->actingAs($this->superAdmin)->get($tela)->assertOk();
        }

        $resposta = $this->actingAs($this->superAdmin)->get('/plataforma/tenants');

        $listados = collect($resposta->inertiaProps('tenants.data'))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->assertContains($this->empresaUm->id, $listados, 'a listagem de tenants não trouxe a empresa 1');
        $this->assertContains(
            $this->empresaDois->id,
            $listados,
            'a listagem de tenants trouxe apenas a empresa do próprio super admin: a área de plataforma precisa enxergar todos os tenants'
        );
    }

    /**
     * O painel geral conta os tenants dos dois, e não só o do super admin.
     */
    public function test_painel_geral_da_plataforma_conta_todos_os_tenants(): void
    {
        $estatisticas = $this->actingAs($this->superAdmin)
            ->get('/plataforma')
            ->inertiaProps('estatisticas');

        $this->assertSame(
            Company::query()->count(),
            $estatisticas['total'],
            'o painel geral contou menos tenants do que existem no banco'
        );
        $this->assertGreaterThanOrEqual(2, $estatisticas['total']);
    }

    // -----------------------------------------------------------------
    // Caso 3: o escopo desligado vale só dentro do prefixo
    // -----------------------------------------------------------------

    /**
     * Dentro de `/plataforma` o escopo global por empresa fica desligado, e
     * toda consulta de model de domínio enxerga os dois tenants. Fora do
     * prefixo, a mesma consulta volta a enxergar apenas a empresa do super
     * admin.
     *
     * A parte de dentro é medida chamando o middleware de verdade
     * (`EnsurePlatformAdmin`), com o `$next` contando clientes: é ali, e só
     * ali, que `TenantAtual::semEscopo()` é ligado. A parte de fora é uma
     * requisição HTTP normal ao dashboard das empresas.
     */
    public function test_o_escopo_desligado_vale_dentro_da_plataforma_e_volta_a_valer_fora_dela(): void
    {
        $this->actingAs($this->superAdmin);

        $this->assertSame(
            1,
            Client::query()->count(),
            'fora da área de plataforma o super admin precisa enxergar apenas o próprio tenant'
        );

        $requisicao = Request::create('/plataforma', 'GET');
        $requisicao->setUserResolver(fn (): User => $this->superAdmin);

        $resposta = app(EnsurePlatformAdmin::class)->handle(
            $requisicao,
            fn (): \Illuminate\Http\Response => response((string) Client::query()->count())
        );

        $this->assertSame(
            '2',
            $resposta->getContent(),
            'dentro da área de plataforma a consulta precisa enxergar os clientes dos dois tenants'
        );

        $this->assertTrue(
            TenantAtual::escopoAtivo(),
            'o escopo por empresa não voltou a valer depois da requisição de plataforma'
        );

        $this->assertSame(
            1,
            Client::query()->count(),
            'o desligamento do escopo vazou para fora da requisição de plataforma'
        );
    }

    /**
     * Fora do prefixo `/plataforma`, e sem ter assumido tenant nenhum, o super
     * admin é um usuário comum da empresa dele: o dashboard das empresas conta
     * apenas o cliente do tenant 1.
     */
    public function test_super_admin_fora_da_plataforma_enxerga_somente_o_proprio_tenant(): void
    {
        $resposta = $this->actingAs($this->superAdmin)->get('/dashboard');

        $resposta->assertOk();

        $this->assertSame(
            1,
            $resposta->inertiaProps('stats')['total_clients'],
            'o dashboard das empresas somou cliente de outro tenant para o super admin sem tenant assumido'
        );

        $this->assertStringNotContainsString(
            self::MARCA_DOIS,
            (string) $resposta->baseResponse->getContent(),
            'o dashboard das empresas trouxe dado do tenant 2 para o super admin sem tenant assumido'
        );
    }

    // -----------------------------------------------------------------
    // Caso 4: assumir tenant fica em auditoria
    // -----------------------------------------------------------------

    public function test_assumir_tenant_grava_access_log_na_empresa_assumida(): void
    {
        $resposta = $this->actingAs($this->superAdmin)
            ->post("/plataforma/tenants/{$this->empresaDois->id}/assumir");

        $resposta->assertRedirect('/dashboard');

        $registro = AccessLog::query()
            ->deTodasAsEmpresas()
            ->where('evento', 'tenant_assumido')
            ->latest('id')
            ->first();

        $this->assertNotNull($registro, 'assumir tenant precisa gravar o evento tenant_assumido em access_logs');

        $this->assertSame(
            $this->empresaDois->id,
            (int) $registro->company_id,
            'o registro de tenant assumido nasceu na empresa do super admin, e precisa nascer na empresa cujos dados foram acessados'
        );

        $this->assertSame($this->superAdmin->id, (int) $registro->user_id);
        $this->assertSame($this->superAdmin->email, $registro->email);
        $this->assertSame($this->empresaDois->id, (int) $registro->detalhes['company_id']);
        $this->assertSame($this->empresaDois->name, $registro->detalhes['company_nome']);

        $this->assertSame(
            $this->empresaDois->id,
            (int) session(TenantAtual::CHAVE_SESSAO_TENANT_ASSUMIDO),
            'a sessão precisa guardar o tenant assumido'
        );
    }

    // -----------------------------------------------------------------
    // Caso 5: o que é criado no modo suporte nasce no tenant assumido
    // -----------------------------------------------------------------

    public function test_registro_criado_no_modo_suporte_nasce_no_tenant_assumido(): void
    {
        $this->assumirTenantDois();

        $resposta = $this->actingAs($this->superAdmin)
            ->withSession($this->sessaoComTenantAssumido())
            ->post('/clients', [
                'name' => 'Cliente criado no suporte '.self::MARCA_DOIS,
                'email' => 'suporte-'.strtolower(self::MARCA_DOIS).'@exemplo.test',
                'phone' => '11955550000',
                'cnpj' => '44.444.444/0001-22',
            ]);

        $resposta->assertRedirect('/clients');

        $criado = Client::query()
            ->deTodasAsEmpresas()
            ->where('cnpj', '44.444.444/0001-22')
            ->first();

        $this->assertNotNull($criado, 'o cliente não foi criado no modo suporte');

        $this->assertSame(
            $this->empresaDois->id,
            (int) $criado->company_id,
            'o registro criado durante o modo suporte nasceu na empresa do super admin, e não no tenant assumido'
        );
    }

    /**
     * A contraprova do caso 7: com o super admin de verdade, a chave de sessão
     * funciona e ele passa a enxergar o tenant assumido. Sem isto, o caso 7
     * poderia estar passando porque a funcionalidade inteira não faz nada.
     */
    public function test_super_admin_no_modo_suporte_enxerga_o_tenant_assumido(): void
    {
        $this->assumirTenantDois();

        $resposta = $this->actingAs($this->superAdmin)
            ->withSession($this->sessaoComTenantAssumido())
            ->get('/clients');

        $resposta->assertOk();

        $this->assertSame(
            [$this->clienteDois->id],
            collect($resposta->inertiaProps('clients.data'))->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'no modo suporte o super admin precisa enxergar os clientes do tenant assumido'
        );

        $suporte = $resposta->inertiaProps('suporte');

        $this->assertTrue($suporte['ativo'], 'a faixa de aviso do modo suporte precisa aparecer para o super admin');
        $this->assertSame($this->empresaDois->name, $suporte['empresa']);
    }

    // -----------------------------------------------------------------
    // Caso 6: liberar devolve o super admin para fora de qualquer tenant
    // -----------------------------------------------------------------

    public function test_liberar_grava_o_evento_e_tira_o_super_admin_do_tenant(): void
    {
        $this->assumirTenantDois();

        $resposta = $this->actingAs($this->superAdmin)
            ->withSession($this->sessaoComTenantAssumido())
            ->post('/plataforma/liberar');

        $resposta->assertRedirect('/plataforma');
        $resposta->assertSessionMissing(TenantAtual::CHAVE_SESSAO_TENANT_ASSUMIDO);
        $resposta->assertSessionMissing(TenantAtual::CHAVE_SESSAO_TENANT_ASSUMIDO_NOME);

        $registro = AccessLog::query()
            ->deTodasAsEmpresas()
            ->where('evento', 'tenant_liberado')
            ->latest('id')
            ->first();

        $this->assertNotNull($registro, 'liberar precisa gravar o evento tenant_liberado em access_logs');
        $this->assertSame($this->empresaDois->id, (int) $registro->company_id);
        $this->assertSame($this->empresaDois->id, (int) $registro->detalhes['company_id']);
        $this->assertSame($this->superAdmin->id, (int) $registro->user_id);

        // Sem tenant assumido na sessão, a resolução volta ao `company_id` do
        // próprio super admin: o dashboard das empresas conta só o cliente do
        // tenant 1, e a faixa de suporte some.
        $depois = $this->actingAs($this->superAdmin)->get('/dashboard');

        $depois->assertOk();
        $this->assertSame(1, $depois->inertiaProps('stats')['total_clients']);
        $this->assertFalse($depois->inertiaProps('suporte')['ativo']);

        $this->assertSame(
            $this->empresaUm->id,
            TenantAtual::id(),
            'depois de liberar, o tenant do super admin volta a ser resolvido pelo company_id dele'
        );
    }

    /**
     * Liberar sem ter assumido nada não gera registro de saída de lugar
     * nenhum. O botão é clicável em qualquer tela, e clique repetido ou
     * requisição refeita pelo navegador não pode sujar a auditoria.
     */
    public function test_liberar_sem_tenant_assumido_nao_gera_registro(): void
    {
        $this->actingAs($this->superAdmin)->post('/plataforma/liberar')->assertRedirect('/plataforma');

        $this->assertSame(
            0,
            AccessLog::query()->deTodasAsEmpresas()->where('evento', 'tenant_liberado')->count(),
            'liberar sem tenant assumido gravou um registro de saída'
        );
    }

    // -----------------------------------------------------------------
    // Caso 7: sessão forjada não vale para quem não é super admin
    // -----------------------------------------------------------------

    /**
     * O CASO MAIS IMPORTANTE DESTE ARQUIVO.
     *
     * Um usuário comum da empresa 1 com `tenant_assumido_id` apontando para a
     * empresa 2 na própria sessão (cookie adulterado, sessão copiada, payload
     * forjado) continua enxergando apenas a empresa 1, em toda tela, e
     * continua recebendo 403 na área de plataforma.
     *
     * Quem segura isso é a primeira linha de
     * `TenantAtual::idDoTenantAssumidoNaSessao()`: a chave de sessão só tem
     * valor para quem tem `is_platform_admin = true`, e para todo o resto é
     * texto inerte. Comentar essa checagem faz este teste falhar.
     */
    public function test_usuario_comum_com_tenant_assumido_forjado_na_sessao_continua_no_proprio_tenant(): void
    {
        $listagem = $this->actingAs($this->usuarioComum)
            ->withSession($this->sessaoComTenantAssumido())
            ->get('/clients');

        $listagem->assertOk();

        $this->assertSame(
            [$this->clienteUm->id],
            collect($listagem->inertiaProps('clients.data'))->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            'a sessão forjada levou um usuário comum para dentro do tenant da concorrente'
        );

        $this->assertStringNotContainsString(
            self::MARCA_DOIS,
            (string) $listagem->baseResponse->getContent(),
            'a listagem de clientes devolveu dado do tenant 2 para um usuário comum com a sessão forjada'
        );

        $this->assertFalse(
            $listagem->inertiaProps('suporte')['ativo'],
            'a faixa de modo suporte apareceu para um usuário comum com a sessão forjada'
        );

        $dashboard = $this->actingAs($this->usuarioComum)
            ->withSession($this->sessaoComTenantAssumido())
            ->get('/dashboard');

        $dashboard->assertOk();

        $this->assertSame(
            1,
            $dashboard->inertiaProps('stats')['total_clients'],
            'o dashboard somou cliente do tenant 2 para um usuário comum com a sessão forjada'
        );

        foreach (self::TELAS_DA_PLATAFORMA as $tela) {
            $resposta = $this->actingAs($this->usuarioComum)
                ->withSession($this->sessaoComTenantAssumido())
                ->get($tela);

            $this->assertSame(
                403,
                $resposta->status(),
                "GET {$tela} abriu para usuário comum com a sessão forjada: a sessão sozinha não pode conceder acesso"
            );
        }
    }

    /**
     * A mesma sessão forjada também não permite gravar dentro do outro tenant.
     * Ler o dado da concorrente é grave; escrever dentro dela é pior, porque
     * fica.
     */
    public function test_usuario_comum_com_sessao_forjada_nao_grava_no_outro_tenant(): void
    {
        $this->actingAs($this->usuarioComum)
            ->withSession($this->sessaoComTenantAssumido())
            ->post('/clients', [
                'name' => 'Cliente forjado',
                'email' => 'forjado@exemplo.test',
                'phone' => '11944440000',
                'cnpj' => '55.555.555/0001-99',
            ]);

        $criado = Client::query()->deTodasAsEmpresas()->where('cnpj', '55.555.555/0001-99')->first();

        $this->assertNotNull($criado, 'o cliente deveria ter sido criado, mas dentro do tenant de quem pediu');

        $this->assertSame(
            $this->empresaUm->id,
            (int) $criado->company_id,
            'a sessão forjada fez um usuário comum gravar dentro do tenant da concorrente'
        );
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Executa o "assumir tenant" de verdade, pela rota, como o super admin.
     *
     * A sessão do cliente de teste não é levada de uma requisição para a
     * seguinte, então as chamadas posteriores repetem o estado com
     * `sessaoComTenantAssumido()`. O que este método garante é que o estado
     * repetido é exatamente o que a rota real produz, e não uma invenção do
     * teste.
     */
    private function assumirTenantDois(): void
    {
        $this->actingAs($this->superAdmin)
            ->post("/plataforma/tenants/{$this->empresaDois->id}/assumir")
            ->assertRedirect('/dashboard');

        $this->assertSame(
            $this->empresaDois->id,
            (int) session(TenantAtual::CHAVE_SESSAO_TENANT_ASSUMIDO),
            'a rota de assumir tenant não gravou o tenant na sessão'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sessaoComTenantAssumido(): array
    {
        return [
            TenantAtual::CHAVE_SESSAO_TENANT_ASSUMIDO => $this->empresaDois->id,
            TenantAtual::CHAVE_SESSAO_TENANT_ASSUMIDO_NOME => $this->empresaDois->name,
        ];
    }

    /**
     * Usuário administrador da empresa informada.
     *
     * Criado dentro de `comTenant()` porque `company_id` está fora do
     * `$fillable` de `User`: quem preenche é a trait `BelongsToCompany`, a
     * partir do tenant corrente.
     */
    private function criarUsuario(int $empresa, string $apelido): User
    {
        $usuario = TenantAtual::comTenant($empresa, fn (): User => User::factory()->create([
            'name' => 'Usuario '.$apelido,
            'email' => $apelido.'@exemplo.test',
            'is_active' => true,
        ]));

        $usuario->assignRole('administrador');

        return $usuario->fresh();
    }

    private function criarCliente(int $empresa, string $marca, string $cnpj): Client
    {
        return TenantAtual::comTenant($empresa, fn (): Client => Client::create([
            'name' => 'Cliente '.$marca,
            'email' => 'cliente-'.strtolower($marca).'@exemplo.test',
            'phone' => '11912340000',
            'cnpj' => $cnpj,
        ]));
    }
}
