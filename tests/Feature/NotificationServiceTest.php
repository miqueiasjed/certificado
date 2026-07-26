<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Company;
use App\Models\NotificationQueue;
use App\Models\NotificationTemplate;
use App\Models\WorkOrder;
use App\Services\NotificationService;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Closure;
use Database\Factories\CertificateFactory;
use Database\Factories\ClientFactory;
use Database\Factories\WorkOrderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 14.9 do Plano 14: as três garantias do `NotificationService`.
 *
 * 1. **Nenhum aviso duplicado.** É o teste mais importante do plano inteiro:
 *    lembrete repetido para o cliente final queima a confiança na dedetizadora
 *    que contratou o sistema, e a rotina diária pode rodar duas vezes no mesmo
 *    dia por reprocessamento, por cron duplicado ou por operador destravando a
 *    fila no terminal.
 * 2. **A recusa do cliente é respeitada.** Canal desligado no cadastro não gera
 *    linha nenhuma na fila. Não basta o item não sair: fila cheia de linha que
 *    nunca sai esconde o que realmente está pendente.
 * 3. **A data sai no dia de Brasília.** A aplicação roda em UTC, e às 22h de
 *    Brasília o servidor já virou o dia. Um certificado que vence hoje não pode
 *    aparecer no e-mail com a data de amanhã nem com "faltam -1 dias".
 *
 * O relógio é fixado às 22h de Brasília em todos os cenários, de propósito: é o
 * horário em que UTC e o fuso do negócio discordam sobre que dia é hoje. Teste
 * de fuso que usa a hora corrente passa de manhã e quebra à noite.
 *
 * O instante é declarado em UTC, e não em `America/Sao_Paulo`, pelo mesmo motivo
 * de `GeracaoDeVisitasTest`: `Carbon::setTestNow()` empresta o fuso da instância
 * mockada para toda data criada sem fuso explícito, inclusive a que o Eloquent
 * monta ao ler uma coluna `datetime`. Com o relógio fixado em Brasília, o cast
 * devolveria o valor gravado em UTC marcado como se fosse horário de Brasília, e
 * as asserções passariam a medir o defeito do teste em vez do comportamento do
 * código.
 *
 * Duas empresas em todo cenário, pelo mesmo motivo do resto do projeto:
 * vazamento entre tenants é a falha mais grave possível aqui.
 */
