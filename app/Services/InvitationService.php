<?php

namespace App\Services;

use App\Mail\ConviteDeUsuario;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Convite de um usuário para entrar na empresa (Plano 8, Task 8.4).
 *
 * O convite existe porque a alternativa é o administrador criar o usuário e
 * combinar a senha por WhatsApp. Aqui quem entra escolhe a própria senha, por
 * um link com prazo, e o papel dele já vem decidido por quem convidou.
 *
 * ## As duas regras que este Service existe para garantir
 *
 * 1. **O papel vem do convite, nunca de quem aceita.** O formulário público
 *    pede nome e senha, e mais nada. Deixar o convidado escolher o papel
 *    entrega `administrador` para qualquer um que receba (ou intercepte) um
 *    link.
 *
 * 2. **O usuário criado nasce na empresa do convite.** A rota de aceite é
 *    pública: não há usuário autenticado, e portanto `TenantAtual::id()` é
 *    nulo ali. Sem `comTenant()`, `BelongsToCompany` não teria tenant para
 *    preencher e o usuário nasceria sem empresa (ou, pior, na empresa de quem
 *    estivesse logado por acaso naquele navegador). Toda escrita de
 *    `aceitar()` acontece dentro de
 *    `TenantAtual::comTenant($convite->company_id, ...)`, no mesmo padrão de
 *    `ProvisionamentoService`.
 *
 * ## Token
 *
 * Gerado por `Invitation::booted()` com `Str::random(64)`, único no banco, e
 * de **uso único**: `aceitar()` grava `aceito_em`, e a partir daí o mesmo link
 * é recusado. Ele é a credencial inteira de quem aceita, então a busca por
 * token é a única consulta desta classe que roda sem escopo de empresa (ver
 * `localizarPeloToken()`).
 *
 * ## Limite do plano
 *
 * A checagem de `LimitesDoPlanoService::podeCriar('usuarios')` acontece em
 * `convidar()`, e **não** em `aceitar()`. Convidar cinco pessoas e recusar a
 * quinta na hora em que ela escolhe a senha é a pior experiência possível: o
 * erro aparece para quem não pode resolvê-lo. Em compensação, `aceitar()` cria
 * o usuário direto, sem passar por `UserService::criar()`, que reavaliaria o
 * teto (mesmo motivo pelo qual `ProvisionamentoService` também cria o
 * administrador do tenant por conta própria). O efeito colateral é conhecido e
 * aceito: convites emitidos abaixo do teto podem, todos aceitos, passar dele
 * por pouco, e o aviso de limite do Plano 6 é quem mostra isso ao
 * administrador.
 *
 * ## E-mail
 *
 * O envio usa o `Mailer` do Laravel com um Mailable simples. **PONTO DE TROCA
 * (Plano 14):** quando a central de notificações estiver de pé, `enviarEmail()`
 * passa a enfileirar um evento `convite_enviado` em `NotificationService` e
 * `App\Mail\ConviteDeUsuario` deixa de ser usado. É o único método desta classe
 * que precisa mudar.
 *
 * Falha de envio nunca derruba o convite: o registro fica gravado e o link pode
 * ser copiado da tela de convites (Task 8.8). E-mail de convite parando em
 * antispam de servidor corporativo é rotina, e o convite ficaria irrecuperável
 * se o envio fosse condição para gravar.
 */
class InvitationService
{
    /**
     * Prazo padrão do convite, em dias contados a partir de hoje no fuso do
     * negócio.
     *
     * Convite sem prazo é porta de entrada aberta para sempre em um e-mail
     * vazado. Sete dias é curto o bastante para a janela de risco ser pequena
     * e longo o bastante para atravessar um fim de semana e umas férias curtas.
     */
    public const DIAS_DE_VALIDADE = 7;

    private const MOTIVO_INVALIDO = 'invalido';

    private const MOTIVO_EXPIRADO = 'expirado';

    private const MOTIVO_ACEITO = 'aceito';

    private const MOTIVO_CANCELADO = 'cancelado';

