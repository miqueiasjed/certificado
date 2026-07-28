<?php

namespace App\Http\Controllers;

use App\Http\Requests\CadastroEmpresaRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\AvaliacaoService;
use App\Services\TenantService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cadastro público de empresa (Plano 8, Task 8.7). ROTAS SEM AUTENTICAÇÃO.
 *
 * É a operação mais sensível de uma rota pública deste sistema: uma requisição
 * anônima cria uma empresa, um usuário administrador e algumas dezenas de
 * cadastros de catálogo. Quatro coisas sustentam isso, e nenhuma é opcional.
 *
 * 1. **`throttle` nas duas rotas.** Declarado em `routes/web.php`, junto com o
 *    motivo de cada limite. Rota pública que grava é a que precisa dele.
 *
 * 2. **Uma transação só, e ela não é aberta aqui.** `TenantService::criar()`
 *    abre a transação que envolve a empresa, o administrador, o catálogo
 *    inicial e a trilha de primeiros passos (Task 8.3). Falhou qualquer um dos
 *    quatro, nada fica gravado. Abrir uma transação por cima neste controller
 *    não acrescentaria proteção nenhuma (viraria savepoint) e prenderia lock
 *    durante a resposta HTTP.
 *
 * 3. **Nenhum campo de privilégio atravessa o formulário.** O `is_internal`
 *    não é aceito pelo `CadastroEmpresaRequest`, não está em
 *    `TenantService::CAMPOS_DO_TENANT` e nem sequer é `fillable` por este
 *    caminho: o tenant novo nasce com o padrão do banco, `false`. Tenant
 *    interno é imune a suspensão e a limite de plano, e nenhum caminho público
 *    pode criar um. O mesmo vale para `plan_id`, `situacao` e
 *    `is_platform_admin` do usuário.
 *
 * 4. **Duplicidade responde genérico.** Ver `CadastroEmpresaRequest::messages()`
 *    e o tratamento de `QueryException` em `store()`.
 *
 * ## Por que a avaliação começa aqui, e não dentro do `criar()`
 *
 * `TenantService::criar()` decide a situação inicial a partir de
 * `trial_ends_at`, e este formulário não envia esse campo: quanto dura a
 * avaliação é política da plataforma (`config('assinatura.dias_de_avaliacao')`),
 * não dado de formulário público. Quem aplica a política é
 * `AvaliacaoService::iniciar()`, chamado logo depois, sem argumento de prazo.
 * A empresa passa por `ativa` durante uma escrita e termina `em_avaliacao`.
 *
 * Essa segunda escrita fica fora da transação de propósito, mesma decisão
 * documentada em `AvaliacaoService::converter()`. Se ela falhar, o que resta é
 * uma empresa completa e utilizável, apenas sem prazo de avaliação marcado, que
 * o super admin acerta pela tela de tenants. O desfecho que a transação existe
 * para impedir, o tenant meio criado em que ninguém consegue entrar, continua
 * impossível.
 */
class CadastroEmpresaController extends Controller
{
    public function __construct(
        private readonly TenantService $tenantService,
        private readonly AvaliacaoService $avaliacaoService,
    ) {}

    /**
     * Formulário público de criação de empresa. ROTA SEM AUTENTICAÇÃO.
     *
     * `diasDeAvaliacao` vai para a tela porque ela precisa dizer quantos dias o
     * período de avaliação dura, e esse número é configuração
     * (`config/assinatura.php`), não texto fixo do componente: escrever "14
     * dias" no Vue faria a promessa da página divergir do prazo real no dia em
     * que a configuração mudasse.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->user() !== null) {
            return $this->redirecionarQuemJaEstaDentro();
        }

        return Inertia::render('Auth/CadastroEmpresa', [
            'diasDeAvaliacao' => max(1, (int) config('assinatura.dias_de_avaliacao', 14)),
        ]);
    }

    /**
     * Cria a empresa, provisiona, inicia a avaliação e autentica quem se
     * cadastrou. ROTA SEM AUTENTICAÇÃO.
     *
     * A autenticação imediata é deliberada, mesma decisão de
     * `InvitationController::aceitar()`: a pessoa acabou de escolher a própria
     * senha, e mandá-la para o login para digitar tudo de novo só acrescenta um
     * passo em que dá para errar.
     *
     * O destino é o dashboard, onde a trilha de primeiros passos aparece. Ela
     * não precisa ser montada aqui: `ProvisionamentoService` já gravou os passos
     * pendentes dentro da transação, e `HandleInertiaRequests` compartilha a
     * prop `onboarding` em toda navegação de quem tem trilha em aberto.
     */
    public function store(CadastroEmpresaRequest $request): RedirectResponse
    {
        if ($request->user() !== null) {
            return $this->redirecionarQuemJaEstaDentro();
        }

        try {
            $empresa = $this->tenantService->criar($request->dadosDoTenant());
        } catch (QueryException $erro) {
            return $this->recusarPorConflito($erro, $request->validated('administrador_email'));
        }

        $this->avaliacaoService->iniciar($empresa);

        $administrador = $this->administradorDa($empresa, (string) $request->validated('administrador_email'));

        if ($administrador === null) {
            // Inalcançável na prática: `TenantService::criar()` desfaz a empresa
            // inteira quando o administrador não nasce, então chegar aqui com a
            // empresa criada e sem administrador significaria que a transação
            // não se comportou como deveria. Fica registrado e a pessoa é
            // mandada ao login em vez de receber um erro de tipo, porque a
            // empresa dela existe e o acesso pode ser recuperado por lá.
            Log::error('Empresa criada pelo cadastro público sem administrador localizável.', [
                'company_id' => $empresa->getKey(),
            ]);

            return redirect()->route('login')->with(
                'message',
                'Sua empresa foi criada. Entre com o e-mail e a senha que você acabou de cadastrar.'
            );
        }

        Auth::login($administrador);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with(
            'success',
            'Conta criada. Siga os primeiros passos abaixo para deixar o sistema pronto para uso.'
        );
    }

