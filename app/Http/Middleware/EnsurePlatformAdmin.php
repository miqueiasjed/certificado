<?php

namespace App\Http\Middleware;

use App\Models\AccessLog;
use App\Support\TenantAtual;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Portão único da área de plataforma (`/plataforma`).
 *
 * Duas responsabilidades, nesta ordem:
 *
 * 1. Só deixa passar quem tem `is_platform_admin = true`. Qualquer outro
 *    usuário recebe 403, e a tentativa fica registrada em `access_logs` com
 *    `evento = plataforma_negada`.
 * 2. Para quem passa, liga `TenantAtual::semEscopo()` durante toda a
 *    requisição, para o painel enxergar os registros de todos os tenants.
 *
 * O ponto crítico é o item 2. `semEscopo()` é a única saída do isolamento entre
 * empresas que o sistema tem, e ela precisa valer exatamente pelo tempo da
 * requisição de plataforma: nem menos (o controller consultaria com o escopo
 * ligado e veria só a empresa do próprio super admin), nem mais (uma requisição
 * seguinte, de um usuário de tenant, enxergaria o banco inteiro). Por isso o
 * callback envolve `$next($request)`, e não outra coisa: o desligamento cobre o
 * controller e a montagem da resposta Inertia, e o `finally` de `semEscopo()`
 * religa o escopo na saída, inclusive quando o controller lança exceção.
 *
 * Este middleware é a razão de não existir um guard separado para o super
 * admin: o isolamento vem daqui, do flag em `users`, e não de uma tabela de
 * login à parte.
 */
class EnsurePlatformAdmin
{
    /**
     * Tetos de caracteres gravados em ip e user_agent, iguais aos do
     * `RegistraAcesso`, para as duas origens de evento caberem na mesma tabela.
     */
    private const LIMITE_IP = 45;

    private const LIMITE_USER_AGENT = 255;

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        // Comparação estrita e a favor do fechado: só o valor booleano `true`
        // abre a porta. Usuário ausente, coluna nula ou qualquer outro valor
        // cai no 403. A rota já vem com `auth` antes deste middleware, e a
        // checagem de `null` aqui é para o caso de alguém aplicar
        // `platform.admin` sozinho em uma rota nova.
        if ($usuario === null || $usuario->is_platform_admin !== true) {
            $this->registrarAcessoNegado($request, $usuario);

            abort(403, 'Esta área é exclusiva da administração da plataforma. Sua conta não tem esse acesso.');
        }

        return TenantAtual::semEscopo(fn () => $next($request));
    }

    /**
     * Grava a tentativa recusada em `access_logs`.
     *
     * O `company_id` é preenchido sozinho pela trait `BelongsToCompany` a partir
     * do tenant de quem tentou, que é um usuário de empresa normal. A gravação
     * acontece antes do `semEscopo()` justamente por isso: o registro pertence à
     * empresa do usuário negado, não à plataforma.
     *
     * Qualquer falha aqui é engolida e vai para o log da aplicação. Auditoria
     * não pode transformar um 403 legítimo em erro 500, mesmo modelo de proteção
     * do `RegistraAcesso`.
     */
    private function registrarAcessoNegado(Request $request, mixed $usuario): void
    {
        try {
            AccessLog::create([
                'user_id' => $usuario?->getAuthIdentifier(),
                'email' => $usuario?->email ?? '',
                'evento' => 'plataforma_negada',
                'ip' => substr((string) $request->ip(), 0, self::LIMITE_IP),
                'user_agent' => substr((string) $request->userAgent(), 0, self::LIMITE_USER_AGENT),
                // Sem a rota tentada, o registro diria que alguém bateu na
                // plataforma sem dizer onde, e é o "onde" que separa curiosidade
                // de varredura.
                'detalhes' => [
                    'metodo' => $request->method(),
                    'rota' => $request->path(),
                ],
                'created_at' => now(),
            ]);
        } catch (Throwable $falha) {
            try {
                Log::error('Falha ao registrar o evento de acesso "plataforma_negada". A resposta 403 não foi interrompida.', [
                    'excecao' => $falha::class,
                    'mensagem' => $falha->getMessage(),
                ]);
            } catch (Throwable) {
                // Nem o log respondeu. Engolir aqui é a última linha de defesa:
                // o acesso continua negado, que é o que importa.
            }
        }
    }
}