    /**
     * Mensagem de cada desfecho que impede o aceite.
     *
     * Nenhuma delas diz se o e-mail do convite já existe no sistema, e isso é
     * deliberado: a tela do aceite é pública, e quem estiver testando links
     * alheios não pode sair dali sabendo quais endereços têm conta. As quatro
     * apontam para a mesma saída prática, que é pedir um convite novo.
     *
     * @var array<string, string>
     */
    private const MENSAGENS = [
        self::MOTIVO_INVALIDO => 'Este convite não é válido. Confira se o link foi copiado inteiro ou peça um convite novo ao administrador da empresa.',
        self::MOTIVO_EXPIRADO => 'Este convite expirou. Peça um convite novo ao administrador da empresa.',
        self::MOTIVO_ACEITO => 'Este convite já foi usado. Se o acesso é seu, entre pelo login ou recupere a senha.',
        self::MOTIVO_CANCELADO => 'Este convite foi cancelado. Peça um convite novo ao administrador da empresa.',
    ];

    public function __construct(
        private readonly LimitesDoPlanoService $limitesDoPlano,
        private readonly Mailer $mailer,
    ) {}

    /**
     * Convida um e-mail para a empresa, com o papel já definido.
     *
     * Ordem das recusas, do mais barato para o mais caro, porque todas elas
     * produzem o mesmo tipo de resposta e não faz sentido pagar a consulta de
     * uso do plano para descobrir depois que o e-mail já tinha dono:
     *
     * 1. Papel inexistente (consulta de uma linha em `roles`).
     * 2. E-mail já cadastrado em `users`.
     * 3. Convite pendente para o mesmo e-mail nesta empresa.
     * 4. Limite de usuários do plano (apura o uso inteiro do tenant, ver
     *    `TenantUsageService`).
     *
     * @throws InvalidArgumentException Quando a empresa não está persistida ou quem convida é de outra empresa.
     * @throws RuntimeException Quando alguma das quatro recusas acima se aplica.
     */
    public function convidar(Company $empresa, string $email, string $papel, ?string $nome, User $convidadoPor): Invitation
    {
        $this->exigirEmpresaPersistida($empresa);
        $this->exigirConvidadoPorDaEmpresa($empresa, $convidadoPor);

        $email = $this->normalizarEmail($email);
        $papel = trim($papel);
        $nome = $this->normalizarNome($nome);

        return TenantAtual::comTenant((int) $empresa->id, function () use ($empresa, $email, $papel, $nome, $convidadoPor): Invitation {
            $this->exigirPapelExistente($papel);
            $this->recusarEmailJaCadastrado($email);
            $this->recusarConvitePendente($email);
            $this->recusarSeEstourarLimiteDeUsuarios($empresa);

            // `company_id` sai da trait `BelongsToCompany` a partir do tenant
            // aberto acima, e não do array: é o mesmo caminho de todo registro
            // de domínio do sistema.
            $convite = Invitation::query()->create([
                'email' => $email,
                'nome' => $nome,
                'papel' => $papel,
                'convidado_por' => $convidadoPor->id,
                'expira_em' => $this->validadePadrao(),
            ]);

            $this->enviarEmail($convite, $empresa);

            return $convite;
        });
    }

    /**
     * Cria o usuário de quem aceitou o convite e encerra o convite.
     *
     * O nome e a senha são os únicos dados que vêm de quem aceita. E-mail,
     * papel e empresa vêm do convite, sempre.
     *
     * A transação relê o convite com `lockForUpdate()` antes de gravar: dois
     * cliques no botão, ou o mesmo link aberto em duas abas, chegariam aqui em
     * paralelo e criariam dois usuários com o mesmo convite. Com o lock, o
     * segundo espera, encontra `aceito_em` preenchido e recebe a mensagem de
     * convite já usado.
     *
     * @throws RuntimeException Quando o token não serve mais, quando o e-mail já virou usuário ou quando o papel do convite deixou de existir.
     */
    public function aceitar(string $token, string $nome, string $senha): User
    {
        $convite = $this->localizarPeloToken($token);

        $this->recusarConviteInutilizavel($convite);

        return TenantAtual::comTenant(
            (int) $convite->company_id,
            fn (): User => DB::transaction(function () use ($convite, $nome, $senha): User {
                // Releitura dentro da transação, já escopada pela empresa do
                // convite: é este SELECT ... FOR UPDATE que serializa dois
                // aceites simultâneos do mesmo link.
                $atual = Invitation::query()->whereKey($convite->id)->lockForUpdate()->first();

                $this->recusarConviteInutilizavel($atual);

                // O e-mail pode ter virado usuário entre o convite e o aceite
                // (cadastro manual, outro convite aceito antes). `users.email`
                // é unique global, então sem esta checagem o desfecho seria uma
                // violação de chave crua na cara de quem está se cadastrando.
                if ($this->emailJaCadastrado($atual->email)) {
                    throw new RuntimeException(
                        'Já existe um acesso com este e-mail. Entre pelo login ou use a recuperação de senha.'
                    );
                }

                $usuario = User::create([
                    'name' => $this->normalizarNome($nome) ?? $atual->nome ?? $atual->email,
                    'email' => $atual->email,
                    // O cast `hashed` de `User` cuida do hash.
                    'password' => $senha,
                    'is_active' => true,
                ]);

                $this->atribuirPapelDoConvite($usuario, (string) $atual->papel);

                $atual->update(['aceito_em' => now()]);

                return $usuario->fresh();
            })
        );
    }

