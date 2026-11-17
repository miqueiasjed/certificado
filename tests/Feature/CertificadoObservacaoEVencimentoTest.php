<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Certificate;
use App\Models\Company;
use App\Models\User;
use App\Services\CertificateService;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Database\Factories\ClientFactory;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Observação impressa no PDF do certificado e lista de certificados a
 * renovar que alimenta o aviso de vencimento do painel.
 */
class CertificadoObservacaoEVencimentoTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        Carbon::setTestNow('2026-11-16 10:00:00');

        $this->empresa = Company::query()->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    public function test_observacao_aparece_no_pdf_do_certificado(): void
    {
        TenantAtual::comTenant($this->empresa->id, function (): void {
            $certificado = Certificate::factory()->create([
                'notes' => 'Cliente com monitoramento semanal',
            ]);

            $html = view('pdf.certificate', app(CertificateService::class)->preparePdfData($certificado))->render();

            $this->assertStringContainsString('OBSERVAÇÕES', $html);
            $this->assertStringContainsString('Cliente com monitoramento semanal', $html);
        });
    }

    public function test_pdf_sem_observacao_nao_imprime_o_bloco(): void
    {
        TenantAtual::comTenant($this->empresa->id, function (): void {
            $certificado = Certificate::factory()->create(['notes' => null]);

            $html = view('pdf.certificate', app(CertificateService::class)->preparePdfData($certificado))->render();

            $this->assertStringNotContainsString('OBSERVAÇÕES', $html);
        });
    }

    public function test_lista_a_renovar_considera_a_janela_e_ignora_renovado_e_cancelado(): void
    {
        TenantAtual::comTenant($this->empresa->id, function (): void {
            $cliente = ClientFactory::new()->create();
            $endereco = Address::factory()->create(['client_id' => $cliente->id]);
            $base = ['client_id' => $cliente->id, 'address_id' => $endereco->id];

            $aVencer = Certificate::factory()->create($base + ['warranty' => '2026-11-26']);
            $foraDaJanela = Certificate::factory()->create(['warranty' => '2027-02-01']);
            $cancelado = Certificate::factory()->cancelado()->create(['warranty' => '2026-11-20']);

            // Vencido há 10 dias, mas renovado depois: não entra.
            $outroCliente = ClientFactory::new()->create();
            $renovadoAntigo = Certificate::factory()->create(['client_id' => $outroCliente->id, 'warranty' => '2026-11-06']);
            Certificate::factory()->create(['client_id' => $outroCliente->id, 'warranty' => '2027-05-06']);

            // Vencido há 5 dias, sem renovação: entra.
            $vencido = Certificate::factory()->create(['warranty' => '2026-11-11']);

            $resultado = app(CertificateService::class)->certificadosParaRenovar();
            $ids = $resultado['itens']->pluck('id')->all();

            $this->assertSame(2, $resultado['total']);
            $this->assertSame([$vencido->id, $aVencer->id], $ids);
            $this->assertNotContains($foraDaJanela->id, $ids);
            $this->assertNotContains($cancelado->id, $ids);
            $this->assertNotContains($renovadoAntigo->id, $ids);

            $this->assertSame(-5, $resultado['itens'][0]['dias']);
            $this->assertSame(10, $resultado['itens'][1]['dias']);
        });
    }

    public function test_renovacao_sem_endereco_cobre_o_certificado_com_endereco(): void
    {
        TenantAtual::comTenant($this->empresa->id, function (): void {
            $cliente = ClientFactory::new()->create();
            $endereco = Address::factory()->create(['client_id' => $cliente->id]);

            Certificate::factory()->create(['client_id' => $cliente->id, 'address_id' => $endereco->id, 'warranty' => '2026-11-10']);
            Certificate::factory()->create(['client_id' => $cliente->id, 'address_id' => null, 'warranty' => '2027-05-10']);

            $this->assertSame(0, app(CertificateService::class)->certificadosParaRenovar()['total']);
        });
    }

    public function test_quadro_do_painel_so_vai_para_quem_ve_certificado(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ModulesSeeder::class);

        $criar = fn (): User => TenantAtual::comTenant($this->empresa->id, fn () => User::factory()->create([
            'email' => 'usuario-'.uniqid().'@exemplo.test',
            'is_active' => true,
        ]));

        TenantAtual::comTenant($this->empresa->id, fn () => Certificate::factory()->create(['warranty' => '2026-11-20']));

        $comPermissao = $criar();
        $comPermissao->givePermissionTo('certificado-ver');
        $semPermissao = $criar();

        TenantAtual::limpar();
        $this->actingAs($comPermissao->fresh())->get('/dashboard')->assertOk()
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina->where('certificadosParaRenovar.total', 1));

        TenantAtual::limpar();
        $this->actingAs($semPermissao->fresh())->get('/dashboard')->assertOk()
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina->where('certificadosParaRenovar', null));
    }

    public function test_data_de_emissao_usa_o_dia_de_brasilia(): void
    {
        TenantAtual::comTenant($this->empresa->id, function (): void {
            // 17/11 00h30 UTC ainda é 16/11 21h30 em Brasília.
            $certificado = Certificate::factory()->create();
            $certificado->forceFill(['created_at' => Carbon::parse('2026-11-17 00:30:00', 'UTC')])->save();

            $html = view('pdf.certificate', app(CertificateService::class)->preparePdfData($certificado->fresh()))->render();

            $this->assertStringContainsString('16/11/2026', $html);
            $this->assertStringNotContainsString('17/11/2026', $html);
        });
    }
}
