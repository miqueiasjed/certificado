<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Company;
use App\Models\Technician;
use App\Models\User;
use App\Services\UserService;
use App\Support\TenantAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Task 12.2 do Plano 12: autenticação do aplicativo do técnico.
 *
 * Cobre a emissão do token no login, a recusa de usuário inativo e de usuário
 * sem técnico vinculado, a proteção `auth:sanctum` das demais rotas
 * `/api/app/*`, o isolamento entre empresas do contexto autenticado por
 * token, a revogação de todos os tokens ao desativar o usuário e o registro
 * de login e de revogação em `access_logs` (auditoria do Plano 3).
 */
class AppAuthTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-do-tecnico-123';

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Login válido
    // -----------------------------------------------------------------

    public function test_login_valido_devolve_token_usuario_tecnico_e_empresa(): void
    {
        [$empresa, $usuario, $tecnico] = $this->criarEmpresaComTecnico('Um');

        $resposta = $this->postJson('/api/app/login', [
            'email' => $usuario->email,
            'password' => self::SENHA,
            'dispositivo' => 'Moto G54 de Teste',
        ]);

        $resposta->assertOk();
        $resposta->assertJsonStructure([
            'token',
            'usuario' => ['id', 'name', 'email'],
            'tecnico' => ['id', 'name', 'specialty'],
            'empresa' => ['id', 'name'],
        ]);

        $this->assertStringContainsString('|', (string) $resposta->json('token'));
        $this->assertSame($usuario->id, $resposta->json('usuario.id'));
        $this->assertSame($usuario->email, $resposta->json('usuario.email'));
        $this->assertSame($tecnico->id, $resposta->json('tecnico.id'));
        $this->assertSame($empresa->id, $resposta->json('empresa.id'));
        $this->assertSame($empresa->name, $resposta->json('empresa.name'));

        $this->assertSame(1, $usuario->tokens()->count());
    }

    // -----------------------------------------------------------------
    // Usuário inativo
    // -----------------------------------------------------------------

    public function test_usuario_inativo_recebe_403_com_mensagem_de_conta_desativada(): void
    {
        [, $usuario] = $this->criarEmpresaComTecnico('Dois', usuarioAtivo: false);

        $resposta = $this->postJson('/api/app/login', [
            'email' => $usuario->email,
            'password' => self::SENHA,
            'dispositivo' => 'Aparelho qualquer',
        ]);

        $resposta->assertStatus(403);
        $this->assertStringContainsString('desativad', mb_strtolower((string) $resposta->json('message')));
        $this->assertSame(0, $usuario->tokens()->count());
    }

    // -----------------------------------------------------------------
    // Usuário sem técnico vinculado
    // -----------------------------------------------------------------

    public function test_usuario_sem_tecnico_vinculado_recebe_403_com_mensagem_propria(): void
    {
        $empresa = Company::create(['name' => 'Empresa Tres']);

        $usuario = TenantAtual::comTenant($empresa->id, fn () => User::factory()->create([
            'email' => 'sem-tecnico-'.uniqid().'@exemplo.test',
            'password' => self::SENHA,
            'is_active' => true,
        ]));

        $resposta = $this->postJson('/api/app/login', [
            'email' => $usuario->email,
            'password' => self::SENHA,
            'dispositivo' => 'Aparelho qualquer',
        ]);

        $resposta->assertStatus(403);

        $mensagem = mb_strtolower((string) $resposta->json('message'));
        $this->assertStringContainsString('técnico', $mensagem);
        $this->assertStringNotContainsString('desativad', $mensagem);
    }

    // -----------------------------------------------------------------
    // Rota protegida sem token
    // -----------------------------------------------------------------

    public function test_rota_protegida_sem_token_devolve_401(): void
    {
        $resposta = $this->getJson('/api/app/sessoes');

        $resposta->assertStatus(401);
    }

    public function test_logout_sem_token_devolve_401(): void
    {
        $resposta = $this->postJson('/api/app/logout');

        $resposta->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas
    // -----------------------------------------------------------------

    /**
     * O contexto resolvido a partir do token é o que sustenta todo o resto do
     * isolamento (Task 4.x): `TenantAtual` lê o `company_id` do usuário
     * autenticado, e é dele que o escopo global de `BelongsToCompany` parte.
     * Autenticado por token como técnico da empresa 1, um técnico cadastrado
     * na empresa 2 precisa ficar invisível, e o da própria empresa continua
     * alcançável.
     */
    public function test_tenant_resolvido_pelo_token_nao_alcanca_dado_de_outra_empresa(): void
    {
        [$empresaUm, $usuarioUm, $tecnicoUm] = $this->criarEmpresaComTecnico('TenantUm');
        [$empresaDois, , $tecnicoDois] = $this->criarEmpresaComTecnico('TenantDois');

        Sanctum::actingAs($usuarioUm->fresh(), ['*']);

        $this->assertSame($empresaUm->id, TenantAtual::id());

        $this->assertNull(
            Technician::find($tecnicoDois->id),
            'o técnico da empresa 2 foi encontrado a partir do contexto autenticado da empresa 1'
        );

        $this->assertNotNull(
            Technician::find($tecnicoUm->id),
            'o próprio técnico da empresa 1 deveria continuar alcançável'
        );
    }

    /**
     * Mesmo cenário, agora ponta a ponta pela rota HTTP real: login pelo
     * endpoint, token em mãos, e a lista de sessões do próprio usuário nunca
     * traz o aparelho de um usuário de outra empresa.
     */
    public function test_lista_de_sessoes_por_token_nao_traz_sessao_de_usuario_de_outra_empresa(): void
    {
        [, $usuarioUm] = $this->criarEmpresaComTecnico('SessaoUm');
        [, $usuarioDois] = $this->criarEmpresaComTecnico('SessaoDois');

        $tokenUm = $this->logar($usuarioUm, 'Aparelho da empresa um')->json('token');
        $this->logar($usuarioDois, 'Aparelho da empresa dois');

        $resposta = $this->withToken($tokenUm)->getJson('/api/app/sessoes');

        $resposta->assertOk();

        $dispositivos = collect($resposta->json('sessoes'))->pluck('dispositivo')->all();

        $this->assertContains('Aparelho da empresa um', $dispositivos);
        $this->assertNotContains('Aparelho da empresa dois', $dispositivos);
    }

    // -----------------------------------------------------------------
    // Desativar o usuário revoga os tokens
    // -----------------------------------------------------------------

    public function test_desativar_o_usuario_revoga_os_tokens(): void
    {
        [, $usuario] = $this->criarEmpresaComTecnico('Quatro');

        $token = $this->logar($usuario, 'Aparelho a ser revogado')->json('token');

        $this->assertSame(1, $usuario->tokens()->count());

        app(UserService::class)->alterarStatus($usuario, false);

        $this->assertSame(0, $usuario->fresh()->tokens()->count());

        // O token antigo, ainda em posse do celular, deixa de servir.
        $this->withToken($token)->getJson('/api/app/sessoes')->assertStatus(401);
    }

    // -----------------------------------------------------------------
    // Login e revogação na auditoria
    // -----------------------------------------------------------------

    public function test_login_aparece_na_auditoria(): void
    {
        [, $usuario] = $this->criarEmpresaComTecnico('Cinco');

        $this->logar($usuario, 'Aparelho auditado');

        $log = AccessLog::query()
            ->where('evento', 'app_login')
            ->where('user_id', $usuario->id)
            ->first();

        $this->assertNotNull($log, 'o login do aplicativo deveria gravar um access log de evento app_login');
        $this->assertSame($usuario->email, $log->email);
    }

    public function test_logout_revoga_e_aparece_na_auditoria(): void
    {
        [, $usuario] = $this->criarEmpresaComTecnico('Seis');

        $token = $this->logar($usuario, 'Aparelho a sair')->json('token');

        $this->withToken($token)->postJson('/api/app/logout')->assertOk();

        $this->assertSame(0, $usuario->fresh()->tokens()->count());

        $log = AccessLog::query()
            ->where('evento', 'app_sessao_revogada')
            ->where('user_id', $usuario->id)
            ->first();

        $this->assertNotNull($log, 'o logout deveria gravar um access log de evento app_sessao_revogada');
        $this->assertSame('logout', $log->detalhes['motivo'] ?? null);
    }

    public function test_desativacao_revoga_e_aparece_na_auditoria(): void
    {
        [, $usuario] = $this->criarEmpresaComTecnico('Sete');

        $this->logar($usuario, 'Aparelho do usuario desativado');

        app(UserService::class)->alterarStatus($usuario, false);

        $log = AccessLog::query()
            ->where('evento', 'app_sessao_revogada')
            ->where('user_id', $usuario->id)
            ->first();

        $this->assertNotNull($log, 'desativar o usuário deveria gravar um access log de evento app_sessao_revogada');
        $this->assertSame('usuario_desativado', $log->detalhes['motivo'] ?? null);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Empresa nova com um usuário técnico vinculado (ou não, e/ou desativado,
     * conforme os parâmetros), pronta para logar no aplicativo.
     *
     * @return array{0: Company, 1: User, 2: ?Technician}
     */
    private function criarEmpresaComTecnico(
        string $marca,
        bool $usuarioAtivo = true,
        bool $comTecnico = true
    ): array {
        $empresa = Company::create(['name' => 'Empresa '.$marca]);

        [$usuario, $tecnico] = TenantAtual::comTenant($empresa->id, function () use ($marca, $usuarioAtivo, $comTecnico) {
            $usuario = User::factory()->create([
                'name' => 'Tecnico '.$marca,
                'email' => 'tecnico-'.strtolower($marca).'-'.uniqid().'@exemplo.test',
                'password' => self::SENHA,
                'is_active' => $usuarioAtivo,
            ]);

            $tecnico = null;

            if ($comTecnico) {
                $tecnico = Technician::create([
                    'name' => 'Cadastro Tecnico '.$marca,
                    'email' => 'cadastro-'.strtolower($marca).'-'.uniqid().'@exemplo.test',
                    'phone' => '11999990000',
                    'specialty' => 'Controle de pragas',
                    'is_active' => true,
                    'user_id' => $usuario->id,
                ]);
            }

            return [$usuario, $tecnico];
        });

        return [$empresa, $usuario->fresh(), $tecnico];
    }

    /**
     * Faz o login HTTP de um usuário já preparado por
     * `criarEmpresaComTecnico()` e devolve a resposta.
     */
    private function logar(User $usuario, string $dispositivo): \Illuminate\Testing\TestResponse
    {
        $resposta = $this->postJson('/api/app/login', [
            'email' => $usuario->email,
            'password' => self::SENHA,
            'dispositivo' => $dispositivo,
        ]);

        $resposta->assertOk();

        return $resposta;
    }
}
