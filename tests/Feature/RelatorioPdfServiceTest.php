<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Client;
use App\Models\Company;
use App\Models\Device;
use App\Models\DevicePosition;
use App\Models\FloorPlan;
use App\Models\MonitoringReport;
use App\Models\PestSighting;
use App\Models\User;
use App\Models\WorkOrderDeviceEvent;
use App\Services\FloorPlanService;
use App\Services\Monitoring\ConsolidadorDePeriodo;
use App\Services\Monitoring\RelatorioPdfService;
use App\Support\TenantAtual;
use Closure;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Factories\WorkOrderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Task 21.6 do Plano 21: PDF definitivo do relatório de monitoramento e do
 * croqui da planta.
 *
 * Cobre os critérios de aceitação da task: seções na ordem prevista, período
 * sem visita marcado (nunca como barra de valor zero indistinguível), escala
 * absoluta ao lado da intensidade normalizada na legenda do mapa de calor,
 * croqui usando a planta vigente no FIM DO PERÍODO (nunca a atual), as duas
 * versões do croqui (técnico/cliente), e geração sem estourar memória/timeout
 * com o volume citado na task (40 dispositivos, 12 visitas).
 *
 * Teste descartável, no mesmo espírito de `MapaDeCalorServiceTest` e
 * `TendenciaServiceTest`. Testes formais são escopo da Task 21.9.
 */
class RelatorioPdfServiceTest extends TestCase
{
    use RefreshDatabase;

    private RelatorioPdfService $servico;

    private Company $empresa;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        Storage::fake('public');

        $this->servico = app(RelatorioPdfService::class);
        $this->empresa = Company::query()->firstOrFail();
        $this->usuario = $this->comTenant(fn () => User::factory()->create(['is_active' => true]));
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // O PDF sai com todas as seções, e período sem visita aparece marcado
    // -----------------------------------------------------------------

    public function test_pdf_sai_com_todas_as_secoes_e_marca_periodo_sem_visita(): void
    {
        $relatorio = $this->cenarioComUmMesSemVisita();

        $dados = $this->comTenant(fn () => $this->servico->dados($relatorio));

        $this->assertSame(1, $dados['resumo']['visitas']);
        $this->assertCount(1, $dados['porEndereco']);

        $endereco = $dados['porEndereco'][0];
        $this->assertTrue($endereco['grafico_evolucao']['tem_dados']);

        $barras = collect($endereco['grafico_evolucao']['barras']);
        $barraComVisita = $barras->firstWhere('sem_visita', false);
        $barraSemVisita = $barras->firstWhere('sem_visita', true);

        $this->assertNotNull($barraComVisita, 'precisa existir ao menos um mês com visita no cenário');
        $this->assertNotNull($barraSemVisita, 'precisa existir ao menos um mês sem visita no cenário');
        $this->assertGreaterThan(0, $barraComVisita['valor']);

        // Mapa de calor: escala absoluta sempre ao lado da intensidade.
        $this->assertTrue($endereco['mapa_de_calor']['suportado']);
        $this->assertGreaterThan(0, $endereco['mapa_de_calor']['maximo']);
        foreach ($endereco['mapa_de_calor']['barras'] as $barraMapa) {
            $this->assertArrayHasKey('valor_absoluto', $barraMapa);
            $this->assertArrayHasKey('intensidade_percentual', $barraMapa);
        }

        // Renderiza a view de verdade (sem passar pelo dompdf) para conferir
        // que cada seção aparece no HTML final, inclusive a marcação textual
        // de "sem visita" - nunca uma barra de valor zero indistinguível de
        // "visitado e sem ocorrência".
        $html = view('pdf.monitoring-report', $dados)->render();

        $this->assertStringContainsString('Relatório de Monitoramento', $html);
        $this->assertStringContainsString('Resumo do período', $html);
        $this->assertStringContainsString('Evolução no período', $html);
        $this->assertStringContainsString('Ranking dos pontos críticos', $html);
        $this->assertStringContainsString('Mapa de calor por área', $html);
        $this->assertStringContainsString('Ocorrência por espécie', $html);
        $this->assertStringContainsString('Croqui', $html);
        $this->assertStringContainsString('Adequações em aberto', $html);
        $this->assertStringContainsString(
            'SEM VISITA',
            $html,
            'período sem visita precisa aparecer marcado, nunca como barra de valor zero indistinguível'
        );
        $this->assertStringContainsString(
            'corresponde a',
            $html,
            'legenda do mapa de calor precisa citar a escala absoluta por extenso, ao lado da intensidade normalizada'
        );
    }

    // -----------------------------------------------------------------
    // Croqui usa a planta vigente no FIM DO PERÍODO, não a atual
    // -----------------------------------------------------------------

