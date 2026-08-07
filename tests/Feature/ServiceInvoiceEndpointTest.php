<?php

namespace Tests\Feature;

use App\Console\Commands\SyncPermissions;
use App\Exceptions\DadoFiscalInvalidoException;
use App\Models\Address;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Company;
use App\Models\FiscalConfig;
use App\Models\Module;
use App\Models\Receivable;
use App\Models\ServiceInvoice;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Fiscal\ProvedorDeNfse;
use App\Services\Fiscal\ResolvedorDeProvedor;
use App\Services\ModuleService;
use App\Support\Fiscal\MensagemFiscalPublica;
use App\Support\Fiscal\RespostaDeCancelamento;
use App\Support\Fiscal\RespostaDeNfse;
use App\Support\TenantAtual;
use Closure;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class ServiceInvoiceEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private User $administrador;

    private Client $cliente;

    private Address $endereco;

    private WorkOrder $os;

    private FiscalConfig $configuracao;

    private ProvedorFiscalDoEndpoint $provedor;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        Storage::fake('local');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ModulesSeeder::class);
        $this->empresa = Company::query()->firstOrFail();
        $this->empresa->update(['cnpj' => '11.444.777/0001-61']);

        TenantAtual::comTenant($this->empresa->id, function (): void {
            $this->administrador = User::factory()->create(['is_active' => true]);
            $this->administrador->assignRole('administrador');
            $this->cliente = ClientFactory::new()->create([
                'name' => 'Tomador Fiscal',
                'cnpj' => '04.252.011/0001-10',
                'codigo_municipio_ibge' => '3550308',
            ]);
            $this->endereco = AddressFactory::new()->create([
                'client_id' => $this->cliente->id,
                'zip' => '01001-000',
                'codigo_municipio_ibge' => '3550308',
            ]);
            $this->os = WorkOrder::factory()->create([
                'client_id' => $this->cliente->id,
                'address_id' => $this->endereco->id,
                'scheduled_date' => '2026-08-01',
                'description' => 'Controle integrado de pragas',
                'final_amount' => '250.00',
            ]);
            $this->configuracao = FiscalConfig::create([
                'provedor' => 'nuvem_fiscal',
                'ambiente' => 'homologacao',
                'credenciais' => ['client_id' => 'id-atual', 'client_secret' => 'segredo-atual'],
                'regime_tributario' => 'simples_nacional',
                'codigo_servico' => '07.13',
                'aliquota_iss' => '5.00',
                'natureza_operacao' => 'tributacao_no_municipio',
                'ativo' => true,
            ]);
        });

        $this->provedor = new ProvedorFiscalDoEndpoint;
        $resolvedor = Mockery::mock(ResolvedorDeProvedor::class);
        $resolvedor->shouldReceive('configuracaoAtiva')
            ->andReturnUsing(fn (): FiscalConfig => FiscalConfig::query()->where('ativo', true)->firstOrFail());
        $resolvedor->shouldReceive('paraConfiguracao')->andReturn($this->provedor);
        $resolvedor->shouldReceive('validar')->andReturnUsing(fn (FiscalConfig $configuracao): bool => $this->provedor->validarCredenciais($configuracao));
        $this->app->instance(ResolvedorDeProvedor::class, $resolvedor);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    public function test_emite_da_os_e_do_titulo_com_origens_exclusivas(): void
    {
        $respostaOs = $this->actingAs($this->administrador)->postJson('/notas', ['work_order_id' => $this->os->id]);
        $respostaOs->assertCreated()
            ->assertJsonPath('resultado', 'criada')
            ->assertJsonPath('resultado_fiscal', 'pendente')
            ->assertJsonPath('nota.situacao', 'processando');

        $this->postJson('/notas', ['work_order_id' => $this->os->id])
            ->assertOk()
            ->assertJsonPath('resultado', 'reutilizada')
            ->assertJsonPath('nota.id', $respostaOs->json('nota.id'));

        $titulo = TenantAtual::comTenant($this->empresa->id, fn (): Receivable => Receivable::create([
            'client_id' => $this->cliente->id,
            'descricao' => 'Serviço fiscal avulso',
            'valor_total' => '180.00',
            'emitido_em' => '2026-08-02',
            'situacao' => 'aberto',
        ]));

        $this->actingAs($this->administrador)
            ->postJson('/notas', ['receivable_id' => $titulo->id])
            ->assertCreated()
            ->assertJsonPath('nota.receivable_id', $titulo->id);

        $this->postJson('/notas', ['work_order_id' => $this->os->id, 'receivable_id' => $titulo->id])
            ->assertUnprocessable();
    }

    public function test_lista_filtra_situacao_periodo_e_cliente_sem_expor_dados_internos(): void
    {
        $outroCliente = TenantAtual::comTenant($this->empresa->id, fn (): Client => ClientFactory::new()->create());

        TenantAtual::comTenant($this->empresa->id, function () use ($outroCliente): void {
            $this->criarNota(['situacao' => 'emitida', 'competencia' => '2026-08-01', 'payload_dps' => ['segredo' => true]]);
            $this->criarNota(['client_id' => $outroCliente->id, 'situacao' => 'cancelada', 'competencia' => '2026-07-01']);
        });

        $resposta = $this->actingAs($this->administrador)
            ->getJson("/notas?situacao=emitida&de=2026-08-01&ate=2026-08-31&client_id={$this->cliente->id}")
            ->assertOk()
            ->assertJsonPath('ambiente', 'homologacao');

        $this->assertCount(1, $resposta->json('notas.data'));
        $nota = $resposta->json('notas.data.0');
        $this->assertSame('emitida', $nota['situacao']);
        $this->assertArrayNotHasKey('payload_dps', $nota);
        $this->assertArrayNotHasKey('pdf_path', $nota);
        $this->assertArrayNotHasKey('fiscal_config_id', $nota);
    }

    public function test_pendencias_agrupam_motivo_e_lote_ignora_substituicao(): void
    {
        [$normal, $substituta, $codigoRecusado] = TenantAtual::comTenant($this->empresa->id, function (): array {
            $normal = $this->criarNota([
                'work_order_id' => $this->os->id,
                'situacao' => 'erro',
                'erro_mensagem' => 'Preencha o campo Código IBGE.',
            ]);
            $substituta = $this->criarNota([
                'situacao' => 'erro',
                'erro_mensagem' => 'Preencha o campo Código IBGE.',
                'metadados_substituicao' => ['motivo' => 'Correção fiscal necessária'],
            ]);
            $codigoRecusado = $this->criarNota([
                'situacao' => 'erro',
                'erro_mensagem' => 'Código de serviço recusado.',
            ]);

            return [$normal, $substituta, $codigoRecusado];
        });

        $grupos = collect($this->actingAs($this->administrador)
            ->getJson('/notas/pendencias')
            ->assertOk()
            ->assertJsonPath('ambiente', 'homologacao')
            ->json('grupos'))
            ->keyBy('motivo');

        $this->assertCount(2, $grupos);
        $grupoIbge = $grupos->get('Preencha o campo Código IBGE.');
        $this->assertIsArray($grupoIbge);
        $this->assertSame(2, $grupoIbge['contagem']);
        $this->assertSame([$normal->id, $substituta->id], $grupoIbge['nota_ids']);
        $this->assertSame(1, $grupoIbge['reprocessaveis_em_lote']);
        $this->assertSame($this->cliente->id, $grupoIbge['clientes'][0]['id']);
        $this->assertSame([$normal->id], $grupoIbge['clientes'][0]['nota_ids']);

        $grupoCodigo = $grupos->get('Código de serviço recusado.');
        $this->assertIsArray($grupoCodigo);
        $this->assertSame(1, $grupoCodigo['contagem']);
        $this->assertSame([$codigoRecusado->id], $grupoCodigo['nota_ids']);
        $this->assertSame(1, $grupoCodigo['reprocessaveis_em_lote']);
        $this->assertSame([$codigoRecusado->id], $grupoCodigo['clientes'][0]['nota_ids']);

        $resultado = $this->postJson('/notas/pendencias/reprocessar', [
            'nota_ids' => [$normal->id, $substituta->id],
        ])->assertOk();

        $this->assertCount(1, $resultado->json('processadas'));
        $this->assertSame($normal->id, $resultado->json('processadas.0.pendencia_id'));
        $this->assertSame('pendente', $resultado->json('processadas.0.resultado_fiscal'));
        $this->assertNotNull($normal->refresh()->reprocessada_por_id);

        $gruposDepois = collect($this->getJson('/notas/pendencias')->assertOk()->json('grupos'))
            ->keyBy('motivo');
        $this->assertCount(2, $gruposDepois);
        $this->assertSame(1, $gruposDepois['Preencha o campo Código IBGE.']['contagem']);
        $this->assertSame([$substituta->id], $gruposDepois['Preencha o campo Código IBGE.']['nota_ids']);
        $this->assertSame(0, $gruposDepois['Preencha o campo Código IBGE.']['reprocessaveis_em_lote']);
        $this->assertSame(1, $gruposDepois['Código de serviço recusado.']['contagem']);
        $this->assertSame([$codigoRecusado->id], $gruposDepois['Código de serviço recusado.']['nota_ids']);
        $this->assertSame(1, $gruposDepois['Código de serviço recusado.']['reprocessaveis_em_lote']);
    }

    public function test_reprocessamento_individual_encadeia_historico_e_e_idempotente(): void
    {
        $pendencia = TenantAtual::comTenant($this->empresa->id, fn (): ServiceInvoice => $this->criarNota([
            'work_order_id' => $this->os->id,
            'situacao' => 'erro',
            'erro_mensagem' => 'Corrija os dados fiscais do tomador.',
        ]));

        $primeira = $this->actingAs($this->administrador)
            ->postJson("/notas/{$pendencia->id}/reprocessar")
            ->assertOk()
            ->assertJsonPath('resultado_fiscal', 'pendente')
            ->assertJsonPath('nota.situacao', 'processando');

        $novaId = $primeira->json('nota.id');
        $this->assertSame($novaId, $pendencia->refresh()->reprocessada_por_id);
        $this->assertNull(ServiceInvoice::query()->findOrFail($novaId)->substituida_por_id);

        $this->postJson("/notas/{$pendencia->id}/reprocessar")
            ->assertOk()
            ->assertJsonPath('nota.id', $novaId)
            ->assertJsonPath('resultado_fiscal', 'pendente');

        $this->assertSame(2, TenantAtual::comTenant(
            $this->empresa->id,
            fn (): int => ServiceInvoice::query()->where('work_order_id', $this->os->id)->count(),
        ));
        $this->getJson('/notas/pendencias')->assertOk()->assertJsonCount(0, 'grupos');
    }

    public function test_reprocessamento_rele_e_bloqueia_a_pendencia_antes_de_criar_outra_tentativa(): void
    {
        $conexaoOriginal = DB::getDefaultConnection();
        $configuracaoOriginal = config("database.connections.{$conexaoOriginal}");
        $conexoesOriginais = config('database.connections');
        $conexaoPadraoOriginal = config('database.default');
        $bancoTemporario = 'testing_nfse_'.getmypid().'_'.strtolower(bin2hex(random_bytes(4)));
        $administrativa = null;
        $bancoCriado = false;

        try {
            $configuracaoBase = [...$configuracaoOriginal, 'url' => null];
            $configuracaoAdministrativa = [...$configuracaoBase, 'database' => $configuracaoOriginal['database']];
            $configuracaoIsolada = [...$configuracaoBase, 'database' => $bancoTemporario];
            config([
                'database.connections.nfse_administrativa' => $configuracaoAdministrativa,
                'database.connections.nfse_isolada' => $configuracaoIsolada,
                'database.connections.nfse_concorrente' => $configuracaoIsolada,
            ]);
            DB::purge('nfse_administrativa');
            DB::purge('nfse_isolada');
            DB::purge('nfse_concorrente');

            $administrativa = DB::connection('nfse_administrativa');
            $contagensPersistidasAntes = [
                'companies' => $administrativa->table('companies')->count(),
                'users' => $administrativa->table('users')->count(),
                'clients' => $administrativa->table('clients')->count(),
            ];
            $administrativa->statement('CREATE DATABASE '.$bancoTemporario);
            $bancoCriado = true;

            DB::setDefaultConnection('nfse_isolada');
            Artisan::call('migrate', ['--database' => 'nfse_isolada', '--force' => true]);

            $isolada = DB::connection('nfse_isolada');
            $concorrente = DB::connection('nfse_concorrente');
            $isolada->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $concorrente->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

            foreach ([true, false] as $dadosValidos) {
                $empresaId = $this->criarCenarioConcorrente($isolada, $dadosValidos);
                $this->provarReprocessamentoConcorrente($isolada, $concorrente, $empresaId, $dadosValidos);
            }

            $administrativa->statement('DROP DATABASE '.$bancoTemporario);
            $bancoCriado = false;
            $this->assertSame($contagensPersistidasAntes, [
                'companies' => $administrativa->table('companies')->count(),
                'users' => $administrativa->table('users')->count(),
                'clients' => $administrativa->table('clients')->count(),
            ], 'o DDL do banco temporário não pode confirmar os dados transacionados do setUp');
        } finally {
            try {
                if ($bancoCriado && $administrativa instanceof Connection) {
                    $administrativa->statement('DROP DATABASE IF EXISTS '.$bancoTemporario);
                }
            } finally {
                DB::purge('nfse_administrativa');
                DB::purge('nfse_isolada');
                DB::purge('nfse_concorrente');
                DB::setDefaultConnection($conexaoOriginal);
                config([
                    'database.connections' => $conexoesOriginais,
                    'database.default' => $conexaoPadraoOriginal,
                ]);
            }
        }
    }

    public function test_falha_no_reprocessamento_mantem_so_a_nova_pendencia_ativa_e_mensagem_segura(): void
    {
        $pendencia = TenantAtual::comTenant($this->empresa->id, fn (): ServiceInvoice => $this->criarNota([
            'work_order_id' => $this->os->id,
            'situacao' => 'erro',
            'erro_mensagem' => 'Pendência anterior.',
        ]));
        $this->provedor->falhaAoEmitir = new RuntimeException('SQLSTATE[HY000] /Users/segredo/config.php');

        $resposta = $this->actingAs($this->administrador)
            ->postJson("/notas/{$pendencia->id}/reprocessar")
            ->assertOk()
            ->assertJsonPath('resultado_fiscal', 'erro')
            ->assertJsonPath('nota.erro_mensagem', MensagemFiscalPublica::FALHA_INTERNA);

        $novaId = $resposta->json('nota.id');
        $this->assertSame($novaId, $pendencia->refresh()->reprocessada_por_id);
        $this->assertSame('erro', ServiceInvoice::query()->findOrFail($novaId)->situacao);
        $this->assertStringNotContainsString('SQLSTATE', (string) $resposta->getContent());

        $grupos = $this->getJson('/notas/pendencias')->assertOk()->json('grupos');
        $this->assertCount(1, $grupos);
        $this->assertSame([$novaId], $grupos[0]['nota_ids']);
    }

    public function test_reprocessamento_de_outro_tenant_retorna_404(): void
    {
        $pendencia = TenantAtual::comTenant($this->empresa->id, fn (): ServiceInvoice => $this->criarNota([
            'situacao' => 'erro',
        ]));
        [, $outroAdmin] = $this->outroTenantComModulo();

        $this->actingAs($outroAdmin)
            ->postJson("/notas/{$pendencia->id}/reprocessar")
            ->assertNotFound();
        $this->assertNull(TenantAtual::comTenant(
            $this->empresa->id,
            fn () => ServiceInvoice::query()->findOrFail($pendencia->id)->reprocessada_por_id,
        ));
    }

    public function test_lote_preserva_falha_fiscal_e_oculta_falha_interna(): void
    {
        [$conhecida, $interna] = TenantAtual::comTenant($this->empresa->id, fn (): array => [
            $this->criarNota(['situacao' => 'erro']),
            $this->criarNota(['situacao' => 'erro']),
        ]);
        $servico = Mockery::mock(\App\Services\ServiceInvoiceService::class);
        $servico->shouldReceive('reemitir')->twice()->andReturnUsing(
            function (ServiceInvoice $nota) use ($conhecida): never {
                if ($nota->is($conhecida)) {
                    throw new DadoFiscalInvalidoException('Corrija o código do município do tomador.');
                }

                throw new RuntimeException('SQLSTATE[HY000] arquivo /Users/privado/app.php:42');
            },
        );
        $this->app->instance(\App\Services\ServiceInvoiceService::class, $servico);
        Log::spy();

        $resposta = $this->actingAs($this->administrador)
            ->postJson('/notas/pendencias/reprocessar', [
                'nota_ids' => [$conhecida->id, $interna->id],
            ])
            ->assertOk();

        $this->assertSame('Corrija o código do município do tomador.', $resposta->json('falhas.0.message'));
        $this->assertSame(MensagemFiscalPublica::FALHA_INTERNA, $resposta->json('falhas.1.message'));
        $this->assertStringNotContainsString('SQLSTATE', (string) $resposta->getContent());
        Log::shouldHaveReceived('error')->once();
    }

    public function test_serializacao_oculta_detalhe_interno_persistido(): void
    {
        TenantAtual::comTenant($this->empresa->id, function (): void {
            foreach ([
                'SQLSTATE[HY000] falha em /Users/privado/app.php:42',
                'Undefined array key client_secret',
                'Call to undefined method Provedor::token()',
                'Connection refused tcp://127.0.0.1:3306',
            ] as $mensagem) {
                $this->criarNota([
                    'situacao' => 'erro',
                    'erro_mensagem' => $mensagem,
                ]);
            }
        });

        $resposta = $this->actingAs($this->administrador)->getJson('/notas')->assertOk();

        $this->assertSame(
            array_fill(0, 4, MensagemFiscalPublica::FALHA_INTERNA),
            array_column($resposta->json('notas.data'), 'erro_mensagem'),
        );
        $this->assertStringNotContainsString('SQLSTATE', (string) $resposta->getContent());
        $this->assertStringNotContainsString('client_secret', (string) $resposta->getContent());
        $this->assertStringNotContainsString('undefined method', mb_strtolower((string) $resposta->getContent()));
        $this->assertStringNotContainsString('tcp://', (string) $resposta->getContent());

        $pendencias = $this->getJson('/notas/pendencias')->assertOk();
        $this->assertSame(MensagemFiscalPublica::FALHA_INTERNA, $pendencias->json('grupos.0.motivo'));
        $this->assertSame(4, $pendencias->json('grupos.0.contagem'));
    }

    public function test_endpoints_nao_expoem_falhas_internas_capturadas_na_emissao_cancelamento_e_substituicao(): void
    {
        $this->provedor->falhaAoEmitir = new RuntimeException('Undefined array key client_secret');

        $emissao = $this->actingAs($this->administrador)
            ->postJson('/notas', ['work_order_id' => $this->os->id])
            ->assertCreated()
            ->assertJsonPath('resultado_fiscal', 'erro');
        $this->assertSame(MensagemFiscalPublica::FALHA_INTERNA, $emissao->json('nota.erro_mensagem'));
        $this->assertStringNotContainsString('client_secret', (string) $emissao->getContent());
        $this->provedor->falhaAoEmitir = null;

        [$cancelavel, $substituivel] = TenantAtual::comTenant($this->empresa->id, fn (): array => [
            $this->criarNota(['situacao' => 'emitida', 'provedor_id' => 'nota-interna-cancelamento']),
            $this->criarNota(['situacao' => 'emitida', 'provedor_id' => 'nota-interna-substituicao']),
        ]);
        $this->provedor->falhaAoCancelar = new RuntimeException('Call to undefined method Provedor::cancelarAgora()');

        $cancelamento = $this->postJson("/notas/{$cancelavel->id}/cancelar", [
            'motivo' => 'Documento fiscal emitido com tomador incorreto',
        ])->assertUnprocessable();
        $this->assertSame(MensagemFiscalPublica::FALHA_INTERNA, $cancelamento->json('errors.nota.0'));
        $this->assertSame(MensagemFiscalPublica::FALHA_INTERNA, $cancelavel->refresh()->erro_mensagem);
        $this->assertStringNotContainsString('undefined method', mb_strtolower((string) $cancelamento->getContent()));
        $this->provedor->falhaAoCancelar = null;
        $this->provedor->falhaAoSubstituir = new RuntimeException('Connection refused tcp://127.0.0.1:443');

        $substituicao = $this->postJson("/notas/{$substituivel->id}/substituir", [
            'motivo' => 'Correção da descrição do serviço prestado',
            'descricao_servico' => 'Controle integrado de pragas corrigido',
        ])->assertUnprocessable();
        $this->assertSame(MensagemFiscalPublica::FALHA_INTERNA, $substituicao->json('errors.nota.0'));
        $substituta = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): ServiceInvoice => ServiceInvoice::query()->findOrFail($substituivel->refresh()->substituida_por_id),
        );
        $this->assertSame(MensagemFiscalPublica::FALHA_INTERNA, $substituta->erro_mensagem);
        $this->assertStringNotContainsString('tcp://', (string) $substituicao->getContent());

        $this->provedor->falhaAoValidar = new RuntimeException('Undefined array key client_secret');
        $configuracao = $this->putJson('/fiscal/configuracao', ['emissao_automatica' => true])
            ->assertUnprocessable();
        $this->assertSame(MensagemFiscalPublica::FALHA_INTERNA, $configuracao->json('errors.credenciais.0'));
        $this->assertStringNotContainsString('client_secret', (string) $configuracao->getContent());
    }

    public function test_download_privado_tem_nome_legivel_e_outro_tenant_recebe_404(): void
    {
        $nota = TenantAtual::comTenant($this->empresa->id, function (): ServiceInvoice {
            $nota = $this->criarNota(['situacao' => 'cancelada', 'numero' => '2026/15']);
            $nota->update([
                'pdf_path' => "fiscal/empresa-{$this->empresa->id}/nota-{$nota->id}/nfse.pdf",
                'xml_path' => "fiscal/empresa-{$this->empresa->id}/nota-{$nota->id}/nfse.xml",
            ]);
            Storage::disk('local')->put($nota->pdf_path, '%PDF');
            Storage::disk('local')->put($nota->xml_path, '<xml/>');

            return $nota->refresh();
        });

        $this->actingAs($this->administrador)
            ->get("/notas/{$nota->id}/pdf")
            ->assertDownload('NFS-e-2026-15.pdf');
        $this->get("/notas/{$nota->id}/xml")->assertDownload('NFS-e-2026-15.xml');

        [$outraEmpresa, $outroAdmin] = $this->outroTenantComModulo();
        $this->actingAs($outroAdmin)->get("/notas/{$nota->id}/pdf")->assertNotFound();
        $this->assertNotSame($this->empresa->id, $outraEmpresa->id);
    }

    public function test_cancelar_e_substituir_passam_pelo_service_e_expoem_a_cadeia(): void
    {
        [$cancelavel, $substituivel] = TenantAtual::comTenant($this->empresa->id, function (): array {
            return [
                $this->criarNota(['situacao' => 'emitida', 'provedor_id' => 'nota-cancelavel']),
                $this->criarNota(['situacao' => 'emitida', 'provedor_id' => 'nota-substituivel']),
            ];
        });

        $this->actingAs($this->administrador)
            ->postJson("/notas/{$cancelavel->id}/cancelar", [
                'motivo' => 'Documento fiscal emitido com tomador incorreto',
            ])
            ->assertOk()
            ->assertJsonPath('nota.situacao', 'cancelada');

        $substituicao = $this->postJson("/notas/{$substituivel->id}/substituir", [
            'motivo' => 'Correção da descrição do serviço prestado',
            'descricao_servico' => 'Controle integrado de pragas corrigido',
        ])->assertCreated()->assertJsonPath('resultado', 'criada');

        $idNova = $substituicao->json('nota.id');
        $this->assertSame('processando', $substituicao->json('nota.situacao'));
        $this->assertSame($substituivel->id, $substituicao->json('nota.cadeia.substitui'));
        $this->assertSame($idNova, $substituivel->refresh()->substituida_por_id);

        $this->postJson("/notas/{$substituivel->id}/substituir", [
            'motivo' => 'Correção da descrição do serviço prestado',
            'descricao_servico' => 'Controle integrado de pragas corrigido',
        ])->assertOk()
            ->assertJsonPath('resultado', 'reutilizada')
            ->assertJsonPath('nota.id', $idNova);
    }

    public function test_cancelamento_pendente_retorna_202_e_estado_real(): void
    {
        $nota = TenantAtual::comTenant($this->empresa->id, fn (): ServiceInvoice => $this->criarNota([
            'situacao' => 'emitida',
            'provedor_id' => 'nota-pendente',
        ]));
        $this->provedor->situacaoCancelamento = 'processando';

        $this->actingAs($this->administrador)
            ->postJson("/notas/{$nota->id}/cancelar", [
                'motivo' => 'Documento fiscal emitido com tomador incorreto',
            ])
            ->assertAccepted()
            ->assertJsonPath('resultado', 'pendente')
            ->assertJsonPath('nota.situacao', 'cancelamento_pendente')
            ->assertJsonPath('message', 'Cancelamento fiscal solicitado e aguardando autorização.');
    }

    public function test_configuracao_oculta_credencial_e_troca_configuracao_usada(): void
    {
        TenantAtual::comTenant($this->empresa->id, fn () => $this->criarNota(['situacao' => 'emitida']));

        $antes = $this->actingAs($this->administrador)->getJson('/fiscal/configuracao')->assertOk();
        $this->assertArrayNotHasKey('credenciais', $antes->json('configuracao'));
        $this->assertArrayNotHasKey('client_id', $antes->json('configuracao'));

        $operacional = $this->putJson('/fiscal/configuracao', ['emissao_automatica' => true])->assertOk();
        $this->assertSame($this->configuracao->id, $operacional->json('configuracao.id'));
        $this->assertTrue($operacional->json('configuracao.emissao_automatica'));

        $salva = $this->putJson('/fiscal/configuracao', ['codigo_servico' => '07.14'])
            ->assertOk();
        $this->assertArrayNotHasKey('credenciais', $salva->json('configuracao'));

        foreach ([$antes, $operacional, $salva] as $resposta) {
            $corpo = (string) $resposta->getContent();
            $this->assertStringNotContainsString('segredo-atual', $corpo);
            $this->assertStringNotContainsString('id-atual', $corpo);
            $this->assertStringNotContainsString('client_secret', $corpo);
        }

        TenantAtual::comTenant($this->empresa->id, function (): void {
            $this->assertSame(2, FiscalConfig::query()->count());
            $this->assertFalse($this->configuracao->refresh()->ativo);
            $this->assertSame('07.14', FiscalConfig::query()->where('ativo', true)->firstOrFail()->codigo_servico);
        });

        $this->assertSame('segredo-atual', $this->provedor->ultimoSegredoValidado);
        $this->assertSame(2, $this->provedor->validacoes);
    }

    public function test_configuracao_operacional_so_e_persistida_apos_validar_credencial(): void
    {
        $this->provedor->aceitarCredenciais = false;

        $this->actingAs($this->administrador)
            ->putJson('/fiscal/configuracao', ['emissao_automatica' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credenciais');

        $this->putJson('/fiscal/configuracao', ['codigo_servico' => '07.14'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credenciais');

        $this->assertFalse($this->configuracao->refresh()->emissao_automatica);
        $this->assertSame('07.13', $this->configuracao->codigo_servico);
        $this->assertSame(1, TenantAtual::comTenant(
            $this->empresa->id,
            fn (): int => FiscalConfig::query()->count(),
        ));
    }

    public function test_troca_fiscal_preserva_atualizacao_operacional_concorrente(): void
    {
        $this->provedor->aoValidar = function (): void {
            FiscalConfig::query()
                ->where('ativo', true)
                ->update([
                    'emissao_automatica' => true,
                    'gatilho_emissao_automatica' => 'quitacao_titulo',
                ]);
        };

        $resposta = $this->actingAs($this->administrador)
            ->putJson('/fiscal/configuracao', ['codigo_servico' => '07.14'])
            ->assertOk();

        $this->assertTrue($resposta->json('configuracao.emissao_automatica'));
        $this->assertSame('quitacao_titulo', $resposta->json('configuracao.gatilho_emissao_automatica'));
        $ativa = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): FiscalConfig => FiscalConfig::query()->where('ativo', true)->firstOrFail(),
        );
        $this->assertTrue($ativa->emissao_automatica);
        $this->assertSame('quitacao_titulo', $ativa->gatilho_emissao_automatica);
        $this->assertSame('07.14', $ativa->codigo_servico);
    }

    public function test_permissoes_e_modulo_barram_acesso(): void
    {
        $catalogo = collect(SyncPermissions::catalogo())->flatten()->all();
        $this->assertContains('fiscal-ver', $catalogo);
        $this->assertContains('fiscal-emitir', $catalogo);
        $this->assertContains('fiscal-cancelar', $catalogo);
        $this->assertContains('fiscal-configurar', $catalogo);

        $semPermissao = TenantAtual::comTenant($this->empresa->id, fn (): User => User::factory()->create(['is_active' => true]));
        $this->actingAs($semPermissao)->getJson('/notas')->assertForbidden();

        $empresaSemModulo = Company::create(['name' => 'Empresa sem NFS-e', 'email' => 'sem-nfse@teste.local']);
        $adminSemModulo = TenantAtual::comTenant($empresaSemModulo->id, function (): User {
            $usuario = User::factory()->create(['is_active' => true]);
            $usuario->assignRole('administrador');

            return $usuario;
        });
        app(ModuleService::class)->esquecerCache();

        $this->actingAs($adminSemModulo)
            ->getJson('/notas')
            ->assertForbidden()
            ->assertJsonPath('modulo', 'nfse');

        $usuarioPortal = TenantAtual::comTenant($empresaSemModulo->id, function (): ClientUser {
            $cliente = ClientFactory::new()->create();

            return ClientUser::create([
                'client_id' => $cliente->id,
                'nome' => 'Cliente sem NFS-e',
                'email' => 'portal-sem-nfse@teste.local',
                'password' => Hash::make('senha-portal-123'),
                'ativo' => true,
                'email_verificado_em' => now(),
            ]);
        });
        $portal = Module::query()->where('chave', 'portal_cliente')->firstOrFail();
        app(ModuleService::class)->liberarPara($empresaSemModulo, $portal, 'Teste do portal', null);
        $this->post('/portal/login', [
            'email' => $usuarioPortal->email,
            'password' => 'senha-portal-123',
        ])->assertRedirect();

        $this->getJson('/portal/notas')
            ->assertForbidden()
            ->assertJsonPath('modulo', 'nfse');
    }

    public function test_corrige_dados_fiscais_do_cliente_sem_expor_outro_tenant(): void
    {
        $this->actingAs($this->administrador)
            ->getJson("/fiscal/clientes/{$this->cliente->id}")
            ->assertOk()
            ->assertJsonPath('cliente.id', $this->cliente->id)
            ->assertJsonPath('cliente.addresses.0.id', $this->endereco->id)
            ->assertJsonMissingPath('cliente.company_id');

        $this->putJson("/fiscal/clientes/{$this->cliente->id}", [
            'name' => 'Tomador Fiscal Corrigido',
            'email' => 'fiscal-corrigido@teste.local',
            'email_nfe' => 'nfse-corrigida@teste.local',
            'phone' => '(11) 99999-9999',
            'cnpj' => '04.252.011/0001-10',
            'inscricao_municipal' => '123456',
            'inscricao_estadual' => null,
            'address' => [
                'id' => $this->endereco->id,
                'nickname' => 'Matriz fiscal',
                'street' => 'Praça da Sé',
                'number' => '100',
                'district' => 'Sé',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zip' => '01001-000',
                'codigo_municipio_ibge' => '3550308',
            ],
        ])->assertOk()
            ->assertJsonPath('cliente.name', 'Tomador Fiscal Corrigido')
            ->assertJsonPath('cliente.addresses.0.codigo_municipio_ibge', '3550308');

        $this->assertSame('nfse-corrigida@teste.local', $this->cliente->refresh()->email_nfe);
        $this->assertSame('Matriz fiscal', $this->endereco->refresh()->nickname);

        [, $outroAdmin] = $this->outroTenantComModulo();
        $this->actingAs($outroAdmin)
            ->getJson("/fiscal/clientes/{$this->cliente->id}")
            ->assertNotFound();
    }

    public function test_correcao_inline_define_endereco_da_os_e_prioridade_do_titulo_na_proxima_dps(): void
    {
        [$enderecoNovo, $titulo, $pendenciaOs, $pendenciaTitulo] = TenantAtual::comTenant($this->empresa->id, function (): array {
            $enderecoNovo = AddressFactory::new()->create([
                'client_id' => $this->cliente->id,
                'nickname' => 'Filial fiscal',
                'street' => 'Avenida Paulista',
                'number' => '1000',
                'district' => 'Bela Vista',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zip' => '01310-100',
                'codigo_municipio_ibge' => '3550308',
            ]);
            $titulo = Receivable::create([
                'client_id' => $this->cliente->id,
                'descricao' => 'Serviço avulso com endereço prioritário',
                'valor_total' => '180.00',
                'emitido_em' => '2026-08-02',
                'situacao' => 'quitado',
            ]);
            $pendenciaOs = $this->criarNota([
                'work_order_id' => $this->os->id,
                'situacao' => 'erro',
                'erro_mensagem' => 'Endereço incompleto.',
            ]);
            $pendenciaTitulo = $this->criarNota([
                'receivable_id' => $titulo->id,
                'situacao' => 'erro',
                'erro_mensagem' => 'Endereço incompleto.',
            ]);

            return [$enderecoNovo, $titulo, $pendenciaOs, $pendenciaTitulo];
        });

        $this->actingAs($this->administrador)
            ->putJson("/fiscal/clientes/{$this->cliente->id}", $this->dadosDeCorrecaoFiscal(
                $enderecoNovo,
                [$pendenciaOs->id, $pendenciaTitulo->id],
            ))
            ->assertOk();

        $this->assertSame($enderecoNovo->id, $this->os->refresh()->address_id);
        $this->assertSame($enderecoNovo->id, $pendenciaOs->refresh()->address_id);
        $this->assertSame($enderecoNovo->id, $pendenciaTitulo->refresh()->address_id);

        $novaOs = $this->postJson("/notas/{$pendenciaOs->id}/reprocessar")->assertOk()->json('nota.id');
        $novaTitulo = $this->postJson("/notas/{$pendenciaTitulo->id}/reprocessar")->assertOk()->json('nota.id');

        $this->assertSame($enderecoNovo->id, ServiceInvoice::query()->findOrFail($novaOs)->address_id);
        $notaDoTitulo = ServiceInvoice::query()->findOrFail($novaTitulo);
        $this->assertSame($enderecoNovo->id, $notaDoTitulo->address_id);
        $this->assertSame('Avenida Paulista', $notaDoTitulo->payload_dps['toma']['end']['xLgr']);
        $this->assertSame($titulo->id, $notaDoTitulo->receivable_id);
    }

    public function test_portal_lista_e_baixa_somente_notas_do_proprio_cliente_inclusive_cancelada(): void
    {
        $outroCliente = TenantAtual::comTenant($this->empresa->id, fn (): Client => ClientFactory::new()->create());
        [$propria, $alheia] = TenantAtual::comTenant($this->empresa->id, function () use ($outroCliente): array {
            $propria = $this->criarNota(['situacao' => 'cancelada', 'numero' => '55']);
            $alheia = $this->criarNota(['client_id' => $outroCliente->id, 'situacao' => 'emitida']);
            $propria->update(['xml_path' => "fiscal/empresa-{$this->empresa->id}/nota-{$propria->id}/nfse.xml"]);
            Storage::disk('local')->put($propria->xml_path, '<nota/>');

            return [$propria, $alheia];
        });

        $usuarioPortal = TenantAtual::comTenant($this->empresa->id, fn (): ClientUser => ClientUser::create([
            'client_id' => $this->cliente->id,
            'nome' => 'Cliente fiscal',
            'email' => 'portal-fiscal@teste.local',
            'password' => Hash::make('senha-portal-123'),
            'ativo' => true,
            'email_verificado_em' => now(),
        ]));
        $this->post('/portal/login', ['email' => $usuarioPortal->email, 'password' => 'senha-portal-123'])->assertRedirect();

        $resposta = $this->getJson('/portal/notas')->assertOk();
        $ids = array_column($resposta->json('notas_fiscais'), 'id');
        $this->assertContains($propria->id, $ids);
        $this->assertNotContains($alheia->id, $ids);
        $this->assertSame('cancelada', $resposta->json('notas_fiscais.0.situacao'));

        $this->get("/portal/notas/{$propria->id}/xml")->assertDownload('NFS-e-55.xml');
        $this->get("/portal/notas/{$alheia->id}/xml")->assertNotFound();
    }

    private function criarCenarioConcorrente(Connection $conexao, bool $dadosValidos): int
    {
        $agora = now();
        $sufixo = $dadosValidos ? 'valido' : 'invalido';
        $empresaId = $conexao->table('companies')->insertGetId([
            'name' => "Tenant concorrente {$sufixo}",
            'cnpj' => '11.444.777/0001-61',
            'email' => "tenant-concorrente-{$sufixo}@teste.local",
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);
        $clienteId = $conexao->table('clients')->insertGetId([
            'company_id' => $empresaId,
            'name' => 'Tomador da corrida',
            'email' => "tomador-concorrente-{$sufixo}@teste.local",
            'phone' => '(11) 99999-9999',
            'cnpj' => $dadosValidos ? '04.252.011/0001-10' : '',
            'codigo_municipio_ibge' => $dadosValidos ? '3550308' : null,
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);
        $enderecoId = $conexao->table('addresses')->insertGetId([
            'company_id' => $empresaId,
            'client_id' => $clienteId,
            'nickname' => 'Matriz',
            'street' => 'Praça da Sé',
            'number' => '100',
            'district' => 'Sé',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip' => '01001-000',
            'codigo_municipio_ibge' => $dadosValidos ? '3550308' : null,
            'active' => true,
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);
        $configuracaoId = $conexao->table('fiscal_configs')->insertGetId([
            'company_id' => $empresaId,
            'provedor' => 'nuvem_fiscal',
            'ambiente' => 'homologacao',
            'credenciais' => 'credencial-nao-utilizada-neste-cenario',
            'regime_tributario' => 'simples_nacional',
            'codigo_servico' => '07.13',
            'aliquota_iss' => '5.00',
            'natureza_operacao' => 'tributacao_no_municipio',
            'ativo' => true,
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);
        $tituloId = $conexao->table('receivables')->insertGetId([
            'company_id' => $empresaId,
            'client_id' => $clienteId,
            'descricao' => "Serviço concorrente {$sufixo}",
            'valor_total' => '180.00',
            'emitido_em' => '2026-08-02',
            'situacao' => 'aberto',
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);
        $conexao->table('service_invoices')->insert([
            'company_id' => $empresaId,
            'fiscal_config_id' => $configuracaoId,
            'client_id' => $clienteId,
            'address_id' => $enderecoId,
            'receivable_id' => $tituloId,
            'situacao' => 'erro',
            'valor_servico' => '180.00',
            'valor_iss' => '9.00',
            'valor_liquido' => '180.00',
            'descricao_servico' => 'Tentativa original da corrida',
            'competencia' => '2026-08-02',
            'erro_mensagem' => 'Visão carregada antes da corrida.',
            'erro_temporario' => false,
            'tentativas' => 0,
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);

        return $empresaId;
    }

    private function provarReprocessamentoConcorrente(
        Connection $isolada,
        Connection $concorrente,
        int $empresaId,
        bool $dadosValidos,
    ): void {
        TenantAtual::comTenant($empresaId, function () use ($isolada, $concorrente, $empresaId, $dadosValidos): void {
            $visaoAntiga = ServiceInvoice::query()->where('company_id', $empresaId)->firstOrFail();
            $tituloId = $visaoAntiga->receivable_id;
            $transacoesIniciadas = 0;
            $vencedoraId = null;
            $passouPeloRegistroDePendencia = false;

            $isolada->beforeStartingTransaction(
                function (Connection $conexao) use (&$transacoesIniciadas, &$vencedoraId, &$passouPeloRegistroDePendencia, $concorrente, $visaoAntiga, $tituloId): void {
                    if ($conexao->getName() !== 'nfse_isolada' || ++$transacoesIniciadas !== 2) {
                        return;
                    }

                    $passouPeloRegistroDePendencia = in_array(
                        'registrarPendenciaDeValidacao',
                        array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 'function'),
                        true,
                    );

                    $agora = now();
                    $vencedoraId = $concorrente->table('service_invoices')->insertGetId([
                        'company_id' => $visaoAntiga->company_id,
                        'fiscal_config_id' => $visaoAntiga->fiscal_config_id,
                        'client_id' => $visaoAntiga->client_id,
                        'address_id' => $visaoAntiga->address_id,
                        'receivable_id' => $tituloId,
                        'situacao' => 'erro',
                        'valor_servico' => '180.00',
                        'valor_iss' => '9.00',
                        'valor_liquido' => '180.00',
                        'descricao_servico' => 'Tentativa vencedora da corrida',
                        'competencia' => '2026-08-02',
                        'erro_mensagem' => 'A tentativa vencedora ainda tem erro.',
                        'erro_temporario' => false,
                        'tentativas' => 0,
                        'created_at' => $agora,
                        'updated_at' => $agora,
                    ]);
                    $concorrente->table('service_invoices')
                        ->where('id', $visaoAntiga->id)
                        ->update(['reprocessada_por_id' => $vencedoraId, 'updated_at' => $agora]);
                },
            );

            $resultado = app(\App\Services\ServiceInvoiceService::class)->reemitir($visaoAntiga);

            $notas = $isolada->table('service_invoices')
                ->where('company_id', $empresaId)
                ->where('receivable_id', $tituloId)
                ->orderBy('id')
                ->get(['id', 'reprocessada_por_id']);

            $this->assertNotNull($vencedoraId);
            $this->assertSame($vencedoraId, $resultado->id);
            $this->assertSame($vencedoraId, $notas->firstWhere('id', $visaoAntiga->id)?->reprocessada_por_id);
            $this->assertSame([$visaoAntiga->id, $vencedoraId], $notas->pluck('id')->all());
            $this->assertCount(2, $notas);
            $this->assertSame(2, $transacoesIniciadas, 'a vencedora concorrente deve encerrar o reprocessamento');
            $this->assertSame(
                ! $dadosValidos,
                $passouPeloRegistroDePendencia,
                'o cenário fiscal inválido deve alcançar registrarPendenciaDeValidacao antes da barreira',
            );
        });
    }

    private function criarNota(array $dados = []): ServiceInvoice
    {
        return ServiceInvoice::create(array_merge([
            'fiscal_config_id' => $this->configuracao->id,
            'client_id' => $this->cliente->id,
            'address_id' => $this->endereco->id,
            'situacao' => 'processando',
            'valor_servico' => '250.00',
            'valor_iss' => '12.50',
            'valor_liquido' => '250.00',
            'descricao_servico' => 'Controle integrado de pragas',
            'competencia' => '2026-08-01',
        ], $dados));
    }

    /** @param array<int, int> $notaIds */
    private function dadosDeCorrecaoFiscal(Address $endereco, array $notaIds): array
    {
        return [
            'name' => $this->cliente->name,
            'email' => $this->cliente->email,
            'email_nfe' => $this->cliente->email_nfe,
            'phone' => $this->cliente->phone,
            'cnpj' => $this->cliente->cnpj,
            'inscricao_municipal' => $this->cliente->inscricao_municipal,
            'inscricao_estadual' => $this->cliente->inscricao_estadual,
            'nota_ids' => $notaIds,
            'address' => [
                'id' => $endereco->id,
                'nickname' => $endereco->nickname,
                'street' => $endereco->street,
                'number' => $endereco->number,
                'district' => $endereco->district,
                'city' => $endereco->city,
                'state' => $endereco->state,
                'zip' => $endereco->zip,
                'codigo_municipio_ibge' => $endereco->codigo_municipio_ibge,
            ],
        ];
    }

    /** @return array{Company, User} */
    private function outroTenantComModulo(): array
    {
        $empresa = Company::create(['name' => 'Outro tenant', 'email' => 'outro-tenant@teste.local']);
        $usuario = TenantAtual::comTenant($empresa->id, function (): User {
            $usuario = User::factory()->create(['is_active' => true]);
            $usuario->assignRole('administrador');

            return $usuario;
        });
        $modulo = Module::query()->where('chave', 'nfse')->firstOrFail();
        app(ModuleService::class)->liberarPara($empresa, $modulo, 'Teste de isolamento', null);

        return [$empresa, $usuario];
    }
}

class ProvedorFiscalDoEndpoint implements ProvedorDeNfse
{
    public ?Throwable $falhaAoEmitir = null;

    public ?Throwable $falhaAoCancelar = null;

    public ?Throwable $falhaAoSubstituir = null;

    public ?Throwable $falhaAoValidar = null;

    public ?string $ultimoSegredoValidado = null;

    public bool $aceitarCredenciais = true;

    public int $validacoes = 0;

    public ?Closure $aoValidar = null;

    public string $situacaoCancelamento = 'autorizado';

    private int $sequencia = 0;

    public function emitir(FiscalConfig $configuracao, array $dadosDaDps, string $referencia): string
    {
        if ($this->falhaAoEmitir !== null) {
            throw $this->falhaAoEmitir;
        }

        return 'provedor-endpoint-'.(++$this->sequencia);
    }

    public function consultar(FiscalConfig $configuracao, string $idNoProvedor): RespostaDeNfse
    {
        return new RespostaDeNfse($idNoProvedor, RespostaDeNfse::SITUACAO_PROCESSANDO);
    }

    public function cancelar(FiscalConfig $configuracao, string $idNoProvedor, string $motivo, ?string $codigo = null): RespostaDeCancelamento
    {
        if ($this->falhaAoCancelar !== null) {
            throw $this->falhaAoCancelar;
        }

        return new RespostaDeCancelamento('cancelamento-endpoint', $this->situacaoCancelamento);
    }

    public function consultarCancelamento(FiscalConfig $configuracao, string $idNoProvedor): RespostaDeCancelamento
    {
        return new RespostaDeCancelamento('cancelamento-endpoint', $this->situacaoCancelamento);
    }

    public function substituir(FiscalConfig $configuracao, string $idNoProvedorDaNotaSubstituida, string $codigoMotivo, string $motivo, array $dadosDaDps, string $referencia): string
    {
        if ($this->falhaAoSubstituir !== null) {
            throw $this->falhaAoSubstituir;
        }

        return 'substituta-endpoint-'.(++$this->sequencia);
    }

    public function baixarPdf(FiscalConfig $configuracao, string $idNoProvedor): string
    {
        return '%PDF';
    }

    public function baixarXml(FiscalConfig $configuracao, string $idNoProvedor): string
    {
        return '<nota/>';
    }

    public function validarCredenciais(FiscalConfig $configuracao): bool
    {
        if ($this->falhaAoValidar !== null) {
            throw $this->falhaAoValidar;
        }

        $this->validacoes++;
        $this->ultimoSegredoValidado = $configuracao->credenciais['client_secret'] ?? null;
        $aoValidar = $this->aoValidar;
        $this->aoValidar = null;
        $aoValidar?->__invoke();

        return $this->aceitarCredenciais && $this->ultimoSegredoValidado !== null;
    }
}
