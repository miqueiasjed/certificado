<?php

namespace Tests\Feature;

use App\Exceptions\DadoFiscalInvalidoException;
use App\Exceptions\PrefeituraIndisponivelException;
use App\Models\Address;
use App\Models\Client;
use App\Models\Company;
use App\Models\FiscalConfig;
use App\Models\Receivable;
use App\Models\ReceivableInstallment;
use App\Models\ScheduledTaskRun;
use App\Models\Service;
use App\Models\ServiceInvoice;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Fiscal\ProvedorDeNfse;
use App\Services\Fiscal\ResolvedorDeProvedor;
use App\Services\ServiceInvoiceService;
use App\Services\SettlementService;
use App\Services\WorkOrderService;
use App\Support\Fiscal\MensagemFiscalPublica;
use App\Support\Fiscal\RespostaDeCancelamento;
use App\Support\Fiscal\RespostaDeNfse;
use App\Support\RotinasAgendadas;
use App\Support\TenantAtual;
use Carbon\CarbonImmutable;
use Database\Factories\WorkOrderFactory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ServiceInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private FiscalConfig $configuracao;

    private Client $cliente;

    private WorkOrder $os;

    private ProvedorFiscalSimulado $provedor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-07 15:00:00');
        TenantAtual::limpar();
        Storage::fake('local');

        $this->empresa = Company::create([
            'name' => 'Prestadora Fiscal',
            'cnpj' => '11.444.777/0001-61',
        ]);
        TenantAtual::definir($this->empresa->id);
        $this->configuracao = FiscalConfig::create([
            'provedor' => 'simulado',
            'ambiente' => 'homologacao',
            'credenciais' => ['token' => 'teste'],
            'regime_tributario' => 'simples_nacional',
            'codigo_servico' => '07.13',
            'cnae' => '8122200',
            'aliquota_iss' => '5.00',
            'natureza_operacao' => 'tributacao_no_municipio',
            'ativo' => true,
        ]);
        $this->cliente = Client::create([
            'name' => 'Tomador Fiscal',
            'email' => 'fiscal@tomador.test',
            'phone' => '11999999999',
            'cnpj' => '04.252.011/0001-10',
            'codigo_municipio_ibge' => '3550308',
            'inscricao_municipal' => '123456',
        ]);
        $endereco = Address::create([
            'client_id' => $this->cliente->id,
            'nickname' => 'Matriz',
            'street' => 'Rua Fiscal',
            'number' => '100',
            'district' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip' => '01001-000',
            'codigo_municipio_ibge' => '3550308',
            'active' => true,
        ]);
        $this->os = WorkOrderFactory::new()->create([
            'client_id' => $this->cliente->id,
            'address_id' => $endereco->id,
            'status' => 'in_progress',
            'scheduled_date' => '2026-08-05',
            'final_amount' => '250.00',
        ]);
        $servico = Service::create([
            'name' => 'Controle de pragas',
            'price' => '250.00',
            'is_active' => true,
        ]);
        $this->os->services()->attach($servico->id, ['observations' => 'Área interna']);

        $this->provedor = new ProvedorFiscalSimulado;
        $this->instalarProvedor($this->provedor);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_valida_codigo_ibge_antes_de_chamar_o_provedor(): void
    {
        $this->os->address->update(['codigo_municipio_ibge' => null]);

        $this->expectException(DadoFiscalInvalidoException::class);
        $this->expectExceptionMessage('Código do município (IBGE)');

        try {
            app(ServiceInvoiceService::class)->emitirDaOs($this->os);
        } finally {
            $this->assertSame(0, $this->provedor->emissoes);
            $this->assertSame('erro', ServiceInvoice::query()->firstOrFail()->situacao);
            $this->assertStringContainsString(
                'Código do município (IBGE)',
                ServiceInvoice::query()->firstOrFail()->erro_mensagem,
            );
        }
    }

    public function test_emite_da_os_com_payload_atual_calculo_em_centavos_e_competencia_da_os(): void
    {
        $nota = app(ServiceInvoiceService::class)->emitirDaOs($this->os);

        $this->assertSame('processando', $nota->situacao);
        $this->assertSame('250.00', $nota->valor_servico);
        $this->assertSame('12.50', $nota->valor_iss);
        $this->assertSame('250.00', $nota->valor_liquido);
        $this->assertSame('2026-08-05', $nota->competencia->toDateString());
        $this->assertSame('Controle de pragas: Área interna', $nota->descricao_servico);
        $this->assertSame('2026-08-05', $this->provedor->payload['dCompet']);
        $this->assertSame('04252011000110', $this->provedor->payload['toma']['CNPJ']);
        $this->assertSame('3550308', $this->provedor->payload['toma']['end']['endNac']['cMun']);
        $this->assertSame('3550308', $this->provedor->payload['serv']['locPrest']['cLocPrestacao']);
        $this->assertSame('0713', $this->provedor->payload['serv']['cServ']['cTribNac']);
        $this->assertSame('250.00', $this->provedor->payload['valores']['vServPrest']['vServ']);
        $this->assertSame('5.00', $this->provedor->payload['valores']['trib']['tribMun']['pAliq']);
    }

    public function test_valida_o_endereco_efetivamente_enviado_e_desconta_iss_somente_quando_retido(): void
    {
        $enderecoIncompleto = Address::create([
            'client_id' => $this->cliente->id,
            'nickname' => 'Filial',
            'street' => '',
            'number' => '200',
            'district' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip' => '01001-000',
            'codigo_municipio_ibge' => '3550308',
            'active' => true,
        ]);
        $this->os->update(['address_id' => $enderecoIncompleto->id]);

        try {
            app(ServiceInvoiceService::class)->emitirDaOs($this->os->refresh());
            $this->fail('Era esperada a recusa do endereço enviado na DPS.');
        } catch (DadoFiscalInvalidoException $falha) {
            $this->assertStringContainsString('Logradouro do endereço do cliente', $falha->getMessage());
            $this->assertSame(0, $this->provedor->emissoes);
        }

        $enderecoIncompleto->update(['street' => 'Rua da Filial']);
        $this->configuracao->update(['ativo' => false]);
        $this->configuracao = FiscalConfig::create([
            'provedor' => 'simulado',
            'ambiente' => 'homologacao',
            'credenciais' => ['token' => 'segredo-novo'],
            'regime_tributario' => 'simples_nacional',
            'codigo_servico' => '07.13',
            'aliquota_iss' => '4.37',
            'iss_retido' => true,
            'natureza_operacao' => 'tributacao_no_municipio',
            'ativo' => true,
        ]);
        $nota = app(ServiceInvoiceService::class)->emitirDaOs($this->os->refresh());

        $this->assertSame('10.93', $nota->valor_iss);
        $this->assertSame('239.07', $nota->valor_liquido);
        $this->assertSame(2, $this->provedor->payload['valores']['trib']['tribMun']['tpRetISSQN']);
    }

    public function test_emite_do_titulo_e_bloqueia_duplicidade_cruzada_com_a_os(): void
    {
        $titulo = $this->tituloDaOs();
        $primeira = app(ServiceInvoiceService::class)->emitirDoTitulo($titulo);
        $segunda = app(ServiceInvoiceService::class)->emitirDaOs($this->os);

        $this->assertTrue($primeira->is($segunda));
        $this->assertSame(1, ServiceInvoice::query()->count());
        $this->assertSame(1, $this->provedor->emissoes);
    }

    public function test_nota_pendente_existente_nao_e_enviada_uma_segunda_vez(): void
    {
        $pendente = ServiceInvoice::create([
            'fiscal_config_id' => $this->configuracao->id,
            'client_id' => $this->cliente->id,
            'address_id' => $this->os->address_id,
            'work_order_id' => $this->os->id,
            'situacao' => 'pendente',
            'valor_servico' => '250.00',
            'valor_iss' => '12.50',
            'valor_liquido' => '250.00',
            'descricao_servico' => 'Envio já reservado',
            'competencia' => '2026-08-05',
        ]);

        $resultado = app(ServiceInvoiceService::class)->emitirDaOs($this->os);

        $this->assertTrue($pendente->is($resultado));
        $this->assertSame(0, $this->provedor->emissoes);
        $this->assertSame(1, ServiceInvoice::query()->count());
    }

    public function test_nota_processando_e_emitida_preserva_idempotencia_apos_cadastro_fiscal_ser_invalidado(): void
    {
        $primeira = app(ServiceInvoiceService::class)->emitirDaOs($this->os);

        $this->assertSame('processando', $primeira->situacao);
        $this->assertSame(1, $this->provedor->emissoes);

        $this->os->address->update([
            'street' => '',
            'codigo_municipio_ibge' => null,
        ]);

        $segunda = app(ServiceInvoiceService::class)->emitirDaOs($this->os->refresh());

        $this->assertTrue($primeira->is($segunda));
        $this->assertSame(1, ServiceInvoice::query()->count());
        $this->assertSame(1, $this->provedor->emissoes);

        $this->provedor->resposta = new RespostaDeNfse(
            id: 'provedor-1',
            situacao: RespostaDeNfse::SITUACAO_AUTORIZADA,
            numero: '9001',
        );
        app(ServiceInvoiceService::class)->processarNotas();

        $terceira = app(ServiceInvoiceService::class)->emitirDaOs($this->os->refresh());

        $this->assertSame('emitida', $primeira->refresh()->situacao);
        $this->assertTrue($primeira->is($terceira));
        $this->assertSame(1, ServiceInvoice::query()->count());
        $this->assertSame(1, $this->provedor->emissoes);
    }

    public function test_reenvio_ambiguo_usa_o_payload_persistido_sem_reler_cadastro_ou_recriar_data(): void
    {
        $this->provedor->falhaNaEmissao = new PrefeituraIndisponivelException;
        $nota = app(ServiceInvoiceService::class)->emitirDaOs($this->os);
        $snapshot = $nota->payload_dps;

        $this->cliente->update(['name' => 'Nome alterado depois da tentativa']);
        $this->os->address->update([
            'street' => 'Rua alterada depois da tentativa',
            'codigo_municipio_ibge' => '3304557',
        ]);
        Carbon::setTestNow($nota->proxima_tentativa_em);
        $this->provedor->falhaNaEmissao = null;

        app(ServiceInvoiceService::class)->processarNotas();

        $this->assertSame($snapshot, $this->provedor->payload);
        $this->assertSame($snapshot['dhEmi'], $this->provedor->payload['dhEmi']);
        $this->assertSame('Tomador Fiscal', $this->provedor->payload['toma']['xNome']);
        $this->assertSame('Rua Fiscal', $this->provedor->payload['toma']['end']['xLgr']);
        $this->assertSame('3550308', $this->provedor->payload['serv']['locPrest']['cLocPrestacao']);
    }

    public function test_comando_recupera_nota_pendente_orfa_com_claim_e_referencia_idempotente(): void
    {
        $pendente = ServiceInvoice::create([
            'fiscal_config_id' => $this->configuracao->id,
            'client_id' => $this->cliente->id,
            'address_id' => $this->os->address_id,
            'work_order_id' => $this->os->id,
            'situacao' => 'pendente',
            'valor_servico' => '250.00',
            'valor_iss' => '12.50',
            'valor_liquido' => '250.00',
            'descricao_servico' => 'Envio interrompido após o commit',
            'competencia' => '2026-08-05',
        ]);

        $this->assertSame(1, app(ServiceInvoiceService::class)->processarNotas());

        $pendente->refresh();
        $this->assertSame('processando', $pendente->situacao);
        $this->assertSame('provedor-1', $pendente->provedor_id);
        $this->assertSame("nfse-{$this->empresa->id}-{$pendente->id}", $this->provedor->referencias[0]);
        $this->assertNull($pendente->processamento_token);
        $this->assertNull($pendente->processamento_bloqueado_ate);

        $pendente->update([
            'situacao' => 'pendente',
            'provedor_id' => null,
            'processamento_token' => 'outro-processo',
            'processamento_bloqueado_ate' => now()->addMinutes(5),
        ]);

        $this->assertSame(0, app(ServiceInvoiceService::class)->processarNotas());
        $this->assertSame(1, $this->provedor->emissoes);

        Carbon::setTestNow(now()->addMinutes(6));
        $this->assertSame(1, app(ServiceInvoiceService::class)->processarNotas());
        $this->assertSame($this->provedor->referencias[0], $this->provedor->referencias[1]);
        $this->assertSame('provedor-1', $pendente->refresh()->provedor_id);
    }

    public function test_usa_codigo_ibge_do_endereco_fiscal_de_cada_filial(): void
    {
        $filial = Address::create([
            'client_id' => $this->cliente->id,
            'nickname' => 'Filial Rio',
            'street' => 'Rua Carioca',
            'number' => '20',
            'district' => 'Centro',
            'city' => 'Rio de Janeiro',
            'state' => 'RJ',
            'zip' => '20040-020',
            'codigo_municipio_ibge' => '3304557',
            'active' => true,
        ]);
        $this->os->update(['address_id' => $filial->id]);
        $this->cliente->update(['codigo_municipio_ibge' => '3550308']);

        app(ServiceInvoiceService::class)->emitirDaOs($this->os->refresh());

        $this->assertSame('3304557', $this->provedor->payload['toma']['end']['endNac']['cMun']);
        $this->assertSame('3304557', $this->provedor->payload['serv']['locPrest']['cLocPrestacao']);
    }

    public function test_endereco_principal_incompleto_nao_reprova_outro_endereco_fiscal_completo(): void
    {
        $principal = $this->os->address;
        $principal->update(['zip' => '']);
        $filial = Address::create([
            'client_id' => $this->cliente->id,
            'nickname' => 'Filial completa',
            'street' => 'Rua Fiscal Dois',
            'number' => '300',
            'district' => 'Centro',
            'city' => 'Campinas',
            'state' => 'SP',
            'zip' => '13010-001',
            'codigo_municipio_ibge' => '3509502',
            'active' => true,
        ]);
        $this->os->update(['address_id' => $filial->id]);

        $nota = app(ServiceInvoiceService::class)->emitirDaOs($this->os->refresh());

        $this->assertSame('processando', $nota->situacao);
        $this->assertSame('3509502', $this->provedor->payload['toma']['end']['endNac']['cMun']);
    }

    public function test_nota_em_erro_permanente_fica_visivel_e_aceita_nova_emissao_apos_correcao(): void
    {
        $this->provedor->falhaNaEmissao = new DadoFiscalInvalidoException('Código de serviço recusado.');
        $erro = app(ServiceInvoiceService::class)->emitirDaOs($this->os);

        $this->assertSame('erro', $erro->situacao);
        $this->assertFalse($erro->erro_temporario);
        $this->assertStringContainsString('Código de serviço', $erro->erro_mensagem);
        $this->assertSame(1, $this->provedor->emissoes);

        Carbon::setTestNow(now()->addDay());
        app(ServiceInvoiceService::class)->processarNotas();
        $this->assertSame(1, $this->provedor->emissoes);

        $this->provedor->falhaNaEmissao = null;
        $nova = app(ServiceInvoiceService::class)->emitirDaOs($this->os);

        $this->assertNotSame($erro->id, $nova->id);
        $this->assertSame('erro', $erro->refresh()->situacao);
        $this->assertSame(2, ServiceInvoice::query()->count());
    }

    public function test_falha_interna_na_emissao_persiste_mensagem_publica_e_registra_a_excecao_original(): void
    {
        $falha = new RuntimeException('Undefined array key client_secret');
        $this->provedor->falhaNaEmissao = $falha;
        Log::spy();

        $nota = app(ServiceInvoiceService::class)->emitirDaOs($this->os);

        $this->assertSame('erro', $nota->situacao);
        $this->assertSame(MensagemFiscalPublica::FALHA_INTERNA, $nota->erro_mensagem);
        $this->assertFalse($nota->erro_temporario);
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $mensagem, array $contexto): bool => $mensagem === '[fiscal] Falha interna ocultada da resposta pública.'
                && $contexto['service_invoice_id'] === $nota->id
                && $contexto['operacao'] === 'emitir'
                && $contexto['exception'] === $falha,
        )->once();
    }

    public function test_polling_autoriza_e_salva_pdf_e_xml_em_diretorio_privado_do_tenant(): void
    {
        $nota = app(ServiceInvoiceService::class)->emitirDaOs($this->os);
        $this->provedor->resposta = new RespostaDeNfse(
            id: 'provedor-1',
            situacao: RespostaDeNfse::SITUACAO_AUTORIZADA,
            numero: '9001',
            codigoVerificacao: 'ABC123',
            emitidaEm: CarbonImmutable::parse('2026-08-07T14:30:00-03:00'),
        );

        $this->assertSame(1, app(ServiceInvoiceService::class)->processarNotas());
        $nota->refresh();

        $this->assertSame('emitida', $nota->situacao);
        $this->assertSame('9001', $nota->numero);
        $this->assertStringContainsString("empresa-{$this->empresa->id}/nota-{$nota->id}", $nota->pdf_path);
        Storage::disk('local')->assertExists($nota->pdf_path);
        Storage::disk('local')->assertExists($nota->xml_path);
        $this->assertSame('%PDF-simulado', Storage::disk('local')->get($nota->pdf_path));
    }

    public function test_polling_preserva_configuracao_original_e_bloqueia_mudanca_incompativel(): void
    {
        $nota = app(ServiceInvoiceService::class)->emitirDaOs($this->os);

        try {
            $this->configuracao->update(['ambiente' => 'producao']);
            $this->fail('Era esperado o bloqueio da mudança de ambiente durante o processamento.');
        } catch (\RuntimeException $falha) {
            $this->assertStringContainsString('não podem ser alterados', $falha->getMessage());
        }

        $this->configuracao->refresh();
        $this->configuracao->update(['ativo' => false]);
        $novaConfiguracao = FiscalConfig::create([
            'provedor' => 'simulado',
            'ambiente' => 'producao',
            'credenciais' => ['token' => 'novo'],
            'regime_tributario' => 'simples_nacional',
            'codigo_servico' => '07.13',
            'aliquota_iss' => '3.00',
            'natureza_operacao' => 'tributacao_no_municipio',
            'ativo' => true,
        ]);
        $this->provedor->resposta = new RespostaDeNfse(
            id: 'provedor-1',
            situacao: RespostaDeNfse::SITUACAO_AUTORIZADA,
            numero: '9002',
        );

        app(ServiceInvoiceService::class)->processarNotas();

        $this->assertSame('emitida', $nota->refresh()->situacao);
        $this->assertSame($this->configuracao->id, $nota->fiscal_config_id);
        $this->assertSame([$this->configuracao->id], $this->provedor->configuracoesConsultadas);
        $this->assertTrue($novaConfiguracao->refresh()->ativo);
        $this->assertFalse($this->configuracao->refresh()->ativo);
    }

    public function test_impede_duas_configuracoes_fiscais_ativas_no_tenant(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('já possui uma configuração fiscal ativa');

        FiscalConfig::create([
            'provedor' => 'simulado',
            'ambiente' => 'homologacao',
            'credenciais' => ['token' => 'duplicado'],
            'regime_tributario' => 'simples_nacional',
            'codigo_servico' => '07.13',
            'aliquota_iss' => '5.00',
            'natureza_operacao' => 'tributacao_no_municipio',
            'ativo' => true,
        ]);
    }

    public function test_erro_temporario_usa_espera_crescente_e_para_na_quinta_tentativa(): void
    {
        $this->provedor->falhaNaEmissao = new PrefeituraIndisponivelException;
        $nota = app(ServiceInvoiceService::class)->emitirDaOs($this->os);

        $this->assertSame(1, $nota->tentativas);
        $this->assertSame(1, $this->provedor->emissoes);
        $this->assertSame('2026-08-07 15:10:00', $nota->proxima_tentativa_em->format('Y-m-d H:i:s'));

        foreach ([2 => 20, 3 => 40, 4 => 80, 5 => null] as $tentativa => $espera) {
            Carbon::setTestNow($nota->proxima_tentativa_em ?? now());
            app(ServiceInvoiceService::class)->processarNotas();
            $nota->refresh();
            $this->assertSame($tentativa, $nota->tentativas);
            $this->assertSame($tentativa, $this->provedor->emissoes);

            if ($espera === null) {
                $this->assertNull($nota->proxima_tentativa_em);
            } else {
                $this->assertSame($espera, (int) now()->diffInMinutes($nota->proxima_tentativa_em));
            }
        }

        $this->assertSame(5, $this->provedor->emissoes);

        Carbon::setTestNow(now()->addDay());
        app(ServiceInvoiceService::class)->processarNotas();
        $this->assertSame(5, $nota->refresh()->tentativas);
        $this->assertSame(5, $this->provedor->emissoes);
    }

    public function test_gatilho_de_conclusao_funciona_nos_dois_caminhos_reais_da_os(): void
    {
        $this->configuracao->update([
            'emissao_automatica' => true,
            'gatilho_emissao_automatica' => 'conclusao_os',
        ]);
        $workOrders = app(WorkOrderService::class);

        $this->assertTrue($workOrders->updateWorkOrder($this->os, ['status' => 'completed']));
        $this->assertSame(1, ServiceInvoice::query()->count());

        $outra = WorkOrderFactory::new()->create([
            'client_id' => $this->cliente->id,
            'address_id' => $this->os->address_id,
            'status' => 'in_progress',
        ]);
        $this->assertTrue($workOrders->markAsCompleted($outra));
        $this->assertSame(2, ServiceInvoice::query()->count());
    }

    public function test_gatilho_de_quitacao_emite_no_ponto_real_da_baixa(): void
    {
        $this->configuracao->update([
            'emissao_automatica' => true,
            'gatilho_emissao_automatica' => 'quitacao_titulo',
        ]);
        $titulo = $this->tituloDaOs();
        $parcela = ReceivableInstallment::create([
            'receivable_id' => $titulo->id,
            'numero' => 1,
            'valor' => '250.00',
            'vencimento' => '2026-08-10',
            'valor_pago' => '0.00',
            'situacao' => 'aberta',
        ]);
        $usuario = User::factory()->create(['company_id' => $this->empresa->id]);

        app(SettlementService::class)->baixar($parcela, [
            'valor' => '250.00',
            'data' => '2026-08-07',
            'usuario' => $usuario,
        ]);

        $this->assertSame('quitado', $titulo->refresh()->situacao);
        $this->assertSame(1, ServiceInvoice::query()->where('receivable_id', $titulo->id)->count());
    }

    public function test_comando_isola_tenants_e_rotina_esta_agendada_e_auditada(): void
    {
        $nota = app(ServiceInvoiceService::class)->emitirDaOs($this->os);
        $outraEmpresa = Company::create(['name' => 'Outra empresa']);
        $notaOutra = TenantAtual::comTenant($outraEmpresa->id, function (): ServiceInvoice {
            $cliente = Client::create([
                'name' => 'Outro cliente',
                'email' => 'outro@cliente.test',
                'phone' => '11988887777',
                'cnpj' => '529.982.247-25',
            ]);
            $configuracao = FiscalConfig::create([
                'provedor' => 'simulado',
                'ambiente' => 'homologacao',
                'credenciais' => ['token' => 'outro'],
                'regime_tributario' => 'simples_nacional',
                'codigo_servico' => '07.13',
                'aliquota_iss' => '5.00',
                'natureza_operacao' => 'tributacao_no_municipio',
                'ativo' => true,
            ]);

            return ServiceInvoice::create([
                'fiscal_config_id' => $configuracao->id,
                'client_id' => $cliente->id,
                'situacao' => 'processando',
                'provedor_id' => 'outro-provedor',
                'valor_servico' => '100.00',
                'valor_iss' => '5.00',
                'valor_liquido' => '95.00',
                'descricao_servico' => 'Outra nota',
                'competencia' => '2026-08-07',
            ]);
        });
        $this->provedor->resposta = new RespostaDeNfse('provedor-1', RespostaDeNfse::SITUACAO_PROCESSANDO);

        $this->artisan('fiscal:processar-notas', ['--company' => $this->empresa->id])->assertSuccessful();
        $this->assertSame('processando', $nota->refresh()->situacao);
        $this->assertSame('processando', TenantAtual::comTenant($outraEmpresa->id, fn () => $notaOutra->refresh()->situacao));

        $this->artisan('schedule:list')->assertSuccessful();
        $evento = collect($this->app->make(Schedule::class)->events())->first(
            fn ($item): bool => is_string($item->command) && str_contains($item->command, 'fiscal:processar-notas')
        );
        $this->assertNotNull($evento);
        $this->assertSame('*/10 * * * *', $evento->expression);
        $this->assertSame(10, RotinasAgendadas::POR_INTERVALO['fiscal:processar-notas']);

        ServiceInvoice::query()->update(['situacao' => 'emitida']);
        $this->artisan('schedule:test', ['--name' => 'fiscal:processar-notas'])->assertSuccessful();
        $this->assertTrue(ScheduledTaskRun::query()->daTarefa('fiscal:processar-notas')->exists());
    }

    private function tituloDaOs(): Receivable
    {
        return Receivable::create([
            'client_id' => $this->cliente->id,
            'work_order_id' => $this->os->id,
            'descricao' => 'Serviço da OS',
            'valor_total' => '250.00',
            'emitido_em' => '2026-08-06',
            'situacao' => 'aberto',
        ]);
    }

    private function instalarProvedor(ProvedorFiscalSimulado $provedor): void
    {
        $resolvedor = Mockery::mock(ResolvedorDeProvedor::class);
        $resolvedor->shouldReceive('configuracaoAtiva')
            ->andReturnUsing(fn (): FiscalConfig => FiscalConfig::query()->where('ativo', true)->firstOrFail());
        $resolvedor->shouldReceive('paraConfiguracao')->andReturn($provedor);
        $this->app->instance(ResolvedorDeProvedor::class, $resolvedor);
    }
}

