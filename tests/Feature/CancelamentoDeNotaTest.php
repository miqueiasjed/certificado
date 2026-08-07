<?php

namespace Tests\Feature;

use App\Console\Commands\SyncPermissions;
use App\Exceptions\CancelamentoNaoEncontradoException;
use App\Exceptions\NotaJaCanceladaException;
use App\Exceptions\PrazoDeCancelamentoExpiradoException;
use App\Exceptions\PrefeituraIndisponivelException;
use App\Exceptions\RecusaFiscalException;
use App\Mail\NotificacaoDaFila;
use App\Models\Address;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Company;
use App\Models\FiscalConfig;
use App\Models\NotificationQueue;
use App\Models\ServiceInvoice;
use App\Models\User;
use App\Services\Fiscal\CancelamentoDeNotaService;
use App\Services\Fiscal\ProvedorDeNfse;
use App\Services\Fiscal\ResolvedorDeProvedor;
use App\Services\Notification\DriverDeEmail;
use App\Services\Notification\DriverDeEnvio;
use App\Services\Notification\ResultadoDeEnvio;
use App\Services\NotificationDispatcher;
use App\Services\NotificationService;
use App\Services\ServiceInvoiceService;
use App\Support\EventosDeNotificacao;
use App\Support\Fiscal\MensagemFiscalPublica;
use App\Support\Fiscal\RespostaDeCancelamento;
use App\Support\Fiscal\RespostaDeNfse;
use App\Support\TenantAtual;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Throwable;

class CancelamentoDeNotaTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private FiscalConfig $configuracao;

    private Client $cliente;

    private Address $endereco;

    private ServiceInvoice $nota;

    private User $administrador;

    private ProvedorDeCancelamentoSimulado $provedor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-07 16:30:00');
        Storage::fake('local');
        TenantAtual::limpar();

        $this->empresa = Company::create([
            'name' => 'Empresa Fiscal',
            'email' => 'empresa@fiscal.test',
            'cnpj' => '11.444.777/0001-61',
        ]);
        TenantAtual::definir($this->empresa->id);
        $this->configuracao = FiscalConfig::create([
            'provedor' => 'simulado',
            'ambiente' => 'homologacao',
            'credenciais' => ['token' => 'segredo'],
            'regime_tributario' => 'simples_nacional',
            'codigo_servico' => '07.13',
            'aliquota_iss' => '5.00',
            'natureza_operacao' => 'tributacao_no_municipio',
            'ativo' => true,
        ]);
        $this->cliente = Client::create([
            'name' => 'Cliente da Nota',
            'email' => 'cliente@fiscal.test',
            'phone' => '11999999999',
            'cnpj' => '04.252.011/0001-10',
            'codigo_municipio_ibge' => '3550308',
        ]);
        $this->endereco = Address::create([
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
        $this->nota = $this->criarNotaEmitida();
        $this->administrador = User::factory()->create(['company_id' => $this->empresa->id]);
        Permission::findOrCreate(CancelamentoDeNotaService::PERMISSAO, 'web');
        Role::findOrCreate('administrador', 'web');
        $this->administrador->assignRole('administrador');
        $this->administrador->givePermissionTo(CancelamentoDeNotaService::PERMISSAO);
        $this->actingAs($this->administrador);

        $this->provedor = new ProvedorDeCancelamentoSimulado;
        $resolvedor = Mockery::mock(ResolvedorDeProvedor::class);
        $resolvedor->shouldReceive('paraConfiguracao')->andReturn($this->provedor);
        $this->app->instance(ResolvedorDeProvedor::class, $resolvedor);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_motivo_curto_e_status_diferente_de_emitida_sao_recusados_antes_do_provedor(): void
    {
        try {
            app(CancelamentoDeNotaService::class)->cancelar($this->nota, 'erro');
            $this->fail('O motivo curto precisava ser recusado.');
        } catch (ValidationException $falha) {
            $this->assertStringContainsString('15 caracteres', $falha->getMessage());
        }

        $this->nota->update(['situacao' => 'processando']);

        try {
            app(CancelamentoDeNotaService::class)->cancelar($this->nota->refresh(), 'Correção fiscal necessária');
            $this->fail('Uma nota em processamento não pode ser cancelada.');
        } catch (ValidationException $falha) {
            $this->assertStringContainsString('Somente uma nota emitida', $falha->getMessage());
        }

        $this->assertSame(0, $this->provedor->cancelamentos);
    }

    public function test_cancelamento_usa_configuracao_original_preserva_arquivos_e_audita_usuario(): void
    {
        $pdf = $this->nota->pdf_path;
        $xml = $this->nota->xml_path;
        $cancelada = app(CancelamentoDeNotaService::class)->cancelar(
            $this->nota,
            'Documento do tomador informado incorretamente',
        );

        $this->assertSame('cancelada', $cancelada->situacao);
        $this->assertSame('Documento do tomador informado incorretamente', $cancelada->motivo_cancelamento);
        $this->assertSame($pdf, $cancelada->pdf_path);
        $this->assertSame($xml, $cancelada->xml_path);
        Storage::disk('local')->assertExists($pdf);
        Storage::disk('local')->assertExists($xml);
        $this->assertSame('nota-provedor-1', $this->provedor->idCancelado);
        $this->assertSame($this->configuracao->id, $this->provedor->configuracaoCancelada);

        $auditoria = AuditLog::query()
            ->where('auditable_type', ServiceInvoice::class)
            ->where('auditable_id', $cancelada->id)
            ->where('acao', 'alterado')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($this->administrador->id, $auditoria->user_id);
        $this->assertSame('cancelada', $auditoria->valores_depois['situacao']);
        $this->assertArrayNotHasKey('processamento_token', $auditoria->valores_depois);
    }

    public function test_recusa_por_prazo_e_traduzida_sem_presumir_quantidade_de_dias(): void
    {
        $this->provedor->falhaAoCancelar = new PrazoDeCancelamentoExpiradoException;

        try {
            app(CancelamentoDeNotaService::class)->cancelar(
                $this->nota,
                'Cancelamento solicitado pelo responsável fiscal',
            );
            $this->fail('A recusa municipal precisava chegar ao usuário.');
        } catch (PrazoDeCancelamentoExpiradoException $falha) {
            $this->assertStringContainsString('substituição da nota ou uma carta de correção', $falha->getMessage());
            $this->assertDoesNotMatchRegularExpression('/\d+\s+dias/', $falha->getMessage());
        }

        $this->assertSame('emitida', $this->nota->refresh()->situacao);
        $this->assertNull($this->nota->processamento_token);
        $this->assertNull($this->nota->cancelamento_solicitado_em);

        $this->provedor->falhaAoCancelar = null;
        $cancelada = app(CancelamentoDeNotaService::class)->cancelar(
            $this->nota->refresh(),
            'Novo motivo fiscal válido depois da recusa municipal',
        );
        $this->assertSame('cancelada', $cancelada->situacao);
        $this->assertSame(2, $this->provedor->cancelamentos);
    }

    public function test_nota_ja_cancelada_so_recupera_quando_ha_tentativa_anterior_persistida(): void
    {
        $motivo = 'Cancelamento solicitado pelo responsável fiscal';
        $this->provedor->falhaAoCancelar = new NotaJaCanceladaException;

        try {
            app(CancelamentoDeNotaService::class)->cancelar($this->nota, $motivo);
            $this->fail('Uma nota cancelada fora deste fluxo não pode ser reconciliada sem evidência.');
        } catch (NotaJaCanceladaException) {
            $this->assertSame('emitida', $this->nota->refresh()->situacao);
            $this->assertNull($this->nota->cancelamento_solicitado_em);
        }

        $this->nota->update([
            'cancelamento_solicitado_em' => now()->subMinute(),
            'motivo_cancelamento' => $motivo,
            'processamento_token' => 'reserva-interrompida',
            'processamento_bloqueado_ate' => now()->subSecond(),
        ]);
        $this->provedor->consultaCancelamento = new RespostaDeCancelamento('cancelamento-1', 'autorizado');
        $recuperada = app(CancelamentoDeNotaService::class)->cancelar($this->nota->refresh(), $motivo);

        $this->assertSame('cancelada', $recuperada->situacao);
        $this->assertSame($motivo, $recuperada->motivo_cancelamento);
    }

    public function test_usuario_sem_permissao_recebe_403_e_catalogo_reserva_permissao_ao_administrador(): void
    {
        $semPermissao = User::factory()->create(['company_id' => $this->empresa->id]);
        $this->actingAs($semPermissao);

        $this->expectException(AuthorizationException::class);

        try {
            app(CancelamentoDeNotaService::class)->cancelar(
                $this->nota,
                'Tentativa sem a permissão fiscal exigida',
            );
        } finally {
            $this->assertContains(CancelamentoDeNotaService::PERMISSAO, SyncPermissions::catalogo()['fiscal']);
        }
    }

    public function test_permissao_sem_papel_administrador_continua_recusada(): void
    {
        $usuario = User::factory()->create(['company_id' => $this->empresa->id]);
        $usuario->givePermissionTo(CancelamentoDeNotaService::PERMISSAO);
        $this->actingAs($usuario);

        $this->expectException(AuthorizationException::class);

        app(CancelamentoDeNotaService::class)->cancelar(
            $this->nota,
            'Tentativa com permissão e sem papel administrativo',
        );
    }

    public function test_seeder_atribui_fiscal_cancelar_somente_ao_administrador(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        foreach (['financeiro', 'comercial', 'tecnico', 'leitura'] as $papel) {
            $this->assertFalse(Role::findByName($papel)->hasPermissionTo(CancelamentoDeNotaService::PERMISSAO));
        }

        $administrador = Role::findByName('administrador');
        $this->assertTrue($administrador->hasPermissionTo(CancelamentoDeNotaService::PERMISSAO));
    }

    public function test_substituicao_preserva_snapshot_cadeia_e_repeticao_logica(): void
    {
        $avisoEmEnvio = $this->avisoInicial($this->nota);
        $avisoEmEnvio->update([
            'evento' => EventosDeNotificacao::NFSE_SUBSTITUIDA,
            'situacao' => NotificationQueue::SITUACAO_ENVIANDO,
        ]);
        $nova = app(CancelamentoDeNotaService::class)->substituir($this->nota, [
            'motivo' => 'Correção da descrição do serviço prestado',
            'descricao_servico' => 'Controle de pragas urbanas corrigido',
        ]);

        $antiga = $this->nota->refresh();
        $this->assertSame('emitida', $antiga->situacao);
        $this->assertSame($nova->id, $antiga->substituida_por_id);
        $this->assertTrue($antiga->substituidaPor->is($nova));
        $this->assertTrue($nova->notaSubstituida->is($antiga));
        $this->assertSame('processando', $nova->situacao);
        $this->assertSame($antiga->client_id, $nova->client_id);
        $this->assertSame($antiga->fiscal_config_id, $nova->fiscal_config_id);
        $this->assertSame($antiga->address_id, $nova->address_id);
        $this->assertSame($antiga->valor_servico, $nova->valor_servico);
        $this->assertSame('Controle de pragas urbanas corrigido', $nova->descricao_servico);
        $this->assertStringStartsWith('nfse-subst-'.$this->empresa->id.'-', $nova->referencia_provedor);
        $this->assertSame($nova->referencia_provedor, $this->provedor->referenciaSubstituicao);
        $this->assertSame(NotificationQueue::SITUACAO_ENVIANDO, $avisoEmEnvio->refresh()->situacao);

        $auditoria = AuditLog::query()
            ->where('auditable_type', ServiceInvoice::class)
            ->where('auditable_id', $antiga->id)
            ->where('acao', 'alterado')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame($this->administrador->id, $auditoria->user_id);
        $this->assertSame($nova->id, $auditoria->valores_depois['substituida_por_id']);
        $this->assertSame('Correção da descrição do serviço prestado', $auditoria->valores_depois['motivo_substituicao']);

        $repetida = app(CancelamentoDeNotaService::class)->substituir($antiga, [
            'motivo' => 'Correção da descrição do serviço prestado',
        ]);
        $this->assertTrue($nova->is($repetida));
        $this->assertSame(1, $this->provedor->substituicoes);
        $this->assertSame(2, ServiceInvoice::query()->count());

        $imutavel = app(CancelamentoDeNotaService::class)->substituir($antiga, [
            'motivo' => 'Outro motivo que não pode alterar a substituta',
            'descricao_servico' => 'Texto que não pode substituir o já enviado',
            'valor_servico' => '999.99',
        ]);
        $this->assertSame('Controle de pragas urbanas corrigido', $imutavel->descricao_servico);
        $this->assertSame('250.00', $imutavel->valor_servico);
        $this->assertSame(1, $this->provedor->substituicoes);
    }

    public function test_repeticao_de_substituicao_em_erro_aplica_correcao_sob_claim_e_recalcula_valores(): void
    {
        $this->provedor->falhaAoSubstituir = new RecusaFiscalException;

        try {
            app(CancelamentoDeNotaService::class)->substituir($this->nota, [
                'motivo' => 'Correção fiscal solicitada pelo tomador',
            ]);
        } catch (RecusaFiscalException) {
        }

        $substituta = $this->nota->refresh()->substituidaPor;
        $this->provedor->falhaAoSubstituir = null;

        $reprocessada = app(CancelamentoDeNotaService::class)->substituir($this->nota->refresh(), [
            'motivo' => 'Correção fiscal solicitada pelo tomador',
            'descricao_servico' => 'Descrição fiscal corrigida no reenvio',
            'valor_servico' => '333.33',
            'valor_iss' => '0.01',
            'valor_liquido' => '0.02',
        ]);

        $this->assertSame('processando', $reprocessada->situacao);
        $this->assertSame('333.33', $reprocessada->valor_servico);
        $this->assertSame('16.67', $reprocessada->valor_iss);
        $this->assertSame('333.33', $reprocessada->valor_liquido);
        $this->assertSame('Descrição fiscal corrigida no reenvio', $this->provedor->payloadSubstituicao['serv']['cServ']['xDescServ']);
        $this->assertSame('333.33', $this->provedor->payloadSubstituicao['valores']['vServPrest']['vServ']);
        $this->assertSame(2, $this->provedor->substituicoes);
    }

    public function test_lease_expirado_repete_snapshot_persistido_sem_aceitar_novas_correcoes(): void
    {
        $this->provedor->falhaAoSubstituir = new PrefeituraIndisponivelException;

        try {
            app(CancelamentoDeNotaService::class)->substituir($this->nota, [
                'motivo' => 'Correção fiscal solicitada pelo tomador',
                'descricao_servico' => 'Snapshot persistido antes da queda',
                'valor_servico' => '400.00',
            ]);
        } catch (PrefeituraIndisponivelException) {
        }

        $substituta = $this->nota->refresh()->substituidaPor;
        $snapshot = $substituta->payload_dps;
        $metadados = $substituta->metadados_substituicao;
        $this->cliente->update(['name' => 'Cliente alterado depois da tentativa']);
        $this->endereco->update(['street' => 'Rua alterada depois da tentativa']);
        $substituta->update([
            'processamento_token' => 'claim-da-queda-ambigua',
            'processamento_bloqueado_ate' => now()->subSecond(),
        ]);
        $this->provedor->falhaAoSubstituir = null;

        $repetida = app(CancelamentoDeNotaService::class)->substituir($this->nota->refresh(), [
            'motivo' => 'Outro motivo válido que precisa ser ignorado',
            'codigo_motivo' => '9',
            'descricao_servico' => 'Payload diferente que deve ser ignorado',
            'valor_servico' => '999.99',
        ]);

        $this->assertSame('processando', $repetida->situacao);
        $this->assertSame('Snapshot persistido antes da queda', $repetida->descricao_servico);
        $this->assertSame('400.00', $repetida->valor_servico);
        $this->assertSame($snapshot, $this->provedor->payloadSubstituicao);
        $this->assertSame($metadados, $repetida->metadados_substituicao);
        $this->assertSame('Correção fiscal solicitada pelo tomador', $metadados['motivo']);
        $this->assertSame('1', $metadados['codigo_motivo']);
        $this->assertSame('Cliente da Nota', $this->provedor->payloadSubstituicao['toma']['xNome']);
        $this->assertSame('Rua Fiscal', $this->provedor->payloadSubstituicao['toma']['end']['xLgr']);
        $this->assertSame('Snapshot persistido antes da queda', $this->provedor->payloadSubstituicao['serv']['cServ']['xDescServ']);
        $this->assertSame('400.00', $this->provedor->payloadSubstituicao['valores']['vServPrest']['vServ']);
    }

    public function test_falha_na_substituicao_nao_entra_na_fila_de_emissao_comum(): void
    {
        $this->provedor->falhaAoSubstituir = new PrefeituraIndisponivelException;

        try {
            app(CancelamentoDeNotaService::class)->substituir($this->nota, [
                'motivo' => 'Correção fiscal solicitada pelo tomador',
            ]);
            $this->fail('A indisponibilidade precisava ser devolvida.');
        } catch (PrefeituraIndisponivelException) {
            $substituta = $this->nota->refresh()->substituidaPor;
            $this->assertSame('erro', $substituta->situacao);
            $this->assertTrue($substituta->erro_temporario);
        }

        $this->provedor->falhaAoSubstituir = null;
        $this->assertSame(0, app(ServiceInvoiceService::class)->processarNotas());
        $this->assertSame(0, $this->provedor->emissoesComuns);
    }

    public function test_recusa_da_substituta_preserva_nota_antiga_emitida_documentos_e_aviso(): void
    {
        $aviso = $this->avisoInicial($this->nota);
        $substituta = app(CancelamentoDeNotaService::class)->substituir($this->nota, [
            'motivo' => 'Correção fiscal solicitada pelo tomador',
        ]);
        $this->provedor->consulta = new RespostaDeNfse(
            id: 'substituta-1',
            situacao: RespostaDeNfse::SITUACAO_ERRO,
            mensagens: [['descricao' => 'Substituição recusada', 'correcao' => null, 'codigo' => 'R1']],
        );

        app(ServiceInvoiceService::class)->processarNotas();

        $this->assertSame('erro', $substituta->refresh()->situacao);
        $this->assertSame('emitida', $this->nota->refresh()->situacao);
        $this->assertSame(NotificationQueue::SITUACAO_PENDENTE, $aviso->refresh()->situacao);
        Storage::disk('local')->assertExists($this->nota->pdf_path);
        Storage::disk('local')->assertExists($this->nota->xml_path);
    }

    public function test_cancelamento_trata_envio_em_andamento_como_entrega_incerta_e_avisa_correcao(): void
    {
        $pendente = $this->avisoInicial($this->nota);
        $pendente->update([
            'evento' => EventosDeNotificacao::NFSE_SUBSTITUIDA,
            'situacao' => NotificationQueue::SITUACAO_ENVIANDO,
        ]);

        app(CancelamentoDeNotaService::class)->cancelar(
            $this->nota,
            'Cancelamento solicitado antes do envio ao cliente',
        );

        $this->assertSame(NotificationQueue::SITUACAO_CANCELADA, $pendente->refresh()->situacao);
        $this->assertSame(1, NotificationQueue::query()->where('evento', EventosDeNotificacao::NFSE_CANCELADA)->count());

        $outra = $this->criarNotaEmitida('9002');
        $enviada = $this->avisoInicial($outra);
        $enviada->update(['situacao' => NotificationQueue::SITUACAO_ENVIADA]);

        app(CancelamentoDeNotaService::class)->cancelar(
            $outra,
            'Cancelamento solicitado depois do envio ao cliente',
        );

        $aviso = NotificationQueue::query()
            ->where('evento', EventosDeNotificacao::NFSE_CANCELADA)
            ->where('contexto->referencia_id', $outra->id)
            ->sole();
        $this->assertSame($outra->id, $aviso->contexto['service_invoice_id']);
    }

    public function test_cancelamento_pendente_so_conclui_depois_do_polling_autorizado(): void
    {
        $this->provedor->respostaCancelamento = new RespostaDeCancelamento('pedido-77', 'pendente');
        $pendente = app(CancelamentoDeNotaService::class)->cancelar(
            $this->nota,
            'Cancelamento solicitado pelo responsável fiscal',
        );

        $this->assertSame('cancelamento_pendente', $pendente->situacao);
        $this->assertSame('pedido-77', $pendente->cancelamento_provedor_id);
        $this->assertNull($pendente->cancelada_em);
        $this->assertSame(1, $this->provedor->cancelamentos);

        $this->provedor->consultaCancelamento = new RespostaDeCancelamento('pedido-77', 'autorizado');
        $this->assertSame(1, app(CancelamentoDeNotaService::class)->processarCancelamentosPendentes());
        $this->assertSame('cancelada', $this->nota->refresh()->situacao);
        $this->assertSame(1, $this->provedor->consultasCancelamento);
    }

    public function test_falha_interna_no_cancelamento_persiste_mensagem_publica_e_registra_contexto(): void
    {
        $falha = new RuntimeException('Call to undefined method ClienteFiscal::segredo()');
        $this->provedor->falhaAoCancelar = $falha;
        Log::spy();

        try {
            app(CancelamentoDeNotaService::class)->cancelar(
                $this->nota,
                'Cancelamento solicitado pelo responsável fiscal',
            );
            $this->fail('A falha interna do provedor precisava ser propagada.');
        } catch (RuntimeException $capturada) {
            $this->assertSame($falha, $capturada);
        }

        $this->assertSame('emitida', $this->nota->refresh()->situacao);
        $this->assertSame(MensagemFiscalPublica::FALHA_INTERNA, $this->nota->erro_mensagem);
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $mensagem, array $contexto): bool => $mensagem === '[fiscal] Falha interna ocultada da resposta pública.'
                && $contexto['service_invoice_id'] === $this->nota->id
                && $contexto['operacao'] === 'cancelar'
                && $contexto['exception'] === $falha,
        )->once();
    }

    public function test_polling_recusado_persiste_mensagem_e_preserva_documentos(): void
    {
        $this->provedor->respostaCancelamento = new RespostaDeCancelamento('pedido-88', 'pendente');
        app(CancelamentoDeNotaService::class)->cancelar(
            $this->nota,
            'Cancelamento solicitado pelo responsável fiscal',
        );
        $this->provedor->consultaCancelamento = new RespostaDeCancelamento(
            'pedido-88',
            'recusado',
            motivo: 'Prazo de cancelamento expirado no município',
        );

        app(CancelamentoDeNotaService::class)->processarCancelamentosPendentes();

        $nota = $this->nota->refresh();
        $this->assertSame('emitida', $nota->situacao);
        $this->assertStringContainsString('prazo de cancelamento expirou', $nota->erro_mensagem);
        Storage::disk('local')->assertExists($nota->pdf_path);
        Storage::disk('local')->assertExists($nota->xml_path);
    }

    public function test_recuperacao_consulta_cancelamento_existente_antes_de_repetir_post(): void
    {
        $motivo = 'Cancelamento solicitado pelo responsável fiscal';
        $this->nota->update([
            'cancelamento_solicitado_em' => now()->subMinute(),
            'motivo_cancelamento' => $motivo,
        ]);
        $this->provedor->consultaCancelamento = new RespostaDeCancelamento('pedido-recuperado', 'autorizado');

        $recuperada = app(CancelamentoDeNotaService::class)->cancelar($this->nota->refresh(), $motivo);

        $this->assertSame('cancelada', $recuperada->situacao);
        $this->assertSame(0, $this->provedor->cancelamentos);
        $this->assertSame(1, $this->provedor->consultasCancelamento);
    }

    public function test_recuperacao_repete_um_post_somente_quando_get_informa_ausencia(): void
    {
        $motivo = 'Cancelamento solicitado pelo responsável fiscal';
        $this->nota->update([
            'cancelamento_solicitado_em' => now()->subMinute(),
            'motivo_cancelamento' => $motivo,
        ]);
        $this->provedor->falhaAoConsultarCancelamento = new CancelamentoNaoEncontradoException;
        $this->provedor->respostaCancelamento = new RespostaDeCancelamento('pedido-novo', 'pendente');

        $recuperada = app(CancelamentoDeNotaService::class)->cancelar($this->nota->refresh(), $motivo);

        $this->assertSame('cancelamento_pendente', $recuperada->situacao);
        $this->assertSame('pedido-novo', $recuperada->cancelamento_provedor_id);
        $this->assertSame($motivo, $recuperada->motivo_cancelamento);
        $this->assertSame(1, $this->provedor->consultasCancelamento);
        $this->assertSame(1, $this->provedor->cancelamentos);

        $this->provedor->falhaAoConsultarCancelamento = null;
        $this->provedor->consultaCancelamento = new RespostaDeCancelamento('pedido-novo', 'pendente');
        app(CancelamentoDeNotaService::class)->processarCancelamentosPendentes();
        $this->assertSame(1, $this->provedor->cancelamentos);
    }

    public function test_recuperacao_nao_repete_post_quando_get_falha_por_outro_motivo(): void
    {
        $motivo = 'Cancelamento solicitado pelo responsável fiscal';
        $this->nota->update([
            'cancelamento_solicitado_em' => now()->subMinute(),
            'motivo_cancelamento' => $motivo,
        ]);
        $this->provedor->falhaAoConsultarCancelamento = new PrefeituraIndisponivelException;

        try {
            app(CancelamentoDeNotaService::class)->cancelar($this->nota->refresh(), $motivo);
            $this->fail('A indisponibilidade da consulta precisava ser devolvida.');
        } catch (PrefeituraIndisponivelException) {
            $this->assertSame(1, $this->provedor->consultasCancelamento);
            $this->assertSame(0, $this->provedor->cancelamentos);
        }
    }

    public function test_aviso_da_substituta_espera_pdf_e_driver_anexa_arquivo_privado(): void
    {
        Mail::fake();
        $enviada = $this->avisoInicial($this->nota);
        $enviada->update(['situacao' => NotificationQueue::SITUACAO_ENVIANDO]);
        $nova = app(CancelamentoDeNotaService::class)->substituir($this->nota, [
            'motivo' => 'Correção fiscal solicitada pelo tomador',
        ]);

        $this->assertSame(0, NotificationQueue::query()->where('evento', EventosDeNotificacao::NFSE_SUBSTITUIDA)->count());

        $this->provedor->consulta = new RespostaDeNfse(
            id: 'substituta-1',
            situacao: RespostaDeNfse::SITUACAO_AUTORIZADA,
            numero: '9100',
        );
        app(ServiceInvoiceService::class)->processarNotas();

        $nova->refresh();
        $this->assertSame('substituida', $this->nota->refresh()->situacao);
        $this->assertSame(NotificationQueue::SITUACAO_CANCELADA, $enviada->refresh()->situacao);
        Storage::disk('local')->assertExists($nova->pdf_path);
        $aviso = NotificationQueue::query()->where('evento', EventosDeNotificacao::NFSE_SUBSTITUIDA)->sole();
        $this->assertSame($nova->id, $aviso->contexto['service_invoice_id']);

        $resultado = app(DriverDeEmail::class)->enviar($aviso);
        $this->assertTrue($resultado->ehSucesso());
        Mail::assertSent(NotificacaoDaFila::class, function (NotificacaoDaFila $mail): bool {
            $anexos = $mail->attachments();

            return count($anexos) === 1;
        });
    }

    public function test_segunda_substituicao_reconhece_aviso_de_substituicao_ja_enviado(): void
    {
        $aviso = $this->avisoInicial($this->nota);
        $aviso->update(['situacao' => NotificationQueue::SITUACAO_ENVIADA]);
        $primeira = app(CancelamentoDeNotaService::class)->substituir($this->nota, [
            'motivo' => 'Primeira correção fiscal solicitada pelo tomador',
        ]);
        $this->provedor->consulta = new RespostaDeNfse('substituta-1', RespostaDeNfse::SITUACAO_AUTORIZADA, numero: '9100');
        app(ServiceInvoiceService::class)->processarNotas();
        $avisoPrimeira = NotificationQueue::query()
            ->where('evento', EventosDeNotificacao::NFSE_SUBSTITUIDA)
            ->where('contexto->referencia_id', $primeira->id)
            ->sole();
        $avisoPrimeira->update(['situacao' => NotificationQueue::SITUACAO_ENVIADA]);

        $segunda = app(CancelamentoDeNotaService::class)->substituir($primeira->refresh(), [
            'motivo' => 'Segunda correção fiscal solicitada pelo tomador',
        ]);
        $this->provedor->consulta = new RespostaDeNfse('substituta-1', RespostaDeNfse::SITUACAO_AUTORIZADA, numero: '9200');
        app(ServiceInvoiceService::class)->processarNotas();

        $this->assertTrue(NotificationQueue::query()
            ->where('evento', EventosDeNotificacao::NFSE_SUBSTITUIDA)
            ->where('contexto->referencia_id', $segunda->id)
            ->exists());
    }

    public function test_driver_recusa_caminho_com_nome_alternativo_ou_traversal(): void
    {
        $aviso = $this->avisoInicial($this->nota);

        foreach ([
            "fiscal/empresa-{$this->empresa->id}/nota-{$this->nota->id}/outro.pdf",
            "fiscal/empresa-{$this->empresa->id}/nota-{$this->nota->id}/../segredo.pdf",
        ] as $caminho) {
            $this->nota->update(['pdf_path' => $caminho]);
            $resultado = app(DriverDeEmail::class)->enviar($aviso->refresh());
            $this->assertFalse($resultado->ehSucesso());
            $this->assertTrue($resultado->ehFalhaPermanente());
        }
    }

    public function test_driver_rele_estado_e_nao_transporta_aviso_que_foi_cancelado(): void
    {
        Mail::fake();
        $aviso = $this->avisoInicial($this->nota);
        $objetoDesatualizado = $aviso->fresh();
        $aviso->update(['situacao' => NotificationQueue::SITUACAO_CANCELADA]);

        $resultado = app(DriverDeEmail::class)->enviar($objetoDesatualizado);

        $this->assertTrue($resultado->ehFalhaPermanente());
        Mail::assertNothingSent();
    }

    public function test_dispatcher_nao_ressuscita_aviso_cancelado_durante_o_transporte(): void
    {
        $aviso = $this->avisoInicial($this->nota);
        $driver = new DriverQueCancelaAvisoDuranteTransporte;

        $resumo = (new NotificationDispatcher([$driver]))->despachar();

        $this->assertSame(1, $driver->transportes);
        $this->assertSame(1, $resumo['processados']);
        $this->assertSame(NotificationQueue::SITUACAO_CANCELADA, $aviso->refresh()->situacao);
    }

    public function test_configuracao_usada_fica_imutavel_mas_opcoes_operacionais_podem_mudar(): void
    {
        $this->configuracao->update([
            'ativo' => false,
            'emissao_automatica' => true,
            'gatilho_emissao_automatica' => 'quitacao_titulo',
        ]);
        $this->assertFalse($this->configuracao->refresh()->ativo);

        $this->expectException(\RuntimeException::class);
        $this->configuracao->update(['aliquota_iss' => '4.00']);
    }

    private function criarNotaEmitida(string $numero = '9001'): ServiceInvoice
    {
        $nota = ServiceInvoice::create([
            'fiscal_config_id' => $this->configuracao->id,
            'client_id' => $this->cliente->id,
            'address_id' => $this->endereco->id,
            'numero' => $numero,
            'situacao' => 'emitida',
            'provedor_id' => 'nota-provedor-'.(ServiceInvoice::query()->count() + 1),
            'valor_servico' => '250.00',
            'valor_iss' => '12.50',
            'valor_liquido' => '250.00',
            'descricao_servico' => 'Controle de pragas urbanas',
            'competencia' => '2026-08-07',
            'emitida_em' => now(),
        ]);
        $base = "fiscal/empresa-{$this->empresa->id}/nota-{$nota->id}";
        $nota->update(['pdf_path' => "{$base}/nfse.pdf", 'xml_path' => "{$base}/nfse.xml"]);
        Storage::disk('local')->put($nota->pdf_path, '%PDF-fiscal');
        Storage::disk('local')->put($nota->xml_path, '<NFS-e/>');

        return $nota->refresh();
    }

    private function avisoInicial(ServiceInvoice $nota): NotificationQueue
    {
        return app(NotificationService::class)->enfileirar(EventosDeNotificacao::NFSE_EMITIDA, $nota, [
            'canal' => EventosDeNotificacao::CANAL_EMAIL,
            'variaveis' => ['nota_numero' => $nota->numero],
            'contexto' => ['service_invoice_id' => $nota->id],
        ])['item'];
    }
}

