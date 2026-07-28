<?php

namespace Tests\Feature;

use App\Contracts\GatewayAssinatura;
use App\Exceptions\AssinaturaInvalidaException;
use App\Exceptions\GatewayRecusouException;
use App\Exceptions\TenantInternoException;
use App\Models\Client;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use App\Support\BusinessDate;
use App\Support\Gateway\EventoDeGateway;
use App\Support\Gateway\RespostaDeAssinatura;
use App\Support\Gateway\RespostaDeCobranca;
use App\Support\TenantAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Task 7.4 do Plano 7: as regras de assinatura do tenant.
 *
 * O conjunto completo (webhook, régua de inadimplência, imunidade do tenant
 * interno em todos os caminhos) é a Task 7.11. Este arquivo trava agora os
 * critérios de aceitação da 7.4, que são os que decidem se o tenant é cobrado
 * certo: tenant interno recusado antes de qualquer coisa, cartão sem cifra
 * recusado antes do provedor, e nada gravado quando o provedor recusa.
 *
 * O provedor nunca é chamado de verdade: `GatewayDeTeste` fica no lugar de
 * `GatewayAssinatura` no container e anota o que foi pedido, na ordem.
 */
class SubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private GatewayDeTeste $gateway;

    private SubscriptionService $subscriptionService;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->gateway = new GatewayDeTeste;
        $this->app->instance(GatewayAssinatura::class, $this->gateway);

        $this->subscriptionService = $this->app->make(SubscriptionService::class);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Tenant interno: a regra que não pode falhar
    // -----------------------------------------------------------------

    public function test_assinar_no_tenant_interno_lanca_excecao_e_nao_chama_o_provedor(): void
    {
        $interno = $this->tenantInterno();
        $plano = $this->criarPlano('Essencial');

        try {
            $this->subscriptionService->assinar($interno, $plano, SubscriptionService::FORMA_PIX);
            $this->fail('Assinar o tenant interno deveria lançar TenantInternoException.');
        } catch (TenantInternoException) {
            // esperado
        }

        $this->assertSame([], $this->gateway->chamadas);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_situacao_do_tenant_interno_volta_imune_e_sem_cobranca(): void
    {
        $interno = $this->tenantInterno();
        $plano = $this->criarPlano('Interno');
        $interno->update(['plan_id' => $plano->id]);

        $situacao = $this->subscriptionService->situacaoDe($interno->refresh());

        $this->assertTrue($situacao['imune']);
        $this->assertFalse($situacao['tem_assinatura']);
        $this->assertNull($situacao['situacao']);
        $this->assertNull($situacao['proxima_cobranca_em']);
        $this->assertSame('Interno', $situacao['plano']['nome']);
    }

    public function test_cancelar_assinatura_de_tenant_interno_e_recusado(): void
    {
        $interno = $this->tenantInterno();
        $plano = $this->criarPlano('Essencial');

        // Assinatura criada direto no banco, como se tivesse entrado por
        // importação ou correção manual: o Service continua barrando.
        $assinatura = Subscription::create([
            'company_id' => $interno->id,
            'plan_id' => $plano->id,
            'situacao' => RespostaDeAssinatura::SITUACAO_ATIVA,
            'forma_pagamento' => SubscriptionService::FORMA_BOLETO,
            'gateway_subscription_id' => 'sub_interno',
            'inicio_em' => BusinessDate::hoje()->toDateString(),
        ]);

        $this->expectException(TenantInternoException::class);

        try {
            $this->subscriptionService->cancelar($assinatura, 'teste');
        } finally {
            $this->assertSame([], $this->gateway->chamadas);
            $this->assertSame(
                RespostaDeAssinatura::SITUACAO_ATIVA,
                $assinatura->refresh()->situacao
            );
        }
    }

    // -----------------------------------------------------------------
    // Contratação
    // -----------------------------------------------------------------

    public function test_assinar_cria_no_provedor_e_no_banco_com_datas_no_fuso_do_negocio(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Alfa');
        $plano = $this->criarPlano('Essencial');

        $assinatura = $this->subscriptionService->assinar(
            $empresa,
            $plano,
            SubscriptionService::FORMA_BOLETO
        );

        $this->assertSame(['criarAssinatura'], $this->gateway->chamadas);

        $hoje = BusinessDate::hoje();

        $this->assertDatabaseHas('subscriptions', [
            'company_id' => $empresa->id,
            'plan_id' => $plano->id,
            'situacao' => RespostaDeAssinatura::SITUACAO_ATIVA,
            'forma_pagamento' => SubscriptionService::FORMA_BOLETO,
            'gateway_subscription_id' => 'sub_de_teste',
            'inicio_em' => $hoje->toDateString(),
            'proxima_cobranca_em' => $hoje->addMonthNoOverflow()->toDateString(),
        ]);

        // O Plano 6 lê o plano pela empresa: gravar só na assinatura deixaria o
        // tenant pagando um plano e usando outro.
        $this->assertSame($plano->id, $empresa->refresh()->plan_id);
        $this->assertNull($assinatura->cancelada_em);
    }

    public function test_assinar_no_cartao_cadastra_o_meio_de_pagamento_antes_de_criar_a_assinatura(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Beta');
        $plano = $this->criarPlano('Profissional');

        $this->subscriptionService->assinar(
            $empresa,
            $plano,
            SubscriptionService::FORMA_CARTAO,
            'cartao-cifrado-no-navegador'
        );

        $this->assertSame(
            ['registrarMeioDePagamento', 'criarAssinatura'],
            $this->gateway->chamadas
        );
        $this->assertSame('cartao-cifrado-no-navegador', $this->gateway->cartaoRecebido);
    }

    public function test_assinar_no_cartao_sem_a_cifra_e_recusado_antes_do_provedor(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Gama');
        $plano = $this->criarPlano('Essencial');

        try {
            $this->subscriptionService->assinar($empresa, $plano, SubscriptionService::FORMA_CARTAO);
            $this->fail('Cartão sem cifra deveria lançar AssinaturaInvalidaException.');
        } catch (AssinaturaInvalidaException) {
            // esperado
        }

        $this->assertSame([], $this->gateway->chamadas);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_assinar_no_pix_ignora_o_cartao_cifrado_que_venha_junto(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Delta');
        $plano = $this->criarPlano('Essencial');

        $this->subscriptionService->assinar(
            $empresa,
            $plano,
            SubscriptionService::FORMA_PIX,
            'cartao-que-nao-deveria-ser-usado'
        );

        $this->assertSame(['criarAssinatura'], $this->gateway->chamadas);
        $this->assertNull($this->gateway->cartaoRecebido);
    }

    public function test_recusa_do_provedor_nao_deixa_assinatura_no_banco(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Epsilon');
        $plano = $this->criarPlano('Essencial');

        $this->gateway->erroAoCriar = new GatewayRecusouException('provedor recusou');

        try {
            $this->subscriptionService->assinar($empresa, $plano, SubscriptionService::FORMA_PIX);
            $this->fail('A recusa do provedor deveria subir.');
        } catch (GatewayRecusouException) {
            // esperado
        }

        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertNull($empresa->refresh()->plan_id);
    }

    public function test_falha_no_banco_depois_do_provedor_registra_pendencia_com_o_id_do_gateway(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Zeta');

        // Plano que não existe no banco: o provedor aceita, e a inserção local
        // bate na chave estrangeira de `subscriptions.plan_id`.
        $planoFantasma = new Plan(['nome' => 'Fantasma', 'periodicidade' => 'mensal']);
        $planoFantasma->id = 999999;

        Log::spy();

        try {
            $this->subscriptionService->assinar($empresa, $planoFantasma, SubscriptionService::FORMA_PIX);
            $this->fail('A falha de banco deveria subir.');
        } catch (\Throwable) {
            // esperado
        }

        $this->assertDatabaseCount('subscriptions', 0);

        Log::shouldHaveReceived('critical')->once()->withArgs(
            function (string $mensagem, array $contexto): bool {
                return str_contains($mensagem, 'Conciliação manual')
                    && $contexto['operacao'] === 'assinar'
                    && $contexto['gateway_subscription_id'] === 'sub_de_teste';
            }
        );
    }

    public function test_assinar_duas_vezes_e_recusado_antes_de_criar_a_segunda_no_provedor(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Eta');
        $plano = $this->criarPlano('Essencial');

        $this->subscriptionService->assinar($empresa, $plano, SubscriptionService::FORMA_PIX);
        $this->gateway->chamadas = [];

        $this->expectException(AssinaturaInvalidaException::class);

        try {
            $this->subscriptionService->assinar($empresa->refresh(), $plano, SubscriptionService::FORMA_PIX);
        } finally {
            $this->assertSame([], $this->gateway->chamadas);
            $this->assertDatabaseCount('subscriptions', 1);
        }
    }

    // -----------------------------------------------------------------
    // Troca de plano, troca de forma e cancelamento
    // -----------------------------------------------------------------

    public function test_trocar_plano_nao_apaga_dado_e_nao_antecipa_a_proxima_cobranca(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Teta');
        $planoMaior = $this->criarPlano('Profissional');
        $planoMenor = $this->criarPlano('Essencial');

        TenantAtual::comTenant($empresa->id, fn (): Client => Client::create([
            'name' => 'Cliente que precisa continuar existindo',
            'email' => 'cliente@teta.test',
            'phone' => '11900000001',
            'cnpj' => '99.999.999/0001-99',
        ]));

        $assinatura = $this->subscriptionService->assinar(
            $empresa,
            $planoMaior,
            SubscriptionService::FORMA_BOLETO
        );
        $proximaCobranca = $assinatura->proxima_cobranca_em->toDateString();

        $trocada = $this->subscriptionService->trocarPlano($assinatura, $planoMenor);

        $this->assertSame($planoMenor->id, $trocada->plan_id);
        $this->assertSame($planoMenor->id, $empresa->refresh()->plan_id);
        $this->assertSame($proximaCobranca, $trocada->proxima_cobranca_em->toDateString());
        $this->assertSame(1, Client::withoutGlobalScopes()->where('company_id', $empresa->id)->count());
    }

    public function test_trocar_forma_para_cartao_sem_cifra_e_recusado_antes_do_provedor(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Iota');
        $plano = $this->criarPlano('Essencial');

        $assinatura = $this->subscriptionService->assinar($empresa, $plano, SubscriptionService::FORMA_PIX);
        $this->gateway->chamadas = [];

        try {
            $this->subscriptionService->trocarFormaDePagamento($assinatura, SubscriptionService::FORMA_CARTAO);
            $this->fail('Cartão sem cifra deveria lançar AssinaturaInvalidaException.');
        } catch (AssinaturaInvalidaException) {
            // esperado
        }

        $this->assertSame([], $this->gateway->chamadas);
        $this->assertSame(SubscriptionService::FORMA_PIX, $assinatura->refresh()->forma_pagamento);
    }

    public function test_trocar_forma_para_cartao_cadastra_o_meio_de_pagamento_e_grava_a_forma(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Kappa');
        $plano = $this->criarPlano('Essencial');

        $assinatura = $this->subscriptionService->assinar($empresa, $plano, SubscriptionService::FORMA_BOLETO);
        $this->gateway->chamadas = [];

        $trocada = $this->subscriptionService->trocarFormaDePagamento(
            $assinatura,
            SubscriptionService::FORMA_CARTAO,
            'outro-cartao-cifrado'
        );

        $this->assertSame(['registrarMeioDePagamento'], $this->gateway->chamadas);
        $this->assertSame(SubscriptionService::FORMA_CARTAO, $trocada->forma_pagamento);
    }

    public function test_cancelar_mantem_o_acesso_ate_o_fim_do_periodo_pago(): void
    {
        $empresa = $this->criarTenantComum('Dedetizadora Lambda');
        $plano = $this->criarPlano('Essencial');

        $assinatura = $this->subscriptionService->assinar($empresa, $plano, SubscriptionService::FORMA_BOLETO);
        $fimDoPeriodoPago = $assinatura->proxima_cobranca_em->toDateString();

        $cancelada = $this->subscriptionService->cancelar($assinatura, 'Fechou a empresa');

        $this->assertSame(RespostaDeAssinatura::SITUACAO_CANCELADA, $cancelada->situacao);
        $this->assertNotNull($cancelada->cancelada_em);
        $this->assertSame('Fechou a empresa', $cancelada->motivo_cancelamento);

        // O período já pago continua valendo, e a empresa não é bloqueada nem
        // perde o plano por ter cancelado.
        $this->assertSame($fimDoPeriodoPago, $cancelada->proxima_cobranca_em->toDateString());
        $this->assertSame('ativa', $empresa->refresh()->situacao);
        $this->assertSame($plano->id, $empresa->plan_id);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * A empresa fundadora, marcada como interna pela migration da Task 5.1.
     */
    private function tenantInterno(): Company
    {
        return Company::query()->where('is_internal', true)->firstOrFail();
    }

    private function criarTenantComum(string $nome): Company
    {
        return Company::create(['name' => $nome]);
    }

    private function criarPlano(string $nome): Plan
    {
        return Plan::create([
            'nome' => $nome,
            'slug' => str($nome)->slug()->toString(),
            'valor' => 199.90,
            'periodicidade' => SubscriptionService::PERIODICIDADE_MENSAL,
            'ativo' => true,
        ]);
    }
}

/**
 * Dublê de `GatewayAssinatura` que anota o que foi pedido, na ordem, e nunca
 * toca na rede.
 *
 * A ordem importa: com forma cartão, `registrarMeioDePagamento` precisa vir
 * antes de `criarAssinatura`, senão o provedor fica sem com o que cobrar a
 * recorrência.
 *
 * Fica neste arquivo, e não em `tests/Support/`, porque a Task 7.11 monta o
 * dublê dela com as necessidades dela (webhook, cobrança), e um dublê
 * compartilhado cedo demais vira o lugar onde os dois testes brigam.
 */
class GatewayDeTeste implements GatewayAssinatura
{
    /**
     * @var array<int, string>
     */
    public array $chamadas = [];

    public ?string $cartaoRecebido = null;

    public ?\Throwable $erroAoCriar = null;

    public function registrarMeioDePagamento(Company $empresa, string $cartaoCifrado): void
    {
        $this->chamadas[] = 'registrarMeioDePagamento';
        $this->cartaoRecebido = $cartaoCifrado;
    }

    public function criarAssinatura(Company $empresa, Plan $plano, string $formaPagamento): RespostaDeAssinatura
    {
        if ($this->erroAoCriar !== null) {
            throw $this->erroAoCriar;
        }

        $this->chamadas[] = 'criarAssinatura';

        return new RespostaDeAssinatura('sub_de_teste', RespostaDeAssinatura::SITUACAO_ATIVA);
    }

    public function cancelarAssinatura(string $idNoGateway): void
    {
        $this->chamadas[] = 'cancelarAssinatura';
    }

    public function trocarPlano(string $idNoGateway, Plan $novoPlano): RespostaDeAssinatura
    {
        $this->chamadas[] = 'trocarPlano';

        return new RespostaDeAssinatura($idNoGateway, RespostaDeAssinatura::SITUACAO_ATIVA);
    }

    public function gerarCobranca(Invoice $fatura): RespostaDeCobranca
    {
        $this->chamadas[] = 'gerarCobranca';

        return new RespostaDeCobranca('cob_de_teste', RespostaDeCobranca::SITUACAO_ABERTA);
    }

    public function consultarCobranca(string $idNoGateway): RespostaDeCobranca
    {
        $this->chamadas[] = 'consultarCobranca';

        return new RespostaDeCobranca($idNoGateway, RespostaDeCobranca::SITUACAO_ABERTA);
    }

    public function validarWebhook(Request $requisicao): bool
    {
        return true;
    }

    public function interpretarWebhook(array $payload): EventoDeGateway
    {
        return new EventoDeGateway('evt_de_teste', EventoDeGateway::TIPO_DESCONHECIDO);
    }
}
