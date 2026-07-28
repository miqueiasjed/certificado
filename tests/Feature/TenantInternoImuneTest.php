<?php

namespace Tests\Feature;

use App\Contracts\GatewayAssinatura;
use App\Exceptions\TenantInternoException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\InadimplenciaService;
use App\Services\InvoiceService;
use App\Services\SubscriptionService;
use App\Services\TenantService;
use App\Support\BusinessDate;
use App\Support\Gateway\EventoDeGateway;
use App\Support\Gateway\RespostaDeAssinatura;
use App\Support\Gateway\RespostaDeCobranca;
use App\Support\TenantAtual;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * O portão de subida do Plano 7 (Task 7.11).
 *
 * **O tenant interno (`companies.is_internal = true`) não assina, não é
 * cobrado, não é bloqueado e não entra em número de receita.** Ele é a empresa
 * que opera o sistema hoje e gera a receita atual do negócio: um erro na régua
 * de cobrança derruba a operação do cliente que paga a conta, e é o único jeito
 * de este plano causar prejuízo maior do que a funcionalidade que ele entrega.
 *
 * Cada Service que pode cobrar ou bloquear já tem o teste de tenant interno
 * dele (`SubscriptionServiceTest`, `InvoiceServiceTest`, `InadimplenciaTest`,
 * `TenantServiceTest`), e a sobreposição com este arquivo é deliberada. O valor
 * daqui é ser o único lugar que alguém precisa abrir para confiar na regra
 * inteira: todos os pontos de entrada, de ponta a ponta, em sete casos.
 *
 * O critério de aceitação da task é o experimento inverso: remover a
 * verificação de `is_internal` de qualquer um desses Services precisa derrubar
 * este arquivo.
 *
 * ## Como o cenário é montado
 *
 * O tenant interno é a empresa fundadora (id 1), marcada pela migration da
 * Task 5.1 (`2026_07_26_190001_add_platform_fields_to_companies_and_users`).
 * Nenhum teste daqui chama `assinar()` para criar a assinatura dele: ela é
 * gravada direto no banco, como entraria por importação ou correção manual,
 * justamente porque `assinar()` já recusaria e o que se quer provar é que as
 * camadas seguintes recusam sozinhas.
 *
 * O provedor nunca é chamado: `GatewayDeImunidadeDeTeste` fica no lugar de
 * `GatewayAssinatura` no container e anota o que foi pedido. Afirmar que o
 * dublê não foi chamado é mais forte do que afirmar que a exceção subiu: uma
 * assinatura criada no PagBank e recusada aqui depois continuaria cobrando o
 * tenant todo mês.
 */
class TenantInternoImuneTest extends TestCase
{
    use RefreshDatabase;

    private const ROTA_WEBHOOK = '/webhooks/gateway/pagbank';

    private GatewayDeImunidadeDeTeste $gateway;

    private SubscriptionService $subscriptionService;

    private InvoiceService $invoiceService;

    private InadimplenciaService $inadimplenciaService;

    private TenantService $tenantService;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->gateway = new GatewayDeImunidadeDeTeste;
        $this->app->instance(GatewayAssinatura::class, $this->gateway);

        $this->subscriptionService = $this->app->make(SubscriptionService::class);
        $this->invoiceService = $this->app->make(InvoiceService::class);
        $this->inadimplenciaService = $this->app->make(InadimplenciaService::class);
        $this->tenantService = $this->app->make(TenantService::class);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // 1. Contratação
    // -----------------------------------------------------------------

    /**
     * A primeira porta: contratar um plano para o tenant interno o colocaria
     * dentro da régua de inadimplência, cujo fim é bloqueio de acesso.
     */
    public function test_assinar_no_tenant_interno_lanca_excecao_sem_gravar_e_sem_chamar_o_provedor(): void
    {
        $interno = $this->tenantInterno();
        $plano = $this->criarPlano('Essencial');

        try {
            $this->subscriptionService->assinar($interno, $plano, SubscriptionService::FORMA_PIX);
            $this->fail('Assinar o tenant interno deveria lançar TenantInternoException.');
        } catch (TenantInternoException) {
            // esperado
        }

        $this->assertSame(
            [],
            $this->gateway->chamadas,
            'o provedor foi chamado para o tenant interno: sobraria uma assinatura cobrando lá'
        );
        $this->assertDatabaseCount('subscriptions', 0);
        $this->assertNull($interno->refresh()->plan_id);
    }

    // -----------------------------------------------------------------
    // 2. Geração de fatura
    // -----------------------------------------------------------------

