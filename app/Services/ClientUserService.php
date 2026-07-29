<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Company;
use App\Support\EventosDeNotificacao;
use App\Support\TenantAtual;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Acesso do cliente ao portal (Plano 15, Task 15.2): convite, definição de
 * senha e recuperação, tudo em torno das colunas `convite_token` e
 * `convite_expira_em` de `ClientUser`.
 *
 * ## Um par de colunas, dois eventos
 *
 * O primeiro acesso (convite) e a recuperação de senha usam exatamente o mesmo
 * mecanismo: um token de 64 caracteres, válido por `DIAS_DE_VALIDADE` dias, de
 * uso único, consumido por `definirSenha()`. O que muda entre os dois é só o
 * texto do e-mail (`EventosDeNotificacao::CONVITE_PORTAL` acolhe,
 * `RECUPERACAO_SENHA_PORTAL` avisa sobre um pedido de redefinição), e por isso
 * as duas filas terminam no mesmo método de consumo.
 *
 * ## Tenant sempre do registro, nunca de parâmetro
 *
 * `convidar()` roda dentro do tenant de quem convida (o funcionário
 * autenticado, guard `web`), então `company_id` sai sozinho de
 * `TenantAtual::id()` na criação. Os demais métodos recebem o `ClientUser` já
 * carregado ou o localizam pelo token/e-mail sem escopo de empresa
 * (`deTodasAsEmpresas()`, porque quem chama ainda não tem tenant resolvido) e
 * então abrem `TenantAtual::comTenant()` com o `company_id` do próprio
 * registro antes de gravar qualquer coisa. Em nenhum lugar desta classe o
 * tenant vem de um parâmetro solto.
 *
 * ## E-mail repetido entre empresas
 *
 * `client_users.email` é único por empresa, não globalmente (a mesma pessoa
 * pode ser cliente de duas empresas da plataforma - ver o cabeçalho de
 * `ClientUser`). `solicitarRecuperacaoSenha()` busca por todas as empresas de
 * propósito, e manda um link por conta ativa encontrada: a pessoa pode
 * precisar recuperar a senha de mais de um portal com o mesmo e-mail, e nada
 * aqui obriga escolher qual.
 */
class ClientUserService
{
    /**
     * Validade do token, em dias corridos a partir de agora.
     *
     * `convite_expira_em` é um instante (`datetime`), não um dia (`date`): a
     * contagem é por horas corridas, sem relação com o fuso do negócio (ver a
     * skill de datas e timezone - campo de validade sem significado de "dia
     * civil" usa o instante puro).
     */
    public const DIAS_DE_VALIDADE = 7;

    private const MOTIVO_TOKEN_INVALIDO = 'invalido';

    private const MOTIVO_TOKEN_EXPIRADO = 'expirado';

    /**
     * Mensagem de cada motivo de recusa do token. Nenhuma delas diferencia
     * "nunca existiu" de "já foi usado": as duas parecem exatamente iguais no
     * banco (`convite_token` nulo), e mesmo que não parecessem, a tela pública
     * de definir senha não é lugar para revelar detalhe do estado de uma
     * conta.
     *
     * @var array<string, string>
     */
    private const MENSAGENS = [
        self::MOTIVO_TOKEN_INVALIDO => 'Este link não é mais válido. Ele já pode ter sido usado, ou o acesso foi desativado. '
            .'Peça um convite novo ou use "Esqueci minha senha" caso já tenha acesso.',
        self::MOTIVO_TOKEN_EXPIRADO => 'Este link expirou. Peça um convite novo ou use "Esqueci minha senha" para gerar outro.',
    ];

    public function __construct(
        private readonly NotificationService $notificacoes,
    ) {}

    /**
     * Cria o acesso do cliente ao portal e enfileira o e-mail de convite.
     *
     * `$dados` espera `nome` e `email`. O `$cliente` precisa pertencer à
     * empresa corrente: com o escopo global de `Client` ativo, um model de
     * outra empresa não deveria chegar até aqui vindo de route model binding,
     * mas o Service não assume o framework por trás dele (pode ser chamado de
     * um comando ou de outro Service amanhã), e por isso a checagem é
     * explícita.
     *
     * @param  array{nome?: string, email?: string}  $dados
     *
     * @throws InvalidArgumentException Cliente não persistido, de outra empresa, ou dados obrigatórios ausentes.
     * @throws RuntimeException Já existe um acesso de portal com este e-mail nesta empresa.
     */
    public function convidar(Client $cliente, array $dados): ClientUser
    {
        $this->exigirClienteDaEmpresaCorrente($cliente);

        $nome = trim((string) ($dados['nome'] ?? ''));
        $email = $this->normalizarEmail((string) ($dados['email'] ?? ''));

        if ($nome === '') {
            throw new InvalidArgumentException('Informe o nome do cliente para convidar o acesso ao portal.');
        }

        if ($email === '') {
            throw new InvalidArgumentException('Informe o e-mail do cliente para convidar o acesso ao portal.');
        }

        $this->recusarEmailJaConvidado($email);

        $clientUser = ClientUser::create([
            'client_id' => $cliente->id,
            'nome' => $nome,
            'email' => $email,
            'ativo' => true,
            'convite_token' => Str::random(64),
            'convite_expira_em' => $this->validade(),
        ]);

        $this->enviarConvite($clientUser);

        return $clientUser;
    }

