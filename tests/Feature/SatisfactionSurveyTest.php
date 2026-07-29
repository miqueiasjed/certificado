<?php

namespace Tests\Feature;

use App\Http\Controllers\Publico\PesquisaController;
use App\Models\Address;
use App\Models\AppointmentRequest;
use App\Models\Client;
use App\Models\Company;
use App\Models\NotificationQueue;
use App\Models\SatisfactionSurvey;
use App\Models\ScheduledTaskRun;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\SatisfactionSurveyService;
use App\Support\EventosDeNotificacao;
use App\Support\RotinasAgendadas;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Factories\ClientFactory;
use Database\Factories\WorkOrderFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Task 16.5 do Plano 16: a pesquisa de satisfação, do disparo automático até a
 * resposta em página pública sem login.
 *
 * O relógio é fixado em um instante em que o dia em UTC e o dia no fuso do
 * negócio **divergem**: 02:00 de 11/08 em UTC são 23:00 de 10/08 em Brasília.
 * Sem essa divergência os cenários de "concluída ontem" passariam mesmo com a
 * comparação feita em UTC, que é justamente o defeito que a task não pode ter: a
 * visita encerrada às 22h30 de Brasília já é do dia seguinte em UTC, e uma rotina
 * que comparasse assim deixaria a pesquisa dela para nunca.
 *
 * Todo instante é declarado em UTC de propósito. `Carbon::setTestNow()` empresta
 * o fuso da instância mockada para toda data criada sem fuso explícito, então
 * fixar o relógio em Brasília faria as asserções medirem o defeito do teste.
 */
class SatisfactionSurveyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 02:00 de 11/08 em UTC, que é 23:00 de 10/08 em Brasília.
     */
    private const AGORA = '2026-08-11 02:00:00';

    /** Dia de hoje no fuso do negócio. */
    private const HOJE = '2026-08-10';

    /** Dia de ontem no fuso do negócio, o que a rotina envia. */
    private const ONTEM = '2026-08-09';

    private const ANTEONTEM = '2026-08-08';

    /** Hoje + 30 dias: o último dia em que o link responde. */
    private const EXPIRA_EM = '2026-09-09';

    private Company $empresa;

    private Company $outraEmpresa;

    private Technician $tecnico;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        Carbon::setTestNow(Carbon::parse(self::AGORA, 'UTC'));

        // A chave nasce desligada para o deploy (ver config/notificacoes.php).
        // Ligada aqui porque é o comportamento com o envio ativo que os cenários
        // medem; a chave desligada tem cenário próprio.
        config(['notificacoes.pesquisa_satisfacao_ativa' => true]);

        $this->seed(RolesAndPermissionsSeeder::class);

        // A empresa 1 vem da migration de fundação do tenant.
        $this->empresa = Company::query()->findOrFail(1);
        $this->empresa->update([
            'name' => 'Dedetizadora A',
            'email' => 'contato@dedetizadora-a.test',
            'phone' => '(11) 3333-1111',
        ]);

        $this->outraEmpresa = Company::create([
            'name' => 'Dedetizadora B',
            'email' => 'contato@dedetizadora-b.test',
            'phone' => '(11) 3333-2222',
        ]);

        $this->tecnico = TenantAtual::comTenant(
            (int) $this->empresa->id,
            fn (): Technician => Technician::create([
                'name' => 'Ana Ferreira',
                'email' => 'ana@dedetizadora-a.test',
                'phone' => '11999990000',
                'specialty' => 'Controle de pragas',
                'is_active' => true,
            ])
        );

        // Guarda do próprio teste: sem esta divergência nenhum cenário de fuso
        // deste arquivo exercita o que se propõe a exercitar.
        $this->assertSame('2026-08-11', Carbon::now('UTC')->toDateString());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // A rotina envia as pesquisas das visitas concluídas ontem
    // -----------------------------------------------------------------

    public function test_rotina_envia_pesquisa_das_visitas_concluidas_ontem_no_fuso_do_negocio(): void
    {
        // Encerrada às 22h30 de Brasília de ontem, que em UTC já é hoje. É a
        // visita que uma comparação em UTC deixaria de fora.
        $deOntem = $this->visitaConcluida('Padaria Central', self::ONTEM.' 22:30');

        // Sem `end_time` registrado: o corte cai no dia agendado.
        $deOntemSemHora = $this->visitaConcluida('Mercado Bom Preço', null, self::ONTEM);

        // Encerrada hoje: pesquisa no mesmo dia da conclusão pega o cliente antes
        // de ele ter visto o resultado do serviço.
        $deHoje = $this->visitaConcluida('Restaurante do Porto', self::HOJE.' 20:00');

        $deAnteontem = $this->visitaConcluida('Escola Girassol', self::ANTEONTEM.' 15:00');

        $this->artisan('pesquisas:enviar')->assertSuccessful();

        $pesquisadas = SatisfactionSurvey::query()
            ->deTodasAsEmpresas()
            ->pluck('work_order_id')
            ->all();

        sort($pesquisadas);

        $esperadas = [$deOntem->id, $deOntemSemHora->id];
        sort($esperadas);

        $this->assertSame($esperadas, $pesquisadas);
        $this->assertNull($this->pesquisaDaVisita($deHoje), 'visita concluída hoje não recebe pesquisa hoje');
        $this->assertNull($this->pesquisaDaVisita($deAnteontem), 'a rotina envia só as de ontem');

        $pesquisa = $this->pesquisaDaVisita($deOntem);

        $this->assertNotNull($pesquisa);
        $this->assertSame((int) $this->empresa->id, (int) $pesquisa->company_id);
        $this->assertSame(SatisfactionSurveyService::TAMANHO_DO_TOKEN, strlen($pesquisa->token));
        $this->assertSame(self::EXPIRA_EM, $pesquisa->expira_em->toDateString());
        $this->assertNotNull($pesquisa->enviada_em);
        $this->assertNull($pesquisa->respondida_em);
        $this->assertNull($pesquisa->nota);
        $this->assertFalse($pesquisa->pendencia_de_contato);
        $this->assertSame($this->tecnico->id, $pesquisa->technician_id);

        // O convite sai pela central do Plano 14, com o link da página pública.
        $convite = $this->avisos(EventosDeNotificacao::PESQUISA_SATISFACAO)
            ->firstWhere('destino', 'padaria-central@exemplo.test');

        $this->assertNotNull($convite, 'o cliente precisa receber o convite da pesquisa');
        $this->assertSame(NotificationQueue::DESTINATARIO_CLIENTE, $convite->destinatario_tipo);
        $this->assertSame(EventosDeNotificacao::CANAL_EMAIL, $convite->canal);
        $this->assertStringContainsString('/pesquisa/'.$pesquisa->token, $convite->corpo);
        $this->assertStringContainsString((string) SatisfactionSurveyService::DIAS_DE_VALIDADE, $convite->corpo);
    }

    public function test_rotina_rodada_duas_vezes_no_mesmo_dia_nao_duplica_nada(): void
    {
        $this->visitaConcluida('Padaria Central', self::ONTEM.' 09:00');

        $this->artisan('pesquisas:enviar')->assertSuccessful();
        $this->artisan('pesquisas:enviar')->assertSuccessful();

        $this->assertSame(1, SatisfactionSurvey::query()->deTodasAsEmpresas()->count());
        $this->assertSame(1, $this->avisos(EventosDeNotificacao::PESQUISA_SATISFACAO)->count());
    }

    /**
     * A chave nasce desligada, por exigência da ordem de aplicação em produção do
     * plano: o deploy sobe com o envio desligado e a empresa confere antes de
     * ligar. A rotina precisa terminar com sucesso mesmo assim, ou a verificação
     * de rotina parada (Task 14.5) passaria a acusar falha todo dia.
     */
    public function test_envio_desligado_nao_cria_pesquisa_e_a_rotina_continua_com_sucesso(): void
    {
        config(['notificacoes.pesquisa_satisfacao_ativa' => false]);

        $this->visitaConcluida('Padaria Central', self::ONTEM.' 09:00');

        $this->artisan('pesquisas:enviar')->assertSuccessful();

        $this->assertSame(0, SatisfactionSurvey::query()->deTodasAsEmpresas()->count());
        $this->assertSame(0, NotificationQueue::query()->deTodasAsEmpresas()->count());
    }

    public function test_rotina_aparece_em_scheduled_task_runs(): void
    {
        $this->assertArrayHasKey('pesquisas:enviar', RotinasAgendadas::DIARIAS);

        $this->artisan('schedule:test', ['--name' => 'pesquisas:enviar'])->assertSuccessful();

        $rodada = ScheduledTaskRun::query()->daTarefa('pesquisas:enviar')->first();

        $this->assertNotNull($rodada, 'a rodada da rotina não foi registrada em scheduled_task_runs');
        $this->assertNotNull($rodada->started_at);
        $this->assertNotNull($rodada->finished_at);
    }

    public function test_pesquisa_herda_o_tipo_de_servico_do_pedido_de_horario(): void
    {
        $visita = $this->visitaConcluida('Padaria Central', self::ONTEM.' 10:00');

        $tipo = TenantAtual::comTenant(
            (int) $this->empresa->id,
            fn (): ServiceType => ServiceType::query()->active()->ordered()->firstOrFail()
        );

        TenantAtual::comTenant((int) $this->empresa->id, function () use ($visita, $tipo): void {
            AppointmentRequest::create([
                'nome' => 'Padaria Central',
                'email' => 'padaria-central@exemplo.test',
                'telefone' => '(11) 98888-0001',
                'endereco_texto' => 'Rua das Acácias, 120',
                'service_type_id' => $tipo->id,
                'data_preferida' => self::ONTEM,
                'periodo' => AppointmentRequest::PERIODO_MANHA,
                'situacao' => AppointmentRequest::SITUACAO_CONFIRMADA,
                'work_order_id' => $visita->id,
                'origem' => AppointmentRequest::ORIGEM_PAGINA_PUBLICA,
            ]);
        });

        $this->artisan('pesquisas:enviar')->assertSuccessful();

        $pesquisa = $this->pesquisaDaVisita($visita);

        $this->assertNotNull($pesquisa);
        $this->assertSame($tipo->id, $pesquisa->service_type_id);

        // E o nome do tipo é o único dado da visita que a página mostra.
        $this->assertSame($tipo->name, $this->propsDaPagina($this->get($this->url($pesquisa)))['servico_avaliado']);
    }

    // -----------------------------------------------------------------
    // Quem não recebe pesquisa
    // -----------------------------------------------------------------

    public function test_cliente_que_recusou_os_canais_nao_recebe(): void
    {
        $this->visitaConcluida('Padaria Central', self::ONTEM.' 09:00', atributosDoCliente: [
            'aceita_email' => false,
            'aceita_whatsapp' => false,
        ]);

        $this->artisan('pesquisas:enviar')->assertSuccessful();

        $this->assertSame(0, SatisfactionSurvey::query()->deTodasAsEmpresas()->count());
        $this->assertSame(0, $this->avisos(EventosDeNotificacao::PESQUISA_SATISFACAO)->count());
    }

    /**
     * Recusa de um canal não é recusa da pesquisa: o convite sai pelo canal que o
     * cliente aceita. Se o canal fosse decidido pelo `NotificationService`, este
     * cliente receberia `recusada`, porque o padrão de lá é e-mail.
     */
    public function test_cliente_que_recusou_email_recebe_por_whatsapp(): void
    {
        $this->visitaConcluida('Padaria Central', self::ONTEM.' 09:00', atributosDoCliente: [
            'aceita_email' => false,
            'aceita_whatsapp' => true,
            'phone' => '(11) 98888-0001',
        ]);

        $this->artisan('pesquisas:enviar')->assertSuccessful();

        $convite = $this->avisos(EventosDeNotificacao::PESQUISA_SATISFACAO)->sole();

        $this->assertSame(EventosDeNotificacao::CANAL_WHATSAPP, $convite->canal);
        $this->assertSame('(11) 98888-0001', $convite->destino);
        $this->assertSame(1, SatisfactionSurvey::query()->deTodasAsEmpresas()->count());
    }

    public function test_cliente_que_respondeu_ha_dez_dias_nao_recebe_outra(): void
    {
        $visita = $this->visitaConcluida('Padaria Central', self::ONTEM.' 09:00');

        $this->pesquisaExistente($visita->client_id, [
            'enviada_em' => Carbon::parse(self::AGORA, 'UTC')->subDays(11),
            'respondida_em' => Carbon::parse(self::AGORA, 'UTC')->subDays(10),
            'nota' => 5,
        ]);

        $this->artisan('pesquisas:enviar')->assertSuccessful();

        $this->assertNull(
            $this->pesquisaDaVisita($visita),
            'cliente com visita semanal receberia quatro pesquisas por mês e pararia de responder'
        );
        $this->assertSame(0, $this->avisos(EventosDeNotificacao::PESQUISA_SATISFACAO)->count());
    }

    public function test_cliente_que_respondeu_ha_quarenta_dias_recebe_outra(): void
    {
        $visita = $this->visitaConcluida('Padaria Central', self::ONTEM.' 09:00');

        $this->pesquisaExistente($visita->client_id, [
            'enviada_em' => Carbon::parse(self::AGORA, 'UTC')->subDays(41),
            'respondida_em' => Carbon::parse(self::AGORA, 'UTC')->subDays(40),
            'nota' => 4,
        ]);

        $this->artisan('pesquisas:enviar')->assertSuccessful();

        $this->assertNotNull($this->pesquisaDaVisita($visita));
    }

    public function test_visita_sem_cliente_com_contato_nao_gera_pesquisa(): void
    {
        $this->visitaConcluida('Cliente Sem Contato', self::ONTEM.' 09:00', atributosDoCliente: [
            'email' => '',
            'phone' => '',
        ]);

        $this->artisan('pesquisas:enviar')->assertSuccessful();

        $this->assertSame(0, SatisfactionSurvey::query()->deTodasAsEmpresas()->count());
    }

    // -----------------------------------------------------------------
    // Resposta pelo token
    // -----------------------------------------------------------------

    public function test_token_valido_grava_nota_e_comentario_e_a_segunda_resposta_e_recusada(): void
    {
        $pesquisa = $this->pesquisaAberta();

        $resposta = $this->post($this->url($pesquisa), [
            'nota' => 5,
            'comentario' => 'Equipe pontual e cuidadosa.',
        ]);

        $resposta->assertRedirect($this->url($pesquisa));
        $resposta->assertSessionHasNoErrors();
        $resposta->assertSessionHas('success', PesquisaController::MENSAGEM_DE_AGRADECIMENTO);

        $respondida = $pesquisa->fresh();

        $this->assertSame(5, $respondida->nota);
        $this->assertSame('Equipe pontual e cuidadosa.', $respondida->comentario);
        $this->assertNotNull($respondida->respondida_em);
        $this->assertFalse($respondida->pendencia_de_contato);
        $this->assertSame(0, $this->avisos(EventosDeNotificacao::NOTA_BAIXA_RECEBIDA)->count());

        // Segunda resposta com o mesmo token: a primeira é a que vale.
        $segunda = $this->post($this->url($pesquisa), ['nota' => 1, 'comentario' => 'Mudei de ideia.']);

        $segunda->assertRedirect($this->url($pesquisa));
        $segunda->assertSessionMissing('success');

        $depois = $pesquisa->fresh();

        $this->assertSame(5, $depois->nota, 'a segunda resposta não pode sobrescrever a primeira');
        $this->assertSame('Equipe pontual e cuidadosa.', $depois->comentario);
        $this->assertFalse($depois->pendencia_de_contato);
        $this->assertSame(0, $this->avisos(EventosDeNotificacao::NOTA_BAIXA_RECEBIDA)->count());

        // E a página passa a mostrar o estado de já respondida.
        $this->assertSame(
            SatisfactionSurveyService::ESTADO_RESPONDIDA,
            $this->propsDaPagina($this->get($this->url($pesquisa)))['estado']
        );
    }

    public function test_nota_fora_de_um_a_cinco_e_recusada(): void
    {
        $pesquisa = $this->pesquisaAberta();

        $this->post($this->url($pesquisa), ['nota' => 6])->assertSessionHasErrors('nota');
        $this->post($this->url($pesquisa), ['nota' => 0])->assertSessionHasErrors('nota');
        $this->post($this->url($pesquisa), [])->assertSessionHasErrors('nota');

        $this->assertNull($pesquisa->fresh()->nota);
    }

    public function test_token_expirado_e_recusado(): void
    {
        $pesquisa = $this->pesquisaAberta(['expira_em' => self::ONTEM]);

        $pagina = $this->get($this->url($pesquisa));

        $pagina->assertOk();
        $this->assertSame(SatisfactionSurveyService::ESTADO_EXPIRADA, $this->propsDaPagina($pagina)['estado']);
        $this->assertNull($this->propsDaPagina($pagina)['token'], 'pesquisa vencida não recebe formulário');

        $this->post($this->url($pesquisa), ['nota' => 5])->assertRedirect($this->url($pesquisa));

        $this->assertNull($pesquisa->fresh()->nota);
        $this->assertNull($pesquisa->fresh()->respondida_em);
    }

    /**
     * O que vence hoje não está vencido: mesma convenção de vencimento do resto
     * do sistema (`BusinessDate::estaVencido()`). Às 23h de Brasília o dia em UTC
     * já virou, e uma comparação em UTC recusaria esta resposta.
     */
    public function test_pesquisa_que_expira_hoje_ainda_aceita_resposta(): void
    {
        $pesquisa = $this->pesquisaAberta(['expira_em' => self::HOJE]);

        $this->post($this->url($pesquisa), ['nota' => 4])->assertSessionHasNoErrors();

        $this->assertSame(4, $pesquisa->fresh()->nota);
    }

    public function test_token_invalido_expirado_e_respondido_mostram_paginas_distintas(): void
    {
        $expirada = $this->pesquisaAberta(['expira_em' => self::ONTEM]);
        $respondida = $this->pesquisaAberta([
            'respondida_em' => Carbon::parse(self::AGORA, 'UTC')->subDay(),
            'nota' => 4,
        ]);

        $invalida = $this->propsDaPagina($this->get('/pesquisa/'.Str::random(64)));
        $vencida = $this->propsDaPagina($this->get($this->url($expirada)));
        $usada = $this->propsDaPagina($this->get($this->url($respondida)));

        $this->assertSame(SatisfactionSurveyService::ESTADO_INVALIDA, $invalida['estado']);
        $this->assertSame(SatisfactionSurveyService::ESTADO_EXPIRADA, $vencida['estado']);
        $this->assertSame(SatisfactionSurveyService::ESTADO_RESPONDIDA, $usada['estado']);

        $titulos = [$invalida['titulo'], $vencida['titulo'], $usada['titulo']];
        $mensagens = [$invalida['mensagem'], $vencida['mensagem'], $usada['mensagem']];

        $this->assertCount(3, array_unique($titulos), 'cada estado precisa do próprio título');
        $this->assertCount(3, array_unique($mensagens), 'cada estado precisa do próprio texto');

        // Token quebrado no meio pelo cliente de e-mail cai na página de link
        // inválido, e não em um 404 cru, que é onde a pessoa mais precisa da
        // explicação.
        $truncado = $this->get('/pesquisa/'.substr($expirada->token, 0, 30));

        $truncado->assertOk();
        $this->assertSame(SatisfactionSurveyService::ESTADO_INVALIDA, $this->propsDaPagina($truncado)['estado']);

        // Link inválido não pode mostrar marca de empresa nenhuma: o sistema não
        // sabe de quem é o token.
        $this->assertNull($invalida['empresa']);
        $this->assertNull($invalida['servico_avaliado']);
    }

    // -----------------------------------------------------------------
    // Nota baixa
    // -----------------------------------------------------------------

    public function test_nota_um_marca_pendencia_e_avisa_a_empresa_com_o_comentario(): void
    {
        $pesquisa = $this->pesquisaAberta();

        $resposta = $this->post($this->url($pesquisa), [
            'nota' => 1,
            'comentario' => 'O técnico não olhou o depósito.',
        ]);

        $resposta->assertSessionHas('success', PesquisaController::MENSAGEM_DE_NOTA_BAIXA);

        $respondida = $pesquisa->fresh();

        $this->assertSame(1, $respondida->nota);
        $this->assertTrue($respondida->pendencia_de_contato);
        $this->assertNull($respondida->contato_feito_em);

        $aviso = $this->avisos(EventosDeNotificacao::NOTA_BAIXA_RECEBIDA)->sole();

        $this->assertSame(NotificationQueue::DESTINATARIO_EMPRESA, $aviso->destinatario_tipo);
        $this->assertSame('contato@dedetizadora-a.test', $aviso->destino);
        $this->assertSame(EventosDeNotificacao::CANAL_EMAIL, $aviso->canal);
        $this->assertSame((int) $this->empresa->id, (int) $aviso->company_id);
        $this->assertStringContainsString('O técnico não olhou o depósito.', $aviso->corpo);
        $this->assertStringContainsString('nota 1', $aviso->corpo);
        $this->assertStringContainsString('Ana Ferreira', $aviso->corpo);

        // Nada é enviado ao cliente sobre a nota baixa: quem resolve insatisfação
        // é pessoa, não e-mail automático.
        $this->assertSame(
            0,
            NotificationQueue::query()
                ->deTodasAsEmpresas()
                ->where('destinatario_tipo', NotificationQueue::DESTINATARIO_CLIENTE)
                ->where('evento', '!=', EventosDeNotificacao::PESQUISA_SATISFACAO)
                ->count()
        );
    }

    public function test_nota_dois_tambem_abre_pendencia_e_nota_tres_nao(): void
    {
        $duas = $this->pesquisaAberta();
        $tres = $this->pesquisaAberta();

        $this->post($this->url($duas), ['nota' => 2]);
        $this->post($this->url($tres), ['nota' => 3]);

        $this->assertTrue($duas->fresh()->pendencia_de_contato);
        $this->assertFalse($tres->fresh()->pendencia_de_contato);
        $this->assertSame(1, $this->avisos(EventosDeNotificacao::NOTA_BAIXA_RECEBIDA)->count());
    }

    // -----------------------------------------------------------------
    // A página não expõe mais nada do cliente
    // -----------------------------------------------------------------

    public function test_pagina_do_token_nao_expoe_outro_dado_do_cliente(): void
    {
        $visita = $this->visitaConcluida('Padaria Central do Seu Zé', self::ONTEM.' 09:00', atributosDoCliente: [
            'phone' => '(11) 98888-1234',
            'cnpj' => '12.345.678/0001-99',
        ]);

        $this->artisan('pesquisas:enviar')->assertSuccessful();

        $pesquisa = $this->pesquisaDaVisita($visita);
        $cliente = Client::query()->deTodasAsEmpresas()->findOrFail($visita->client_id);
        $endereco = Address::query()->deTodasAsEmpresas()->findOrFail($visita->address_id);

        $pagina = $this->get($this->url($pesquisa));
        $pagina->assertOk();

        $conteudo = $pagina->content();

        // O id da OS não entra nesta lista de propósito: um número de uma ou duas
        // casas apareceria por coincidência dentro do token sorteado ou do token
        // de CSRF, e o teste passaria a falhar em dia de sorte. O que identifica a
        // visita para quem lê é `order_number`, e é ele que precisa estar fora.
        foreach ([
            $cliente->name,
            $cliente->email,
            $cliente->phone,
            $cliente->cnpj,
            $visita->order_number,
            $endereco->street,
            // A data da visita, no formato em que o sistema mostra data ao
            // usuário: nem ela precisa aparecer para o cliente dar a nota.
            $visita->scheduled_date->format('d/m/Y'),
            $this->tecnico->email,
            $this->tecnico->phone,
        ] as $dado) {
            $this->assertStringNotContainsString(
                (string) $dado,
                $conteudo,
                'a página da pesquisa não pode expor nada do cliente além do serviço avaliado'
            );
        }

        $props = $this->propsDaPagina($pagina);

        $permitidas = [
            'estado', 'titulo', 'mensagem', 'empresa', 'servico_avaliado', 'token',
            'nota_minima', 'nota_maxima', 'nota_maxima_de_contato', 'limite_do_comentario',
            // Props da ponte Inertia pública.
            'errors', 'flash',
        ];

        foreach (array_keys($props) as $prop) {
            $this->assertContains($prop, $permitidas, "a prop {$prop} não faz parte do recorte da página pública");
        }

        // O serviço avaliado é o nome dele, e nada além: virar objeto aqui é o
        // caminho por onde a visita inteira entraria na página "de brinde".
        $this->assertTrue(
            $props['servico_avaliado'] === null || is_string($props['servico_avaliado']),
            'servico_avaliado precisa ser o nome do serviço, não uma estrutura com dado da visita'
        );

        // A marca da empresa sai, porque é o que ela divulga por vontade própria.
        $this->assertSame('Dedetizadora A', $props['empresa']['nome']);
    }

    /**
     * O caso concreto do vazamento: o cliente abre o link no mesmo navegador em
     * que alguém está logado no painel. Nada da sessão pode sair na resposta.
     */
    public function test_pagina_aberta_com_sessao_de_funcionario_nao_devolve_dado_dele(): void
    {
        $pesquisa = $this->pesquisaAberta();

        $administrador = TenantAtual::comTenant(
            (int) $this->empresa->id,
            fn (): User => User::factory()->create([
                'name' => 'Administradora da Empresa',
                'email' => 'admin@dedetizadora-a.test',
            ])
        );
        $administrador->assignRole('administrador');

        $this->actingAs($administrador);

        $pagina = $this->get($this->url($pesquisa));

        $pagina->assertOk();

        $props = $this->propsDaPagina($pagina);

        foreach (['auth', 'suporte', 'avisos', 'modulos', 'onboarding', 'solicitacoesAbertas', 'clienteLogado'] as $prop) {
            $this->assertArrayNotHasKey($prop, $props, "a prop {$prop} vem da sessão autenticada e não pode sair aqui");
        }

        $this->assertStringNotContainsString('admin@dedetizadora-a.test', $pagina->content());
        $this->assertSame(SatisfactionSurveyService::ESTADO_DISPONIVEL, $props['estado']);
    }

    // -----------------------------------------------------------------
    // Limite de taxa da rota pública
    // -----------------------------------------------------------------

    /**
     * A rota da pesquisa tem limitadores próprios (`pesquisa-publica-leitura` e
     * `pesquisa-publica`), separados dos do agendamento de propósito: chave
     * compartilhada faria quem responde uma pesquisa consumir a cota de quem pede
     * horário.
     *
     * Os dois estados que interessam aqui: varredura de token na leitura, e
     * marretada no envio da nota. Nos dois casos a resposta é 429 em português,
     * porque quem cai nela é o cliente final da empresa, sem login e sem ninguém
     * para chamar.
     */
    public function test_limite_de_taxa_da_pesquisa_responde_em_portugues(): void
    {
        // Varredura de token: 30 leituras por minuto por IP.
        for ($tentativa = 1; $tentativa <= 30; $tentativa++) {
            $this->get('/pesquisa/'.Str::random(64))->assertOk();
        }

        $leituraBloqueada = $this->get('/pesquisa/'.Str::random(64));

        $leituraBloqueada->assertStatus(429);
        $leituraBloqueada->assertSee('Muitas tentativas');
        $leituraBloqueada->assertDontSee('Too Many');

        // Envio da nota: 10 por minuto por IP, contados por requisição, mesmo
        // quando nenhuma delas chega a gravar nota.
        $pesquisa = $this->pesquisaAberta();

        for ($tentativa = 1; $tentativa <= 10; $tentativa++) {
            $this->post('/pesquisa/'.Str::random(64), ['nota' => 5]);
        }

        $envioBloqueado = $this->post($this->url($pesquisa), ['nota' => 5]);

        $envioBloqueado->assertStatus(429);
        $envioBloqueado->assertSee('Muitas tentativas');

        $this->assertNull($pesquisa->fresh()->nota, 'a resposta barrada pelo limite não pode ter sido gravada');
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas
    // -----------------------------------------------------------------

    /**
     * O token resolve a própria pesquisa, e só ela. A empresa nunca vem da sessão
     * de quem abriu o link, e responder o token de uma empresa não toca em nada da
     * outra.
     */
    public function test_token_de_uma_empresa_nunca_alcanca_a_pesquisa_da_outra(): void
    {
        $daEmpresaA = $this->pesquisaAberta();
        $daEmpresaB = $this->pesquisaAberta(empresa: $this->outraEmpresa);

        // Funcionário da empresa A autenticado no navegador, abrindo o link da
        // empresa B: a página é da B, com a marca da B.
        $funcionarioDaA = TenantAtual::comTenant(
            (int) $this->empresa->id,
            fn (): User => User::factory()->create(['name' => 'Gerente da A'])
        );

        $this->actingAs($funcionarioDaA);

        $props = $this->propsDaPagina($this->get($this->url($daEmpresaB)));

        $this->assertSame(SatisfactionSurveyService::ESTADO_DISPONIVEL, $props['estado']);
        $this->assertSame('Dedetizadora B', $props['empresa']['nome']);

        $this->post($this->url($daEmpresaB), ['nota' => 1, 'comentario' => 'Nota da empresa B.']);

        $this->assertSame(1, $daEmpresaB->fresh()->nota);
        $this->assertNull($daEmpresaA->fresh()->nota, 'a pesquisa da outra empresa não pode ser tocada');

        $aviso = $this->avisos(EventosDeNotificacao::NOTA_BAIXA_RECEBIDA)->sole();

        $this->assertSame((int) $this->outraEmpresa->id, (int) $aviso->company_id);
        $this->assertSame('contato@dedetizadora-b.test', $aviso->destino);

        // E, de dentro da empresa A, a pesquisa da B não existe: nem pelo token,
        // nem nos indicadores.
        TenantAtual::comTenant((int) $this->empresa->id, function () use ($daEmpresaB): void {
            $this->assertNull(SatisfactionSurvey::query()->where('token', $daEmpresaB->token)->first());
            $this->assertSame(0, $this->indicadores()['geral']['respostas']);
        });

        TenantAtual::comTenant((int) $this->outraEmpresa->id, function (): void {
            $this->assertSame(1, $this->indicadores()['geral']['respostas']);
        });
    }

    // -----------------------------------------------------------------
    // Indicadores
    // -----------------------------------------------------------------

    public function test_corte_com_duas_respostas_omite_a_media_e_mostra_a_contagem(): void
    {
        $outroTecnico = TenantAtual::comTenant(
            (int) $this->empresa->id,
            fn (): Technician => Technician::create([
                'name' => 'Bruno Lima',
                'email' => 'bruno@dedetizadora-a.test',
                'phone' => '11999991111',
                'is_active' => true,
            ])
        );

        // Ana: duas respostas, abaixo do mínimo. Bruno: três, no mínimo exato.
        $this->respostaDe($this->tecnico, 5);
        $this->respostaDe($this->tecnico, 3);
        $this->respostaDe($outroTecnico, 4);
        $this->respostaDe($outroTecnico, 5);
        $this->respostaDe($outroTecnico, 3);

        $indicadores = TenantAtual::comTenant((int) $this->empresa->id, fn (): array => $this->indicadores());

        $this->assertSame(3, $indicadores['minimo_de_respostas']);

        // Geral: cinco respostas, média exibida.
        $this->assertSame(5, $indicadores['geral']['respostas']);
        $this->assertSame(4.0, $indicadores['geral']['media']);
        $this->assertFalse($indicadores['geral']['media_omitida']);

        $porTecnico = collect($indicadores['por_tecnico'])->keyBy('tecnico');

        $this->assertSame(2, $porTecnico['Ana Ferreira']['respostas']);
        $this->assertNull($porTecnico['Ana Ferreira']['media'], 'média de duas respostas é injustiça com o técnico');
        $this->assertTrue($porTecnico['Ana Ferreira']['media_omitida']);

        $this->assertSame(3, $porTecnico['Bruno Lima']['respostas']);
        $this->assertSame(4.0, $porTecnico['Bruno Lima']['media']);
        $this->assertFalse($porTecnico['Bruno Lima']['media_omitida']);
    }

    public function test_indicadores_recortam_por_periodo_e_por_tipo_de_servico(): void
    {
        $tipo = TenantAtual::comTenant(
            (int) $this->empresa->id,
            fn (): ServiceType => ServiceType::query()->active()->ordered()->firstOrFail()
        );

        // Três respostas neste mês, com tipo de serviço.
        $this->respostaDe($this->tecnico, 5, self::HOJE.' 12:00', $tipo);
        $this->respostaDe($this->tecnico, 4, self::HOJE.' 13:00', $tipo);
        $this->respostaDe($this->tecnico, 3, self::HOJE.' 14:00', $tipo);

        // Uma no mês anterior, sem tipo.
        $this->respostaDe($this->tecnico, 2, '2026-07-15 12:00');

        $indicadores = TenantAtual::comTenant((int) $this->empresa->id, fn (): array => $this->indicadores());

        $porPeriodo = collect($indicadores['por_periodo'])->keyBy('periodo');

        $this->assertSame(['2026-07', '2026-08'], $porPeriodo->keys()->all(), 'a evolução sai do mais antigo ao mais novo');
        $this->assertSame(1, $porPeriodo['2026-07']['respostas']);
        $this->assertNull($porPeriodo['2026-07']['media']);
        $this->assertSame('07/2026', $porPeriodo['2026-07']['rotulo']);
        $this->assertSame(3, $porPeriodo['2026-08']['respostas']);
        $this->assertSame(4.0, $porPeriodo['2026-08']['media']);

        $porTipo = collect($indicadores['por_tipo_de_servico'])->keyBy('tipo_de_servico');

        $this->assertSame(3, $porTipo[$tipo->name]['respostas']);
        $this->assertSame(4.0, $porTipo[$tipo->name]['media']);
        $this->assertSame(1, $porTipo['Sem tipo informado']['respostas']);
        $this->assertNull($porTipo['Sem tipo informado']['media']);

        // Recorte de dias: só o mês corrente.
        $doMes = TenantAtual::comTenant(
            (int) $this->empresa->id,
            fn (): array => $this->indicadores(['de' => '2026-08-01', 'ate' => self::HOJE])
        );

        $this->assertSame(3, $doMes['geral']['respostas']);
        $this->assertSame(['de' => '2026-08-01', 'ate' => self::HOJE], $doMes['periodo']);
    }

    public function test_pesquisa_enviada_e_nao_respondida_nao_entra_na_media(): void
    {
        $this->pesquisaAberta();
        $this->respostaDe($this->tecnico, 5);

        $indicadores = TenantAtual::comTenant((int) $this->empresa->id, fn (): array => $this->indicadores());

        $this->assertSame(1, $indicadores['geral']['respostas'], 'pesquisa sem resposta não é nota zero');
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function indicadores(array $filtros = []): array
    {
        return app(SatisfactionSurveyService::class)->indicadores($filtros);
    }

    /**
     * Visita concluída de um cliente novo da empresa A.
     *
     * `$fimEmBrasilia` é o instante do encerramento no fuso do negócio, que é
     * como a empresa o enxerga; a gravação converte para o fuso da aplicação,
     * igual ao que o aplicativo do técnico faz.
     *
     * @param  array<string, mixed>  $atributosDoCliente
     */
    private function visitaConcluida(
        string $nomeDoCliente,
        ?string $fimEmBrasilia,
        ?string $diaAgendado = null,
        array $atributosDoCliente = []
    ): WorkOrder {
        return TenantAtual::comTenant((int) $this->empresa->id, function () use (
            $nomeDoCliente,
            $fimEmBrasilia,
            $diaAgendado,
            $atributosDoCliente
        ): WorkOrder {
            $cliente = ClientFactory::new()->create(array_merge([
                'name' => $nomeDoCliente,
                'email' => Str::slug($nomeDoCliente).'@exemplo.test',
                'phone' => '(11) 98888-0001',
            ], $atributosDoCliente));

            $fim = $fimEmBrasilia === null
                ? null
                : CarbonImmutable::parse($fimEmBrasilia, 'America/Sao_Paulo')->setTimezone('UTC');

            return WorkOrderFactory::new()->create([
                'client_id' => $cliente->id,
                'technician_id' => $this->tecnico->id,
                'status' => 'completed',
                'scheduled_date' => $diaAgendado ?? ($fim === null ? self::ONTEM : $fimEmBrasilia),
                'end_time' => $fim,
                'active' => true,
            ]);
        });
    }

    /**
     * Pesquisa aberta (enviada, sem resposta e no prazo), criada direto para os
     * cenários que não estão medindo a rotina.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function pesquisaAberta(array $atributos = [], ?Company $empresa = null): SatisfactionSurvey
    {
        $empresa ??= $this->empresa;

        return TenantAtual::comTenant((int) $empresa->id, function () use ($atributos, $empresa): SatisfactionSurvey {
            $cliente = ClientFactory::new()->create();

            $visita = WorkOrderFactory::new()->create([
                'client_id' => $cliente->id,
                'technician_id' => $empresa->is($this->empresa) ? $this->tecnico->id : null,
                'status' => 'completed',
                'scheduled_date' => self::ONTEM,
            ]);

            return SatisfactionSurvey::create(array_merge([
                'work_order_id' => $visita->id,
                'client_id' => $cliente->id,
                'technician_id' => $visita->technician_id,
                'token' => Str::random(SatisfactionSurveyService::TAMANHO_DO_TOKEN),
                'enviada_em' => now(),
                'expira_em' => self::EXPIRA_EM,
                'pendencia_de_contato' => false,
            ], $atributos));
        });
    }

    /**
     * Pesquisa anterior do mesmo cliente, para exercitar a janela de 30 dias.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function pesquisaExistente(int $clientId, array $atributos): SatisfactionSurvey
    {
        return TenantAtual::comTenant((int) $this->empresa->id, function () use ($clientId, $atributos): SatisfactionSurvey {
            $visita = WorkOrderFactory::new()->create([
                'client_id' => $clientId,
                'status' => 'completed',
                'scheduled_date' => '2026-06-01',
            ]);

            return SatisfactionSurvey::create(array_merge([
                'work_order_id' => $visita->id,
                'client_id' => $clientId,
                'token' => Str::random(SatisfactionSurveyService::TAMANHO_DO_TOKEN),
                'expira_em' => '2026-07-01',
                'pendencia_de_contato' => false,
            ], $atributos));
        });
    }

    /**
     * Resposta já gravada, para os indicadores.
     */
    private function respostaDe(
        Technician $tecnico,
        int $nota,
        ?string $respondidaEmBrasilia = null,
        ?ServiceType $tipo = null
    ): SatisfactionSurvey {
        $respondidaEm = CarbonImmutable::parse($respondidaEmBrasilia ?? self::HOJE.' 10:00', 'America/Sao_Paulo')
            ->setTimezone('UTC');

        return $this->pesquisaAberta([
            'technician_id' => $tecnico->id,
            'service_type_id' => $tipo?->id,
            'nota' => $nota,
            'respondida_em' => $respondidaEm,
            'pendencia_de_contato' => $nota <= SatisfactionSurveyService::NOTA_MAXIMA_DE_PENDENCIA,
        ]);
    }

    private function pesquisaDaVisita(WorkOrder $visita): ?SatisfactionSurvey
    {
        return SatisfactionSurvey::query()
            ->deTodasAsEmpresas()
            ->where('work_order_id', $visita->id)
            ->first();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, NotificationQueue>
     */
    private function avisos(string $evento)
    {
        return NotificationQueue::query()
            ->deTodasAsEmpresas()
            ->where('evento', $evento)
            ->orderBy('id')
            ->get();
    }

    private function url(SatisfactionSurvey $pesquisa): string
    {
        return '/pesquisa/'.$pesquisa->token;
    }

    /**
     * @return array<string, mixed>
     */
    private function propsDaPagina(TestResponse $resposta): array
    {
        return $resposta->viewData('page')['props'];
    }
}
