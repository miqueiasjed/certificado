<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 3.9 do Plano 3: cobre por teste o listener RegistraAcesso (Task 3.3),
 * que grava em access_logs todo login bem-sucedido, toda tentativa recusada
 * e todo logout.
 *
 * Um cenário adicional, fora da especificação original da task, entra aqui
 * porque veio de uma correção feita durante o plano: o AuthController exige
 * is_active na própria tentativa de autenticação (Auth::attempt com a
 * condição extra), então usuário desativado com senha correta nunca chega a
 * autenticar e o access log correto é login_falhou, nunca o par login mais
 * logout.
 */
class RegistroDeAcessoTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-correta-123';

    // -----------------------------------------------------------------
    // Login válido
    // -----------------------------------------------------------------

    public function test_login_valido_grava_evento_login_com_user_id(): void
    {
        $usuario = $this->criarUsuarioAtivo();

        $this->post('/login', [
            'email' => $usuario->email,
            'password' => self::SENHA,
        ]);

        $this->assertAuthenticatedAs($usuario);

        $log = AccessLog::query()->where('evento', 'login')->where('email', $usuario->email)->first();

        $this->assertNotNull($log, 'login válido deveria gravar um access log de evento login');
        $this->assertSame($usuario->id, $log->user_id);
    }

    // -----------------------------------------------------------------
    // Senha errada
    // -----------------------------------------------------------------

    public function test_senha_errada_grava_login_falhou_com_o_email_digitado_e_user_id_nulo(): void
    {
        $usuario = $this->criarUsuarioAtivo();

        $this->post('/login', [
            'email' => $usuario->email,
            'password' => 'senha-errada-nao-e-esta',
        ]);

        $this->assertGuest();

        $log = AccessLog::query()->where('evento', 'login_falhou')->where('email', $usuario->email)->first();

        $this->assertNotNull($log, 'senha errada deveria gravar um access log de evento login_falhou');
        $this->assertNull($log->user_id);
    }

    // -----------------------------------------------------------------
    // E-mail inexistente
    // -----------------------------------------------------------------

    public function test_email_inexistente_tambem_grava_login_falhou(): void
    {
        $emailInexistente = 'nao-existe-'.uniqid().'@example.com';

        $this->post('/login', [
            'email' => $emailInexistente,
            'password' => 'qualquer-coisa',
        ]);

        $this->assertGuest();

        $log = AccessLog::query()->where('evento', 'login_falhou')->where('email', $emailInexistente)->first();

        $this->assertNotNull($log, 'e-mail inexistente também deveria gravar um access log de evento login_falhou');
        $this->assertNull($log->user_id);
    }

    // -----------------------------------------------------------------
    // Logout
    // -----------------------------------------------------------------

    public function test_logout_grava_evento_logout(): void
    {
        $usuario = $this->criarUsuarioAtivo();

        $this->actingAs($usuario)->post('/logout');

        $this->assertGuest();

        $log = AccessLog::query()->where('evento', 'logout')->where('email', $usuario->email)->first();

        $this->assertNotNull($log, 'logout deveria gravar um access log de evento logout');
        $this->assertSame($usuario->id, $log->user_id);
    }

    // -----------------------------------------------------------------
    // Senha nunca vai para access_logs.detalhes
    // -----------------------------------------------------------------

    public function test_nenhum_access_logs_detalhes_contem_a_senha_enviada(): void
    {
        $usuario = $this->criarUsuarioAtivo();
        $senhaErrada = 'senha-errada-que-nao-pode-vazar';

        $this->post('/login', ['email' => $usuario->email, 'password' => self::SENHA]);
        $this->post('/login', ['email' => $usuario->email, 'password' => $senhaErrada]);
        $this->actingAs($usuario)->post('/logout');

        $linhas = DB::table('access_logs')->get();

        $this->assertNotEmpty($linhas, 'o cenário deveria ter gravado ao menos um access log');

        foreach ($linhas as $linha) {
            $this->assertNull($linha->detalhes, 'access_logs.detalhes nunca deveria ser preenchido pelo listener de acesso');
        }

        $conteudoBruto = json_encode($linhas);
        $this->assertStringNotContainsString(self::SENHA, $conteudoBruto);
        $this->assertStringNotContainsString($senhaErrada, $conteudoBruto);
    }

    // -----------------------------------------------------------------
    // Usuário desativado com senha correta: login_falhou, nunca login/logout
    // -----------------------------------------------------------------

    public function test_usuario_desativado_com_senha_correta_grava_login_falhou_e_nao_grava_login_nem_logout(): void
    {
        $usuario = User::factory()->create([
            'password' => self::SENHA,
            'is_active' => false,
        ]);

        $this->post('/login', [
            'email' => $usuario->email,
            'password' => self::SENHA,
        ]);

        $this->assertGuest();

        $this->assertSame(
            1,
            AccessLog::query()->where('email', $usuario->email)->count(),
            'usuário desativado com senha correta deveria gravar exatamente um access log'
        );

        $log = AccessLog::query()->where('email', $usuario->email)->first();

        $this->assertSame('login_falhou', $log->evento);
        $this->assertNull($log->user_id);

        $this->assertFalse(
            AccessLog::query()->where('email', $usuario->email)->where('evento', 'login')->exists(),
            'usuário desativado não deveria gerar access log de evento login'
        );
        $this->assertFalse(
            AccessLog::query()->where('email', $usuario->email)->where('evento', 'logout')->exists(),
            'usuário desativado não deveria gerar access log de evento logout'
        );
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function criarUsuarioAtivo(): User
    {
        return User::factory()->create([
            'password' => self::SENHA,
            'is_active' => true,
        ]);
    }
}
