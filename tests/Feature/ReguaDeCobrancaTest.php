<?php

namespace Tests\Feature;

use App\Models\Charge;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyBillingSetting;
use App\Models\Contract;
use App\Models\NotificationQueue;
use App\Models\PaymentGatewayConfig;
use App\Models\Receivable;
use App\Models\ReceivableInstallment;
use App\Models\ScheduledTaskRun;
use App\Services\NotificationDispatcher;
use App\Services\Payments\GatewayPagBank;
use App\Services\Payments\ReguaDeCobrancaService;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 19.5 do Plano 19: `cobrancas:emitir-recorrentes`,
 * `App\Services\Payments\ReguaDeCobrancaService` e `cobrancas:regua`.
 *
 * Nenhum teste toca a rede de verdade: a emissão recorrente usa
 * `Http::fake()` com `preventStrayRequests()`, mesmo critério de
 * `ChargeServiceTest`. A régua em si não fala com o gateway (só lê `Charge`
 * já gravada), então os testes dela criam a cobrança ativa diretamente.
 *
 * O relógio é fixado em UTC num horário sem risco de virada de dia entre
 * UTC e o fuso do negócio, e todas as datas dos cenários são relativas a
 * `BusinessDate::hoje()`, nunca a `now()`.
 */
class ReguaDeCobrancaTest extends TestCase
{
    use RefreshDatabase;

    private const HOST_SANDBOX = 'https://sandbox.api.pagseguro.com';

    /**
     * 12h em UTC, 09h em Brasília: mesmo dia calendário nos dois fusos.
     */
    private const AGORA_EM_UTC = '2026-08-10 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        Carbon::setTestNow(Carbon::parse(self::AGORA_EM_UTC, 'UTC'));

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // cobrancas:emitir-recorrentes
    // -----------------------------------------------------------------

    public function test_emite_cobranca_de_parcela_de_contrato_dentro_da_antecedencia(): void
    {
        $this->fakeBoleto();

        $empresa = $this->empresa();
        $this->configuracaoGateway($empresa);
        $cliente = $this->clienteValido($empresa);
        $contrato = $this->contrato($empresa, $cliente);
        $parcela = $this->parcelaDeContrato($cliente, $contrato, $empresa, [
            'vencimento' => BusinessDate::hoje()->addDays(5)->toDateString(),
        ]);

        $this->artisan('cobrancas:emitir-recorrentes', ['--company' => $empresa->id])
            ->assertSuccessful();

        $cobranca = $this->comTenant($empresa, fn () => $parcela->fresh()->charges()->first());

        $this->assertNotNull($cobranca, 'a cobrança recorrente não foi emitida dentro da antecedência padrão (10 dias)');
        $this->assertSame('emitida', $cobranca->situacao);
        $this->assertSame('boleto', $cobranca->tipo);
    }

    public function test_nao_emite_para_parcela_fora_da_antecedencia_padrao(): void
    {
        $this->fakeBoleto();

        $empresa = $this->empresa();
        $this->configuracaoGateway($empresa);
        $cliente = $this->clienteValido($empresa);
        $contrato = $this->contrato($empresa, $cliente);
        $parcela = $this->parcelaDeContrato($cliente, $contrato, $empresa, [
            // Antecedência padrão é 10 dias: 20 dias à frente é cedo demais.
            'vencimento' => BusinessDate::hoje()->addDays(20)->toDateString(),
        ]);

        $this->artisan('cobrancas:emitir-recorrentes', ['--company' => $empresa->id])
            ->assertSuccessful();

        $this->assertSame(0, $this->comTenant($empresa, fn () => $parcela->fresh()->charges()->count()));
    }

    public function test_nao_emite_quando_parcela_ja_tem_cobranca_ativa(): void
    {
        $this->fakeBoleto();

        $empresa = $this->empresa();
        $this->configuracaoGateway($empresa);
        $cliente = $this->clienteValido($empresa);
        $contrato = $this->contrato($empresa, $cliente);
        $parcela = $this->parcelaDeContrato($cliente, $contrato, $empresa, [
            'vencimento' => BusinessDate::hoje()->addDays(5)->toDateString(),
        ]);
        $this->cobrancaAtiva($parcela, $empresa);

        // Http::preventStrayRequests() já garantiria a falha se o gateway
        // fosse chamado; a asserção abaixo confirma que continua uma só
        // cobrança.
        $this->artisan('cobrancas:emitir-recorrentes', ['--company' => $empresa->id])
            ->assertSuccessful();

        $this->assertSame(1, $this->comTenant($empresa, fn () => $parcela->fresh()->charges()->count()));
    }