    /**
     * O administrador recém-criado da empresa.
     *
     * Consultado pela relação da empresa, e não por `User::where('email', ...)`
     * solto: o que se quer aqui é "o usuário com este e-mail dentro da empresa
     * que acabou de nascer", e a relação já filtra por `company_id`. A diferença
     * seria invisível hoje (o e-mail é unique global e `User` não aplica escopo
     * de empresa na leitura), e seria exatamente o tipo de consulta que, um dia,
     * autenticaria a pessoa errada.
     */
    private function administradorDa(Company $empresa, string $email): ?User
    {
        return $empresa->users()->where('email', $email)->first();
    }

    /**
     * Duas requisições simultâneas com o mesmo CNPJ ou o mesmo e-mail: as duas
     * passam pelo `unique` do FormRequest, e é o índice do banco que recusa a
     * segunda.
     *
     * A transação de `TenantService::criar()` já desfez tudo antes de a exceção
     * chegar aqui, então não há empresa nem usuário órfão a limpar. O que falta
     * é responder, e a resposta é a mesma mensagem genérica da validação: a
     * corrida é indistinguível, para quem está do outro lado, de ter digitado um
     * dado já cadastrado.
     *
     * Só violação de unicidade é tratada assim, reconhecida pela mensagem do
     * driver e não pelo SQLSTATE: `23000` é "integrity constraint violation" em
     * geral no MySQL, e cobre também violação de chave estrangeira. Uma FK
     * quebrada aqui seria bug de código, e mostrá-la como "estes dados já estão
     * cadastrados" mandaria quem se cadastrou tentar outro CNPJ para sempre.
     * Qualquer outro erro de banco continua subindo, vira 500 e entra no
     * relatório de erros, que é onde ele precisa aparecer.
     */
    private function recusarPorConflito(QueryException $erro, ?string $email): RedirectResponse
    {
        $duplicidade = str_contains($erro->getMessage(), 'Duplicate entry')
            || str_contains($erro->getMessage(), 'UNIQUE constraint failed');

        if (! $duplicidade) {
            throw $erro;
        }

        Log::warning('Cadastro público recusado por conflito de unicidade.', [
            'email' => $email,
            'sqlstate' => $erro->getCode(),
        ]);

        return back()
            ->withInput()
            ->withErrors([
                'cnpj' => 'Não foi possível criar a conta com estes dados. Se a sua empresa já usa o sistema, '
                    .'entre com a sua conta ou peça um convite a quem administra a conta dela.',
            ]);
    }

    /**
     * Quem já está autenticado não cria empresa por aqui.
     *
     * Não é regra de segurança (nada vaza: `ProvisionamentoService` grava tudo
     * dentro do tenant novo, via `TenantAtual::comTenant()`), é proteção contra
     * o acidente. Uma aba velha em `/cadastro`, submetida por quem já entrou,
     * criaria uma segunda empresa e trocaria a sessão para ela sem que a pessoa
     * entendesse o que aconteceu. Quem realmente quer abrir outra empresa sai da
     * conta antes.
     */
    private function redirecionarQuemJaEstaDentro(): RedirectResponse
    {
        return redirect()->route('dashboard')->with(
            'message',
            'Você já está com uma conta aberta. Para criar outra empresa, saia do sistema primeiro.'
        );
    }
}