    public function test_croqui_do_relatorio_usa_a_planta_congelada_mesmo_apos_nova_versao_ativa(): void
    {
        [$relatorio, $plantaV1] = $this->cenarioComCroqui();

        // Substitui a planta DEPOIS do relatório já gerado: v2 nasce ativa, e
        // a v1 deixa de ser - exatamente o cenário que a task pede conferir.
        $plantaV2 = $this->comTenant(fn () => app(FloorPlanService::class)->substituir(
            $plantaV1->fresh(),
            UploadedFile::fake()->image('planta-v2.png', 900, 650)
        ));
        $this->assertTrue($plantaV2->ativa);
        $this->assertFalse($plantaV1->fresh()->ativa);

        $dados = $this->comTenant(fn () => $this->servico->dados($relatorio));
        $croqui = $dados['porEndereco'][0]['croqui'];

        $this->assertNotNull($croqui);
        $this->assertSame(
            1,
            $croqui['planta_versao'],
            'o croqui do relatório já gerado precisa continuar na versão 1, mesmo com a v2 ativa hoje'
        );
    }

    // -----------------------------------------------------------------
    // Croqui técnico x cliente
    // -----------------------------------------------------------------

    public function test_croqui_tecnico_traz_codigo_publico_e_croqui_cliente_nao(): void
    {
        [$relatorio] = $this->cenarioComCroqui();

        $dadosTecnico = $this->comTenant(fn () => $this->servico->dados($relatorio, RelatorioPdfService::VERSAO_TECNICO));
        $dadosCliente = $this->comTenant(fn () => $this->servico->dados($relatorio, RelatorioPdfService::VERSAO_CLIENTE));

        $pontoTecnico = $dadosTecnico['porEndereco'][0]['croqui']['pontos'][0];
        $pontoCliente = $dadosCliente['porEndereco'][0]['croqui']['pontos'][0];

        $this->assertNotNull($pontoTecnico['codigo_publico']);
        $this->assertNotNull($pontoTecnico['rotulo']);
        $this->assertNull($pontoTecnico['estado']);

        $this->assertNull($pontoCliente['codigo_publico']);
        $this->assertNull($pontoCliente['rotulo']);
        $this->assertNotNull($pontoCliente['estado']);

        // A mesma numeração identifica o mesmo ponto físico nas duas
        // versões, mesmo a versão cliente não revelando qual dispositivo é.
        $this->assertSame($pontoTecnico['numero'], $pontoCliente['numero']);

        // A distinção técnico/cliente é do CROQUI, não do resto do PDF: o
        // ranking de pontos críticos já mostra código público nas duas
        // versões hoje (mesmo dado que `PortalRelatorioController::paraPortal()`
        // já expõe ao cliente no portal, ver o docblock da classe), então a
        // checagem de string precisa mirar só o fragmento do croqui, não a
        // página inteira.
        $croquiTecnico = $dadosTecnico['porEndereco'][0]['croqui'];
        $croquiCliente = $dadosCliente['porEndereco'][0]['croqui'];

        $htmlCroquiTecnico = view('pdf.partials.croqui-planta', ['croqui' => $croquiTecnico, 'maxLarguraMm' => 175, 'maxAlturaMm' => 140])->render();
        $htmlCroquiCliente = view('pdf.partials.croqui-planta', ['croqui' => $croquiCliente, 'maxLarguraMm' => 175, 'maxAlturaMm' => 140])->render();

        $this->assertStringContainsString((string) $pontoTecnico['codigo_publico'], $htmlCroquiTecnico);
        $this->assertStringNotContainsString((string) $pontoTecnico['codigo_publico'], $htmlCroquiCliente);
    }

    // -----------------------------------------------------------------
    // Croqui direto de uma planta (fora de relatório) não carrega dado de
    // período nenhum
    // -----------------------------------------------------------------

    public function test_croqui_direto_de_uma_planta_nao_carrega_dado_de_periodo(): void
    {
        $planta = $this->comTenant(function (): FloorPlan {
            $cliente = ClientFactory::new()->create();
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);
            $planta = $this->criarPlantaComImagem($endereco);

            $dispositivo = Device::create([
                'address_id' => $endereco->id,
                'label' => 'Isca 01',
                'number' => 'N-'.uniqid(),
                'active' => true,
            ]);
            DevicePosition::create(['floor_plan_id' => $planta->id, 'device_id' => $dispositivo->id, 'x' => 0.4, 'y' => 0.6]);

            return $planta;
        });

        $dados = $this->comTenant(fn () => $this->servico->dadosDoCroqui($planta, RelatorioPdfService::VERSAO_CLIENTE));

        $this->assertCount(1, $dados['croqui']['pontos']);
        $this->assertNull($dados['croqui']['pontos'][0]['estado'], 'sem contexto de relatório, não existe estado de período para mostrar');

