<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\TenantService;
use App\Support\TenantAtual;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Barra o acesso do tenant suspenso (Plano 7, Task 7.7) e o manda para a página
 * de conta suspensa, onde estão o valor em aberto e o caminho para pagar.
 *
 * Mesmo padrão de resposta de `EnsureModuleIsActive`: requisição que espera JSON
 * recebe 403 com o motivo, navegação de página é redirecionada. A diferença é o
 * alcance. Módulo bloqueia rota a rota, com `module:<chave>` em cada uma;
 * suspensão bloqueia o sistema inteiro do tenant, e por isso este middleware é
 * aplicado ao grupo autenticado das empresas de uma vez, com uma lista curta de
 * exceções.
 *
 * ## Bloquear não é apagar
 *
 * Este middleware nega acesso e mais nada. Nenhum dado do tenant suspenso é
 * alterado, escondido ou removido, e a baixa do pagamento devolve tudo como
 * estava, na requisição seguinte (`InadimplenciaService::reativarPorPagamento()`).
 *
 * ## As exceções, e por que elas existem
 *
 * `ROTAS_LIBERADAS` é a lista de rotas que continuam acessíveis com a conta
 * suspensa. Bloquear o caminho do pagamento é o erro mais caro que uma régua de
 * cobrança pode cometer: um cliente que quer pagar e não consegue chegar ao
 * boleto vira cancelamento em vez de recebimento.
 *
 * - `logout`: sair do sistema não pode depender de estar em dia.
 * - `conta-suspensa`: a própria página de destino. Sem ela o redirecionamento
 *   apontaria para si mesmo em laço.
 * - `assinatura.faturas*`: a tela de faturas do tenant, que é a **Task 7.9** e
 *   ainda não existe. A exceção está pronta por nome de rota, e a 7.9 precisa
 *   nomear as rotas dela exatamente com esse prefixo (`assinatura.faturas.index`,
 *   `assinatura.faturas.show`, ...) para herdá-la. Nome diferente lá significa
 *   tenant suspenso sem acesso à própria fatura, que é o cenário que esta lista
 *   existe para impedir.
 *
 * ## Super admin passa
 *
 * `is_platform_admin` não é barrado, e a decisão não estava na especificação da
 * task. O motivo é o modo suporte da Task 5.5: o super admin assume um tenant
 * justamente para olhar os dados dele, e o tenant que ele mais precisa olhar é o
 * que foi suspenso. Barrado aqui, ele veria a tela de conta suspensa em vez do
 * sistema do cliente, e o suporte a inadimplente ficaria impossível. A área
 * `/plataforma` fica fora deste middleware por outro caminho: ela tem grupo
 * próprio em `routes/web.php` e não herda o do mundo das empresas.
 */
class EnsureTenantIsActive
{
    /**
     * Nome da rota da página de conta suspensa.
     *
     * Constante porque aparece em três lugares (a rota, a exceção abaixo e o
     * `redirect()`), e um deles escrito com outra grafia produziria laço de
     * redirecionamento.
     */
    public const ROTA_CONTA_SUSPENSA = 'conta-suspensa';

    /**
     * Rotas que o tenant suspenso continua alcançando. Aceita curinga, no mesmo
     * formato de `Request::routeIs()`.
     *
     * @var array<int, string>
     */
    public const ROTAS_LIBERADAS = [
        'logout',
        self::ROTA_CONTA_SUSPENSA,
        'assinatura.faturas*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        // Sem usuário não há tenant a avaliar. A rota já vem com `auth` antes
        // deste middleware; a checagem é para o caso de alguém aplicá-lo
        // sozinho em uma rota nova.
        if ($usuario === null) {
            return $next($request);
        }

        // Comparação estrita, igual à de `EnsurePlatformAdmin` e à de
        // `TenantAtual`: só o booleano `true` passa direto.
        if (($usuario->is_platform_admin ?? null) === true) {
            return $next($request);
        }

        if ($request->routeIs(...self::ROTAS_LIBERADAS)) {
            return $next($request);
        }

        if (! $this->tenantEstaSuspenso()) {
            return $next($request);
        }

        // Requisição da própria SPA buscando dado (fetch/axios): 403 seco com
        // JSON, para o interceptor do frontend tratar sem precisar entender uma
        // página HTML.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'O acesso da sua empresa está suspenso por falta de pagamento. '
                    .'Nenhum dado foi alterado: assim que a fatura em aberto for paga, o acesso volta automaticamente.',
                'situacao' => TenantService::SITUACAO_SUSPENSA,
            ], 403);
        }

        // Navegação de página, seja o primeiro carregamento (URL digitada) ou um
        // clique dentro da SPA. Redirect comum atende os dois casos porque o
        // destino também é uma página Inertia.
        return redirect()->route(self::ROTA_CONTA_SUSPENSA);
    }

    /**
     * A empresa da requisição corrente está suspensa?
     *
     * Lê a situação persistida com uma consulta escalar por requisição, em vez
     * de confiar em `$usuario->company`, por duas razões. O valor precisa ser o
     * do banco naquele instante: o pagamento que acabou de entrar por webhook
     * devolve o acesso na requisição seguinte, e um valor em memória vindo de
     * relação carregada antes atrasaria isso. E o tenant não é sempre o do
     * `company_id` do usuário: `TenantAtual` também resolve o tenant assumido em
     * sessão, e é ele que vale.
     *
     * Sem tenant resolvido, ninguém é barrado. O escopo global por empresa já
     * não filtra nada nesse estado, e é o `VazamentoEntreEmpresasTest` (Task
     * 4.11) que guarda esse caso; transformar "sem tenant" em "suspenso" aqui
     * criaria um bloqueio novo sem relação com pagamento.
     */
    private function tenantEstaSuspenso(): bool
    {
        $empresa = TenantAtual::id();

        if ($empresa === null) {
            return false;
        }

        return Company::query()
            ->whereKey($empresa)
            ->value('situacao') === TenantService::SITUACAO_SUSPENSA;
    }
}
