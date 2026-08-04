<?php

namespace Tests\Feature;

use App\Models\Charge;
use App\Models\Client;
use App\Models\Company;
use App\Models\GatewayEvent;
use App\Models\PaymentGatewayConfig;
use App\Models\Receivable;
use App\Models\ReceivableInstallment;
use App\Models\User;
use App\Services\Payments\GatewayPagBank;
use App\Support\TenantAtual;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionProperty;
use Tests\TestCase;

/**
 * Task 19.4 do Plano 19: webhook de cobrança do tenant ao cliente final —
 * `CobrancaWebhookController`, `ProcessadorDeEventoDeCobranca` e a resolução
 * do tenant por `PaymentGatewayConfig::paraToken()`.
 *
 * É a task mais sensível do plano inteiro: idempotência errada aqui é
 * dinheiro baixado duas vezes, e isolamento furado é um webhook de uma
 * empresa dando baixa em título de outra. O teste mais importante do arquivo
 * é `test_webhook_de_um_tenant_nao_alcanca_cobranca_de_outro`, com dois
 * tenants reais e um `gateway_charge_id` que existe nos dois.
 *
 * O gateway nunca é chamado de verdade: os testes montam o payload que
 * `GatewayPagBank::interpretarWebhook()` (Task 19.4) sabe traduzir e assinam
 * com o mesmo algoritmo de `GatewayPagBank::validarWebhook()`
 * (`sha256(token-do-tenant + "-" + corpo cru)`), a mesma técnica de
 * `GatewayWebhookTest` (Plano 7) para o webhook irmão.
 */
class CobrancaWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Endereço, autenticidade e idempotência
    // -----------------------------------------------------------------

    public function test_token_valido_grava_o_evento_e_responde_200(): void
    {
        $tenant = $this->cenario('Dedetizadora Alfa');
        $cobranca = $this->cobranca($tenant, 'ORDE_ALFA_1');

        $payload = $this->payloadPago('ORDE_ALFA_1', 'CHAR_1', 30000, '2026-08-10');

        $this->postJson($this->rota($tenant), $payload, $this->cabecalho($tenant, $payload))
            ->assertOk()
            ->assertJson(['message' => 'Evento recebido.']);

        $this->assertDatabaseCount('gateway_events', 1);

        $evento = GatewayEvent::query()->firstOrFail();
        $this->assertSame($tenant['empresa']->id, $evento->company_id);
        $this->assertSame(GatewayPagBank::NOME, $evento->gateway);
        $this->assertNotNull($evento->processado_em);
        $this->assertNull($evento->erro);
        $this->assertSame('ORDE_ALFA_1', $evento->payload['id']);
    }

    public function test_token_invalido_devolve_404_sem_gravar(): void
    {
        $this->cenario('Dedetizadora Beta');

        $payload = $this->payloadPago('ORDE_QUALQUER', 'CHAR_1', 30000, '2026-08-10');

        $this->postJson('/webhooks/cobranca/token-que-nao-existe', $payload)
            ->assertNotFound();

        $this->assertDatabaseCount('gateway_events', 0);
    }

    public function test_assinatura_invalida_e_recusada_sem_processar(): void
    {
        $tenant = $this->cenario('Dedetizadora Gama');
        $this->cobranca($tenant, 'ORDE_GAMA_1');

        $payload = $this->payloadPago('ORDE_GAMA_1', 'CHAR_1', 30000, '2026-08-10');

        $this->postJson($this->rota($tenant), $payload, [
            'x-authenticity-token' => 'assinatura-forjada',
        ])->assertStatus(401);

        $this->assertDatabaseCount('gateway_events', 0);
        $this->assertSame('emitida', Charge::query()->where('gateway_charge_id', 'ORDE_GAMA_1')->firstOrFail()->situacao);
    }

    public function test_mesmo_evento_enviado_duas_vezes_baixa_uma_vez(): void
    {
        $tenant = $this->cenario('Dedetizadora Delta');
        $this->cobranca($tenant, 'ORDE_DELTA_1');

        $payload = $this->payloadPago('ORDE_DELTA_1', 'CHAR_1', 30000, '2026-08-10');
        $cabecalho = $this->cabecalho($tenant, $payload);

        $this->postJson($this->rota($tenant), $payload, $cabecalho)->assertOk();
        $this->postJson($this->rota($tenant), $payload, $cabecalho)->assertOk();

        $this->assertDatabaseCount('gateway_events', 1);

        $parcela = TenantAtual::comTenant(
            $tenant['empresa']->id,
            fn () => ReceivableInstallment::query()->firstOrFail()
        );

        // O valor precisa provar que a baixa aconteceu uma vez só: dois
        // eventos gravados e a parcela baixada em dobro seria um bug que a
        // simples contagem de linhas de gateway_events não pegaria.
        $this->assertSame('300.00', $parcela->valor_pago);
        $this->assertSame('paga', $parcela->situacao);
    }

    /**
     * Task 19.8: prova que a idempotência sobrevive quando duas entregas do
     * mesmo evento colidem de verdade no banco, e não só em sequência (o que
     * o teste acima, `test_mesmo_evento_enviado_duas_vezes_baixa_uma_vez`, já
     * cobre com duas chamadas HTTP uma depois da outra no mesmo processo).
     *
     * PHPUnit roda num processo só: não há como abrir dois sockets HTTP
     * genuinamente em paralelo sem subir dois processos, e a suíte deste
     * projeto não faz isso em lugar nenhum. Em vez disso, o teste força a
     * pior intercalação possível por dentro do próprio caminho real de
     * `ProcessadorDeEventoDeCobranca::registrar()`
     * (`GatewayEvent::firstOrCreate()`):
     *
     * 1. A chamada desta requisição já checou "este evento_id não existe" (a
     *    leitura que `firstOrCreate` faz primeiro) e está prestes a gravar a
     *    linha.
     * 2. No evento `creating` do Eloquent — o instante exato entre essa
     *    checagem e o INSERT de verdade — uma **segunda conexão PDO real**,
     *    independente da conexão do teste (não a mesma sessão, não uma
     *    transação aninhada), grava e comita por fora a mesma `evento_id`.
     *    É o "outro processo que venceu a corrida por uma fração de
     *    segundo".
     * 3. O INSERT desta requisição, que a essa altura já acha que pode
     *    prosseguir, esbarra de verdade no unique de
     *    `gateway_events.evento_id` — erro 1062 do MySQL, não uma simulação
     *    em memória.
     *
     * O que isso prova: o `UniqueConstraintViolationException` real do banco
     * é capturado pelo `createOrFirst()` do Laravel (por trás do
     * `firstOrCreate` do Eloquent desde a v9), que busca de novo e devolve a
     * linha que a outra conexão gravou, em vez de estourar erro 500 para o
     * gateway. E que, a partir daí, o processamento roda uma vez só sobre
     * essa linha: a parcela é baixada com o valor de uma cobrança só, nunca
     * duas.
     *
     * A conexão da requisição HTTP roda, durante o teste inteiro, dentro de
     * uma transação aberta pelo `RefreshDatabase` desde o `setUp()` — sob o
     * isolamento padrão do projeto (REPEATABLE READ), essa transação fixaria
     * o retrato do banco na primeira leitura (já feita na seleção de papéis
     * do `RolesAndPermissionsSeeder`) e nunca enxergaria o que a segunda
     * conexão comitar depois, mesmo após comitado — o que faria a
     * recuperação do `createOrFirst` falhar por um motivo que não existe em
     * produção (lá cada statement autocommitado tem seu próprio retrato). Por
     * isso o teste reabre a transação sob READ COMMITTED antes de montar o
     * cenário: cada leitura passa a enxergar o commit mais recente, do jeito
     * que qualquer requisição de verdade, fora de uma transação de teste
     * longa, já se comporta.
     *
     * Limitação de fidelidade, documentada aqui de propósito: a segunda
     * "gravação" é um INSERT direto por uma conexão à parte, não uma segunda
     * chamada inteira ao controller — uma segunda chamada completa cairia no
     * mesmo caminho de `processado_em` que o teste sequencial acima já prova.
     * O que este teste isola, sem depender de timing de sistema operacional,
     * é exatamente a barreira que protege da corrida real: o unique
     * constraint do banco reagindo a uma escrita de outra conexão de
     * verdade, e não a suposição de que "rodar duas vezes em sequência"
     * basta.
     */
    public function test_dois_envios_simultaneos_do_mesmo_evento_baixam_a_parcela_uma_vez_so(): void
    {
        // Reabre a transação de teste sob READ COMMITTED antes de montar
        // qualquer coisa: feito agora, e não depois de criar o cenário, tudo
        // que este teste grava (tenant, cobrança, evento) continua dentro da
        // transação que o RefreshDatabase desfaz ao final. Só o seed do
        // `setUp()` (papéis e permissões, idempotente por natureza) fica
        // comitado de verdade — sem efeito nos demais testes da suíte.
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        DB::commit();
        DB::beginTransaction();

        // Único try/finally para todo o corpo do teste, do reabrir da
        // transação até as asserções: uma falha durante o próprio setup
        // (`cenario()`, `cobranca()`, o cálculo de `$eventoId`, o registro do
        // hook) precisa passar pelo mesmo cleanup que uma falha nas
        // asserções passaria. Dois blocos aninhados, cada um com seu próprio
        // finally, deixavam essa janela de setup coberta só pelo finally
        // externo — que reabre transação sem antes desfazer a que já estava
        // aberta, produzindo uma transação aninhada (savepoint) em vez de
        // uma transação nova de verdade nesse caminho de exceção.
        $jaDisparou = false;
        $eventoId = null;
        $conexaoConcorrente = null;

        try {
            $tenant = $this->cenario('Dedetizadora Concorrência');
            $this->cobranca($tenant, 'ORDE_RACE_1');

            $payload = $this->payloadPago('ORDE_RACE_1', 'CHAR_1', 30000, '2026-08-10');
            $cabecalho = $this->cabecalho($tenant, $payload);

            // O mesmo evento_id que `ProcessadorDeEventoDeCobranca::registrar()`
            // vai calcular para este payload, computado pela implementação
            // real do gateway (não reproduzido à mão), para a gravação
            // concorrente colidir exatamente na mesma chave.
            $eventoId = app(GatewayPagBank::class)->interpretarWebhook($payload)->eventoId;

            // Segunda conexão PDO real e independente para o mesmo banco
            // físico de teste — não a mesma sessão, não uma transação
            // aninhada da conexão padrão.
            config(['database.connections.concorrente' => config('database.connections.'.config('database.default'))]);
            DB::purge('concorrente');
            $conexaoConcorrente = DB::connection('concorrente');

            // O gancho `creating` do Eloquent dispara depois que
            // `firstOrCreate` já leu "não existe" e exatamente antes do
            // INSERT de verdade: é essa janela, e não o método inteiro, que
            // duas entregas simultâneas do mesmo evento disputariam.
            GatewayEvent::creating(function (GatewayEvent $evento) use (&$jaDisparou, $eventoId, $conexaoConcorrente, $payload): void {
                if ($jaDisparou || $evento->evento_id !== $eventoId) {
                    return;
                }

                $jaDisparou = true;

                // `company_id` fica nulo de propósito: a linha é gravada por
                // uma conexão que não enxerga o tenant ainda não comitado na
                // conexão da requisição (o cenário roda dentro da mesma
                // transação reaberta acima), e `gateway_events.company_id` é
                // nullable por design (evento de plataforma também usa
                // null). O que importa colidir aqui é só `evento_id`.
                $conexaoConcorrente->table('gateway_events')->insert([
                    'company_id' => null,
                    'gateway' => GatewayPagBank::NOME,
                    'evento_id' => $eventoId,
                    'tipo' => 'cobranca_paga',
                    'payload' => json_encode($payload),
                    'processado_em' => null,
                    'erro' => null,
                    'tentativas' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $resposta = $this->postJson($this->rota($tenant), $payload, $cabecalho);

            // 200, nunca 500: a colisão real do unique precisa ser
            // absorvida pelo `createOrFirst`, não vazar como erro de
            // servidor para o gateway (que reenviaria em laço).
            $resposta->assertOk();

            $this->assertSame(
                1,
                GatewayEvent::query()->where('evento_id', $eventoId)->count(),
                'a corrida real no banco não pode deixar duas linhas para o mesmo evento_id'
            );

            $evento = GatewayEvent::query()->where('evento_id', $eventoId)->firstOrFail();
            $this->assertNotNull($evento->processado_em, 'o evento devia ter sido processado apesar da colisão real no INSERT');

            $parcela = TenantAtual::comTenant(
                $tenant['empresa']->id,
                fn () => ReceivableInstallment::query()->firstOrFail()
            );

            // O valor prova a baixa única, mesmo com o INSERT de
            // `gateway_events` tendo colidido de propósito no banco: se o
            // processamento tivesse rodado duas vezes por cima da corrida,
            // o valor pago dobraria.
            $this->assertSame('300.00', $parcela->valor_pago);
            $this->assertSame('paga', $parcela->situacao);
        } finally {
            GatewayEvent::flushEventListeners();

            // A conexão da requisição, mesmo com o INSERT tendo colidido e
            // falhado (ou nem chegado a rodar, se o teste falhou antes),
            // pode segurar lock sobre a linha até a transação terminar —
            // desfazer tudo que o teste gravou é o que libera esse lock.
            // Sem isso, a limpeza da conexão concorrente abaixo bloquearia
            // até o `innodb_lock_wait_timeout` do MySQL.
            DB::rollBack();

            if ($conexaoConcorrente !== null && $eventoId !== null) {
                $conexaoConcorrente->table('gateway_events')->where('evento_id', $eventoId)->delete();
            }

            DB::purge('concorrente');

            // Volta o isolamento ao padrão do projeto e reabre uma
            // transação vazia: é o que o `RefreshDatabase` espera encontrar
            // (uma transação ativa) para desfazer sem erro ao final do
            // teste, sem forçar uma nova `migrate:fresh` no próximo teste da
            // suíte por encontrar a conexão sem transação alguma.
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            DB::beginTransaction();
        }
    }

    /**
     * Espelha `GatewayWebhookTest::test_a_rota_do_webhook_responde_sem_autenticacao_e_esta_fora_da_verificacao_de_csrf`
     * (Plano 7), para a rota irmã registrada nesta task.
     */
    public function test_a_rota_esta_fora_da_verificacao_de_csrf(): void
    {
        $isentas = new ReflectionProperty(ValidateCsrfToken::class, 'neverVerify');
        $isentas->setAccessible(true);

        $this->assertContains('webhooks/cobranca/*', $isentas->getValue());

        $this->assertGuest();
    }

    // -----------------------------------------------------------------
    // Efeito de domínio
    // -----------------------------------------------------------------

    public function test_pagamento_confirmado_baixa_a_parcela_com_data_e_valor_do_gateway(): void
    {
        $tenant = $this->cenario('Dedetizadora Épsilon');
        $cobranca = $this->cobranca($tenant, 'ORDE_EPS_1');

        $payload = $this->payloadPago('ORDE_EPS_1', 'CHAR_1', 30000, '2026-08-10');

        $this->postJson($this->rota($tenant), $payload, $this->cabecalho($tenant, $payload))->assertOk();

        $parcela = TenantAtual::comTenant(
            $tenant['empresa']->id,
            fn () => ReceivableInstallment::query()->firstOrFail()
        );

        $this->assertSame('paga', $parcela->situacao);
        $this->assertSame('300.00', $parcela->valor_pago);
        $this->assertSame('2026-08-10', $parcela->pago_em->toDateString());
        $this->assertNotNull($parcela->financial_entry_id, 'a baixa precisa deixar um lançamento de caixa');

        $cobrancaFresca = $cobranca->fresh();
        $this->assertSame('paga', $cobrancaFresca->situacao);
        $this->assertSame('300.00', $cobrancaFresca->valor_pago);
        $this->assertSame('2026-08-10', $cobrancaFresca->pago_em->toDateString());
    }

    public function test_estorno_reverte_a_baixa_com_motivo(): void
    {
        $tenant = $this->cenario('Dedetizadora Zeta');
        $this->cobranca($tenant, 'ORDE_ZETA_1');

        $pago = $this->payloadPago('ORDE_ZETA_1', 'CHAR_1', 30000, '2026-08-10');
        $this->postJson($this->rota($tenant), $pago, $this->cabecalho($tenant, $pago))->assertOk();

        $estornado = $this->payloadComStatus('ORDE_ZETA_1', 'CHAR_1', 'REFUNDED', 30000);
        $this->postJson($this->rota($tenant), $estornado, $this->cabecalho($tenant, $estornado))->assertOk();

        $parcela = TenantAtual::comTenant(
            $tenant['empresa']->id,
            fn () => ReceivableInstallment::query()->firstOrFail()->fresh()
        );

        $this->assertSame('0.00', $parcela->valor_pago);
        $this->assertNull($parcela->pago_em);
        $this->assertContains($parcela->situacao, ['aberta', 'vencida']);

        $cobranca = Charge::query()->where('gateway_charge_id', 'ORDE_ZETA_1')->firstOrFail();
        $this->assertSame('estornada', $cobranca->situacao);

        $lancamentoDeEstorno = TenantAtual::comTenant(
            $tenant['empresa']->id,
            fn () => $parcela->financialEntry()->first()
        );

        $this->assertNotNull($lancamentoDeEstorno);
        $this->assertStringContainsString('Estorno automático', (string) $lancamentoDeEstorno->notes);
    }

    public function test_evento_de_tipo_desconhecido_e_gravado_sem_erro(): void
    {
        $tenant = $this->cenario('Dedetizadora Ômicron');
        $this->cobranca($tenant, 'ORDE_OMI_1');

        // Sem `charges`, formato de webhook que `GatewayPagBank` não
        // reconhece como cobrança: vira `desconhecido`, nunca erro.
        $payload = ['id' => 'evt-qualquer', 'event' => 'algo.que.nao.usamos'];

        $this->postJson($this->rota($tenant), $payload, $this->cabecalho($tenant, $payload))
            ->assertOk();

        $evento = GatewayEvent::query()->firstOrFail();

        $this->assertSame('desconhecido', $evento->tipo);
        $this->assertNotNull($evento->processado_em);
        $this->assertNull($evento->erro);
        $this->assertSame(0, $evento->tentativas);

        $this->assertSame(
            'emitida',
            Charge::query()->where('gateway_charge_id', 'ORDE_OMI_1')->firstOrFail()->situacao
        );
    }

    public function test_evento_com_falha_fica_reprocessavel(): void
    {
        $tenant = $this->cenario('Dedetizadora Sigma');
        // De propósito, nenhuma Charge é criada com este gateway_charge_id.

        $payload = $this->payloadPago('ORDE_QUE_NAO_EXISTE', 'CHAR_1', 30000, '2026-08-10');

        $this->postJson($this->rota($tenant), $payload, $this->cabecalho($tenant, $payload))
            ->assertOk();

        $evento = GatewayEvent::query()->firstOrFail();

        $this->assertNull($evento->processado_em);
        $this->assertNotNull($evento->erro);
        $this->assertSame(1, $evento->tentativas);
        $this->assertStringContainsString('ORDE_QUE_NAO_EXISTE', (string) $evento->erro);
    }

    // -----------------------------------------------------------------
    // Isolamento entre tenants — o teste mais importante do arquivo
    // -----------------------------------------------------------------

    public function test_webhook_de_um_tenant_nao_alcanca_cobranca_de_outro(): void
    {
        $tenantA = $this->cenario('Dedetizadora Tenant A');
        $tenantB = $this->cenario('Dedetizadora Tenant B');

        $this->cobranca($tenantA, 'ORDE_TENANT_A');
        $cobrancaB = $this->cobranca($tenantB, 'ORDE_TENANT_B');

        $parcelaB = TenantAtual::comTenant(
            $tenantB['empresa']->id,
            fn () => ReceivableInstallment::query()->firstOrFail()
        );

        // Webhook chega no endereço do tenant A (assinado com o token do
        // tenant A), mas o payload referencia o gateway_charge_id que existe
        // é no tenant B. Um webhook forjado, ou um bug de integração, não
        // pode fazer o tenant A baixar título do tenant B.
        $payload = $this->payloadPago('ORDE_TENANT_B', 'CHAR_1', 30000, '2026-08-10');

        $resposta = $this->postJson($this->rota($tenantA), $payload, $this->cabecalho($tenantA, $payload));

        $resposta->assertOk()->assertJson(['message' => 'Evento recebido.']);

        $evento = GatewayEvent::query()->firstOrFail();
        $this->assertSame($tenantA['empresa']->id, $evento->company_id, 'o evento é do tenant que assinou, não do tenant citado no payload');
        $this->assertNull($evento->processado_em, 'o processamento não pode ter dado certo: a cobrança não é deste tenant');
        $this->assertNotNull($evento->erro);

        // A cobrança e a parcela do tenant B continuam intocadas.
        $this->assertSame('emitida', $cobrancaB->fresh()->situacao);
        $this->assertSame('0.00', $parcelaB->fresh()->valor_pago);
        $this->assertSame('aberta', $parcelaB->fresh()->situacao);

        // E o tenant A também não ganhou baixa nenhuma: ele não tem cobrança
        // com esse gateway_charge_id.
        $this->assertSame(
            'emitida',
            Charge::query()->where('gateway_charge_id', 'ORDE_TENANT_A')->firstOrFail()->situacao
        );
    }

    // -----------------------------------------------------------------
    // Apoio: cenário (empresa + gateway + administrador)
    // -----------------------------------------------------------------

    /**
     * Empresa com gateway configurado e um administrador ativo — o
     * administrador é a quem `ProcessadorDeEventoDeCobranca` atribui o
     * lançamento de caixa da baixa automática (ver o cabeçalho da classe).
     *
     * @return array{empresa: Company, config: PaymentGatewayConfig, token: string, webhookToken: string, cliente: Client}
     */
    private function cenario(string $nome): array
    {
        $empresa = Company::create(['name' => $nome]);

        $tokenDoGateway = 'token-'.str($nome)->slug();
        // Alfanumérico puro, sem hífen: casa com a restrição da rota
        // (`where('webhookToken', '[A-Za-z0-9]+')`) e com o formato que a
        // tela de configuração da Task 19.6/19.7 vai gerar de verdade.
        $webhookToken = Str::random(40);

        $config = TenantAtual::comTenant($empresa->id, fn () => PaymentGatewayConfig::create([
            'gateway' => GatewayPagBank::NOME,
            'ambiente' => 'sandbox',
            'credenciais' => ['token' => $tokenDoGateway],
            'webhook_token' => $webhookToken,
            'ativo' => true,
        ]));

        $administrador = TenantAtual::comTenant($empresa->id, fn () => User::factory()->create([
            'name' => 'Administrador '.$nome,
            'email' => 'admin-'.uniqid().'@exemplo.test',
        ]));
        $administrador->assignRole('administrador');

        $cliente = TenantAtual::comTenant($empresa->id, function () {
            $cliente = ClientFactory::new()->create(['cnpj' => '111.444.777-35']);

            AddressFactory::new()->create([
                'client_id' => $cliente->id,
                'zip' => '01000-000',
            ]);

            return $cliente->fresh();
        });

        return [
            'empresa' => $empresa,
            'config' => $config,
            'token' => $tokenDoGateway,
            'webhookToken' => $webhookToken,
            'cliente' => $cliente,
        ];
    }

    /**
     * Cobrança `emitida`, vinculada a uma parcela de 300,00 nova, própria do
     * tenant do cenário informado.
     *
     * @param  array{empresa: Company, cliente: Client}  $tenant
     */
    private function cobranca(array $tenant, string $gatewayChargeId): Charge
    {
        return TenantAtual::comTenant($tenant['empresa']->id, function () use ($tenant, $gatewayChargeId) {
            $titulo = Receivable::create([
                'client_id' => $tenant['cliente']->id,
                'descricao' => 'Serviço de dedetização',
                'valor_total' => 300.00,
                'emitido_em' => '2026-08-01',
                'situacao' => 'aberto',
            ]);

            $parcela = ReceivableInstallment::create([
                'receivable_id' => $titulo->id,
                'numero' => 1,
                'valor' => 300.00,
                // Vencimento no futuro: depois de um eventual estorno, a
                // parcela volta para "aberta", nunca "vencida", em qualquer
                // data em que a suíte rodar.
                'vencimento' => '2026-12-31',
                'valor_pago' => 0,
                'situacao' => 'aberta',
            ]);

            return Charge::create([
                'receivable_installment_id' => $parcela->id,
                'tipo' => 'boleto',
                'gateway' => GatewayPagBank::NOME,
                'gateway_charge_id' => $gatewayChargeId,
                'valor' => '300.00',
                'vencimento' => '2026-12-31',
                'situacao' => 'emitida',
            ]);
        });
    }

    // -----------------------------------------------------------------
    // Apoio: requisição
    // -----------------------------------------------------------------

    /**
     * @param  array{webhookToken: string}  $tenant
     */
    private function rota(array $tenant): string
    {
        return '/webhooks/cobranca/'.$tenant['webhookToken'];
    }

    /**
     * Cabeçalho de assinatura, no mesmo formato que `GatewayPagBank::validarWebhook()`
     * confere: SHA-256 de `token-do-tenant + "-" + corpo cru`. O corpo
     * precisa ser `json_encode()` sem opção nenhuma, porque é exatamente
     * assim que `postJson()` serializa o array antes de mandar — qualquer
     * diferença de codificação muda o hash e derruba a assinatura.
     *
     * @param  array{token: string}  $tenant
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function cabecalho(array $tenant, array $payload): array
    {
        return [
            'x-authenticity-token' => hash('sha256', $tenant['token'].'-'.json_encode($payload)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadPago(string $idDoPedido, string $idDaCobranca, int $centavos, string $pagoEm): array
    {
        return [
            'id' => $idDoPedido,
            'charges' => [[
                'id' => $idDaCobranca,
                'status' => 'PAID',
                'amount' => ['value' => $centavos],
                'paid_at' => $pagoEm.'T10:00:00-03:00',
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadComStatus(string $idDoPedido, string $idDaCobranca, string $status, int $centavos): array
    {
        return [
            'id' => $idDoPedido,
            'charges' => [[
                'id' => $idDaCobranca,
                'status' => $status,
                'amount' => ['value' => $centavos],
            ]],
        ];
    }
}
