<?php

namespace App\Services;

use App\Models\AccessLog;
use App\Models\User;
use App\Support\TenantAtual;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * Sessão por token do aplicativo do técnico (Plano 12, Task 12.2).
 *
 * Concentra emissão, renovação e revogação do token pessoal (Sanctum) que
 * substitui a sessão de navegador no aplicativo de campo, e registra login e
 * revogação em `access_logs` (Plano 3), no mesmo padrão explícito já usado por
 * `AssumirTenantService`: como o login acontece antes de existir qualquer
 * usuário autenticado na requisição, `TenantAtual::id()` não tem como resolver
 * a empresa sozinho, e o registro é gravado dentro de
 * `TenantAtual::comTenant($usuario->company_id, ...)`.
 *
 * Validade do token
 * -----------------
 * 30 dias, sempre a partir do instante da emissão ou da renovação. Sessão que
 * expira em 24 horas derrubaria o técnico no meio do dia; sessão eterna seria
 * credencial permanente em um celular que se perde. Renovar a cada
 * sincronização bem-sucedida faz o técnico ativo nunca ser deslogado, e o
 * celular perdido (sem sincronizar mais) expira sozinho. `renovar()` fica
 * pronta para ser chamada pelas Tasks 12.3 a 12.5, que são os endpoints de
 * carga do dia e de sincronização.
 *
 * Retorno em array, não em exceção
 * ---------------------------------
 * `autenticar()` devolve `sucesso`, `status` e `mensagem`/`dados`, no padrão
 * que a skill de arquitetura recomenda para Service. A diferença entre 401
 * (credencial inválida) e 403 (conta desativada / sem técnico vinculado) é
 * decidida aqui, e o controller só repassa o `status` recebido.
 */
class AppSessionService
{
    private const DIAS_DE_VALIDADE_DO_TOKEN = 30;

    private const LIMITE_IP = 45;

    private const LIMITE_USER_AGENT = 255;

    /**
     * Autentica o técnico e emite o token do aplicativo.
     *
     * @return array{sucesso: bool, status: int, mensagem?: string, dados?: array}
     */
    public function autenticar(string $email, string $senha, string $dispositivo): array
    {
        $usuario = User::where('email', $email)->first();

        if ($usuario === null || ! Hash::check($senha, $usuario->password)) {
            return $this->falha(401, 'As credenciais fornecidas não correspondem aos nossos registros.');
        }

        if (! $usuario->is_active) {
            return $this->falha(403, 'Este usuário está desativado. Procure um administrador do sistema.');
        }

        $usuario->loadMissing(['technician', 'company']);

        if ($usuario->technician === null) {
            return $this->falha(
                403,
                'Este usuário não está vinculado a um técnico e não pode acessar o aplicativo de campo.'
            );
        }

        $token = $this->emitirToken($usuario, $dispositivo);

        $this->registrarAcesso($usuario, 'app_login', ['dispositivo' => $dispositivo]);

        return [
            'sucesso' => true,
            'status' => 200,
            'dados' => [
                'token' => $token,
                'usuario' => [
                    'id' => $usuario->id,
                    'name' => $usuario->name,
                    'email' => $usuario->email,
                ],
                'tecnico' => [
                    'id' => $usuario->technician->id,
                    'name' => $usuario->technician->name,
                    'specialty' => $usuario->technician->specialty,
                ],
                'empresa' => [
                    'id' => $usuario->company->id,
                    'name' => $usuario->company->name,
                ],
            ],
        ];
    }

    /**
     * Revoga o token com que a requisição corrente está autenticada
     * (`POST /api/app/logout`).
     */
    public function revogarAtual(User $usuario, PersonalAccessToken $token): void
    {
        $dispositivo = $token->name;
        $token->delete();

        $this->registrarAcesso($usuario, 'app_sessao_revogada', [
            'dispositivo' => $dispositivo,
            'motivo' => 'logout',
        ]);
    }