    public function test_rodar_duas_vezes_no_mesmo_dia_nao_duplica_cobranca(): void
    {
        $this->fakeBoleto();

        $empresa = $this->empresa();
        $this->configuracaoGateway($empresa);
        $cliente = $this->clienteValido($empresa);
        $contrato = $this->contrato($empresa, $cliente);
        $parcela = $this->parcelaDeContrato($cliente, $contrato, $empresa, [
            'vencimento' => BusinessDate::hoje()->addDays(5)->toDateString(),
        ]);

        $this->artisan('cobrancas:emitir-recorrentes', ['--company' => $empresa->id])->assertSuccessful();
        $this->artisan('cobrancas:emitir-recorrentes', ['--company' => $empresa->id])->assertSuccessful();

        $this->assertSame(1, $this->comTenant($empresa, fn () => $parcela->fresh()->charges()->count()));
    }

    public function test_respeita_a_antecedencia_configurada_por_tenant(): void
    {
        $this->fakeBoleto();

        $empresa = $this->empresa();
        $this->configuracaoGateway($empresa);
        $this->comTenant($empresa, fn () => CompanyBillingSetting::create(['antecedencia_emissao_dias' => 3]));

        $cliente = $this->clienteValido($empresa);
        $contrato = $this->contrato($empresa, $cliente);

        $foraDoPrazo = $this->parcelaDeContrato($cliente, $contrato, $empresa, [
            'vencimento' => BusinessDate::hoje()->addDays(5)->toDateString(),
        ]);
        $dentroDoPrazo = $this->parcelaDeContrato($cliente, $contrato, $empresa, [
            'vencimento' => BusinessDate::hoje()->addDays(2)->toDateString(),
        ]);

        $this->artisan('cobrancas:emitir-recorrentes', ['--company' => $empresa->id])->assertSuccessful();

        $this->assertSame(0, $this->comTenant($empresa, fn () => $foraDoPrazo->fresh()->charges()->count()), 'parcela fora da antecedência de 3 dias configurada pelo tenant não deveria ser emitida');
        $this->assertSame(1, $this->comTenant($empresa, fn () => $dentroDoPrazo->fresh()->charges()->count()), 'parcela dentro da antecedência de 3 dias configurada pelo tenant deveria ser emitida');
    }

    public function test_falha_de_emissao_em_uma_parcela_ou_em_um_tenant_nao_impede_as_demais(): void
    {
        $this->fakeBoleto();

        $empresaA = $this->empresa('Dedetizadora A');
        $this->configuracaoGateway($empresaA, 'token-a');
        $clienteValidoA = $this->clienteValido($empresaA);
        $clienteQuebradoA = $this->clienteValido($empresaA, ['cnpj' => '']); // sem documento: ChargeService recusa
        $contratoA = $this->contrato($empresaA, $clienteValidoA);
        $contratoQuebradoA = $this->contrato($empresaA, $clienteQuebradoA);

        $parcelaOkA = $this->parcelaDeContrato($clienteValidoA, $contratoA, $empresaA, [
            'vencimento' => BusinessDate::hoje()->addDays(5)->toDateString(),
        ]);
        $parcelaQuebradaA = $this->parcelaDeContrato($clienteQuebradoA, $contratoQuebradoA, $empresaA, [
            'vencimento' => BusinessDate::hoje()->addDays(5)->toDateString(),
        ]);

        $empresaB = $this->empresa('Dedetizadora B');
        $this->configuracaoGateway($empresaB, 'token-b');
        $clienteB = $this->clienteValido($empresaB);
        $contratoB = $this->contrato($empresaB, $clienteB);
        $parcelaB = $this->parcelaDeContrato($clienteB, $contratoB, $empresaB, [
            'vencimento' => BusinessDate::hoje()->addDays(5)->toDateString(),
        ]);

        // --todas percorre as duas empresas na mesma execução.
        $this->artisan('cobrancas:emitir-recorrentes', ['--todas' => true])->assertFailed();

        $this->assertSame(1, $this->comTenant($empresaA, fn () => $parcelaOkA->fresh()->charges()->count()), 'a parcela válida da empresa A deveria ter sido emitida');
        $this->assertSame(0, $this->comTenant($empresaA, fn () => $parcelaQuebradaA->fresh()->charges()->count()), 'a parcela com cliente sem documento não deveria gerar cobrança');
        $this->assertSame(1, $this->comTenant($empresaB, fn () => $parcelaB->fresh()->charges()->count()), 'a falha na empresa A não pode impedir a emissão da empresa B');
    }

