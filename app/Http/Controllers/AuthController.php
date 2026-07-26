<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function showLoginForm(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // O is_active entra na própria tentativa: usuário desativado não chega a
        // autenticar, então o registro de acesso grava login_falhou em vez do par
        // login mais logout, que daria a entender que ele entrou no sistema.
        if (Auth::attempt($credentials + ['is_active' => true])) {
            $request->session()->regenerate();

            // Super admin entra direto na área da plataforma, sem passar pelo
            // `intended()`: a URL guardada na sessão é sempre de uma tela de
            // empresa, e mandar quem administra a plataforma para lá logo no
            // login inverte o destino esperado. Para operar dentro de uma
            // empresa ele assume o tenant explicitamente (Task 5.5).
            if (Auth::user()->is_platform_admin) {
                return redirect('/plataforma');
            }

            return redirect()->intended('/');
        }

        if ($this->credencialDeUsuarioDesativado($credentials)) {
            return back()->withErrors([
                'email' => 'Este usuário está desativado. Procure um administrador do sistema.',
            ])->onlyInput('email');
        }

        return back()->withErrors([
            'email' => 'As credenciais fornecidas não correspondem aos nossos registros.',
        ])->onlyInput('email');
    }

    /**
     * Senha correta de um usuário que existe mas está desativado. Serve só para
     * escolher a mensagem de erro, sem autenticar ninguém: senha errada continua
     * recebendo a mensagem genérica, para não revelar quais e-mails existem.
     */
    private function credencialDeUsuarioDesativado(array $credenciais): bool
    {
        $usuario = User::where('email', $credenciais['email'])->first();

        return $usuario !== null
            && ! $usuario->is_active
            && Hash::check($credenciais['password'], $usuario->password);
    }

    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
