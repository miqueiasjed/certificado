<?php

namespace Tests\Feature;

use App\Mail\ConviteDeUsuario;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\User;
use App\Services\InvitationService;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 8.10 do Plano 8: os convites de usuário (Task 8.4).
 *
 * O caso que mais importa é
 * `test_usuario_criado_por_convite_enxerga_apenas_o_tenant_que_o_convidou`. O
 * convite é a única porta do sistema em que alguém de fora vira usuário de uma
 * empresa, e a rota de aceite é pública: não há sessão, não há tenant resolvido
 * e, sem o `comTenant()` do `InvitationService`, o usuário nasceria sem empresa
 * ou na empresa de quem estivesse logado por acaso naquele navegador. Convite
 * que vira porta de entrada em outro tenant é a falha mais grave possível aqui.
 *
 * Logo atrás vêm as quatro recusas de `convidar()` e as três de `aceitar()`.
 * Cada uma tem caso próprio porque cada uma protege coisa diferente: e-mail já
 * cadastrado protege a unique global de `users`, convite pendente duplicado
 * protege o histórico da empresa, o limite do plano protege a cobrança, e os
 * três estados de token protegem o link em circulação.
 */
class ConviteTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-forte-123';

    private Company $empresaUm;

    private Company $empresaDois;

    private User $administradorUm;

    private User $administradorDois;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        Mail::fake();

        // Papéis e permissões são globais (Spatie sem `teams`, decisão do Plano
        // 2): sem eles o convite não encontra papel válido e o aceite não
        // consegue atribuir nenhum.
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ModulesSeeder::class);

        $this->empresaUm = Company::create([
            'name' => 'Dedetizadora Um',
            'cnpj' => '11.111.111/0001-11',
            'email' => 'contato@um.test',
        ]);

        $this->empresaDois = Company::create([
            'name' => 'Dedetizadora Dois',
            'cnpj' => '22.222.222/0001-22',
            'email' => 'contato@dois.test',
        ]);

        $this->administradorUm = $this->criarUsuario($this->empresaUm, 'admin@um.test', 'administrador');
        $this->administradorDois = $this->criarUsuario($this->empresaDois, 'admin@dois.test', 'administrador');

        TenantAtual::limpar();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // 1. Convidar grava e envia
    // -----------------------------------------------------------------

    public function test_convidar_cria_o_convite_e_envia_o_email(): void
    {
        $resposta = $this->actingAs($this->administradorUm)->post('/settings/convites', [
            'email' => 'Novo.Tecnico@Um.test',
            'nome' => 'Novo Técnico',
            'papel' => 'tecnico',
        ]);

        $resposta->assertSessionHasNoErrors();
        $resposta->assertRedirect(route('settings.convites.index'));

        $convite = Invitation::query()->deTodasAsEmpresas()->firstOrFail();

        $this->assertSame('novo.tecnico@um.test', $convite->email, 'o e-mail é normalizado antes de gravar');
        $this->assertSame('Novo Técnico', $convite->nome);
        $this->assertSame('tecnico', $convite->papel);
        $this->assertSame($this->empresaUm->id, (int) $convite->company_id, 'o convite nasceu fora da empresa de quem convidou');
        $this->assertSame($this->administradorUm->id, (int) $convite->convidado_por);
        $this->assertSame(64, strlen((string) $convite->token), 'o token é a credencial inteira de quem aceita');
        $this->assertNull($convite->aceito_em);
        $this->assertNull($convite->cancelado_em);
        $this->assertSame(
            BusinessDate::hoje()->addDays(InvitationService::DIAS_DE_VALIDADE)->toDateString(),
            BusinessDate::diaDe($convite->expira_em),
            'o prazo é contado por dia no fuso do negócio, não por instante em UTC'
        );

        Mail::assertSent(
            ConviteDeUsuario::class,
            fn (ConviteDeUsuario $email): bool => $email->hasTo('novo.tecnico@um.test')
        );
    }

    // -----------------------------------------------------------------
    // 2. E-mail já cadastrado
    // -----------------------------------------------------------------

    public function test_convidar_email_ja_cadastrado_e_recusado(): void
    {
        // Pelo formulário, o erro sai no campo.
        $resposta = $this->actingAs($this->administradorUm)->post('/settings/convites', [
            'email' => $this->administradorUm->email,
            'papel' => 'tecnico',
        ]);

        $resposta->assertSessionHasErrors('email');

        // E o Service recusa também um e-mail que existe em OUTRA empresa:
        // `users.email` é unique global de propósito, porque o login é por
        // e-mail. Sem esta regra, o convite gravaria e o aceite estouraria na
        // cara de quem estivesse criando a senha.
        try {
            app(InvitationService::class)->convidar(
                $this->empresaUm,
                $this->administradorDois->email,
                'tecnico',
                null,
                $this->administradorUm
            );
            $this->fail('convidar um e-mail que já é usuário de outra empresa deveria ter sido recusado');
        } catch (RuntimeException $erro) {
            $this->assertSame('Já existe um usuário com este e-mail no sistema.', $erro->getMessage());
        }

        $this->assertSame(0, Invitation::query()->deTodasAsEmpresas()->count());
        Mail::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // 3. Convite pendente duplicado
    // -----------------------------------------------------------------

    public function test_convite_pendente_duplicado_e_recusado(): void
    {
        $servico = app(InvitationService::class);

        $servico->convidar($this->empresaUm, 'repetido@um.test', 'tecnico', null, $this->administradorUm);

        try {
            $servico->convidar($this->empresaUm, 'repetido@um.test', 'financeiro', null, $this->administradorUm);
            $this->fail('o segundo convite pendente para o mesmo e-mail deveria ter sido recusado');
        } catch (RuntimeException $erro) {
            $this->assertStringContainsString('convite pendente', $erro->getMessage());
        }

        $this->assertSame(1, Invitation::query()->deTodasAsEmpresas()->count());

        // A regra é um pendente por e-mail POR EMPRESA: a outra empresa
        // convida a mesma pessoa sem esbarrar em nada.
        $servico->convidar($this->empresaDois, 'repetido@um.test', 'tecnico', null, $this->administradorDois);

        $this->assertSame(2, Invitation::query()->deTodasAsEmpresas()->count());

        // Cancelado o primeiro, a empresa um pode convidar de novo: a regra vale
        // só para convite pendente.
        $servico->cancelar(
            Invitation::query()->deTodasAsEmpresas()->where('company_id', $this->empresaUm->id)->firstOrFail()
        );

        $servico->convidar($this->empresaUm, 'repetido@um.test', 'tecnico', null, $this->administradorUm);

        $this->assertSame(3, Invitation::query()->deTodasAsEmpresas()->count());
    }

    // -----------------------------------------------------------------
    // 4. Limite de usuários do plano
    // -----------------------------------------------------------------

    public function test_limite_de_usuarios_do_plano_estourado_impede_o_convite(): void
    {
        $plano = Plan::create([
            'nome' => 'Plano Apertado',
            'slug' => 'plano-apertado',
            'valor' => 100,
            'periodicidade' => 'mensal',
            'limite_usuarios' => 1,
            'ativo' => true,
        ]);

        // A empresa um já tem um usuário ativo (o administrador), então o teto
        // de 1 já está atingido antes de qualquer convite.
        $this->empresaUm->update(['plan_id' => $plano->id]);

        $resposta = $this->actingAs($this->administradorUm)->post('/settings/convites', [
            'email' => 'excedente@um.test',
            'papel' => 'tecnico',
        ]);

        $resposta->assertSessionHasErrors('error');

        $this->assertSame(0, Invitation::query()->deTodasAsEmpresas()->count(), 'o convite foi gravado apesar do limite estourado');
        Mail::assertNothingSent();

        // O bloqueio é do plano, não do sistema: a empresa dois, sem teto,
        // convida normalmente.
        app(InvitationService::class)->convidar(
            $this->empresaDois,
            'cabe@dois.test',
            'tecnico',
            null,
            $this->administradorDois
        );

        $this->assertSame(1, Invitation::query()->deTodasAsEmpresas()->count());
    }

    // -----------------------------------------------------------------
    // 5. Aceitar cria o usuário com o papel e a empresa do convite
    // -----------------------------------------------------------------

    public function test_aceitar_cria_o_usuario_com_o_papel_e_a_empresa_do_convite(): void
    {
        $convite = app(InvitationService::class)->convidar(
            $this->empresaUm,
            'convidado@um.test',
            'financeiro',
            'Convidado',
            $this->administradorUm
        );

        TenantAtual::limpar();

        // O formulário manda nome e senha. Papel e empresa vêm do convite, e
        // nada enviado aqui os altera: quem escolhesse o próprio papel viraria
        // administrador com um link em mãos.
        $resposta = $this->post(route('convite.aceitar', $convite->token), [
            'nome' => 'Convidado Aceito',
            'senha' => self::SENHA,
            'senha_confirmation' => self::SENHA,
            'papel' => 'administrador',
            'company_id' => $this->empresaDois->id,
        ]);

        $resposta->assertSessionHasNoErrors();
        $resposta->assertRedirect('/');

        $usuario = User::query()->where('email', 'convidado@um.test')->firstOrFail();

        $this->assertSame('Convidado Aceito', $usuario->name);
        $this->assertSame($this->empresaUm->id, (int) $usuario->company_id, 'o usuário nasceu fora da empresa do convite');
        $this->assertTrue($usuario->hasRole('financeiro'), 'o papel do usuário não veio do convite');
        $this->assertFalse($usuario->hasRole('administrador'), 'o papel enviado no formulário de aceite foi obedecido');
        $this->assertTrue((bool) $usuario->is_active);
        $this->assertFalse((bool) $usuario->is_platform_admin);
        $this->assertNotSame(self::SENHA, $usuario->password, 'a senha é gravada com hash');

        $this->assertAuthenticatedAs($usuario);
        $this->assertNotNull($convite->fresh()->aceito_em, 'o convite continuou pendente depois de aceito');
    }

    // -----------------------------------------------------------------
    // 6. Os três estados que recusam o aceite, um caso para cada
    // -----------------------------------------------------------------

    public function test_token_expirado_e_recusado(): void
    {
        $convite = app(InvitationService::class)->convidar(
            $this->empresaUm,
            'expirado@um.test',
            'tecnico',
            null,
            $this->administradorUm
        );

        $convite->update(['expira_em' => BusinessDate::hoje()->subDay()->toDateString()]);

        TenantAtual::limpar();

        $this->assertConviteRecusado($convite->token, 'expirado@um.test', 'expirou');
    }

    public function test_token_ja_usado_e_recusado(): void
    {
        $convite = app(InvitationService::class)->convidar(
            $this->empresaUm,
            'jausado@um.test',
            'tecnico',
            null,
            $this->administradorUm
        );

        TenantAtual::limpar();

        $this->post(route('convite.aceitar', $convite->token), [
            'nome' => 'Primeiro Uso',
            'senha' => self::SENHA,
            'senha_confirmation' => self::SENHA,
        ])->assertRedirect('/');

        $this->post('/logout');

        $usuarios = User::query()->where('email', 'jausado@um.test')->count();

        // A segunda tentativa com o mesmo link não cria um segundo usuário. A
        // mensagem manda para o login, e não para "peça outro convite": quem
        // já usou o link tem conta.
        $this->assertConviteRecusado($convite->fresh()->token, 'jausado@um.test', 'já foi usado', $usuarios);
    }

    public function test_token_cancelado_e_recusado(): void
    {
        $servico = app(InvitationService::class);

        $convite = $servico->convidar(
            $this->empresaUm,
            'cancelado@um.test',
            'tecnico',
            null,
            $this->administradorUm
        );

        $this->actingAs($this->administradorUm)
            ->delete(route('settings.convites.destroy', $convite->id))
            ->assertRedirect(route('settings.convites.index'));

        $this->assertNotNull($convite->fresh()->cancelado_em);

        $this->post('/logout');
        TenantAtual::limpar();

        $this->assertConviteRecusado($convite->token, 'cancelado@um.test', 'cancelado');
    }

    // -----------------------------------------------------------------
    // 7. As rotas de aceite não pedem login
    // -----------------------------------------------------------------

    public function test_rotas_de_aceite_funcionam_sem_autenticacao(): void
    {
        $convite = app(InvitationService::class)->convidar(
            $this->empresaUm,
            'sem-sessao@um.test',
            'tecnico',
            'Sem Sessão',
            $this->administradorUm
        );

        TenantAtual::limpar();

        // Nenhuma sessão aberta, nenhum tenant resolvido.
        $this->assertGuest();

        $this->get(route('convite.show', $convite->token))
            ->assertOk()
            ->assertInertia(fn ($pagina) => $pagina
                ->component('Auth/AceitarConvite')
                ->where('valido', true)
                ->where('empresa', 'Dedetizadora Um')
                ->where('papel', 'tecnico')
                ->where('email', 'sem-sessao@um.test'));

        // A tela pública de aceite não exige nem cria sessão.
        $this->assertGuest();

        $this->post(route('convite.aceitar', $convite->token), [
            'nome' => 'Sem Sessão',
            'senha' => self::SENHA,
            'senha_confirmation' => self::SENHA,
        ])->assertRedirect('/');

        $this->assertAuthenticated();

        // As rotas administrativas de convite, essas sim, continuam fechadas a
        // quem não está autenticado.
        $this->post('/logout');

        $this->get(route('settings.convites.index'))->assertRedirect(route('login'));
        $this->post('/settings/convites', ['email' => 'tentativa@um.test', 'papel' => 'tecnico'])
            ->assertRedirect(route('login'));
    }

    // -----------------------------------------------------------------
    // 8. O convite não abre porta em outro tenant
    // -----------------------------------------------------------------

    public function test_usuario_criado_por_convite_enxerga_apenas_o_tenant_que_o_convidou(): void
    {
        // Sem a página de depuração: ela imprimiria o código-fonte deste
        // arquivo em qualquer 500, e as marcas apareceriam como falso positivo.
        config(['app.debug' => false]);

        $clienteUm = $this->criarCliente($this->empresaUm, 'CLIENTEMARCAUM', 'cliente-um@exemplo.test', '33.333.333/0001-33');
        $clienteDois = $this->criarCliente($this->empresaDois, 'CLIENTEMARCADOIS', 'cliente-dois@exemplo.test', '44.444.444/0001-44');

        $convite = app(InvitationService::class)->convidar(
            $this->empresaUm,
            'convidado@um.test',
            'administrador',
            'Convidado',
            $this->administradorUm
        );

        TenantAtual::limpar();

        $this->post(route('convite.aceitar', $convite->token), [
            'nome' => 'Convidado Aceito',
            'senha' => self::SENHA,
            'senha_confirmation' => self::SENHA,
        ])->assertRedirect('/');

        $convidado = User::query()->where('email', 'convidado@um.test')->firstOrFail();

        $this->assertSame($this->empresaUm->id, (int) $convidado->company_id);

        foreach (['/dashboard', '/clients', '/addresses', '/work-orders', '/certificates', '/settings/users'] as $tela) {
            $resposta = $this->actingAs($convidado)->get($tela);

            $this->assertLessThan(
                500,
                $resposta->status(),
                "GET {$tela} respondeu {$resposta->status()}: erro de servidor esconde vazamento"
            );

            $this->assertStringNotContainsString(
                'CLIENTEMARCADOIS',
                $resposta->getContent(),
                "GET {$tela} mostrou dado da empresa dois para um usuário convidado pela empresa um"
            );
        }

        // Enxerga o próprio tenant: sem isto, a varredura acima passaria com o
        // sistema inteiro quebrado.
        $this->assertStringContainsString(
            'CLIENTEMARCAUM',
            $this->actingAs($convidado)->get('/clients')->getContent(),
            'o usuário convidado não enxerga nem o dado da empresa que o convidou'
        );

        // Acesso direto por id ao registro da outra empresa: 404, e nem a
        // existência dele é revelada.
        $this->actingAs($convidado)->get("/clients/{$clienteDois->id}")->assertNotFound();
        $this->actingAs($convidado)->get("/clients/{$clienteUm->id}")->assertOk();

        // E no Eloquent, antes de qualquer rota.
        TenantAtual::comTenant($this->empresaUm->id, function () use ($clienteDois): void {
            $this->assertNull(Client::query()->whereKey($clienteDois->id)->first());
        });
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * O convite deste token não vale mais: a tela pública responde 200 sem
     * expor empresa, papel nem e-mail, e o POST de aceite é recusado sem criar
     * usuário nenhum.
     */
    private function assertConviteRecusado(
        string $token,
        string $email,
        string $trechoDaMensagem,
        int $usuariosEsperados = 0
    ): void {
        $tela = $this->get(route('convite.show', $token));

        $tela->assertOk();
        $tela->assertInertia(fn ($pagina) => $pagina
            ->component('Auth/AceitarConvite')
            ->where('valido', false)
            ->where('empresa', null)
            ->where('papel', null)
            ->where('email', null));

        $this->assertStringContainsString(
            $trechoDaMensagem,
            (string) $tela->inertiaProps('mensagem'),
            'a tela não disse o que aconteceu com este convite, e quem clicou no link fica sem saída'
        );

        $aceite = $this->post(route('convite.aceitar', $token), [
            'nome' => 'Tentativa',
            'senha' => self::SENHA,
            'senha_confirmation' => self::SENHA,
        ]);

        $aceite->assertSessionHasErrors('token');

        $this->assertSame(
            $usuariosEsperados,
            User::query()->where('email', $email)->count(),
            "o aceite recusado criou usuário para {$email}"
        );
    }

    private function criarUsuario(Company $empresa, string $email, string $papel): User
    {
        return TenantAtual::comTenant($empresa->id, function () use ($email, $papel): User {
            $usuario = User::create([
                'name' => 'Usuário '.$email,
                'email' => $email,
                'password' => self::SENHA,
                'is_active' => true,
            ]);

            $usuario->assignRole($papel);

            return $usuario->fresh();
        });
    }

    private function criarCliente(Company $empresa, string $marca, string $email, string $cnpj): Client
    {
        return TenantAtual::comTenant($empresa->id, fn (): Client => Client::create([
            'name' => $marca,
            'email' => $email,
            'phone' => '11900000000',
            'cnpj' => $cnpj,
        ]));
    }
}