    /**
     * Sessões (tokens) ativas do usuário, mais recente primeiro. Cada linha
     * traz o nome do aparelho (guardado no `name` do token na emissão) e as
     * datas relevantes para a empresa decidir se revoga.
     */
    public function listarSessoes(User $usuario): Collection
    {
        $tokenAtual = $usuario->currentAccessToken();

        return $usuario->tokens()
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'dispositivo' => $token->name,
                'criado_em' => $token->created_at,
                'ultimo_uso_em' => $token->last_used_at,
                'expira_em' => $token->expires_at,
                'atual' => $tokenAtual !== null && $tokenAtual->getKey() === $token->getKey(),
            ]);
    }

    /**
     * Revoga uma sessão pelo id do token (`DELETE /api/app/sessoes/{id}`).
     *
     * A busca é escopada a `$usuario->tokens()`: token de outro usuário nunca é
     * encontrado por aqui, então quem pede a revogação só alcança o próprio
     * aparelho. Devolve `false` quando o id não pertence a este usuário, para o
     * controller responder 404.
     */
    public function revogarSessao(User $usuario, int $tokenId): bool
    {
        $token = $usuario->tokens()->whereKey($tokenId)->first();

        if ($token === null) {
            return false;
        }

        $dispositivo = $token->name;
        $token->delete();

        $this->registrarAcesso($usuario, 'app_sessao_revogada', [
            'dispositivo' => $dispositivo,
            'motivo' => 'revogado_pela_empresa',
        ]);

        return true;
    }

    /**
     * Revoga todos os tokens do aplicativo do usuário.
     *
     * Chamada por `UserService::alterarStatus()` no mesmo ato em que o usuário
     * é desativado: usuário desativado não pode continuar sincronizando pelo
     * aplicativo só porque o token dele ainda não venceu.
     */
    public function revogarTodas(User $usuario): void
    {
        $tokens = $usuario->tokens()->get();

        if ($tokens->isEmpty()) {
            return;
        }

        foreach ($tokens as $token) {
            $token->delete();
        }

        $this->registrarAcesso($usuario, 'app_sessao_revogada', [
            'motivo' => 'usuario_desativado',
            'quantidade' => $tokens->count(),
        ]);
    }

    /**
     * Estende a validade do token por mais 30 dias a partir de agora.
     *
     * Ainda sem chamador nesta task: as Tasks 12.3 a 12.5 (carga do dia e
     * sincronização) chamam este método a cada requisição autenticada bem
     * sucedida, com `$request->user()->currentAccessToken()`.
     */
    public function renovar(PersonalAccessToken $token): void
    {
        $token->forceFill([
            'expires_at' => now()->addDays(self::DIAS_DE_VALIDADE_DO_TOKEN),
        ])->save();
    }

    private function emitirToken(User $usuario, string $dispositivo): string
    {
        return $usuario->createToken(
            $dispositivo,
            ['*'],
            now()->addDays(self::DIAS_DE_VALIDADE_DO_TOKEN)
        )->plainTextToken;
    }

    /**
     * Grava o evento em `access_logs`, na empresa do usuário.
     *
     * Sem `try/catch` ao redor da resolução da empresa: se ela falhar, é
     * porque o próprio usuário está com o dado inconsistente, e não é este
     * método que deve engolir isso. A gravação em si segue o mesmo cuidado do
     * `RegistraAcesso`: falha ao auditar nunca derruba login, logout ou
     * revogação.
     */
    private function registrarAcesso(User $usuario, string $evento, array $detalhes = []): void
    {
        $empresa = $usuario->company_id;

        $gravar = function () use ($usuario, $evento, $detalhes): void {
            try {
                AccessLog::create([
                    'user_id' => $usuario->id,
                    'email' => $usuario->email,
                    'evento' => $evento,
                    'ip' => substr((string) request()->ip(), 0, self::LIMITE_IP),
                    'user_agent' => substr((string) request()->userAgent(), 0, self::LIMITE_USER_AGENT),
                    'detalhes' => $detalhes,
                    'created_at' => now(),
                ]);
            } catch (Throwable $falha) {
                Log::error("Falha ao registrar o evento de acesso do aplicativo \"{$evento}\".", [
                    'excecao' => $falha::class,
                    'mensagem' => $falha->getMessage(),
                    'user_id' => $usuario->id,
                ]);
            }
        };

        if ($empresa === null) {
            $gravar();

            return;
        }

        TenantAtual::comTenant((int) $empresa, $gravar);
    }

    /**
     * @return array{sucesso: bool, status: int, mensagem: string}
     */
    private function falha(int $status, string $mensagem): array
    {
        return [
            'sucesso' => false,
            'status' => $status,
            'mensagem' => $mensagem,
        ];
    }
}