    /**
     * Cancela um convite pendente. O link para de funcionar na hora.
     *
     * Convite já cancelado não faz nada: cancelar duas vezes é a mesma coisa
     * que cancelar uma. Convite já aceito recusa, porque o que existe do outro
     * lado agora é um usuário, e tirar o acesso dele é desativar o usuário, não
     * mexer no convite.
     *
     * @throws RuntimeException Quando o convite já foi aceito.
     */
    public function cancelar(Invitation $convite): void
    {
        if ($convite->aceito_em !== null) {
            throw new RuntimeException(
                'Este convite já foi aceito e não pode ser cancelado. Para tirar o acesso, desative o usuário em Configurações.'
            );
        }

        if ($convite->cancelado_em !== null) {
            return;
        }

        $convite->update(['cancelado_em' => now()]);
    }

    /**
     * Gera um token novo, estende o prazo e manda o e-mail de novo.
     *
     * O token antigo morre aqui: quem tiver o link anterior (o e-mail que se
     * perdeu, o encaminhamento que foi parar em outra caixa) deixa de conseguir
     * entrar. É o comportamento desejado para "reenviar".
     *
     * Vale para convite expirado também, que é o caso mais comum de uso: o
     * convite venceu, e reenviar é mais direto do que apagar e recriar. Por
     * isso as duas checagens de duplicidade se repetem aqui, e não só em
     * `convidar()`: entre o convite original e o reenvio, o e-mail pode ter
     * virado usuário ou ganhado outro convite pendente.
     *
     * Não reavalia o limite de usuários do plano, de propósito: reenviar não
     * acrescenta convite nenhum, só mantém vivo um que já foi autorizado.
     *
     * @throws RuntimeException Quando o convite já foi aceito ou cancelado, ou quando o e-mail deixou de estar convidável.
     */
    public function reenviar(Invitation $convite): Invitation
    {
        if ($convite->aceito_em !== null) {
            throw new RuntimeException('Este convite já foi aceito. Não há o que reenviar.');
        }

        if ($convite->cancelado_em !== null) {
            throw new RuntimeException('Este convite foi cancelado. Crie um convite novo para este e-mail.');
        }

        return TenantAtual::comTenant((int) $convite->company_id, function () use ($convite): Invitation {
            $this->recusarEmailJaCadastrado((string) $convite->email);
            $this->recusarConvitePendente((string) $convite->email, (int) $convite->id);

            $convite->update([
                'token' => Str::random(64),
                'expira_em' => $this->validadePadrao(),
            ]);

            $empresa = Company::query()->find($convite->company_id);

            if ($empresa !== null) {
                $this->enviarEmail($convite, $empresa);
            }

            return $convite->fresh();
        });
    }

