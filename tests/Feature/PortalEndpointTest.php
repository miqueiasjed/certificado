<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Module;
use App\Models\PaymentDetail;
use App\Models\WorkOrder;
use App\Models\WorkOrderAdequation;
use App\Services\ModuleService;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Database\Factories\ClientFactory;
use Database\Seeders\ModulesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Task 15.4 do Plano 15: painel, visitas, certificados, contratos,
 * adequações, faturas e download de documento do portal do cliente.
 *
 * Cobre os critérios de aceitação da task: escopo duplo nas cinco telas de
 * listagem, 404 para id de outro cliente (tanto na tela quanto no download),
 * download registrado em auditoria, módulo `portal_cliente` desligado
 * devolvendo a página de indisponível, paginação de 20 em 20 em toda lista,
 * e a inspeção estática dos dois controllers atrás de consulta Eloquent
 * direta.
 */
class PortalEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Client $clienteA;

    private Client $clienteB;

    private WorkOrder $visitaA;

    private Certificate $certificadoA;

    private Contract $contratoA;

    private WorkOrderAdequation $adequacaoAbertaA;

    private PaymentDetail $faturaPendenteA;

    private PaymentDetail $faturaPagaA;

    private WorkOrder $visitaB;

    private Certificate $certificadoB;

    private Contract $contratoB;

    private WorkOrderAdequation $adequacaoAbertaB;

    private PaymentDetail $faturaPendenteB;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        Carbon::setTestNow('2026-07-28 10:00:00');

        // A empresa 1 vem da migration de fundação do tenant.
        $this->empresa = Company::query()->firstOrFail();

        // Vincula o tenant fundador ao "Plano Interno", que libera todos os
        // módulos do catálogo, inclusive `portal_cliente`. Sem isto, toda
        // rota protegida por EnsurePortalModuleIsActive barraria mesmo o
        // cliente que deveria ter acesso.
        $this->seed(ModulesSeeder::class);

        TenantAtual::comTenant($this->empresa->id, function (): void {
            $this->clienteA = ClientFactory::new()->create(['name' => 'Cliente A']);
            $this->clienteB = ClientFactory::new()->create(['name' => 'Cliente B']);

            [
                $this->visitaA,
                $this->certificadoA,
                $this->contratoA,
                $this->adequacaoAbertaA,
                $this->faturaPendenteA,
                $this->faturaPagaA,
            ] = $this->criarCenarioDoCliente($this->clienteA);

            [
                $this->visitaB,
                $this->certificadoB,
                $this->contratoB,
                $this->adequacaoAbertaB,
                $this->faturaPendenteB,
            ] = $this->criarCenarioDoCliente($this->clienteB);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    /**
     * Monta visita, certificado, contrato, uma adequação em aberto e duas
     * faturas (uma pendente, uma paga) do cliente informado. Roda dentro do
     * tenant já resolvido pelo chamador.
     *
     * @return array{0: WorkOrder, 1: Certificate, 2: Contract, 3: WorkOrderAdequation, 4: PaymentDetail, 5: PaymentDetail}
     */
    private function criarCenarioDoCliente(Client $cliente): array
    {
        $endereco = Address::factory()->create(['client_id' => $cliente->id]);

        $visita = WorkOrder::factory()->create([
            'client_id' => $cliente->id,
            'address_id' => $endereco->id,
            'scheduled_date' => '2026-08-01',
            'status' => 'scheduled',
        ]);

        $certificado = Certificate::factory()->create([
            'client_id' => $cliente->id,
            'address_id' => $endereco->id,
            'status' => 'active',
            'execution_date' => '2026-07-24',
        ]);

        $contrato = Contract::create([
            'address_id' => $endereco->id,
            'contract_number' => 'CONT-'.$cliente->id,
            'start_date' => '2026-01-01',
            'end_date' => '2027-01-01',
            'service_value' => 1200.50,
            'service_type' => 'periodico',
            'visit_frequency_valor' => 3,
            'visit_frequency_unidade' => 'meses',
            'pest_target' => 'Baratas',
            'payment_method' => 'PIX',
        ]);

        $adequacaoAberta = WorkOrderAdequation::create([
            'work_order_id' => $visita->id,
            'type' => 'estrutural',
            'description' => 'Vedar frestas na parede',
            'priority' => 'alta',
            'status' => 'pendente',
            'deadline' => '2026-09-01',
        ]);

        $faturaPendente = PaymentDetail::factory()->create([
            'work_order_id' => $visita->id,
            'payment_due_date' => '2026-08-10',
            'payment_date' => null,
        ]);

        $faturaPaga = PaymentDetail::factory()->create([
            'work_order_id' => $visita->id,
            'payment_due_date' => '2026-06-01',
            'payment_date' => '2026-06-05',
            'amount_paid' => 500,
        ]);

        return [$visita, $certificado, $contrato, $adequacaoAberta, $faturaPendente, $faturaPaga];
    }

    private function autenticarComo(Client $cliente, string $email): ClientUser
    {
        TenantAtual::comTenant($this->empresa->id, fn (): ClientUser => ClientUser::create([
            'client_id' => $cliente->id,
            'nome' => 'Usuário do portal',
            'email' => $email,
            'password' => Hash::make('senha-teste-123'),
            'ativo' => true,
            'email_verificado_em' => now(),
        ]));

        $this->post('/portal/login', ['email' => $email, 'password' => 'senha-teste-123'])
            ->assertRedirect();

        return ClientUser::query()->where('email', $email)->firstOrFail();
    }

    // -----------------------------------------------------------------
    // Escopo duplo nas cinco telas de listagem
    // -----------------------------------------------------------------

    public function test_cliente_ve_apenas_as_proprias_visitas_certificados_contratos_e_faturas(): void
    {
        $this->autenticarComo($this->clienteA, 'escopo-a@exemplo.test');

        $this->assertPropriaListaExcluiOutroCliente('/portal/visitas', 'visitas', $this->visitaA->id, $this->visitaB->id);
        $this->assertPropriaListaExcluiOutroCliente('/portal/certificados', 'certificados', $this->certificadoA->id, $this->certificadoB->id);
        $this->assertPropriaListaExcluiOutroCliente('/portal/contratos', 'contratos', $this->contratoA->id, $this->contratoB->id);
        $this->assertPropriaListaExcluiOutroCliente('/portal/faturas', 'faturas', $this->faturaPendenteA->id, $this->faturaPendenteB->id);
        $this->assertPropriaListaExcluiOutroCliente('/portal/adequacoes', 'adequacoes', $this->adequacaoAbertaA->id, $this->adequacaoAbertaB->id);
    }

    private function assertPropriaListaExcluiOutroCliente(string $uri, string $prop, int $idProprio, int $idDeOutroCliente): void
    {
        $resposta = $this->get($uri);
        $resposta->assertOk();

        $paginado = $resposta->inertiaProps($prop);
        $ids = array_column($paginado['data'], 'id');

        $this->assertContains($idProprio, $ids, "'{$uri}' deveria conter o registro do próprio cliente");
        $this->assertNotContains($idDeOutroCliente, $ids, "'{$uri}' vazou o registro de outro cliente da mesma empresa");
    }

    // -----------------------------------------------------------------
    // Painel: combina próximas visitas, adequações em aberto e faturas vencendo
    // -----------------------------------------------------------------

    public function test_painel_combina_proximas_visitas_adequacoes_em_aberto_e_faturas_vencendo(): void
    {
        $this->autenticarComo($this->clienteA, 'painel@exemplo.test');

        $resposta = $this->get('/portal');
        $resposta->assertOk();

        $proximasVisitas = $resposta->inertiaProps('proximasVisitas');
        $adequacoesEmAberto = $resposta->inertiaProps('adequacoesEmAberto');
        $faturasVencendo = $resposta->inertiaProps('faturasVencendo');

        $this->assertContains($this->visitaA->id, array_column($proximasVisitas, 'id'));
        $this->assertContains($this->adequacaoAbertaA->id, array_column($adequacoesEmAberto, 'id'));

        $idsFaturasVencendo = array_column($faturasVencendo, 'id');
        $this->assertContains($this->faturaPendenteA->id, $idsFaturasVencendo);
        $this->assertNotContains($this->faturaPagaA->id, $idsFaturasVencendo, 'fatura já paga não deveria aparecer como vencendo');
        $this->assertNotContains($this->faturaPendenteB->id, $idsFaturasVencendo, 'fatura de outro cliente vazou no painel');
    }

    // -----------------------------------------------------------------
    // 404 para id de outro cliente (nunca 403), na tela e no download
    // -----------------------------------------------------------------

    public function test_acessar_visita_de_outro_cliente_devolve_404(): void
    {
        $this->autenticarComo($this->clienteA, '404-visita@exemplo.test');

        $this->get('/portal/visitas/'.$this->visitaB->id)->assertNotFound();
    }

    public function test_acessar_visita_inexistente_devolve_404(): void
    {
        $this->autenticarComo($this->clienteA, '404-inexistente@exemplo.test');

        $this->get('/portal/visitas/999999')->assertNotFound();
    }

    public function test_download_de_documento_de_outro_cliente_devolve_404(): void
    {
        $this->autenticarComo($this->clienteA, '404-download@exemplo.test');

        $this->get('/portal/documentos/visita/'.$this->visitaB->id)->assertNotFound();
        $this->get('/portal/documentos/certificado/'.$this->certificadoB->id)->assertNotFound();
        $this->get('/portal/documentos/contrato/'.$this->contratoB->id)->assertNotFound();
        $this->get('/portal/documentos/fatura/'.$this->faturaPendenteB->id)->assertNotFound();
    }

    public function test_download_com_tipo_desconhecido_devolve_404(): void
    {
        $this->autenticarComo($this->clienteA, '404-tipo@exemplo.test');

        $this->get('/portal/documentos/tipo-que-nao-existe/'.$this->visitaA->id)->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Download de documento próprio funciona e fica na auditoria
    // -----------------------------------------------------------------

    public function test_download_de_certificado_proprio_funciona_e_fica_na_auditoria(): void
    {
        $this->autenticarComo($this->clienteA, 'download-cert@exemplo.test');

        $resposta = $this->get('/portal/documentos/certificado/'.$this->certificadoA->id);

        $resposta->assertOk();

        $disposicao = (string) $resposta->headers->get('content-disposition');
        $this->assertStringContainsString('attachment', $disposicao);
        $this->assertStringContainsString('Certificado-2026-07-24.pdf', $disposicao);
        $this->assertStringNotContainsString((string) $this->certificadoA->id, $this->nomeDoArquivo($disposicao));

        $log = TenantAtual::comTenant($this->empresa->id, fn () => AuditLog::query()
            ->where('auditable_type', Certificate::class)
            ->where('auditable_id', $this->certificadoA->id)
            ->where('acao', 'download_portal')
            ->first());

        $this->assertNotNull($log, 'o download deveria ter ficado registrado em auditoria');
        $this->assertNull($log->user_id, 'user_id aponta para a tabela users, e quem baixou é um ClientUser');
        $this->assertStringContainsString('download-cert@exemplo.test', (string) $log->autor_nome);
    }

    public function test_download_de_visita_propria_funciona(): void
    {
        $this->autenticarComo($this->clienteA, 'download-visita@exemplo.test');

        $resposta = $this->get('/portal/documentos/visita/'.$this->visitaA->id);

        $resposta->assertOk();
        $this->assertStringContainsString(
            'Visita-2026-08-01.pdf',
            (string) $resposta->headers->get('content-disposition')
        );
    }

    public function test_download_de_contrato_proprio_funciona(): void
    {
        $this->autenticarComo($this->clienteA, 'download-contrato@exemplo.test');

        $resposta = $this->get('/portal/documentos/contrato/'.$this->contratoA->id);

        $resposta->assertOk();
        $this->assertStringContainsString(
            'Contrato-2026-01-01.pdf',
            (string) $resposta->headers->get('content-disposition')
        );
    }

    public function test_download_de_fatura_paga_funciona_como_recibo(): void
    {
        $this->autenticarComo($this->clienteA, 'download-fatura-paga@exemplo.test');

        $resposta = $this->get('/portal/documentos/fatura/'.$this->faturaPagaA->id);

        $resposta->assertOk();
        $this->assertStringContainsString(
            'Recibo-2026-06-05.pdf',
            (string) $resposta->headers->get('content-disposition')
        );
    }

    public function test_download_de_fatura_ainda_nao_paga_devolve_409_sem_gerar_arquivo(): void
    {
        $this->autenticarComo($this->clienteA, 'download-fatura-pendente@exemplo.test');

        $this->get('/portal/documentos/fatura/'.$this->faturaPendenteA->id)->assertStatus(409);
    }

    /**
     * Extrai só o nome do arquivo do cabeçalho `Content-Disposition`, para não
     * confundir um id que apareça por acaso em outra parte do cabeçalho.
     */
    private function nomeDoArquivo(string $disposicao): string
    {
        return preg_replace('/^.*filename="?([^"]+)"?.*$/', '$1', $disposicao);
    }

    // -----------------------------------------------------------------
    // Módulo portal_cliente desligado: página de indisponível, nunca erro seco
    // -----------------------------------------------------------------

    public function test_tenant_sem_o_modulo_portal_cliente_ve_a_pagina_de_indisponivel(): void
    {
        $this->autenticarComo($this->clienteA, 'sem-modulo@exemplo.test');

        $moduloPortal = Module::query()->where('chave', 'portal_cliente')->firstOrFail();
        app(ModuleService::class)->bloquearPara($this->empresa, $moduloPortal, 'Teste: módulo bloqueado');

        $destino = route('portal.modulo-indisponivel');

        foreach (['/portal', '/portal/visitas', '/portal/certificados', '/portal/contratos', '/portal/adequacoes', '/portal/faturas'] as $uri) {
            $this->get($uri)->assertRedirect($destino);
        }

        $respostaJson = $this->getJson('/portal/visitas');
        $respostaJson->assertStatus(403);
        $respostaJson->assertJson(['modulo' => 'portal_cliente']);

        // A própria página de indisponível continua acessível, fora do bloqueio.
        $paginaIndisponivel = $this->get('/portal/modulo-indisponivel');
        $paginaIndisponivel->assertOk();
        $paginaIndisponivel->assertInertia(fn ($pagina) => $pagina->component('Portal/ModuloIndisponivel', false));
    }

    public function test_tenant_com_o_modulo_portal_cliente_acessa_normalmente(): void
    {
        $this->autenticarComo($this->clienteA, 'com-modulo@exemplo.test');

        $this->get('/portal')->assertOk();
        $this->get('/portal/visitas')->assertOk();
    }

    // -----------------------------------------------------------------
    // Paginação: 20 itens por página em toda tela de listagem
    // -----------------------------------------------------------------

    public function test_todas_as_cinco_telas_de_listagem_paginam_com_vinte_itens_por_pagina(): void
    {
        $this->autenticarComo($this->clienteA, 'paginacao@exemplo.test');

        foreach (['visitas', 'certificados', 'contratos', 'adequacoes', 'faturas'] as $prop) {
            $resposta = $this->get('/portal/'.$prop);
            $resposta->assertOk();

            $paginado = $resposta->inertiaProps($prop);

            $this->assertIsArray($paginado, "'{$prop}' deveria vir paginado (array com chave 'data')");
            $this->assertArrayHasKey('data', $paginado);
            $this->assertArrayHasKey('per_page', $paginado);
            $this->assertSame(20, (int) $paginado['per_page'], "'/portal/{$prop}' deveria paginar com 20 itens por página");
        }
    }

    // -----------------------------------------------------------------
    // Nenhum controller do portal monta consulta Eloquent própria a model de
    // domínio - tudo passa por PortalService (inspeção estática do código-fonte)
    // -----------------------------------------------------------------

    public function test_nenhum_controller_do_portal_consulta_model_de_dominio_diretamente(): void
    {
        $arquivos = [
            app_path('Http/Controllers/Portal/PortalController.php'),
            app_path('Http/Controllers/Portal/PortalDocumentController.php'),
        ];

        // AuditLog é infraestrutura de auditoria, não dado de domínio do
        // cliente, e ClientUser só é usado aqui para tipar o retorno do guard
        // - por isso os dois ficam fora desta lista.
        $modelosDeDominio = [
            'WorkOrder', 'Certificate', 'Contract', 'PaymentDetail',
            'Client', 'Address', 'WorkOrderAdequation',
        ];

        foreach ($arquivos as $arquivo) {
            $conteudo = file_get_contents($arquivo);
            $this->assertNotFalse($conteudo, "não consegui ler {$arquivo}");

            foreach ($modelosDeDominio as $modelo) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\b'.$modelo.'::(query|where|find|findOrFail|first|firstOrFail|all|create|paginate)\s*\(/',
                    $conteudo,
                    basename($arquivo)." parece ter uma consulta Eloquent direta a {$modelo}; isso deveria vir do PortalService"
                );
            }
        }
    }
}
