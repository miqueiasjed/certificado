<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientRequest;
use App\Models\ClientUser;
use App\Models\Company;
use App\Models\NotificationQueue;
use App\Models\User;
use App\Services\ClientRequestService;
use App\Support\EventosDeNotificacao;
use App\Support\TenantAtual;
use Database\Factories\ClientFactory;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Task 15.5 do Plano 15: solicitação de atendimento aberta pelo cliente no
 * portal, que chega à empresa como pendência com aviso.
 *
 * Cobre os critérios de aceitação da task: aviso à empresa na abertura,
 * escopo por cliente na listagem e no acesso por id (404, nunca 403), aviso
 * e registro da resposta, teto de 5 solicitações abertas, o contador no menu
 * do painel e a permissão `solicitacao-responder`.
 */
class ClientRequestTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Client $clienteA;

    private Client $clienteB;

    private User $administrador;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        // A empresa 1 vem da migration de fundação do tenant, sem e-mail. O
        // aviso `solicitacao_recebida` vai para o e-mail da empresa
        // (DESTINATARIO_EMPRESA), então sem isto `NotificationService`
        // devolveria `sem_destino` e nenhuma linha entraria na fila.
        $this->empresa = Company::query()->firstOrFail();
        $this->empresa->update(['email' => 'contato@empresateste.test']);
        $this->empresa = $this->empresa->fresh();

        // Libera o módulo portal_cliente (sem isto, AutenticarCliente/
        // EnsurePortalModuleIsActive barra qualquer rota de portal) e
        // sincroniza papéis e permissões, inclusive as duas novas desta task
        // (solicitacao-ver e solicitacao-responder).
        $this->seed(ModulesSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        TenantAtual::comTenant($this->empresa->id, function (): void {
            $this->clienteA = ClientFactory::new()->create(['name' => 'Cliente A']);
            $this->clienteB = ClientFactory::new()->create(['name' => 'Cliente B']);
        });

        $this->administrador = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): User => User::factory()->create(['name' => 'Administrador Teste', 'is_active' => true])
        );
        $this->administrador->assignRole('administrador');
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Apoio de cenário
    // -----------------------------------------------------------------

    private function criarClientUser(Client $cliente, string $email): ClientUser
    {
        return TenantAtual::comTenant($this->empresa->id, fn (): ClientUser => ClientUser::create([
            'client_id' => $cliente->id,
            'nome' => 'Usuário do portal',
            'email' => $email,
            'password' => Hash::make('senha-teste-123'),
            'ativo' => true,
            'email_verificado_em' => now(),
        ]));
    }

    private function autenticarComo(string $email): void
    {
        $this->post('/portal/login', ['email' => $email, 'password' => 'senha-teste-123'])
            ->assertRedirect();
    }

    private function criarSolicitacao(
        Client $cliente,
        ClientUser $clientUser,
        string $situacao = ClientRequest::SITUACAO_ABERTA
    ): ClientRequest {
        return TenantAtual::comTenant($this->empresa->id, fn (): ClientRequest => ClientRequest::create([
            'client_id' => $cliente->id,
            'client_user_id' => $clientUser->id,
            'assunto' => 'Dúvida sobre a próxima visita',
            'descricao' => 'Preciso remarcar a visita da próxima semana.',
            'situacao' => $situacao,
            'prioridade' => ClientRequest::PRIORIDADE_NORMAL,
        ]));
    }

    private function buscar(int $id): ClientRequest
    {
        return TenantAtual::comTenant(
            $this->empresa->id,
            fn (): ClientRequest => ClientRequest::query()->findOrFail($id)
        );
    }

    // -----------------------------------------------------------------
    // Cliente abre solicitação e a empresa recebe o aviso
    // -----------------------------------------------------------------

    public function test_cliente_abre_solicitacao_e_a_empresa_recebe_o_aviso(): void
    {
        $this->criarClientUser($this->clienteA, 'abrir@exemplo.test');
        $this->autenticarComo('abrir@exemplo.test');

        $resposta = $this->post('/portal/solicitacoes', [
            'assunto' => 'Dúvida sobre certificado',
            'descricao' => 'O certificado da minha unidade está para vencer, preciso renovar.',
        ]);

        $resposta->assertRedirect(route('portal.solicitacoes.index'));

        $solicitacao = TenantAtual::comTenant(
            $this->empresa->id,
            fn () => ClientRequest::query()->where('client_id', $this->clienteA->id)->firstOrFail()
        );

        $this->assertSame('Dúvida sobre certificado', $solicitacao->assunto);
        $this->assertSame(ClientRequest::SITUACAO_ABERTA, $solicitacao->situacao);
        $this->assertSame(ClientRequest::PRIORIDADE_NORMAL, $solicitacao->prioridade);

        $aviso = TenantAtual::comTenant(
            $this->empresa->id,
            fn () => NotificationQueue::query()
                ->where('evento', EventosDeNotificacao::SOLICITACAO_RECEBIDA)
                ->first()
        );

        $this->assertNotNull($aviso, 'abrir() deveria enfileirar o evento solicitacao_recebida para a empresa');
        $this->assertSame(NotificationQueue::DESTINATARIO_EMPRESA, $aviso->destinatario_tipo);
        $this->assertStringContainsString('Dúvida sobre certificado', $aviso->corpo);
    }

    /**
     * O cliente não define prioridade: mesmo mandando o campo no corpo da
     * requisição, a solicitação nasce sempre `normal`.
     */
    public function test_cliente_nunca_define_prioridade_mesmo_enviando_o_campo(): void
    {
        $this->criarClientUser($this->clienteA, 'prioridade@exemplo.test');
        $this->autenticarComo('prioridade@exemplo.test');

        $this->post('/portal/solicitacoes', [
            'assunto' => 'Assunto qualquer',
            'descricao' => 'Descrição qualquer, sem relação com a prioridade.',
            'prioridade' => 'alta',
        ])->assertRedirect();

        $solicitacao = TenantAtual::comTenant(
            $this->empresa->id,
            fn () => ClientRequest::query()->where('client_id', $this->clienteA->id)->firstOrFail()
        );

        $this->assertSame(ClientRequest::PRIORIDADE_NORMAL, $solicitacao->prioridade);
    }

    // -----------------------------------------------------------------
    // Cliente vê apenas as próprias solicitações
    // -----------------------------------------------------------------

    public function test_cliente_ve_apenas_as_proprias_solicitacoes(): void
    {
        $clientUserB = $this->criarClientUser($this->clienteB, 'listagem-b@exemplo.test');
        $solicitacaoB = $this->criarSolicitacao($this->clienteB, $clientUserB);

        $clientUserA = $this->criarClientUser($this->clienteA, 'listagem-a@exemplo.test');
        $solicitacaoA = $this->criarSolicitacao($this->clienteA, $clientUserA);

        $this->autenticarComo('listagem-a@exemplo.test');

        $resposta = $this->get('/portal/solicitacoes');
        $resposta->assertOk();

        $ids = array_column($resposta->inertiaProps('solicitacoes'), 'id');

        $this->assertContains($solicitacaoA->id, $ids, 'a própria solicitação deveria aparecer na listagem');
        $this->assertNotContains($solicitacaoB->id, $ids, 'a listagem vazou a solicitação de outro cliente');
    }

    public function test_solicitacao_de_outro_cliente_devolve_404(): void
    {
        $clientUserB = $this->criarClientUser($this->clienteB, 'outro-404@exemplo.test');
        $solicitacaoB = $this->criarSolicitacao($this->clienteB, $clientUserB);

        $this->criarClientUser($this->clienteA, 'proprio-404@exemplo.test');
        $this->autenticarComo('proprio-404@exemplo.test');

        $this->get('/portal/solicitacoes/'.$solicitacaoB->id)->assertNotFound();
    }

    public function test_solicitacao_inexistente_devolve_404(): void
    {
        $this->criarClientUser($this->clienteA, 'inexistente-404@exemplo.test');
        $this->autenticarComo('inexistente-404@exemplo.test');

        $this->get('/portal/solicitacoes/999999')->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Responder envia o aviso e a resposta aparece no portal
    // -----------------------------------------------------------------

    public function test_responder_envia_aviso_e_resposta_aparece_no_portal(): void
    {
        $clientUserA = $this->criarClientUser($this->clienteA, 'responder@exemplo.test');
        $solicitacao = $this->criarSolicitacao($this->clienteA, $clientUserA);

        $resposta = $this->actingAs($this->administrador)
            ->post("/solicitacoes/{$solicitacao->id}/responder", [
                'resposta' => 'Já agendamos sua nova visita para a próxima semana.',
            ]);

        $resposta->assertRedirect();
        $resposta->assertSessionHasNoErrors();

        $solicitacaoAtualizada = $this->buscar($solicitacao->id);

        $this->assertSame(
            'Já agendamos sua nova visita para a próxima semana.',
            $solicitacaoAtualizada->resposta
        );
        $this->assertNotNull($solicitacaoAtualizada->respondida_em);
        $this->assertSame($this->administrador->id, $solicitacaoAtualizada->atendida_por);
        $this->assertSame(
            ClientRequest::SITUACAO_EM_ATENDIMENTO,
            $solicitacaoAtualizada->situacao,
            'responder uma solicitação aberta deveria avançá-la para em_atendimento'
        );

        $aviso = TenantAtual::comTenant(
            $this->empresa->id,
            fn () => NotificationQueue::query()
                ->where('evento', EventosDeNotificacao::SOLICITACAO_RESPONDIDA)
                ->first()
        );

        $this->assertNotNull($aviso, 'responder() deveria enfileirar o evento solicitacao_respondida para o cliente');
        $this->assertSame(NotificationQueue::DESTINATARIO_CLIENTE, $aviso->destinatario_tipo);
        $this->assertStringContainsString('Já agendamos sua nova visita', $aviso->corpo);

        // A resposta aparece no portal para o cliente dono da solicitação.
        $this->autenticarComo('responder@exemplo.test');

        $respostaPortal = $this->get('/portal/solicitacoes/'.$solicitacao->id);
        $respostaPortal->assertOk();

        $this->assertSame(
            'Já agendamos sua nova visita para a próxima semana.',
            $respostaPortal->inertiaProps('solicitacao')['resposta']
        );
    }

    // -----------------------------------------------------------------
    // Sexta solicitação aberta é recusada com mensagem clara
    // -----------------------------------------------------------------

    public function test_sexta_solicitacao_aberta_e_recusada_com_mensagem_clara(): void
    {
        $clientUser = $this->criarClientUser($this->clienteA, 'limite@exemplo.test');

        for ($i = 1; $i <= ClientRequestService::LIMITE_ABERTAS; $i++) {
            $this->criarSolicitacao($this->clienteA, $clientUser);
        }

        $this->autenticarComo('limite@exemplo.test');

        $resposta = $this->post('/portal/solicitacoes', [
            'assunto' => 'Sexta solicitação',
            'descricao' => 'Essa deveria ser recusada pelo teto de solicitações abertas.',
        ]);

        $resposta->assertSessionHasErrors('limite');

        $total = TenantAtual::comTenant(
            $this->empresa->id,
            fn () => ClientRequest::query()->where('client_id', $this->clienteA->id)->count()
        );

        $this->assertSame(5, $total, 'a sexta solicitação não deveria ter sido criada');
    }

    /**
     * Situação `resolvida`/`cancelada` não conta para o teto: fechar
     * solicitações abre espaço para novas.
     */
    public function test_solicitacao_resolvida_nao_conta_para_o_teto(): void
    {
        $clientUser = $this->criarClientUser($this->clienteA, 'teto-resolvida@exemplo.test');

        $this->criarSolicitacao($this->clienteA, $clientUser, ClientRequest::SITUACAO_RESOLVIDA);
        $this->criarSolicitacao($this->clienteA, $clientUser, ClientRequest::SITUACAO_CANCELADA);
        $this->criarSolicitacao($this->clienteA, $clientUser, ClientRequest::SITUACAO_ABERTA);
        $this->criarSolicitacao($this->clienteA, $clientUser, ClientRequest::SITUACAO_EM_ATENDIMENTO);

        $this->autenticarComo('teto-resolvida@exemplo.test');

        $this->post('/portal/solicitacoes', [
            'assunto' => 'Quinta solicitação, mas só duas abertas de verdade',
            'descricao' => 'Só duas das quatro anteriores estão realmente abertas.',
        ])->assertSessionHasNoErrors();

        $total = TenantAtual::comTenant(
            $this->empresa->id,
            fn () => ClientRequest::query()->where('client_id', $this->clienteA->id)->count()
        );

        $this->assertSame(5, $total);
    }

    // -----------------------------------------------------------------
    // O contador de abertas aparece no menu do painel
    // -----------------------------------------------------------------

    public function test_contador_de_solicitacoes_abertas_aparece_no_menu_do_painel(): void
    {
        $clientUser = $this->criarClientUser($this->clienteA, 'contador@exemplo.test');

        $this->criarSolicitacao($this->clienteA, $clientUser, ClientRequest::SITUACAO_ABERTA);
        $this->criarSolicitacao($this->clienteA, $clientUser, ClientRequest::SITUACAO_EM_ATENDIMENTO);
        $this->criarSolicitacao($this->clienteA, $clientUser, ClientRequest::SITUACAO_RESOLVIDA);

        $resposta = $this->actingAs($this->administrador)->get('/dashboard');

        $resposta->assertOk();
        $this->assertSame(
            2,
            $resposta->inertiaProps('solicitacoesAbertas'),
            'o contador deveria somar só aberta + em_atendimento'
        );
    }

    public function test_usuario_sem_permissao_solicitacao_ver_nao_ve_o_contador(): void
    {
        $clientUser = $this->criarClientUser($this->clienteA, 'sem-ver@exemplo.test');
        $this->criarSolicitacao($this->clienteA, $clientUser, ClientRequest::SITUACAO_ABERTA);

        $usuarioTecnico = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): User => User::factory()->create(['name' => 'Técnico Teste', 'is_active' => true])
        );
        $usuarioTecnico->assignRole('tecnico');

        $resposta = $this->actingAs($usuarioTecnico)->get('/dashboard');

        $resposta->assertOk();
        $this->assertSame(0, $resposta->inertiaProps('solicitacoesAbertas'));
    }

    // -----------------------------------------------------------------
    // Usuário sem solicitacao-responder não responde
    // -----------------------------------------------------------------

    public function test_usuario_sem_permissao_solicitacao_responder_nao_responde(): void
    {
        $clientUser = $this->criarClientUser($this->clienteA, 'sem-permissao@exemplo.test');
        $solicitacao = $this->criarSolicitacao($this->clienteA, $clientUser);

        // O papel "leitura" recebe todas as permissões terminadas em "-ver"
        // do catálogo (inclusive solicitacao-ver), mas não solicitacao-responder.
        $usuarioLeitura = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): User => User::factory()->create(['name' => 'Leitura Teste', 'is_active' => true])
        );
        $usuarioLeitura->assignRole('leitura');

        $resposta = $this->actingAs($usuarioLeitura)
            ->post("/solicitacoes/{$solicitacao->id}/responder", ['resposta' => 'Tentativa sem permissão']);

        $resposta->assertForbidden();

        $solicitacaoInalterada = $this->buscar($solicitacao->id);
        $this->assertNull($solicitacaoInalterada->resposta);

        $respostaSituacao = $this->actingAs($usuarioLeitura)
            ->post("/solicitacoes/{$solicitacao->id}/situacao", ['situacao' => ClientRequest::SITUACAO_RESOLVIDA]);

        $respostaSituacao->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Filtro por situação no painel
    // -----------------------------------------------------------------

    public function test_painel_filtra_solicitacoes_por_situacao(): void
    {
        $clientUser = $this->criarClientUser($this->clienteA, 'filtro@exemplo.test');

        $aberta = $this->criarSolicitacao($this->clienteA, $clientUser, ClientRequest::SITUACAO_ABERTA);
        $resolvida = $this->criarSolicitacao($this->clienteA, $clientUser, ClientRequest::SITUACAO_RESOLVIDA);

        $resposta = $this->actingAs($this->administrador)->get('/solicitacoes?situacao=resolvida');

        $resposta->assertOk();

        $ids = array_column($resposta->inertiaProps('solicitacoes.data'), 'id');

        $this->assertContains($resolvida->id, $ids);
        $this->assertNotContains($aberta->id, $ids);
    }
}