    /**
     * O que a tela pública de aceite precisa saber sobre um token.
     *
     * Existe para o `show` do controller não precisar consultar `Invitation`
     * por conta própria (e, ao fazer isso, repetir a decisão de rodar a busca
     * sem escopo de empresa, que é a parte sensível). Devolve sempre um array
     * do mesmo formato, com `valido` dizendo se o formulário deve aparecer.
     *
     * Nunca devolve o papel nem o nome da empresa de um convite recusado: o
     * link que não vale mais não é fonte de informação sobre a empresa.
     *
     * @return array{token: string, valido: bool, mensagem: string|null, empresa: string|null, papel: string|null, email: string|null, nome: string|null, expira_em: string|null}
     */
    public function situacaoDoToken(string $token): array
    {
        $convite = $this->localizarPeloToken($token);
        $motivo = $this->motivoDeRecusa($convite);

        if ($motivo !== null) {
            return [
                'token' => $token,
                'valido' => false,
                'mensagem' => self::MENSAGENS[$motivo],
                'empresa' => null,
                'papel' => null,
                'email' => null,
                'nome' => null,
                'expira_em' => null,
            ];
        }

        // `Company` não usa `BelongsToCompany` (ela é o próprio tenant), então
        // a busca pelo id do convite é direta e não depende de escopo nenhum.
        $empresa = Company::query()->find($convite->company_id);

        return [
            'token' => $token,
            'valido' => true,
            'mensagem' => null,
            'empresa' => $empresa?->name,
            'papel' => (string) $convite->papel,
            'email' => (string) $convite->email,
            'nome' => $convite->nome,
            'expira_em' => BusinessDate::diaDe($convite->expira_em),
        ];
    }

    /**
     * Convite de um token, sem escopo de empresa.
     *
     * ESTA É A ÚNICA CONSULTA SEM ESCOPO DESTA CLASSE, e o motivo precisa
     * ficar explícito: a rota de aceite é pública, não tem usuário autenticado
     * e portanto não tem tenant resolvido. Com o escopo ligado a consulta já
     * não filtraria nada (ver `BelongsToCompany`), mas passaria a filtrar no
     * dia em que alguém abrisse o link de convite de outra empresa estando
     * logado nesta, e o aceite legítimo viraria "convite inválido".
     *
     * A saída de escopo não abre vazamento: o filtro é igualdade contra um
     * token de 64 caracteres aleatórios, ou seja, só encontra o convite de quem
     * já tem o token em mãos. Todo o resto do aceite volta para dentro do
     * tenant do convite, com `comTenant()`.
     */
    private function localizarPeloToken(string $token): ?Invitation
    {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        return Invitation::query()->deTodasAsEmpresas()->where('token', $token)->first();
    }

    /**
     * Motivo pelo qual este convite não pode ser aceito, ou `null` quando ele
     * está pendente e válido.
     *
     * A ordem importa para a mensagem: um convite aceito há dois meses também
     * está expirado, e dizer "expirou" para quem já usou o link mandaria a
     * pessoa pedir um convite novo em vez de ir para o login.
     */
    private function motivoDeRecusa(?Invitation $convite): ?string
    {
        if ($convite === null) {
            return self::MOTIVO_INVALIDO;
        }

        if ($convite->aceito_em !== null) {
            return self::MOTIVO_ACEITO;
        }

        if ($convite->cancelado_em !== null) {
            return self::MOTIVO_CANCELADO;
        }

        if ($convite->estaExpirado()) {
            return self::MOTIVO_EXPIRADO;
        }

        return null;
    }

    /**
     * @phpstan-assert Invitation $convite
     *
     * @throws RuntimeException
     */
    private function recusarConviteInutilizavel(?Invitation $convite): void
    {
        $motivo = $this->motivoDeRecusa($convite);

        if ($motivo !== null) {
            throw new RuntimeException(self::MENSAGENS[$motivo]);
        }
    }

    /**
     * Dia em que o convite criado (ou reenviado) agora deixa de valer.
     *
     * Contado por dia no fuso do negócio, nunca por instante: `expira_em` é
     * campo `date`, e `Invitation::estaExpirado()` compara dia contra dia. Um
     * convite criado às 23h de Brasília precisa valer os sete dias inteiros, e
     * não seis dias e uma hora, que é o que sairia de um `now()` em UTC.
     */
    private function validadePadrao(): string
    {
        return BusinessDate::hoje()->addDays(self::DIAS_DE_VALIDADE)->toDateString();
    }