    // -----------------------------------------------------------------
    // Régua: os cinco marcos
    // -----------------------------------------------------------------

    public function test_marcos_da_regua_sao_cinco(): void
    {
        $this->assertSame(
            ['antes_3', 'no_dia', 'depois_3', 'depois_7', 'depois_15'],
            ReguaDeCobrancaService::marcos()
        );
    }

    public function test_marco_antes_do_vencimento_dispara_3_dias_antes_com_link_de_pagamento(): void
    {
        $empresa = $this->empresa();
        $cliente = $this->clienteValido($empresa);
        $contrato = $this->contrato($empresa, $cliente);
        $parcela = $this->parcelaDeContrato($cliente, $contrato, $empresa, [
            'vencimento' => BusinessDate::hoje()->addDays(3)->toDateString(),
        ]);
        $this->cobrancaAtiva($parcela, $empresa, ['link_pagamento' => 'https://pay.test/abc123']);

        $this->artisan('cobrancas:regua', ['--company' => $empresa->id])->assertSuccessful();

        $item = $this->comTenant($empresa, fn () => NotificationQueue::where('evento', EventosDeNotificacao::COBRANCA_A_VENCER)->first());

        $this->assertNotNull($item, 'o marco "3 dias antes" não enfileirou aviso');
        $this->assertStringContainsString('https://pay.test/abc123', $item->corpo);
    }

    public function test_marco_no_dia_do_vencimento_dispara_no_dia(): void
    {
        $empresa = $this->empresa();
        $cliente = $this->clienteValido($empresa);
        $contrato = $this->contrato($empresa, $cliente);
        $parcela = $this->parcelaDeContrato($cliente, $contrato, $empresa, [
            'vencimento' => BusinessDate::hoje()->toDateString(),
        ]);
        $this->cobrancaAtiva($parcela, $empresa);

        $this->artisan('cobrancas:regua', ['--company' => $empresa->id])->assertSuccessful();

        $this->assertSame(
            1,
            $this->comTenant($empresa, fn () => NotificationQueue::where('evento', EventosDeNotificacao::COBRANCA_VENCE_HOJE)->count())
        );
    }

    public function test_marcos_depois_do_vencimento_disparam_em_3_7_e_15_dias(): void
    {
        $empresa = $this->empresa();
        $cliente = $this->clienteValido($empresa);
        $contrato = $this->contrato($empresa, $cliente);

        $mapa = [3 => null, 7 => null, 15 => null];

        foreach (array_keys($mapa) as $dias) {
            $parcela = $this->parcelaDeContrato($cliente, $contrato, $empresa, [
                'vencimento' => BusinessDate::hoje()->subDays($dias)->toDateString(),
                'situacao' => 'vencida',
            ]);
            $this->cobrancaAtiva($parcela, $empresa);
        }

        $this->artisan('cobrancas:regua', ['--company' => $empresa->id])->assertSuccessful();

        $itens = $this->comTenant(
            $empresa,
            fn () => NotificationQueue::where('evento', EventosDeNotificacao::COBRANCA_EM_ATRASO)->get()
        );

        $this->assertCount(3, $itens, 'os três marcos de atraso (3, 7 e 15 dias) deveriam ter enfileirado um aviso cada');

        foreach ([3, 7, 15] as $dias) {
            $this->assertTrue(
                $itens->contains(fn (NotificationQueue $item): bool => str_contains($item->corpo, "há {$dias} dias")),
                "não encontrei o aviso do marco de {$dias} dias de atraso"
            );
        }
    }

    public function test_rodar_a_regua_duas_vezes_no_mesmo_dia_nao_duplica_o_aviso(): void
    {
        $empresa = $this->empresa();
        $cliente = $this->clienteValido($empresa);
        $contrato = $this->contrato($empresa, $cliente);
        $parcela = $this->parcelaDeContrato($cliente, $contrato, $empresa, [
            'vencimento' => BusinessDate::hoje()->toDateString(),
        ]);
        $this->cobrancaAtiva($parcela, $empresa);

        $this->artisan('cobrancas:regua', ['--company' => $empresa->id])->assertSuccessful();
        $this->artisan('cobrancas:regua', ['--company' => $empresa->id])->assertSuccessful();

        $this->assertSame(1, $this->comTenant($empresa, fn () => NotificationQueue::count()));
    }

