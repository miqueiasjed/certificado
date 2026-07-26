<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Certificate;
use App\Models\Company;
use App\Models\Contract;
use App\Models\NotificationQueue;
use App\Models\PaymentDetail;
use App\Models\WorkOrder;
use App\Services\NotificationService;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\AddressFactory;
use Database\Factories\CertificateFactory;
use Database\Factories\ClientFactory;
use Database\Factories\PaymentDetailFactory;
use Database\Factories\WorkOrderFactory;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 14.9 do Plano 14: os avisos saem quando a condição acontece, e uma vez só.
 *
 * Dois disparadores são cobertos aqui:
 *
 * - `WorkOrderNotificationObserver`, para o que nasce de uma ação do usuário
 *   (visita agendada, técnico a caminho, OS concluída);
 * - `notificacoes:avisos-diarios`, para o que nasce da passagem do tempo
 *   (lembrete de véspera, certificado e contrato a vencer, pagamento vencido,
 *   orçamento a expirar).
 *
 * Três cenários deste arquivo são obrigatórios pelo plano, e nenhum é
 * decorativo:
 *
 * 1. **A rotina rodada duas vezes no mesmo dia não duplica nada.** O cron pode
 *    subir duas vezes, o dia pode ser reprocessado depois de uma falha e alguém
 *    pode rodar o comando à mão para conferir. Nenhuma dessas três coisas pode
 *    mandar o mesmo lembrete de novo para o cliente final.
 * 2. **Erro em um registro não cala os demais nem o outro tenant.** Sem isso, um
 *    certificado com dado inconsistente de um cliente pararia os avisos de todas
 *    as empresas do sistema. O teste afirma a fila do segundo tenant, e não
 *    apenas que o comando não estourou.
 * 3. **O corte de vencimento é o dia de Brasília.** O relógio é fixado às 23h de
 *    Brasília, quando o servidor em UTC já virou o dia, e o teste cobra que o
 *    certificado avisado seja o do dia certo.
 *
 * O instante é sempre declarado em UTC: `Carbon::setTestNow()` empresta o fuso
 * da instância mockada para toda data criada sem fuso explícito, inclusive a que
 * o Eloquent monta ao ler coluna `datetime`. Fixar o relógio em Brasília faria
 * as asserções medirem o defeito do teste em vez do comportamento do código.
 */
class EventosDeNotificacaoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Dia de referência no fuso do negócio para a maior parte dos cenários.
     */
    private const HOJE = '2026-07-26';

    private Company $empresaA;

    private Company $empresaB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixarRelogioEm(self::HOJE, '09:00');

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Eventos disparados pelo observer da ordem de serviço
    // -----------------------------------------------------------------

    public function test_ordem_de_servico_criada_com_data_futura_enfileira_visita_agendada(): void
    {
        $deA = $this->criarVisitaFutura($this->empresaA);
        $deB = $this->criarVisitaFutura($this->empresaB);

        $avisos = $this->itensDoEvento(EventosDeNotificacao::VISITA_AGENDADA);

        $this->assertCount(2, $avisos, 'as duas empresas precisavam ter o próprio aviso de visita agendada');

        $this->assertSame(
            [(int) $this->empresaA->id, (int) $this->empresaB->id],
            $avisos->pluck('company_id')->map('intval')->all()
        );

        $this->assertSame($deA->client->email, $avisos[0]->destino);
        $this->assertSame($deB->client->email, $avisos[1]->destino);
        $this->assertStringContainsString($deA->order_number, $avisos[0]->corpo);
        $this->assertStringNotContainsString($deB->order_number, $avisos[0]->corpo);
    }

    /**
     * OS lançada para hoje é registro de atendimento em curso. "Sua visita está
     * agendada" para quem já está com o técnico na porta é ruído.
     */
    public function test_ordem_de_servico_criada_para_hoje_nao_enfileira_visita_agendada(): void
    {
        $this->criarVisita($this->empresaA, [
            'scheduled_date' => self::HOJE,
            'status' => 'scheduled',
        ]);

        $this->assertCount(0, $this->itensDoEvento(EventosDeNotificacao::VISITA_AGENDADA));
    }

    public function test_mudanca_de_status_para_em_andamento_enfileira_tecnico_a_caminho(): void
    {
        $deA = $this->criarVisita($this->empresaA, ['scheduled_date' => self::HOJE, 'status' => 'scheduled']);
        $deB = $this->criarVisita($this->empresaB, ['scheduled_date' => self::HOJE, 'status' => 'scheduled']);

        $this->naEmpresa($this->empresaA, fn (): bool => $deA->update(['status' => 'in_progress']));
        $this->naEmpresa($this->empresaB, fn (): bool => $deB->update(['status' => 'in_progress']));

        $avisos = $this->itensDoEvento(EventosDeNotificacao::TECNICO_A_CAMINHO);

        $this->assertCount(2, $avisos);
        $this->assertSame(
            [(int) $this->empresaA->id, (int) $this->empresaB->id],
            $avisos->pluck('company_id')->map('intval')->all()
        );
        $this->assertSame($deA->client->email, $avisos[0]->destino);
    }

    public function test_mudanca_de_status_para_concluida_enfileira_os_concluida_com_o_id_no_contexto(): void
    {
        $visita = $this->criarVisita($this->empresaA, ['scheduled_date' => self::HOJE, 'status' => 'in_progress']);

        $this->naEmpresa($this->empresaA, fn (): bool => $visita->update(['status' => 'completed']));

        $avisos = $this->itensDoEvento(EventosDeNotificacao::OS_CONCLUIDA);

        $this->assertCount(1, $avisos);
        $this->assertSame(
            (int) $visita->id,
            (int) ($avisos[0]->contexto['work_order_id'] ?? 0),
            'sem work_order_id no contexto o driver de e-mail não tem como anexar o documento da OS'
        );
    }

    /**
     * Só a transição de status dispara aviso. A rotina financeira altera
     * `payment_status` de OS o tempo todo, e cada passada dela mandaria um
     * e-mail se a checagem fosse por alteração qualquer.
     */
    public function test_alteracao_que_nao_mexe_no_status_nao_enfileira_nada(): void
    {
        $visita = $this->criarVisita($this->empresaA, ['scheduled_date' => self::HOJE, 'status' => 'scheduled']);

        $this->naEmpresa($this->empresaA, fn (): bool => $visita->update([
            'observations' => 'Cliente pediu para entrar pelos fundos.',
            'payment_status' => 'paid',
        ]));

        $this->assertSame(0, NotificationQueue::query()->count());
    }

    // -----------------------------------------------------------------
    // Eventos disparados pela rotina diária
    // -----------------------------------------------------------------

    public function test_certificado_a_vencer_enfileira_para_o_cliente_e_para_a_empresa(): void
    {
        $certificado = $this->criarCertificado($this->empresaA, $this->diaEmBrasilia(30));

        $this->artisan('notificacoes:avisos-diarios')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::CERTIFICADO_A_VENCER);

        $this->assertCount(2, $avisos, 'o certificado a vencer avisa o cliente e a empresa, em dois itens');

        $doCliente = $avisos->firstWhere('destinatario_tipo', NotificationQueue::DESTINATARIO_CLIENTE);
        $daEmpresa = $avisos->firstWhere('destinatario_tipo', NotificationQueue::DESTINATARIO_EMPRESA);

        $this->assertNotNull($doCliente);
        $this->assertNotNull($daEmpresa);

        $this->assertSame($certificado->client->email, $doCliente->destino);
        $this->assertSame($this->empresaA->email, $daEmpresa->destino);
        $this->assertStringContainsString($certificado->certificate_number, $doCliente->corpo);

        // O texto do cliente e o aviso interno são diferentes de propósito: o
        // interno não é endereçado a quem não é o leitor.
        $this->assertStringContainsString('Olá, '.$certificado->client->name, $doCliente->corpo);
        $this->assertStringContainsString('O cliente recebeu o aviso dele em separado', $daEmpresa->corpo);
    }

    /**
     * O mesmo certificado avisa três vezes ao longo do tempo, e nenhuma vez a
     * mais. É o marco na chave de idempotência que separa os três, e é por isso
     * que ele existe.
     */
    public function test_certificado_a_trinta_quinze_e_sete_dias_gera_tres_avisos_distintos(): void
    {
        $garantia = '2026-10-01';
        $certificado = $this->criarCertificado($this->empresaA, $garantia);

        foreach ([30, 15, 7] as $dias) {
            $this->fixarRelogioEm(
                CarbonImmutable::parse($garantia, BusinessDate::fuso())->subDays($dias)->toDateString(),
                '09:00'
            );

            $this->artisan('notificacoes:avisos-diarios')->assertSuccessful();
        }

        $doCliente = $this->itensDoEvento(EventosDeNotificacao::CERTIFICADO_A_VENCER)
            ->where('destinatario_tipo', NotificationQueue::DESTINATARIO_CLIENTE)
            ->values();

        $this->assertCount(3, $doCliente, 'os três marcos do mesmo certificado não geraram três avisos');

        $chaves = $doCliente->pluck('chave_idempotencia')->all();

        $this->assertCount(3, array_unique($chaves), 'os três marcos saíram com a mesma chave de idempotência');

        foreach ([30, 15, 7] as $dias) {
            $this->assertTrue(
                $doCliente->contains(
                    fn (NotificationQueue $aviso): bool => str_ends_with((string) $aviso->chave_idempotencia, ':'.$dias)
                ),
                "faltou o aviso do marco de {$dias} dias"
            );
        }

        // O certificado é sempre o mesmo: três avisos, uma referência.
        $this->assertSame(
            [(int) $certificado->id],
            array_values(array_unique($doCliente->map(
                fn (NotificationQueue $aviso): int => (int) ($aviso->contexto['referencia_id'] ?? 0)
            )->all()))
        );
    }

    public function test_pagamento_vencido_ontem_enfileira_para_o_cliente_e_para_a_empresa(): void
    {
        $parcela = $this->criarParcelaVencida($this->empresaA, $this->diaEmBrasilia(-1));

        $this->artisan('notificacoes:avisos-diarios')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::PAGAMENTO_VENCIDO);

        $this->assertCount(2, $avisos);

        $doCliente = $avisos->firstWhere('destinatario_tipo', NotificationQueue::DESTINATARIO_CLIENTE);
        $daEmpresa = $avisos->firstWhere('destinatario_tipo', NotificationQueue::DESTINATARIO_EMPRESA);

        $this->assertNotNull($doCliente);
        $this->assertNotNull($daEmpresa);

        $this->assertSame($parcela->workOrder->client->email, $doCliente->destino);
        $this->assertSame($this->empresaA->email, $daEmpresa->destino);
        $this->assertStringContainsString('R$ 500,00', $doCliente->corpo);
    }

    public function test_contrato_a_vencer_enfileira_aviso_interno_para_a_empresa(): void
    {
        $dias = (int) config('notificacoes.dias_aviso_contrato_vencer');
        $contrato = $this->criarContrato($this->empresaA, $this->diaEmBrasilia($dias));

        $this->artisan('notificacoes:avisos-diarios')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::CONTRATO_A_VENCER);

        $this->assertCount(1, $avisos);
        $this->assertSame(NotificationQueue::DESTINATARIO_EMPRESA, $avisos[0]->destinatario_tipo);
        $this->assertSame($this->empresaA->email, $avisos[0]->destino);
        $this->assertStringContainsString((string) $contrato->contract_number, $avisos[0]->corpo);
    }

    public function test_orcamento_sem_resposta_perto_da_validade_enfileira_aviso_interno(): void
    {
        $dias = (int) config('notificacoes.dias_aviso_orcamento_a_expirar');
        $orcamento = $this->criarOrcamento($this->empresaA, $this->diaEmBrasilia($dias));

        $this->artisan('notificacoes:avisos-diarios')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::ORCAMENTO_A_EXPIRAR);

        $this->assertCount(1, $avisos);
        $this->assertSame($this->empresaA->email, $avisos[0]->destino);
        $this->assertStringContainsString('#'.$orcamento->id, $avisos[0]->corpo);
        $this->assertStringContainsString($orcamento->client->name, $avisos[0]->corpo);
    }

    /**
     * O lembrete fala da visita de amanhã e é agendado para hoje. Entregá-lo no
     * dia da visita transformaria o texto em informação errada, além de chegar
     * tarde demais para o cliente deixar o local acessível.
     */
    public function test_lembrete_de_vespera_sai_para_a_visita_de_amanha_agendado_para_hoje(): void
    {
        $this->criarVisita($this->empresaA, [
            'scheduled_date' => $this->diaEmBrasilia(1),
            'status' => 'scheduled',
        ]);

        $this->artisan('notificacoes:avisos-diarios')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::LEMBRETE_VESPERA);

        $this->assertCount(1, $avisos);

        $agendada = BusinessDate::paraFusoNegocio($avisos[0]->agendada_para);

        $this->assertNotNull($agendada);
        $this->assertSame(
            self::HOJE.' '.NotificationService::HORA_PADRAO_DE_ENVIO,
            $agendada->format('Y-m-d H:i'),
            'o lembrete de véspera precisa sair hoje, no horário padrão de envio do fuso do negócio'
        );
    }

    // -----------------------------------------------------------------
    // Idempotência da rotina diária
    // -----------------------------------------------------------------

    public function test_a_rotina_diaria_rodada_duas_vezes_no_mesmo_dia_nao_duplica_nada(): void
    {
        foreach ([$this->empresaA, $this->empresaB] as $empresa) {
            $this->criarCertificado($empresa, $this->diaEmBrasilia(30));
            $this->criarParcelaVencida($empresa, $this->diaEmBrasilia(-1));
            $this->criarContrato($empresa, $this->diaEmBrasilia((int) config('notificacoes.dias_aviso_contrato_vencer')));
            $this->criarOrcamento($empresa, $this->diaEmBrasilia((int) config('notificacoes.dias_aviso_orcamento_a_expirar')));
            $this->criarVisita($empresa, ['scheduled_date' => $this->diaEmBrasilia(1), 'status' => 'scheduled']);
        }

        $this->artisan('notificacoes:avisos-diarios')->assertSuccessful();

        $depoisDaPrimeira = NotificationQueue::query()->orderBy('id')->pluck('chave_idempotencia')->all();

        $this->assertNotEmpty($depoisDaPrimeira, 'o cenário precisa enfileirar algo, ou não testa duplicidade');
        $this->assertCount(
            count($depoisDaPrimeira),
            array_unique($depoisDaPrimeira),
            'a primeira passada já saiu com chave repetida'
        );

        $this->artisan('notificacoes:avisos-diarios')
            ->expectsOutputToContain('Já estavam na fila')
            ->assertSuccessful();

        $depoisDaSegunda = NotificationQueue::query()->orderBy('id')->pluck('chave_idempotencia')->all();

        $this->assertSame(
            $depoisDaPrimeira,
            $depoisDaSegunda,
            'a segunda execução do dia criou item novo: o cliente receberia o mesmo aviso duas vezes'
        );
    }

    /**
     * Cinco passadas seguidas, que é o cenário do operador rodando o comando à
     * mão para conferir enquanto o cron também roda.
     */
    public function test_cinco_passadas_da_rotina_no_mesmo_dia_deixam_a_fila_igual(): void
    {
        $this->criarCertificado($this->empresaA, $this->diaEmBrasilia(30));
        $this->criarCertificado($this->empresaB, $this->diaEmBrasilia(30));

        $this->artisan('notificacoes:avisos-diarios')->assertSuccessful();

        $total = NotificationQueue::query()->count();

        $this->assertSame(4, $total, 'duas empresas, um certificado cada, cliente e cópia interna');

        for ($passada = 2; $passada <= 5; $passada++) {
            $this->artisan('notificacoes:avisos-diarios')->assertSuccessful();
        }

        $this->assertSame($total, NotificationQueue::query()->count());
    }

    // -----------------------------------------------------------------
    // Continuidade depois de um erro
    // -----------------------------------------------------------------

    /**
     * Um dado ruim de um cliente não pode calar os avisos dos outros clientes da
     * mesma empresa, nem os das outras empresas.
     *
     * A fila do segundo tenant é afirmada de propósito: o comando terminar sem
     * exceção não prova nada, porque ele poderia ter engolido o erro e parado.
     */
    public function test_erro_em_um_registro_nao_impede_os_demais_nem_o_outro_tenant(): void
    {
        $comDefeito = $this->criarCertificado($this->empresaA, $this->diaEmBrasilia(30));
        $saudavelDeA = $this->criarCertificado($this->empresaA, $this->diaEmBrasilia(30));
        $deB = $this->criarCertificado($this->empresaB, $this->diaEmBrasilia(30));

        $this->app->instance(
            NotificationService::class,
            new NotificationServiceQueFalhaNoCertificado((int) $comDefeito->id)
        );

        $this->artisan('notificacoes:avisos-diarios')
            ->expectsOutputToContain('Os demais registros continuam sendo processados.')
            ->assertExitCode(Command::FAILURE);

        $avisos = $this->itensDoEvento(EventosDeNotificacao::CERTIFICADO_A_VENCER);
        $referencias = $avisos->map(fn (NotificationQueue $aviso): int => (int) ($aviso->contexto['referencia_id'] ?? 0));

        $this->assertNotContains(
            (int) $comDefeito->id,
            $referencias->all(),
            'o certificado com defeito não podia ter gerado aviso'
        );

        // O registro seguinte da mesma empresa continuou sendo processado.
        $this->assertContains((int) $saudavelDeA->id, $referencias->all());
        $this->assertCount(
            2,
            $avisos->where('company_id', $this->empresaA->id),
            'a empresa A precisava do aviso do cliente e da cópia interna do certificado saudável'
        );

        // E a empresa seguinte também: é a garantia que o plano exige.
        $daEmpresaB = $avisos->where('company_id', $this->empresaB->id)->values();

        $this->assertCount(2, $daEmpresaB, 'o erro na empresa A calou os avisos da empresa B');
        $this->assertSame(
            [(int) $deB->id, (int) $deB->id],
            $daEmpresaB->map(fn (NotificationQueue $aviso): int => (int) ($aviso->contexto['referencia_id'] ?? 0))->all()
        );
    }

    // -----------------------------------------------------------------
    // O corte de vencimento é o dia de Brasília
    // -----------------------------------------------------------------

    /**
     * 23h de Brasília: o servidor em UTC já está no dia seguinte. O certificado
     * que fica a 30 dias do dia de Brasília precisa ser avisado, e o que fica a
     * 30 dias do dia em UTC não.
     */
    public function test_o_corte_de_certificado_a_vencer_usa_o_dia_de_brasilia(): void
    {
        $this->fixarRelogioEm(self::HOJE, '23:00');

        $this->assertSame(self::HOJE, BusinessDate::hoje()->toDateString());
        $this->assertSame('2026-07-27', Carbon::now()->toDateString(), 'o cenário precisa rodar com UTC já no dia seguinte');

        $noDiaDeBrasilia = $this->criarCertificado($this->empresaA, $this->diaEmBrasilia(30));
        $umDiaAdiante = $this->criarCertificado($this->empresaA, $this->diaEmBrasilia(31));

        $this->artisan('notificacoes:avisos-diarios')->assertSuccessful();

        $referencias = $this->itensDoEvento(EventosDeNotificacao::CERTIFICADO_A_VENCER)
            ->map(fn (NotificationQueue $aviso): int => (int) ($aviso->contexto['referencia_id'] ?? 0))
            ->unique()
            ->values()
            ->all();

        $this->assertSame(
            [(int) $noDiaDeBrasilia->id],
            $referencias,
            'a rotina escorregou um dia: o corte foi feito com o dia em UTC, não com o de Brasília'
        );

        $this->assertNotContains((int) $umDiaAdiante->id, $referencias);
    }

    /**
     * Mesmo corte, do outro lado da linha: "vencido ontem" é ontem em Brasília.
     */
    public function test_o_corte_de_pagamento_vencido_usa_o_dia_de_brasilia(): void
    {
        $this->fixarRelogioEm(self::HOJE, '23:00');

        $ontemEmBrasilia = $this->criarParcelaVencida($this->empresaA, $this->diaEmBrasilia(-1));
        $ontemEmUtc = $this->criarParcelaVencida($this->empresaA, self::HOJE);

        $this->artisan('notificacoes:avisos-diarios')->assertSuccessful();

        $referencias = $this->itensDoEvento(EventosDeNotificacao::PAGAMENTO_VENCIDO)
            ->map(fn (NotificationQueue $aviso): int => (int) ($aviso->contexto['referencia_id'] ?? 0))
            ->unique()
            ->values()
            ->all();

        $this->assertSame([(int) $ontemEmBrasilia->id], $referencias);
        $this->assertNotContains((int) $ontemEmUtc->id, $referencias);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Fixa o relógio no instante em UTC correspondente à hora informada em
     * Brasília.
     *
     * O `setTestNow` recebe uma instância em UTC de propósito: Carbon empresta o
     * fuso do relógio mockado para toda data criada sem fuso explícito, e o cast
     * de coluna `datetime` do Eloquent é uma delas.
     */
    private function fixarRelogioEm(string $diaEmBrasilia, string $hora): void
    {
        $emUtc = CarbonImmutable::parse($diaEmBrasilia.' '.$hora, BusinessDate::fuso())->setTimezone('UTC');

        Carbon::setTestNow(Carbon::parse($emUtc->format('Y-m-d H:i:s'), 'UTC'));
    }

    /**
     * Dia no fuso do negócio, a tantos dias de hoje.
     */
    private function diaEmBrasilia(int $dias): string
    {
        return BusinessDate::hoje()->addDays($dias)->toDateString();
    }

    private function naEmpresa(Company $empresa, Closure $callback): mixed
    {
        return TenantAtual::comTenant((int) $empresa->id, $callback);
    }

    /**
     * Ordem de serviço agendada para amanhã, que é o caso que dispara o aviso de
     * visita agendada.
     */
    private function criarVisitaFutura(Company $empresa): WorkOrder
    {
        return $this->criarVisita($empresa, [
            'scheduled_date' => $this->diaEmBrasilia(1),
            'status' => 'scheduled',
        ]);
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function criarVisita(Company $empresa, array $atributos = []): WorkOrder
    {
        return $this->naEmpresa($empresa, function () use ($atributos): WorkOrder {
            $cliente = ClientFactory::new()->create();

            return WorkOrderFactory::new()->create(array_merge(['client_id' => $cliente->id], $atributos));
        });
    }

    private function criarCertificado(Company $empresa, string $garantia): Certificate
    {
        return $this->naEmpresa($empresa, fn (): Certificate => CertificateFactory::new()->create([
            'client_id' => ClientFactory::new()->create()->id,
            'warranty' => $garantia,
        ]));
    }

    private function criarParcelaVencida(Company $empresa, string $vencimento): PaymentDetail
    {
        return $this->naEmpresa($empresa, function () use ($vencimento): PaymentDetail {
            $cliente = ClientFactory::new()->create();

            $ordem = WorkOrderFactory::new()->create([
                'client_id' => $cliente->id,
                // No passado, para o observer de visita agendada não entrar no
                // cenário e confundir a contagem da fila.
                'scheduled_date' => $this->diaEmBrasilia(-10),
            ]);

            return PaymentDetailFactory::new()->create([
                'work_order_id' => $ordem->id,
                'payment_due_date' => $vencimento,
            ]);
        });
    }

    private function criarContrato(Company $empresa, string $fimDaVigencia): Contract
    {
        return $this->naEmpresa($empresa, function () use ($fimDaVigencia): Contract {
            $cliente = ClientFactory::new()->create();
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);

            return Contract::create([
                'address_id' => $endereco->id,
                'contract_number' => 'CONT-'.uniqid(),
                'start_date' => $this->diaEmBrasilia(-365),
                'end_date' => $fimDaVigencia,
                'service_value' => 1200.00,
                // Pontual de propósito: contrato periódico entraria também no
                // painel de pendências, e o cenário perderia o foco.
                'service_type' => 'pontual',
            ]);
        });
    }

    private function criarOrcamento(Company $empresa, string $validade): Budget
    {
        return $this->naEmpresa($empresa, function () use ($validade): Budget {
            $cliente = ClientFactory::new()->create();

            return Budget::create([
                'client_id' => $cliente->id,
                'status' => 'sent',
                'date' => $this->diaEmBrasilia(-5),
                'validity_date' => $validade,
            ]);
        });
    }

    /**
     * Itens do evento em todas as empresas, na ordem em que entraram na fila.
     *
     * A consulta roda fora de `comTenant`, e nesse estado o escopo global não
     * filtra nada: é o que permite conferir as duas empresas de uma vez.
     *
     * @return Collection<int, NotificationQueue>
     */
    private function itensDoEvento(string $evento): Collection
    {
        return NotificationQueue::query()
            ->where('evento', $evento)
            ->orderBy('id')
            ->get();
    }
}

/**
 * Service que estoura em um certificado escolhido e se comporta normalmente no
 * resto.
 *
 * É como o teste reproduz "dado inconsistente em um registro" sem precisar
 * corromper o banco de propósito. Fica neste arquivo porque só este teste o usa
 * e a Task 14.9 declara três arquivos.
 */
class NotificationServiceQueFalhaNoCertificado extends NotificationService
{
    public function __construct(private readonly int $certificadoQueFalha) {}

    /**
     * @param  array<string, mixed>  $opcoes
     * @return array{resultado: string, criado: bool, item: NotificationQueue|null, canal: string|null, chave_idempotencia: string|null, mensagem: string}
     */
    public function enfileirar(string $evento, Model $referencia, array $opcoes = []): array
    {
        if ($referencia instanceof Certificate && (int) $referencia->getKey() === $this->certificadoQueFalha) {
            throw new RuntimeException(
                "Dado inconsistente no certificado #{$this->certificadoQueFalha}."
            );
        }

        return parent::enfileirar($evento, $referencia, $opcoes);
    }
}