    /**
     * Defesa em profundidade: mesmo com plano vinculado e uma assinatura já no
     * banco (contornando `assinar()`, que recusaria), a rotina diária não emite
     * fatura nem pede cobrança ao provedor.
     */
    public function test_gerar_faturas_nao_fatura_o_tenant_interno_com_assinatura_no_banco(): void
    {
        $interno = $this->tenantInterno();
        $plano = $this->criarPlano('Interno');

        $interno->update(['plan_id' => $plano->id]);
        $this->assinaturaFicticia($interno, $plano, diasAteACobranca: 0);

        $this->artisan('plataforma:gerar-faturas')->assertSuccessful();

        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(
            0,
            $this->gateway->cobrancasEmitidas,
            'o provedor recebeu um pedido de cobrança para o tenant interno'
        );
    }

    // -----------------------------------------------------------------
    // 3. Régua de inadimplência
    // -----------------------------------------------------------------

    /**
     * Noventa dias de atraso não produzem aviso, atraso nem suspensão. A régua
     * nem chega a incluir a assinatura no resumo da passada.
     */
    public function test_regua_de_inadimplencia_nao_toca_no_tenant_interno_com_fatura_vencida_ha_90_dias(): void
    {
        config(['assinatura.dias_de_tolerancia' => 5]);

        $interno = $this->tenantInterno();
        $plano = $this->criarPlano('Interno');
        $assinatura = $this->assinaturaFicticia($interno, $plano, diasAteACobranca: -90);
        $fatura = $this->faturaFicticia($assinatura, $interno, $plano, diasAteOVencimento: -90);

        $resumo = $this->inadimplenciaService->avaliar();

        $this->assertSame([], $resumo, 'a assinatura do tenant interno entrou na passada da régua');

        $interno->refresh();

        $this->assertSame(TenantService::SITUACAO_ATIVA, $interno->situacao);
        $this->assertNull($interno->suspensa_em);
        $this->assertNull($interno->motivo_suspensao);
        $this->assertSame(RespostaDeAssinatura::SITUACAO_ATIVA, $assinatura->refresh()->situacao);
        $this->assertSame(RespostaDeCobranca::SITUACAO_ABERTA, $fatura->refresh()->situacao);
        $this->assertDatabaseMissing('access_logs', ['company_id' => $interno->id]);
    }

    // -----------------------------------------------------------------
    // 4. Suspensão manual
    // -----------------------------------------------------------------

    /**
     * Nem o super admin suspende o tenant interno pelo painel: a recusa está no
     * Service, e não na tela, para valer igual em comando artisan, job e
     * qualquer código futuro que resolva suspender em lote.
     */
    public function test_suspender_o_tenant_interno_lanca_excecao_e_nao_altera_a_empresa(): void
    {
        $interno = $this->tenantInterno();

        $this->expectException(TenantInternoException::class);

        try {
            $this->tenantService->suspender($interno, 'motivo qualquer');
        } finally {
            $interno->refresh();

            $this->assertSame(TenantService::SITUACAO_ATIVA, $interno->situacao);
            $this->assertNull($interno->suspensa_em);
            $this->assertNull($interno->motivo_suspensao);
        }
    }

    // -----------------------------------------------------------------
    // 5. Nenhum caminho automático suspende
    // -----------------------------------------------------------------

    /**
     * As três rotinas automáticas do plano, na sequência, sobre o pior cenário
     * possível: plano vinculado, assinatura no banco, fatura vencida há 90 dias
     * e dois eventos do provedor referenciando esses dados.
     *
     * `situacao` continua `ativa` no fim. É esta a afirmação que o plano inteiro
     * existe para proteger.
     */
    public function test_nenhum_caminho_automatico_deixa_o_tenant_interno_suspenso(): void
    {
        config(['assinatura.dias_de_tolerancia' => 1]);

        $interno = $this->tenantInterno();
        $plano = $this->criarPlano('Interno');

        $interno->update(['plan_id' => $plano->id]);

        $assinatura = $this->assinaturaFicticia($interno, $plano, diasAteACobranca: -90);
        $fatura = $this->faturaFicticia($assinatura, $interno, $plano, diasAteOVencimento: -90);

        $this->artisan('plataforma:gerar-faturas')->assertSuccessful();
        $this->artisan('plataforma:inadimplencia')->assertSuccessful();

        $this->postJson(self::ROTA_WEBHOOK, [
            'evento_id' => 'evt-interno-vencida',
            'tipo' => EventoDeGateway::TIPO_COBRANCA_VENCIDA,
            'recurso' => $fatura->gateway_invoice_id,
            'situacao' => RespostaDeCobranca::SITUACAO_VENCIDA,
        ])->assertOk();

        $this->postJson(self::ROTA_WEBHOOK, [
            'evento_id' => 'evt-interno-cancelada',
            'tipo' => EventoDeGateway::TIPO_ASSINATURA_CANCELADA,
            'recurso' => $assinatura->gateway_subscription_id,
        ])->assertOk();

        $interno->refresh();

        $this->assertSame(TenantService::SITUACAO_ATIVA, $interno->situacao);
        $this->assertNull($interno->suspensa_em);
        $this->assertNull($interno->motivo_suspensao);

        // Nada ao redor mudou de mão: nem a assinatura foi encerrada, nem a
        // fatura andou para a situação que alimenta a régua, nem uma segunda
        // fatura nasceu.
        $this->assertSame(RespostaDeAssinatura::SITUACAO_ATIVA, $assinatura->refresh()->situacao);
        $this->assertSame(RespostaDeCobranca::SITUACAO_ABERTA, $fatura->refresh()->situacao);
        $this->assertSame(1, Invoice::query()->where('company_id', $interno->id)->count());
    }

