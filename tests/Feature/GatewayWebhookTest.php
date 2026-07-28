<?php

namespace Tests\Feature;

use App\Contracts\GatewayAssinatura;
use App\Models\Company;
use App\Models\GatewayEvent;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use App\Support\BusinessDate;
use App\Support\Gateway\EventoDeGateway;
use App\Support\Gateway\RespostaDeAssinatura;
use App\Support\Gateway\RespostaDeCobranca;
use App\Support\TenantAtual;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Recebimento, registro e processamento do webhook do gateway (Plano 7, Tasks
 * 7.6 e 7.11).
 *
 * Nasceu como `GatewayEventServiceTest` na Task 7.6 e é o arquivo que a Task
 * 7.11 pede pelo nome: o que decide se alguém recebe baixa em duplicidade ou
 * fica devendo uma fatura que pagou. Os sete casos exigidos pela 7.11, na ordem
 * da especificação:
 *
 * 1. Token inválido devolve 401 e não grava `GatewayEvent`
 *    (`test_webhook_sem_autenticidade_valida_devolve_401_e_nao_grava_nada`).
 * 2. Evento válido grava, processa e a fatura vira paga
 *    (`test_evento_de_cobranca_paga_baixa_a_fatura_e_marca_o_evento_como_processado`).
 * 3. O mesmo `evento_id` duas vezes gera um único evento e um único efeito
 *    (`test_o_mesmo_evento_enviado_duas_vezes_produz_um_unico_efeito`).
 * 4. Tipo desconhecido é gravado e processado, sem erro e sem efeito
 *    (`test_evento_de_tipo_desconhecido_e_gravado_e_processado_sem_erro`).
 * 5. Erro de processamento devolve 200, grava `erro` e incrementa `tentativas`
 *    (`test_fatura_nao_localizada_devolve_200_grava_o_erro_e_incrementa_tentativas`).
 * 6. `plataforma:reprocessar-eventos` reprocessa e marca como processado
 *    (`test_reprocessar_eventos_baixa_a_fatura_depois_de_ela_ser_conciliada`).
 * 7. A rota responde sem autenticação e fora do CSRF
 *    (`test_a_rota_do_webhook_responde_sem_autenticacao_e_esta_fora_da_verificacao_de_csrf`).
 *
 * O provedor nunca é chamado de verdade: `GatewayDeWebhookDeTeste` fica no lugar
 * de `GatewayAssinatura` no container e conta quantas vezes cada método foi
 * usado, o que é como o teste prova que o processamento de um evento de
 * cancelamento não liga de volta para o provedor.
 */
class GatewayWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const ROTA = '/webhooks/gateway/pagbank';

    private GatewayDeWebhookDeTeste $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->gateway = new GatewayDeWebhookDeTeste;
        $this->app->instance(GatewayAssinatura::class, $this->gateway);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Autenticidade e endereço
    // -----------------------------------------------------------------

    public function test_webhook_sem_autenticidade_valida_devolve_401_e_nao_grava_nada(): void
    {
        $this->gateway->autentico = false;

        $this->postJson(self::ROTA, [
            'evento_id' => 'evt-1',
            'tipo' => EventoDeGateway::TIPO_COBRANCA_PAGA,
        ])->assertStatus(401);

        $this->assertDatabaseCount('gateway_events', 0);
        $this->assertSame(0, $this->gateway->interpretacoes, 'o payload foi interpretado antes de a autenticidade ser confirmada');
    }

    public function test_gateway_desconhecido_devolve_404_sem_sequer_validar_a_assinatura(): void
    {
        $this->postJson('/webhooks/gateway/outro-provedor', ['evento_id' => 'evt-1'])
            ->assertStatus(404);

        $this->assertDatabaseCount('gateway_events', 0);
        $this->assertSame(0, $this->gateway->validacoes);
    }

    public function test_a_rota_do_webhook_responde_sem_autenticacao_e_esta_fora_da_verificacao_de_csrf(): void
    {
        $isentas = new ReflectionProperty(ValidateCsrfToken::class, 'neverVerify');
        $isentas->setAccessible(true);

        $this->assertContains('webhooks/gateway/*', $isentas->getValue());

        // Sem usuário autenticado: a resposta é a do processamento, nunca um
        // redirecionamento para o login.
        $this->assertGuest();
        $this->postJson(self::ROTA, ['evento_id' => 'evt-1'])->assertOk();
    }

    // -----------------------------------------------------------------
    // Efeito de domínio e idempotência
    // -----------------------------------------------------------------

    public function test_evento_de_cobranca_paga_baixa_a_fatura_e_marca_o_evento_como_processado(): void
    {
        $fatura = $this->cenarioComFatura('Dedetizadora Alfa');

        $this->postJson(self::ROTA, [
            'evento_id' => 'evt-pago-1',
            'tipo' => EventoDeGateway::TIPO_COBRANCA_PAGA,
            'recurso' => $fatura->gateway_invoice_id,
            'situacao' => RespostaDeCobranca::SITUACAO_PAGA,
            'pago_em' => BusinessDate::hoje()->toDateString(),
        ])->assertOk();

        $fatura->refresh();

        $this->assertSame(RespostaDeCobranca::SITUACAO_PAGA, $fatura->situacao);
        $this->assertSame(BusinessDate::hoje()->toDateString(), $fatura->pago_em->toDateString());

        $evento = GatewayEvent::query()->firstOrFail();

        $this->assertSame('pagbank', $evento->gateway);
        $this->assertNotNull($evento->processado_em);
        $this->assertNull($evento->erro);
        $this->assertSame(0, $evento->tentativas);
        $this->assertSame('evt-pago-1', $evento->payload['evento_id'], 'o payload gravado precisa ser o corpo cru recebido');
    }

    public function test_o_mesmo_evento_enviado_duas_vezes_produz_um_unico_efeito(): void
    {
        $fatura = $this->cenarioComFatura('Dedetizadora Beta');
        $assinatura = $fatura->subscription;

        $corpo = [
            'evento_id' => 'evt-pago-repetido',
            'tipo' => EventoDeGateway::TIPO_COBRANCA_PAGA,
            'recurso' => $fatura->gateway_invoice_id,
            'situacao' => RespostaDeCobranca::SITUACAO_PAGA,
            'pago_em' => BusinessDate::hoje()->toDateString(),
        ];

        $this->postJson(self::ROTA, $corpo)->assertOk();
        $this->postJson(self::ROTA, $corpo)->assertOk();

        $this->assertDatabaseCount('gateway_events', 1);

        // O segundo envio não pode empurrar a próxima cobrança mais um mês: o
        // tenant deixaria de ser cobrado por um período inteiro.
        $this->assertSame(
            BusinessDate::hoje()->addMonthNoOverflow()->toDateString(),
            $assinatura->refresh()->proxima_cobranca_em->toDateString()
        );
        $this->assertSame(
            BusinessDate::hoje()->toDateString(),
            $fatura->refresh()->pago_em->toDateString()
        );
    }

    public function test_evento_de_tipo_desconhecido_e_gravado_e_processado_sem_erro(): void
    {
        // A fatura existe só para provar a ausência de efeito: o PagBank manda
        // dezenas de eventos que este sistema não usa, e nenhum deles pode mexer
        // na cobrança de ninguém.
        $fatura = $this->cenarioComFatura('Dedetizadora Ômicron');
        $assinatura = $fatura->subscription;

        $this->postJson(self::ROTA, [
            'evento_id' => 'evt-desconhecido',
            'tipo' => EventoDeGateway::TIPO_DESCONHECIDO,
            'recurso' => $fatura->gateway_invoice_id,
            'seja_la_o_que_for' => ['algo' => 'que o provedor mandou'],
        ])->assertOk();

        $evento = GatewayEvent::query()->firstOrFail();

        $this->assertSame(EventoDeGateway::TIPO_DESCONHECIDO, $evento->tipo);
        $this->assertNotNull($evento->processado_em);
        $this->assertNull($evento->erro);
        $this->assertSame(0, $evento->tentativas);

        $this->assertSame(RespostaDeCobranca::SITUACAO_ABERTA, $fatura->refresh()->situacao);
        $this->assertNull($fatura->pago_em);
        $this->assertSame(RespostaDeAssinatura::SITUACAO_ATIVA, $assinatura->refresh()->situacao);
    }

    public function test_cobranca_cancelada_cancela_a_fatura_em_aberto_e_nao_desfaz_a_paga(): void
    {
        $fatura = $this->cenarioComFatura('Dedetizadora Gama');

        $this->postJson(self::ROTA, [
            'evento_id' => 'evt-cancelada-1',
            'tipo' => EventoDeGateway::TIPO_COBRANCA_CANCELADA,
            'recurso' => $fatura->gateway_invoice_id,
            'situacao' => RespostaDeCobranca::SITUACAO_CANCELADA,
        ])->assertOk();

        $this->assertSame(RespostaDeCobranca::SITUACAO_CANCELADA, $fatura->refresh()->situacao);

        $paga = $this->cenarioComFatura('Dedetizadora Delta');
        $paga->update([
            'situacao' => RespostaDeCobranca::SITUACAO_PAGA,
            'pago_em' => BusinessDate::hoje()->toDateString(),
        ]);

        $this->postJson(self::ROTA, [
            'evento_id' => 'evt-cancelada-2',
            'tipo' => EventoDeGateway::TIPO_COBRANCA_CANCELADA,
            'recurso' => $paga->gateway_invoice_id,
            'situacao' => RespostaDeCobranca::SITUACAO_CANCELADA,
        ])->assertOk();

        $this->assertSame(
            RespostaDeCobranca::SITUACAO_PAGA,
            $paga->refresh()->situacao,
            'um evento de cancelamento tardio apagou a baixa de uma fatura já paga'
        );
    }

    public function test_assinatura_cancelada_encerra_o_contrato_sem_ligar_de_volta_para_o_provedor(): void
    {
        $fatura = $this->cenarioComFatura('Dedetizadora Epsilon');
        $assinatura = $fatura->subscription;

        $this->postJson(self::ROTA, [
            'evento_id' => 'evt-assinatura-cancelada',
            'tipo' => EventoDeGateway::TIPO_ASSINATURA_CANCELADA,
            'recurso' => $assinatura->gateway_subscription_id,
        ])->assertOk();

        $assinatura->refresh();

        $this->assertSame(RespostaDeAssinatura::SITUACAO_CANCELADA, $assinatura->situacao);
        $this->assertNotNull($assinatura->cancelada_em);
        $this->assertSame(
            SubscriptionService::MOTIVO_CANCELADO_PELO_PROVEDOR,
            $assinatura->motivo_cancelamento
        );
        $this->assertSame(
            0,
            $this->gateway->cancelamentosPedidos,
            'o processamento do webhook pediu ao provedor um cancelamento que ele já tinha feito'
        );

        // O período já pago continua valendo: cancelar não corta o acesso.
        $this->assertNotNull($assinatura->proxima_cobranca_em);
    }

    // -----------------------------------------------------------------
    // Erro de processamento
    // -----------------------------------------------------------------

    public function test_fatura_nao_localizada_devolve_200_grava_o_erro_e_incrementa_tentativas(): void
    {
        $this->postJson(self::ROTA, [
            'evento_id' => 'evt-orfao',
            'tipo' => EventoDeGateway::TIPO_COBRANCA_PAGA,
            'recurso' => 'ORDE_QUE_NINGUEM_CONHECE',
            'situacao' => RespostaDeCobranca::SITUACAO_PAGA,
            'pago_em' => BusinessDate::hoje()->toDateString(),
        ])->assertOk();

        $evento = GatewayEvent::query()->firstOrFail();

        $this->assertNull($evento->processado_em);
        $this->assertNotNull($evento->erro);
        $this->assertSame(1, $evento->tentativas);
        $this->assertStringContainsString('ORDE_QUE_NINGUEM_CONHECE', (string) $evento->erro);
    }

    // -----------------------------------------------------------------
    // Reprocessamento
    // -----------------------------------------------------------------

    public function test_reprocessar_eventos_baixa_a_fatura_depois_de_ela_ser_conciliada(): void
    {
        $fatura = $this->cenarioComFatura('Dedetizadora Zeta');
        $idNoProvedor = (string) $fatura->gateway_invoice_id;

        // Chega antes de a fatura ter o identificador do provedor gravado, que é
        // o caso real de conciliação perdida.
        $fatura->update(['gateway_invoice_id' => null]);

        $this->postJson(self::ROTA, [
            'evento_id' => 'evt-para-reprocessar',
            'tipo' => EventoDeGateway::TIPO_COBRANCA_PAGA,
            'recurso' => $idNoProvedor,
            'situacao' => RespostaDeCobranca::SITUACAO_PAGA,
            'pago_em' => BusinessDate::hoje()->toDateString(),
        ])->assertOk();

        $this->assertSame(1, GatewayEvent::query()->firstOrFail()->tentativas);

        $fatura->update(['gateway_invoice_id' => $idNoProvedor]);

        $this->artisan('plataforma:reprocessar-eventos')->assertSuccessful();

        $this->assertSame(RespostaDeCobranca::SITUACAO_PAGA, $fatura->refresh()->situacao);

        $evento = GatewayEvent::query()->firstOrFail();

        $this->assertNotNull($evento->processado_em);
        $this->assertNull($evento->erro);
    }

    public function test_reprocessar_eventos_sem_pendencia_nao_faz_nada(): void
    {
        $this->artisan('plataforma:reprocessar-eventos')
            ->expectsOutput('Nenhum evento de gateway pendente de reprocessamento.')
            ->assertSuccessful();
    }

    public function test_reprocessar_eventos_com_id_inexistente_recusa(): void
    {
        $this->artisan('plataforma:reprocessar-eventos', ['--id' => '999'])
            ->assertExitCode(2);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Tenant comum com plano, assinatura ativa e uma fatura em aberto já com
     * cobrança emitida no provedor.
     */
    private function cenarioComFatura(string $nome): Invoice
    {
        $empresa = Company::create(['name' => $nome]);

        $plano = Plan::create([
            'nome' => $nome,
            'slug' => str($nome)->slug()->toString().'-'.uniqid(),
            'valor' => 199.90,
            'periodicidade' => SubscriptionService::PERIODICIDADE_MENSAL,
            'ativo' => true,
        ]);

        $assinatura = Subscription::create([
            'company_id' => $empresa->id,
            'plan_id' => $plano->id,
            'situacao' => RespostaDeAssinatura::SITUACAO_ATIVA,
            'forma_pagamento' => SubscriptionService::FORMA_PIX,
            'gateway_subscription_id' => 'SUBS_'.$empresa->id,
            'inicio_em' => BusinessDate::hoje()->subMonthNoOverflow()->toDateString(),
            'proxima_cobranca_em' => BusinessDate::hoje()->toDateString(),
        ]);

        $fatura = Invoice::create([
            'company_id' => $empresa->id,
            'subscription_id' => $assinatura->id,
            'referencia' => BusinessDate::hoje()->format('Y-m'),
            'valor' => $plano->valor,
            'situacao' => RespostaDeCobranca::SITUACAO_ABERTA,
            'vencimento' => BusinessDate::hoje()->toDateString(),
            'gateway_invoice_id' => 'ORDE_'.$empresa->id,
        ]);

        $fatura->setRelation('subscription', $assinatura);

        return $fatura;
    }
}

