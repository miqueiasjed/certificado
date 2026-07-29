<?php

namespace Tests\Feature;

use App\Http\Controllers\Publico\AgendamentoPublicoController;
use App\Http\Middleware\HandleInertiaPublicoRequests;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Requests\Publico\StoreAppointmentRequestRequest;
use App\Models\AppointmentRequest;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyAvailabilitySetting;
use App\Models\NotificationQueue;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PublicSchedulingService;
use App\Support\EventosDeNotificacao;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Database\Factories\ClientFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RotaRegistrada;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Task 16.3 do Plano 16: a página pública de pedido de horário, a primeira rota
 * do sistema que qualquer pessoa alcança sem login.
 *
 * O que este arquivo cobra, além dos critérios de aceitação da task: que nenhuma
 * rota de `routes/publico.php` devolva dado de quem está autenticado no
 * navegador. É a verificação que o INDEX do plano exige antes de expor qualquer
 * slug em produção, e ela é estrutural de propósito (percorre as rotas
 * registradas), para valer também para as rotas que a Task 16.5 vai acrescentar
 * ao mesmo arquivo.
 *
 * O relógio é fixado em todos os cenários: a grade conta antecedência mínima e
 * janela máxima a partir de hoje, e o dia escolhido no formulário precisa ser um
 * dos que a grade oferece. `HOJE` é uma segunda-feira, e o dia pedido
 * (`DIA_ESCOLHIDO`) é a quarta seguinte, dois dias à frente, que é exatamente a
 * antecedência mínima configurada.
 */
class AgendamentoPublicoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Segunda-feira, meio-dia em UTC (09:00 em Brasília), para o dia do fuso do
     * negócio e o dia em UTC coincidirem e o cenário não medir o defeito do
     * teste.
     */
    private const HOJE = '2026-08-10 12:00:00';

    private const DIA_ESCOLHIDO = '2026-08-12';

    private const SLUG = 'dedetizadora-a';

    private const SLUG_DESLIGADA = 'dedetizadora-b';

    private Company $empresa;

    private Company $empresaDesligada;

    private ServiceType $tipoDeServico;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        Carbon::setTestNow(self::HOJE);

        $this->seed(RolesAndPermissionsSeeder::class);

        // A empresa 1 vem da migration de fundação do tenant.
        $this->empresa = Company::query()->findOrFail(1);
        $this->empresa->update([
            'name' => 'Dedetizadora A',
            'email' => 'contato@dedetizadora-a.test',
            'phone' => '(11) 3333-1111',
            'slug_publico' => self::SLUG,
        ]);

        $this->empresaDesligada = Company::create([
            'name' => 'Dedetizadora B',
            'email' => 'contato@dedetizadora-b.test',
            'slug_publico' => self::SLUG_DESLIGADA,
        ]);

        // A empresa A aceita agendamento online; a B existe, tem slug e não
        // aceita. É o par que prova o 404 igual para os dois casos.
        $this->configurar($this->empresa, true);
        $this->configurar($this->empresaDesligada, false);

        TenantAtual::comTenant((int) $this->empresa->id, function (): void {
            Technician::create([
                'name' => 'Ana Ferreira',
                'email' => 'ana@dedetizadora-a.test',
                'phone' => '11999990000',
                'specialty' => 'Controle de pragas',
                'is_active' => true,
            ]);

            // O catálogo de tipos de serviço da empresa fundadora vem de
            // migration (`populate_service_types_table`), então aqui é só
            // pegar um deles em vez de criar outro.
            $this->tipoDeServico = ServiceType::query()->active()->ordered()->firstOrFail();
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // A página abre pelo slug e mostra a grade
    // -----------------------------------------------------------------

    public function test_pagina_abre_pelo_slug_e_mostra_a_grade(): void
    {
        $resposta = $this->get('/agendar/'.self::SLUG);

        $resposta->assertOk();

        $props = $this->propsDaPagina($resposta);

        $this->assertSame('Publico/Agendar', $this->componenteDaPagina($resposta));
        $this->assertSame('Dedetizadora A', $props['empresa']['nome']);
        $this->assertSame('2026-08', $props['mes']);

        // Tipo de serviço sai só com id e nome: nem slug, nem descrição, nem
        // ordenação interna.
        $this->assertNotEmpty($props['tipos_de_servico']);
        $this->assertSame(['id', 'nome'], array_keys($props['tipos_de_servico'][0]));
        $this->assertContains($this->tipoDeServico->id, array_column($props['tipos_de_servico'], 'id'));

        $this->assertNotEmpty($props['carregado_em'], 'sem carimbo o formulário não tem como provar o tempo mínimo');
        $this->assertSame(StoreAppointmentRequestRequest::CAMPO_ARMADILHA, $props['campo_armadilha']);

        $datas = array_column($props['grade'], 'data');

        $this->assertContains(self::DIA_ESCOLHIDO, $datas, 'a quarta-feira dentro da janela precisa aparecer');
        $this->assertNotContains('2026-08-10', $datas, 'hoje está antes da antecedência mínima de 2 dias');
        $this->assertNotContains('2026-08-15', $datas, 'sábado não é dia de atendimento');

        // A grade não pode expor carga da operação: só o dia e um booleano por
        // período, nada de contagem de vagas nem de técnico.
        $this->assertSame(['data', 'manha', 'tarde'], array_keys($props['grade'][0]));
        $this->assertSame(['disponivel'], array_keys($props['grade'][0]['manha']));
        $this->assertSame(['disponivel'], array_keys($props['grade'][0]['tarde']));
    }

    /**
     * A grade em JSON é o que qualquer visitante lê com o navegador aberto no
     * inspetor. Ela responde uma pergunta só: este período está disponível. Quantas
     * vagas sobraram e quem atende são dado de operação, e entregá-los conta à
     * concorrência o tamanho da equipe e a ocupação da agenda.
     *
     * Por isso a conferência é do JSON inteiro, dia a dia, e não só do primeiro
     * item: a chave a mais tende a aparecer no dia que tem alguma
     * particularidade.
     */
    public function test_grade_do_mes_responde_em_json_sem_expor_capacidade(): void
    {
        $resposta = $this->getJson('/agendar/'.self::SLUG.'/grade?mes=2026-09');

        $resposta->assertOk();
        $resposta->assertJsonPath('mes', '2026-09');

        $this->assertSame(['mes', 'grade'], array_keys($resposta->json()));

        $grade = $resposta->json('grade');

        $this->assertNotEmpty($grade);

        foreach ($grade as $dia) {
            $this->assertSame(['data', 'manha', 'tarde'], array_keys($dia));
            $this->assertStringStartsWith('2026-09-', $dia['data']);

            foreach ([AppointmentRequest::PERIODO_MANHA, AppointmentRequest::PERIODO_TARDE] as $periodo) {
                $this->assertSame(
                    ['disponivel'],
                    array_keys($dia[$periodo]),
                    "o período {$periodo} de {$dia['data']} tem chave além de disponivel"
                );
                $this->assertIsBool($dia[$periodo]['disponivel']);
            }
        }

        // E o corpo cru, contra o vocabulário de capacidade e contra o nome do
        // técnico da empresa, que existe no banco e não pode aparecer aqui.
        $corpo = $resposta->getContent();

        foreach ([
            'Ana Ferreira',
            'vagas', 'restantes', 'ocupa', 'capacidade', 'visitas_por_periodo',
            'tecnico', 'técnico', 'technician',
        ] as $termo) {
            $this->assertStringNotContainsStringIgnoringCase(
                $termo,
                $corpo,
                "a grade pública não pode conter \"{$termo}\""
            );
        }
    }

    // -----------------------------------------------------------------
    // 404: slug inexistente e agendamento desligado, sem diferença nenhuma
    // -----------------------------------------------------------------

    public function test_slug_inexistente_e_tenant_desligado_devolvem_o_mesmo_404(): void
    {
        // Sem a página de depuração: com `app.debug` ligado o Laravel devolve a
        // tela de erro detalhada, que carrega a URL pedida e impediria a
        // comparação byte a byte que é o ponto deste teste.
        config(['app.debug' => false]);

        $inexistente = $this->get('/agendar/nao-existe-ninguem');
        $desligada = $this->get('/agendar/'.self::SLUG_DESLIGADA);

        $inexistente->assertNotFound();
        $desligada->assertNotFound();

        $this->assertSame(
            $inexistente->content(),
            $desligada->content(),
            'a resposta precisa ser idêntica: diferença aqui confirma quais empresas existem no sistema'
        );

        $this->assertStringNotContainsString('Dedetizadora B', $desligada->content());
    }

    public function test_grade_e_envio_tambem_devolvem_404_para_tenant_desligado(): void
    {
        $this->get('/agendar/'.self::SLUG_DESLIGADA.'/grade')->assertNotFound();

        $this->post('/agendar/'.self::SLUG_DESLIGADA, $this->dadosValidos())->assertNotFound();

        $this->assertSame(0, AppointmentRequest::query()->count());
    }

    public function test_slug_com_caractere_fora_do_padrao_nao_chega_ao_controller(): void
    {
        $this->get('/agendar/Dedetizadora%20A')->assertNotFound();
        $this->get('/agendar/../../etc/passwd')->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Pedido bem-sucedido: pendente, empresa avisada, nenhuma OS
    // -----------------------------------------------------------------

    public function test_pedido_nasce_pendente_avisa_a_empresa_e_nao_cria_ordem_de_servico(): void
    {
        $resposta = $this->enviarPedido();

        $resposta->assertRedirect('/agendar/'.self::SLUG);
        $resposta->assertSessionHasNoErrors();
        $resposta->assertSessionHas('success', AgendamentoPublicoController::MENSAGEM_DE_RECEBIMENTO);

        $pedido = AppointmentRequest::query()->sole();

        $this->assertSame((int) $this->empresa->id, (int) $pedido->company_id);
        $this->assertSame(AppointmentRequest::SITUACAO_PENDENTE, $pedido->situacao);
        $this->assertSame(AppointmentRequest::ORIGEM_PAGINA_PUBLICA, $pedido->origem);
        $this->assertSame('Maria Souza', $pedido->nome);
        $this->assertSame('maria@exemplo.test', $pedido->email);
        $this->assertSame(self::DIA_ESCOLHIDO, $pedido->data_preferida->toDateString());
        $this->assertSame(AppointmentRequest::PERIODO_MANHA, $pedido->periodo);
        $this->assertSame($this->tipoDeServico->id, $pedido->service_type_id);
        $this->assertNull($pedido->work_order_id);
        $this->assertNull($pedido->respondida_em);

        $this->assertSame(0, WorkOrder::query()->count(), 'pedido de horário nunca cria ordem de serviço');

        $aviso = NotificationQueue::query()->sole();

        $this->assertSame(EventosDeNotificacao::SOLICITACAO_HORARIO_RECEBIDA, $aviso->evento);
        $this->assertSame(NotificationQueue::DESTINATARIO_EMPRESA, $aviso->destinatario_tipo);
        $this->assertSame('contato@dedetizadora-a.test', $aviso->destino);
        $this->assertSame((int) $this->empresa->id, (int) $aviso->company_id);
        $this->assertStringContainsString('Maria Souza', $aviso->corpo);
        $this->assertStringContainsString('12/08/2026', $aviso->corpo);
        $this->assertStringContainsString('manhã', $aviso->corpo);
    }

    public function test_pedido_sem_tipo_de_servico_e_aceito(): void
    {
        $this->enviarPedido(['service_type_id' => null])->assertSessionHasNoErrors();

        $this->assertNull(AppointmentRequest::query()->sole()->service_type_id);
    }

    public function test_tipo_de_servico_de_outra_empresa_e_recusado(): void
    {
        $tipoDeOutraEmpresa = TenantAtual::comTenant(
            (int) $this->empresaDesligada->id,
            fn (): ServiceType => ServiceType::create(['name' => 'Descupinização', 'active' => true])
        );

        $resposta = $this->enviarPedido(['service_type_id' => $tipoDeOutraEmpresa->id]);

        $resposta->assertSessionHasErrors('service_type_id');
        $this->assertSame(0, AppointmentRequest::query()->count());
    }

    // -----------------------------------------------------------------
    // Limite de pedidos por hora
    // -----------------------------------------------------------------

    public function test_quarto_pedido_na_mesma_hora_pelo_mesmo_ip_e_recusado(): void
    {
        foreach (['(11) 98888-0001', '(11) 98888-0002', '(11) 98888-0003'] as $telefone) {
            $this->enviarPedido(['telefone' => $telefone])->assertSessionHasNoErrors();
        }

        $this->assertSame(3, AppointmentRequest::query()->count());

        $quarto = $this->enviarPedido(['telefone' => '(11) 98888-0004']);

        $quarto->assertRedirect('/agendar/'.self::SLUG);
        $quarto->assertSessionHasErrors('limite');
        $quarto->assertSessionMissing('success');

        $this->assertSame(3, AppointmentRequest::query()->count(), 'o quarto pedido da hora não pode ser gravado');
    }

    public function test_segundo_pedido_do_mesmo_telefone_na_mesma_hora_e_recusado(): void
    {
        $this->enviarPedido(['telefone' => '(11) 98888-7777'])->assertSessionHasNoErrors();

        // Telefone igual, escrito de outra forma (com código do país e sem
        // máscara): a contagem é por dígitos, então continua sendo o mesmo
        // número.
        $segundo = $this->enviarPedido([
            'telefone' => '+55 11 988887777',
            'email' => 'outro@exemplo.test',
        ]);

        $segundo->assertSessionHasErrors('limite');
        $this->assertSame(1, AppointmentRequest::query()->count());
    }

    /**
     * O limite conta pedido gravado, e não requisição: quem erra o formulário
     * não pode perder a cota da hora, senão um engano de digitação deixa o
     * visitante sem conseguir pedir horário.
     */
    public function test_erro_de_validacao_nao_consome_o_limite_da_hora(): void
    {
        $this->enviarPedido(['email' => 'nao-e-email'])->assertSessionHasErrors('email');
        $this->enviarPedido(['telefone' => '1234'])->assertSessionHasErrors('telefone');

        $this->assertSame(0, AppointmentRequest::query()->count());

        // Mesmo telefone do envio recusado acima, agora com tudo válido.
        $this->enviarPedido()->assertSessionHasNoErrors();

        $this->assertSame(1, AppointmentRequest::query()->count());
    }

    // -----------------------------------------------------------------
    // Campo armadilha e tempo mínimo
    // -----------------------------------------------------------------

    public function test_campo_armadilha_preenchido_descarta_o_pedido_sem_erro_visivel(): void
    {
        $armadilha = $this->enviarPedido([
            StoreAppointmentRequestRequest::CAMPO_ARMADILHA => 'https://spam.example',
        ]);

        $armadilha->assertRedirect('/agendar/'.self::SLUG);
        $armadilha->assertSessionHasNoErrors();
        $armadilha->assertSessionHas('success', AgendamentoPublicoController::MENSAGEM_DE_RECEBIMENTO);

        $this->assertSame(0, AppointmentRequest::query()->count(), 'o pedido do robô não pode ser gravado');
        $this->assertSame(0, NotificationQueue::query()->count(), 'ninguém é avisado de um pedido descartado');

        // A resposta do descarte precisa ser indistinguível da de um pedido bom:
        // é o que impede o robô de descobrir qual campo o delatou.
        $legitimo = $this->enviarPedido(['telefone' => '(11) 98888-0002']);

        $this->assertSame($legitimo->getStatusCode(), $armadilha->getStatusCode());
        $this->assertSame(
            $legitimo->headers->get('Location'),
            $armadilha->headers->get('Location')
        );
        $this->assertSame(1, AppointmentRequest::query()->count());
    }

    /**
     * O campo armadilha preenchido também não pode ser delatado por uma
     * validação: enviado junto com dado inválido, a resposta continua sendo a
     * confirmação, e não a lista de erros do formulário.
     */
    public function test_campo_armadilha_vence_qualquer_erro_de_validacao(): void
    {
        $resposta = $this->enviarPedido([
            StoreAppointmentRequestRequest::CAMPO_ARMADILHA => 'x',
            'email' => 'nao-e-email',
            'telefone' => '',
            'data_preferida' => '1999-01-01',
        ]);

        $resposta->assertSessionHasNoErrors();
        $resposta->assertSessionHas('success', AgendamentoPublicoController::MENSAGEM_DE_RECEBIMENTO);
        $this->assertSame(0, AppointmentRequest::query()->count());
    }

    public function test_envio_em_menos_de_tres_segundos_e_recusado(): void
    {
        $carimbo = $this->carimboDaPagina();

        // Nenhum avanço de relógio: o envio acontece no mesmo instante do
        // carregamento.
        $resposta = $this->post('/agendar/'.self::SLUG, $this->dadosValidos(['carregado_em' => $carimbo]));

        $resposta->assertSessionHasErrors('carregado_em');
        $this->assertSame(0, AppointmentRequest::query()->count());

        // O mesmo formulário, depois dos três segundos, passa: a recusa é do
        // tempo, não do carimbo.
        $this->travel(PublicSchedulingService::SEGUNDOS_MINIMOS_DE_PREENCHIMENTO + 1)->seconds();

        $this->post('/agendar/'.self::SLUG, $this->dadosValidos(['carregado_em' => $carimbo]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, AppointmentRequest::query()->count());
    }

    public function test_carimbo_ausente_ou_adulterado_e_recusado(): void
    {
        $dados = $this->dadosValidos();
        unset($dados['carregado_em']);

        $this->post('/agendar/'.self::SLUG, $dados)->assertSessionHasErrors('carregado_em');

        // Instante em texto puro, do jeito que alguém tentaria burlar o tempo
        // mínimo sem passar pela página.
        $this->post('/agendar/'.self::SLUG, $this->dadosValidos([
            'carregado_em' => (string) (Carbon::now()->getTimestamp() - 600),
        ]))->assertSessionHasErrors('carregado_em');

        $this->assertSame(0, AppointmentRequest::query()->count());
    }

    // -----------------------------------------------------------------
    // Dia e período precisam ser os que a grade oferece
    // -----------------------------------------------------------------

    public function test_dia_fora_da_grade_e_recusado(): void
    {
        // Sábado: fora dos dias de atendimento configurados.
        $this->enviarPedido(['data_preferida' => '2026-08-15'])
            ->assertSessionHasErrors('data_preferida');

        // Dentro da antecedência mínima de 2 dias.
        $this->enviarPedido(['data_preferida' => '2026-08-11'])
            ->assertSessionHasErrors('data_preferida');

        // Passado.
        $this->enviarPedido(['data_preferida' => '2026-08-03'])
            ->assertSessionHasErrors('data_preferida');

        $this->assertSame(0, AppointmentRequest::query()->count());
    }

    public function test_periodo_fora_do_catalogo_e_recusado(): void
    {
        $this->enviarPedido(['periodo' => 'noite'])->assertSessionHasErrors('periodo');

        $this->assertSame(0, AppointmentRequest::query()->count());
    }

    // -----------------------------------------------------------------
    // Vínculo automático com cliente existente, sem revelar nada
    // -----------------------------------------------------------------

    public function test_telefone_de_cliente_existente_vincula_sem_revelar(): void
    {
        $cliente = TenantAtual::comTenant(
            (int) $this->empresa->id,
            fn (): Client => ClientFactory::new()->create([
                'name' => 'Padaria Central',
                'email' => 'financeiro@padaria.test',
                'phone' => '(11) 98888-7777',
            ])
        );

        // Telefone do cadastro, digitado sem máscara e com código do país. O
        // e-mail é outro de propósito: quem vincula aqui é o telefone.
        $resposta = $this->enviarPedido([
            'email' => 'compras@padaria.test',
            'telefone' => '5511988887777',
        ]);

        $pedido = AppointmentRequest::query()->sole();

        $this->assertSame($cliente->id, $pedido->client_id, 'o pedido precisa nascer vinculado ao cliente existente');

        // E a resposta ao visitante não pode dar nenhum sinal disso: nem no
        // texto, nem em erro, nem em prop da página que ele recebe depois.
        $resposta->assertSessionHas('success', AgendamentoPublicoController::MENSAGEM_DE_RECEBIMENTO);
        $resposta->assertSessionHasNoErrors();

        $pagina = $this->get('/agendar/'.self::SLUG);
        $conteudo = $pagina->content();

        $this->assertStringNotContainsString('Padaria Central', $conteudo);
        $this->assertStringNotContainsString('financeiro@padaria.test', $conteudo);
        $this->assertStringNotContainsString('98888-7777', $conteudo);

        $props = $this->propsDaPagina($pagina);

        foreach (['client_id', 'cliente', 'pedido', 'pedidos'] as $prop) {
            $this->assertArrayNotHasKey($prop, $props, "a prop {$prop} entregaria dado de cliente numa página pública");
        }
    }

    public function test_email_de_cliente_existente_tambem_vincula(): void
    {
        $cliente = TenantAtual::comTenant(
            (int) $this->empresa->id,
            fn (): Client => ClientFactory::new()->create([
                'name' => 'Mercado Bom Preço',
                'email' => 'Contato@Mercado.test',
                'phone' => '(11) 3555-1234',
            ])
        );

        $this->enviarPedido(['email' => 'contato@mercado.test', 'telefone' => '(11) 97777-1111']);

        $this->assertSame($cliente->id, AppointmentRequest::query()->sole()->client_id);
    }

    public function test_cliente_de_outra_empresa_nao_vincula(): void
    {
        TenantAtual::comTenant(
            (int) $this->empresaDesligada->id,
            fn (): Client => ClientFactory::new()->create([
                'name' => 'Cliente da Concorrente',
                'email' => 'maria@exemplo.test',
                'phone' => '(11) 98888-7777',
            ])
        );

        $this->enviarPedido(['email' => 'maria@exemplo.test', 'telefone' => '(11) 98888-7777']);

        $this->assertNull(
            AppointmentRequest::query()->sole()->client_id,
            'cliente de outra empresa jamais pode ser vinculado a um pedido deste tenant'
        );
    }

    // -----------------------------------------------------------------
    // Nenhuma rota pública devolve dado de quem está autenticado
    // -----------------------------------------------------------------

    public function test_nenhuma_rota_publica_expoe_dado_autenticado(): void
    {
        $rotas = $this->rotasPublicas();

        foreach ($rotas as $rota) {
            $identificacao = $rota->methods()[0].' '.$rota->uri();
            $middleware = app('router')->gatherRouteMiddleware($rota);

            $this->assertNotContains(
                HandleInertiaRequests::class,
                $middleware,
                "{$identificacao} usa a ponte Inertia do painel, que compartilha auth.user, permissões e módulos"
            );

            $this->assertContains(
                HandleInertiaPublicoRequests::class,
                $middleware,
                "{$identificacao} precisa da ponte Inertia pública, que não compartilha nada de autenticado"
            );

            $this->assertContains(
                ValidateCsrfToken::class,
                $middleware,
                "{$identificacao} está fora da verificação de CSRF"
            );

            foreach (['auth', 'auth:sanctum', 'auth.cliente', 'tenant.ativo'] as $exigenciaDeLogin) {
                $this->assertNotContains(
                    $exigenciaDeLogin,
                    $rota->middleware(),
                    "{$identificacao} não pode exigir login: a rota é pública por definição"
                );
            }

            // A lista da própria rota, e não a resolvida: `gatherRouteMiddleware`
            // já troca `throttle:nome` pela classe do middleware com o parâmetro
            // colado, e o que interessa aqui é que a rota declarou um limite.
            $this->assertTrue(
                collect($rota->middleware())->contains(
                    fn ($item): bool => is_string($item) && str_starts_with($item, 'throttle:')
                ),
                "{$identificacao} está sem limite de taxa, e rota pública sem limite vira depósito de spam"
            );

            $this->assertStringStartsWith(
                'App\\Http\\Controllers\\Publico\\',
                (string) $rota->getAction('controller'),
                "{$identificacao} precisa ser atendida por um controller de App\\Http\\Controllers\\Publico"
            );
        }
    }

    /**
     * A contraprova do teste acima, com requisição de verdade: cada rota de
     * leitura de `routes/publico.php` é aberta com um funcionário autenticado no
     * navegador, e nenhuma pode devolver nada dele.
     *
     * O teste estrutural garante o envelope (ponte Inertia pública, nenhum
     * middleware de login); este garante o efeito, e é ele que pega o caso do
     * controller que compartilha dado da sessão por conta própria, sem depender de
     * middleware nenhum. Uma rota de leitura acrescentada ao arquivo entra aqui
     * sozinha.
     *
     * Só os métodos de leitura: escrita pública devolve redirecionamento, e
     * conferir gravação em laço exigiria payload válido por rota, que é o que os
     * cenários específicos de cada uma já fazem.
     */
    public function test_nenhuma_rota_publica_de_leitura_devolve_dado_da_sessao(): void
    {
        $administrador = TenantAtual::comTenant(
            (int) $this->empresa->id,
            fn (): User => User::factory()->create([
                'name' => 'Funcionaria Logada Noutra Aba',
                'email' => 'funcionaria@dedetizadora-a.test',
            ])
        );
        $administrador->assignRole('administrador');

        $this->actingAs($administrador);

        // Token sorteado de propósito: a pesquisa não existe, e a página de link
        // inválido também é resposta pública e também não pode vazar sessão.
        $parametros = [
            'slug' => self::SLUG,
            'token' => Str::random(64),
        ];

        $conferidas = 0;

        foreach ($this->rotasPublicas() as $rota) {
            if (! in_array('GET', $rota->methods(), true)) {
                continue;
            }

            $identificacao = 'GET '.$rota->uri();
            $url = '/'.$rota->uri();

            foreach ($rota->parameterNames() as $parametro) {
                $this->assertArrayHasKey(
                    $parametro,
                    $parametros,
                    "{$identificacao} tem o parâmetro {$parametro}, que este teste não sabe preencher: "
                    .'acrescente um valor válido para a rota nova entrar na conferência'
                );

                $url = str_replace(
                    ['{'.$parametro.'}', '{'.$parametro.'?}'],
                    (string) $parametros[$parametro],
                    $url
                );
            }

            $resposta = $this->get($url);

            $resposta->assertOk();
            $conferidas++;

            foreach (array_keys($this->propsOuJson($resposta)) as $chave) {
                $this->assertNotContains(
                    $chave,
                    ['auth', 'suporte', 'avisos', 'modulos', 'onboarding', 'solicitacoesAbertas', 'clienteLogado'],
                    "{$identificacao} devolve a chave {$chave}, que vem da sessão autenticada"
                );
            }

            foreach ([$administrador->name, $administrador->email, 'administrador'] as $dado) {
                $this->assertStringNotContainsString(
                    (string) $dado,
                    $resposta->content(),
                    "{$identificacao} devolveu \"{$dado}\", que é dado de quem está logado no painel"
                );
            }
        }

        $this->assertSame(
            3,
            $conferidas,
            'as rotas de leitura de routes/publico.php são a página de agendamento, a grade e a página da pesquisa'
        );
    }

    /**
     * Limite de requisição estourado devolve 429 com texto em português, e não a
     * página crua "Too Many Requests": quem cai aqui é o cliente final da
     * empresa, numa página sem login.
     */
    public function test_limite_de_leitura_da_pagina_responde_em_portugues(): void
    {
        for ($tentativa = 1; $tentativa <= 30; $tentativa++) {
            $this->get('/agendar/'.self::SLUG)->assertOk();
        }

        $bloqueada = $this->get('/agendar/'.self::SLUG);

        $bloqueada->assertStatus(429);
        $bloqueada->assertSee('Muitas tentativas');
        $bloqueada->assertDontSee('Too Many');
    }

    /**
     * O limite de requisição do envio (10 por minuto por IP) é outra camada, e
     * atende a outro risco: o limite de negócio conta pedido gravado, então robô
     * mandando POST em laço com dado inválido nunca encostaria nele, e cada
     * tentativa custaria validação e cálculo de grade.
     *
     * Os dez envios saem com dado inválido de propósito: é o formato da marretada,
     * e prova que a contagem do middleware é de requisição, sem depender de nada
     * ter sido gravado.
     */
    public function test_limite_de_envio_do_pedido_responde_em_portugues(): void
    {
        for ($tentativa = 1; $tentativa <= 10; $tentativa++) {
            $this->post('/agendar/'.self::SLUG, [])->assertSessionHasErrors();
        }

        $bloqueada = $this->post('/agendar/'.self::SLUG, $this->dadosValidos());

        $bloqueada->assertStatus(429);
        $bloqueada->assertSee('Muitas tentativas');
        $bloqueada->assertDontSee('Too Many');

        $this->assertSame(0, AppointmentRequest::query()->count(), 'nada foi gravado em nenhuma das tentativas');

        // A leitura da página continua liberada: os dois limitadores são
        // independentes, e quem estourou o de envio ainda vê a página com o aviso
        // do formulário.
        $this->get('/agendar/'.self::SLUG)->assertOk();
    }

    /**
     * O caso concreto do vazamento: funcionário logado no painel abre a página
     * pública em outra aba. A resposta não pode carregar nada dele.
     */
    public function test_pagina_publica_aberta_com_sessao_de_funcionario_nao_devolve_dado_dele(): void
    {
        $administrador = TenantAtual::comTenant(
            (int) $this->empresa->id,
            fn (): User => User::factory()->create([
                'name' => 'Administradora da Empresa',
                'email' => 'admin@dedetizadora-a.test',
            ])
        );
        $administrador->assignRole('administrador');

        $this->actingAs($administrador);

        $resposta = $this->get('/agendar/'.self::SLUG);

        $resposta->assertOk();

        $props = $this->propsDaPagina($resposta);

        foreach (['auth', 'suporte', 'avisos', 'modulos', 'onboarding', 'solicitacoesAbertas', 'clienteLogado'] as $prop) {
            $this->assertArrayNotHasKey($prop, $props, "a prop {$prop} vem da sessão autenticada e não pode sair aqui");
        }

        $this->assertStringNotContainsString('admin@dedetizadora-a.test', $resposta->content());
        $this->assertStringNotContainsString('administrador', $resposta->content());

        // O Ziggy da casca pública só publica as rotas de routes/publico.php: a
        // lista inteira entregaria o mapa da aplicação (financeiro, plataforma,
        // portal) a qualquer visitante.
        foreach (['financial-dashboard', 'plataforma', 'work-orders', '/portal'] as $rotaInterna) {
            $this->assertStringNotContainsString(
                $rotaInterna,
                $resposta->content(),
                "a página pública não pode publicar a rota interna {$rotaInterna}"
            );
        }

        $this->assertStringContainsString('publico.agendar.show', $resposta->content());

        // E a grade em JSON, pelo mesmo caminho.
        $grade = $this->getJson('/agendar/'.self::SLUG.'/grade');

        $grade->assertOk();
        $this->assertSame(['mes', 'grade'], array_keys($grade->json()));
    }

    /**
     * A sessão do funcionário também não pode virar o tenant da página: quem
     * decide a empresa aqui é o slug da URL, sempre.
     */
    public function test_tenant_da_pagina_vem_do_slug_e_nao_do_usuario_logado(): void
    {
        $daEmpresaDesligada = TenantAtual::comTenant(
            (int) $this->empresaDesligada->id,
            fn (): User => User::factory()->create(['name' => 'Gerente da B'])
        );

        $this->actingAs($daEmpresaDesligada);

        $resposta = $this->get('/agendar/'.self::SLUG);

        $resposta->assertOk();
        $this->assertSame('Dedetizadora A', $this->propsDaPagina($resposta)['empresa']['nome']);

        // E o pedido enviado por esse navegador nasce na empresa do slug.
        $this->enviarPedido()->assertSessionHasNoErrors();

        // `deTodasAsEmpresas()` de propósito: com o usuário da empresa B
        // autenticado no teste, o escopo global resolveria o tenant por ele e a
        // consulta olharia para a empresa errada, que é exatamente o que este
        // cenário existe para negar.
        $pedido = AppointmentRequest::query()->deTodasAsEmpresas()->sole();

        $this->assertSame((int) $this->empresa->id, (int) $pedido->company_id);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function configurar(Company $empresa, bool $aceita): CompanyAvailabilitySetting
    {
        return TenantAtual::comTenant(
            (int) $empresa->id,
            fn (): CompanyAvailabilitySetting => CompanyAvailabilitySetting::create([
                'dias_da_semana' => [1, 2, 3, 4, 5],
                'visitas_por_periodo' => 4,
                'antecedencia_minima_dias' => 2,
                'janela_maxima_dias' => 60,
                'aceita_agendamento_online' => $aceita,
            ])
        );
    }

    /**
     * Abre a página, espera o tempo mínimo e envia o formulário, que é o
     * caminho de um visitante de verdade.
     *
     * @param  array<string, mixed>  $sobrescritos
     */
    private function enviarPedido(array $sobrescritos = []): TestResponse
    {
        $carimbo = $this->carimboDaPagina();

        $this->travel(PublicSchedulingService::SEGUNDOS_MINIMOS_DE_PREENCHIMENTO + 1)->seconds();

        return $this->post(
            '/agendar/'.self::SLUG,
            $this->dadosValidos(array_merge(['carregado_em' => $carimbo], $sobrescritos))
        );
    }

    /**
     * Carimbo cifrado que a própria página entrega, nunca um valor montado no
     * teste: é assim que o formulário real prova o instante de carregamento.
     */
    private function carimboDaPagina(): string
    {
        $resposta = $this->get('/agendar/'.self::SLUG);

        $resposta->assertOk();

        return $this->propsDaPagina($resposta)['carregado_em'];
    }

    /**
     * @param  array<string, mixed>  $sobrescritos
     * @return array<string, mixed>
     */
    private function dadosValidos(array $sobrescritos = []): array
    {
        return array_merge([
            'nome' => 'Maria Souza',
            'email' => 'maria@exemplo.test',
            'telefone' => '(11) 98888-7777',
            'endereco_texto' => 'Rua das Acácias, 120, Centro',
            'service_type_id' => $this->tipoDeServico->id,
            'data_preferida' => self::DIA_ESCOLHIDO,
            'periodo' => AppointmentRequest::PERIODO_MANHA,
            'observacao' => 'Portão azul, tocar a campainha.',
            'carregado_em' => app(PublicSchedulingService::class)->carimboDeCarregamento(),
        ], $sobrescritos);
    }

    /**
     * @return array<string, mixed>
     */
    private function propsDaPagina(TestResponse $resposta): array
    {
        return $resposta->viewData('page')['props'];
    }

    /**
     * As props da página Inertia, ou o corpo do JSON quando a rota responde JSON
     * (a grade do mês). Serve para conferir o mesmo recorte nas duas formas de
     * resposta pública.
     *
     * @return array<string, mixed>
     */
    private function propsOuJson(TestResponse $resposta): array
    {
        if (str_contains((string) $resposta->headers->get('content-type'), 'json')) {
            return (array) $resposta->json();
        }

        return $this->propsDaPagina($resposta);
    }

    /**
     * Todas as rotas de `routes/publico.php`, tomadas pelo envelope que
     * `bootstrap/app.php` aplica ao arquivo inteiro: o grupo de middleware
     * `publico`.
     *
     * O critério é o grupo, e não o prefixo de nome, porque rota registrada sem
     * `->name()` fica sem nome nenhum (o prefixo `publico.` do grupo não inventa
     * um) e escaparia calada de toda a conferência deste arquivo. O nome é cobrado
     * aqui dentro, rota por rota, porque dele dependem esta conferência e a página
     * de 429 em português, que `bootstrap/app.php` decide por
     * `routeIs('publico.*')`.
     *
     * @return \Illuminate\Support\Collection<int, RotaRegistrada>
     */
    private function rotasPublicas(): Collection
    {
        $rotas = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RotaRegistrada $rota): bool => in_array('publico', $rota->middleware(), true))
            ->values();

        $this->assertNotEmpty(
            $rotas,
            'nenhuma rota do grupo de middleware publico foi registrada: '
            .'ou routes/publico.php ficou vazio, ou o envelope de bootstrap/app.php mudou de nome'
        );

        foreach ($rotas as $rota) {
            $this->assertStringStartsWith(
                'publico.',
                (string) $rota->getName(),
                $rota->methods()[0].' '.$rota->uri().' está sem nome publico.*: sem ele a rota não recebe a '
                .'página de 429 em português e escapa da conferência das rotas públicas'
            );
        }

        return $rotas;
    }

    private function componenteDaPagina(TestResponse $resposta): string
    {
        return $resposta->viewData('page')['component'];
    }
}
