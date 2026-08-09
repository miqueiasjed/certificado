<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Module;
use App\Models\PersonalProtectiveEquipment;
use App\Models\Service;
use App\Models\ServicePpeRequirement;
use App\Models\User;
use App\Models\WorkOrderPpeConfirmation;
use App\Services\ModuleService;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Closure;
use Database\Factories\PersonalProtectiveEquipmentFactory;
use Database\Factories\ServicePpeRequirementFactory;
use Database\Factories\WorkOrderFactory;
use Database\Factories\WorkOrderPpeConfirmationFactory;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Task 29.6 do Plano 29: as quatro rotas de EPI exigido por serviço, vistas pela
 * porta de entrada.
 *
 * O que se prova aqui não é regra de negócio — isso é assunto do
 * `ExigenciaDeEpiService` — e sim **quem consegue bater na porta e o que a porta
 * responde**. Três eixos, todos com defeito conhecido no histórico do projeto:
 *
 * **1. Permissão.** As quatro rotas, leitura incluída, ficam sob
 * `epi-gerenciar`, e não sob `epi-ver`: declarar o que a empresa passa a exigir
 * em campo é configuração do mesmo cadastro que `epi-gerenciar` já governa, e
 * quem apenas enxerga a ficha do técnico não decide isso. Um teste que só
 * verificasse "administrador consegue, anônimo não" deixaria essa separação
 * virar decoração. Nenhuma permissão nova entrou no catálogo nesta task, e o
 * teste do catálogo guarda essa decisão.
 *
 * **2. Id de outra empresa vindo do corpo.** O escopo global do Eloquent não
 * alcança o que a regra `exists` do Laravel consulta: `exists:` cru aceita id de
 * outro tenant e ainda serve de oráculo ("este id existe em alguma empresa?").
 * É o defeito corrigido no commit `d9a3a9c`, e a exigência aqui é a recusa **na
 * validação** (422), com nada gravado — não o conserto silencioso mais adiante,
 * no Service. Model que chega por rota é outra história: ali o binding escopado
 * responde 404, que é a resposta certa quando revelar a existência já vaza.
 *
 * **3. Módulo desligado.** O módulo `epi` nasce desligado, e o Deploy 1 do plano
 * sobe com ele assim em todo mundo: digitar a URL direto não pode contornar o
 * bloqueio.
 */
class ExigenciaDeEpiEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const AGORA = '2026-08-08 15:00:00';

    /**
     * As quatro rotas da task, pelo nome.
     *
     * @var array<int, string>
     */
    private const ROTAS = [
        'epi.exigencias.index',
        'epi.exigencias.store',
        'epi.exigencias.update',
        'epi.exigencias.destroy',
    ];

    private Company $empresa;

    private User $gestor;

    private Service $servico;

    private ?Company $concorrente = null;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AGORA, 'UTC'));
        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ModulesSeeder::class);

        $this->empresa = Company::query()->findOrFail(1);

        $this->gestor = $this->criarUsuario(['epi-gerenciar']);

        $this->servico = $this->comTenant(fn (): Service => Service::create([
            'name' => 'Desinsetização',
            'category' => 'controle_de_pragas',
            'price' => 300,
            'is_active' => true,
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Permissão
    // -----------------------------------------------------------------

    /**
     * Nenhuma permissão nova entrou no catálogo: as quatro rotas reusam
     * `epi-gerenciar`, que o Plano 28 já criou. Permissão inventada em código e
     * ausente do `SyncPermissions` desaparece no próximo sync do servidor.
     */
    public function test_as_quatro_rotas_estao_todas_sob_epi_gerenciar(): void
    {
        foreach (self::ROTAS as $nome) {
            $rota = Route::getRoutes()->getByName($nome);

            $this->assertNotNull($rota, "a rota {$nome} deveria estar registrada");

            $middlewares = $rota->gatherMiddleware();

            $this->assertContains(
                'permission:epi-gerenciar',
                $middlewares,
                "a rota {$nome} precisa exigir epi-gerenciar: declarar o que a empresa exige em campo é cadastro, não leitura"
            );
            $this->assertContains('module:epi', $middlewares, "a rota {$nome} precisa estar sob o módulo epi");
        }
    }

    public function test_quem_tem_epi_gerenciar_percorre_o_ciclo_completo_da_exigencia(): void
    {
        $epi = $this->criarEpi(['nome' => 'Respirador semifacial']);

        $criacao = $this->actingAs($this->gestor)->postJson('/epis/exigencias', [
            'service_id' => $this->servico->getKey(),
            'personal_protective_equipment_id' => $epi->getKey(),
        ]);

        $criacao->assertCreated();
        $this->assertTrue($criacao->json('exigencia.obrigatorio'), 'quem cadastra uma exigência está dizendo que o EPI é exigido');

        $id = $criacao->json('exigencia.id');

        $listagem = $this->actingAs($this->gestor)
            ->getJson('/epis/exigencias?service_id='.$this->servico->getKey());

        $listagem->assertOk();
        $this->assertCount(1, $listagem->json('exigencias'));
        $this->assertSame('Respirador semifacial', $listagem->json('exigencias.0.personal_protective_equipment.nome'));

        $this->actingAs($this->gestor)
            ->putJson("/epis/exigencias/{$id}", ['obrigatorio' => false])
            ->assertOk();

        $this->assertFalse($this->comTenant(fn () => ServicePpeRequirement::query()->findOrFail($id)->obrigatorio));

        $this->actingAs($this->gestor)->deleteJson("/epis/exigencias/{$id}")->assertOk();

        $this->assertSame(0, $this->comTenant(fn (): int => ServicePpeRequirement::query()->count()));
    }

    /**
     * `epi-ver` abre a leitura operacional (o cadastro do modelo, a ficha do
     * técnico). Ele **não** abre a declaração do que a empresa passa a exigir em
     * campo — nem para ler, porque a listagem já entrega o cadastro inteiro de
     * EPIs disponíveis junto.
     */
    public function test_quem_so_tem_epi_ver_nao_alcanca_nenhuma_das_quatro_rotas(): void
    {
        $leitor = $this->criarUsuario(['epi-ver']);
        $exigencia = $this->exigencia($this->criarEpi());

        $this->actingAs($leitor)->getJson('/epis/exigencias')->assertForbidden();
        $this->actingAs($leitor)->postJson('/epis/exigencias', [
            'service_id' => $this->servico->getKey(),
            'personal_protective_equipment_id' => $this->criarEpi()->getKey(),
        ])->assertForbidden();
        $this->actingAs($leitor)
            ->putJson("/epis/exigencias/{$exigencia->getKey()}", ['obrigatorio' => false])
            ->assertForbidden();
        $this->actingAs($leitor)->deleteJson("/epis/exigencias/{$exigencia->getKey()}")->assertForbidden();

        $this->assertSame(
            1,
            $this->comTenant(fn (): int => ServicePpeRequirement::query()->count()),
            'nenhuma das recusas pode ter alterado o cadastro'
        );
        $this->assertTrue($exigencia->fresh()->obrigatorio);
    }

    public function test_usuario_sem_permissao_nenhuma_e_barrado(): void
    {
        $semNada = $this->criarUsuario([]);

        $this->actingAs($semNada)->getJson('/epis/exigencias')->assertForbidden();
    }

    /**
     * O módulo `epi` nasce desligado, e é assim que o Deploy 1 do plano sobe em
     * todo mundo. Digitar a URL direto não contorna o bloqueio, nem para quem tem
     * a permissão.
     */
    public function test_modulo_de_epi_desligado_bloqueia_as_quatro_rotas(): void
    {
        $exigencia = $this->exigencia($this->criarEpi());

        $modulo = Module::query()->where('chave', 'epi')->firstOrFail();
        app(ModuleService::class)->bloquearPara($this->empresa, $modulo, 'Desligado no teste.');

        $this->actingAs($this->gestor)->getJson('/epis/exigencias')->assertForbidden();
        $this->actingAs($this->gestor)->postJson('/epis/exigencias', [
            'service_id' => $this->servico->getKey(),
            'personal_protective_equipment_id' => $this->criarEpi()->getKey(),
        ])->assertForbidden();
        $this->actingAs($this->gestor)
            ->putJson("/epis/exigencias/{$exigencia->getKey()}", ['obrigatorio' => false])
            ->assertForbidden();
        $this->actingAs($this->gestor)->deleteJson("/epis/exigencias/{$exigencia->getKey()}")->assertForbidden();

        $this->assertSame(1, $this->comTenant(fn (): int => ServicePpeRequirement::query()->count()));
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas
    // -----------------------------------------------------------------

    /**
     * Model por rota: o binding escopado não encontra a exigência da concorrente
     * e responde 404 — a resposta certa quando revelar a existência do registro
     * já vaza informação.
     */
    public function test_exigencia_de_outra_empresa_por_rota_devolve_404(): void
    {
        $daConcorrente = $this->exigenciaDaConcorrente();

        $this->actingAs($this->gestor)
            ->putJson("/epis/exigencias/{$daConcorrente->getKey()}", ['obrigatorio' => false])
            ->assertNotFound();

        $this->actingAs($this->gestor)
            ->deleteJson("/epis/exigencias/{$daConcorrente->getKey()}")
            ->assertNotFound();

        $this->assertNotNull(
            TenantAtual::comTenant(
                (int) $this->concorrente()->getKey(),
                fn () => ServicePpeRequirement::query()->find($daConcorrente->getKey())
            ),
            'a exigência da concorrente continua intacta'
        );
    }

    /**
     * Id vindo do **corpo**, onde o escopo global não alcança. A recusa precisa
     * ser 422, pela validação, e não um 404 do Service mais adiante: a diferença
     * é o que separa "a tela não deveria ter oferecido isso" de "descobri que
     * este id existe em algum tenant".
     */
    public function test_servico_de_outra_empresa_no_corpo_e_recusado_pela_validacao(): void
    {
        $servicoDaConcorrente = TenantAtual::comTenant(
            (int) $this->concorrente()->getKey(),
            fn (): Service => Service::create([
                'name' => 'Desinsetização da concorrente',
                'price' => 500,
                'is_active' => true,
            ])
        );

        $resposta = $this->actingAs($this->gestor)->postJson('/epis/exigencias', [
            'service_id' => $servicoDaConcorrente->getKey(),
            'personal_protective_equipment_id' => $this->criarEpi()->getKey(),
        ]);

        $resposta->assertStatus(422)->assertJsonValidationErrors('service_id');

        $this->assertSame(0, $this->comTenant(fn (): int => ServicePpeRequirement::query()->count()));
        $this->assertSame(
            0,
            TenantAtual::comTenant(
                (int) $this->concorrente()->getKey(),
                fn (): int => ServicePpeRequirement::query()->count()
            ),
            'nada pode ter sido gravado na empresa da concorrente'
        );
    }

    public function test_epi_de_outra_empresa_no_corpo_e_recusado_pela_validacao(): void
    {
        $epiDaConcorrente = TenantAtual::comTenant(
            (int) $this->concorrente()->getKey(),
            fn (): PersonalProtectiveEquipment => PersonalProtectiveEquipmentFactory::new()
                ->create(['nome' => 'Respirador da concorrente'])
        );

        $resposta = $this->actingAs($this->gestor)->postJson('/epis/exigencias', [
            'service_id' => $this->servico->getKey(),
            'personal_protective_equipment_id' => $epiDaConcorrente->getKey(),
        ]);

        $resposta->assertStatus(422)->assertJsonValidationErrors('personal_protective_equipment_id');

        $this->assertSame(0, $this->comTenant(fn (): int => ServicePpeRequirement::query()->count()));
    }

    /**
     * O filtro de listagem recebe o mesmo `service_id` pelo corpo, e por isso
     * carrega a mesma regra: sem ela, a rota de cadastro logo abaixo aceitaria o
     * id que a listagem já teria confirmado existir.
     */
    public function test_a_listagem_nao_mostra_nem_aceita_filtro_de_outra_empresa(): void
    {
        $daConcorrente = $this->exigenciaDaConcorrente();
        $this->exigencia($this->criarEpi(['nome' => 'Respirador da minha empresa']));

        $listagem = $this->actingAs($this->gestor)->getJson('/epis/exigencias');

        $listagem->assertOk();
        $this->assertCount(1, $listagem->json('exigencias'));
        $this->assertStringNotContainsString('Respirador da concorrente', $listagem->getContent());

        $this->actingAs($this->gestor)
            ->getJson('/epis/exigencias?service_id='.$daConcorrente->service_id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_id');
    }

    // -----------------------------------------------------------------
    // O que o cadastro faz e o que ele não desfaz
    // -----------------------------------------------------------------

    /**
     * Quem clicou duas vezes no botão, ou reabriu a tela com o item já
     * cadastrado, não pode receber um 500 de violação de chave única: o segundo
     * cadastro atualiza a linha existente.
     */
    public function test_cadastrar_o_mesmo_par_duas_vezes_atualiza_em_vez_de_estourar(): void
    {
        $epi = $this->criarEpi();

        $corpo = [
            'service_id' => $this->servico->getKey(),
            'personal_protective_equipment_id' => $epi->getKey(),
        ];

        $primeira = $this->actingAs($this->gestor)->postJson('/epis/exigencias', $corpo);
        $segunda = $this->actingAs($this->gestor)
            ->postJson('/epis/exigencias', $corpo + ['obrigatorio' => false]);

        $primeira->assertCreated();
        $segunda->assertCreated();

        $this->assertSame($primeira->json('exigencia.id'), $segunda->json('exigencia.id'));
        $this->assertSame(1, $this->comTenant(fn (): int => ServicePpeRequirement::query()->count()));
        $this->assertFalse($segunda->json('exigencia.obrigatorio'));
    }

    /**
     * O cadastro do Plano 28 inativa em vez de excluir, e um EPI inativo é o que
     * a empresa deixou de usar. Exigir em campo o que não se compra mais
     * produziria pendência que ninguém consegue resolver — e a carga do dia
     * sequer levaria o item ao aparelho, então a exigência nasceria invisível.
     */
    public function test_epi_inativo_nao_vira_exigencia(): void
    {
        $aposentado = $this->criarEpi(['nome' => 'Máscara aposentada', 'ativo' => false]);

        $resposta = $this->actingAs($this->gestor)->postJson('/epis/exigencias', [
            'service_id' => $this->servico->getKey(),
            'personal_protective_equipment_id' => $aposentado->getKey(),
        ]);

        $resposta->assertStatus(422)->assertJsonValidationErrors('personal_protective_equipment_id');

        $this->assertSame(0, $this->comTenant(fn (): int => ServicePpeRequirement::query()->count()));
    }

    /**
     * Remover a exigência é correção de cadastro. O que o técnico confirmou
     * vestir em campo aconteceu, e continua no histórico: é registro de execução,
     * não configuração.
     */
    public function test_remover_a_exigencia_nao_apaga_a_confirmacao_ja_registrada(): void
    {
        $epi = $this->criarEpi();
        $exigencia = $this->exigencia($epi);

        $confirmacao = $this->comTenant(function () use ($epi): WorkOrderPpeConfirmation {
            $os = WorkOrderFactory::new()->create();

            return WorkOrderPpeConfirmationFactory::new()->naOrdem($os, $epi)->create();
        });

        $this->actingAs($this->gestor)->deleteJson("/epis/exigencias/{$exigencia->getKey()}")->assertOk();

        $this->assertSame(0, $this->comTenant(fn (): int => ServicePpeRequirement::query()->count()));
        $this->assertNotNull(
            $this->comTenant(fn () => WorkOrderPpeConfirmation::query()->find($confirmacao->getKey())),
            'a prova de uso em campo não pode sumir junto com o cadastro'
        );
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Usuário sem papel, com exatamente as permissões pedidas. Papel traria junto
     * o resto do catálogo e apagaria a distinção que estes testes existem para
     * medir.
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

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function criarEpi(array $atributos = []): PersonalProtectiveEquipment
    {
        return $this->comTenant(
            fn (): PersonalProtectiveEquipment => PersonalProtectiveEquipmentFactory::new()->create($atributos)
        );
    }

    private function exigencia(PersonalProtectiveEquipment $epi): ServicePpeRequirement
    {
        return $this->comTenant(fn (): ServicePpeRequirement => ServicePpeRequirementFactory::new()
            ->exigidoPor($this->servico, $epi)
            ->create());
    }

    private function concorrente(): Company
    {
        return $this->concorrente ??= Company::create([
            'name' => 'Concorrente da exigência',
            'cnpj' => '55.555.555/0001-55',
            'email' => 'contato@concorrente-exigencia.test',
        ]);
    }

    private function exigenciaDaConcorrente(): ServicePpeRequirement
    {
        return TenantAtual::comTenant((int) $this->concorrente()->getKey(), function (): ServicePpeRequirement {
            $servico = Service::create([
                'name' => 'Desratização da concorrente',
                'price' => 400,
                'is_active' => true,
            ]);

            $epi = PersonalProtectiveEquipmentFactory::new()->create(['nome' => 'Respirador da concorrente']);

            return ServicePpeRequirementFactory::new()->exigidoPor($servico, $epi)->create();
        });
    }

    private function comTenant(Closure $callback): mixed
    {
        return TenantAtual::comTenant((int) $this->empresa->getKey(), $callback);
    }
}