/**
 * Dublê de `GatewayAssinatura` para os caminhos de webhook, sem rede.
 *
 * `interpretarWebhook()` lê o payload no formato do próprio teste, e não no do
 * PagBank: a tradução do formato do provedor já é coberta por
 * `PagBankGatewayTest`, e repeti-la aqui misturaria dois assuntos no mesmo
 * arquivo. O que este dublê precisa provar é outra coisa: quantas vezes cada
 * método foi chamado.
 *
 * Fica neste arquivo, e não em `tests/Support/`, pelo mesmo motivo dos dublês
 * das Tasks 7.4 e 7.5: a Task 7.11 monta o dela com as necessidades dela.
 */
class GatewayDeWebhookDeTeste implements GatewayAssinatura
{
    public bool $autentico = true;

    public int $validacoes = 0;

    public int $interpretacoes = 0;

    public int $cancelamentosPedidos = 0;

    public function registrarMeioDePagamento(Company $empresa, string $cartaoCifrado): void {}

    public function criarAssinatura(Company $empresa, Plan $plano, string $formaPagamento): RespostaDeAssinatura
    {
        return new RespostaDeAssinatura('SUBS_de_teste', RespostaDeAssinatura::SITUACAO_ATIVA);
    }

    public function cancelarAssinatura(string $idNoGateway): void
    {
        $this->cancelamentosPedidos++;
    }

