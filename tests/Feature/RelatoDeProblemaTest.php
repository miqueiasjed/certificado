<?php

namespace Tests\Feature;

use App\Jobs\EnviarRelatoParaSistemaJob;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Company;
use App\Models\ProblemReport;
use App\Models\User;
use App\Services\ProblemReportService;
use App\Support\TenantAtual;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as RequisicaoHttp;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Botão "Relatar problema" e o repasse do relato ao sistema central de
 * suporte técnico (`EnviarRelatoParaSistemaJob`).
 *
 * Cobre a criação no painel e no portal (autenticação, validação, limite por
 * conta), o agendamento do envio depois da resposta, cada classe de resposta
 * do sistema central (201/200 gravam a tarefa; 401/409/422 falham de vez;
 * 5xx lança no worker e reenfileira na primeira tentativa), a ausência de dado
 * pessoal no corpo e o acesso à fila de relatos na área da plataforma.
 *
 * Um cuidado próprio deste arquivo: o `Application::terminate()` do Laravel
 * não esvazia a lista de callbacks entre requisições do mesmo teste, então o
 * envio agendado por `afterResponse()` numa requisição rodaria de novo na
 * seguinte. Os testes que conferem o envio de verdade fazem uma requisição
 * só; os que fazem várias usam `Bus::fake()`.
 */
class RelatoDeProblemaTest extends TestCase
{
    use RefreshDatabase;

    private const URL_DO_INTAKE = 'https://suporte.exemplo.test/api/v1/intake/reports';

    private const TOKEN_DO_INTAKE = 'token-intake-de-teste';

    private Company $empresaUm;

    private Company $empresaDois;

    private User $funcionario;

