<?php

namespace Tests\Feature;

use App\Console\Commands\SyncPermissions;
use App\Models\Company;
use App\Models\Module;
use App\Models\PersonalProtectiveEquipment;
use App\Models\PpeDelivery;
use App\Models\Technician;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\Ppe\PpeDeliveryService;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Closure;
use Database\Factories\PersonalProtectiveEquipmentFactory;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Task 28.7 do Plano 28: os endpoints de EPI, vistos pela porta de entrada.
 *
 * O que se prova aqui não é regra de negócio — isso é assunto do
 * `PpeDeliveryServiceTest` — e sim quem consegue bater na porta e o que a porta
 * responde. São três eixos, todos com defeito conhecido no histórico do projeto:
 *
 * **1. Permissão, e em especial a separação do estorno.** `epi-estornar` é
 * permissão própria porque o estorno altera prova documental: quem registra a
 * entrega no almoxarifado não é necessariamente quem pode declarar errada uma
 * linha já assinada pelo trabalhador. Um teste que só verificasse "administrador
 * consegue, anônimo não" deixaria essa separação virar decoração, que foi
 * exatamente o risco descrito na Task 18.4 do financeiro.
 *
 * **2. Id de outra empresa vindo do corpo da requisição.** O escopo global do
 * Eloquent não alcança o que a regra `exists` do Laravel consulta: `exists:` cru
 * aceita id de outro tenant e ainda serve de oráculo ("este id existe em alguma
 * empresa?"). É o defeito corrigido no commit `d9a3a9c`, e a exigência aqui é a
 * recusa **na validação** (422), com nada gravado — não o conserto silencioso
 * mais adiante. Model que chega por rota é outra história: ali o binding escopado
 * responde 404, que é a resposta certa quando revelar a existência já vaza.
 *
 * **3. Exceção de domínio vira resposta em português, nunca 500.**
 * `CaVencidoException`, `EntregaDeEpiEstornadaException` e
 * `EpiComEntregaException` carregam a mensagem pronta com o caminho de correção.
 * Deixá-las subir daria erro de servidor sem explicação — o defeito 2 do mesmo
 * `d9a3a9c`.
 *
 * E o que **não** existe: rota de exclusão de entrega. A entrega é documento
 * oponível, com arquivamento recomendado de 20 anos, e a correção é o estorno com
 * motivo. `epi.destroy` é do **cadastro** de EPI, não da entrega, e o teste que
 * varre a tabela de rotas existe para que a diferença não se perca.
 *
 * O módulo `epi` nasce desligado (`sempre_ativo = false`): `ModulesSeeder` liga o
 * tenant fundador pelo Plano Interno, e o bloco de módulo desligado confere que
 * digitar a URL direto não contorna o bloqueio.
 */
class EpiEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const AGORA = '2026-08-10 12:00:00';

    private Company $empresa;

    private User $administrador;

    private Technician $tecnico;

    private ?Company $outraEmpresa = null;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::AGORA);
        TenantAtual::limpar();

        // A assinatura de recebimento grava PNG em disco. Sem o disco falso, os
        // testes deixariam lixo em `storage/app/public` da máquina.
        Storage::fake('public');

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ModulesSeeder::class);

        $this->empresa = Company::query()->findOrFail(1);

        $this->administrador = $this->comTenant(function (): User {
            $admin = User::factory()->create(['is_active' => true]);
            $admin->assignRole('administrador');

            return $admin;
        });

        $this->tecnico = $this->criarTecnico();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Catálogo e caminho feliz
    // -----------------------------------------------------------------

    /**
     * Hífen, não ponto: é a convenção do catálogo inteiro. O sufixo `-ver` é o
     * que `RolesAndPermissionsSeeder` reconhece para compor o papel de leitura —
     * mas `epi-ver` está na lista de exceções de propósito, e o teste do papel
     * `leitura` mais abaixo é quem guarda essa decisão.
     */
    public function test_as_quatro_permissoes_estao_no_catalogo(): void
    {
        $catalogo = collect(SyncPermissions::catalogo())->flatten()->all();

        foreach (['epi-ver', 'epi-gerenciar', 'epi-entregar', 'epi-estornar'] as $permissao) {
            $this->assertContains($permissao, $catalogo);
        }
    }

    public function test_o_administrador_percorre_o_ciclo_completo_do_cadastro_e_da_entrega(): void
    {
        $criacao = $this->actingAs($this->administrador)->postJson('/epis', [
            'nome' => 'Respirador semifacial',
            'tipo' => 'respirador',
            'fabricante' => '3M',
            'numero_ca' => 'CA-12345',
            'validade_ca' => '2027-01-31',
            'vida_util_dias' => 180,
        ]);

        $criacao->assertCreated();
        $epiId = $criacao->json('epi.id');

        $this->actingAs($this->administrador)->getJson('/epis')->assertOk();
        $this->actingAs($this->administrador)->getJson("/epis/{$epiId}")->assertOk();

        $entrega = $this->actingAs($this->administrador)->postJson('/epis/entregas', [
            'technician_id' => $this->tecnico->getKey(),
            'personal_protective_equipment_id' => $epiId,
            'entregue_em' => '2026-08-01',
        ]);

        $entrega->assertCreated();
        $entregaId = $entrega->json('entrega.id');

        // O CA gravado é o do cadastro no dia da entrega, copiado pelo Service.
        $this->assertDatabaseHas('ppe_deliveries', [
            'id' => $entregaId,
            'numero_ca' => 'CA-12345',
            'company_id' => $this->empresa->getKey(),
            'user_id' => $this->administrador->getKey(),
        ]);

        $this->actingAs($this->administrador)
            ->getJson("/epis/tecnicos/{$this->tecnico->getKey()}")
            ->assertOk()
            ->assertJsonCount(1, 'entregas');

        $this->actingAs($this->administrador)
            ->postJson("/epis/entregas/{$entregaId}/devolucao", [
                'motivo_devolucao' => 'Troca de equipamento',
                'devolvido_em' => '2026-08-05',
            ])
            ->assertOk();

        $this->actingAs($this->administrador)
            ->postJson("/epis/entregas/{$entregaId}/estorno", [
                'motivo_estorno' => 'Lançada no técnico errado.',
            ])
            ->assertOk();

        // Estornada, e ainda no banco: a linha nunca some.
        $this->assertDatabaseHas('ppe_deliveries', ['id' => $entregaId]);
        $this->assertNotNull($this->comTenant(fn () => PpeDelivery::query()->findOrFail($entregaId)->estornada_em));
    }

    public function test_a_ficha_em_pdf_e_a_extracao_respondem_com_o_documento(): void
    {
        $epi = $this->criarEpi();
        $this->registrar($epi, '2026-08-01');

        $pdf = $this->actingAs($this->administrador)
            ->get("/epis/tecnicos/{$this->tecnico->getKey()}/ficha-pdf");

        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());

        $csv = $this->actingAs($this->administrador)
            ->get('/epis/extracao?inicio=2026-08-01&fim=2026-08-31');

        $csv->assertOk();
        $csv->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('attachment; filename=', $csv->headers->get('Content-Disposition'));
        $this->assertStringStartsWith("\u{FEFF}", $csv->getContent());
    }

    // -----------------------------------------------------------------
    // Permissão: ausência barra, e o estorno é separado de propósito
    // -----------------------------------------------------------------

    public function test_usuario_sem_epi_ver_nao_alcanca_nenhuma_leitura(): void
    {
        $semNada = $this->criarUsuario([]);

        $epi = $this->criarEpi();

        foreach ([
            '/epis',
            '/epis/entregas',
            "/epis/{$epi->getKey()}",
            "/epis/tecnicos/{$this->tecnico->getKey()}",
            "/epis/tecnicos/{$this->tecnico->getKey()}/ficha-pdf",
            '/epis/extracao?inicio=2026-08-01&fim=2026-08-31',
        ] as $uri) {
            $this->actingAs($semNada)->getJson($uri)->assertForbidden();
        }
    }

    /**
     * Quem só lê não cadastra e não entrega — e, no EPI, também não lê.
     *
     * `epi-ver` está na lista de exceções de `permissoesLeitura()`, junto de
     * `comissoes-ver`: apesar do sufixo `-ver`, a ficha traz nome, matrícula,
     * histórico e assinatura do trabalhador, e a extração despeja isso de todos
     * os técnicos de uma vez. É dado pessoal de funcionário, e se concede
     * explicitamente a quem responde pela segurança do trabalho.
     *
     * Este teste é o que impede o papel de reganhar a permissão em silêncio, se
     * alguém tirar a exceção do seeder.
     */
    public function test_quem_so_le_nao_cadastra_nem_entrega(): void
    {
        $epi = $this->criarEpi();

        $leitor = $this->comTenant(function (): User {
            $usuario = User::factory()->create(['is_active' => true]);
            $usuario->assignRole('leitura');

            return $usuario;
        });

        $this->assertFalse($leitor->can('epi-ver'), 'O papel `leitura` não pode alcançar a ficha de EPI.');

        $this->actingAs($leitor)->getJson('/epis')->assertForbidden();
        $this->actingAs($leitor)->getJson("/epis/tecnicos/{$this->tecnico->getKey()}")->assertForbidden();
        $this->actingAs($leitor)->get('/epis/extracao?inicio=2026-08-01&fim=2026-08-31')->assertForbidden();

        $this->actingAs($leitor)
            ->postJson('/epis', ['nome' => 'EPI que não deveria nascer'])
            ->assertForbidden();

        $this->actingAs($leitor)
            ->putJson("/epis/{$epi->getKey()}", ['nome' => 'Renomeado sem permissão'])
            ->assertForbidden();

        $this->actingAs($leitor)->postJson("/epis/{$epi->getKey()}/inativar")->assertForbidden();
        $this->actingAs($leitor)->postJson("/epis/{$epi->getKey()}/reativar")->assertForbidden();
        $this->actingAs($leitor)->deleteJson("/epis/{$epi->getKey()}")->assertForbidden();

        $this->actingAs($leitor)
            ->postJson('/epis/entregas', [
                'technician_id' => $this->tecnico->getKey(),
                'personal_protective_equipment_id' => $epi->getKey(),
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('personal_protective_equipments', ['nome' => 'EPI que não deveria nascer']);
        $this->assertSame(0, $this->contarEntregas());
    }

    /**
     * O teste que sustenta a separação: quem entrega **não** estorna.
     *
     * O estorno altera prova documental — é a única forma de declarar errada uma
     * linha já assinada pelo trabalhador. Dar essa capacidade a todo mundo que
     * registra entrega no almoxarifado tornaria `epi-estornar` decorativa.
     */
    public function test_quem_entrega_nao_estorna_sem_a_permissao_propria(): void
    {
        $epi = $this->criarEpi();

        $almoxarife = $this->criarUsuario(['epi-ver', 'epi-entregar']);

        $entrega = $this->actingAs($almoxarife)->postJson('/epis/entregas', [
            'technician_id' => $this->tecnico->getKey(),
            'personal_protective_equipment_id' => $epi->getKey(),
        ]);

        $entrega->assertCreated();
        $entregaId = $entrega->json('entrega.id');

        // Assinar e devolver continuam ao alcance de quem entrega.
        $this->actingAs($almoxarife)
            ->postJson("/epis/entregas/{$entregaId}/assinatura", [
                'assinatura' => base64_encode('conteudo-png-de-teste'),
            ])
            ->assertOk();

        $this->actingAs($almoxarife)
            ->postJson("/epis/entregas/{$entregaId}/devolucao", ['motivo_devolucao' => 'Troca'])
            ->assertOk();

        // O estorno, não.
        $this->actingAs($almoxarife)
            ->postJson("/epis/entregas/{$entregaId}/estorno", ['motivo_estorno' => 'Tentativa sem permissão'])
            ->assertForbidden();

        $this->assertNull(
            $this->comTenant(fn () => PpeDelivery::query()->findOrFail($entregaId)->estornada_em),
            'o estorno barrado por permissão gravou mesmo assim'
        );
    }

    /**
     * O contrário também vale: quem tem só o estorno não registra entrega nova.
     * A separação corta os dois lados, senão bastaria pedir `epi-estornar` para
     * ganhar o almoxarifado.
     */
    public function test_quem_estorna_nao_registra_entrega_sem_epi_entregar(): void
    {
        $epi = $this->criarEpi();
        $entrega = $this->registrar($epi);

        $auditor = $this->criarUsuario(['epi-ver', 'epi-estornar']);

        $this->actingAs($auditor)
            ->postJson('/epis/entregas', [
                'technician_id' => $this->tecnico->getKey(),
                'personal_protective_equipment_id' => $epi->getKey(),
            ])
            ->assertForbidden();

        $this->actingAs($auditor)
            ->postJson("/epis/entregas/{$entrega->getKey()}/assinatura", ['assinatura' => base64_encode('x')])
            ->assertForbidden();

        $this->actingAs($auditor)
            ->postJson("/epis/entregas/{$entrega->getKey()}/estorno", ['motivo_estorno' => 'Correção legítima'])
            ->assertOk();
    }

    /**
     * Cadastrar o modelo de EPI é `epi-gerenciar`, e ela não vem junto com
     * `epi-entregar`: mexer no número do CA do cadastro é o que decide o que a
     * próxima ficha vai imprimir.
     */
    public function test_quem_entrega_nao_mexe_no_cadastro_sem_epi_gerenciar(): void
    {
        $epi = $this->criarEpi();
        $almoxarife = $this->criarUsuario(['epi-ver', 'epi-entregar']);

        $this->actingAs($almoxarife)
            ->putJson("/epis/{$epi->getKey()}", ['nome' => 'Renomeado', 'numero_ca' => 'CA-INVENTADO'])
            ->assertForbidden();

        $this->actingAs($almoxarife)->postJson("/epis/{$epi->getKey()}/inativar")->assertForbidden();

        $this->assertSame($epi->numero_ca, $epi->fresh()->numero_ca);
        $this->assertTrue((bool) $epi->fresh()->ativo);
    }

    // -----------------------------------------------------------------
    // Módulo desligado: o EPI nasce desligado e digitar a URL não contorna
    // -----------------------------------------------------------------

    public function test_modulo_epi_desligado_bloqueia_todas_as_rotas(): void
    {
        $this->desligarModulo('epi');

        $epi = $this->criarEpi();

        $this->actingAs($this->administrador)
            ->get('/epis')
            ->assertRedirect(route('modulo-indisponivel', ['modulo' => 'epi']));

        foreach ([
            '/epis',
            '/epis/entregas',
            "/epis/{$epi->getKey()}",
            "/epis/tecnicos/{$this->tecnico->getKey()}",
            "/epis/tecnicos/{$this->tecnico->getKey()}/ficha-pdf",
            '/epis/extracao?inicio=2026-08-01&fim=2026-08-31',
        ] as $uri) {
            $this->actingAs($this->administrador)
                ->getJson($uri)
                ->assertForbidden()
                ->assertJsonPath('modulo', 'epi');
        }

        // A escrita também: o bloqueio de módulo é independente da permissão, e
        // o administrador tem todas.
        $this->actingAs($this->administrador)
            ->postJson('/epis/entregas', [
                'technician_id' => $this->tecnico->getKey(),
                'personal_protective_equipment_id' => $epi->getKey(),
            ])
            ->assertForbidden()
            ->assertJsonPath('modulo', 'epi');

        $this->assertSame(0, $this->contarEntregas());
    }

    /**
     * Tenant sem plano nenhum não enxerga o módulo: é o estado em que o EPI
     * chega em produção no Deploy 1, e o que o rollout depende de ver.
     */
    public function test_tenant_sem_o_modulo_no_plano_nao_alcanca_o_epi(): void
    {
        $empresaSemModulo = Company::create([
            'name' => 'Empresa Sem EPI',
            'cnpj' => '99.999.999/0001-99',
            'email' => 'contato@sem-epi.test',
        ]);

        $admin = TenantAtual::comTenant($empresaSemModulo->getKey(), function (): User {
            $usuario = User::factory()->create(['is_active' => true]);
            $usuario->assignRole('administrador');

            return $usuario;
        });

        $this->actingAs($admin)
            ->get('/epis')
            ->assertRedirect(route('modulo-indisponivel', ['modulo' => 'epi']));

        $this->actingAs($admin)
            ->getJson('/epis')
            ->assertForbidden()
            ->assertJsonPath('modulo', 'epi');
    }

    // -----------------------------------------------------------------
    // Isolamento: id pelo corpo é 422, model pela rota é 404
    // -----------------------------------------------------------------

    /**
     * Regressão do commit `d9a3a9c`. Com `exists:` cru, o id de outra empresa
     * passava na validação — e o próprio `exists` já respondia se aquele id
     * existia em algum tenant, o que é vazamento mesmo quando a gravação falha
     * adiante.
     */
    public function test_tecnico_de_outra_empresa_no_corpo_e_recusado_na_validacao(): void
    {
        $epi = $this->criarEpi();
        $tecnicoDaOutra = $this->tecnicoDaOutraEmpresa();

        $this->actingAs($this->administrador)
            ->postJson('/epis/entregas', [
                'technician_id' => $tecnicoDaOutra->getKey(),
                'personal_protective_equipment_id' => $epi->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('technician_id');

        $this->assertSame(0, $this->contarEntregas());
        $this->assertDatabaseMissing('ppe_deliveries', ['technician_id' => $tecnicoDaOutra->getKey()]);
    }

    public function test_epi_de_outra_empresa_no_corpo_e_recusado_na_validacao(): void
    {
        $epiDaOutra = $this->epiDaOutraEmpresa();

        $this->actingAs($this->administrador)
            ->postJson('/epis/entregas', [
                'technician_id' => $this->tecnico->getKey(),
                'personal_protective_equipment_id' => $epiDaOutra->getKey(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('personal_protective_equipment_id');

        $this->assertSame(0, $this->contarEntregas());
    }

    /**
     * Contraprova: a regra separa empresas, em vez de simplesmente barrar tudo.
     * Sem ela, um `exists` quebrado passaria neste teste tanto quanto uma regra
     * correta.
     */
    public function test_tecnico_e_epi_da_propria_empresa_nao_sao_recusados(): void
    {
        $epi = $this->criarEpi();

        $this->actingAs($this->administrador)
            ->postJson('/epis/entregas', [
                'technician_id' => $this->tecnico->getKey(),
                'personal_protective_equipment_id' => $epi->getKey(),
            ])
            ->assertCreated();
    }

    public function test_model_de_outra_empresa_pela_rota_responde_404(): void
    {
        $epiDaOutra = $this->epiDaOutraEmpresa();
        $tecnicoDaOutra = $this->tecnicoDaOutraEmpresa();
        $entregaDaOutra = $this->entregaDaOutraEmpresa($tecnicoDaOutra, $epiDaOutra);

        $this->actingAs($this->administrador)->getJson("/epis/{$epiDaOutra->getKey()}")->assertNotFound();
        $this->actingAs($this->administrador)
            ->putJson("/epis/{$epiDaOutra->getKey()}", ['nome' => 'Invasão'])
            ->assertNotFound();
        $this->actingAs($this->administrador)->deleteJson("/epis/{$epiDaOutra->getKey()}")->assertNotFound();
        $this->actingAs($this->administrador)
            ->postJson("/epis/{$epiDaOutra->getKey()}/inativar")
            ->assertNotFound();

        $this->actingAs($this->administrador)
            ->getJson("/epis/tecnicos/{$tecnicoDaOutra->getKey()}")
            ->assertNotFound();
        $this->actingAs($this->administrador)
            ->get("/epis/tecnicos/{$tecnicoDaOutra->getKey()}/ficha-pdf")
            ->assertNotFound();

        $this->actingAs($this->administrador)
            ->postJson("/epis/entregas/{$entregaDaOutra->getKey()}/estorno", ['motivo_estorno' => 'Invasão'])
            ->assertNotFound();
        $this->actingAs($this->administrador)
            ->postJson("/epis/entregas/{$entregaDaOutra->getKey()}/assinatura", ['assinatura' => base64_encode('x')])
            ->assertNotFound();
        $this->actingAs($this->administrador)
            ->postJson("/epis/entregas/{$entregaDaOutra->getKey()}/devolucao")
            ->assertNotFound();

        // Nada da outra empresa foi tocado.
        TenantAtual::comTenant($this->outraEmpresa()->getKey(), function () use ($entregaDaOutra, $epiDaOutra): void {
            $this->assertNull($entregaDaOutra->fresh()->estornada_em);
            $this->assertSame('Respirador da concorrente', $epiDaOutra->fresh()->nome);
        });
    }

    public function test_a_listagem_e_a_extracao_nao_trazem_dado_de_outra_empresa(): void
    {
        $this->registrar($this->criarEpi(), '2026-08-01');

        $epiDaOutra = $this->epiDaOutraEmpresa();
        $tecnicoDaOutra = $this->tecnicoDaOutraEmpresa();
        $this->entregaDaOutraEmpresa($tecnicoDaOutra, $epiDaOutra, '2026-08-02');

        $this->actingAs($this->administrador)
            ->getJson('/epis')
            ->assertOk()
            ->assertJsonCount(1, 'epis');

        $this->actingAs($this->administrador)
            ->getJson('/epis/entregas')
            ->assertOk()
            ->assertJsonCount(1, 'entregas');

        $csv = $this->actingAs($this->administrador)
            ->get('/epis/extracao?inicio=2026-08-01&fim=2026-08-31')
            ->getContent();

        $this->assertStringNotContainsString($tecnicoDaOutra->name, $csv);
    }

    // -----------------------------------------------------------------
    // A entrega não se exclui
    // -----------------------------------------------------------------

    /**
     * Não existe rota de exclusão de entrega, e não pode passar a existir: a
     * correção é o estorno com motivo, que preserva a linha.
     *
     * A varredura na tabela de rotas é o que pega a regressão de verdade — um
     * `Route::delete` novo apareceria aqui antes de qualquer teste de resposta.
     *
     * O recorte é `epis/entregas`, e não o módulo inteiro: `epi.destroy` é do
     * **cadastro** de EPI e continua legítimo, e um plano posterior que
     * acrescente exclusão a outro recurso sob `/epis` não pode falhar aqui com
     * uma mensagem que fala de entrega. É a mesma armadilha de escopo que o
     * `7fd654c` corrigiu no catálogo de permissões.
     */
    public function test_nao_existe_rota_de_exclusao_de_entrega(): void
    {
        $exclusoes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($rota): bool => in_array('DELETE', $rota->methods(), true));

        $this->assertSame(
            [],
            $exclusoes
                ->filter(fn ($rota): bool => str_starts_with($rota->uri(), 'epis/entregas'))
                ->map(fn ($rota): string => $rota->getName().' '.$rota->uri())
                ->values()
                ->all(),
            'Entrega de EPI não se exclui: a correção é o estorno com motivo, que preserva a linha.'
        );

        // A exclusão do cadastro continua existindo, e é outra coisa.
        $this->assertContains(
            'epi.destroy epis/{epi}',
            $exclusoes->map(fn ($rota): string => $rota->getName().' '.$rota->uri())->all()
        );
    }

    public function test_delete_em_uma_entrega_nao_responde_e_a_linha_permanece(): void
    {
        $entrega = $this->registrar($this->criarEpi());

        $this->actingAs($this->administrador)
            ->deleteJson("/epis/entregas/{$entrega->getKey()}")
            ->assertNotFound();

        $this->actingAs($this->administrador)
            ->deleteJson('/epis/entregas')
            ->assertNotFound();

        $this->assertDatabaseHas('ppe_deliveries', ['id' => $entrega->getKey()]);
    }

    /**
     * O cadastro já entregue também não se exclui: a ficha antiga aponta para
     * ele, e é dele que saem nome, tipo e fabricante do que o técnico recebeu. A
     * recusa é 422 em português, com o caminho certo, e não um erro de
     * integridade do MySQL.
     */
    public function test_excluir_cadastro_de_epi_ja_entregue_devolve_422_apontando_a_inativacao(): void
    {
        $epi = $this->criarEpi();
        $this->registrar($epi);

        $resposta = $this->actingAs($this->administrador)->deleteJson("/epis/{$epi->getKey()}");

        $resposta->assertStatus(422);
        $this->assertStringContainsString('Inative o cadastro', (string) $resposta->json('mensagem'));

        $this->assertDatabaseHas('personal_protective_equipments', ['id' => $epi->getKey()]);
    }

    // -----------------------------------------------------------------
    // Exceção de domínio vira mensagem, nunca 500
    // -----------------------------------------------------------------

    public function test_ca_vencido_devolve_422_em_portugues_apontando_onde_corrigir(): void
    {
        $epi = $this->comTenant(fn (): PersonalProtectiveEquipment => PersonalProtectiveEquipmentFactory::new()
            ->create(['numero_ca' => 'CA-98765', 'validade_ca' => '2026-08-09']));

        $resposta = $this->actingAs($this->administrador)->postJson('/epis/entregas', [
            'technician_id' => $this->tecnico->getKey(),
            'personal_protective_equipment_id' => $epi->getKey(),
        ]);

        $resposta->assertStatus(422);

        $mensagem = (string) $resposta->json('mensagem');

        $this->assertStringContainsString('CA-98765', $mensagem);
        $this->assertStringContainsString('09/08/2026', $mensagem);
        $this->assertStringContainsString('Cadastros > EPI', $mensagem);

        // A tela precisa saber onde pousar o erro: é o campo do EPI.
        $resposta->assertJsonValidationErrors('personal_protective_equipment_id');

        $this->assertSame(0, $this->contarEntregas());
    }

    public function test_operacao_sobre_entrega_estornada_devolve_422_em_portugues(): void
    {
        $entrega = $this->registrar($this->criarEpi());

        $this->actingAs($this->administrador)
            ->postJson("/epis/entregas/{$entrega->getKey()}/estorno", ['motivo_estorno' => 'Lançada no técnico errado.'])
            ->assertOk();

        foreach ([
            ["/epis/entregas/{$entrega->getKey()}/assinatura", ['assinatura' => base64_encode('x')]],
            ["/epis/entregas/{$entrega->getKey()}/devolucao", ['motivo_devolucao' => 'Troca']],
            ["/epis/entregas/{$entrega->getKey()}/estorno", ['motivo_estorno' => 'De novo']],
        ] as [$uri, $corpo]) {
            $resposta = $this->actingAs($this->administrador)->postJson($uri, $corpo);

            $resposta->assertStatus(422);
            $this->assertStringContainsString(
                'Registre uma nova entrega',
                (string) $resposta->json('mensagem'),
                "a recusa de {$uri} não veio com a saída em português"
            );
        }
    }

    public function test_estorno_sem_motivo_e_recusado_pela_validacao(): void
    {
        $entrega = $this->registrar($this->criarEpi());

        $this->actingAs($this->administrador)
            ->postJson("/epis/entregas/{$entrega->getKey()}/estorno", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo_estorno');

        $this->assertNull($entrega->fresh()->estornada_em);
    }

    // -----------------------------------------------------------------
    // Período da extração
    // -----------------------------------------------------------------

    /**
     * Extração sem recorte varreria a tabela inteira e montaria o CSV em
     * memória; com o fim antes do início, devolveria um arquivo vazio — e
     * arquivo vazio entregue ao fiscal parece ausência de entregas.
     */
    public function test_a_extracao_exige_periodo_valido(): void
    {
        $this->actingAs($this->administrador)
            ->getJson('/epis/extracao')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['inicio', 'fim']);

        $this->actingAs($this->administrador)
            ->getJson('/epis/extracao?inicio=2026-08-31&fim=2026-08-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('fim');

        $this->actingAs($this->administrador)
            ->getJson('/epis/extracao?inicio=nao-e-data&fim=2026-08-31')
            ->assertStatus(422)
            ->assertJsonValidationErrors('inicio');

        // Período de um dia só é legítimo: as duas pontas entram.
        $this->actingAs($this->administrador)
            ->get('/epis/extracao?inicio=2026-08-01&fim=2026-08-01')
            ->assertOk();
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function comTenant(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->getKey(), $callback);
    }

    /**
     * Usuário sem papel, com exatamente as permissões pedidas. Papel traria
     * junto o resto do catálogo e apagaria a distinção que estes testes existem
     * para medir.
     *
     * @param  array<int, string>  $permissoes
     */
    private function criarUsuario(array $permissoes): User
    {
        return $this->comTenant(function () use ($permissoes): User {
            $usuario = User::factory()->create(['is_active' => true]);

            if ($permissoes !== []) {
                $usuario->givePermissionTo($permissoes);
            }

            return $usuario->fresh();
        });
    }

    private function criarTecnico(string $nome = 'Técnico do EPI'): Technician
    {
        return $this->comTenant(fn (): Technician => Technician::create([
            'name' => $nome,
            'email' => 'tecnico-epi-'.uniqid().'@dedetizadora.test',
            'phone' => '11999990000',
            'is_active' => true,
        ]));
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function criarEpi(array $atributos = []): PersonalProtectiveEquipment
    {
        return $this->comTenant(
            fn (): PersonalProtectiveEquipment => PersonalProtectiveEquipmentFactory::new()->create($atributos)
        );
    }

    private function registrar(PersonalProtectiveEquipment $epi, ?string $entregueEm = null): PpeDelivery
    {
        return $this->comTenant(fn (): PpeDelivery => app(PpeDeliveryService::class)->registrar(array_filter([
            'technician_id' => $this->tecnico->getKey(),
            'personal_protective_equipment_id' => $epi->getKey(),
            'entregue_em' => $entregueEm,
        ])));
    }

    private function contarEntregas(): int
    {
        return $this->comTenant(fn (): int => PpeDelivery::query()->count());
    }

    private function outraEmpresa(): Company
    {
        return $this->outraEmpresa ??= Company::create([
            'name' => 'Concorrente do EPI',
            'cnpj' => '55.555.555/0001-55',
            'email' => 'contato@concorrente-epi.test',
        ]);
    }

    private function tecnicoDaOutraEmpresa(): Technician
    {
        return TenantAtual::comTenant($this->outraEmpresa()->getKey(), fn (): Technician => Technician::create([
            'name' => 'Técnico da concorrente',
            'email' => 'tecnico-concorrente-'.uniqid().'@concorrente.test',
            'phone' => '11988887777',
            'is_active' => true,
        ]));
    }

    private function epiDaOutraEmpresa(): PersonalProtectiveEquipment
    {
        return TenantAtual::comTenant(
            $this->outraEmpresa()->getKey(),
            fn (): PersonalProtectiveEquipment => PersonalProtectiveEquipmentFactory::new()
                ->create(['nome' => 'Respirador da concorrente'])
        );
    }

    private function entregaDaOutraEmpresa(
        Technician $tecnico,
        PersonalProtectiveEquipment $epi,
        ?string $entregueEm = null,
    ): PpeDelivery {
        return TenantAtual::comTenant(
            $this->outraEmpresa()->getKey(),
            fn (): PpeDelivery => app(PpeDeliveryService::class)->registrar(array_filter([
                'technician_id' => $tecnico->getKey(),
                'personal_protective_equipment_id' => $epi->getKey(),
                'entregue_em' => $entregueEm,
            ]))
        );
    }

    /**
     * Desliga o módulo pelo mesmo caminho do super admin
     * (`ModuleService::bloquearPara()`), em vez de mexer em `company_modules` na
     * mão: é o único jeito de o cache de módulos ser invalidado junto.
     */
    private function desligarModulo(string $chave): void
    {
        $modulo = Module::query()->where('chave', $chave)->firstOrFail();

        app(ModuleService::class)->bloquearPara($this->empresa, $modulo, 'Desligado no teste.');
    }
}