    /**
     * Valida o token (existência, validade de 7 dias e conta ativa), grava a
     * senha em hash, marca `email_verificado_em` e invalida o token.
     *
     * Serve tanto para o primeiro acesso (token nascido em `convidar()`)
     * quanto para a recuperação (token nascido em
     * `solicitarRecuperacaoSenha()`): as duas colunas envolvidas são as
     * mesmas, e o consumo é idêntico.
     *
     * A releitura com `lockForUpdate()` dentro da transação serializa duas
     * submissões do mesmo link (duplo clique, duas abas): a segunda encontra
     * o token já nulo e recebe a mensagem de link inválido, em vez de gravar a
     * senha duas vezes.
     *
     * @throws RuntimeException Token inexistente, de conta inativa, ou expirado.
     */
    public function definirSenha(string $token, string $senha): ClientUser
    {
        $clientUser = $this->localizarPeloToken($token);

        $this->recusarTokenInvalido($clientUser);

        return TenantAtual::comTenant((int) $clientUser->company_id, function () use ($clientUser, $senha): ClientUser {
            return DB::transaction(function () use ($clientUser, $senha): ClientUser {
                $atual = ClientUser::query()->whereKey($clientUser->id)->lockForUpdate()->first();

                $this->recusarTokenInvalido($atual);

                // Atribuição direta, não `update()`: `email_verificado_em`
                // não é fillable de propósito (ver o docblock de ClientUser -
                // "gravado pelo próprio fluxo de autenticação, nunca a partir
                // de um formulário"). Mass assignment o ignoraria em silêncio.
                $atual->password = $senha;
                $atual->email_verificado_em ??= now();
                $atual->convite_token = null;
                $atual->convite_expira_em = null;
                $atual->save();

                return $atual->fresh();
            });
        });
    }

    /**
     * Gera um token novo, estende o prazo e manda o e-mail de convite outra
     * vez. O token anterior morre aqui: quem tiver o link antigo (e-mail
     * perdido, encaminhamento) deixa de conseguir usá-lo.
     */
    public function reenviarConvite(ClientUser $clientUser): ClientUser
    {
        return TenantAtual::comTenant((int) $clientUser->company_id, function () use ($clientUser): ClientUser {
            $clientUser->update([
                'convite_token' => Str::random(64),
                'convite_expira_em' => $this->validade(),
            ]);

            $clientUser = $clientUser->fresh();

            $this->enviarConvite($clientUser);

            return $clientUser;
        });
    }

    /**
     * Gera um token de recuperação para toda conta ativa com este e-mail, em
     * qualquer empresa, e manda o e-mail de redefinição para cada uma.
     *
     * Sem exceção e sem retorno informativo de propósito: a rota pública de
     * "esqueci minha senha" precisa devolver a mesma resposta exista ou não
     * conta com este e-mail, do contrário o formulário vira um jeito de
     * descobrir e-mail de cliente cadastrado.
     */
    public function solicitarRecuperacaoSenha(string $email): void
    {
        $email = $this->normalizarEmail($email);

        if ($email === '') {
            return;
        }

        // Sem escopo de empresa: quem pede recuperação ainda não está
        // autenticado, não tem tenant resolvido, e pode ser cliente de mais de
        // uma empresa com o mesmo e-mail (ver o cabeçalho da classe).
        $contas = ClientUser::query()
            ->deTodasAsEmpresas()
            ->where('email', $email)
            ->where('ativo', true)
            ->get();

        foreach ($contas as $clientUser) {
            TenantAtual::comTenant((int) $clientUser->company_id, function () use ($clientUser): void {
                $clientUser->update([
                    'convite_token' => Str::random(64),
                    'convite_expira_em' => $this->validade(),
                ]);

                $this->enviarRecuperacao($clientUser->fresh());
            });
        }
    }

    /**
     * Empresa dona do token informado, para a tela pública de definir senha
     * mostrar a marca do portal (Plano 15, Task 15.6) antes mesmo de validar
     * o token.
     *
     * Deliberadamente não confere validade nem `ativo` aqui: mostrar a marca
     * certa é conveniência de UX para quem já tem o link genuíno na mão (o
     * token de 64 caracteres não é adivinhável por tentativa), não uma
     * verificação de segurança - essa continua inteira em
     * {@see self::definirSenha()}, que é quem decide se o formulário pode ser
     * submetido. `null` para token inexistente, em branco, ou sem empresa
     * associada.
     */
    public function empresaDoToken(string $token): ?Company
    {
        return $this->localizarPeloToken($token)?->company;
    }