    // -----------------------------------------------------------------
    // A régua para quando a parcela é paga ou cancelada
    // -----------------------------------------------------------------

    public function test_parcela_ja_paga_nao_entra_na_regua_no_enfileiramento(): void
    {
        $empresa = $this->empresa();
        $cliente = $this->clienteValido($empresa);
        $contrato = $this->contrato($empresa, $cliente);
        $parcela = $this->parcelaDeContrato($cliente, $contrato, $empresa, [
            'vencimento' => BusinessDate::hoje()->addDays(3)->toDateString(),
            'situacao' => 'paga',
        ]);
        $this->cobrancaAtiva($parcela, $empresa, ['situacao' => 'paga']);

        $this->artisan('cobrancas:regua', ['--company' => $empresa->id])->assertSuccessful();

        $this->assertSame(0, $this->comTenant($empresa, fn () => NotificationQueue::count()));
    }

    /**
     * A segunda checagem, no envio: o aviso já estava na fila quando a
     * parcela foi paga, e o despachante ainda assim não pode entregá-lo.
     */
    public function test_parcela_paga_depois_do_enfileiramento_interrompe_o_envio(): void
    {
        $empresa = $this->empresa(nome: 'Dedetizadora Envio', email: 'contato@envio.test');
        $cliente = $this->clienteValido($empresa);
        $contrato = $this->contrato($empresa, $cliente);
        $parcela = $this->parcelaDeContrato($cliente, $contrato, $empresa, [
            'vencimento' => BusinessDate::hoje()->toDateString(),
        ]);
        $this->cobrancaAtiva($parcela, $empresa);

        $this->artisan('cobrancas:regua', ['--company' => $empresa->id])->assertSuccessful();

        $item = $this->comTenant($empresa, fn () => NotificationQueue::first());
        $this->assertNotNull($item, 'pré-condição: o aviso precisa estar na fila antes do pagamento');
        $this->assertSame(NotificationQueue::SITUACAO_PENDENTE, $item->situacao);

        // O cliente paga a parcela depois que o aviso já foi enfileirado, mas
        // antes de o despachante processá-lo.
        $this->comTenant($empresa, fn () => $parcela->update(['situacao' => 'paga']));

        $resumo = $this->comTenant($empresa, fn () => (new NotificationDispatcher)->despachar());

        $this->assertSame(1, $resumo['processados']);
        $this->assertSame(0, $resumo['enviados'], 'o despachante não pode entregar aviso de cobrança de parcela já paga');

        $this->assertSame(NotificationQueue::SITUACAO_FALHA, $item->refresh()->situacao);
        $this->assertCount(0, Mail::getSymfonyTransport()->messages(), 'nenhum e-mail deveria ter saído para uma parcela já paga');
    }

    // -----------------------------------------------------------------
    // Canal recusado x pendência visível para a empresa
    // -----------------------------------------------------------------

    public function test_cliente_que_recusou_o_canal_nao_recebe_mas_a_pendencia_continua_visivel(): void
    {
        $empresa = $this->empresa();
        $cliente = $this->clienteValido($empresa, ['aceita_email' => false, 'aceita_whatsapp' => false]);
        $contrato = $this->contrato($empresa, $cliente);
        $parcela = $this->parcelaDeContrato($cliente, $contrato, $empresa, [
            'vencimento' => BusinessDate::hoje()->toDateString(),
        ]);
        $this->cobrancaAtiva($parcela, $empresa);

        $this->artisan('cobrancas:regua', ['--company' => $empresa->id])->assertSuccessful();

        $this->assertSame(0, $this->comTenant($empresa, fn () => NotificationQueue::count()), 'cliente com os dois canais recusados não pode receber aviso nenhum');

        // A pendência em si (a parcela e a cobrança ativa) continua intacta e
        // visível para a empresa: a recusa do canal não esconde o título.
        $parcelaAtualizada = $this->comTenant($empresa, fn () => $parcela->fresh());
        $this->assertSame('aberta', $parcelaAtualizada->situacao);
        $this->assertSame(1, $this->comTenant($empresa, fn () => $parcela->fresh()->charges()->count()));
    }

