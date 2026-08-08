<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\Client;
use App\Models\Commission;
use App\Models\CommissionItem;
use App\Models\CommissionRule;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Goal;
use App\Models\Payable;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Closure;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 23.6 do Plano 23: a camada HTTP de comissão, meta e renovação de
 * contrato.
 *
 * O comportamento interno já está coberto por `CommissionCalculationServiceTest`
 * e `CommissionClosingServiceTest`-equivalente (Task 23.2), `GoalServiceTest`
 * (Task 23.3) e `ContractRenewalServiceTest` (Task 23.4). O que este arquivo
 * prende é o que só existe na borda: rota, permissão, FormRequest, e a regra
 * de autorização a nível de DADO que nenhum Service cobre sozinho - cada
 * pessoa só vê a própria comissão.
 *
 * Critérios de aceitação da task, um por bloco:
 *
 * 1. Apuração e listagem funcionam por competência.
 * 2. Vendedor sem `comissoes-ver-todas` vê apenas a própria comissão.
 * 3. Fechar e reabrir funcionam, com permissão e justificativa.
 * 4. Marcar como paga pode gerar o título a pagar.
 * 5. Metas são criadas em lote por competência.
 * 6. Painel de contratos a vencer traz marcos e vencidos sem tratativa.
 * 7. Renovar cria o contrato novo pelos endpoints.
 * 8. Usuário sem permissão recebe 403 em cada rota nova.
 *
 * Mais um bloco fora da lista da task, mas exigido pela skill
 * `permissoes-e-multitenancy`: nenhum endpoint novo alcança registro de outra
 * empresa por route-model binding.
 *
 * O relógio da suíte fica fixo em 15/08/2026: a competência 07/2026 já
 * terminou (necessário para fechar) e o contrato de exemplo cai dentro da
 * janela de renovação (90 dias antes / 30 depois do fim).
 */
class CommissionEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const AGORA = '2026-08-15 12:00:00';

    private Company $empresa;

    private User $administrador;

    /**
     * Papel comercial: tem `comissoes-ver` e `contrato-renovar`, não tem
     * `comissoes-ver-todas` nem `comissoes-fechar`.
     */
    private User $vendedorUm;

    private User $vendedorDois;

    /**
     * Usuário do mesmo tenant sem nenhum papel nem permissão extra.
     */
    private User $semPermissao;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::AGORA);
        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ModulesSeeder::class);

        $this->empresa = Company::query()->firstOrFail();

        $this->administrador = $this->criarUsuario('administrador');
        $this->vendedorUm = $this->criarUsuario('comercial');
        $this->vendedorDois = $this->criarUsuario('comercial');
        $this->semPermissao = $this->comTenant(fn () => User::factory()->create(['company_id' => $this->empresa->id]));
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // 1. Apuração e listagem funcionam por competência
    // -----------------------------------------------------------------

    public function test_apuracao_e_listagem_funcionam_por_competencia(): void
    {
        $cliente = $this->criarCliente();
        $this->criarRegra([
            'user_id' => $this->vendedorUm->id,
            'tipo' => 'vendedor',
            'base' => 'vendido',
            'valor' => 10,
            'vigencia_inicio' => '2026-01-01',
        ]);
        $this->criarOrcamentoAprovado($this->vendedorUm, $cliente, '2026-07-10', '1000.00');

        $apuracao = $this->actingAs($this->administrador)
            ->postJson('/comissoes/apurar', ['competencia' => '2026-07']);

        $apuracao->assertOk();
        $this->assertSame(1, $apuracao->json('pessoas_processadas'));
        $this->assertSame(1, $apuracao->json('itens_gravados'));

        $listagem = $this->actingAs($this->administrador)->getJson('/comissoes?competencia=2026-07');

        $listagem->assertOk();
        $this->assertSame('2026-07', $listagem->json('competencia'));
        $this->assertCount(1, $listagem->json('comissoes'));
        $this->assertSame($this->vendedorUm->id, $listagem->json('comissoes.0.user_id'));
        $this->assertCount(1, $listagem->json('comissoes.0.items'));
        $this->assertSame('100.00', $listagem->json('comissoes.0.valor_total'), '10% de 1000,00');
    }

    public function test_apuracao_sem_competencia_no_corpo_e_recusada_pela_validacao(): void
    {
        $this->actingAs($this->administrador)
            ->postJson('/comissoes/apurar', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('competencia');
    }

    /**
     * O Service já protege internamente competência fechada; este teste
     * confirma isso pela borda HTTP, critério explícito da task.
     */
    public function test_apuracao_nao_recalcula_competencia_ja_fechada(): void
    {
        $comissao = $this->criarComissao($this->vendedorUm, '2026-07', 'fechada', '50.00');
        $regra = $this->criarRegra([
            'user_id' => $this->vendedorUm->id,
            'tipo' => 'vendedor',
            'base' => 'vendido',
            'valor' => 10,
            'vigencia_inicio' => '2026-01-01',
        ]);
        $this->comTenant(fn () => CommissionItem::create([
            'commission_id' => $comissao->id,
            'origem_tipo' => 'orcamento',
            'origem_id' => 1,
            'commission_rule_id' => $regra->id,
            'base_valor' => '500.00',
            'percentual_ou_fixo' => '10.0000',
            'valor' => '50.00',
            'ocorrido_em' => '2026-07-05',
        ]));

        $cliente = $this->criarCliente();
        $this->criarOrcamentoAprovado($this->vendedorUm, $cliente, '2026-07-12', '2000.00');

        $resposta = $this->actingAs($this->administrador)
            ->postJson('/comissoes/apurar', ['competencia' => '2026-07']);

        $resposta->assertOk();
        $this->assertSame(1, $resposta->json('competencias_fechadas_ignoradas'));

        $comissao->refresh();
        $this->assertSame('fechada', $comissao->situacao);
        $this->assertSame('50.00', $comissao->valor_total, 'comissão fechada não pode ser recalculada');
        $this->assertCount(1, $comissao->items()->get(), 'o item novo do orçamento não pode ter sido gravado numa competência fechada');
    }

    // -----------------------------------------------------------------
    // 2. Vendedor sem comissoes-ver-todas vê apenas a própria comissão
    // -----------------------------------------------------------------

    /**
     * O teste central da task: dois vendedores, cada um com a própria
     * comissão apurada na mesma competência. Autenticado como um deles, o
     * outro não pode aparecer em NENHUM lugar da resposta - nem o registro
     * inteiro, nem o valor, nem o nome.
     */
    public function test_vendedor_sem_ver_todas_ve_apenas_a_propria_comissao(): void
    {
        $comissaoUm = $this->criarComissao($this->vendedorUm, '2026-07', 'aberta', '111.11');
        $comissaoDois = $this->criarComissao($this->vendedorDois, '2026-07', 'aberta', '222.22');

        $this->assertTrue($this->vendedorUm->can('comissoes-ver'));
        $this->assertFalse($this->vendedorUm->can('comissoes-ver-todas'));

        $resposta = $this->actingAs($this->vendedorUm)->getJson('/comissoes?competencia=2026-07');

        $resposta->assertOk();
        $this->assertFalse($resposta->json('ve_todas'));

        $comissoes = $resposta->json('comissoes');
        $this->assertCount(1, $comissoes, 'vendedor sem comissoes-ver-todas recebeu mais de uma comissão no payload');
        $this->assertSame($comissaoUm->id, $comissoes[0]['id']);
        $this->assertSame($this->vendedorUm->id, $comissoes[0]['user_id']);

        // Nem o id, nem o user_id, nem o valor do colega aparecem em lugar
        // nenhum da resposta. Comparação exata (não substring) nos ids: dois
        // ids pequenos podem colidir por substring (ex.: "1" dentro de "12"),
        // e um teste de vazamento não pode ter esse falso negativo.
        $this->assertNotContains($comissaoDois->id, array_column($comissoes, 'id'));
        $this->assertNotContains($this->vendedorDois->id, array_column($comissoes, 'user_id'));
        $this->assertStringNotContainsString('222.22', $resposta->getContent());

        // O outro lado é simétrico: vendedorDois só vê a própria.
        $respostaDois = $this->actingAs($this->vendedorDois)->getJson('/comissoes?competencia=2026-07');
        $respostaDois->assertOk();
        $this->assertCount(1, $respostaDois->json('comissoes'));
        $this->assertSame($comissaoDois->id, $respostaDois->json('comissoes.0.id'));
    }

    public function test_usuario_com_ver_todas_ve_a_comissao_de_todo_mundo(): void
    {
        $this->criarComissao($this->vendedorUm, '2026-07', 'aberta', '111.11');
        $this->criarComissao($this->vendedorDois, '2026-07', 'aberta', '222.22');

        $this->assertTrue($this->administrador->can('comissoes-ver-todas'));

        $resposta = $this->actingAs($this->administrador)->getJson('/comissoes?competencia=2026-07');

        $resposta->assertOk();
        $this->assertTrue($resposta->json('ve_todas'));
        $this->assertCount(2, $resposta->json('comissoes'));
    }

    /**
     * A comissão de técnico usa a mesma restrição, pelo vínculo
     * `User::technician()` - mesmo padrão de vínculo usuário-técnico do app
     * do técnico (Plano 12/13).
     */
    public function test_tecnico_ve_apenas_a_propria_comissao_pelo_vinculo_usuario_tecnico(): void
    {
        $tecnicoUsuario = $this->criarUsuario('tecnico');
        $tecnico = $this->comTenant(fn () => \App\Models\Technician::create([
            'name' => 'Técnico com usuário vinculado',
            'email' => fake()->unique()->safeEmail(),
            'phone' => '(11) 99999-0000',
            'is_active' => true,
            'user_id' => $tecnicoUsuario->id,
        ]));

        $comissaoDoTecnico = $this->comTenant(fn () => Commission::create([
            'technician_id' => $tecnico->id,
            'competencia' => '2026-07',
            'situacao' => 'aberta',
            'valor_total' => '75.00',
        ]));

        $this->criarComissao($this->vendedorUm, '2026-07', 'aberta', '111.11');

        $this->assertTrue($tecnicoUsuario->can('comissoes-ver'));

        $resposta = $this->actingAs($tecnicoUsuario)->getJson('/comissoes?competencia=2026-07');

        $resposta->assertOk();
        $this->assertCount(1, $resposta->json('comissoes'));
        $this->assertSame($comissaoDoTecnico->id, $resposta->json('comissoes.0.id'));
    }

    // -----------------------------------------------------------------
    // 3. Fechar e reabrir funcionam, com permissão e justificativa
    // -----------------------------------------------------------------

    public function test_fechar_e_reabrir_funcionam_com_permissao_e_justificativa(): void
    {
        $comissao = $this->criarComissao($this->vendedorUm, '2026-07', 'aberta', '300.00');

        $fechar = $this->actingAs($this->administrador)->postJson("/comissoes/{$comissao->id}/fechar");
        $fechar->assertOk();
        $this->assertSame('fechada', $comissao->fresh()->situacao);

        // Sem permissão comissoes-reabrir: 403, mesmo sendo administrador de
        // um recurso que ele mesmo fechou não importa aqui - testa quem não
        // tem a permissão específica.
        $this->actingAs($this->semPermissao)
            ->postJson("/comissoes/{$comissao->id}/reabrir", ['justificativa' => 'Não deveria nem chegar aqui'])
            ->assertForbidden();

        // Com permissão, mas sem justificativa: 422.
        $this->actingAs($this->administrador)
            ->postJson("/comissoes/{$comissao->id}/reabrir", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('justificativa');

        // Com permissão e justificativa: reabre.
        $reabrir = $this->actingAs($this->administrador)->postJson("/comissoes/{$comissao->id}/reabrir", [
            'justificativa' => 'Vendedor reclamou de item faltando na apuração de julho',
        ]);

        $reabrir->assertOk();
        $this->assertSame('aberta', $comissao->fresh()->situacao);
        $this->assertNull($comissao->fresh()->fechada_em);
    }

    public function test_fechar_exige_permissao_comissoes_fechar(): void
    {
        $comissao = $this->criarComissao($this->vendedorUm, '2026-07', 'aberta', '300.00');

        // vendedorUm tem comissoes-ver mas não comissoes-fechar.
        $this->actingAs($this->vendedorUm)
            ->postJson("/comissoes/{$comissao->id}/fechar")
            ->assertForbidden();

        $this->assertSame('aberta', $comissao->fresh()->situacao);
    }

    // -----------------------------------------------------------------
    // 4. Marcar como paga pode gerar o título a pagar
    // -----------------------------------------------------------------

    public function test_marcar_como_paga_pode_gerar_o_titulo_a_pagar(): void
    {
        $comissao = $this->criarComissao($this->vendedorUm, '2026-07', 'fechada', '300.00');
        $fornecedor = $this->comTenant(fn () => Supplier::create([
            'nome' => 'Vendedor cadastrado como fornecedor',
            'ativo' => true,
        ]));

        $pagar = $this->actingAs($this->administrador)->postJson("/comissoes/{$comissao->id}/pagar", [
            'gerar_titulo' => true,
            'supplier_id' => $fornecedor->id,
            'vencimento' => '2026-09-05',
        ]);

        $pagar->assertOk();

        $comissao->refresh();
        $this->assertSame('paga', $comissao->situacao);
        $this->assertNotNull($comissao->paga_em);
        $this->assertNotNull($comissao->payable_id);

        $titulo = $this->comTenant(fn () => Payable::query()->findOrFail($comissao->payable_id));
        $this->assertSame($fornecedor->id, $titulo->supplier_id);
        $this->assertSame('300.00', $titulo->valor_total);
    }

    public function test_marcar_como_paga_sem_gerar_titulo_apenas_muda_a_situacao(): void
    {
        $comissao = $this->criarComissao($this->vendedorUm, '2026-07', 'fechada', '300.00');

        $this->actingAs($this->administrador)->postJson("/comissoes/{$comissao->id}/pagar", [])->assertOk();

        $comissao->refresh();
        $this->assertSame('paga', $comissao->situacao);
        $this->assertNull($comissao->payable_id);
    }

    public function test_marcar_como_paga_gerando_titulo_sem_fornecedor_e_recusado_pela_validacao(): void
    {
        $comissao = $this->criarComissao($this->vendedorUm, '2026-07', 'fechada', '300.00');

        $this->actingAs($this->administrador)
            ->postJson("/comissoes/{$comissao->id}/pagar", ['gerar_titulo' => true, 'vencimento' => '2026-09-05'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('supplier_id');
    }

    // -----------------------------------------------------------------
    // 5. Metas são criadas em lote por competência
    // -----------------------------------------------------------------

    public function test_metas_sao_criadas_em_lote_por_competencia(): void
    {
        $resposta = $this->actingAs($this->administrador)->postJson('/metas/lote', [
            'competencia' => '2026-08',
            'metas' => [
                ['user_id' => $this->vendedorUm->id, 'tipo' => 'valor_vendido', 'alvo' => 10000],
                ['user_id' => $this->vendedorDois->id, 'tipo' => 'valor_vendido', 'alvo' => 8000, 'observacao' => 'Meta reduzida: entrou no time em agosto'],
            ],
        ]);

        $resposta->assertOk();
        $this->assertSame(2, $resposta->json('quantidade'));

        $metaUm = $this->comTenant(fn () => Goal::query()->where('user_id', $this->vendedorUm->id)->where('competencia', '2026-08')->firstOrFail());
        $metaDois = $this->comTenant(fn () => Goal::query()->where('user_id', $this->vendedorDois->id)->where('competencia', '2026-08')->firstOrFail());

        $this->assertSame('10000.00', $metaUm->alvo);
        $this->assertSame('8000.00', $metaDois->alvo);
        $this->assertSame('Meta reduzida: entrou no time em agosto', $metaDois->observacao);
    }

    /**
     * Reenviar o lote ajusta quem mudou sem duplicar nem falhar para quem
     * não mudou - o `updateOrCreate` documentado em `GoalController::storeLote()`.
     */
    public function test_reenviar_o_lote_de_metas_atualiza_em_vez_de_duplicar(): void
    {
        $this->actingAs($this->administrador)->postJson('/metas/lote', [
            'competencia' => '2026-08',
            'metas' => [['user_id' => $this->vendedorUm->id, 'tipo' => 'valor_vendido', 'alvo' => 10000]],
        ])->assertOk();

        $this->actingAs($this->administrador)->postJson('/metas/lote', [
            'competencia' => '2026-08',
            'metas' => [['user_id' => $this->vendedorUm->id, 'tipo' => 'valor_vendido', 'alvo' => 12000]],
        ])->assertOk();

        $metas = $this->comTenant(fn () => Goal::query()->where('user_id', $this->vendedorUm->id)->where('competencia', '2026-08')->get());

        $this->assertCount(1, $metas, 'reenviar o lote duplicou a meta em vez de atualizar');
        $this->assertSame('12000.00', $metas->first()->alvo);
    }

    /**
     * `vendedorUm` (papel comercial) não serve para este teste: o papel
     * comercial recebe `meta-gerenciar` de propósito (ver
     * `RolesAndPermissionsSeeder::permissoesComercial()`), porque é quem
     * define a meta da equipe. `semPermissao` é quem de fato não tem a
     * permissão.
     */
    public function test_metas_lote_exige_permissao_meta_gerenciar(): void
    {
        $this->assertFalse($this->semPermissao->can('meta-gerenciar'));

        $this->actingAs($this->semPermissao)->postJson('/metas/lote', [
            'competencia' => '2026-08',
            'metas' => [['user_id' => $this->vendedorUm->id, 'tipo' => 'valor_vendido', 'alvo' => 10000]],
        ])->assertForbidden();
    }

    /**
     * Acompanhamento é liberado a qualquer autenticado, escopado à própria
     * meta sem `meta-gerenciar` - mesmo padrão de "só a própria" das
     * comissões, mas sem exigir uma permissão extra para o caso "ver a
     * própria". `semPermissao` é usado aqui pelo mesmo motivo do teste
     * acima: `vendedorUm` tem `meta-gerenciar` e veria a meta de todo mundo,
     * o que não prova nada sobre o escopo "só a própria".
     */
    public function test_acompanhamento_de_meta_e_escopado_sem_meta_gerenciar(): void
    {
        $this->comTenant(fn () => Goal::create([
            'user_id' => $this->semPermissao->id,
            'tipo' => 'valor_vendido',
            'competencia' => '2026-07',
            'alvo' => '10000.00',
        ]));
        $this->comTenant(fn () => Goal::create([
            'user_id' => $this->vendedorDois->id,
            'tipo' => 'valor_vendido',
            'competencia' => '2026-07',
            'alvo' => '5000.00',
        ]));

        $resposta = $this->actingAs($this->semPermissao)->getJson('/metas/acompanhamento?competencia=2026-07');

        $resposta->assertOk();
        $metas = $resposta->json('metas');
        $this->assertCount(1, $metas, 'usuário sem meta-gerenciar recebeu a meta de outra pessoa no acompanhamento');
        $this->assertSame($this->semPermissao->id, $metas[0]['user_id']);
    }

    // -----------------------------------------------------------------
    // 6. Painel de contratos a vencer traz marcos e vencidos sem tratativa
    // -----------------------------------------------------------------

    public function test_painel_de_contratos_a_vencer_traz_marcos_e_vencidos_sem_tratativa(): void
    {
        $aVencer = $this->criarContrato(['end_date' => BusinessDate::hoje()->addDays(30)->toDateString()]);
        $vencido = $this->criarContrato(['end_date' => BusinessDate::hoje()->subDays(5)->toDateString()]);

        $resposta = $this->actingAs($this->administrador)->getJson('/contracts/a-vencer');

        $resposta->assertOk();
        $this->assertSame([60, 30, 15], $resposta->json('marcos'));

        $idsNoMarco30 = array_column($resposta->json('a_vencer.30'), 'id');
        $this->assertContains($aVencer->id, $idsNoMarco30);

        $idsVencidos = array_column($resposta->json('vencidos_sem_tratativa'), 'id');
        $this->assertContains($vencido->id, $idsVencidos);
        $this->assertNotContains($aVencer->id, $idsVencidos);
    }

    public function test_painel_de_contratos_a_vencer_exige_permissao_contrato_ver(): void
    {
        $this->actingAs($this->semPermissao)->getJson('/contracts/a-vencer')->assertForbidden();
    }

    // -----------------------------------------------------------------
    // 7. Renovar cria o contrato novo pelos endpoints
    // -----------------------------------------------------------------

    public function test_renovar_cria_o_contrato_novo_pelo_endpoint(): void
    {
        $anterior = $this->criarContrato([
            'start_date' => '2025-08-20',
            'end_date' => '2026-08-20', // 5 dias à frente de "hoje" (15/08/2026): dentro da janela
            'service_value' => '1000.00',
        ]);

        $previa = $this->actingAs($this->administrador)->getJson("/contracts/{$anterior->id}/renovar/previa");
        $previa->assertOk();
        $this->assertTrue($previa->json('elegivel'));
        $this->assertSame('2026-08-21', $previa->json('inicio_do_novo_contrato'));

        $renovar = $this->actingAs($this->administrador)->postJson("/contracts/{$anterior->id}/renovar", [
            'percentual_reajuste' => 10,
            'end_date' => '2027-08-20',
        ]);

        $renovar->assertOk();
        $this->assertTrue($renovar->json('success'));
        $this->assertNotEmpty($renovar->json('data.contrato_novo'));

        $novo = $this->comTenant(fn () => Contract::query()->where('contrato_anterior_id', $anterior->id)->firstOrFail());
        $this->assertSame('2026-08-21', BusinessDate::diaDe($novo->start_date));
        $this->assertSame('1100.00', $novo->service_value, '10% de reajuste sobre 1000,00');
        $this->assertSame('renovado', $anterior->fresh()->situacao_renovacao);
    }

    public function test_nao_renovar_registra_motivo_e_em_negociacao_marca_a_situacao(): void
    {
        $contrato = $this->criarContrato(['end_date' => BusinessDate::hoje()->addDays(5)->toDateString()]);

        $naoRenovar = $this->actingAs($this->administrador)->postJson("/contracts/{$contrato->id}/nao-renovar", [
            'motivo' => 'preco',
        ]);

        $naoRenovar->assertOk();
        $this->assertSame('nao_renovado', $contrato->fresh()->situacao_renovacao);

        $outroContrato = $this->criarContrato(['end_date' => BusinessDate::hoje()->addDays(6)->toDateString()]);

        $emNegociacao = $this->actingAs($this->administrador)->postJson("/contracts/{$outroContrato->id}/em-negociacao");

        $emNegociacao->assertOk();
        $outroContrato->refresh();
        $this->assertSame('em_negociacao', $outroContrato->situacao_renovacao);
        $this->assertNotNull($outroContrato->em_negociacao_em);
    }

    public function test_renovar_exige_permissao_contrato_renovar(): void
    {
        $contrato = $this->criarContrato(['end_date' => BusinessDate::hoje()->addDays(5)->toDateString()]);

        $this->actingAs($this->semPermissao)
            ->postJson("/contracts/{$contrato->id}/renovar", [])
            ->assertForbidden();

        $this->assertNull($contrato->fresh()->situacao_renovacao);
    }

    // -----------------------------------------------------------------
    // 8. Usuário sem permissão recebe 403 em cada rota nova
    // -----------------------------------------------------------------

    public function test_usuario_sem_permissao_recebe_403_em_cada_rota_nova(): void
    {
        $comissao = $this->criarComissao($this->vendedorUm, '2026-07', 'fechada', '100.00');
        $regra = $this->criarRegra([
            'tipo' => 'vendedor',
            'base' => 'vendido',
            'valor' => 5,
            'vigencia_inicio' => '2026-01-01',
        ]);
        $meta = $this->comTenant(fn () => Goal::create([
            'user_id' => $this->vendedorUm->id,
            'tipo' => 'valor_vendido',
            'competencia' => '2026-08',
            'alvo' => '1000.00',
        ]));
        $contrato = $this->criarContrato(['end_date' => BusinessDate::hoje()->addDays(5)->toDateString()]);

        $usuario = $this->semPermissao;

        $casos = [
            ['GET', '/comissoes'],
            ['POST', '/comissoes/apurar'],
            ['POST', "/comissoes/{$comissao->id}/fechar"],
            ['POST', "/comissoes/{$comissao->id}/reabrir"],
            ['POST', "/comissoes/{$comissao->id}/pagar"],
            ['GET', '/comissoes/regras'],
            ['POST', '/comissoes/regras'],
            ['PUT', "/comissoes/regras/{$regra->id}"],
            ['DELETE', "/comissoes/regras/{$regra->id}"],
            ['GET', '/metas'],
            ['POST', '/metas'],
            ['POST', '/metas/lote'],
            ['PUT', "/metas/{$meta->id}"],
            ['DELETE', "/metas/{$meta->id}"],
            ['GET', '/comercial/indicadores'],
            ['GET', '/contracts/a-vencer'],
            ['GET', "/contracts/{$contrato->id}/renovar/previa"],
            ['POST', "/contracts/{$contrato->id}/renovar"],
            ['POST', "/contracts/{$contrato->id}/nao-renovar"],
            ['POST', "/contracts/{$contrato->id}/em-negociacao"],
        ];

        foreach ($casos as [$metodo, $url]) {
            $resposta = $this->actingAs($usuario)->json($metodo, $url);

            $this->assertSame(
                403,
                $resposta->status(),
                "{$metodo} {$url} deveria devolver 403 para usuário sem permissão, devolveu {$resposta->status()}"
            );
        }
    }

    // -----------------------------------------------------------------
    // Vazamento entre empresas (skill permissoes-e-multitenancy)
    // -----------------------------------------------------------------

    /**
     * Todo endpoint novo que recebe um model por route-model binding
     * (`{comissao}`, `{regra}`, `{meta}`, `{contract}`) precisa devolver 404
     * para registro de outra empresa. O escopo global de `BelongsToCompany`
     * já cobre isso automaticamente (nenhum dos quatro models sobrescreve o
     * binding como `User` faz); este teste é a rede de segurança.
     */
    public function test_nenhum_endpoint_novo_alcanca_registro_de_outra_empresa(): void
    {
        $outra = Company::create([
            'name' => 'Dedetizadora Vizinha',
            'cnpj' => '33.333.333/0001-33',
            'email' => 'contato@vizinha-comissao.test',
        ]);

        [$comissaoAlheia, $regraAlheia, $metaAlheia, $contratoAlheio] = TenantAtual::comTenant($outra->id, function () {
            $vendedor = User::factory()->create([
                'name' => 'Vendedor da vizinha',
                'email' => 'vendedor-vizinha-'.uniqid().'@exemplo.test',
            ]);

            $comissao = Commission::create([
                'user_id' => $vendedor->id,
                'competencia' => '2026-07',
                'situacao' => 'fechada',
                'valor_total' => '999.00',
            ]);

            $regra = CommissionRule::create([
                'tipo' => 'vendedor',
                'base' => 'vendido',
                'forma' => 'percentual',
                'valor' => '5.0000',
                'vigencia_inicio' => '2026-01-01',
                'ativa' => true,
            ]);

            $meta = Goal::create([
                'user_id' => $vendedor->id,
                'tipo' => 'valor_vendido',
                'competencia' => '2026-08',
                'alvo' => '5000.00',
            ]);

            $address = AddressFactory::new()->create();
            $contrato = Contract::create([
                'address_id' => $address->id,
                'contract_number' => 'CONT-VIZINHA-000001',
                'service_type' => 'periodico',
                'start_date' => '2025-08-20',
                'end_date' => BusinessDate::hoje()->addDays(5)->toDateString(),
                'service_value' => '500.00',
            ]);

            return [$comissao, $regra, $meta, $contrato];
        });

        $casos = [
            ['POST', "/comissoes/{$comissaoAlheia->id}/fechar"],
            ['POST', "/comissoes/{$comissaoAlheia->id}/reabrir"],
            ['POST', "/comissoes/{$comissaoAlheia->id}/pagar"],
            ['PUT', "/comissoes/regras/{$regraAlheia->id}"],
            ['DELETE', "/comissoes/regras/{$regraAlheia->id}"],
            ['PUT', "/metas/{$metaAlheia->id}"],
            ['DELETE', "/metas/{$metaAlheia->id}"],
            ['GET', "/contracts/{$contratoAlheio->id}/renovar/previa"],
            ['POST', "/contracts/{$contratoAlheio->id}/renovar"],
            ['POST', "/contracts/{$contratoAlheio->id}/nao-renovar"],
            ['POST', "/contracts/{$contratoAlheio->id}/em-negociacao"],
        ];

        foreach ($casos as [$metodo, $url]) {
            $resposta = $this->actingAs($this->administrador)->json($metodo, $url, $metodo === 'PUT' || $metodo === 'POST' ? ['justificativa' => 'Tentativa de acesso entre empresas', 'motivo' => 'preco'] : []);

            $this->assertSame(
                404,
                $resposta->status(),
                "{$metodo} {$url} devolveu {$resposta->status()} para registro de outra empresa: deveria ser 404"
            );
        }

        $this->assertSame('fechada', $comissaoAlheia->fresh()->situacao, 'a comissão da outra empresa não pode ter sido alterada');
        $this->assertNull($contratoAlheio->fresh()->situacao_renovacao, 'o contrato da outra empresa não pode ter sido alterado');
    }

    // -----------------------------------------------------------------
    // Apoio: catálogo de permissões
    // -----------------------------------------------------------------

    public function test_as_seis_permissoes_novas_estao_no_catalogo(): void
    {
        $catalogo = collect(\App\Console\Commands\SyncPermissions::catalogo())->flatten()->all();

        $this->assertContains('comissoes-ver', $catalogo);
        $this->assertContains('comissoes-ver-todas', $catalogo);
        $this->assertContains('comissoes-apurar', $catalogo);
        $this->assertContains('comissoes-fechar', $catalogo);
        $this->assertContains('meta-gerenciar', $catalogo);
        $this->assertContains('contrato-renovar', $catalogo);
    }

    // -----------------------------------------------------------------
    // Apoio: fixtures
    // -----------------------------------------------------------------

    private function comTenant(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->id, $callback);
    }

    private function criarUsuario(string $papel): User
    {
        $usuario = $this->comTenant(fn () => User::factory()->create([
            'name' => 'Usuário '.$papel.' '.uniqid(),
            'email' => $papel.'-'.uniqid().'@exemplo.test',
            'is_active' => true,
        ]));

        $usuario->assignRole($papel);

        return $usuario->fresh();
    }

    private function criarCliente(): Client
    {
        return $this->comTenant(fn () => ClientFactory::new()->create());
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function criarRegra(array $dados): CommissionRule
    {
        return $this->comTenant(fn () => CommissionRule::create($dados + [
            'forma' => 'percentual',
            'vigencia_fim' => null,
            'ativa' => true,
        ]));
    }

    private function criarComissao(User $vendedor, string $competencia, string $situacao, string $valorTotal): Commission
    {
        return $this->comTenant(fn () => Commission::create([
            'user_id' => $vendedor->id,
            'competencia' => $competencia,
            'situacao' => $situacao,
            'valor_total' => $valorTotal,
            'fechada_em' => in_array($situacao, ['fechada', 'paga'], true) ? now() : null,
            'fechada_por' => in_array($situacao, ['fechada', 'paga'], true) ? $this->administrador->id : null,
        ]));
    }

    /**
     * Cria um orçamento aprovado NA DATA INFORMADA, mesmo critério de
     * `CommissionCalculationServiceTest::criarOrcamentoAprovado()`: o relógio
     * do teste é movido para lá durante a mudança de status, para o
     * `AuditLog` que `Budget` grava nascer com aquele `created_at`.
     */
    private function criarOrcamentoAprovado(User $vendedor, Client $cliente, string $dataAprovacao, string $valorServico): Budget
    {
        return $this->comTenant(function () use ($vendedor, $cliente, $dataAprovacao, $valorServico) {
            Carbon::setTestNow($dataAprovacao.' 09:00:00');

            $orcamento = Budget::create([
                'client_id' => $cliente->id,
                'user_id' => $vendedor->id,
                'date' => $dataAprovacao,
                'priority' => 'normal',
                'status' => 'draft',
            ]);

            $servico = Service::create([
                'name' => 'Dedetização de teste',
                'price' => $valorServico,
                'is_active' => true,
            ]);

            $orcamento->services()->attach($servico->id, [
                'quantity' => 1,
                'unit_price' => $valorServico,
                'subtotal' => $valorServico,
            ]);

            $orcamento->update(['status' => 'approved']);

            Carbon::setTestNow(self::AGORA);

            return $orcamento->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function criarContrato(array $atributos = []): Contract
    {
        return $this->comTenant(function () use ($atributos) {
            $address = AddressFactory::new()->create();

            return Contract::query()->create(array_merge([
                'address_id' => $address->id,
                'contract_number' => 'CONT-'.str_pad((string) $address->id, 6, '0', STR_PAD_LEFT).'-'.uniqid(),
                'service_type' => 'periodico',
                'visit_frequency' => 'mensal',
                'visit_frequency_valor' => 1,
                'visit_frequency_unidade' => 'meses',
                'visit_count' => 12,
                'service_value' => '1000.00',
                'start_date' => '2025-08-20',
                'end_date' => '2026-08-20',
            ], $atributos));
        });
    }

}
