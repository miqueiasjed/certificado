<?php

namespace App\Http\Controllers;

use App\Http\Requests\AceitarConviteRequest;
use App\Http\Requests\ConviteRequest;
use App\Models\Company;
use App\Models\Invitation;
use App\Services\InvitationService;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Convites de usuário (Plano 8, Task 8.4).
 *
 * Este controller vive dos dois lados da fronteira de autenticação, e é o único
 * do sistema nessa situação:
 *
 * - `index`, `store`, `destroy` e `reenviar` são do administrador da empresa,
 *   dentro do grupo `auth`, exigindo `usuario-criar`.
 * - `show` e `aceitar` são **públicos**. Quem os acessa ainda não tem conta,
 *   por definição: é para criar a conta dele que a rota existe. A autorização
 *   ali é o token da URL, conferido no `InvitationService`, do mesmo jeito que
 *   a assinatura autoriza o recibo público.
 *
 * Por isso nenhum método público toca em `Auth`, `Company::current()` ou
 * qualquer coisa que dependa de tenant resolvido: naquele contexto o tenant é
 * nulo, e a empresa correta é sempre a do convite.
 *
 * ## Contrato dos formulários (a Task 8.8 monta as telas em cima disto)
 *
 * - `POST /settings/convites`: `email`, `papel`, `nome` (opcional).
 * - `POST /convite/{token}`: `nome`, `senha`, `senha_confirmation`.
 */
class InvitationController extends Controller
{
    public function __construct(
        private readonly InvitationService $convites,
    ) {
    }

    /**
     * Lista os convites da empresa e alimenta o formulário de novo convite.
     *
     * `Invitation` tem o escopo global por empresa, então a listagem já sai
     * escopada sem filtro explícito. A situação de cada convite é calculada
     * aqui e não vem do banco: ela é a combinação de três colunas
     * (`aceito_em`, `cancelado_em`, `expira_em`) com o dia de hoje no fuso do
     * negócio, e uma coluna gravada ficaria desatualizada sozinha, todo dia à
     * meia-noite.
     *
     * O link de aceite só acompanha convite pendente. É por ele que o convite
     * se completa quando o e-mail não chega (Task 8.8), e para convite já
     * resolvido ele não serviria para nada além de circular um token à toa.
     */
    public function index(): Response
    {
        $convites = Invitation::query()
            ->with('convidadoPor:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Invitation $convite): array {
                $situacao = $this->situacaoDoConvite($convite);

                return [
                    'id' => $convite->id,
                    'email' => $convite->email,
                    'nome' => $convite->nome,
                    'papel' => $convite->papel,
                    'situacao' => $situacao,
                    'expira_em' => BusinessDate::diaDe($convite->expira_em),
                    'aceito_em' => $convite->aceito_em,
                    'cancelado_em' => $convite->cancelado_em,
                    'convidado_por' => $convite->convidadoPor?->name,
                    'created_at' => $convite->created_at,
                    'link' => $situacao === 'pendente'
                        ? route('convite.show', ['token' => $convite->token])
                        : null,
                ];
            });

        return Inertia::render('Settings/Convites', [
            'convites' => $convites,
            'papeis' => Role::orderBy('name')->pluck('name'),
        ]);
    }

    /**
     * Cria e envia o convite.
     *
     * A empresa vem de `Company::current()` e quem convida vem do usuário
     * autenticado: nenhum dos dois chega pelo formulário, e é isso que impede
     * um convite de nascer apontando para outra empresa.
     */
    public function store(ConviteRequest $request)
    {
        try {
            $this->convites->convidar(
                Company::current(),
                (string) $request->input('email'),
                (string) $request->input('papel'),
                $request->input('nome'),
                $request->user(),
            );
        } catch (RuntimeException $erro) {
            return back()->withErrors(['error' => $erro->getMessage()]);
        }

        return redirect()->route('settings.convites.index')
            ->with('success', 'Convite enviado. Se o e-mail não chegar, copie o link na lista de convites.');
    }

    /**
     * Cancela um convite pendente.
     *
     * Nada de checagem de empresa aqui: `{convite}` é resolvido com o escopo
     * global de `Invitation` ativo, então convite de outra empresa não é
     * encontrado e o Laravel responde 404 antes de esta ação começar.
     */
    public function destroy(Invitation $convite)
    {
        try {
            $this->convites->cancelar($convite);
        } catch (RuntimeException $erro) {
            return back()->withErrors(['error' => $erro->getMessage()]);
        }

        return redirect()->route('settings.convites.index')
            ->with('success', 'Convite cancelado.');
    }

    /**
     * Gera um token novo, estende o prazo e manda o e-mail outra vez. O link
     * anterior deixa de funcionar.
     */
    public function reenviar(Invitation $convite)
    {
        try {
            $this->convites->reenviar($convite);
        } catch (RuntimeException $erro) {
            return back()->withErrors(['error' => $erro->getMessage()]);
        }

        return redirect()->route('settings.convites.index')
            ->with('success', 'Convite reenviado com prazo novo. O link anterior deixou de valer.');
    }

    /**
     * Tela pública de aceite. ROTA SEM AUTENTICAÇÃO.
     *
     * Responde 200 mesmo para token inválido, expirado, já aceito ou
     * cancelado: a página é a mesma, e a prop `valido` decide entre mostrar o
     * formulário ou a mensagem com o caminho para o login. Um 404 aqui deixaria
     * quem clicou no link do e-mail diante de uma página de erro crua, sem
     * saber se errou o link ou se o convite venceu.
     */
    public function show(string $token): Response
    {
        return Inertia::render('Auth/AceitarConvite', $this->convites->situacaoDoToken($token));
    }

    /**
     * Cria o usuário de quem aceitou e o autentica. ROTA SEM AUTENTICAÇÃO.
     *
     * O papel e a empresa saem do convite dentro do Service; deste formulário
     * chegam apenas nome e senha.
     *
     * A autenticação logo em seguida é deliberada: a pessoa acabou de provar
     * que tem o token e acabou de escolher a própria senha, então mandá-la para
     * a tela de login para digitar tudo de novo só acrescentaria um passo em
     * que dá para errar. `session()->regenerate()` acompanha o login, como em
     * `AuthController`.
     */
    public function aceitar(AceitarConviteRequest $request, string $token)
    {
        try {
            $usuario = $this->convites->aceitar(
                $token,
                (string) $request->input('nome'),
                (string) $request->input('senha'),
            );
        } catch (RuntimeException $erro) {
            return back()->withErrors(['token' => $erro->getMessage()]);
        }

        Auth::login($usuario);
        $request->session()->regenerate();

        return redirect('/')->with('success', 'Acesso criado. Bem-vindo!');
    }

    /**
     * Situação de exibição do convite.
     *
     * A ordem é a mesma de `InvitationService::motivoDeRecusa()`: aceito e
     * cancelado vencem "expirado", porque um convite resolvido há dois meses
     * também está com a data no passado, e mostrá-lo como "expirado" esconderia
     * o que de fato aconteceu com ele.
     */
    private function situacaoDoConvite(Invitation $convite): string
    {
        if ($convite->aceito_em !== null) {
            return 'aceito';
        }

        if ($convite->cancelado_em !== null) {
            return 'cancelado';
        }

        return $convite->estaExpirado() ? 'expirado' : 'pendente';
    }
}