    // -----------------------------------------------------------------
    // Configuração por tenant
    // -----------------------------------------------------------------

    public function test_tenant_com_a_regua_desligada_nao_dispara_nada(): void
    {
        $empresa = $this->empresa();
        $this->comTenant($empresa, fn () => CompanyBillingSetting::create(['regua_ativa' => false]));

        $cliente = $this->clienteValido($empresa);
        $contrato = $this->contrato($empresa, $cliente);
        $parcela = $this->parcelaDeContrato($cliente, $contrato, $empresa, [
            'vencimento' => BusinessDate::hoje()->toDateString(),
        ]);
        $this->cobrancaAtiva($parcela, $empresa);

        $this->artisan('cobrancas:regua', ['--company' => $empresa->id])->assertSuccessful();

        $this->assertSame(0, $this->comTenant($empresa, fn () => NotificationQueue::count()));
    }

    public function test_tenant_com_apenas_um_marco_ativo_so_dispara_esse_marco(): void
    {
        $empresa = $this->empresa();
        $this->comTenant($empresa, fn () => CompanyBillingSetting::create(['marcos_ativos' => ['no_dia']]));

        $cliente = $this->clienteValido($empresa);
        $contrato = $this->contrato($empresa, $cliente);

        $parcelaAntes = $this->parcelaDeContrato($cliente, $contrato, $empresa, [
            'vencimento' => BusinessDate::hoje()->addDays(3)->toDateString(),
        ]);
        $this->cobrancaAtiva($parcelaAntes, $empresa);

        $parcelaHoje = $this->parcelaDeContrato($cliente, $contrato, $empresa, [
            'vencimento' => BusinessDate::hoje()->toDateString(),
        ]);
        $this->cobrancaAtiva($parcelaHoje, $empresa);

        $this->artisan('cobrancas:regua', ['--company' => $empresa->id])->assertSuccessful();

        $this->assertSame(1, $this->comTenant($empresa, fn () => NotificationQueue::count()), 'só o marco "no_dia" está ativo para este tenant');
        $this->assertSame(
            1,
            $this->comTenant($empresa, fn () => NotificationQueue::where('evento', EventosDeNotificacao::COBRANCA_VENCE_HOJE)->count())
        );
    }

    // -----------------------------------------------------------------
    // Sem cobrança ativa: a régua não inventa lembrete
    // -----------------------------------------------------------------

    public function test_parcela_sem_cobranca_ativa_nao_recebe_aviso_da_regua(): void
    {
        $empresa = $this->empresa();
        $cliente = $this->clienteValido($empresa);
        $contrato = $this->contrato($empresa, $cliente);
        $this->parcelaDeContrato($cliente, $contrato, $empresa, [
            'vencimento' => BusinessDate::hoje()->toDateString(),
        ]);
        // Sem Charge nenhuma vinculada.

        $this->artisan('cobrancas:regua', ['--company' => $empresa->id])->assertSuccessful();

        $this->assertSame(0, $this->comTenant($empresa, fn () => NotificationQueue::count()));
    }

    // -----------------------------------------------------------------
    // Registro em scheduled_task_runs
    // -----------------------------------------------------------------