class ProvedorDeCancelamentoSimulado implements ProvedorDeNfse
{
    public int $emissoesComuns = 0;

    public int $cancelamentos = 0;

    public int $substituicoes = 0;

    public int $consultasCancelamento = 0;

    public ?Throwable $falhaAoCancelar = null;

    public ?Throwable $falhaAoSubstituir = null;

    public ?Throwable $falhaAoConsultarCancelamento = null;

    public ?string $idCancelado = null;

    public ?int $configuracaoCancelada = null;

    public ?string $referenciaSubstituicao = null;

    /** @var array<string, mixed> */
    public array $payloadSubstituicao = [];

    public RespostaDeNfse $consulta;

    public RespostaDeCancelamento $consultaCancelamento;

    public RespostaDeCancelamento $respostaCancelamento;

    public function __construct()
    {
        $this->consulta = new RespostaDeNfse('substituta-1', RespostaDeNfse::SITUACAO_PROCESSANDO);
        $this->consultaCancelamento = new RespostaDeCancelamento('cancelamento-1', 'pendente');
        $this->respostaCancelamento = new RespostaDeCancelamento('cancelamento-1', 'autorizado');
    }

    public function emitir(FiscalConfig $configuracao, array $dadosDaDps, string $referencia): string
    {
        $this->emissoesComuns++;

        return 'emissao-1';
    }

