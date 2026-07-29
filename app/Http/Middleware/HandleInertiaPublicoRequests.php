<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Ponte Inertia das páginas públicas (Plano 16, Task 16.3): as únicas telas do
 * sistema que qualquer pessoa abre sem login nenhum.
 *
 * Por que não reaproveitar `HandleInertiaRequests`
 * ------------------------------------------------
 * Aquele middleware compartilha `auth.user` (com e-mail, papéis e a lista
 * inteira de permissões), `suporte`, `avisos`, `modulos`, `onboarding`,
 * `solicitacoesAbertas`, `empresa` e `clienteLogado`. Todas essas props saem de
 * quem está autenticado na sessão do navegador.
 *
 * A página pública roda com sessão (precisa dela para o CSRF do formulário e
 * para a mensagem de recebimento), e a sessão do navegador pode perfeitamente
 * ser a de um funcionário logado no painel, ou a de um cliente logado no
 * portal, que abriu `/agendar/{slug}` em outra aba. Com o middleware do painel,
 * a resposta de uma rota pública carregaria o e-mail e as permissões dessa
 * pessoa no payload da Inertia. Ninguém no frontend leria essas props (a página
 * pública não tem menu nem área autenticada), e é exatamente por isso que o
 * vazamento passaria despercebido.
 *
 * Este middleware não tem como vazar dado autenticado: ele não consulta guard
 * nenhum, não pergunta o tenant e não tem acesso a banco. O que sai daqui é o
 * que `Inertia\Middleware` já manda (`errors`, para o formulário mostrar a
 * validação) mais o `flash` de sucesso e de erro.
 *
 * Existe um teste que percorre todas as rotas de `routes/publico.php` e falha
 * se `HandleInertiaRequests` aparecer na pilha de qualquer uma delas
 * (`AgendamentoPublicoTest::test_nenhuma_rota_publica_expoe_dado_autenticado`).
 */
class HandleInertiaPublicoRequests extends Middleware
{
    /**
     * Casca própria, e não a `app` do painel.
     *
     * O bundle de JavaScript é o mesmo (as páginas públicas são componentes
     * Inertia como as outras). O que muda é o `@routes` do Ziggy: a casca do
     * painel imprime a lista inteira de rotas nomeadas do sistema no HTML, e
     * numa página aberta por qualquer pessoa isso entrega o mapa completo da
     * aplicação. A casca pública imprime só o grupo `publico`. Ver
     * `resources/views/publico/pagina.blade.php` e `config/ziggy.php`.
     *
     * @var string
     */
    protected $rootView = 'publico.pagina';

    /**
     * Props compartilhadas com toda página pública.
     *
     * `parent::share()` traz só `errors`. Nada aqui pode passar a depender de
     * `$request->user()`, de `Auth::guard(...)` ou de `Company::current()`: o
     * dado do tenant da página pública vem do slug da URL, resolvido no
     * controller, e nunca de quem está autenticado no navegador.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ]);
    }
}
