<?php

namespace Tests\Feature;

use App\Models\ActiveIngredient;
use App\Models\Address;
use App\Models\Antidote;
use App\Models\BaitType;
use App\Models\Certificate;
use App\Models\ChemicalGroup;
use App\Models\Client;
use App\Models\Company;
use App\Models\Device;
use App\Models\EventType;
use App\Models\OnboardingStep;
use App\Models\OrganRegistration;
use App\Models\Product;
use App\Models\Room;
use App\Models\Service;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDeviceEvent;
use App\Services\TenantService;
use App\Support\CatalogoInicialDoTenant;
use App\Support\PassosDeOnboarding;
use App\Support\TenantAtual;
use Database\Seeders\CatalogoInicialSeeder;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * Task 8.10 do Plano 8: o portão do plano.
 *
 * O caso que decide tudo é
 * `test_fluxo_completo_de_os_e_certificado_sem_ajuste_manual`. Uma empresa nasce
 * pela rota pública `/cadastro`, e a partir dali o teste opera só pelos
 * endpoints reais do sistema, autenticado como o administrador que aquele
 * cadastro criou: cliente, endereço, cômodo, dispositivo, técnico, ordem de
 * serviço, cômodo e dispositivo na OS, evento do dispositivo, produto,
 * conclusão e certificado. Nenhuma linha é inserida por model, factory ou SQL no
 * meio do caminho, e nenhum cadastro de apoio é criado à mão: tipo de isca, tipo
 * de evento, serviço e produto saem todos do catálogo inicial (Task 8.2).
 *
 * DUAS LACUNAS DO CATÁLOGO ENCONTRADAS POR ESTE TESTE
 * ---------------------------------------------------
 * A primeira execução parou duas vezes, e as duas correções foram no catálogo,
 * nunca na asserção:
 *
 * 1. `services` estava vazia no tenant novo, e `WorkOrderRequest` exige
 *    `service_id`. O catálogo semeava `service_types`, que é outra tabela e não
 *    participa do fluxo de OS. Corrigido em
 *    `CatalogoInicialDoTenant::servicos()`.
 * 2. `products` estava vazia, e lançar produto na OS ou no certificado exige um
 *    produto cadastrado. Os quatro cadastros regulatórios de que ele depende já
 *    vinham no catálogo; o produto que os amarra, não. Corrigido em
 *    `CatalogoInicialDoTenant::produtos()`.
 *
 * Se um passo do fluxo voltar a exigir cadastro que o provisionamento não cria,
 * a correção continua sendo no catálogo. Enfraquecer um caso daqui para o teste
 * passar desmonta o único ponto do sistema que prova que a empresa nova consegue
 * trabalhar sozinha.
 */
class OnboardingCompletoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Carimbo do tenant fundador (empresa #1, criada pela migration de
     * fundação). Sem acento e sem espaço, mesmo critério de
     * `VazamentoEntreEmpresasTest`: JSON escapa acento em \uXXXX e a marca
     * deixaria de casar na busca textual.
     */
    private const MARCA_FUNDADOR = 'MARCADOFUNDADOR';

    /**
     * Payload do formulário público. Os nomes são os de
     * `CadastroEmpresaRequest`; nenhum campo de privilégio existe aqui, e isso
     * é conferido em `test_tenant_novo_nasce_em_avaliacao_e_nao_interno`.
     *
     * @var array<string, mixed>
     */
    private const CADASTRO = [
        'name' => 'Dedetizadora Nascente',
        'cnpj' => '44.444.444/0001-44',
        'phone' => '11 4004-4004',
        'administrador_nome' => 'Ana Administradora',
        'administrador_email' => 'ana@nascente.test',
        'administrador_senha' => 'senha-forte-123',
        'administrador_senha_confirmation' => 'senha-forte-123',
        'aceite_termos' => true,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        // Papéis e permissões são globais (Spatie sem `teams`, decisão do Plano
        // 2): sem eles, o `assignRole('administrador')` do provisionamento
        // estoura e nenhuma empresa nasce.
        $this->seed(RolesAndPermissionsSeeder::class);

        // Catálogo de módulos do Plano 6. O fluxo de OS e certificado não passa
        // por nenhuma rota com `module:<chave>`, mas o middleware do Inertia
        // compartilha a lista de módulos ativos em toda navegação, e sem a
        // tabela semeada a prop sairia vazia para todo mundo, escondendo uma
        // regressão de módulo em vez de mostrá-la.
        $this->seed(ModulesSeeder::class);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // 1. O cadastro público
    // -----------------------------------------------------------------

    public function test_cadastro_publico_cria_empresa_administrador_e_cadastros_e_autentica(): void
    {
        $this->assertGuest();

        $empresa = $this->cadastrarPelaRotaPublica();

        $this->assertSame(self::CADASTRO['name'], $empresa->name);
        $this->assertSame(self::CADASTRO['cnpj'], $empresa->cnpj);
        $this->assertSame(self::CADASTRO['phone'], $empresa->phone);
        $this->assertSame(
            self::CADASTRO['administrador_email'],
            $empresa->email,
            'a empresa nasce com o e-mail do administrador como contato: é para ele que a régua de avaliação escreve'
        );

        $administrador = $this->administradorDe($empresa);

        $this->assertSame($empresa->id, (int) $administrador->company_id);
        $this->assertTrue($administrador->hasRole('administrador'));
        $this->assertTrue((bool) $administrador->is_active);
        $this->assertFalse(
            (bool) $administrador->is_platform_admin,
            'cadastro público não pode fabricar super admin da plataforma'
        );
        $this->assertNotSame(
            self::CADASTRO['administrador_senha'],
            $administrador->password,
            'a senha é gravada com hash'
        );

        // Autenticado na hora, sem passar pelo login.
        $this->assertAuthenticatedAs($administrador);

        // Cadastros e trilha nasceram junto, dentro da mesma transação.
        $this->noTenant($empresa, function (): void {
            $this->assertGreaterThan(0, EventType::query()->count());
            $this->assertGreaterThan(0, Service::query()->count());
            $this->assertGreaterThan(0, Product::query()->count());

            $passos = OnboardingStep::query()->get();

            $this->assertCount(count(PassosDeOnboarding::catalogo()), $passos);
            $this->assertTrue(
                $passos->every(fn (OnboardingStep $passo): bool => $passo->concluido_em === null && $passo->ignorado_em === null),
                'a trilha do tenant novo nasce inteira pendente'
            );
        });
    }

    // -----------------------------------------------------------------
    // 2. O fluxo inteiro, pelos endpoints reais
    // -----------------------------------------------------------------

    public function test_fluxo_completo_de_os_e_certificado_sem_ajuste_manual(): void
    {
        $empresa = $this->cadastrarPelaRotaPublica();
        $criados = $this->executarFluxoCompleto($empresa);

        $this->noTenant($empresa, function () use ($criados): void {
            $ordem = WorkOrder::query()->findOrFail($criados['workOrder']->id);

            $this->assertSame('completed', $ordem->status, 'a OS não foi concluída pelo endpoint de atualização');
            $this->assertCount(1, $ordem->rooms, 'o cômodo não ficou vinculado à OS');
            $this->assertCount(1, $ordem->devices, 'o dispositivo não ficou vinculado à OS');
            $this->assertCount(1, $ordem->products, 'o produto não ficou vinculado à OS');
            $this->assertCount(1, $ordem->technicians, 'o técnico não ficou vinculado à OS');

            $evento = WorkOrderDeviceEvent::query()->firstOrFail();

            $this->assertSame($ordem->id, (int) $evento->work_order_id);
            $this->assertSame($criados['device']->id, (int) $evento->device_id);

            $certificado = Certificate::query()->firstOrFail();

            $this->assertSame($ordem->id, (int) $certificado->work_order_id);
            $this->assertSame($criados['client']->id, (int) $certificado->client_id);
            $this->assertSame($criados['address']->id, (int) $certificado->address_id);
            $this->assertNotEmpty(
                $certificado->certificate_number,
                'o certificado emitido saiu sem número: documento com valor perante fiscalização precisa de numeração'
            );
        });

        // A tela do certificado abre para quem o emitiu.
        $this->get(route('certificates.show', $criados['certificate']->id))->assertOk();
    }

    // -----------------------------------------------------------------
    // 3. Os documentos em PDF
    // -----------------------------------------------------------------

    public function test_pdf_da_os_e_do_certificado_sao_gerados_sem_erro(): void
    {
        $empresa = $this->cadastrarPelaRotaPublica();
        $criados = $this->executarFluxoCompleto($empresa);

        $pdfDaOrdem = $this->get("/work-orders/{$criados['workOrder']->id}/pdf");

        $pdfDaOrdem->assertOk();
        $pdfDaOrdem->assertHeader('content-type', 'application/pdf');

        $pdfDoCertificado = $this->get("/certificates/{$criados['certificate']->id}/pdf");

        $pdfDoCertificado->assertOk();
        $pdfDoCertificado->assertHeader('content-type', 'application/pdf');

        // O PDF da OS cai em `back()` com erro quando a geração falha, em vez de
        // estourar. Sem conferir o conteúdo, uma falha de renderização passaria
        // como redirecionamento de sucesso.
        $this->assertStringStartsWith('%PDF', $pdfDaOrdem->content());
        $this->assertStringStartsWith('%PDF', $pdfDoCertificado->content());
    }

    // -----------------------------------------------------------------
    // 4. Todo registro nasce no tenant novo
    // -----------------------------------------------------------------

    public function test_todo_registro_criado_nasce_no_company_id_do_tenant_novo(): void
    {
        $empresa = $this->cadastrarPelaRotaPublica();
        $criados = $this->executarFluxoCompleto($empresa);

        $criados['user'] = $this->administradorDe($empresa);
        $criados['deviceEvent'] = $this->noTenant($empresa, fn () => WorkOrderDeviceEvent::query()->firstOrFail());

        foreach ($criados as $rotulo => $registro) {
            $classe = $registro::class;

            $this->assertSame(
                $empresa->id,
                (int) $registro->company_id,
                "{$rotulo} ({$classe}) nasceu fora do tenant novo: company_id = {$registro->company_id}"
            );
        }

        // Nenhum registro do fluxo escapou para a empresa fundadora.
        foreach (['clients', 'addresses', 'rooms', 'devices', 'technicians', 'work_orders', 'certificates'] as $tabela) {
            $this->assertSame(
                0,
                DB::table($tabela)->where('company_id', '!=', $empresa->id)->count(),
                "a tabela {$tabela} tem registro fora do tenant novo, e o fluxo inteiro rodou dentro dele"
            );
        }
    }

    // -----------------------------------------------------------------
    // 5. Isolamento em relação ao tenant 1
    // -----------------------------------------------------------------

    public function test_tenant_novo_nao_enxerga_nenhum_dado_do_tenant_1(): void
    {
        // Sem a página de depuração: ela imprime o código-fonte deste arquivo em
        // qualquer 500, e a marca do fundador apareceria como falso positivo.
        config(['app.debug' => false]);

        $fundador = $this->tenantFundador();
        $doFundador = $this->criarConjuntoNoFundador($fundador);

        $empresa = $this->cadastrarPelaRotaPublica();
        $this->executarFluxoCompleto($empresa);

        $telas = [
            '/dashboard',
            '/clients',
            '/addresses',
            '/rooms',
            '/devices',
            '/technicians',
            '/work-orders',
            '/certificates',
            '/products',
            '/services',
            '/cadastros',
            '/settings/users',
            '/settings/convites',
        ];

        foreach ($telas as $tela) {
            $resposta = $this->get($tela);

            $this->assertLessThan(
                500,
                $resposta->status(),
                "GET {$tela} respondeu {$resposta->status()}: erro de servidor esconde vazamento"
            );

            $this->assertStringNotContainsString(
                self::MARCA_FUNDADOR,
                $this->corpoDaResposta($resposta),
                "GET {$tela} devolveu dado do tenant 1 para o tenant recém-criado"
            );
        }

        // Acesso direto por id: o escopo global não encontra o registro e o
        // binding responde 404, sem revelar que ele existe.
        $acessosDiretos = [
            "/addresses/{$doFundador['address']->id}",
            "/rooms/{$doFundador['room']->id}",
            "/devices/{$doFundador['device']->id}",
            "/technicians/{$doFundador['technician']->id}",
            "/work-orders/{$doFundador['workOrder']->id}",
            "/certificates/{$doFundador['certificate']->id}",
            "/certificates/{$doFundador['certificate']->id}/pdf",
            "/work-orders/{$doFundador['workOrder']->id}/pdf",
        ];

        foreach ($acessosDiretos as $url) {
            $this->assertSame(
                404,
                $this->get($url)->status(),
                "GET {$url} alcançou registro do tenant 1 a partir do tenant novo"
            );
        }

        // E no Eloquent, antes de qualquer rota.
        $this->noTenant($empresa, function () use ($doFundador): void {
            foreach ($doFundador as $rotulo => $registro) {
                $classe = $registro::class;

                $this->assertNull(
                    $classe::query()->whereKey($registro->id)->first(),
                    "{$rotulo} ({$classe}) do tenant 1 foi encontrado dentro do tenant novo"
                );
            }
        });

        // Rede contra passe vazio: as mesmas telas mostram o dado do próprio
        // tenant novo.
        $this->assertStringContainsString(
            'Padaria do Bairro',
            $this->corpoDaResposta($this->get('/clients')),
            'a listagem de clientes do tenant novo não mostrou nem o cliente dele: a varredura acima estaria passando à toa'
        );
    }

    // -----------------------------------------------------------------
    // 6. O catálogo inteiro chegou
    // -----------------------------------------------------------------

    public function test_cadastros_de_apoio_e_regulatorios_existem_com_a_contagem_do_catalogo(): void
    {
        $empresa = $this->cadastrarPelaRotaPublica();

        $esperado = [
            EventType::class => CatalogoInicialDoTenant::tiposDeEvento(),
            BaitType::class => CatalogoInicialDoTenant::tiposDeIsca(),
            ServiceType::class => CatalogoInicialDoTenant::tiposDeServico(),
            Service::class => CatalogoInicialDoTenant::servicos(),
            ChemicalGroup::class => CatalogoInicialDoTenant::gruposQuimicos(),
            Antidote::class => CatalogoInicialDoTenant::antidotos(),
            ActiveIngredient::class => CatalogoInicialDoTenant::principiosAtivos(),
            OrganRegistration::class => CatalogoInicialDoTenant::registrosEmOrgao(),
            Product::class => CatalogoInicialDoTenant::produtos(),
        ];

        $this->noTenant($empresa, function () use ($esperado): void {
            foreach ($esperado as $model => $itens) {
                $this->assertSame(
                    count($itens),
                    $model::query()->count(),
                    "o tenant novo não recebeu o catálogo completo de {$model}"
                );
            }

            // O produto do catálogo chega amarrado aos quatro cadastros
            // regulatórios: é o vínculo que o certificado imprime.
            $produto = Product::query()->firstOrFail();

            $this->assertNotNull($produto->activeIngredient, 'produto do catálogo sem princípio ativo');
            $this->assertNotNull($produto->chemicalGroup, 'produto do catálogo sem grupo químico');
            $this->assertNotNull($produto->antidote, 'produto do catálogo sem antídoto');
            $this->assertNotNull($produto->organRegistration, 'produto do catálogo sem registro no Ministério da Saúde');
        });
    }

    // -----------------------------------------------------------------
    // 7. A trilha anda sozinha
    // -----------------------------------------------------------------

    public function test_trilha_de_primeiros_passos_avanca_sozinha_conforme_os_passos_sao_feitos(): void
    {
        $empresa = $this->cadastrarPelaRotaPublica();

        // O cadastro público preenche CNPJ e telefone, mas não o endereço da
        // empresa: `dados_empresa` continua pendente e é a prova de que a
        // avaliação não marca passo por marcar.
        $inicial = $this->trilhaDoDashboard();

        $this->assertSame('pendente', $inicial['dados_empresa']);
        $this->assertSame('pendente', $inicial['primeiro_cliente']);
        $this->assertSame('pendente', $inicial['primeiro_tecnico']);
        $this->assertSame('pendente', $inicial['primeira_os']);

        $this->post('/clients', [
            'name' => 'Padaria do Bairro',
            'email' => 'contato@padaria.test',
            'phone' => '11933330000',
            'cnpj' => '55.555.555/0001-55',
        ])->assertSessionHasNoErrors();

        $depoisDoCliente = $this->trilhaDoDashboard();

        $this->assertSame(
            'concluido',
            $depoisDoCliente['primeiro_cliente'],
            'cadastrar o primeiro cliente não fechou o passo `primeiro_cliente` na avaliação seguinte'
        );
        $this->assertSame('pendente', $depoisDoCliente['primeiro_tecnico'], 'a avaliação fechou um passo que ninguém cumpriu');

        $this->post('/technicians', [
            'name' => 'Tiago Técnico',
            'email' => 'tiago@nascente.test',
            'phone' => '11922220000',
        ])->assertSessionHasNoErrors();

        $depoisDoTecnico = $this->trilhaDoDashboard();

        $this->assertSame('concluido', $depoisDoTecnico['primeiro_tecnico']);
        $this->assertSame('concluido', $depoisDoTecnico['primeiro_cliente'], 'passo já concluído voltou a pendente');
        $this->assertSame('pendente', $depoisDoTecnico['dados_empresa']);

        // Nada disso dependeu de clique: os registros gravados em
        // `onboarding_steps` acompanham o que a tela mostra.
        $this->noTenant($empresa, function (): void {
            $this->assertNotNull(
                OnboardingStep::query()->where('chave', 'primeiro_cliente')->firstOrFail()->concluido_em
            );
            $this->assertNull(
                OnboardingStep::query()->where('chave', 'dados_empresa')->firstOrFail()->concluido_em
            );
        });
    }

    // -----------------------------------------------------------------
    // 8. Falha no meio do cadastro
    // -----------------------------------------------------------------

    public function test_falha_no_meio_do_cadastro_nao_deixa_empresa_orfa(): void
    {
        $empresasAntes = Company::query()->count();

        // Falha depois de a empresa e o administrador já terem sido gravados,
        // no meio da semeadura do catálogo. É o pior momento possível: sem a
        // transação de `TenantService::criar()`, sobraria uma empresa com
        // administrador e sem cadastro nenhum, com cara de pronta na listagem da
        // plataforma.
        $this->app->bind(CatalogoInicialSeeder::class, fn (): CatalogoInicialSeeder => new class extends CatalogoInicialSeeder
        {
            public function run(): void
            {
                throw new RuntimeException('falha simulada no meio do provisionamento');
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->post('/cadastro', self::CADASTRO);
            $this->fail('a falha no provisionamento deveria ter subido em vez de gravar a empresa pela metade');
        } catch (RuntimeException $erro) {
            $this->assertStringContainsString('falha simulada', $erro->getMessage());
        }

        $this->assertSame(
            $empresasAntes,
            Company::query()->count(),
            'a empresa ficou gravada mesmo com o provisionamento falhando'
        );
        $this->assertSame(
            0,
            Company::query()->where('cnpj', self::CADASTRO['cnpj'])->count(),
            'sobrou uma empresa órfã com o CNPJ do cadastro que falhou'
        );
        $this->assertSame(
            0,
            User::query()->where('email', self::CADASTRO['administrador_email'])->count(),
            'sobrou o usuário administrador de uma empresa que não existe'
        );
        // Ninguém sai autenticado de um cadastro que falhou.
        $this->assertGuest();

        // E o mesmo cadastro passa depois que a causa da falha some, sem que
        // nada precise ser limpo à mão.
        $this->app->forgetInstance(CatalogoInicialSeeder::class);
        $this->app->bind(CatalogoInicialSeeder::class, fn (): CatalogoInicialSeeder => new CatalogoInicialSeeder);

        $empresa = $this->cadastrarPelaRotaPublica();

        $this->assertSame($empresasAntes + 1, Company::query()->count());
        $this->noTenant($empresa, fn () => $this->assertGreaterThan(0, EventType::query()->count()));
    }

    // -----------------------------------------------------------------
    // 9. Situação inicial do tenant
    // -----------------------------------------------------------------

    public function test_tenant_novo_nasce_em_avaliacao_e_nao_interno(): void
    {
        $empresa = $this->cadastrarPelaRotaPublica();

        $this->assertSame(TenantService::SITUACAO_EM_AVALIACAO, $empresa->situacao);
        $this->assertNotNull($empresa->trial_ends_at, 'a empresa entrou em avaliação sem prazo marcado');

        $this->assertFalse(
            (bool) $empresa->is_internal,
            'o cadastro público criou um tenant INTERNO: ele é imune a suspensão e a limite de plano, e nenhum caminho público pode criar um'
        );

        $this->assertNull($empresa->plan_id, 'a empresa entra em avaliação e escolhe o plano depois, pela tela de assinatura');
        $this->assertNull($empresa->suspensa_em);

        // Nem enviando os campos de plataforma no formulário.
        $forjado = self::CADASTRO;
        $forjado['cnpj'] = '66.666.666/0001-66';
        $forjado['administrador_email'] = 'forjado@nascente.test';
        $forjado['is_internal'] = true;
        $forjado['situacao'] = TenantService::SITUACAO_ATIVA;
        $forjado['trial_ends_at'] = '2099-12-31';

        $this->post('/logout');
        $this->post('/cadastro', $forjado)->assertSessionHasNoErrors();

        $forjada = Company::query()->where('cnpj', $forjado['cnpj'])->firstOrFail();

        $this->assertFalse((bool) $forjada->is_internal, 'o formulário público conseguiu fabricar um tenant interno');
        $this->assertSame(TenantService::SITUACAO_EM_AVALIACAO, $forjada->situacao);
        $this->assertNotSame('2099-12-31', (string) $forjada->trial_ends_at);
    }

    // -----------------------------------------------------------------
    // Apoio: o fluxo completo
    // -----------------------------------------------------------------

    /**
     * Roda o fluxo inteiro pelos endpoints reais, autenticado como o
     * administrador que o cadastro público criou, e devolve os registros
     * gerados.
     *
     * Nenhum `create()` de model aqui, de propósito: o valor deste teste está
     * exatamente em não ter atalho. As consultas por `firstOrFail()` só leem o
     * que o endpoint anterior gravou, porque os controllers respondem com
     * redirecionamento e não devolvem o registro criado.
     *
     * @return array<string, Model>
     */
    private function executarFluxoCompleto(Company $empresa): array
    {
        $catalogo = $this->catalogoDoTenant($empresa);

        $this->post('/clients', [
            'name' => 'Padaria do Bairro',
            'email' => 'contato@padaria.test',
            'phone' => '11933330000',
            'cnpj' => '55.555.555/0001-55',
        ])->assertSessionHasNoErrors();

        $cliente = $this->noTenant($empresa, fn () => Client::query()->firstOrFail());

        $this->post('/addresses', [
            'client_id' => $cliente->id,
            'nickname' => 'Matriz',
            'street' => 'Rua das Flores',
            'number' => '100',
            'district' => 'Centro',
            'city' => 'Sao Paulo',
            'state' => 'SP',
            'zip' => '01000-000',
        ])->assertSessionHasNoErrors();

        $endereco = $this->noTenant($empresa, fn () => Address::query()->firstOrFail());

        $this->post('/rooms', [
            'address_id' => $endereco->id,
            'name' => 'Cozinha',
        ])->assertSessionHasNoErrors();

        $comodo = $this->noTenant($empresa, fn () => Room::query()->firstOrFail());

        // O tipo de isca sai do catálogo inicial, sem cadastro manual.
        $this->post('/devices', [
            'address_id' => $endereco->id,
            'label' => 'Porta-isca da cozinha',
            'number' => 'PI-001',
            'bait_type_id' => $catalogo['baitType']->id,
        ])->assertSessionHasNoErrors();

        $dispositivo = $this->noTenant($empresa, fn () => Device::query()->firstOrFail());

        $this->post('/technicians', [
            'name' => 'Tiago Técnico',
            'email' => 'tiago@nascente.test',
            'phone' => '11922220000',
            'registration_number' => 'CRQ-0001',
        ])->assertSessionHasNoErrors();

        $tecnico = $this->noTenant($empresa, fn () => Technician::query()->firstOrFail());

        // `service_id` é obrigatório em `WorkOrderRequest`, e o serviço vem do
        // catálogo: é a primeira das duas lacunas que este teste encontrou.
        $this->post('/work-orders', [
            'client_id' => $cliente->id,
            'address_id' => $endereco->id,
            'service_id' => $catalogo['service']->id,
            'priority_level' => 'medium',
            'scheduled_date' => '2026-08-10',
            'status' => 'scheduled',
            'description' => 'Primeira ordem de serviço da empresa.',
            'technicians' => [$tecnico->id],
        ])->assertSessionHasNoErrors();

        $ordem = $this->noTenant($empresa, fn () => WorkOrder::query()->firstOrFail());

        // Cômodo na OS, com o tipo de evento vindo do catálogo.
        $this->post("/work-orders/{$ordem->id}/rooms", [
            'room_id' => $comodo->id,
            'event_type' => $catalogo['eventType']->id,
            'event_date' => '2026-08-10',
            'event_description' => 'Inspeção da cozinha.',
        ])->assertSessionHasNoErrors();

        // Dispositivo na OS.
        $this->put("/work-orders/{$ordem->id}", [
            'client_id' => $cliente->id,
            'address_id' => $endereco->id,
            'service_id' => $catalogo['service']->id,
            'priority_level' => 'medium',
            'scheduled_date' => '2026-08-10',
            'status' => 'in_progress',
            'devices' => [['id' => $dispositivo->id]],
        ])->assertSessionHasNoErrors();

        // Evento do dispositivo.
        $this->post("/work-orders/{$ordem->id}/devices/{$dispositivo->id}/events", [
            'event_type' => $catalogo['eventType']->id,
            'event_date' => '2026-08-10',
            'event_description' => 'Consumo parcial da isca.',
        ])->assertSessionHasNoErrors();

        // Produto na OS: a segunda lacuna do catálogo que este teste encontrou.
        $this->post("/work-orders/{$ordem->id}/products/{$catalogo['product']->id}", [
            'quantity' => 2,
            'unit' => 'unidade',
            'observations' => 'Reposição de isca.',
        ])->assertSessionHasNoErrors();

        // Conclusão da OS.
        $this->put("/work-orders/{$ordem->id}", [
            'client_id' => $cliente->id,
            'address_id' => $endereco->id,
            'service_id' => $catalogo['service']->id,
            'priority_level' => 'medium',
            'scheduled_date' => '2026-08-10',
            'status' => 'completed',
            'completion_notes' => 'Serviço executado e cômodo liberado.',
        ])->assertSessionHasNoErrors();

        // Certificado emitido a partir da OS.
        $this->post("/work-orders/{$ordem->id}/certificates", [
            'execution_date' => '2026-08-10',
            'warranty' => '2026-11-10',
            'procedure_used' => 'Aplicação de isca em estação porta-isca e inspeção dos pontos.',
            'notes' => 'Certificado da primeira ordem de serviço.',
        ])->assertSessionHasNoErrors();

        $certificado = $this->noTenant($empresa, fn () => Certificate::query()->firstOrFail());

        return [
            'client' => $cliente,
            'address' => $endereco,
            'room' => $comodo,
            'device' => $dispositivo,
            'technician' => $tecnico,
            'workOrder' => $ordem,
            'certificate' => $certificado,
        ];
    }

    // -----------------------------------------------------------------
    // Apoio: cadastro, tenant fundador e leitura
    // -----------------------------------------------------------------

    private function cadastrarPelaRotaPublica(?array $dados = null): Company
    {
        $dados ??= self::CADASTRO;

        $resposta = $this->post('/cadastro', $dados);

        $resposta->assertSessionHasNoErrors();
        $resposta->assertRedirect(route('dashboard'));

        return Company::query()->where('cnpj', $dados['cnpj'])->firstOrFail();
    }

    private function administradorDe(Company $empresa): User
    {
        return $empresa->users()->where('email', self::CADASTRO['administrador_email'])->firstOrFail();
    }

    /**
     * A empresa #1, criada pela migration de fundação e marcada como interna.
     * É o "tenant 1" do caso de isolamento.
     */
    private function tenantFundador(): Company
    {
        return Company::query()->where('is_internal', true)->firstOrFail();
    }

    /**
     * Conjunto de dado do tenant fundador, todo carimbado com a marca dele.
     *
     * Aqui os registros nascem por model de propósito: eles são o cenário
     * contra o qual o isolamento é medido, não o objeto do teste. O fluxo do
     * tenant novo, esse sim, roda inteiro pelos endpoints.
     *
     * @return array<string, Model>
     */
    private function criarConjuntoNoFundador(Company $fundador): array
    {
        return TenantAtual::comTenant($fundador->id, function (): array {
            $cliente = Client::create([
                'name' => 'Cliente '.self::MARCA_FUNDADOR,
                'email' => 'cliente@fundador.test',
                'phone' => '11911110000',
                'cnpj' => '11.111.111/0001-11',
                'notes' => 'Observacao '.self::MARCA_FUNDADOR,
            ]);

            $endereco = Address::create([
                'client_id' => $cliente->id,
                'nickname' => 'Unidade '.self::MARCA_FUNDADOR,
                'street' => 'Rua '.self::MARCA_FUNDADOR,
                'number' => '1',
                'district' => 'Bairro '.self::MARCA_FUNDADOR,
                'city' => 'Cidade'.self::MARCA_FUNDADOR,
                'state' => 'SP',
                'zip' => '02000-000',
                'active' => true,
            ]);

            $comodo = Room::create([
                'address_id' => $endereco->id,
                'name' => 'Comodo '.self::MARCA_FUNDADOR,
                'active' => true,
            ]);

            $isca = BaitType::create(['name' => 'Isca '.self::MARCA_FUNDADOR]);

            $dispositivo = Device::create([
                'address_id' => $endereco->id,
                'bait_type_id' => $isca->id,
                'label' => 'Dispositivo '.self::MARCA_FUNDADOR,
                'number' => 'DISP-'.self::MARCA_FUNDADOR,
                'active' => true,
            ]);

            $tecnico = Technician::create([
                'name' => 'Tecnico '.self::MARCA_FUNDADOR,
                'email' => 'tecnico@fundador.test',
                'phone' => '11900000000',
                'is_active' => true,
            ]);

            $servico = Service::create([
                'name' => 'Servico '.self::MARCA_FUNDADOR,
                'description' => 'Descricao '.self::MARCA_FUNDADOR,
                'price' => 400,
                'is_active' => true,
            ]);

            $principio = ActiveIngredient::create(['name' => 'Principio '.self::MARCA_FUNDADOR]);
            $grupo = ChemicalGroup::create(['name' => 'Grupo '.self::MARCA_FUNDADOR]);
            $antidoto = Antidote::create(['name' => 'Antidoto '.self::MARCA_FUNDADOR]);
            $registro = OrganRegistration::create(['record' => 'REGISTRO-'.self::MARCA_FUNDADOR]);

            $produto = Product::create([
                'name' => 'Produto '.self::MARCA_FUNDADOR,
                'active_ingredient_id' => $principio->id,
                'chemical_group_id' => $grupo->id,
                'antidote_id' => $antidoto->id,
                'organ_registration_id' => $registro->id,
            ]);

            $ordem = WorkOrder::create([
                'order_number' => 'OS-'.self::MARCA_FUNDADOR,
                'client_id' => $cliente->id,
                'address_id' => $endereco->id,
                'service_id' => $servico->id,
                'scheduled_date' => '2026-07-01',
                'status' => 'completed',
                'description' => 'Ordem '.self::MARCA_FUNDADOR,
                'active' => true,
            ]);

            $certificado = Certificate::create([
                'client_id' => $cliente->id,
                'address_id' => $endereco->id,
                'work_order_id' => $ordem->id,
                'service_id' => $servico->id,
                'certificate_number' => 'CERT-'.self::MARCA_FUNDADOR,
                'execution_date' => '2026-07-01',
                'warranty' => '2026-10-01',
                'status' => 'active',
                'notes' => 'Certificado '.self::MARCA_FUNDADOR,
            ]);

            return [
                'client' => $cliente,
                'address' => $endereco,
                'room' => $comodo,
                'baitType' => $isca,
                'device' => $dispositivo,
                'technician' => $tecnico,
                'service' => $servico,
                'activeIngredient' => $principio,
                'chemicalGroup' => $grupo,
                'antidote' => $antidoto,
                'organRegistration' => $registro,
                'product' => $produto,
                'workOrder' => $ordem,
                'certificate' => $certificado,
            ];
        });
    }

    /**
     * Os cadastros do catálogo inicial que o fluxo consome. Todos vêm do
     * provisionamento; nenhum é criado por este teste.
     *
     * @return array<string, Model>
     */
    private function catalogoDoTenant(Company $empresa): array
    {
        return $this->noTenant($empresa, fn (): array => [
            'baitType' => BaitType::query()->orderBy('id')->firstOrFail(),
            'eventType' => EventType::query()->orderBy('id')->firstOrFail(),
            'service' => Service::query()->orderBy('id')->firstOrFail(),
            'product' => Product::query()->orderBy('id')->firstOrFail(),
        ]);
    }

    /**
     * Estado de cada passo da trilha, do jeito que o dashboard entrega para a
     * tela: passando pelo middleware do Inertia, que é quem chama
     * `OnboardingService::avaliar()`.
     *
     * @return array<string, string> chave do passo => estado
     */
    private function trilhaDoDashboard(): array
    {
        $resposta = $this->get('/dashboard');

        $resposta->assertOk();

        $onboarding = $resposta->inertiaProps('onboarding');

        $this->assertIsArray($onboarding, 'o dashboard parou de compartilhar a trilha de primeiros passos');

        return collect($onboarding['passos'])->pluck('estado', 'chave')->all();
    }

    private function noTenant(Company $empresa, callable $acao): mixed
    {
        return TenantAtual::comTenant($empresa->id, $acao);
    }

    /**
     * Corpo de uma resposta, seja ela uma página comum ou um download em
     * fluxo. Rota nova que devolva `StreamedResponse` entra na varredura de
     * vazamento sem quebrar o teste com "the response is not streamed".
     */
    private function corpoDaResposta(mixed $resposta): string
    {
        return $resposta->baseResponse instanceof StreamedResponse
            ? $resposta->streamedContent()
            : $resposta->getContent();
    }
}