class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 01h de 27/07 em UTC, que é 22h de 26/07 em Brasília. Todo cenário deste
     * arquivo roda nesse instante.
     */
    private const AGORA_EM_UTC = '2026-07-27 01:00:00';

    /**
     * O dia que vale para o negócio no instante acima.
     */
    private const HOJE_EM_BRASILIA = '2026-07-26';

    /**
     * O dia em que o servidor está, em UTC, no mesmo instante. Nenhuma data de
     * mensagem pode sair com ele.
     */
    private const HOJE_EM_UTC = '27/07/2026';

    private Company $empresaA;

    private Company $empresaB;

    private NotificationService $notificacoes;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AGORA_EM_UTC, 'UTC'));

        // A empresa 1 vem da migration de fundação do tenant, sem nome.
        Company::query()->whereKey(1)->update([
            'name' => 'Dedetizadora A',
            'email' => 'contato@a.test',
            'phone' => '(11) 3333-1111',
        ]);

        $this->empresaA = Company::query()->findOrFail(1);
        $this->empresaB = Company::create([
            'name' => 'Dedetizadora B',
            'email' => 'contato@b.test',
            'phone' => '(21) 3333-2222',
        ]);

        $this->notificacoes = app(NotificationService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Idempotência: o teste mais importante do plano
    // -----------------------------------------------------------------

    public function test_enfileirar_o_mesmo_evento_e_a_mesma_referencia_duas_vezes_gera_um_item_so(): void
    {
        $visita = $this->criarVisita($this->empresaA);

        $primeira = $this->naEmpresa(
            $this->empresaA,
            fn (): array => $this->notificacoes->enfileirar(EventosDeNotificacao::VISITA_AGENDADA, $visita)
        );

        $segunda = $this->naEmpresa(
            $this->empresaA,
            fn (): array => $this->notificacoes->enfileirar(EventosDeNotificacao::VISITA_AGENDADA, $visita)
        );

        $this->assertSame(NotificationService::RESULTADO_ENFILEIRADA, $primeira['resultado']);
        $this->assertTrue($primeira['criado']);

        $this->assertSame(NotificationService::RESULTADO_DUPLICADA, $segunda['resultado']);
        $this->assertFalse($segunda['criado'], 'a segunda chamada gravou um item novo');
        $this->assertSame(
            $primeira['item']->id,
            $segunda['item']->id,
            'a duplicata devolveu um item diferente do que já estava na fila'
        );

        $this->assertSame(
            1,
            $this->itensDoEvento(EventosDeNotificacao::VISITA_AGENDADA)->count(),
            'o mesmo aviso entrou duas vezes na fila'
        );
    }

    /**
     * Cinco execuções seguidas, que é o cenário real da rotina reprocessada
     * depois de uma falha ou do cron que subiu duas vezes.
     */
    public function test_cinco_chamadas_seguidas_deixam_uma_linha_so_na_fila(): void
    {
        $visita = $this->criarVisita($this->empresaA);

        for ($passada = 1; $passada <= 5; $passada++) {
            $this->naEmpresa(
                $this->empresaA,
                fn (): array => $this->notificacoes->enfileirar(EventosDeNotificacao::VISITA_AGENDADA, $visita)
            );
        }

        $this->assertSame(1, $this->itensDoEvento(EventosDeNotificacao::VISITA_AGENDADA)->count());
    }

    /**
     * O marco é o único acréscimo permitido à chave, e existe justamente para os
     * avisos que são legitimamente vários sobre a mesma referência.
     */
    public function test_marcos_diferentes_sobre_a_mesma_referencia_geram_avisos_distintos(): void
    {
        $certificado = $this->criarCertificado($this->empresaA, '2026-08-25');

        foreach (['30', '15', '7'] as $marco) {
            $resultado = $this->naEmpresa($this->empresaA, fn (): array => $this->notificacoes->enfileirar(
                EventosDeNotificacao::CERTIFICADO_A_VENCER,
                $certificado,
                ['marco' => $marco]
            ));

            $this->assertSame(NotificationService::RESULTADO_ENFILEIRADA, $resultado['resultado']);
        }

        $avisos = $this->itensDoEvento(EventosDeNotificacao::CERTIFICADO_A_VENCER);

        $this->assertCount(3, $avisos);
        $this->assertCount(
            3,
            array_unique($avisos->pluck('chave_idempotencia')->all()),
            'os três marcos do mesmo certificado saíram com a mesma chave'
        );
    }

    // -----------------------------------------------------------------
    // Preferência do cliente
    // -----------------------------------------------------------------

    public function test_cliente_que_recusou_email_nao_entra_na_fila(): void
    {
        $visita = $this->criarVisita($this->empresaA, clienteAtributos: ['aceita_email' => false]);

        $resultado = $this->naEmpresa($this->empresaA, fn (): array => $this->notificacoes->enfileirar(
            EventosDeNotificacao::VISITA_AGENDADA,
            $visita,
            ['canal' => EventosDeNotificacao::CANAL_EMAIL]
        ));

        $this->assertSame(NotificationService::RESULTADO_RECUSADA, $resultado['resultado']);
        $this->assertFalse($resultado['criado']);
        $this->assertNull($resultado['item']);

        $this->assertSame(
            0,
            NotificationQueue::query()->count(),
            'a recusa do cliente gravou linha na fila; a fila é o que vai sair, e nada aqui vai sair'
        );
    }

    public function test_cliente_que_recusou_whatsapp_nao_entra_na_fila(): void
    {
        $visita = $this->criarVisita($this->empresaA, clienteAtributos: ['aceita_whatsapp' => false]);

        $resultado = $this->naEmpresa($this->empresaA, fn (): array => $this->notificacoes->enfileirar(
            EventosDeNotificacao::VISITA_AGENDADA,
            $visita,
            ['canal' => EventosDeNotificacao::CANAL_WHATSAPP]
        ));

        $this->assertSame(NotificationService::RESULTADO_RECUSADA, $resultado['resultado']);
        $this->assertSame(EventosDeNotificacao::CANAL_WHATSAPP, $resultado['canal']);
        $this->assertSame(0, NotificationQueue::query()->count());
    }

    /**
     * A recusa é por canal, não por cliente. Quem desligou o e-mail continua
     * recebendo pelo WhatsApp, que foi o que ele escolheu.
     */
    public function test_recusar_um_canal_nao_desliga_o_outro(): void
    {
        $visita = $this->criarVisita($this->empresaA, clienteAtributos: [
            'aceita_email' => false,
            'aceita_whatsapp' => true,
            'canal_preferido' => EventosDeNotificacao::CANAL_WHATSAPP,
        ]);

        $resultado = $this->naEmpresa(
            $this->empresaA,
            fn (): array => $this->notificacoes->enfileirar(EventosDeNotificacao::VISITA_AGENDADA, $visita)
        );

        $this->assertSame(NotificationService::RESULTADO_ENFILEIRADA, $resultado['resultado']);
        $this->assertSame(EventosDeNotificacao::CANAL_WHATSAPP, $resultado['canal']);
        $this->assertSame($visita->client->phone, $resultado['item']->destino);
    }

    /**
     * A recusa vale para quem recebe. Aviso interno da mesma referência, que vai
     * para a caixa da empresa, não é barrado pela preferência do cliente final.
     */
    public function test_recusa_do_cliente_nao_barra_a_copia_interna_da_empresa(): void
    {
        $certificado = $this->criarCertificado(
            $this->empresaA,
            '2026-08-25',
            clienteAtributos: ['aceita_email' => false]
        );

        $paraOCliente = $this->naEmpresa($this->empresaA, fn (): array => $this->notificacoes->enfileirar(
            EventosDeNotificacao::CERTIFICADO_A_VENCER,
            $certificado
        ));

        $paraAEmpresa = $this->naEmpresa($this->empresaA, fn (): array => $this->notificacoes->enfileirar(
            EventosDeNotificacao::CERTIFICADO_A_VENCER,
            $certificado,
            [
                'destinatario_tipo' => NotificationQueue::DESTINATARIO_EMPRESA,
                'assunto' => 'Aviso interno',
                'corpo' => 'O certificado {{certificado_numero}} vence em {{data_vencimento}}.',
            ]
        ));

        $this->assertSame(NotificationService::RESULTADO_RECUSADA, $paraOCliente['resultado']);
        $this->assertSame(NotificationService::RESULTADO_ENFILEIRADA, $paraAEmpresa['resultado']);
        $this->assertSame($this->empresaA->email, $paraAEmpresa['item']->destino);
    }

    public function test_cliente_sem_endereco_de_destino_nao_entra_na_fila(): void
    {
        // A coluna `email` é obrigatória no banco, então o cadastro incompleto
        // aparece como texto vazio, que é o que o formulário grava.
        $visita = $this->criarVisita($this->empresaA, clienteAtributos: [
            'email' => '',
            'email_notificacao' => null,
        ]);

        $resultado = $this->naEmpresa(
            $this->empresaA,
            fn (): array => $this->notificacoes->enfileirar(EventosDeNotificacao::VISITA_AGENDADA, $visita)
        );

        $this->assertSame(NotificationService::RESULTADO_SEM_DESTINO, $resultado['resultado']);
        $this->assertSame(0, NotificationQueue::query()->count());
    }

    // -----------------------------------------------------------------
    // Template do tenant contra o texto padrão do catálogo
    // -----------------------------------------------------------------

    public function test_template_do_tenant_vence_o_padrao_do_catalogo(): void
    {
        $this->criarTemplate($this->empresaA, EventosDeNotificacao::VISITA_AGENDADA, [
            'assunto' => 'Texto da empresa A: {{os_numero}}',
            'corpo' => 'Corpo escrito pela empresa A para {{cliente_nome}}.',
        ]);

        $visitaDeA = $this->criarVisita($this->empresaA);
        $visitaDeB = $this->criarVisita($this->empresaB);

        $deA = $this->naEmpresa(
            $this->empresaA,
            fn (): array => $this->notificacoes->enfileirar(EventosDeNotificacao::VISITA_AGENDADA, $visitaDeA)
        );

        $deB = $this->naEmpresa(
            $this->empresaB,
            fn (): array => $this->notificacoes->enfileirar(EventosDeNotificacao::VISITA_AGENDADA, $visitaDeB)
        );

        $this->assertStringContainsString('Corpo escrito pela empresa A', $deA['item']->corpo);
        $this->assertStringContainsString('Texto da empresa A: '.$visitaDeA->order_number, $deA['item']->assunto);

        // A empresa B não tem template próprio e cai no texto do catálogo. É o
        // mesmo teste do isolamento: template de uma empresa não vaza na outra.
        $padrao = EventosDeNotificacao::templatePadrao(
            EventosDeNotificacao::VISITA_AGENDADA,
            EventosDeNotificacao::CANAL_EMAIL
        );

        $this->assertNotNull($padrao);
        $this->assertStringContainsString('Sua visita técnica está agendada', $deB['item']->corpo);
        $this->assertStringNotContainsString('empresa A', $deB['item']->corpo);
    }

    /**
     * Desligar o template do tenant significa "voltar ao texto do sistema", e
     * nunca "parar de avisar": quem para de avisar é a preferência do cliente.
     */
    public function test_template_do_tenant_desativado_cai_no_padrao_do_catalogo(): void
    {
        $this->criarTemplate($this->empresaA, EventosDeNotificacao::VISITA_AGENDADA, [
            'corpo' => 'Corpo desligado da empresa A.',
            'ativo' => false,
        ]);

        $visita = $this->criarVisita($this->empresaA);

        $resultado = $this->naEmpresa(
            $this->empresaA,
            fn (): array => $this->notificacoes->enfileirar(EventosDeNotificacao::VISITA_AGENDADA, $visita)
        );

        $this->assertSame(NotificationService::RESULTADO_ENFILEIRADA, $resultado['resultado']);
        $this->assertStringNotContainsString('Corpo desligado', $resultado['item']->corpo);
        $this->assertStringContainsString('Sua visita técnica está agendada', $resultado['item']->corpo);
    }

    /**
     * O template é campo livre editado pelo tenant. Um nome de variável digitado
     * errado ali não pode derrubar a fila de avisos da empresa inteira: a
     * variável some do texto e o aviso sai.
     */
    public function test_variavel_desconhecida_no_corpo_nao_quebra_a_renderizacao(): void
    {
        $this->criarTemplate($this->empresaA, EventosDeNotificacao::VISITA_AGENDADA, [
            'assunto' => 'Visita de {{cliente_nome}}',
            'corpo' => 'Olá, {{cliente_nome}}. Protocolo: {{variavel_que_nunca_existiu}}. Fim.',
        ]);

        $visita = $this->criarVisita($this->empresaA);

        $resultado = $this->naEmpresa(
            $this->empresaA,
            fn (): array => $this->notificacoes->enfileirar(EventosDeNotificacao::VISITA_AGENDADA, $visita)
        );

        $this->assertSame(NotificationService::RESULTADO_ENFILEIRADA, $resultado['resultado']);

        $corpo = $resultado['item']->corpo;

        $this->assertStringNotContainsString('{{', $corpo, 'a variável desconhecida ficou crua no texto do cliente');
        $this->assertStringContainsString('Protocolo: . Fim.', $corpo);
        $this->assertStringContainsString($visita->client->name, $corpo);
    }

    // -----------------------------------------------------------------
    // Fuso do negócio no texto que o cliente lê
    // -----------------------------------------------------------------

    /**
     * Às 22h de Brasília o servidor já está no dia seguinte em UTC. O
     * certificado que vence hoje precisa sair com a data de hoje e com zero dia
     * restante, e não com a data de amanhã e "-1".
     */
    public function test_data_de_vencimento_sai_no_dia_de_brasilia_e_nao_no_dia_em_utc(): void
    {
        $this->criarTemplate($this->empresaA, EventosDeNotificacao::CERTIFICADO_A_VENCER, [
            'assunto' => 'Vencimento em {{data_vencimento}}',
            'corpo' => 'Vence em {{data_vencimento}} e faltam {{dias_para_vencer}} dia(s).',
        ]);

        $certificado = $this->criarCertificado($this->empresaA, self::HOJE_EM_BRASILIA);

        $resultado = $this->naEmpresa(
            $this->empresaA,
            fn (): array => $this->notificacoes->enfileirar(EventosDeNotificacao::CERTIFICADO_A_VENCER, $certificado)
        );

        $corpo = $resultado['item']->corpo;

        $this->assertStringContainsString('Vence em 26/07/2026', $corpo);
        $this->assertStringContainsString('faltam 0 dia(s)', $corpo, 'a contagem de dias comparou com o dia em UTC');
        $this->assertStringNotContainsString(self::HOJE_EM_UTC, $corpo, 'a data saiu com o dia do servidor, não com o de Brasília');
        $this->assertStringNotContainsString('-1', $corpo);
    }

    /**
     * O mesmo cuidado para instante, e não só para dia: `end_time` está gravado
     * em UTC e precisa aparecer no fuso do negócio.
     */
    public function test_instante_renderizado_no_corpo_sai_no_fuso_do_negocio(): void
    {
        $visita = $this->criarVisita($this->empresaA, [
            'status' => 'completed',
            // O mesmo instante fixado no relógio: 22h de Brasília, 01h em UTC.
            'end_time' => Carbon::now(),
        ]);

        $resultado = $this->naEmpresa(
            $this->empresaA,
            fn (): array => $this->notificacoes->enfileirar(EventosDeNotificacao::OS_CONCLUIDA, $visita)
        );

        $this->assertStringContainsString('26/07/2026 22:00', $resultado['item']->corpo);
        $this->assertStringNotContainsString(self::HOJE_EM_UTC, $resultado['item']->corpo);
    }

    /**
     * Agendamento informado como dia sai no horário padrão de envio, contado no
     * fuso do negócio. Sem isso o aviso do dia seguinte sairia às 21h de hoje.
     */
    public function test_agendamento_por_dia_recebe_a_hora_padrao_no_fuso_do_negocio(): void
    {
        $visita = $this->criarVisita($this->empresaA);

        $resultado = $this->naEmpresa($this->empresaA, fn (): array => $this->notificacoes->enfileirar(
            EventosDeNotificacao::VISITA_AGENDADA,
            $visita,
            ['agendada_para' => '2026-07-28']
        ));

        $agendada = BusinessDate::paraFusoNegocio($resultado['item']->agendada_para);

        $this->assertNotNull($agendada);
        $this->assertSame(
            '28/07/2026 '.NotificationService::HORA_PADRAO_DE_ENVIO,
            $agendada->format('d/m/Y H:i')
        );
    }

    // -----------------------------------------------------------------
    // Link de WhatsApp
    // -----------------------------------------------------------------

    public function test_o_link_wa_me_sai_com_o_telefone_normalizado(): void
    {
        $visita = $this->criarVisita($this->empresaA, clienteAtributos: ['phone' => '(11) 98888-7777']);

        $resultado = $this->naEmpresa($this->empresaA, fn (): array => $this->notificacoes->enfileirar(
            EventosDeNotificacao::VISITA_AGENDADA,
            $visita,
            ['canal' => EventosDeNotificacao::CANAL_WHATSAPP]
        ));

        $link = $this->notificacoes->linkWhatsapp($resultado['item']);

        $this->assertStringStartsWith('https://wa.me/5511988887777?text=', $link);
        $this->assertStringContainsString(rawurlencode($resultado['item']->corpo), $link);

        // O número do cadastro continua como o usuário digitou: a normalização
        // vale para o link, não para o dado.
        $this->assertSame('(11) 98888-7777', $visita->client->fresh()->phone);
    }

    /**
     * Item que saiu por e-mail também gera link: o número vem do cadastro do
     * cliente destinatário, e é o que permite à tela oferecer "mandar por
     * WhatsApp" para um aviso de e-mail.
     */
    public function test_item_de_email_monta_o_link_com_o_telefone_do_cadastro(): void
    {
        $visita = $this->criarVisita($this->empresaA, clienteAtributos: ['phone' => '11 3333-4444']);

        $resultado = $this->naEmpresa($this->empresaA, fn (): array => $this->notificacoes->enfileirar(
            EventosDeNotificacao::VISITA_AGENDADA,
            $visita,
            ['canal' => EventosDeNotificacao::CANAL_EMAIL]
        ));

        $this->assertStringContainsString('@', $resultado['item']->destino);

        $link = $this->naEmpresa(
            $this->empresaA,
            fn (): string => $this->notificacoes->linkWhatsapp($resultado['item'])
        );

        $this->assertStringStartsWith('https://wa.me/551133334444?text=', $link);
    }

    public function test_normalizacao_de_telefone_cobre_os_formatos_do_cadastro(): void
    {
        $this->assertSame('5511988887777', $this->notificacoes->normalizarTelefone('(11) 98888-7777'));
        $this->assertSame('551133334444', $this->notificacoes->normalizarTelefone('11 3333-4444'));
        $this->assertSame('5511988887777', $this->notificacoes->normalizarTelefone('5511988887777'));

        // Número de outro país sai como veio: inventar 55 na frente quebraria a
        // conversa.
        $this->assertSame('351912345678', $this->notificacoes->normalizarTelefone('+351 912 345 678'));

        $this->assertNull($this->notificacoes->normalizarTelefone('99999'));
        $this->assertNull($this->notificacoes->normalizarTelefone(null));
        $this->assertNull($this->notificacoes->normalizarTelefone('sem número'));
    }

    // -----------------------------------------------------------------
    // Duas empresas
    // -----------------------------------------------------------------

    /**
     * Mesmo cliente lógico (mesmo nome e mesmo e-mail) e mesma referência lógica
     * em empresas diferentes: cada empresa precisa ter o próprio item, e nenhuma
     * das duas pode ver a chave da outra como duplicata.
     */
    public function test_a_mesma_chave_de_negocio_em_empresas_diferentes_nao_colide(): void
    {
        $dadosDoCliente = ['name' => 'Mercado Central', 'email' => 'contato@mercado.test'];

        $visitaDeA = $this->criarVisita($this->empresaA, clienteAtributos: $dadosDoCliente);
        $visitaDeB = $this->criarVisita($this->empresaB, clienteAtributos: $dadosDoCliente);

        $deA = $this->naEmpresa(
            $this->empresaA,
            fn (): array => $this->notificacoes->enfileirar(EventosDeNotificacao::VISITA_AGENDADA, $visitaDeA)
        );

        $deB = $this->naEmpresa(
            $this->empresaB,
            fn (): array => $this->notificacoes->enfileirar(EventosDeNotificacao::VISITA_AGENDADA, $visitaDeB)
        );

        $this->assertSame(NotificationService::RESULTADO_ENFILEIRADA, $deA['resultado']);
        $this->assertSame(
            NotificationService::RESULTADO_ENFILEIRADA,
            $deB['resultado'],
            'a empresa B foi tratada como duplicata da empresa A'
        );

        $this->assertNotSame($deA['chave_idempotencia'], $deB['chave_idempotencia']);
        $this->assertSame((int) $this->empresaA->id, (int) $deA['item']->company_id);
        $this->assertSame((int) $this->empresaB->id, (int) $deB['item']->company_id);

        $this->assertSame(2, $this->itensDoEvento(EventosDeNotificacao::VISITA_AGENDADA)->count());
    }

    /**
     * O cabeçalho do aviso é o da empresa dona do tenant em que ele foi
     * enfileirado, nunca o da outra. Aviso saindo com o nome da concorrente é a
     * pior forma possível de vazamento aqui.
     */
    public function test_cada_empresa_aparece_apenas_no_proprio_aviso(): void
    {
        $visitaDeA = $this->criarVisita($this->empresaA);
        $visitaDeB = $this->criarVisita($this->empresaB);

        $deA = $this->naEmpresa(
            $this->empresaA,
            fn (): array => $this->notificacoes->enfileirar(EventosDeNotificacao::VISITA_AGENDADA, $visitaDeA)
        );

        $deB = $this->naEmpresa(
            $this->empresaB,
            fn (): array => $this->notificacoes->enfileirar(EventosDeNotificacao::VISITA_AGENDADA, $visitaDeB)
        );

        $this->assertStringContainsString('Dedetizadora A', $deA['item']->corpo);
        $this->assertStringNotContainsString('Dedetizadora B', $deA['item']->corpo);

        $this->assertStringContainsString('Dedetizadora B', $deB['item']->corpo);
        $this->assertStringNotContainsString('Dedetizadora A', $deB['item']->corpo);
    }

    /**
     * Dentro do tenant, a consulta da fila enxerga só o próprio item. É o escopo
     * global de `BelongsToCompany` valendo também para `notification_queue`.
     */
    public function test_a_fila_consultada_dentro_do_tenant_so_enxerga_os_proprios_itens(): void
    {
        $visitaDeA = $this->criarVisita($this->empresaA);
        $visitaDeB = $this->criarVisita($this->empresaB);

        $this->naEmpresa(
            $this->empresaA,
            fn (): array => $this->notificacoes->enfileirar(EventosDeNotificacao::VISITA_AGENDADA, $visitaDeA)
        );

        $this->naEmpresa(
            $this->empresaB,
            fn (): array => $this->notificacoes->enfileirar(EventosDeNotificacao::VISITA_AGENDADA, $visitaDeB)
        );

        $vistosPorA = $this->naEmpresa(
            $this->empresaA,
            fn (): array => NotificationQueue::query()->pluck('company_id')->all()
        );

        $vistosPorB = $this->naEmpresa(
            $this->empresaB,
            fn (): array => NotificationQueue::query()->pluck('company_id')->all()
        );

        $this->assertSame([(int) $this->empresaA->id], array_map('intval', $vistosPorA));
        $this->assertSame([(int) $this->empresaB->id], array_map('intval', $vistosPorB));
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function naEmpresa(Company $empresa, Closure $callback): mixed
    {
        return TenantAtual::comTenant((int) $empresa->id, $callback);
    }

    /**
     * Ordem de serviço da empresa, com o cliente dela.
     *
     * A data agendada padrão da factory está no passado em relação ao instante
     * fixado, então o `WorkOrderNotificationObserver` não dispara nada na
     * criação: os cenários deste arquivo chamam o Service de propósito, e um
     * item vindo do observer confundiria a contagem.
     *
     * @param  array<string, mixed>  $atributos
     * @param  array<string, mixed>  $clienteAtributos
     */
    private function criarVisita(Company $empresa, array $atributos = [], array $clienteAtributos = []): WorkOrder
    {
        return $this->naEmpresa($empresa, function () use ($atributos, $clienteAtributos): WorkOrder {
            $cliente = ClientFactory::new()->create($clienteAtributos);

            return WorkOrderFactory::new()->create(array_merge(
                ['client_id' => $cliente->id],
                $atributos
            ));
        });
    }

    /**
     * @param  array<string, mixed>  $clienteAtributos
     */
    private function criarCertificado(Company $empresa, string $garantia, array $clienteAtributos = []): Certificate
    {
        return $this->naEmpresa($empresa, function () use ($garantia, $clienteAtributos): Certificate {
            $cliente = ClientFactory::new()->create($clienteAtributos);

            return CertificateFactory::new()->create([
                'client_id' => $cliente->id,
                'warranty' => $garantia,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function criarTemplate(Company $empresa, string $evento, array $atributos): NotificationTemplate
    {
        return $this->naEmpresa($empresa, fn (): NotificationTemplate => NotificationTemplate::create(array_merge([
            'evento' => $evento,
            'canal' => EventosDeNotificacao::CANAL_EMAIL,
            'assunto' => 'Assunto do tenant',
            'corpo' => 'Corpo do tenant.',
            'ativo' => true,
        ], $atributos)));
    }

    /**
     * Itens do evento em todas as empresas.
     *
     * A consulta roda fora de `comTenant`, e nesse estado o escopo global não
     * filtra nada: é o que permite conferir as duas empresas de uma vez.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, NotificationQueue>
     */
    private function itensDoEvento(string $evento): \Illuminate\Database\Eloquent\Collection
    {
        return NotificationQueue::query()
            ->where('evento', $evento)
            ->orderBy('id')
            ->get();
    }
}