        $html = view('pdf.floor-plan', $dados)->render();
        $this->assertStringContainsString('CROQUI DA PLANTA', $html);
    }

    // -----------------------------------------------------------------
    // 40 dispositivos, 12 visitas: gera sem estourar memória/timeout
    // -----------------------------------------------------------------

    public function test_gera_pdf_com_40_dispositivos_e_12_visitas_sem_estourar_memoria_ou_timeout(): void
    {
        $relatorio = $this->cenarioComVolume(dispositivos: 40, visitas: 12);

        $inicio = microtime(true);
        $memoriaAntes = memory_get_usage();

        $conteudo = $this->comTenant(fn () => $this->servico->relatorio($relatorio)->output());

        $duracao = microtime(true) - $inicio;
        $memoriaDepois = memory_get_peak_usage();

        $this->assertNotEmpty($conteudo);
        $this->assertStringStartsWith('%PDF', $conteudo);
        $this->assertLessThan(
            30.0,
            $duracao,
            'geração com 40 dispositivos e 12 visitas não pode se aproximar de um timeout de requisição web'
        );
        $this->assertLessThan(
            512 * 1024 * 1024,
            $memoriaDepois - $memoriaAntes,
            'geração não pode consumir memória desproporcional ao volume de dado'
        );
    }

    // -----------------------------------------------------------------
    // PDF real, salvo em disco, para conferência visual - a task exige
    // explicitamente "conferir o PDF gerado com dado real antes de dar a
    // task por concluída"
    // -----------------------------------------------------------------

    public function test_gera_pdf_real_para_inspecao_visual(): void
    {
        [$relatorio] = $this->cenarioComCroqui(comOcorrenciaPorEspecie: true);

        $diretorio = '/private/tmp/claude-501/-Users-miqueias-Sites-certificado/861e73d1-0ac2-413f-a7d3-18f6454fb364/scratchpad';

        $caminhoTecnico = $diretorio.'/relatorio-monitoramento-tecnico.pdf';
        $caminhoCliente = $diretorio.'/relatorio-monitoramento-cliente.pdf';

        $this->comTenant(function () use ($relatorio, $caminhoTecnico, $caminhoCliente): void {
            $this->servico->relatorio($relatorio, RelatorioPdfService::VERSAO_TECNICO)->save($caminhoTecnico);
            $this->servico->relatorio($relatorio, RelatorioPdfService::VERSAO_CLIENTE)->save($caminhoCliente);
        });

        $this->assertFileExists($caminhoTecnico);
        $this->assertFileExists($caminhoCliente);
        $this->assertGreaterThan(5000, filesize($caminhoTecnico));
    }

    // -----------------------------------------------------------------
    // Cenários de apoio
    // -----------------------------------------------------------------

    /**
     * Endereço com 1 dispositivo, 1 visita em julho/2026 (com captura) e
     * nenhum evento em agosto/2026: o relatório cobre os dois meses, então
     * agosto precisa sair marcado como "sem visita".
     */
    private function cenarioComUmMesSemVisita(): MonitoringReport
    {
        [$cliente, $endereco] = $this->comTenant(function (): array {
            $cliente = ClientFactory::new()->create();
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);
            $planta = $this->criarPlantaComImagem($endereco);
            $dispositivo = Device::create([
                'address_id' => $endereco->id,
                'label' => 'Armadilha Cozinha',
                'number' => 'N-'.uniqid(),
                'active' => true,
            ]);
            DevicePosition::create(['floor_plan_id' => $planta->id, 'device_id' => $dispositivo->id, 'x' => 0.5, 'y' => 0.5]);

            $ordem = WorkOrderFactory::new()->create([
                'client_id' => $cliente->id,
                'address_id' => $endereco->id,
                'scheduled_date' => '2026-07-10',
            ]);

            WorkOrderDeviceEvent::create([
                'work_order_id' => $ordem->id,
                'device_id' => $dispositivo->id,
                'event_date' => '2026-07-10',
                'pest_found' => 'Baratas',
            ]);

            PestSighting::create([
                'address_id' => $endereco->id,
                'work_order_id' => $ordem->id,
                'sighting_date' => '2026-07-10 09:00:00',
                'pest_type' => 'cockroaches',
                'severity_level' => 'medium',
                'location_description' => 'Cozinha',
                'active' => true,
            ]);

            return [$cliente, $endereco];
        });

        return $this->gerarRelatorio($cliente, $endereco, '2026-07-01', '2026-08-31');
    }

    /**
     * Endereço com planta versionada (v1, com imagem real) e 1 dispositivo
     * posicionado, com captura no período - cenário base dos testes de
     * croqui.
     *
     * @return array{0: MonitoringReport, 1: FloorPlan}
     */
    private function cenarioComCroqui(bool $comOcorrenciaPorEspecie = false): array
    {
        [$cliente, $endereco, $planta] = $this->comTenant(function () use ($comOcorrenciaPorEspecie): array {
            $cliente = ClientFactory::new()->create();
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);
            $planta = $this->criarPlantaComImagem($endereco);

            $dispositivo = Device::create([
                'address_id' => $endereco->id,
                'label' => 'Armadilha Entrada',
                'number' => 'N-'.uniqid(),
                'active' => true,
            ]);
            DevicePosition::create(['floor_plan_id' => $planta->id, 'device_id' => $dispositivo->id, 'x' => 0.35, 'y' => 0.55]);

            $ordem = WorkOrderFactory::new()->create([
                'client_id' => $cliente->id,
                'address_id' => $endereco->id,
                'scheduled_date' => '2026-07-10',
            ]);

            foreach (['2026-07-05', '2026-07-15'] as $data) {
                WorkOrderDeviceEvent::create([
                    'work_order_id' => $ordem->id,
                    'device_id' => $dispositivo->id,
                    'event_date' => $data,
                    'pest_found' => 'Ratos',
                ]);
            }

            if ($comOcorrenciaPorEspecie) {
                foreach (['cockroaches', 'rats', 'ants'] as $especie) {
                    PestSighting::create([
                        'address_id' => $endereco->id,
                        'work_order_id' => $ordem->id,
                        'sighting_date' => '2026-07-12 10:00:00',
                        'pest_type' => $especie,
                        'severity_level' => 'medium',
                        'location_description' => 'Área de teste',
                        'active' => true,
                    ]);
                }
            }

            return [$cliente, $endereco, $planta];
        });

        $relatorio = $this->gerarRelatorio($cliente, $endereco, '2026-07-01', '2026-07-31');

        return [$relatorio, $planta];
    }

    /**
     * Endereço com `$dispositivos` dispositivos, cada um com `$visitas`
     * ordens de serviço concluídas ao longo do ano, e planta com todos
     * posicionados - volume equivalente ao pior caso citado na task ("12
     * visitas e 40 dispositivos").
     */
    private function cenarioComVolume(int $dispositivos, int $visitas): MonitoringReport
    {
        [$cliente, $endereco] = $this->comTenant(function () use ($dispositivos, $visitas): array {
            $cliente = ClientFactory::new()->create();
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);
            $planta = $this->criarPlantaComImagem($endereco);

            $listaDeDispositivos = [];
            for ($i = 0; $i < $dispositivos; $i++) {
                $dispositivo = Device::create([
                    'address_id' => $endereco->id,
                    'label' => "Ponto {$i}",
                    'number' => 'N-'.$i.'-'.uniqid(),
                    'active' => true,
                ]);
                $listaDeDispositivos[] = $dispositivo;

                DevicePosition::create([
                    'floor_plan_id' => $planta->id,
                    'device_id' => $dispositivo->id,
                    'x' => 0.05 + (($i % 10) * 0.09),
                    'y' => 0.05 + (intdiv($i, 10) * 0.2),
                ]);
            }

            for ($visita = 0; $visita < $visitas; $visita++) {
                $mes = min($visita + 1, 12);
                $data = sprintf('2026-%02d-10', $mes);

                $ordem = WorkOrderFactory::new()->create([
                    'client_id' => $cliente->id,
                    'address_id' => $endereco->id,
                    'scheduled_date' => $data,
                ]);

                foreach ($listaDeDispositivos as $indice => $dispositivo) {
                    WorkOrderDeviceEvent::create([
                        'work_order_id' => $ordem->id,
                        'device_id' => $dispositivo->id,
                        'event_date' => $data,
                        'pest_found' => $indice % 4 === 0 ? 'Baratas' : null,
                    ]);
                }
            }

            return [$cliente, $endereco];
        });

        return $this->gerarRelatorio($cliente, $endereco, '2026-01-01', '2026-12-31');
    }

    private function criarPlantaComImagem(Address $endereco): FloorPlan
    {
        return app(FloorPlanService::class)->enviar(
            $endereco,
            UploadedFile::fake()->image('planta.png', 800, 600),
            ['nome' => 'Térreo']
        );
    }

    private function gerarRelatorio(Client $cliente, Address $endereco, string $de, string $ate): MonitoringReport
    {
        return $this->comTenant(function () use ($cliente, $endereco, $de, $ate): MonitoringReport {
            $dados = app(ConsolidadorDePeriodo::class)->consolidar($cliente, $endereco, $de, $ate);

            return MonitoringReport::create([
                'client_id' => $cliente->id,
                'address_id' => $endereco->id,
                'periodo_inicio' => $de,
                'periodo_fim' => $ate,
                'gerado_em' => now(),
                'gerado_por' => $this->usuario->id,
                'dados' => $dados,
                'publicado_no_portal' => false,
            ]);
        });
    }

    private function comTenant(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->id, $callback);
    }
}
