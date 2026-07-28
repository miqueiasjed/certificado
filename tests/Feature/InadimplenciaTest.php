<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTenantIsActive;
use App\Models\Client;
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
use App\Support\Gateway\RespostaDeAssinatura;
use App\Support\Gateway\RespostaDeCobranca;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Factories\ClientFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Régua de inadimplência com bloqueio reversível (Plano 7, Tasks 7.7 e 7.11).
 *
 * É o que decide se alguém é bloqueado sem dever ou continua bloqueado depois de
 * pagar. A imunidade do tenant interno em todos os caminhos que podem cobrar ou
 * bloquear é `TenantInternoImuneTest`; os dois casos daqui que tocam o assunto
 * cobrem a régua especificamente.
 *
 * As datas são sempre relativas a `BusinessDate::hoje()`, nunca a `now()` em
 * UTC: a tolerância é contada por dia no fuso do negócio, e é justamente essa
 * diferença que separa "vence hoje" de "vencido" às 21h de Brasília. O caso que
 * prova isso (`test_vencimento_de_hoje_nao_suspende_as_21h_de_brasilia`) fixa o
 * relógio no instante em que os dois fusos estão em dias diferentes, em vez de
 * confiar na hora em que a suíte roda.
 */
class InadimplenciaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Instante em que UTC e o fuso do negócio estão em dias diferentes: 00:30 do
     * dia 28 em UTC é 21:30 do dia 27 em São Paulo.
     *
     * Declarado em UTC porque é o fuso em que o backend roda, e porque
     * `Carbon::setTestNow()` empresta o fuso da instância que recebe: fixar em
     * Brasília mudaria o fuso de `now()` na aplicação inteira e o teste deixaria
     * de reproduzir a produção.
     */
    private const MOMENTO_VIRADA_EM_UTC = '2026-07-28 00:30:00';

    private InadimplenciaService $regua;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->regua = $this->app->make(InadimplenciaService::class);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        // O relógio fixado por um único teste não pode vazar para os demais: a
        // suíte inteira roda no mesmo processo.
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Tolerância
    // -----------------------------------------------------------------

    public function test_fatura_vencida_dentro_da_tolerancia_marca_em_atraso_sem_suspender(): void
    {
        config(['assinatura.dias_de_tolerancia' => 5]);

        [$empresa, , $assinatura, $fatura] = $this->cenario('Dedetizadora Alfa', diasDeAtraso: 2);

        $this->artisan('plataforma:inadimplencia')->assertSuccessful();

        $this->assertSame(RespostaDeCobranca::SITUACAO_VENCIDA, $fatura->refresh()->situacao);
        $this->assertSame(RespostaDeAssinatura::SITUACAO_EM_ATRASO, $assinatura->refresh()->situacao);
        $this->assertSame(TenantService::SITUACAO_ATIVA, $empresa->refresh()->situacao);
        $this->assertNull($empresa->suspensa_em);
    }

    /**
     * Fatura que vence hoje não está vencida. Comparar com o instante em UTC
     * bloquearia o tenant um dia antes do que o contrato dele diz.
     */
    public function test_fatura_que_vence_hoje_nao_marca_atraso(): void
    {
        [$empresa, , $assinatura, $fatura] = $this->cenario('Dedetizadora Beta', diasDeAtraso: 0);

        $this->artisan('plataforma:inadimplencia')->assertSuccessful();

        $this->assertSame(RespostaDeCobranca::SITUACAO_ABERTA, $fatura->refresh()->situacao);
        $this->assertSame(RespostaDeAssinatura::SITUACAO_ATIVA, $assinatura->refresh()->situacao);
        $this->assertSame(TenantService::SITUACAO_ATIVA, $empresa->refresh()->situacao);
    }

    /**
     * O mesmo caso do teste acima, mas com o relógio parado no instante que
     * separa os dois fusos: 00:30 UTC do dia 28 é 21:30 do dia 27 em São Paulo.
     *
     * Em UTC o dia já virou, e uma régua que comparasse `now()` cru veria uma
     * fatura vencida ontem, marcaria a assinatura em atraso e, com a tolerância
     * em 1 dia, suspenderia a empresa. No fuso do negócio ainda é o dia do
     * vencimento, e quem vence hoje não deve nada.
     *
     * A tolerância é fixada em 1 (o piso de `diasDeTolerancia()`) de propósito:
     * é o valor que transforma o erro de um dia em bloqueio imediato, então é
     * ele que torna a falha visível se a comparação voltar a ser por instante.
     */
    public function test_vencimento_de_hoje_nao_suspende_as_21h_de_brasilia(): void
    {
        config(['assinatura.dias_de_tolerancia' => 1]);

        Carbon::setTestNow(Carbon::parse(self::MOMENTO_VIRADA_EM_UTC, 'UTC'));

        // O cenário só vale se os dois fusos estiverem mesmo em dias diferentes.
        $this->assertSame('2026-07-28', CarbonImmutable::now('UTC')->toDateString());
        $this->assertSame('2026-07-27', BusinessDate::hoje()->toDateString());

        [$empresa, , $assinatura, $fatura] = $this->cenario('Dedetizadora Csi', diasDeAtraso: 0);

        $this->assertSame('2026-07-27', $fatura->vencimento->toDateString());

        $this->regua->avaliar();

        $this->assertSame(RespostaDeCobranca::SITUACAO_ABERTA, $fatura->refresh()->situacao);
        $this->assertSame(RespostaDeAssinatura::SITUACAO_ATIVA, $assinatura->refresh()->situacao);
        $this->assertSame(TenantService::SITUACAO_ATIVA, $empresa->refresh()->situacao);
        $this->assertNull($empresa->suspensa_em);
        $this->assertDatabaseMissing('access_logs', [
            'company_id' => $empresa->id,
            'evento' => InadimplenciaService::EVENTO_SUSPENSAO,
        ]);
    }

    public function test_fatura_vencida_alem_da_tolerancia_suspende_a_empresa(): void
    {
        config(['assinatura.dias_de_tolerancia' => 5]);

        [$empresa, , $assinatura] = $this->cenario('Dedetizadora Gama', diasDeAtraso: 5);

        $this->artisan('plataforma:inadimplencia')->assertSuccessful();

        $empresa->refresh();

        $this->assertSame(TenantService::SITUACAO_SUSPENSA, $empresa->situacao);
        $this->assertSame(InvoiceService::MOTIVO_SUSPENSAO_INADIMPLENCIA, $empresa->motivo_suspensao);
        $this->assertNotNull($empresa->suspensa_em);
        $this->assertSame(RespostaDeAssinatura::SITUACAO_SUSPENSA, $assinatura->refresh()->situacao);
    }

    /**
     * Rodar a rotina duas vezes no mesmo dia não pode reescrever a data do
     * bloqueio: `suspensa_em` é a prova de quando o acesso caiu.
     */
    public function test_segunda_passada_no_mesmo_dia_nao_reescreve_a_suspensao(): void
    {
        config(['assinatura.dias_de_tolerancia' => 5]);

        [$empresa] = $this->cenario('Dedetizadora Delta', diasDeAtraso: 9);

        $this->artisan('plataforma:inadimplencia')->assertSuccessful();

        $suspensaEm = $empresa->refresh()->suspensa_em;

        $this->artisan('plataforma:inadimplencia')->assertSuccessful();

        $this->assertEquals($suspensaEm, $empresa->refresh()->suspensa_em);
    }

    // -----------------------------------------------------------------
    // Avisos
    // -----------------------------------------------------------------

    public function test_aviso_antes_do_vencimento_e_registrado_sem_alterar_nenhuma_situacao(): void
    {
        config(['assinatura.dias_de_aviso' => [3, 1]]);

        [$empresa, , $assinatura, $fatura] = $this->cenario('Dedetizadora Épsilon', diasDeAtraso: -3);

        $this->artisan('plataforma:inadimplencia')->assertSuccessful();

        $this->assertSame(RespostaDeCobranca::SITUACAO_ABERTA, $fatura->refresh()->situacao);
        $this->assertSame(RespostaDeAssinatura::SITUACAO_ATIVA, $assinatura->refresh()->situacao);
        $this->assertSame(TenantService::SITUACAO_ATIVA, $empresa->refresh()->situacao);

        $this->assertDatabaseHas('access_logs', [
            'company_id' => $empresa->id,
            'evento' => InadimplenciaService::EVENTO_AVISO_VENCIMENTO,
        ]);
    }

    public function test_cobranca_dentro_da_tolerancia_registra_o_aviso_no_dia_do_marco(): void
    {
        config(['assinatura.dias_de_tolerancia' => 5, 'assinatura.dias_de_cobranca' => [1, 3]]);

        [$comAviso] = $this->cenario('Dedetizadora Zeta', diasDeAtraso: 3);
        [$semAviso] = $this->cenario('Dedetizadora Eta', diasDeAtraso: 2);

        $this->artisan('plataforma:inadimplencia')->assertSuccessful();

        $this->assertDatabaseHas('access_logs', [
            'company_id' => $comAviso->id,
            'evento' => InadimplenciaService::EVENTO_AVISO_COBRANCA,
        ]);
        $this->assertDatabaseMissing('access_logs', [
            'company_id' => $semAviso->id,
            'evento' => InadimplenciaService::EVENTO_AVISO_COBRANCA,
        ]);
    }

    // -----------------------------------------------------------------
    // Tenant interno: a regra que não pode falhar
    // -----------------------------------------------------------------

    /**
     * O tenant interno é o cliente que sustenta o negócio hoje. Uma fatura
     * fictícia vencida há muito tempo não pode avisá-lo, colocá-lo em atraso nem
     * suspendê-lo por caminho nenhum.
     */
    public function test_tenant_interno_com_fatura_vencida_nao_e_tocado_por_nenhum_caminho(): void
    {
        config(['assinatura.dias_de_tolerancia' => 5]);

        $interno = Company::query()->where('is_internal', true)->firstOrFail();
        $plano = $this->criarPlano('Interno');
        $assinatura = $this->criarAssinatura($interno, $plano, -30);
        $fatura = $this->criarFatura($assinatura, $interno, $plano, -30);

        $this->artisan('plataforma:inadimplencia')->assertSuccessful();

        $interno->refresh();

        $this->assertSame(TenantService::SITUACAO_ATIVA, $interno->situacao);
        $this->assertNull($interno->suspensa_em);
        $this->assertNull($interno->motivo_suspensao);
        $this->assertSame(RespostaDeAssinatura::SITUACAO_ATIVA, $assinatura->refresh()->situacao);
        $this->assertSame(RespostaDeCobranca::SITUACAO_ABERTA, $fatura->refresh()->situacao);
        $this->assertDatabaseMissing('access_logs', ['company_id' => $interno->id]);
    }

    /**
     * Chamada direta, sem passar pela consulta que já filtra o tenant interno:
     * a verificação de cada método público precisa segurar sozinha.
     */
    public function test_suspender_por_inadimplencia_recusa_o_tenant_interno_chamado_direto(): void
    {
        $interno = Company::query()->where('is_internal', true)->firstOrFail();
        $plano = $this->criarPlano('Interno Direto');
        $assinatura = $this->criarAssinatura($interno, $plano, -30);

        $this->regua->suspenderPorInadimplencia($assinatura);

        $this->assertSame(TenantService::SITUACAO_ATIVA, $interno->refresh()->situacao);
        $this->assertSame(RespostaDeAssinatura::SITUACAO_ATIVA, $assinatura->refresh()->situacao);
    }

    // -----------------------------------------------------------------
    // Bloqueio de acesso
    // -----------------------------------------------------------------

    public function test_empresa_suspensa_e_redirecionada_para_a_pagina_de_conta_suspensa(): void
    {
        config(['assinatura.dias_de_tolerancia' => 5]);

        [, $usuario] = $this->cenario('Dedetizadora Teta', diasDeAtraso: 7);

        $this->artisan('plataforma:inadimplencia')->assertSuccessful();

        $this->actingAs($usuario)
            ->get('/dashboard')
            ->assertRedirect(route(EnsureTenantIsActive::ROTA_CONTA_SUSPENSA));
    }

    public function test_empresa_suspensa_continua_acessando_logout_e_a_pagina_de_conta_suspensa(): void
    {
        config(['assinatura.dias_de_tolerancia' => 5]);

        [, $usuario] = $this->cenario('Dedetizadora Iota', diasDeAtraso: 7);

        $this->artisan('plataforma:inadimplencia')->assertSuccessful();

        $this->actingAs($usuario)
            ->get(route(EnsureTenantIsActive::ROTA_CONTA_SUSPENSA))
            ->assertOk();

        $this->actingAs($usuario)
            ->post(route('logout'))
            ->assertRedirect();
    }

    /**
     * A tela de faturas do tenant (Task 7.9) é o caminho do pagamento. Bloqueá-la
     * para quem está suspenso é o erro mais caro que uma régua de cobrança pode
     * cometer: o cliente que quer pagar e não chega ao boleto vira cancelamento
     * em vez de recebimento.
     *
     * O teste bate na rota de verdade, e não só na lista de exceções: com a
     * empresa suspensa pela própria régua, `GET /assinatura` responde 200, e não
     * redirecionamento para a página de conta suspensa.
     */
    public function test_empresa_suspensa_continua_acessando_a_tela_de_faturas(): void
    {
        config(['assinatura.dias_de_tolerancia' => 5]);

        [$empresa, $usuario] = $this->cenario('Dedetizadora Ômicron', diasDeAtraso: 7);

        $this->artisan('plataforma:inadimplencia')->assertSuccessful();

        $this->assertSame(TenantService::SITUACAO_SUSPENSA, $empresa->refresh()->situacao);

        $this->actingAs($usuario)
            ->get(route('assinatura.faturas.index'))
            ->assertOk();
    }

    /**
     * A exceção que sustenta o teste acima, conferida por nome: a rota da tela de
     * faturas precisa continuar casando com o padrão liberado. Renomeá-la sem
     * ajustar a lista deixaria o tenant suspenso sem acesso à própria fatura.
     */
    public function test_a_excecao_da_tela_de_faturas_do_tenant_esta_declarada(): void
    {
        $this->assertContains('assinatura.faturas*', EnsureTenantIsActive::ROTAS_LIBERADAS);
        $this->assertContains('logout', EnsureTenantIsActive::ROTAS_LIBERADAS);
        $this->assertContains(
            EnsureTenantIsActive::ROTA_CONTA_SUSPENSA,
            EnsureTenantIsActive::ROTAS_LIBERADAS
        );

        $this->assertTrue(
            Str::is('assinatura.faturas*', 'assinatura.faturas.index'),
            'a rota da tela de faturas deixou de casar com o padrão liberado'
        );
    }

    public function test_requisicao_json_de_empresa_suspensa_recebe_403_com_o_motivo(): void
    {
        config(['assinatura.dias_de_tolerancia' => 5]);

        [, $usuario] = $this->cenario('Dedetizadora Capa', diasDeAtraso: 7);

        $this->artisan('plataforma:inadimplencia')->assertSuccessful();

        $resposta = $this->actingAs($usuario)->getJson('/api/clients/1/addresses');

        $resposta->assertStatus(403);
        $resposta->assertJson(['situacao' => TenantService::SITUACAO_SUSPENSA]);
    }

    // -----------------------------------------------------------------
    // Reversão pelo pagamento
    // -----------------------------------------------------------------

    public function test_pagar_reativa_e_o_acesso_volta_na_requisicao_seguinte(): void
    {
        config(['assinatura.dias_de_tolerancia' => 5]);

        [$empresa, $usuario, $assinatura, $fatura] = $this->cenario('Dedetizadora Lambda', diasDeAtraso: 7);

        $this->artisan('plataforma:inadimplencia')->assertSuccessful();

        $this->actingAs($usuario)
            ->get('/dashboard')
            ->assertRedirect(route(EnsureTenantIsActive::ROTA_CONTA_SUSPENSA));

        $this->app->make(InvoiceService::class)
            ->marcarComoPaga($fatura->refresh(), BusinessDate::hoje()->toDateString());

        $empresa->refresh();

        $this->assertSame(TenantService::SITUACAO_ATIVA, $empresa->situacao);
        $this->assertNull($empresa->suspensa_em);
        $this->assertNull($empresa->motivo_suspensao);
        $this->assertSame(RespostaDeAssinatura::SITUACAO_ATIVA, $assinatura->refresh()->situacao);

        $this->actingAs($usuario)->get('/dashboard')->assertOk();
    }

    /**
     * A empresa que o super admin suspendeu por outro motivo não volta pelo
     * pagamento de uma fatura antiga: seria devolver acesso que ninguém
     * autorizou.
     */
    public function test_pagar_nao_reativa_empresa_suspensa_por_outro_motivo(): void
    {
        [$empresa, , $assinatura, $fatura] = $this->cenario('Dedetizadora Mi', diasDeAtraso: 7);

        $this->app->make(TenantService::class)->suspender($empresa, 'fraude');

        $this->app->make(InvoiceService::class)
            ->marcarComoPaga($fatura->refresh(), BusinessDate::hoje()->toDateString());

        $empresa->refresh();

        $this->assertSame(TenantService::SITUACAO_SUSPENSA, $empresa->situacao);
        $this->assertSame('fraude', $empresa->motivo_suspensao);
        $this->assertSame(RespostaDeAssinatura::SITUACAO_ATIVA, $assinatura->refresh()->situacao);
    }

    /**
     * Bloqueio é acesso negado, não exclusão nem ocultação. O dado de domínio do
     * tenant precisa continuar exatamente como estava, antes e depois da
     * suspensão, e voltar inteiro na reativação.
     */
    public function test_bloqueio_nao_altera_nem_apaga_dado_do_tenant(): void
    {
        config(['assinatura.dias_de_tolerancia' => 5]);

        [$empresa, , , $fatura] = $this->cenario('Dedetizadora Ni', diasDeAtraso: 7);

        $clientes = TenantAtual::comTenant($empresa->id, fn () => ClientFactory::new()->count(3)->create());
        $nomes = $clientes->pluck('name')->sort()->values()->all();

        $this->artisan('plataforma:inadimplencia')->assertSuccessful();

        $this->assertSame(
            $nomes,
            TenantAtual::comTenant(
                $empresa->id,
                fn () => Client::query()->pluck('name')->sort()->values()->all()
            )
        );

        // A fatura continua sendo a mesma dívida: só a situação mudou.
        $fatura->refresh();
        $this->assertSame(RespostaDeCobranca::SITUACAO_VENCIDA, $fatura->situacao);
        $this->assertNull($fatura->pago_em);

        $this->app->make(InvoiceService::class)
            ->marcarComoPaga($fatura, BusinessDate::hoje()->toDateString());

        $this->assertSame(
            $nomes,
            TenantAtual::comTenant(
                $empresa->id,
                fn () => Client::query()->pluck('name')->sort()->values()->all()
            )
        );
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Tenant comum com plano, usuário administrador, assinatura ativa e uma
     * fatura em aberto vencendo há `$diasDeAtraso` dias (negativo = ainda vai
     * vencer).
     *
     * @return array{0: Company, 1: User, 2: Subscription, 3: Invoice}
     */
    private function cenario(string $nome, int $diasDeAtraso): array
    {
        $plano = $this->criarPlano($nome);

        $email = 'admin-'.uniqid().'@exemplo.test';

        $empresa = $this->app->make(TenantService::class)->criar([
            'name' => $nome,
            'plan_id' => $plano->id,
            'administrador_nome' => 'Administrador de '.$nome,
            'administrador_email' => $email,
        ]);

        $usuario = User::query()->where('email', $email)->firstOrFail();
        $assinatura = $this->criarAssinatura($empresa, $plano, -$diasDeAtraso);
        $fatura = $this->criarFatura($assinatura, $empresa, $plano, -$diasDeAtraso);

        return [$empresa->fresh(), $usuario->fresh(), $assinatura, $fatura];
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
     * @param  int  $diasAteACobranca  Dias a partir de hoje, no fuso do negócio. Negativo é passado.
     */
    private function criarAssinatura(Company $empresa, Plan $plano, int $diasAteACobranca): Subscription
    {
        return Subscription::create([
            'company_id' => $empresa->id,
            'plan_id' => $plano->id,
            'situacao' => RespostaDeAssinatura::SITUACAO_ATIVA,
            'forma_pagamento' => SubscriptionService::FORMA_PIX,
            'gateway_subscription_id' => 'sub_'.$empresa->id,
            'inicio_em' => BusinessDate::hoje()->subMonthNoOverflow()->toDateString(),
            'proxima_cobranca_em' => BusinessDate::hoje()->addDays($diasAteACobranca)->toDateString(),
        ]);
    }

    /**
     * @param  int  $diasAteOVencimento  Dias a partir de hoje, no fuso do negócio. Negativo é passado.
     */
    private function criarFatura(Subscription $assinatura, Company $empresa, Plan $plano, int $diasAteOVencimento): Invoice
    {
        $vencimento = BusinessDate::hoje()->addDays($diasAteOVencimento);

        return Invoice::create([
            'company_id' => $empresa->id,
            'subscription_id' => $assinatura->id,
            'referencia' => $vencimento->format('Y-m'),
            'valor' => $plano->valor,
            'situacao' => RespostaDeCobranca::SITUACAO_ABERTA,
            'vencimento' => $vencimento->toDateString(),
            'qr_code_pix' => 'pix-copia-e-cola-de-teste',
        ]);
    }
}