    // -----------------------------------------------------------------
    // 6. Painel de receita
    // -----------------------------------------------------------------

    /**
     * O tenant interno não é cliente: contá-lo infla a receita recorrente e a
     * inadimplência que o super admin usa para decidir onde prestar atenção.
     *
     * O número é conferido duas vezes: só com ele (zero) e depois com um tenant
     * pagante ao lado (um), o que separa "não conta o interno" de "não conta
     * ninguém".
     */
    public function test_tenant_interno_nao_aparece_nos_numeros_do_painel_de_receita(): void
    {
        $interno = $this->tenantInterno();
        $plano = $this->criarPlano('Interno');
        $assinaturaInterna = $this->assinaturaFicticia($interno, $plano, diasAteACobranca: 0);
        $this->faturaFicticia($assinaturaInterna, $interno, $plano, diasAteOVencimento: 0);

        $this->assertSame(
            ['ativa' => 0, 'em_atraso' => 0, 'suspensa' => 0, 'cancelada' => 0],
            $this->subscriptionService->contadoresPorSituacao()
        );
        $this->assertSame(0.0, $this->subscriptionService->receitaRecorrenteMensal());

        $emAberto = $this->invoiceService->faturasEmAberto();

        $this->assertSame(0, $emAberto['quantidade']);
        $this->assertSame(0.0, $emAberto['valor_total']);
        $this->assertSame([], $emAberto['lista']);

        // Um tenant pagante ao lado, com os mesmos valores: o painel passa a
        // contar um, e não dois.
        $pagante = Company::create(['name' => 'Dedetizadora Pagante']);
        $assinaturaPagante = $this->assinaturaFicticia($pagante, $plano, diasAteACobranca: 0);
        $this->faturaFicticia($assinaturaPagante, $pagante, $plano, diasAteOVencimento: 0);

        $this->assertSame(1, $this->subscriptionService->contadoresPorSituacao()['ativa']);
        $this->assertSame((float) $plano->valor, $this->subscriptionService->receitaRecorrenteMensal());

        $emAbertoComPagante = $this->invoiceService->faturasEmAberto();

        $this->assertSame(1, $emAbertoComPagante['quantidade']);
        $this->assertSame((float) $plano->valor, $emAbertoComPagante['valor_total']);
        $this->assertSame($pagante->id, $emAbertoComPagante['lista'][0]['company_id']);
    }

    // -----------------------------------------------------------------
    // 7. Acesso ao sistema
    // -----------------------------------------------------------------

