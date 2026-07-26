<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 2.16 do Plano 2: cobre a regra de negócio que garante que a empresa
 * nunca fica sem nenhum administrador ativo, implementada em
 * UserService::garantirAdministradorRestante() (Task 2.12) e acionada tanto
 * por alterarStatus() quanto por atualizar().
 *
 * Os três primeiros cenários chamam o Service diretamente, do mesmo jeito
 * que UserController faz, porque a regra é de negócio e não de permissão: o
 * texto da task pede a garantia de "administrador restante", não a
 * autorização de quem pede a operação. A permissão de acesso à tela de
 * usuários é middleware simples, coberto no último cenário, no mesmo padrão
 * de AcessoFinanceiroTest (Task 2.15).
 */
class GestaoUsuariosTest extends TestCase
{
    use RefreshDatabase;

    private const PAPEIS_SEM_ACESSO_A_USUARIOS = ['tecnico', 'comercial', 'financeiro', 'leitura'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // -----------------------------------------------------------------
    // Desativação: nunca fica sem administrador ativo
    // -----------------------------------------------------------------

    public function test_desativar_o_ultimo_administrador_ativo_devolve_erro_e_mantem_o_usuario_ativo(): void
    {
        $ultimoAdministrador = $this->criarUsuarioComPapel('administrador');
        $solicitante = $this->criarUsuarioComPapel('leitura');

        $excecao = $this->capturarExcecao(
            fn () => app(UserService::class)->alterarStatus($ultimoAdministrador, false, $solicitante->id)
        );

        $this->assertNotNull($excecao, 'deveria ter lançado exceção ao tentar desativar o último administrador ativo');
        $this->assertStringContainsString('ao menos um administrador ativo', $excecao->getMessage());

        $this->assertTrue(
            $ultimoAdministrador->fresh()->is_active,
            'o último administrador deveria continuar ativo após a tentativa de desativação'
        );
    }

    public function test_com_dois_administradores_ativos_desativar_um_funciona(): void
    {
        $administradorQueFica = $this->criarUsuarioComPapel('administrador');
        $administradorDesativado = $this->criarUsuarioComPapel('administrador');

        app(UserService::class)->alterarStatus($administradorDesativado, false, $administradorQueFica->id);

        $this->assertFalse(
            $administradorDesativado->fresh()->is_active,
            'o segundo administrador deveria ter sido desativado'
        );
        $this->assertTrue(
            $administradorQueFica->fresh()->is_active,
            'o administrador que continua no sistema não deveria ser afetado'
        );
    }

    // -----------------------------------------------------------------
    // Troca de papel: nunca fica sem administrador ativo
    // -----------------------------------------------------------------

    public function test_trocar_o_papel_do_ultimo_administrador_para_leitura_devolve_erro(): void
    {
        $ultimoAdministrador = $this->criarUsuarioComPapel('administrador');

        $excecao = $this->capturarExcecao(fn () => app(UserService::class)->atualizar($ultimoAdministrador, [
            'name' => $ultimoAdministrador->name,
            'email' => $ultimoAdministrador->email,
            'role' => 'leitura',
        ]));

        $this->assertNotNull($excecao, 'deveria ter lançado exceção ao trocar o papel do último administrador');
        $this->assertStringContainsString('ao menos um administrador ativo', $excecao->getMessage());

        $this->assertTrue(
            $ultimoAdministrador->fresh()->hasRole('administrador'),
            'o último administrador deveria manter o papel de administrador'
        );
    }

    // -----------------------------------------------------------------
    // Login: usuário desativado é barrado
    // -----------------------------------------------------------------

    public function test_usuario_desativado_tenta_logar_e_e_rejeitado_com_mensagem_em_portugues(): void
    {
        $usuario = User::factory()->create([
            'password' => 'password',
            'is_active' => false,
        ]);

        $resposta = $this->post('/login', [
            'email' => $usuario->email,
            'password' => 'password',
        ]);

        $resposta->assertSessionHasErrors([
            'email' => 'Este usuário está desativado. Procure um administrador do sistema.',
        ]);

        $this->assertGuest();
    }

    // -----------------------------------------------------------------
    // Middleware: acesso à tela de usuários exige usuario-ver
    // -----------------------------------------------------------------

    public function test_usuario_sem_permissao_usuario_ver_recebe_403_em_settings_users(): void
    {
        foreach (self::PAPEIS_SEM_ACESSO_A_USUARIOS as $papel) {
            $usuario = $this->criarUsuarioComPapel($papel);

            $resposta = $this->actingAs($usuario)->get('/settings/users');

            $resposta->assertForbidden("papel '{$papel}' deveria receber 403 em /settings/users");
        }

        $administrador = $this->criarUsuarioComPapel('administrador');

        $resposta = $this->actingAs($administrador)->get('/settings/users');

        $this->assertNotSame(
            403,
            $resposta->status(),
            'administrador deveria conseguir acessar /settings/users'
        );
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * `is_active` explícito de propósito: o valor `true` é apenas o default
     * da coluna no banco, e um `User::factory()->create()` sem essa chave
     * deixa o atributo ausente no objeto em memória. O cast booleano então
     * resolve esse atributo ausente como `false`, embora o banco grave
     * `true`. Passar a chave aqui evita esse descompasso entre o que está no
     * banco e o que o objeto retornado carrega, o que importa nestes testes
     * porque UserService recebe a instância diretamente, sem recarregar do
     * banco como o route-model binding faria numa requisição HTTP real.
     */
    private function criarUsuarioComPapel(string $papel): User
    {
        $usuario = User::factory()->create(['is_active' => true]);
        $usuario->assignRole($papel);

        return $usuario;
    }

    /**
     * Executa o callback e devolve a \RuntimeException lançada por ele, ou
     * null se nada for lançado.
     *
     * Existe só por causa de um detalhe do PHPUnit: as próprias exceções de
     * asserção (AssertionFailedError) estendem \RuntimeException, então um
     * `catch (\RuntimeException $e)` colocado ao redor de `$this->fail(...)`
     * recaptura a falha do teste em vez de deixá-la estourar. Isolar a
     * chamada aqui, fora de qualquer catch de asserção, evita essa
     * armadilha.
     */
    private function capturarExcecao(\Closure $callback): ?\RuntimeException
    {
        try {
            $callback();
        } catch (\RuntimeException $e) {
            return $e;
        }

        return null;
    }
}
