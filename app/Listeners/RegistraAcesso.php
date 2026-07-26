<?php

namespace App\Listeners;

use App\Models\AccessLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Grava em access_logs todo login bem-sucedido, toda tentativa recusada e todo logout.
 *
 * É observabilidade, não regra de negócio, por isso a classe fica em Listeners.
 * A gravação é sempre secundária ao fluxo de autenticação: se o insert falhar,
 * o login ou o logout seguem normalmente e o erro vai para o log da aplicação.
 * Sob nenhuma hipótese um problema aqui bloqueia quem está entrando ou saindo.
 *
 * Senha nunca é gravada, em nenhuma forma: nem em texto puro, nem com hash,
 * nem dentro de detalhes. Os eventos nativos do Laravel (Login, Failed, Logout)
 * são disparados pelo próprio Auth::attempt()/Auth::logout() usados no
 * AuthController, então este listener é o único ponto de captura.
 */
class RegistraAcesso
{
    /**
     * Teto de caracteres gravados em ip e user_agent.
     */
    private const LIMITE_IP = 45;

    private const LIMITE_USER_AGENT = 255;

    public function aoEntrar(Login $evento): void
    {
        $this->registrar([
            'user_id' => $evento->user->getAuthIdentifier(),
            'email' => $evento->user->email,
            'evento' => 'login',
        ]);
    }

    /**
     * Grava a tentativa recusada com o e-mail digitado, mesmo quando o
     * usuário não existe: é justamente esse padrão que revela tentativa de
     * invasão. A senha tentada fica em $evento->credentials['password'] e
     * jamais é lida aqui.
     */
    public function aoFalhar(Failed $evento): void
    {
        $this->registrar([
            'user_id' => null,
            'email' => $evento->credentials['email'] ?? '',
            'evento' => 'login_falhou',
        ]);
    }

    public function aoSair(Logout $evento): void
    {
        $this->registrar([
            'user_id' => $evento->user?->getAuthIdentifier(),
            'email' => $evento->user?->email ?? '',
            'evento' => 'logout',
        ]);
    }

    /**
     * Grava a linha em access_logs. Qualquer falha aqui é registrada no log
     * de erro da aplicação e engolida: nunca deve derrubar o fluxo de
     * autenticação.
     */
    private function registrar(array $dados): void
    {
        try {
            AccessLog::create($dados + [
                'ip' => substr((string) request()->ip(), 0, self::LIMITE_IP),
                'user_agent' => substr((string) request()->userAgent(), 0, self::LIMITE_USER_AGENT),
                'created_at' => now(),
            ]);
        } catch (Throwable $falha) {
            $this->registrarFalhaDeAuditoria($dados['evento'], $falha);
        }
    }

    private function registrarFalhaDeAuditoria(string $evento, Throwable $falha): void
    {
        try {
            Log::error("Falha ao registrar o evento de acesso \"{$evento}\". O fluxo de autenticação não foi interrompido.", [
                'excecao' => $falha::class,
                'mensagem' => $falha->getMessage(),
            ]);
        } catch (Throwable) {
            // Nem o log respondeu. Engolir aqui é a última linha de defesa:
            // sob nenhuma hipótese a auditoria derruba o login ou o logout.
        }
    }
}