    public function trocarPlano(string $idNoGateway, Plan $novoPlano): RespostaDeAssinatura
    {
        return new RespostaDeAssinatura($idNoGateway, RespostaDeAssinatura::SITUACAO_ATIVA);
    }

    public function gerarCobranca(Invoice $fatura): RespostaDeCobranca
    {
        return new RespostaDeCobranca('ORDE_'.$fatura->id, RespostaDeCobranca::SITUACAO_ABERTA);
    }

    public function consultarCobranca(string $idNoGateway): RespostaDeCobranca
    {
        return new RespostaDeCobranca($idNoGateway, RespostaDeCobranca::SITUACAO_ABERTA);
    }

    public function validarWebhook(Request $requisicao): bool
    {
        $this->validacoes++;

        return $this->autentico;
    }

    public function interpretarWebhook(array $payload): EventoDeGateway
    {
        $this->interpretacoes++;

        $pagoEm = $payload['pago_em'] ?? null;

        return new EventoDeGateway(
            eventoId: (string) ($payload['evento_id'] ?? 'evt-sem-identificador'),
            tipo: (string) ($payload['tipo'] ?? EventoDeGateway::TIPO_DESCONHECIDO),
            faturaIdNoGateway: isset($payload['recurso']) ? (string) $payload['recurso'] : null,
            situacao: isset($payload['situacao']) ? (string) $payload['situacao'] : null,
            pagoEm: is_string($pagoEm) ? CarbonImmutable::parse($pagoEm) : null,
        );
    }
}
