<?php

namespace Tests\Feature;

use App\Console\Commands\SyncPermissions;
use App\Models\Address;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Company;
use App\Models\Device;
use App\Models\DevicePosition;
use App\Models\FloorPlan;
use App\Models\MonitoringReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\TenantAtual;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Task 21.5 do Plano 21: endpoints do relatório de monitoramento, da planta
 * do endereço e do portal do cliente.
 *
 * Cobre os critérios de aceitação da task: (a) a visão interativa filtra por
 * cliente/endereço/período, (b) gerar relatório congela o consolidado, (c)
 * relatório gerado não aparece no portal até ser publicado, (d) publicar e
 * despublicar funcionam, (e) o cliente vê no portal só os próprios
 * publicados, (f) a planta é enviada/substituída/posicionada pelos
 * endpoints, (g) usuário sem permissão recebe 403, (h) tenant sem o módulo
 * vê a página de indisponível.
 */
class MonitoringReportEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private User $administrador;

    private Client $cliente;

    private Address $endereco;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        Storage::fake('public');

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ModulesSeeder::class);

        $this->empresa = Company::query()->firstOrFail();

        TenantAtual::comTenant($this->empresa->id, function (): void {
            $this->administrador = User::factory()->create(['is_active' => true]);
            $this->administrador->assignRole('administrador');

            $this->cliente = ClientFactory::new()->create(['name' => 'Cliente Monitorado']);
            $this->endereco = AddressFactory::new()->create(['client_id' => $this->cliente->id]);

            WorkOrder::factory()->create([
                'client_id' => $this->cliente->id,
                'address_id' => $this->endereco->id,
                'scheduled_date' => '2026-07-10',
                'status' => 'completed',
            ]);
        });
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    public function test_catalogo_tem_as_tres_permissoes_novas(): void
    {
        $catalogo = collect(SyncPermissions::catalogo())->flatten()->all();

        $this->assertContains('monitoramento-ver', $catalogo);
        $this->assertContains('monitoramento-gerar', $catalogo);
        $this->assertContains('monitoramento-publicar', $catalogo);
    }

    // -----------------------------------------------------------------
    // (a) Visão interativa filtra por cliente, endereço e período
    // -----------------------------------------------------------------

    public function test_visao_interativa_filtra_por_cliente_endereco_e_periodo(): void
    {
        $resposta = $this->actingAs($this->administrador)->get(sprintf(
            '/monitoramento?client_id=%d&address_id=%d&de=%s&ate=%s',
            $this->cliente->id,
            $this->endereco->id,
            '2026-07-01',
            '2026-07-31'
        ));

        $resposta->assertOk();
        $resposta->assertInertia(fn ($page) => $page
            ->component('Monitoring/Index', false)
            ->where('congelado', false)
            ->where('consolidado.client_id', $this->cliente->id)
            ->where('consolidado.address_id', $this->endereco->id)
            ->where('consolidado.visitas.quantidade', 1));

        // Sem cliente/período na querystring, a página carrega sem
        // consolidado nenhum: escolher o filtro é passo do usuário na tela,
        // não pré-requisito para abrir a URL.
        $semFiltro = $this->actingAs($this->administrador)->get('/monitoramento');
        $semFiltro->assertOk();
        $semFiltro->assertInertia(fn ($page) => $page
            ->component('Monitoring/Index', false)
            ->where('consolidado', null));

        // Período fora do que a OS cobre: consolidado existe, mas sem
        // nenhuma visita.
        $outroPeriodo = $this->actingAs($this->administrador)->get(sprintf(
            '/monitoramento?client_id=%d&de=%s&ate=%s',
            $this->cliente->id,
            '2026-01-01',
            '2026-01-31'
        ));
        $outroPeriodo->assertOk();
        $outroPeriodo->assertInertia(fn ($page) => $page
            ->where('consolidado.visitas.quantidade', 0));
    }

    // -----------------------------------------------------------------
    // (b) e (c) Gerar relatório congela o consolidado; não aparece no
    // portal até publicar
    // -----------------------------------------------------------------

    public function test_gerar_relatorio_congela_o_consolidado_e_regerar_cria_outro_registro(): void
    {
        $resposta = $this->actingAs($this->administrador)->post('/monitoramento/relatorios', [
            'client_id' => $this->cliente->id,
            'address_id' => $this->endereco->id,
            'de' => '2026-07-01',
            'ate' => '2026-07-31',
        ]);

        $relatorio = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): MonitoringReport => MonitoringReport::query()->latest('id')->firstOrFail()
        );

        $resposta->assertRedirect(route('monitoramento.relatorios.show', $relatorio));
        $this->assertSame($this->cliente->id, $relatorio->client_id);
        $this->assertSame($this->endereco->id, $relatorio->address_id);
        $this->assertFalse($relatorio->publicado_no_portal, 'relatório precisa nascer despublicado');
        $this->assertSame($this->administrador->id, $relatorio->gerado_por);
        $this->assertSame(1, $relatorio->dados['visitas']['quantidade']);
        $this->assertSame('2026-07-01', $relatorio->periodo_inicio->toDateString());

        $detalhe = $this->actingAs($this->administrador)->get(route('monitoramento.relatorios.show', $relatorio));
        $detalhe->assertOk();
        $detalhe->assertInertia(fn ($page) => $page
            ->component('Monitoring/Relatorios/Show', false)
            ->where('congelado', true)
            ->where('relatorio.id', $relatorio->id));

        // Regerar (mesmo cliente/endereço/período) cria outro registro, nunca
        // sobrescreve o existente - relatório salvo é imutável.
        $this->actingAs($this->administrador)->post('/monitoramento/relatorios', [
            'client_id' => $this->cliente->id,
            'address_id' => $this->endereco->id,
            'de' => '2026-07-01',
            'ate' => '2026-07-31',
        ]);

        $total = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): int => MonitoringReport::query()->where('client_id', $this->cliente->id)->count()
        );
        $this->assertSame(2, $total, 'regerar precisa criar um relatório novo, não sobrescrever o existente');
    }

    public function test_relatorio_gerado_nao_aparece_no_portal_ate_ser_publicado(): void
    {
        $relatorio = $this->gerarRelatorio();

        $usuarioPortal = $this->criarUsuarioPortal($this->cliente, 'nao-publicado@exemplo.test');
        $this->autenticarNoPortal($usuarioPortal);

        // Lista do portal não traz o relatório ainda não publicado.
        $lista = $this->get('/portal/relatorios');
        $lista->assertOk();
        $lista->assertInertia(fn ($page) => $page->where('relatorios.data', []));

        // Acessar diretamente o id devolve 404 - nunca 403, para não vazar
        // que o relatório existe.
        $this->get("/portal/relatorios/{$relatorio->id}")->assertNotFound();
        $this->get("/portal/relatorios/{$relatorio->id}/pdf")->assertNotFound();
    }

    // -----------------------------------------------------------------
    // (d) Publicar e despublicar funcionam
    // -----------------------------------------------------------------

    public function test_publicar_e_despublicar_alternam_a_visibilidade_no_portal(): void
    {
        $relatorio = $this->gerarRelatorio();

        $this->actingAs($this->administrador)
            ->post(route('monitoramento.relatorios.publicar', $relatorio))
            ->assertRedirect();
        $this->assertTrue($relatorio->refresh()->publicado_no_portal);

        $usuarioPortal = $this->criarUsuarioPortal($this->cliente, 'publicado@exemplo.test');
        $this->autenticarNoPortal($usuarioPortal);

        $lista = $this->get('/portal/relatorios');
        $lista->assertOk();
        $lista->assertInertia(fn ($page) => $page->where('relatorios.data.0.id', $relatorio->id));

        $detalhe = $this->get("/portal/relatorios/{$relatorio->id}");
        $detalhe->assertOk();
        $detalhe->assertInertia(fn ($page) => $page
            ->where('relatorio.id', $relatorio->id)
            ->where('relatorio.dados.visitas.quantidade', 1));

        $pdf = $this->get("/portal/relatorios/{$relatorio->id}/pdf");
        $pdf->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');

        // Despublicar: o mesmo relatório some do portal outra vez.
        $this->actingAs($this->administrador)
            ->post(route('monitoramento.relatorios.despublicar', $relatorio))
            ->assertRedirect();
        $this->assertFalse($relatorio->refresh()->publicado_no_portal);

        $this->get("/portal/relatorios/{$relatorio->id}")->assertNotFound();
    }

    // -----------------------------------------------------------------
    // (e) Cliente só vê no portal os próprios relatórios publicados
    // -----------------------------------------------------------------

    public function test_cliente_do_portal_so_ve_os_proprios_relatorios_publicados(): void
    {
        $relatorioDoClienteA = $this->gerarRelatorio();
        $this->actingAs($this->administrador)
            ->post(route('monitoramento.relatorios.publicar', $relatorioDoClienteA));

        [$clienteB, $enderecoB] = TenantAtual::comTenant($this->empresa->id, function (): array {
            $clienteB = ClientFactory::new()->create(['name' => 'Outro Cliente']);
            $enderecoB = AddressFactory::new()->create(['client_id' => $clienteB->id]);

            return [$clienteB, $enderecoB];
        });

        $relatorioDoClienteB = TenantAtual::comTenant($this->empresa->id, fn (): MonitoringReport => MonitoringReport::query()->create([
            'client_id' => $clienteB->id,
            'address_id' => $enderecoB->id,
            'periodo_inicio' => '2026-07-01',
            'periodo_fim' => '2026-07-31',
            'gerado_em' => now(),
            'gerado_por' => $this->administrador->id,
            'dados' => ['visitas' => ['quantidade' => 0, 'datas' => []]],
            'publicado_no_portal' => true,
        ]));

        $usuarioPortalA = $this->criarUsuarioPortal($this->cliente, 'cliente-a@exemplo.test');
        $this->autenticarNoPortal($usuarioPortalA);

        $lista = $this->get('/portal/relatorios')->assertOk();
        $ids = collect($lista->viewData('page')['props']['relatorios']['data'])->pluck('id')->all();
        $this->assertContains($relatorioDoClienteA->id, $ids);
        $this->assertNotContains($relatorioDoClienteB->id, $ids, 'cliente A não pode ver relatório do cliente B');

        // Acesso direto ao id do outro cliente também é 404.
        $this->get("/portal/relatorios/{$relatorioDoClienteB->id}")->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas: acesso interno a relatório de outra empresa
    // -----------------------------------------------------------------

    /**
     * O teste acima cobre "cliente A não vê relatório do cliente B" pelo
     * PORTAL. Do lado do controller INTERNO (`MonitoringReportController`),
     * um usuário com `monitoramento-ver` pode legitimamente abrir o
     * relatório de qualquer cliente da PRÓPRIA empresa - é assim que o
     * módulo foi desenhado: a permissão não é escopada por cliente, só por
     * empresa (ver o comentário de `monitoramento-ver`/`monitoramento-gerar`
     * em `RolesAndPermissionsSeeder`, que dá as duas até para o papel
     * `tecnico`, sem restringir por cliente atendido). Não há, portanto,
     * "relatório de outro cliente da mesma empresa devolve 404" a testar
     * aqui sem contradizer o desenho.
     *
     * O vetor de vazamento real, e que nenhum teste deste arquivo, de
     * `VazamentoEntreEmpresasTest` (o comentário da fixture de
     * `MonitoringReport` ainda diz "nenhum dos três tem rota de CRUD ainda",
     * desatualizado desde a Task 21.4/21.5) ou de `IsolamentoDoPortalTest`
     * (que só cobre o portal) exercitava até agora, é o de OUTRA EMPRESA:
     * `MonitoringReport` usa `BelongsToCompany`, então o route-model-binding
     * de `{monitoringReport}` deveria devolver 404 sozinho nos quatro
     * endpoints que recebem o model pela rota. Este teste é a rede de
     * regressão para isso, no mesmo padrão de
     * `VazamentoEntreEmpresasTest::test_endpoints_aninhados_de_outra_empresa_devolvem_404`.
     */
    public function test_relatorio_de_outra_empresa_devolve_404_nos_endpoints_internos(): void
    {
        $outraEmpresa = Company::create([
            'name' => 'Dedetizadora Concorrente Monitoramento',
            'cnpj' => '88.888.888/0001-88',
            'email' => 'contato@concorrente-monitoramento.test',
        ]);

        $relatorioDaOutra = TenantAtual::comTenant($outraEmpresa->id, function (): MonitoringReport {
            $autorDaOutra = User::factory()->create(['is_active' => true]);
            $clienteDaOutra = ClientFactory::new()->create();
            $enderecoDaOutra = AddressFactory::new()->create(['client_id' => $clienteDaOutra->id]);

            return MonitoringReport::query()->create([
                'client_id' => $clienteDaOutra->id,
                'address_id' => $enderecoDaOutra->id,
                'periodo_inicio' => '2026-07-01',
                'periodo_fim' => '2026-07-31',
                'gerado_em' => now(),
                'gerado_por' => $autorDaOutra->id,
                'dados' => ['visitas' => ['quantidade' => 0, 'datas' => []]],
                'publicado_no_portal' => false,
            ]);
        });

        $this->actingAs($this->administrador)
            ->get(route('monitoramento.relatorios.show', $relatorioDaOutra))
            ->assertNotFound();
        $this->actingAs($this->administrador)
            ->get(route('monitoramento.relatorios.pdf', $relatorioDaOutra))
            ->assertNotFound();
        $this->actingAs($this->administrador)
            ->post(route('monitoramento.relatorios.publicar', $relatorioDaOutra))
            ->assertNotFound();
        $this->actingAs($this->administrador)
            ->post(route('monitoramento.relatorios.despublicar', $relatorioDaOutra))
            ->assertNotFound();
    }

    // -----------------------------------------------------------------
    // (f) Planta é enviada, substituída e posicionada pelos endpoints
    // -----------------------------------------------------------------

    public function test_planta_e_enviada_substituida_e_posicionada_pelos_endpoints(): void
    {
        $dispositivo = TenantAtual::comTenant($this->empresa->id, fn (): Device => Device::create([
            'address_id' => $this->endereco->id,
            'label' => 'Armadilha 01',
            'number' => 'A-01',
            'active' => true,
        ]));

        $envio = $this->actingAs($this->administrador)->post("/enderecos/{$this->endereco->id}/plantas", [
            'nome' => 'Térreo',
            'arquivo' => UploadedFile::fake()->image('planta.png', 800, 600),
        ]);
        $envio->assertRedirect(route('enderecos.plantas.index', $this->endereco));

        $planta = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): FloorPlan => FloorPlan::query()->where('address_id', $this->endereco->id)->firstOrFail()
        );
        $this->assertSame(1, $planta->versao);
        $this->assertTrue($planta->ativa);

        $listagem = $this->actingAs($this->administrador)->get("/enderecos/{$this->endereco->id}/plantas");
        $listagem->assertRedirect(route('enderecos.plantas.editor', $this->endereco));

        $editor = $this->actingAs($this->administrador)->get(route('plantas.editor', $planta->id));
        $editor->assertOk();
        $editor->assertInertia(fn ($page) => $page
            ->component('Plantas/Editor')
            ->where('planta.id', $planta->id)
            ->where('endereco.id', $this->endereco->id));

        $posicoes = $this->actingAs($this->administrador)->putJson("/plantas/{$planta->id}/posicoes", [
            'posicoes' => [
                ['device_id' => $dispositivo->id, 'x' => 0.3, 'y' => 0.4],
            ],
        ]);
        $posicoes->assertOk();
        $posicoes->assertJson(['success' => true]);

        $remocao = $this->actingAs($this->administrador)
            ->deleteJson(route('plantas.posicoes.remover', [$planta->id, $dispositivo->id]));
        $remocao->assertOk();
        $remocao->assertJson(['success' => true]);
        $this->assertDatabaseMissing('device_positions', [
            'floor_plan_id' => $planta->id,
            'device_id' => $dispositivo->id,
        ]);

        // Recoloca a posição para o restante do teste (substituição/cópia)
        // continuar exercitando o mesmo caminho de antes da remoção.
        $this->actingAs($this->administrador)->putJson("/plantas/{$planta->id}/posicoes", [
            'posicoes' => [
                ['device_id' => $dispositivo->id, 'x' => 0.3, 'y' => 0.4],
            ],
        ])->assertOk();

        $posicaoGravada = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): DevicePosition => DevicePosition::query()->where('floor_plan_id', $planta->id)->firstOrFail()
        );
        $this->assertEqualsWithDelta(0.3, (float) $posicaoGravada->x, 0.0001);

        $substituicao = $this->actingAs($this->administrador)->post("/plantas/{$planta->id}/substituir", [
            'nome' => 'Térreo',
            'arquivo' => UploadedFile::fake()->image('planta-v2.png', 900, 650),
        ]);
        $substituicao->assertRedirect(route('enderecos.plantas.index', $this->endereco));

        $plantaV2 = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): FloorPlan => FloorPlan::query()->where('address_id', $this->endereco->id)->where('versao', 2)->firstOrFail()
        );
        $this->assertTrue($plantaV2->ativa);
        $this->assertFalse($planta->refresh()->ativa);

        // A posição da versão 1 continua intacta, e a versão 2 já nasceu com
        // a posição copiada (comportamento de `FloorPlanService::substituir()`,
        // Task 21.4).
        $posicaoV2 = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): DevicePosition => DevicePosition::query()->where('floor_plan_id', $plantaV2->id)->firstOrFail()
        );
        $this->assertEqualsWithDelta(0.3, (float) $posicaoV2->x, 0.0001);

        $croqui = $this->actingAs($this->administrador)->get("/plantas/{$plantaV2->id}/croqui");
        $croqui->assertOk();
        $croqui->assertHeader('content-type', 'application/pdf');
    }

    // -----------------------------------------------------------------
    // (g) Usuário sem permissão recebe 403
    // -----------------------------------------------------------------

    public function test_usuario_sem_permissao_recebe_403(): void
    {
        $semPermissao = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): User => User::factory()->create(['is_active' => true])
        );

        $this->actingAs($semPermissao)->get('/monitoramento')->assertForbidden();
        $this->actingAs($semPermissao)->post('/monitoramento/relatorios', [
            'client_id' => $this->cliente->id,
            'de' => '2026-07-01',
            'ate' => '2026-07-31',
        ])->assertForbidden();
        $this->actingAs($semPermissao)->get("/enderecos/{$this->endereco->id}/plantas")->assertForbidden();

        // Com `monitoramento-ver` mas sem `monitoramento-publicar`, gerar até
        // funciona, mas publicar continua barrado: a permissão de publicar é
        // deliberadamente mais restrita que a de gerar.
        $tecnico = TenantAtual::comTenant($this->empresa->id, function (): User {
            $tecnico = User::factory()->create(['is_active' => true]);
            $tecnico->assignRole('tecnico');

            return $tecnico;
        });
        $relatorio = $this->gerarRelatorio();
        $this->actingAs($tecnico)
            ->post(route('monitoramento.relatorios.publicar', $relatorio))
            ->assertForbidden();
    }

    // -----------------------------------------------------------------
    // (h) Tenant sem o módulo vê a página de indisponível
    // -----------------------------------------------------------------

    public function test_tenant_sem_o_modulo_monitoramento_ve_pagina_de_indisponivel(): void
    {
        $empresaSemModulo = Company::create([
            'name' => 'Empresa Sem Monitoramento',
            'cnpj' => '66.666.666/0001-66',
            'email' => 'contato@sem-monitoramento.test',
        ]);

        $adminSemModulo = TenantAtual::comTenant($empresaSemModulo->id, function (): User {
            $usuario = User::factory()->create(['is_active' => true]);
            $usuario->assignRole('administrador');

            return $usuario;
        });

        $paginaHtml = $this->actingAs($adminSemModulo)->get('/monitoramento');
        $paginaHtml->assertRedirect(route('modulo-indisponivel', ['modulo' => 'monitoramento']));

        $respostaJson = $this->actingAs($adminSemModulo)->getJson('/monitoramento/relatorios');
        $respostaJson->assertStatus(403);
        $respostaJson->assertJsonPath('modulo', 'monitoramento');
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function gerarRelatorio(): MonitoringReport
    {
        $this->actingAs($this->administrador)->post('/monitoramento/relatorios', [
            'client_id' => $this->cliente->id,
            'address_id' => $this->endereco->id,
            'de' => '2026-07-01',
            'ate' => '2026-07-31',
        ]);

        return TenantAtual::comTenant(
            $this->empresa->id,
            fn (): MonitoringReport => MonitoringReport::query()->latest('id')->firstOrFail()
        );
    }

    private function criarUsuarioPortal(Client $cliente, string $email): ClientUser
    {
        return TenantAtual::comTenant($this->empresa->id, fn (): ClientUser => ClientUser::create([
            'client_id' => $cliente->id,
            'nome' => 'Usuário do portal',
            'email' => $email,
            'password' => Hash::make('senha-portal-123'),
            'ativo' => true,
            'email_verificado_em' => now(),
        ]));
    }

    private function autenticarNoPortal(ClientUser $usuario): void
    {
        $this->post('/portal/login', [
            'email' => $usuario->email,
            'password' => 'senha-portal-123',
        ])->assertRedirect();
    }
}
