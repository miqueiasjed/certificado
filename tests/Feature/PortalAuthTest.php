<?php

namespace Tests\Feature;

use App\Http\Middleware\AutenticarCliente;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Company;
use App\Models\NotificationQueue;
use App\Models\User;
use App\Services\ClientUserService;
use App\Support\EventosDeNotificacao;
use App\Support\TenantAtual;
use Database\Factories\ClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 15.2 do Plano 15: convite, definição de senha, recuperação e login do
 * portal do cliente, tudo no guard `cliente`, separado do guard `web` dos
 * funcionários.
 *
 * O critério mais grave do plano é o tenant nunca vir de parâmetro de URL: os
 * testes de resolução do tenant (via `AutenticarCliente`) e de login com o
 * mesmo e-mail em duas empresas são os que prendem isso diretamente.
 */
class PortalAuthTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Client $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        // A empresa 1 vem da migration de fundação do tenant, sem nome.
        $this->empresa = Company::query()->firstOrFail();
        $this->empresa->update([
            'name' => 'Dedetizadora Teste',
            'email' => 'contato@dedetizadorateste.test',
        ]);
        $this->empresa = $this->empresa->fresh();

        $this->cliente = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): Client => ClientFactory::new()->create()
        );
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Convite
    // -----------------------------------------------------------------

    public function test_convidar_cria_o_acesso_e_enfileira_o_convite_por_email(): void
    {
        $clientUser = $this->convidar(['nome' => 'Fulano de Tal', 'email' => 'fulano@exemplo.test']);

        $this->assertTrue($clientUser->ativo);
        $this->assertNull($clientUser->password);
        $this->assertNotNull($clientUser->convite_token);
        $this->assertSame(64, strlen($clientUser->convite_token));
        $this->assertNotNull($clientUser->convite_expira_em);
        $this->assertTrue($clientUser->convite_expira_em->isFuture());

        $aviso = NotificationQueue::query()
            ->where('evento', EventosDeNotificacao::CONVITE_PORTAL)
            ->first();

        $this->assertNotNull($aviso, 'convidar() deveria enfileirar o evento convite_portal via NotificationService::enfileirar()');
        $this->assertSame(EventosDeNotificacao::CANAL_EMAIL, $aviso->canal);
        $this->assertSame('fulano@exemplo.test', $aviso->destino);
        $this->assertSame($clientUser->id, $aviso->destinatario_id);
        $this->assertSame(NotificationQueue::SITUACAO_PENDENTE, $aviso->situacao);
        $this->assertStringContainsString($clientUser->convite_token, $aviso->corpo, 'o link do convite precisa levar o token');
        $this->assertStringContainsString($this->cliente->name, $aviso->corpo);
    }

    public function test_convidar_recusa_segundo_convite_com_o_mesmo_email_na_mesma_empresa(): void
    {
        $this->convidar(['nome' => 'Primeiro', 'email' => 'duplicado@exemplo.test']);

        $this->expectException(RuntimeException::class);

        $this->convidar(['nome' => 'Segundo', 'email' => 'duplicado@exemplo.test']);
    }

    public function test_reenviar_convite_invalida_o_token_anterior(): void
    {
        $clientUser = $this->convidar(['nome' => 'Beltrano', 'email' => 'beltrano@exemplo.test']);
        $tokenAntigo = $clientUser->convite_token;

        TenantAtual::comTenant(
            $this->empresa->id,
            fn () => app(ClientUserService::class)->reenviarConvite($clientUser)
        );

        $atualizado = $clientUser->fresh();
        $this->assertNotSame($tokenAntigo, $atualizado->convite_token);

        $this->assertCount(
            2,
            NotificationQueue::query()->where('evento', EventosDeNotificacao::CONVITE_PORTAL)->get(),
            'o reenvio precisa gerar um item novo na fila, e não ser tratado como duplicata do primeiro'
        );

        $this->expectException(RuntimeException::class);
        app(ClientUserService::class)->definirSenha($tokenAntigo, 'nova-senha-123');
    }

    // -----------------------------------------------------------------
    // Definir senha
    // -----------------------------------------------------------------

    public function test_definir_senha_por_token_valido_funciona_uma_vez(): void
    {
        $clientUser = $this->convidar(['nome' => 'Ciclana', 'email' => 'ciclana@exemplo.test']);
        $token = $clientUser->convite_token;

        $atualizado = app(ClientUserService::class)->definirSenha($token, 'senha-nova-123');

        $this->assertTrue(Hash::check('senha-nova-123', $atualizado->password));
        $this->assertNull($atualizado->convite_token);
        $this->assertNull($atualizado->convite_expira_em);
        $this->assertNotNull($atualizado->email_verificado_em);

        // Uso único: o mesmo token não serve uma segunda vez.
        $this->expectException(RuntimeException::class);
        app(ClientUserService::class)->definirSenha($token, 'outra-senha-456');
    }

    public function test_token_expirado_e_recusado(): void
    {
        $clientUser = $this->convidar(['nome' => 'Detrano', 'email' => 'detrano@exemplo.test']);

        $clientUser->update(['convite_expira_em' => now()->subMinute()]);

        $this->expectException(RuntimeException::class);
        app(ClientUserService::class)->definirSenha($clientUser->convite_token, 'senha-qualquer-123');
    }

    public function test_token_invalido_e_recusado(): void
    {
        $this->expectException(RuntimeException::class);
        app(ClientUserService::class)->definirSenha(str_repeat('x', 64), 'senha-qualquer-123');
    }

    // -----------------------------------------------------------------
    // Esqueci minha senha
    // -----------------------------------------------------------------

    public function test_esqueci_senha_enfileira_recuperacao_e_devolve_mensagem_generica(): void
    {
        $clientUser = $this->criarClientUserComSenha('recupera@exemplo.test', 'senha-antiga-123', true);

        $comConta = $this->mensagemDeSucessoAoEsquecerSenha('recupera@exemplo.test');
        $semConta = $this->mensagemDeSucessoAoEsquecerSenha('nao-existe@exemplo.test');

        $this->assertSame($comConta, $semConta, 'a resposta precisa ser igual, exista ou não conta com o e-mail informado');

        $aviso = NotificationQueue::query()
            ->where('evento', EventosDeNotificacao::RECUPERACAO_SENHA_PORTAL)
            ->first();

        $this->assertNotNull($aviso, 'o pedido de recuperação deveria enfileirar recuperacao_senha_portal');
        $this->assertSame('recupera@exemplo.test', $aviso->destino);

        $this->assertNotNull($clientUser->fresh()->convite_token, 'o pedido de recuperação precisa gerar um token novo');
    }

    public function test_esqueci_senha_de_conta_inativa_nao_enfileira_nada(): void
    {
        $this->criarClientUserComSenha('inativo-recupera@exemplo.test', 'senha-123456', false);

        $this->post('/portal/senha/esqueci', ['email' => 'inativo-recupera@exemplo.test'])
            ->assertSessionHas('success');

        $this->assertSame(
            0,
            NotificationQueue::query()->where('evento', EventosDeNotificacao::RECUPERACAO_SENHA_PORTAL)->count(),
            'acesso desativado não deveria receber link de recuperação'
        );
    }

    // -----------------------------------------------------------------
    // Login: mensagem idêntica para os três casos
    // -----------------------------------------------------------------

    public function test_login_com_senha_errada_email_inexistente_e_conta_inativa_devolvem_a_mesma_mensagem(): void
    {
        $this->criarClientUserComSenha('ativo@exemplo.test', 'senha-correta-123', true);
        $this->criarClientUserComSenha('inativo@exemplo.test', 'senha-correta-123', false);

        $senhaErrada = $this->mensagemDeErroDoLogin('ativo@exemplo.test', 'senha-errada-qualquer');
        $emailInexistente = $this->mensagemDeErroDoLogin('nao-cadastrado@exemplo.test', 'qualquer-coisa');
        $contaInativa = $this->mensagemDeErroDoLogin('inativo@exemplo.test', 'senha-correta-123');

        $this->assertNotEmpty($senhaErrada);
        $this->assertSame($senhaErrada, $emailInexistente, 'e-mail inexistente revelou mensagem diferente da senha errada');
        $this->assertSame($senhaErrada, $contaInativa, 'conta inativa revelou mensagem diferente da senha errada');
    }

    public function test_login_com_credenciais_corretas_autentica_no_guard_cliente(): void
    {
        $this->criarClientUserComSenha('valido@exemplo.test', 'senha-correta-123', true);

        $resposta = $this->post('/portal/login', [
            'email' => 'valido@exemplo.test',
            'password' => 'senha-correta-123',
        ]);

        $resposta->assertRedirect();
        $this->assertTrue(auth('cliente')->check());
        $this->assertSame('valido@exemplo.test', auth('cliente')->user()->email);
    }

    /**
     * `client_users.email` é único por empresa, não globalmente: a mesma
     * pessoa pode ter acesso ao portal de duas empresas com o mesmo e-mail. O
     * login precisa autenticar na empresa cuja senha bateu, nunca na
     * primeira que aparecer na consulta.
     */
    public function test_login_com_mesmo_email_em_duas_empresas_autentica_na_empresa_da_senha_certa(): void
    {
        $empresaDois = Company::create(['name' => 'Dedetizadora Dois', 'email' => 'contato@dois.test']);
        $clienteDois = TenantAtual::comTenant($empresaDois->id, fn (): Client => ClientFactory::new()->create());

        $emailCompartilhado = 'compartilhado@exemplo.test';

        $this->criarClientUserComSenha($emailCompartilhado, 'senha-empresa-um', true);
        TenantAtual::comTenant($empresaDois->id, fn (): ClientUser => ClientUser::create([
            'client_id' => $clienteDois->id,
            'nome' => 'Cliente Compartilhado',
            'email' => $emailCompartilhado,
            'password' => Hash::make('senha-empresa-dois'),
            'ativo' => true,
            'email_verificado_em' => now(),
        ]));

        $this->registrarRotaProtegidaDeTeste();

        $this->post('/portal/login', ['email' => $emailCompartilhado, 'password' => 'senha-empresa-dois'])
            ->assertRedirect();

        $resposta = $this->getJson('/portal/_teste/protegida');

        $resposta->assertOk();
        $this->assertSame(
            $empresaDois->id,
            $resposta->json('tenant_id'),
            'o login com a senha da empresa dois autenticou no tenant errado'
        );
    }

    public function test_seis_tentativas_de_login_em_um_minuto_devolvem_429(): void
    {
        for ($tentativa = 1; $tentativa <= 5; $tentativa++) {
            $resposta = $this->post('/portal/login', [
                'email' => 'alvo-do-limite@exemplo.test',
                'password' => 'senha-errada',
            ]);

            $this->assertNotSame(429, $resposta->status(), "a tentativa {$tentativa} não deveria ser limitada ainda");
        }

        $sexta = $this->post('/portal/login', [
            'email' => 'alvo-do-limite@exemplo.test',
            'password' => 'senha-errada',
        ]);

        $sexta->assertStatus(429);
    }

    // -----------------------------------------------------------------
    // Separação de guards
    // -----------------------------------------------------------------

    public function test_cliente_autenticado_nao_alcanca_rota_de_web_php(): void
    {
        $this->criarClientUserComSenha('cliente-guard@exemplo.test', 'senha-123456', true);

        $this->post('/portal/login', [
            'email' => 'cliente-guard@exemplo.test',
            'password' => 'senha-123456',
        ])->assertRedirect();

        $this->assertTrue(auth('cliente')->check());
        $this->assertFalse(auth('web')->check());

        $resposta = $this->get('/dashboard');

        $resposta->assertRedirect('/login');
    }

    public function test_funcionario_autenticado_nao_alcanca_portal(): void
    {
        $usuario = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): User => User::factory()->create(['is_active' => true])
        );

        $resposta = $this->actingAs($usuario)->post('/portal/logout');

        $this->assertFalse(auth('cliente')->check());
        $resposta->assertRedirect(route('portal.login'));
    }

    // -----------------------------------------------------------------
    // Middleware: tenant resolvido do registro, e desativação imediata
    // -----------------------------------------------------------------

    public function test_middleware_resolve_o_tenant_a_partir_do_registro_do_cliente_autenticado(): void
    {
        $this->registrarRotaProtegidaDeTeste();

        $this->criarClientUserComSenha('resolve-tenant@exemplo.test', 'senha-123456', true);

        $this->post('/portal/login', [
            'email' => 'resolve-tenant@exemplo.test',
            'password' => 'senha-123456',
        ])->assertRedirect();

        $resposta = $this->getJson('/portal/_teste/protegida');

        $resposta->assertOk();
        $this->assertSame($this->empresa->id, $resposta->json('tenant_id'));
    }

    public function test_rota_protegida_sem_autenticacao_redireciona_para_o_login(): void
    {
        $this->registrarRotaProtegidaDeTeste();

        $this->get('/portal/_teste/protegida')->assertRedirect(route('portal.login'));
    }

    public function test_desativar_o_acesso_derruba_na_proxima_requisicao(): void
    {
        $this->registrarRotaProtegidaDeTeste();

        $clientUser = $this->criarClientUserComSenha('desativa-na-hora@exemplo.test', 'senha-123456', true);

        $this->post('/portal/login', [
            'email' => 'desativa-na-hora@exemplo.test',
            'password' => 'senha-123456',
        ])->assertRedirect();

        // Antes de desativar, a rota protegida responde normalmente.
        $this->getJson('/portal/_teste/protegida')->assertOk();

        app(ClientUserService::class)->desativar($clientUser);

        // `auth('cliente')->user()` acima de deixou o ClientUser carregado em
        // cache no guard (`SessionGuard::$user`), coisa que só acontece
        // porque este teste reaproveita a mesma Application/contêiner entre
        // as duas chamadas simuladas de HTTP. Numa requisição real seguinte,
        // o processo é outro e o guard nasce sem cache nenhum, então
        // `retrieveById()` roda fresco sozinho; `forgetGuards()` reproduz
        // exatamente essa condição aqui.
        auth()->forgetGuards();

        // Mesma sessão, próxima requisição: o middleware confere `ativo` a
        // cada passada, não só no momento do login.
        $depois = $this->getJson('/portal/_teste/protegida');

        $depois->assertStatus(403);
        $this->assertFalse(auth('cliente')->check(), 'a sessão deveria ter sido derrubada pelo middleware');
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * @param  array{nome?: string, email?: string}  $dados
     */
    private function convidar(array $dados): ClientUser
    {
        return TenantAtual::comTenant(
            $this->empresa->id,
            fn (): ClientUser => app(ClientUserService::class)->convidar($this->cliente, $dados)
        );
    }

    private function criarClientUserComSenha(string $email, string $senha, bool $ativo): ClientUser
    {
        return TenantAtual::comTenant($this->empresa->id, fn (): ClientUser => ClientUser::create([
            'client_id' => $this->cliente->id,
            'nome' => 'Cliente de teste',
            'email' => $email,
            'password' => Hash::make($senha),
            'ativo' => $ativo,
            'email_verificado_em' => now(),
        ]));
    }

    /**
     * Dispara o login e devolve a mensagem de erro flashada na sessão para o
     * campo `email`, que é onde o controller grava o erro genérico.
     */
    private function mensagemDeErroDoLogin(string $email, string $senha): string
    {
        $resposta = $this->post('/portal/login', ['email' => $email, 'password' => $senha]);

        $resposta->assertSessionHasErrors('email');

        return (string) session('errors')->first('email');
    }

    private function mensagemDeSucessoAoEsquecerSenha(string $email): string
    {
        $resposta = $this->post('/portal/senha/esqueci', ['email' => $email]);

        $resposta->assertSessionHas('success');

        return (string) session('success');
    }

    /**
     * Rota protegida mínima, registrada em tempo de teste, só para exercitar
     * `AutenticarCliente` sem depender do painel do portal (Task 15.3/15.4,
     * fora do escopo desta task). Devolve o tenant resolvido, que é o que
     * mais importa conferir aqui.
     */
    private function registrarRotaProtegidaDeTeste(): void
    {
        Route::middleware(AutenticarCliente::class)->get('/portal/_teste/protegida', function () {
            return response()->json(['ok' => true, 'tenant_id' => TenantAtual::id()]);
        });
    }
}