    /**
     * Desativa o acesso. `AutenticarCliente` confere `ativo` a cada
     * requisição, então a próxima requisição do cliente já cai fora, sem
     * esperar a sessão expirar.
     */
    public function desativar(ClientUser $clientUser): void
    {
        $clientUser->update(['ativo' => false]);
    }

    public function reativar(ClientUser $clientUser): void
    {
        $clientUser->update(['ativo' => true]);
    }

    /**
     * Quem convida precisa pertencer à empresa do cliente convidado.
     *
     * Em requisição normal isso nunca dispara: o `$cliente` chega por route
     * model binding, já escopado pelo `BelongsToCompany` do próprio model, e
     * `TenantAtual::id()` é o do funcionário autenticado. A checagem é a
     * defesa em profundidade para quando o Service for chamado de outro lugar
     * com um model carregado sem o escopo ativo.
     */
    private function exigirClienteDaEmpresaCorrente(Client $cliente): void
    {
        if (! $cliente->exists) {
            throw new InvalidArgumentException('Convite de portal exige um cliente já cadastrado.');
        }

        $tenant = TenantAtual::exigirId();

        if ((int) $cliente->company_id !== $tenant) {
            throw new InvalidArgumentException('O cliente informado não pertence à empresa corrente.');
        }
    }

    /**
     * `client_users` tem unique composto `[company_id, email]`: dentro da
     * empresa corrente (já resolvida por `TenantAtual` neste ponto), este
     * e-mail não pode gerar um segundo acesso. A consulta roda escopada
     * normalmente, então já enxerga só a empresa de quem convida.
     */
    private function recusarEmailJaConvidado(string $email): void
    {
        if (ClientUser::query()->where('email', $email)->exists()) {
            throw new RuntimeException('Já existe um acesso de portal com este e-mail nesta empresa.');
        }
    }

    private function localizarPeloToken(string $token): ?ClientUser
    {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        return ClientUser::query()->deTodasAsEmpresas()->where('convite_token', $token)->first();
    }

    /**
     * @throws RuntimeException
     */
    private function recusarTokenInvalido(?ClientUser $clientUser): void
    {
        $motivo = $this->motivoDeRecusa($clientUser);

        if ($motivo !== null) {
            throw new RuntimeException(self::MENSAGENS[$motivo]);
        }
    }

    private function motivoDeRecusa(?ClientUser $clientUser): ?string
    {
        if ($clientUser === null || ! $clientUser->ativo) {
            return self::MOTIVO_TOKEN_INVALIDO;
        }

        if ($clientUser->convite_expira_em === null) {
            return self::MOTIVO_TOKEN_INVALIDO;
        }

        if ($clientUser->convite_expira_em->isPast()) {
            return self::MOTIVO_TOKEN_EXPIRADO;
        }

        return null;
    }

    /**
     * Enfileira o e-mail de convite (primeiro acesso).
     *
     * `destino`, `destinatario_id` e `canal` são forçados: o destinatário é
     * este `ClientUser` específico, nunca o e-mail geral do `Client` (ver o
     * comentário do evento em `EventosDeNotificacao`). `marco` leva o próprio
     * token, para que reenviar o convite (token novo) gere um item novo na
     * fila em vez de "duplicada" - o link antigo, na chave anterior, já não
     * serve para nada mesmo.
     */
    private function enviarConvite(ClientUser $clientUser): void
    {
        $this->notificacoes->enfileirar(EventosDeNotificacao::CONVITE_PORTAL, $clientUser, [
            'canal' => EventosDeNotificacao::CANAL_EMAIL,
            'destino' => $clientUser->email,
            'destinatario_id' => $clientUser->id,
            'marco' => $clientUser->convite_token,
            'variaveis' => [
                'link_convite' => $this->linkDefinirSenha($clientUser->convite_token),
                'dias_de_validade' => self::DIAS_DE_VALIDADE,
            ],
        ]);
    }

    /**
     * Enfileira o e-mail de recuperação de senha. Mesmo raciocínio de
     * `enviarConvite()` para os overrides e para o `marco`.
     */
    private function enviarRecuperacao(ClientUser $clientUser): void
    {
        $this->notificacoes->enfileirar(EventosDeNotificacao::RECUPERACAO_SENHA_PORTAL, $clientUser, [
            'canal' => EventosDeNotificacao::CANAL_EMAIL,
            'destino' => $clientUser->email,
            'destinatario_id' => $clientUser->id,
            'marco' => $clientUser->convite_token,
            'variaveis' => [
                'link_redefinir_senha' => $this->linkDefinirSenha($clientUser->convite_token),
                'dias_de_validade' => self::DIAS_DE_VALIDADE,
            ],
        ]);
    }

    private function linkDefinirSenha(string $token): string
    {
        return route('portal.senha.definir', ['token' => $token]);
    }

    private function validade(): CarbonImmutable
    {
        return CarbonImmutable::now()->addDays(self::DIAS_DE_VALIDADE);
    }

    private function normalizarEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