    public function consultar(FiscalConfig $configuracao, string $idNoProvedor): RespostaDeNfse
    {
        return $this->consulta;
    }

    public function cancelar(FiscalConfig $configuracao, string $idNoProvedor, string $motivo, ?string $codigo = null): RespostaDeCancelamento
    {
        $this->cancelamentos++;
        $this->idCancelado = $idNoProvedor;
        $this->configuracaoCancelada = $configuracao->id;

        if ($this->falhaAoCancelar !== null) {
            throw $this->falhaAoCancelar;
        }

        return $this->respostaCancelamento;
    }

    public function consultarCancelamento(FiscalConfig $configuracao, string $idNoProvedor): RespostaDeCancelamento
    {
        $this->consultasCancelamento++;

        if ($this->falhaAoConsultarCancelamento !== null) {
            throw $this->falhaAoConsultarCancelamento;
        }

        return $this->consultaCancelamento;
    }

    public function substituir(FiscalConfig $configuracao, string $idNoProvedorDaNotaSubstituida, string $codigoMotivo, string $motivo, array $dadosDaDps, string $referencia): string
    {
        $this->substituicoes++;
        $this->referenciaSubstituicao = $referencia;
        $this->payloadSubstituicao = $dadosDaDps;

        if ($this->falhaAoSubstituir !== null) {
            throw $this->falhaAoSubstituir;
        }

        return 'substituta-1';
    }

    public function baixarPdf(FiscalConfig $configuracao, string $idNoProvedor): string
    {
        return '%PDF-substituta';
    }

    public function baixarXml(FiscalConfig $configuracao, string $idNoProvedor): string
    {
        return '<NFS-e>substituta</NFS-e>';
    }

    public function validarCredenciais(FiscalConfig $configuracao): bool
    {
        return true;
    }
}

class DriverQueCancelaAvisoDuranteTransporte implements DriverDeEnvio
{
    public int $transportes = 0;

    public function canal(): string
    {
        return EventosDeNotificacao::CANAL_EMAIL;
    }

    public function enviar(NotificationQueue $item): ResultadoDeEnvio
    {
        $this->transportes++;
        NotificationQueue::query()->whereKey($item->id)->update([
            'situacao' => NotificationQueue::SITUACAO_CANCELADA,
        ]);

        return ResultadoDeEnvio::sucesso('Transporte aceitou a mensagem antes do cancelamento concorrente.');
    }
}