    /**
     * O outro lado da regra: a imunidade não vale nada se a fatura fictícia
     * vencida no banco tirar o time do cliente de dentro do sistema. Depois de
     * a régua rodar, o usuário do tenant interno entra normalmente, sem passar
     * pela página de conta suspensa.
     */
    public function test_usuario_do_tenant_interno_acessa_o_sistema_com_fatura_vencida_no_banco(): void
    {
        config(['assinatura.dias_de_tolerancia' => 1]);

        $interno = $this->tenantInterno();
        $plano = $this->criarPlano('Interno');
        $assinatura = $this->assinaturaFicticia($interno, $plano, diasAteACobranca: -90);
        $this->faturaFicticia($assinatura, $interno, $plano, diasAteOVencimento: -90);

        $usuario = $this->usuarioDo($interno);

        $this->artisan('plataforma:inadimplencia')->assertSuccessful();

        $this->actingAs($usuario)->get('/dashboard')->assertOk();
        $this->actingAs($usuario)->get(route('assinatura.faturas.index'))->assertOk();
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

    private function usuarioDo(Company $empresa): User
    {
        $usuario = TenantAtual::comTenant(
            (int) $empresa->getKey(),
            fn (): User => User::factory()->create(['is_active' => true])
        );

        $usuario->assignRole('administrador');

        return $usuario->fresh();
    }

    private function criarPlano(string $nome): Plan
    {
        return Plan::create([
            'nome' => $nome,
            'slug' => str($nome)->slug()->toString().'-'.uniqid(),
            'valor' => 199.90,
            'periodicidade' => SubscriptionService::PERIODICIDADE_MENSAL,
            'ativo' => true,
        ]);
    }

    /**
     * Assinatura gravada direto no banco, como entraria por importação ou
     * correção manual. Ver o cabeçalho da classe.
     *
     * @param  int  $diasAteACobranca  Dias a partir de hoje, no fuso do negócio. Negativo é passado.
     */
    private function assinaturaFicticia(Company $empresa, Plan $plano, int $diasAteACobranca): Subscription
    {
        return Subscription::create([
            'company_id' => $empresa->getKey(),
            'plan_id' => $plano->id,
            'situacao' => RespostaDeAssinatura::SITUACAO_ATIVA,
            'forma_pagamento' => SubscriptionService::FORMA_PIX,
            'gateway_subscription_id' => 'SUBS_'.$empresa->getKey(),
            'inicio_em' => BusinessDate::hoje()->subMonthNoOverflow()->toDateString(),
            'proxima_cobranca_em' => BusinessDate::hoje()->addDays($diasAteACobranca)->toDateString(),
        ]);
    }

    /**
     * @param  int  $diasAteOVencimento  Dias a partir de hoje, no fuso do negócio. Negativo é passado.
     */
    private function faturaFicticia(
        Subscription $assinatura,
        Company $empresa,
        Plan $plano,
        int $diasAteOVencimento
    ): Invoice {
        $vencimento = BusinessDate::hoje()->addDays($diasAteOVencimento);

        return Invoice::create([
            'company_id' => $empresa->getKey(),
            'subscription_id' => $assinatura->id,
            'referencia' => $vencimento->format('Y-m'),
            'valor' => $plano->valor,
            'situacao' => RespostaDeCobranca::SITUACAO_ABERTA,
            'vencimento' => $vencimento->toDateString(),
            'gateway_invoice_id' => 'ORDE_'.$empresa->getKey(),
        ]);
    }
}

/**
 * Dublê de `GatewayAssinatura` sem rede, montado para o que esta suíte precisa
 * provar: que o provedor não é chamado para o tenant interno em nenhum caminho.
 *
 * `chamadas` guarda a ordem dos métodos usados e `cobrancasEmitidas` conta os
 * pedidos de cobrança, porque a diferença entre "a exceção subiu" e "nada saiu
 * daqui" é o que separa uma recusa correta de uma assinatura órfã cobrando o
 * cliente todo mês no PagBank.
 *
 * Fica neste arquivo, e não em `tests/Support/`, pelo mesmo motivo dos dublês
 * das Tasks 7.4, 7.5 e 7.6: um dublê compartilhado vira o lugar onde os testes
 * brigam por uma necessidade que só um deles tem.
 */
class GatewayDeImunidadeDeTeste implements GatewayAssinatura
{
    /**
     * @var array<int, string>
     */
    public array $chamadas = [];

    public int $cobrancasEmitidas = 0;

    public function registrarMeioDePagamento(Company $empresa, string $cartaoCifrado): void
    {
        $this->chamadas[] = 'registrarMeioDePagamento';
    }

    public function criarAssinatura(Company $empresa, Plan $plano, string $formaPagamento): RespostaDeAssinatura
    {
        $this->chamadas[] = 'criarAssinatura';

        return new RespostaDeAssinatura('SUBS_de_teste', RespostaDeAssinatura::SITUACAO_ATIVA);
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
        $this->cobrancasEmitidas++;

        return new RespostaDeCobranca('ORDE_'.$fatura->id, RespostaDeCobranca::SITUACAO_ABERTA);
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

    /**
     * Lê o payload no formato do próprio teste, e não no do PagBank: a tradução
     * do formato do provedor é assunto de `PagBankGatewayTest`, e repeti-la aqui
     * misturaria dois arquivos em um.
     */
    public function interpretarWebhook(array $payload): EventoDeGateway
    {
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
