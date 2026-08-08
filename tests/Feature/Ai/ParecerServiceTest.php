<?php

namespace Tests\Feature\Ai;

use App\Exceptions\ParecerNaoRevisadoException;
use App\Exceptions\RascunhoJaExisteException;
use App\Exceptions\TetoDeIaAtingidoException;
use App\Models\Address;
use App\Models\AiDraft;
use App\Models\AiUsage;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Client;
use App\Models\Company;
use App\Models\Module;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Ai\MedicaoDeUsoService;
use App\Services\Ai\ParecerService;
use App\Services\Ai\ProvedorDeTexto;
use App\Services\CertificateService;
use App\Services\ModuleService;
use App\Services\WorkOrderService;
use App\Support\Ai\RespostaDeTexto;
use App\Support\TenantAtual;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 25.7 do Plano 25: o rascunho de parecer e a guarda de emissão.
 *
 * O teste que percorre os métodos de emissão é o que impede que uma rota nova,
 * criada meses depois, publique laudo não revisado: a guarda mora no Service,
 * e é lá que ele confere.
 *
 * O provedor é sempre simulado. Nenhum teste faz chamada real: o custo é por
 * chamada, e teste que gasta dinheiro deixa de ser rodado.
 */
class ParecerServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private User $usuario;

    private ParecerService $servico;

    private object $provedorFake;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ModulesSeeder::class);

        $this->empresa = Company::query()->firstOrFail();
        $this->provedorFake = $this->ligarProvedorFake();
        $this->servico = app(ParecerService::class);

        $this->usuario = TenantAtual::comTenant($this->empresa->id, fn (): User => User::create([
            'name' => 'Responsável Técnico',
            'email' => 'responsavel@exemplo.test',
            'password' => bcrypt('senha-de-teste'),
        ]));
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Geração
    // -----------------------------------------------------------------

    public function test_gerar_cria_o_rascunho_na_situacao_gerado(): void
    {
        $rascunho = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): AiDraft => $this->servico->gerarParaOs($this->criarOs(), $this->usuario)
        );

        $this->assertSame(AiDraft::SITUACAO_GERADO, $rascunho->situacao);
        $this->assertSame(AiDraft::TIPO_PARECER_OS, $rascunho->tipo);
        $this->assertSame('Parecer simulado para teste.', $rascunho->conteudo_gerado);
        $this->assertNull($rascunho->conteudo_revisado);
        $this->assertNull($rascunho->revisado_em);
        $this->assertSame($this->empresa->id, $rascunho->company_id);
        $this->assertFalse($rascunho->revisado);
    }

    public function test_segunda_geracao_para_a_mesma_origem_e_recusada_apontando_a_existente(): void
    {
        TenantAtual::comTenant($this->empresa->id, function (): void {
            $os = $this->criarOs();
            $primeiro = $this->servico->gerarParaOs($os, $this->usuario);

            try {
                $this->servico->gerarParaOs($os, $this->usuario);
                $this->fail('Esperava RascunhoJaExisteException na segunda geração.');
            } catch (RascunhoJaExisteException $e) {
                $this->assertSame($primeiro->id, $e->existente->id);
            }
        });

        $this->assertSame(1, TenantAtual::comTenant(
            $this->empresa->id,
            fn (): int => AiDraft::query()->count()
        ));
    }

    public function test_descartar_libera_uma_nova_geracao(): void
    {
        TenantAtual::comTenant($this->empresa->id, function (): void {
            $os = $this->criarOs();
            $primeiro = $this->servico->gerarParaOs($os, $this->usuario);

            $this->servico->descartar($primeiro, $this->usuario);

            $segundo = $this->servico->gerarParaOs($os, $this->usuario);

            $this->assertNotSame($primeiro->id, $segundo->id);
            $this->assertSame(AiDraft::SITUACAO_GERADO, $segundo->situacao);
            // O texto descartado continua registrado: a pergunta "o que o
            // modelo escreveu antes de alguém refazer" precisa ter resposta.
            $this->assertSame(
                AiDraft::SITUACAO_DESCARTADO,
                $primeiro->fresh()->situacao
            );
            $this->assertNotNull($primeiro->fresh()->conteudo_gerado);
        });
    }

    // -----------------------------------------------------------------
    // Revisão
    // -----------------------------------------------------------------

    public function test_revisar_grava_o_texto_revisado_sem_tocar_no_gerado(): void
    {
        TenantAtual::comTenant($this->empresa->id, function (): void {
            $rascunho = $this->servico->gerarParaOs($this->criarOs(), $this->usuario);
            $geradoOriginal = $rascunho->conteudo_gerado;

            $revisado = $this->servico->revisar(
                $rascunho,
                'Texto reescrito integralmente pelo responsável técnico após conferência.',
                $this->usuario
            );

            $this->assertSame($geradoOriginal, $revisado->conteudo_gerado);
            $this->assertSame(
                'Texto reescrito integralmente pelo responsável técnico após conferência.',
                $revisado->conteudo_revisado
            );
            $this->assertSame(AiDraft::SITUACAO_REVISADO, $revisado->situacao);
            $this->assertSame($this->usuario->id, $revisado->revisado_por);
            $this->assertNotNull($revisado->revisado_em);
        });
    }

    public function test_revisao_aparece_na_auditoria_com_autor_e_instante(): void
    {
        $rascunho = TenantAtual::comTenant($this->empresa->id, function (): AiDraft {
            $rascunho = $this->servico->gerarParaOs($this->criarOs(), $this->usuario);

            return $this->servico->revisar($rascunho, 'Parecer conferido e aprovado pelo responsável técnico.', $this->usuario);
        });

        $registro = TenantAtual::comTenant($this->empresa->id, fn (): ?AuditLog => AuditLog::query()
            ->where('auditable_type', AiDraft::class)
            ->where('auditable_id', $rascunho->id)
            ->where('acao', 'alterado')
            ->latest('id')
            ->first());

        $this->assertNotNull($registro, 'A revisão do parecer precisa ficar na auditoria.');
        $this->assertNotNull($registro->created_at);
        $this->assertArrayHasKey('conteudo_revisado', $registro->valores_depois ?? []);
    }

    // -----------------------------------------------------------------
    // Guarda de emissão: o teste que impede laudo não revisado sair
    // -----------------------------------------------------------------

    /**
     * Percorre os dois métodos de emissão de documento e afirma em cada um.
     * A lista está aqui à mão de propósito: método de emissão novo que não
     * entrar nela é justamente o que este teste existe para cobrar em revisão.
     */
    public function test_nenhum_metodo_de_emissao_publica_documento_com_parecer_nao_revisado(): void
    {
        TenantAtual::comTenant($this->empresa->id, function (): void {
            $os = $this->criarOs();
            $certificado = $this->criarCertificado($os);

            $this->servico->gerarParaOs($os, $this->usuario);
            $this->gerarParaCertificado($certificado);

            $emissoes = [
                'ordem de serviço' => fn () => app(WorkOrderService::class)->preparePdfData($os),
                'certificado' => fn () => app(CertificateService::class)->preparePdfData($certificado),
            ];

            foreach ($emissoes as $documento => $emitir) {
                try {
                    $emitir();
                    $this->fail("A emissão de {$documento} passou com parecer não revisado.");
                } catch (ParecerNaoRevisadoException $e) {
                    $this->assertStringContainsString('revisad', mb_strtolower($e->getMessage()));
                }
            }
        });
    }

    public function test_emissao_passa_depois_da_revisao(): void
    {
        TenantAtual::comTenant($this->empresa->id, function (): void {
            $os = $this->criarOs();
            $rascunho = $this->servico->gerarParaOs($os, $this->usuario);

            $this->servico->revisar($rascunho, 'Parecer conferido e aprovado pelo responsável técnico.', $this->usuario);

            $dados = app(WorkOrderService::class)->preparePdfData($os);

            $this->assertArrayHasKey('workOrder', $dados);
        });
    }

    public function test_ordem_de_servico_sem_rascunho_nenhum_emite_normalmente(): void
    {
        TenantAtual::comTenant($this->empresa->id, function (): void {
            $dados = app(WorkOrderService::class)->preparePdfData($this->criarOs());

            $this->assertArrayHasKey('workOrder', $dados);
        });
    }

    // -----------------------------------------------------------------
    // Permissão
    // -----------------------------------------------------------------

    public function test_usuario_sem_permissao_de_revisar_recebe_403(): void
    {
        $this->liberarModuloDeIa();

        $rascunho = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): AiDraft => $this->servico->gerarParaOs($this->criarOs(), $this->usuario)
        );

        $semPermissao = TenantAtual::comTenant($this->empresa->id, function (): User {
            $usuario = User::create([
                'name' => 'Auxiliar',
                'email' => 'auxiliar@exemplo.test',
                'password' => bcrypt('senha-de-teste'),
            ]);
            $usuario->givePermissionTo('ia-gerar');

            return $usuario;
        });

        $this->actingAs($semPermissao)
            ->putJson(route('ia.rascunhos.revisar', $rascunho->id), [
                'conteudo_revisado' => 'Tentativa de revisão sem permissão para isso.',
            ])
            ->assertForbidden();

        $this->assertSame(AiDraft::SITUACAO_GERADO, $rascunho->fresh()->situacao);
    }

    public function test_responsavel_tecnico_com_permissao_revisa_pelo_endpoint(): void
    {
        $this->liberarModuloDeIa();

        $rascunho = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): AiDraft => $this->servico->gerarParaOs($this->criarOs(), $this->usuario)
        );

        $comPermissao = TenantAtual::comTenant($this->empresa->id, function (): User {
            $usuario = User::create([
                'name' => 'Responsável Técnico Dois',
                'email' => 'responsavel2@exemplo.test',
                'password' => bcrypt('senha-de-teste'),
            ]);
            $usuario->assignRole('tecnico');

            return $usuario;
        });

        $this->actingAs($comPermissao)
            ->putJson(route('ia.rascunhos.revisar', $rascunho->id), [
                'conteudo_revisado' => 'Parecer conferido e aprovado pelo responsável técnico.',
            ])
            ->assertOk();

        $this->assertSame(AiDraft::SITUACAO_REVISADO, $rascunho->fresh()->situacao);
    }

    // -----------------------------------------------------------------
    // Teto do plano
    // -----------------------------------------------------------------

    public function test_teto_atingido_recusa_a_geracao_mas_nao_derruba_o_resto_do_sistema(): void
    {
        config()->set('ai.teto_de_geracoes_por_mes.padrao', 2);

        TenantAtual::comTenant($this->empresa->id, function (): void {
            // Duas chamadas já registradas neste mês esgotam o teto.
            AiUsage::create(['tipo' => 'parecer_os', 'modelo' => 'claude-opus-5']);
            AiUsage::create(['tipo' => 'parecer_os', 'modelo' => 'claude-opus-5']);

            $medicao = app(MedicaoDeUsoService::class);

            try {
                $medicao->garantirDentroDoTeto($this->empresa);
                $this->fail('Esperava TetoDeIaAtingidoException com o teto esgotado.');
            } catch (TetoDeIaAtingidoException $e) {
                $this->assertStringContainsString('limite', mb_strtolower($e->getMessage()));
            }

            // E o essencial continua funcionando: concluir a OS e emitir o
            // documento com parecer escrito à mão não passam nem perto do teto.
            $os = $this->criarOs();

            $this->assertTrue(app(WorkOrderService::class)->markAsCompleted($os));

            $dados = app(WorkOrderService::class)->preparePdfData($os->fresh());
            $this->assertArrayHasKey('workOrder', $dados);

            $certificado = $this->criarCertificado($os);
            $this->assertArrayHasKey('certificate', app(CertificateService::class)->preparePdfData($certificado));
        });
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Provedor simulado no container. Registra as chamadas para os testes
     * poderem afirmar sobre elas, e nunca sai da máquina.
     */
    private function ligarProvedorFake(): object
    {
        $fake = new class implements ProvedorDeTexto
        {
            /** @var array<int, array{sistema: string, entrada: string, opcoes: array<string, mixed>}> */
            public array $chamadas = [];

            public function gerar(string $sistema, string $entrada, array $opcoes = []): RespostaDeTexto
            {
                $this->chamadas[] = ['sistema' => $sistema, 'entrada' => $entrada, 'opcoes' => $opcoes];

                return new RespostaDeTexto(
                    texto: 'Parecer simulado para teste.',
                    modelo: 'claude-opus-5',
                    tokensEntrada: 100,
                    tokensSaida: 50,
                    tokensCacheLeitura: 900,
                );
            }

            public function modelo(): string
            {
                return 'claude-opus-5';
            }
        };

        $this->app->instance(ProvedorDeTexto::class, $fake);

        return $fake;
    }

    private function liberarModuloDeIa(): void
    {
        $modulo = Module::query()->where('chave', 'laudo_ia')->firstOrFail();

        app(ModuleService::class)->liberarPara($this->empresa, $modulo, 'Teste automatizado', null);
    }

    private function criarOs(): WorkOrder
    {
        $cliente = Client::create([
            'name' => 'Cliente do Parecer',
            'email' => str()->random(10).'@exemplo.test',
            'phone' => '11912340000',
            'cnpj' => fake()->numerify('##.###.###/0001-##'),
        ]);

        $endereco = Address::create([
            'client_id' => $cliente->id,
            'nickname' => 'Unidade',
            'street' => 'Rua Um',
            'number' => '10',
            'district' => 'Centro',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip' => '01000-000',
            'active' => true,
        ]);

        return WorkOrder::create([
            'client_id' => $cliente->id,
            'address_id' => $endereco->id,
            'order_number' => 'OS-'.str()->random(8),
            'scheduled_date' => '2026-07-01',
            'status' => 'completed',
            'description' => 'Controle de pragas.',
            'active' => true,
        ]);
    }

    private function criarCertificado(WorkOrder $os): Certificate
    {
        return Certificate::create([
            'client_id' => $os->client_id,
            'work_order_id' => $os->id,
            'certificate_number' => 'CERT-'.str()->random(8),
            'execution_date' => '2026-07-01',
            'status' => 'active',
        ]);
    }

    private function gerarParaCertificado(Certificate $certificado): AiDraft
    {
        return AiDraft::create([
            'tipo' => AiDraft::TIPO_PARECER_CERTIFICADO,
            'origem_tipo' => Certificate::class,
            'origem_id' => $certificado->id,
            'conteudo_gerado' => 'Parecer simulado do certificado.',
            'situacao' => AiDraft::SITUACAO_GERADO,
            'modelo' => 'claude-opus-5',
            'gerado_por' => $this->usuario->id,
        ]);
    }
}
