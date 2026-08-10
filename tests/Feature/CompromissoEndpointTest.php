<?php

namespace Tests\Feature;

use App\Console\Commands\SyncPermissions;
use App\Models\Address;
use App\Models\Client;
use App\Models\Company;
use App\Models\Compromisso;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\CompromissoService;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Closure;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Factories\CompromissoFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 30.6 do Plano 30: o compromisso avulso visto pela porta de entrada.
 *
 * O que se prova aqui não é a regra em si - isso é assunto de
 * `CompromissoService` - e sim quem consegue bater na porta, o que a porta
 * responde e o que fica gravado. Três eixos, no mesmo critério de
 * `EpiEndpointTest` (Task 28.7):
 *
 * **1. Permissão.** `compromisso-gerenciar` é a única permissão de escrita
 * (Task 30.3 decidiu não segregar quem cria de quem corrige, diferente do
 * EPI): sem ela, toda rota de escrita responde 403.
 *
 * **2. Id de outra empresa vindo do corpo da requisição.** `client_id`,
 * `address_id`, `technician_id` e `work_order_id` chegam pelo corpo, onde o
 * escopo global do Eloquent não alcança - é o mesmo defeito descrito no
 * cabeçalho de `CompromissoRequest` e corrigido no commit `d9a3a9c` para o
 * EPI. A recusa certa é 422 na validação, com nada gravado. Compromisso que
 * chega pela rota é outra história: o binding escopado responde 404.
 *
 * **3. Exceção de domínio vira 422 em português, nunca 500.**
 * `CompromissoNaoPromovelException` cobre as duas recusas de promoção (já
 * promovido, cancelado), com a mensagem pronta.
 *
 * Sem teste de módulo desligado: a Task 30.3 decidiu que compromisso não é
 * `module:`-gated, mesmo critério da Agenda (Plano 10), da qual é extensão.
 * Sem teste de leitura por rota própria: não existe `GET /compromissos` nem
 * `GET /compromissos/{id}` nesta task - a leitura acontece por
 * `GET /agenda/dados` (Task 30.4, coberta em `AgendaTest`).
 */
class CompromissoEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const AGORA = '2026-08-10 12:00:00';

    private Company $empresa;

    private User $administrador;

    private Client $cliente;

    private Address $endereco;

    private Technician $tecnico;

    private ?Company $outraEmpresa = null;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::AGORA);
        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->empresa = Company::query()->findOrFail(1);

        $this->administrador = $this->comTenant(function (): User {
            $admin = User::factory()->create(['is_active' => true]);
            $admin->assignRole('administrador');

            return $admin;
        });

        $this->cliente = $this->comTenant(fn (): Client => ClientFactory::new()->create());
        $this->endereco = $this->comTenant(
            fn (): Address => AddressFactory::new()->create(['client_id' => $this->cliente->id])
        );
        $this->tecnico = $this->criarTecnico();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Catálogo
    // -----------------------------------------------------------------

    public function test_as_duas_permissoes_estao_no_catalogo(): void
    {
        $catalogo = collect(SyncPermissions::catalogo())->flatten()->all();

        foreach (['compromisso-ver', 'compromisso-gerenciar'] as $permissao) {
            $this->assertContains($permissao, $catalogo);
        }
    }

    // -----------------------------------------------------------------
    // Ciclo completo: criar, atualizar, concluir, cancelar, promover
    // -----------------------------------------------------------------

    public function test_o_administrador_percorre_o_ciclo_completo_ate_a_promocao_para_os(): void
    {
        $criacao = $this->actingAs($this->administrador)->postJson('/compromissos', $this->dadosValidos());

        $criacao->assertCreated();
        $compromissoId = $criacao->json('compromisso.id');

        $this->assertDatabaseHas('compromissos', [
            'id' => $compromissoId,
            'company_id' => $this->empresa->getKey(),
            'criado_por' => $this->administrador->getKey(),
            'situacao' => 'agendado',
            'tipo' => 'orcamento',
            'titulo' => 'Orçamento para nova instalação',
            'client_id' => $this->cliente->id,
            'address_id' => $this->endereco->id,
            'technician_id' => $this->tecnico->id,
            'work_order_id' => null,
        ]);

        $atualizacao = $this->actingAs($this->administrador)->putJson(
            "/compromissos/{$compromissoId}",
            array_merge($this->dadosValidos(), [
                'titulo' => 'Orçamento revisado',
                'observacoes' => 'Cliente pediu para adiantar a visita.',
            ])
        );

        $atualizacao->assertOk();
        $this->assertDatabaseHas('compromissos', [
            'id' => $compromissoId,
            'titulo' => 'Orçamento revisado',
            'observacoes' => 'Cliente pediu para adiantar a visita.',
        ]);

        $conclusao = $this->actingAs($this->administrador)->postJson("/compromissos/{$compromissoId}/concluir");

        $conclusao->assertOk();
        $this->assertSame('concluido', $conclusao->json('compromisso.situacao'));

        $promocao = $this->actingAs($this->administrador)->postJson("/compromissos/{$compromissoId}/promover-os");

        $promocao->assertOk();

        $ordemDeServicoId = $promocao->json('ordem_de_servico.id');

        // A OS nasce com os dados certos: cliente, endereço, técnico e data do
        // compromisso, mais a origem que a identifica.
        $this->assertDatabaseHas('work_orders', [
            'id' => $ordemDeServicoId,
            'company_id' => $this->empresa->getKey(),
            'client_id' => $this->cliente->id,
            'address_id' => $this->endereco->id,
            'technician_id' => $this->tecnico->id,
            'status' => 'scheduled',
            'origem' => CompromissoService::ORIGEM_DA_OS,
        ]);

        $osGravada = $this->comTenant(fn () => WorkOrder::query()->findOrFail($ordemDeServicoId));

        $this->assertSame('2026-08-20', $osGravada->scheduled_date->toDateString());
        $this->assertStringContainsString((string) $compromissoId, $osGravada->description);
        $this->assertStringContainsString('Orçamento revisado', $osGravada->description);
        $this->assertStringContainsString(
            'Observações do compromisso: Cliente pediu para adiantar a visita.',
            $osGravada->description
        );

        // O compromisso mantém os próprios dados, só com work_order_id preenchido.
        $compromissoGravado = $this->comTenant(fn () => Compromisso::query()->findOrFail($compromissoId));

        $this->assertSame($ordemDeServicoId, $compromissoGravado->work_order_id);
        $this->assertSame(
            'concluido',
            $compromissoGravado->situacao,
            'a promoção não pode mexer na situação do compromisso'
        );
        $this->assertSame('Orçamento revisado', $compromissoGravado->titulo);
        $this->assertSame($this->cliente->id, $compromissoGravado->client_id);

        // Cancelamento, num compromisso separado: cancelado nunca some, só
        // muda de situação (CompromissoService::cancelar()).
        $outroCompromisso = $this->criarCompromisso();

        $cancelamento = $this->actingAs($this->administrador)
            ->postJson("/compromissos/{$outroCompromisso->id}/cancelar");

        $cancelamento->assertOk();
        $this->assertSame('cancelado', $cancelamento->json('compromisso.situacao'));
        $this->assertDatabaseHas('compromissos', ['id' => $outroCompromisso->id, 'situacao' => 'cancelado']);
    }

    // -----------------------------------------------------------------
    // Permissão: sem compromisso-gerenciar, nenhuma escrita passa
    // -----------------------------------------------------------------

    public function test_usuario_sem_compromisso_gerenciar_recebe_403_em_toda_rota_de_escrita(): void
    {
        $semNada = $this->criarUsuario([]);
        $compromisso = $this->criarCompromisso();

        $this->actingAs($semNada)->postJson('/compromissos', $this->dadosValidos())->assertForbidden();
        $this->actingAs($semNada)
            ->putJson("/compromissos/{$compromisso->id}", $this->dadosValidos())
            ->assertForbidden();
        $this->actingAs($semNada)->deleteJson("/compromissos/{$compromisso->id}")->assertForbidden();
        $this->actingAs($semNada)->postJson("/compromissos/{$compromisso->id}/cancelar")->assertForbidden();
        $this->actingAs($semNada)->postJson("/compromissos/{$compromisso->id}/concluir")->assertForbidden();
        $this->actingAs($semNada)->postJson("/compromissos/{$compromisso->id}/promover-os")->assertForbidden();

        $this->assertSame(
            1,
            $this->contarCompromissos(),
            'nenhuma escrita barrada por permissão pode ter criado ou apagado compromisso'
        );
        $this->assertSame('agendado', $compromisso->fresh()->situacao);
        $this->assertNull($compromisso->fresh()->work_order_id);
    }

    // -----------------------------------------------------------------
    // Isolamento: id pelo corpo é 422, model pela rota é 404
    // -----------------------------------------------------------------

    /**
     * Regressão do mesmo defeito corrigido no commit `d9a3a9c` para o EPI:
     * `exists:` cru aceitaria id de outra empresa. Aqui a regra é composta com
     * `company_id = TenantAtual::exigirId()` (skill `permissoes-e-multitenancy`).
     */
    public function test_ids_de_outra_empresa_no_corpo_sao_recusados_na_validacao(): void
    {
        $clienteDaOutra = $this->clienteDaOutraEmpresa();
        $enderecoDaOutra = $this->enderecoDaOutraEmpresa($clienteDaOutra);
        $tecnicoDaOutra = $this->tecnicoDaOutraEmpresa();
        $osDaOutra = $this->workOrderDaOutraEmpresa();

        $this->actingAs($this->administrador)
            ->postJson('/compromissos', array_merge($this->dadosValidos(), ['client_id' => $clienteDaOutra->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_id');

        $this->actingAs($this->administrador)
            ->postJson('/compromissos', array_merge($this->dadosValidos(), ['address_id' => $enderecoDaOutra->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('address_id');

        $this->actingAs($this->administrador)
            ->postJson('/compromissos', array_merge($this->dadosValidos(), ['technician_id' => $tecnicoDaOutra->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('technician_id');

        $this->actingAs($this->administrador)
            ->postJson('/compromissos', array_merge($this->dadosValidos(), ['work_order_id' => $osDaOutra->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('work_order_id');

        $this->assertSame(0, $this->contarCompromissos(), 'nenhuma das quatro tentativas recusadas pode ter gravado');

        // A mesma regra vale na edição: as regras são as mesmas do formulário
        // de criação (CompromissoRequest::regrasDoFormulario()).
        $compromisso = $this->criarCompromisso();

        $this->actingAs($this->administrador)
            ->putJson(
                "/compromissos/{$compromisso->id}",
                array_merge($this->dadosValidos(), ['technician_id' => $tecnicoDaOutra->id])
            )
            ->assertStatus(422)
            ->assertJsonValidationErrors('technician_id');

        $this->assertSame($this->tecnico->id, $compromisso->fresh()->technician_id);
    }

    public function test_compromisso_de_outra_empresa_pela_rota_responde_404(): void
    {
        $compromissoDaOutra = $this->compromissoDaOutraEmpresa();

        $this->actingAs($this->administrador)
            ->putJson("/compromissos/{$compromissoDaOutra->id}", $this->dadosValidos())
            ->assertNotFound();
        $this->actingAs($this->administrador)
            ->deleteJson("/compromissos/{$compromissoDaOutra->id}")
            ->assertNotFound();
        $this->actingAs($this->administrador)
            ->postJson("/compromissos/{$compromissoDaOutra->id}/cancelar")
            ->assertNotFound();
        $this->actingAs($this->administrador)
            ->postJson("/compromissos/{$compromissoDaOutra->id}/concluir")
            ->assertNotFound();
        $this->actingAs($this->administrador)
            ->postJson("/compromissos/{$compromissoDaOutra->id}/promover-os")
            ->assertNotFound();

        // Nada da outra empresa foi tocado.
        TenantAtual::comTenant($this->outraEmpresa()->getKey(), function () use ($compromissoDaOutra): void {
            $this->assertSame('agendado', $compromissoDaOutra->fresh()->situacao);
            $this->assertNull($compromissoDaOutra->fresh()->work_order_id);
        });
    }

    // -----------------------------------------------------------------
    // Exceção de domínio vira 422 em português, nunca 500
    // -----------------------------------------------------------------

    public function test_promover_compromisso_ja_promovido_devolve_422_em_portugues(): void
    {
        $compromisso = $this->criarCompromisso();

        $this->actingAs($this->administrador)->postJson("/compromissos/{$compromisso->id}/promover-os")->assertOk();

        $segundaPromocao = $this->actingAs($this->administrador)
            ->postJson("/compromissos/{$compromisso->id}/promover-os");

        $segundaPromocao->assertStatus(422);
        $this->assertStringContainsString('já foi promovido', (string) $segundaPromocao->json('mensagem'));
        $segundaPromocao->assertJsonValidationErrors('compromisso');

        $this->assertSame(
            1,
            $this->comTenant(fn () => WorkOrder::query()->where('origem', CompromissoService::ORIGEM_DA_OS)->count()),
            'a segunda tentativa de promoção não pode ter gerado uma segunda ordem de serviço'
        );
    }

    public function test_promover_compromisso_cancelado_devolve_422_em_portugues(): void
    {
        $compromisso = $this->criarCompromisso();

        $this->actingAs($this->administrador)->postJson("/compromissos/{$compromisso->id}/cancelar")->assertOk();

        $promocao = $this->actingAs($this->administrador)->postJson("/compromissos/{$compromisso->id}/promover-os");

        $promocao->assertStatus(422);
        $this->assertStringContainsString('está cancelado', (string) $promocao->json('mensagem'));
        $promocao->assertJsonValidationErrors('compromisso');

        $this->assertNull($compromisso->fresh()->work_order_id);
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
     * junto o resto do catálogo e apagaria a distinção que este teste existe
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

    private function criarTecnico(string $nome = 'Técnico do Compromisso'): Technician
    {
        return $this->comTenant(fn (): Technician => Technician::create([
            'name' => $nome,
            'email' => 'tecnico-compromisso-'.uniqid().'@dedetizadora.test',
            'phone' => '11999990000',
            'is_active' => true,
        ]));
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function criarCompromisso(array $atributos = []): Compromisso
    {
        return $this->comTenant(fn (): Compromisso => CompromissoFactory::new()->create(array_merge([
            'client_id' => $this->cliente->id,
            'address_id' => $this->endereco->id,
            'technician_id' => $this->tecnico->id,
            'data' => '2026-08-20',
            'situacao' => 'agendado',
            'criado_por' => $this->administrador->id,
        ], $atributos)));
    }

    private function contarCompromissos(): int
    {
        return $this->comTenant(fn (): int => Compromisso::query()->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function dadosValidos(): array
    {
        return [
            'tipo' => 'orcamento',
            'titulo' => 'Orçamento para nova instalação',
            'observacoes' => 'Cliente pediu retorno em uma semana.',
            'client_id' => $this->cliente->id,
            'address_id' => $this->endereco->id,
            'technician_id' => $this->tecnico->id,
            'data' => '2026-08-20',
            'hora_inicio' => '09:00',
            'hora_fim' => '10:00',
        ];
    }

    private function outraEmpresa(): Company
    {
        return $this->outraEmpresa ??= Company::create([
            'name' => 'Concorrente do Compromisso',
            'cnpj' => '66.666.666/0001-66',
            'email' => 'contato@concorrente-compromisso.test',
        ]);
    }

    private function clienteDaOutraEmpresa(): Client
    {
        return TenantAtual::comTenant(
            $this->outraEmpresa()->getKey(),
            fn (): Client => ClientFactory::new()->create(['name' => 'Cliente da concorrente'])
        );
    }

    private function enderecoDaOutraEmpresa(Client $cliente): Address
    {
        return TenantAtual::comTenant(
            $this->outraEmpresa()->getKey(),
            fn (): Address => AddressFactory::new()->create(['client_id' => $cliente->id])
        );
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

    private function workOrderDaOutraEmpresa(): WorkOrder
    {
        return TenantAtual::comTenant($this->outraEmpresa()->getKey(), function (): WorkOrder {
            $cliente = ClientFactory::new()->create();
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);

            return WorkOrder::create([
                'order_number' => 'OS-CONCORRENTE-'.uniqid(),
                'client_id' => $cliente->id,
                'address_id' => $endereco->id,
                'scheduled_date' => '2026-08-20',
                'status' => 'scheduled',
            ]);
        });
    }

    private function compromissoDaOutraEmpresa(): Compromisso
    {
        return TenantAtual::comTenant(
            $this->outraEmpresa()->getKey(),
            fn (): Compromisso => CompromissoFactory::new()->create(['titulo' => 'Compromisso da concorrente'])
        );
    }
}