    /**
     * O papel precisa existir antes de o convite ser gravado.
     *
     * Papel só é atribuído lá no aceite, e sem esta guarda um erro de digitação
     * no convite só apareceria para o convidado, na forma de uma exceção do
     * Spatie no meio do cadastro dele.
     */
    private function exigirPapelExistente(string $papel): void
    {
        if ($papel === '' || ! Role::query()->where('name', $papel)->exists()) {
            throw new RuntimeException("O papel \"{$papel}\" não existe. Escolha um papel válido para o convite.");
        }
    }

    /**
     * Recusa o convite quando o e-mail já tem usuário no sistema.
     *
     * A consulta é global de propósito: `users.email` é unique global (o login
     * é por e-mail, exceção deliberada registrada na skill de multiempresa),
     * então um e-mail que já existe em OUTRA empresa também não pode virar
     * usuário aqui. A mensagem não diz onde ele existe.
     */
    private function recusarEmailJaCadastrado(string $email): void
    {
        if ($this->emailJaCadastrado($email)) {
            throw new RuntimeException('Já existe um usuário com este e-mail no sistema.');
        }
    }

    private function emailJaCadastrado(string $email): bool
    {
        // `User` não tem escopo global de leitura (ver o próprio model), então
        // esta consulta enxerga todas as empresas, que é o comportamento certo
        // para uma coluna com unique global.
        return User::query()->where('email', $this->normalizarEmail($email))->exists();
    }

    /**
     * Recusa o segundo convite pendente para o mesmo e-mail na mesma empresa.
     *
     * A regra não é unique no banco porque MySQL não faz unique parcial (ver o
     * cabeçalho da migration): dois convites para o mesmo e-mail são legítimos
     * quando o primeiro expirou, foi cancelado ou já foi aceito. É esta
     * checagem, dentro do tenant aberto por quem chamou, que segura o caso de
     * verdade.
     *
     * `$ignorando` existe para o reenvio, que precisa desconsiderar o próprio
     * convite ao perguntar se já existe outro pendente.
     */
    private function recusarConvitePendente(string $email, ?int $ignorando = null): void
    {
        $consulta = Invitation::query()->pendentes()->where('email', $this->normalizarEmail($email));

        if ($ignorando !== null) {
            $consulta->whereKeyNot($ignorando);
        }

        if ($consulta->exists()) {
            throw new RuntimeException(
                'Já existe um convite pendente para este e-mail. Reenvie o convite existente ou cancele-o antes de criar outro.'
            );
        }
    }

    /**
     * Recusa o convite quando a empresa já está no teto de usuários do plano.
     *
     * Bloqueio na origem, e não no aceite: ver o cabeçalho da classe. A
     * mensagem repete o formato de `UserService`, que é o outro lugar do
     * sistema onde este mesmo teto barra alguém.
     */
    private function recusarSeEstourarLimiteDeUsuarios(Company $empresa): void
    {
        if ($this->limitesDoPlano->podeCriar('usuarios', $empresa)) {
            return;
        }

        $empresa->loadMissing('plan');

        throw new RuntimeException(sprintf(
            'O plano %s permite no máximo %d usuário(s) ativos, e esse limite já foi atingido. Fale com o suporte para ampliar o plano antes de convidar mais alguém.',
            $empresa->plan?->nome ?? 'atual',
            $empresa->plan?->limite_usuarios
        ));
    }

    /**
     * Atribui ao usuário novo o papel gravado no convite.
     *
     * O papel foi conferido em `convidar()`, mas o convite tem sete dias de
     * vida e o catálogo de papéis é global: nada impede que ele seja removido
     * nesse meio-tempo. Sem este tratamento, o desfecho seria a exceção crua do
     * Spatie no meio do cadastro de quem aceitou.
     */
    private function atribuirPapelDoConvite(User $usuario, string $papel): void
    {
        try {
            $usuario->assignRole($papel);
        } catch (RoleDoesNotExist) {
            throw new RuntimeException(
                'O papel definido neste convite não existe mais. Peça um convite novo ao administrador da empresa.'
            );
        }
    }