    public function test_as_duas_rotinas_registram_a_execucao_em_scheduled_task_runs(): void
    {
        $this->assertSame(0, ScheduledTaskRun::query()->count());

        $this->artisan('schedule:test', ['--name' => 'cobrancas:emitir-recorrentes'])->assertSuccessful();
        $this->artisan('schedule:test', ['--name' => 'cobrancas:regua'])->assertSuccessful();

        $emissao = ScheduledTaskRun::query()->daTarefa('cobrancas:emitir-recorrentes')->first();
        $regua = ScheduledTaskRun::query()->daTarefa('cobrancas:regua')->first();

        $this->assertNotNull($emissao, 'a rodada de cobrancas:emitir-recorrentes não foi registrada em scheduled_task_runs');
        $this->assertNotNull($regua, 'a rodada de cobrancas:regua não foi registrada em scheduled_task_runs');
        $this->assertSame('success', $emissao->status);
        $this->assertSame('success', $regua->status);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function fakeBoleto(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/orders' => Http::response([
                'id' => 'ORDE_'.Str::random(8),
                'charges' => [[
                    'id' => 'CHAR_'.Str::random(8),
                    'status' => 'WAITING',
                    'payment_method' => [
                        'type' => 'BOLETO',
                        'boleto' => [
                            'barcode' => '03399853012970000000200726101017775500000051',
                            'formatted_barcode' => '03399.85301 29700.000002 00726.101017 7 77550000005100',
                        ],
                    ],
                    'links' => [['rel' => 'PAY', 'href' => 'https://sandbox.pagbank.test/boleto/1.pdf']],
                ]],
            ]),
        ]);
    }

    private function empresa(string $nome = 'Dedetizadora Teste', string $email = 'contato@teste.test'): Company
    {
        return Company::create(['name' => $nome, 'email' => $email]);
    }

    private function configuracaoGateway(Company $empresa, string $token = 'token-empresa'): PaymentGatewayConfig
    {
        return TenantAtual::comTenant($empresa->id, fn (): PaymentGatewayConfig => PaymentGatewayConfig::create([
            'gateway' => GatewayPagBank::NOME,
            'ambiente' => 'sandbox',
            'credenciais' => ['token' => $token],
            'webhook_token' => Str::random(40),
            'ativo' => true,
        ]));
    }

    /**
     * Cliente com nome, CPF válido, e-mail e endereço com CEP: passa em
     * `ValidadorDeDadosDeCobranca` sem pendência, mesmo critério de
     * `ChargeServiceTest`. `$overrides` permite quebrar um campo de
     * propósito (documento em branco) ou desligar canais de notificação.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function clienteValido(Company $empresa, array $overrides = []): Client
    {
        return TenantAtual::comTenant($empresa->id, function () use ($overrides) {
            $cliente = ClientFactory::new()->create(array_merge([
                'cnpj' => '111.444.777-35',
            ], $overrides));

            AddressFactory::new()->create([
                'client_id' => $cliente->id,
                'zip' => '01000-000',
            ]);

            return $cliente->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function contrato(Company $empresa, Client $cliente, array $overrides = []): Contract
    {
        return TenantAtual::comTenant($empresa->id, function () use ($cliente, $overrides) {
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);

            return Contract::create(array_merge([
                'address_id' => $endereco->id,
                'contract_number' => 'CT-'.Str::random(6),
                'service_type' => 'periodico',
                'payment_method' => null,
            ], $overrides));
        });
    }

    /**
     * Parcela única de um título de contrato (a régua e a emissão recorrente
     * só olham para `receivable.contract_id` não nulo).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function parcelaDeContrato(Client $cliente, Contract $contrato, Company $empresa, array $overrides = []): ReceivableInstallment
    {
        return TenantAtual::comTenant($empresa->id, function () use ($cliente, $contrato, $overrides) {
            $valor = $overrides['valor'] ?? 300.00;

            $receivable = Receivable::create([
                'client_id' => $cliente->id,
                'contract_id' => $contrato->id,
                'descricao' => 'Mensalidade do contrato',
                'valor_total' => $valor,
                'emitido_em' => BusinessDate::hoje()->toDateString(),
                'situacao' => 'aberto',
            ]);

            return ReceivableInstallment::create([
                'receivable_id' => $receivable->id,
                'numero' => 1,
                'valor' => $valor,
                'vencimento' => $overrides['vencimento'],
                'valor_pago' => $overrides['valor_pago'] ?? 0,
                'situacao' => $overrides['situacao'] ?? 'aberta',
            ]);
        });
    }

    /**
     * Cria a cobrança ativa da parcela diretamente (sem chamar o gateway):
     * os testes da régua não emitem cobrança, só precisam de uma já
     * existente para lembrar o cliente dela.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function cobrancaAtiva(ReceivableInstallment $parcela, Company $empresa, array $overrides = []): Charge
    {
        return TenantAtual::comTenant($empresa->id, fn (): Charge => Charge::create(array_merge([
            'receivable_installment_id' => $parcela->id,
            'tipo' => 'boleto',
            'gateway' => GatewayPagBank::NOME,
            'gateway_charge_id' => 'CHARGE-'.Str::random(10),
            'valor' => $parcela->valor,
            'vencimento' => $parcela->vencimento,
            'situacao' => 'emitida',
            'link_pagamento' => 'https://pay.test/'.Str::random(8),
            'linha_digitavel' => '00190.00009 01234.567890 12345.678901 1 12340000030000',
            'tentativas' => 1,
        ], $overrides)));
    }

    private function comTenant(Company $empresa, \Closure $callback): mixed
    {
        return TenantAtual::comTenant($empresa->id, $callback);
    }
}
