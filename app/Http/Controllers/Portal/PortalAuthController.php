<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\DefinirSenhaPortalRequest;
use App\Http\Requests\Portal\EsqueciSenhaPortalRequest;
use App\Http\Requests\Portal\PortalLoginRequest;
use App\Models\ClientUser;
use App\Services\ClientUserService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Autenticação do portal do cliente (Plano 15, Task 15.2): login, logout,
 * pedido de recuperação e definição de senha, tudo no guard `cliente`.
 *
 * Guard inteiramente separado do login dos funcionários
 * (`App\Http\Controllers\AuthController`, guard `web`): sessão própria,
 * mensagens próprias, e nenhuma reutilização da lógica de lá.
 *
 * ## A mensagem de erro do login é sempre a mesma
 *
 * Senha errada, e-mail sem acesso e conta desativada devolvem exatamente a
 * mesma mensagem. É o oposto do login do app do técnico (Plano 12), que
 * diferencia usuário desativado de credencial errada porque quem entra ali é
 * sempre funcionário da própria empresa. Aqui quem entra é de fora, e revelar
 * qual dos três casos aconteceu é revelar quem é cliente de quem.
 *
 * ## Por que não dá para usar `Auth::attempt()` puro
 *
 * `client_users.email` é único por empresa, não globalmente: a mesma pessoa
 * pode ser cliente de duas empresas da plataforma com o mesmo e-mail (ver o
 * cabeçalho de `ClientUser`). `Auth::attempt()` localiza um único registro por
 * e-mail e testa a senha só contra ele; com o mesmo e-mail em duas linhas, ele
 * poderia pegar a linha errada e recusar uma senha que era válida na outra
 * empresa. `autenticarPorCredenciais()` resolve isso testando a senha contra
 * cada conta ativa encontrada, e a que bater é a que autentica - e é dela,
 * nunca de outro lugar, que o tenant sai.
 */
class PortalAuthController extends Controller
{
    private const MENSAGEM_LOGIN_INVALIDO = 'E-mail ou senha inválidos.';

    public function __construct(
        private readonly ClientUserService $clientUsers,
    ) {}

    public function showLogin(): Response
    {
        return Inertia::render('Portal/Auth/Login');
    }

    public function login(PortalLoginRequest $request): RedirectResponse
    {
        $email = mb_strtolower(trim((string) $request->input('email')));
        $senha = (string) $request->input('password');

        $clientUser = $this->autenticarPorCredenciais($email, $senha);

        if ($clientUser === null) {
            // Dispara o evento nativo de falha, no mesmo padrão do login dos
            // funcionários: é dele que `RegistraAcesso` (auditoria do Plano 3)
            // grava a tentativa recusada.
            event(new Failed('cliente', null, ['email' => $email]));

            return back()->withErrors(['email' => self::MENSAGEM_LOGIN_INVALIDO])->onlyInput('email');
        }

        Auth::guard('cliente')->login($clientUser);
        $request->session()->regenerate();

        // Sem painel do portal ainda (Task 15.3/15.4): `intended()` cai aqui
        // até existir uma rota protegida de verdade para apontar.
        return redirect()->intended('/portal');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('cliente')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }

    public function showEsqueciSenha(): Response
    {
        return Inertia::render('Portal/Auth/EsqueciSenha');
    }

    /**
     * Sempre a mesma resposta, exista ou não uma conta com este e-mail: é o
     * `ClientUserService::solicitarRecuperacaoSenha()` quem já garante isso,
     * e o controller não tem nada a acrescentar que pudesse vazar a diferença.
     */
    public function esqueciSenha(EsqueciSenhaPortalRequest $request): RedirectResponse
    {
        $this->clientUsers->solicitarRecuperacaoSenha((string) $request->input('email'));

        return back()->with(
            'success',
            'Se este e-mail tiver acesso ao portal, você vai receber um link para redefinir a senha.'
        );
    }

    /**
     * Tela pública de definir senha. Atende tanto o primeiro acesso quanto a
     * recuperação: as duas usam o mesmo token e o mesmo formulário.
     *
     * `empresa` (Plano 15, Task 15.6) mostra a marca do portal a quem chegou
     * pelo link do e-mail, mesmo antes do login: diferente da tela de login
     * (`showLogin()`), aqui o token na própria URL já identifica o tenant, sem
     * precisar de senha nenhuma para isso. `null` quando o token não
     * corresponde a nenhum acesso - a tela cai no verde padrão do sistema, sem
     * nome de empresa nenhum, e quem decide se o token pode ser usado de
     * verdade continua sendo `definirSenha()` no POST.
     */
    public function showDefinirSenha(string $token): Response
    {
        return Inertia::render('Portal/Auth/DefinirSenha', [
            'token' => $token,
            'empresa' => $this->clientUsers->empresaDoToken($token)?->brandingDoPortal(),
        ]);
    }

    public function definirSenha(DefinirSenhaPortalRequest $request, string $token): RedirectResponse
    {
        try {
            $this->clientUsers->definirSenha($token, (string) $request->input('senha'));
        } catch (RuntimeException $erro) {
            return back()->withErrors(['token' => $erro->getMessage()]);
        }

        return redirect()->route('portal.login')
            ->with('success', 'Senha definida. Entre com seu e-mail e a nova senha.');
    }

    /**
     * `ClientUser` ativo cuja senha bate com a informada, entre todas as
     * empresas em que este e-mail tem acesso. `null` quando nenhuma bate,
     * quando o e-mail não existe, ou quando a única conta encontrada está
     * desativada (por isso o filtro `ativo = true` já entra na consulta, e não
     * como checagem separada depois).
     */
    private function autenticarPorCredenciais(string $email, string $senha): ?ClientUser
    {
        if ($email === '' || $senha === '') {
            return null;
        }

        return ClientUser::query()
            ->deTodasAsEmpresas()
            ->where('email', $email)
            ->where('ativo', true)
            ->whereNotNull('password')
            ->get()
            ->first(fn (ClientUser $candidato): bool => Hash::check($senha, (string) $candidato->password));
    }
}