class ProvedorFiscalSimulado implements ProvedorDeNfse
{
    public int $emissoes = 0;

    /** @var array<string, mixed> */
    public array $payload = [];

    /** @var array<int, string> */
    public array $referencias = [];

    /** @var array<int, int> */
    public array $configuracoesConsultadas = [];

    /** @var array<string, string> */
    private array $idsPorReferencia = [];

    public ?\Throwable $falhaNaEmissao = null;

    public RespostaDeNfse $resposta;

    public function __construct()
    {
        $this->resposta = new RespostaDeNfse('provedor-1', RespostaDeNfse::SITUACAO_PROCESSANDO);
    }

    public function emitir(FiscalConfig $configuracao, array $dadosDaDps, string $referencia): string
    {
        $this->emissoes++;
        $this->payload = $dadosDaDps;
        $this->referencias[] = $referencia;

        if ($this->falhaNaEmissao !== null) {
            throw $this->falhaNaEmissao;
        }

        return $this->idsPorReferencia[$referencia] ??= 'provedor-'.(count($this->idsPorReferencia) + 1);
    }

    public function consultar(FiscalConfig $configuracao, string $idNoProvedor): RespostaDeNfse
    {
        $this->configuracoesConsultadas[] = $configuracao->id;

        return $this->resposta;
    }

    public function cancelar(FiscalConfig $configuracao, string $idNoProvedor, string $motivo, ?string $codigo = null): RespostaDeCancelamento
    {
        return new RespostaDeCancelamento('cancelamento-1', 'pendente');
    }

    public function consultarCancelamento(FiscalConfig $configuracao, string $idNoProvedor): RespostaDeCancelamento
    {
        return new RespostaDeCancelamento('cancelamento-1', 'pendente');
    }

    public function substituir(FiscalConfig $configuracao, string $idNoProvedorDaNotaSubstituida, string $codigoMotivo, string $motivo, array $dadosDaDps, string $referencia): string
    {
        return 'substituta-1';
    }

    public function baixarPdf(FiscalConfig $configuracao, string $idNoProvedor): string
    {
        return '%PDF-simulado';
    }

    public function baixarXml(FiscalConfig $configuracao, string $idNoProvedor): string
    {
        return '<NFS-e>simulada</NFS-e>';
    }

    public function validarCredenciais(FiscalConfig $configuracao): bool
    {
        return true;
    }
}