    /**
     * Manda o e-mail do convite.
     *
     * PONTO DE TROCA (Plano 14): é este método que a central de notificações
     * substitui, trocando o envio direto pelo enfileiramento do evento. Nada
     * mais desta classe conhece `Mail` nem Mailable.
     *
     * Nenhuma exceção sai daqui. Provedor fora do ar, credencial de SMTP
     * errada, domínio recusando o remetente: em todos esses casos o convite já
     * está gravado, e o administrador copia o link da tela de convites. Perder
     * o convite porque o e-mail não saiu seria trocar um problema contornável
     * por um irreversível.
     */
    private function enviarEmail(Invitation $convite, Company $empresa): void
    {
        try {
            $remetente = $this->remetente($empresa);

            if ($remetente === null) {
                // Nem a empresa nem a plataforma têm endereço de envio
                // configurado. Não há e-mail possível, e o link continua
                // disponível na tela.
                return;
            }

            $mensagem = new ConviteDeUsuario(
                nomeDaEmpresa: $remetente['nome'],
                papel: (string) $convite->papel,
                link: route('convite.show', ['token' => $convite->token]),
                validade: (string) BusinessDate::paraFusoNegocio($convite->expira_em)?->format('d/m/Y'),
                remetenteEmail: $remetente['email'],
                remetenteNome: $remetente['nome'],
                responderPara: $remetente['responder_para'],
                nomeDoConvidado: $convite->nome,
            );

            $this->mailer->send($mensagem->to((string) $convite->email));
        } catch (Throwable $erro) {
            report($erro);
        }
    }

    /**
     * Remetente e endereço de resposta desta empresa.
     *
     * Mesma regra de `App\Services\Notification\DriverDeEmail::remetente()`, e
     * repetida aqui de propósito: aquele método é privado do driver da central
     * de notificações, e o convite sai por um caminho que ainda não passa por
     * ela. As duas cópias se encontram no Plano 14, quando `enviarEmail()`
     * passar a enfileirar o evento em vez de enviar.
     *
     * O nome exibido é sempre o da empresa: quem recebe o convite conhece a
     * dedetizadora, não a plataforma.
     *
     * @return array{email: string, nome: string, responder_para: ?string}|null
     */
    private function remetente(Company $empresa): ?array
    {
        $doTenant = trim((string) $empresa->email);
        $daPlataforma = trim((string) config('mail.from.address'));
        $endereco = $doTenant !== '' ? $doTenant : $daPlataforma;

        if ($endereco === '') {
            return null;
        }

        $nome = trim((string) $empresa->name);

        return [
            'email' => $endereco,
            'nome' => $nome !== '' ? $nome : (string) config('app.name'),
            'responder_para' => $doTenant !== '' ? $doTenant : null,
        ];
    }

    private function normalizarEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function normalizarNome(?string $nome): ?string
    {
        $nome = trim((string) $nome);

        return $nome === '' ? null : $nome;
    }

    /**
     * A empresa precisa existir no banco para ser tenant de alguma coisa.
     *
     * Sem esta guarda, `comTenant()` receberia `null` e o convite nasceria sem
     * empresa definida, que é a falha que todo este arquivo existe para
     * impedir.
     */
    private function exigirEmpresaPersistida(Company $empresa): void
    {
        if (! $empresa->exists || $empresa->id === null) {
            throw new InvalidArgumentException(
                'Convite exige uma empresa já persistida: crie o tenant antes de convidar alguém para ele.'
            );
        }
    }

    /**
     * Quem convida precisa ser da empresa para a qual está convidando.
     *
     * Em requisição normal os dois vêm do mesmo lugar (o usuário autenticado e
     * o tenant dele), então esta checagem nunca dispara. Ela existe para o dia
     * em que o Service for chamado de outro contexto: `convidado_por` de outra
     * empresa gravaria, no histórico do tenant, um convite atribuído a alguém
     * de fora dele.
     *
     * O super admin é a exceção declarada, e a mesma do resto do sistema: ele
     * não pertence a empresa nenhuma, e em modo suporte (Task 5.5) opera dentro
     * do tenant assumido. Sem esta saída, convidar alguém enquanto dá suporte a
     * um cliente estouraria aqui, e o histórico perderia justamente o registro
     * de que foi a plataforma quem convidou.
     */
    private function exigirConvidadoPorDaEmpresa(Company $empresa, User $convidadoPor): void
    {
        if (($convidadoPor->is_platform_admin ?? null) === true) {
            return;
        }

        if ((int) $convidadoPor->company_id !== (int) $empresa->id) {
            throw new InvalidArgumentException(
                'Quem convida precisa pertencer à empresa do convite.'
            );
        }
    }
}