    private User $funcionarioDaEmpresaDois;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        config(['app.debug' => false]);

        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'services.sistema_intake.url' => self::URL_DO_INTAKE,
            'services.sistema_intake.token' => self::TOKEN_DO_INTAKE,
        ]);

        $this->empresaUm = Company::query()->firstOrFail();
        $this->empresaDois = Company::create([
            'name' => 'Dedetizadora Dois',
            'cnpj' => '22.222.222/0001-22',
            'email' => 'contato@dois.test',
        ]);

        $this->funcionario = $this->criarUsuario($this->empresaUm->id, 'funcionario');
        $this->funcionarioDaEmpresaDois = $this->criarUsuario($this->empresaDois->id, 'funcionario-dois');

        $this->superAdmin = $this->criarUsuario($this->empresaUm->id, 'super-admin');
        $this->superAdmin->is_platform_admin = true;
        $this->superAdmin->save();
        $this->superAdmin = $this->superAdmin->fresh();

        TenantAtual::limpar();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Criação no painel
    // -----------------------------------------------------------------

    public function test_funcionario_autenticado_cria_relato_e_o_envio_fica_para_depois_da_resposta(): void
    {
        Bus::fake([EnviarRelatoParaSistemaJob::class]);

        $resposta = $this->actingAs($this->funcionario)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Teste)'])
            ->postJson('/relatos', [
                'titulo' => 'Botão de salvar não responde',
                'descricao' => 'Cliquei em salvar na OS e nada aconteceu.',
                'pagina_url' => 'https://certificado.test/work-orders/12',
            ]);

        $resposta->assertCreated()->assertJson(['message' => 'Recebemos seu relato. Obrigado!']);

        $relato = ProblemReport::query()->deTodasAsEmpresas()->sole();

        $this->assertSame($this->empresaUm->id, (int) $relato->company_id);
        $this->assertSame($this->funcionario->id, $relato->user_id);
        $this->assertNull($relato->client_user_id);
        $this->assertSame('Botão de salvar não responde', $relato->titulo);
        $this->assertSame('Cliquei em salvar na OS e nada aconteceu.', $relato->descricao);
        $this->assertSame('https://certificado.test/work-orders/12', $relato->pagina_url);
        $this->assertSame('Mozilla/5.0 (Teste)', $relato->user_agent);
        $this->assertSame(ProblemReport::SITUACAO_NOVO, $relato->situacao);
        $this->assertNull($relato->enviado_em);

        Bus::assertDispatchedAfterResponse(
            EnviarRelatoParaSistemaJob::class,
            fn (EnviarRelatoParaSistemaJob $job): bool => $job->problemReportId === $relato->id
                && $job->companyId === $this->empresaUm->id
        );
    }

    public function test_titulo_vazio_vira_o_comeco_da_descricao(): void
    {
        Bus::fake([EnviarRelatoParaSistemaJob::class]);

        $descricao = 'A tela de certificados fica carregando para sempre quando filtro por cliente e depois troco o mês do filtro.';

        $this->actingAs($this->funcionario)
            ->postJson('/relatos', ['titulo' => '   ', 'descricao' => $descricao])
            ->assertCreated();

        $relato = ProblemReport::query()->deTodasAsEmpresas()->sole();

        $this->assertStringStartsWith('A tela de certificados fica carregando', $relato->titulo);
        $this->assertStringEndsWith('...', $relato->titulo);
        $this->assertLessThanOrEqual(83, mb_strlen($relato->titulo));
    }

    public function test_sem_login_nao_cria_relato(): void
    {
        $this->postJson('/relatos', ['descricao' => 'Qualquer coisa'])->assertUnauthorized();

        $this->assertSame(0, ProblemReport::query()->deTodasAsEmpresas()->count());
    }

    public function test_descricao_e_obrigatoria(): void
    {
        Bus::fake([EnviarRelatoParaSistemaJob::class]);

        $this->actingAs($this->funcionario)
            ->postJson('/relatos', ['titulo' => 'Sem descrição', 'descricao' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['descricao' => 'Descreva o problema que você encontrou.']);

        $this->assertSame(0, ProblemReport::query()->deTodasAsEmpresas()->count());
        Bus::assertNothingDispatched();
    }

    public function test_url_da_pagina_que_nao_e_http_e_descartada_sem_erro(): void
    {
        Bus::fake([EnviarRelatoParaSistemaJob::class]);

        $this->actingAs($this->funcionario)
            ->postJson('/relatos', ['descricao' => 'Algo quebrou.', 'pagina_url' => 'javascript:alert(1)'])
            ->assertCreated();

        $this->assertNull(ProblemReport::query()->deTodasAsEmpresas()->sole()->pagina_url);
    }

    public function test_limite_por_conta_barra_o_sexto_relato_no_minuto_sem_afetar_outra_conta(): void
    {
        Bus::fake([EnviarRelatoParaSistemaJob::class]);

        for ($vez = 1; $vez <= 5; $vez++) {
            $this->actingAs($this->funcionario)
                ->postJson('/relatos', ['descricao' => "Relato {$vez}"])
                ->assertCreated();
        }

        $this->actingAs($this->funcionario)
            ->postJson('/relatos', ['descricao' => 'Relato 6'])
            ->assertStatus(429);

        $this->assertSame(5, ProblemReport::query()->deTodasAsEmpresas()->count());

        $this->actingAs($this->funcionarioDaEmpresaDois)
            ->postJson('/relatos', ['descricao' => 'Relato de outra conta'])
            ->assertCreated();
    }

    // -----------------------------------------------------------------
    // Envio ao sistema central
    // -----------------------------------------------------------------

    public function test_criacao_envia_o_relato_depois_da_resposta_e_grava_a_tarefa_no_201(): void
    {
        Http::fake([
            self::URL_DO_INTAKE => Http::response([
                'data' => ['task_id' => 1534, 'task_url' => 'https://suporte.exemplo.test/tarefas/1534', 'created' => true],
            ], 201),
        ]);

        $this->actingAs($this->funcionario)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Teste)'])
            ->postJson('/relatos', [
                'titulo' => 'Erro ao emitir certificado',
                'descricao' => 'O PDF sai em branco.',
                'pagina_url' => 'https://certificado.test/certificates/7',
            ])
            ->assertCreated();

        $relato = ProblemReport::query()->deTodasAsEmpresas()->sole();

        $this->assertSame(1534, $relato->sistema_task_id);
        $this->assertSame('https://suporte.exemplo.test/tarefas/1534', $relato->sistema_task_url);
        $this->assertNotNull($relato->enviado_em);

        Http::assertSentCount(1);
        Http::assertSent(function (RequisicaoHttp $requisicao) use ($relato): bool {
            return $requisicao->url() === self::URL_DO_INTAKE
                && $requisicao->method() === 'POST'
                && $requisicao->hasHeader('Authorization', 'Bearer '.self::TOKEN_DO_INTAKE)
                && $requisicao['external_id'] === (string) $relato->id
                && $requisicao['title'] === 'Erro ao emitir certificado'
                && $requisicao['description'] === 'O PDF sai em branco.'
                && $requisicao['source_url'] === route('plataforma.relatos.show', $relato->id)
                && $requisicao['page_url'] === 'https://certificado.test/certificates/7'
                && $requisicao['user_agent'] === 'Mozilla/5.0 (Teste)'
                && $requisicao['reporter_ref'] === 'user:'.$this->funcionario->id;
        });
    }

    public function test_corpo_do_envio_nunca_leva_dado_pessoal_de_quem_relatou(): void
    {
        Http::fake([self::URL_DO_INTAKE => Http::response(['data' => ['task_id' => 1, 'task_url' => null]], 201)]);

        $relato = $this->relatoDoFuncionario();

        $this->enviarAgora($relato->id);

        Http::assertSent(function (RequisicaoHttp $requisicao): bool {
            $corpo = $requisicao->body();

            foreach (['name', 'email', 'phone', 'reporter_name', 'reporter_email'] as $campoProibido) {
                if (array_key_exists($campoProibido, $requisicao->data())) {
                    return false;
                }
            }

            return ! str_contains($corpo, $this->funcionario->email)
                && ! str_contains($corpo, $this->funcionario->name);
        });
    }

    public function test_reenvio_com_200_tambem_grava_a_tarefa(): void
    {
        Http::fake([
            self::URL_DO_INTAKE => Http::response([
                'data' => ['task_id' => 77, 'task_url' => 'https://suporte.exemplo.test/tarefas/77', 'created' => false],
            ], 200),
        ]);

        $relato = $this->relatoDoFuncionario();

        $this->enviarAgora($relato->id);

        $relato = $relato->fresh();
        $this->assertSame(77, $relato->sistema_task_id);
        $this->assertNotNull($relato->enviado_em);
    }

    public function test_sem_token_configurado_nao_chama_o_sistema_central(): void
    {
        config(['services.sistema_intake.token' => null]);
        Http::fake();

        $relato = $this->relatoDoFuncionario();

        $this->enviarAgora($relato->id);

        Http::assertNothingSent();
        $this->assertNull($relato->fresh()->enviado_em);
    }

    public function test_relato_ja_enviado_nao_e_mandado_de_novo(): void
    {
        Http::fake();

        $relato = $this->relatoDoFuncionario();
        $relato->forceFill(['enviado_em' => now(), 'sistema_task_id' => 10])->save();

        $this->enviarAgora($relato->id);

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function respostasFinais(): array
    {
        return [
            'token recusado' => [401],
            'projeto na lixeira' => [409],
            'corpo fora do contrato' => [422],
        ];
    }

    /**
     * @dataProvider respostasFinais
     */
    public function test_resposta_final_falha_o_job_sem_repetir(int $status): void
    {
        Http::fake([self::URL_DO_INTAKE => Http::response(['message' => 'Recusado.'], $status)]);

        $relato = $this->relatoDoFuncionario();

        $job = $this->jobNoTenantUm($relato->id)->withFakeQueueInteractions();
        $this->noTenantUm(fn () => $job->handle(app(ProblemReportService::class)));

        $job->assertFailed();
        $job->assertNotReleased();
        Http::assertSentCount(1);
        $this->assertNull($relato->fresh()->enviado_em);
    }

    public function test_resposta_final_na_primeira_tentativa_nao_reenfileira(): void
    {
        config(['queue.default' => 'database']);
        Http::fake([self::URL_DO_INTAKE => Http::response(['message' => 'Recusado.'], 422)]);

        $relato = $this->relatoDoFuncionario();

        $this->enviarAgora($relato->id);

        $this->assertSame(0, DB::table('jobs')->count());
        Http::assertSentCount(1);
    }

    public function test_erro_500_no_worker_lanca_para_a_fila_tentar_de_novo(): void
    {
        Http::fake([self::URL_DO_INTAKE => Http::response(['message' => 'Erro.'], 500)]);

        $relato = $this->relatoDoFuncionario();

        $job = $this->jobNoTenantUm($relato->id)->withFakeQueueInteractions();

        $this->expectException(RequestException::class);

        try {
            $this->noTenantUm(fn () => $job->handle(app(ProblemReportService::class)));
        } finally {
            $job->assertNotFailed();
            $this->assertNull($relato->fresh()->enviado_em);
        }
    }

    public function test_erro_500_na_primeira_tentativa_depois_da_resposta_reenfileira_em_vez_de_lancar(): void
    {
        config(['queue.default' => 'database']);
        Http::fake([self::URL_DO_INTAKE => Http::response(['message' => 'Erro.'], 503)]);

        $relato = $this->relatoDoFuncionario();

        $this->enviarAgora($relato->id);

        $this->assertSame(1, DB::table('jobs')->count());
        $payload = json_decode((string) DB::table('jobs')->value('payload'), true);
        $this->assertSame(EnviarRelatoParaSistemaJob::class, $payload['displayName']);
        $this->assertNull($relato->fresh()->enviado_em);
    }

    // -----------------------------------------------------------------
    // Portal do cliente
    // -----------------------------------------------------------------

    public function test_usuario_do_portal_cria_relato_com_referencia_opaca(): void
    {
        $this->seed(ModulesSeeder::class);

        Http::fake([self::URL_DO_INTAKE => Http::response(['data' => ['task_id' => 9, 'task_url' => null]], 201)]);

        $usuarioDoPortal = $this->entrarNoPortal();

        $this->postJson('/portal/relatos', [
            'descricao' => 'Não consigo baixar o certificado.',
            'pagina_url' => 'https://certificado.test/portal/certificados',
        ])->assertCreated()->assertJson(['message' => 'Recebemos seu relato. Obrigado!']);

        $relato = ProblemReport::query()->deTodasAsEmpresas()->sole();

        $this->assertSame($this->empresaUm->id, (int) $relato->company_id);
        $this->assertNull($relato->user_id);
        $this->assertSame($usuarioDoPortal->id, $relato->client_user_id);
        $this->assertSame(9, $relato->sistema_task_id);

        Http::assertSent(fn (RequisicaoHttp $requisicao): bool => $requisicao['reporter_ref'] === 'cliente:'.$usuarioDoPortal->id
            && ! str_contains($requisicao->body(), $usuarioDoPortal->email)
            && ! str_contains($requisicao->body(), $usuarioDoPortal->nome));
    }

    public function test_portal_sem_login_nao_cria_relato(): void
    {
        $this->postJson('/portal/relatos', ['descricao' => 'Qualquer coisa'])->assertUnauthorized();

        $this->assertSame(0, ProblemReport::query()->deTodasAsEmpresas()->count());
    }

    // -----------------------------------------------------------------
    // Área da plataforma
    // -----------------------------------------------------------------

    public function test_super_admin_ve_relatos_de_todos_os_tenants_e_o_detalhe(): void
    {
        $relatoUm = $this->relatoDoFuncionario();
        $relatoDois = TenantAtual::comTenant($this->empresaDois->id, fn (): ProblemReport => ProblemReport::create([
            'user_id' => $this->funcionarioDaEmpresaDois->id,
            'titulo' => 'Relato da empresa dois',
            'descricao' => 'Descrição da empresa dois',
        ]));
        TenantAtual::limpar();

        $this->actingAs($this->superAdmin)
            ->get('/plataforma/relatos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Plataforma/Relatos/Index', false)
                ->has('relatos.data', 2));

        $this->actingAs($this->superAdmin)
            ->get(route('plataforma.relatos.show', $relatoDois->id, false))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Plataforma/Relatos/Show', false)
                ->where('relato.id', $relatoDois->id)
                ->where('relato.titulo', 'Relato da empresa dois')
                ->where('relato.company.name', 'Dedetizadora Dois')
                ->missing('relato.user.email'));

        $this->assertNotNull($relatoUm->id);
    }

    public function test_super_admin_troca_a_situacao_do_relato(): void
    {
        $relato = $this->relatoDoFuncionario();

        $this->actingAs($this->superAdmin)
            ->put(route('plataforma.relatos.situacao', $relato->id, false), ['situacao' => 'em_analise'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(ProblemReport::SITUACAO_EM_ANALISE, $relato->fresh()->situacao);

        $this->actingAs($this->superAdmin)
            ->put(route('plataforma.relatos.situacao', $relato->id, false), ['situacao' => 'arquivado'])
            ->assertSessionHasErrors('situacao');

        $this->assertSame(ProblemReport::SITUACAO_EM_ANALISE, $relato->fresh()->situacao);
    }

    public function test_usuario_que_nao_e_super_admin_nao_ve_nem_altera_relatos(): void
    {
        $relato = $this->relatoDoFuncionario();

        $this->actingAs($this->funcionario)->get('/plataforma/relatos')->assertForbidden();
        $this->actingAs($this->funcionario)->get(route('plataforma.relatos.show', $relato->id, false))->assertForbidden();
        $this->actingAs($this->funcionario)
            ->put(route('plataforma.relatos.situacao', $relato->id, false), ['situacao' => 'resolvido'])
            ->assertForbidden();

        $this->assertSame(ProblemReport::SITUACAO_NOVO, $relato->fresh()->situacao);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function criarUsuario(int $empresa, string $apelido): User
    {
        $usuario = TenantAtual::comTenant($empresa, fn (): User => User::factory()->create([
            'name' => 'Usuario '.$apelido,
            'email' => $apelido.'@exemplo.test',
            'is_active' => true,
        ]));

        $usuario->assignRole('administrador');

        return $usuario->fresh();
    }

    /**
     * Relato do funcionário da empresa 1, criado direto pelo model para não
     * agendar o envio: os testes de envio chamam o job eles mesmos.
     */
    private function relatoDoFuncionario(): ProblemReport
    {
        $relato = $this->noTenantUm(fn (): ProblemReport => ProblemReport::create([
            'user_id' => $this->funcionario->id,
            'titulo' => 'Relato de teste',
            'descricao' => 'Descrição do relato de teste.',
            'pagina_url' => 'https://certificado.test/dashboard',
            'user_agent' => 'Mozilla/5.0 (Teste)',
        ]));

        TenantAtual::limpar();

        return $relato;
    }

    /**
     * Primeira tentativa, como ela roda de verdade depois da resposta: em uma
     * `SyncJob`, despachada dentro do tenant que criou o relato.
     */
    private function enviarAgora(int $relatoId): void
    {
        $this->noTenantUm(fn () => EnviarRelatoParaSistemaJob::dispatchSync($relatoId));
        TenantAtual::limpar();
    }

    private function jobNoTenantUm(int $relatoId): EnviarRelatoParaSistemaJob
    {
        return $this->noTenantUm(fn (): EnviarRelatoParaSistemaJob => new EnviarRelatoParaSistemaJob($relatoId));
    }

    private function noTenantUm(callable $acao): mixed
    {
        return TenantAtual::comTenant($this->empresaUm->id, $acao);
    }

    private function entrarNoPortal(): ClientUser
    {
        $email = 'portal@exemplo.test';

        $this->noTenantUm(function () use ($email): void {
            $cliente = Client::create([
                'name' => 'Cliente do portal',
                'email' => 'cliente-portal@exemplo.test',
                'phone' => '11912340000',
                'cnpj' => '33.333.333/0001-11',
            ]);

            ClientUser::create([
                'client_id' => $cliente->id,
                'nome' => 'Pessoa do Portal',
                'email' => $email,
                'password' => Hash::make('senha-teste-123'),
                'ativo' => true,
                'email_verificado_em' => now(),
            ]);
        });

        TenantAtual::limpar();

        $this->post('/portal/login', ['email' => $email, 'password' => 'senha-teste-123'])->assertRedirect();

        return ClientUser::query()->deTodasAsEmpresas()->where('email', $email)->firstOrFail();
    }
}
