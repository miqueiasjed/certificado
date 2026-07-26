<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\BaitType;
use App\Models\Client;
use App\Models\Company;
use App\Models\Device;
use App\Models\DeviceEvent;
use App\Models\DeviceReplacement;
use App\Models\EventType;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderDeviceEvent;
use App\Services\DeviceReplacementService;
use App\Support\TenantAtual;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 11.9 do Plano 11: substituição de um dispositivo por outro no mesmo
 * ponto de monitoramento.
 *
 * As duas garantias que este arquivo prende, ambas descritas no cabeçalho de
 * `DeviceReplacementService`:
 *
 * - Nenhum evento (`WorkOrderDeviceEvent` nem `DeviceEvent`) muda de dono na
 *   troca. Mover um evento para o dispositivo novo produziria um documento
 *   afirmando que ele registrou uma ocorrência antes de existir.
 * - `historicoDoPonto()` devolve a mesma cadeia completa não importa de qual
 *   elo ela é lida.
 *
 * O cenário segue o padrão de `VazamentoEntreEmpresasTest`: duas empresas, a
 * primeira nascida da fundação do tenant e a segunda criada à mão, com todo
 * dado de apoio criado fora de requisição HTTP dentro de
 * `TenantAtual::comTenant()`.
 */
class DeviceReplacementTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresaUm;

    private Company $empresaDois;

    private User $administrador;

    private Client $cliente;

    private Address $endereco;

    private BaitType $tipoIsca;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->empresaUm = Company::query()->firstOrFail();
        $this->empresaDois = Company::create([
            'name' => 'Dedetizadora Concorrente',
            'cnpj' => '22.222.222/0001-22',
            'email' => 'contato@concorrente.test',
        ]);

        $this->administrador = TenantAtual::comTenant(
            $this->empresaUm->id,
            fn () => User::factory()->create(['name' => 'Administrador', 'is_active' => true])
        );
        $this->administrador->assignRole('administrador');

        [$this->cliente, $this->endereco, $this->tipoIsca] = TenantAtual::comTenant(
            $this->empresaUm->id,
            function (): array {
                $cliente = Client::create([
                    'name' => 'Cliente Um',
                    'email' => 'cliente-um@exemplo.test',
                    'phone' => '11912340000',
                    'cnpj' => '33.333.333/0001-01',
                ]);

                $endereco = Address::create([
                    'client_id' => $cliente->id,
                    'nickname' => 'Unidade Principal',
                    'street' => 'Rua Um',
                    'number' => '100',
                    'district' => 'Bairro Um',
                    'city' => 'Cidade Um',
                    'state' => 'SP',
                    'zip' => '01000-000',
                    'active' => true,
                ]);

                $tipoIsca = BaitType::create(['name' => 'Isca Padrão', 'brand' => 'Marca X']);

                return [$cliente, $endereco, $tipoIsca];
            }
        );

        TenantAtual::limpar();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    public function test_substituir_cria_o_novo_marca_o_anterior_e_grava_a_substituicao(): void
    {
        $anterior = $this->criarDispositivo('001');

        $resposta = $this->actingAs($this->administrador)->postJson("/devices/{$anterior->id}/substituir", [
            'motivo' => 'danificado',
            'substituido_em' => now()->subDay()->toDateString(),
        ]);

        $resposta->assertOk();
        $resposta->assertJsonPath('success', true);

        $idNovo = $resposta->json('dispositivo_novo.id');
        $this->assertNotSame($anterior->id, $idNovo, 'a substituição não criou um dispositivo novo');

        $novo = TenantAtual::comTenant($this->empresaUm->id, fn () => Device::query()->findOrFail($idNovo));
        $this->assertSame('ativo', $novo->situacao);
        $this->assertTrue((bool) $novo->active);

        $anterior->refresh();
        $this->assertSame('substituido', $anterior->situacao, 'o dispositivo anterior não ficou com situacao=substituido');
        $this->assertFalse((bool) $anterior->active, 'o dispositivo anterior continuou ativo depois da troca');

        $substituicaoGravada = TenantAtual::comTenant(
            $this->empresaUm->id,
            fn () => DeviceReplacement::query()
                ->where('device_anterior_id', $anterior->id)
                ->where('device_novo_id', $novo->id)
                ->first()
        );

        $this->assertNotNull($substituicaoGravada, 'nenhuma linha de device_replacements foi gravada para esta troca');
        $this->assertSame('danificado', $substituicaoGravada->motivo);
    }

    /**
     * A garantia mais importante deste arquivo: nenhum evento muda de dono.
     * Compara o `device_id` de cada evento antes e depois da troca, e não
     * apenas a ausência de exceção.
     */
    public function test_eventos_do_anterior_continuam_ligados_a_ele_depois_da_troca(): void
    {
        $anterior = $this->criarDispositivo('002');

        [$eventoDaOrdem, $eventoDoDispositivo] = TenantAtual::comTenant(
            $this->empresaUm->id,
            function () use ($anterior): array {
                $ordem = WorkOrder::create([
                    'order_number' => 'OT-REPL-0002',
                    'client_id' => $this->cliente->id,
                    'address_id' => $this->endereco->id,
                    'scheduled_date' => now()->toDateString(),
                    'status' => 'pending',
                ]);

                $tipoEvento = EventType::create([
                    'name' => 'Vistoria',
                    'description' => 'Vistoria de rotina',
                ]);

                $eventoDaOrdem = WorkOrderDeviceEvent::create([
                    'work_order_id' => $ordem->id,
                    'device_id' => $anterior->id,
                    'event_type_id' => $tipoEvento->id,
                    'event_date' => now()->toDateString(),
                    'event_description' => 'Consumo parcial registrado antes da troca',
                ]);

                $eventoDoDispositivo = DeviceEvent::create([
                    'device_id' => $anterior->id,
                    'work_order_id' => $ordem->id,
                    'event_type' => 'bait_consumption',
                    'event_date' => now(),
                    'active' => true,
                ]);

                return [$eventoDaOrdem, $eventoDoDispositivo];
            }
        );

        $donoAntesDoEventoDaOrdem = $eventoDaOrdem->device_id;
        $donoAntesDoEventoDoDispositivo = $eventoDoDispositivo->device_id;

        $resposta = $this->actingAs($this->administrador)->postJson("/devices/{$anterior->id}/substituir", [
            'motivo' => 'perdido',
            'substituido_em' => now()->subDay()->toDateString(),
        ]);

        $resposta->assertOk();

        $eventoDaOrdem->refresh();
        $eventoDoDispositivo->refresh();

        $this->assertSame(
            $donoAntesDoEventoDaOrdem,
            $eventoDaOrdem->device_id,
            'o evento de work_order_device_events mudou de dono depois da substituição'
        );
        $this->assertSame($anterior->id, $eventoDaOrdem->device_id);

        $this->assertSame(
            $donoAntesDoEventoDoDispositivo,
            $eventoDoDispositivo->device_id,
            'o evento de device_events mudou de dono depois da substituição'
        );
        $this->assertSame($anterior->id, $eventoDoDispositivo->device_id);
    }

    /**
     * Cadeia A -> B -> C, com `historicoDoPonto()` chamado a partir de cada um
     * dos três elos: as três chamadas precisam devolver exatamente [A, B, C].
     */
    public function test_historico_do_ponto_devolve_a_cadeia_completa_a_partir_de_qualquer_elo(): void
    {
        $a = $this->criarDispositivo('A');

        $respostaAB = $this->actingAs($this->administrador)->postJson("/devices/{$a->id}/substituir", [
            'motivo' => 'desgaste',
            'substituido_em' => now()->subDays(2)->toDateString(),
        ]);
        $respostaAB->assertOk();
        $bId = $respostaAB->json('dispositivo_novo.id');

        $respostaBC = $this->actingAs($this->administrador)->postJson("/devices/{$bId}/substituir", [
            'motivo' => 'desgaste',
            'substituido_em' => now()->subDay()->toDateString(),
        ]);
        $respostaBC->assertOk();
        $cId = $respostaBC->json('dispositivo_novo.id');

        $servico = app(DeviceReplacementService::class);

        $a->refresh();
        $b = TenantAtual::comTenant($this->empresaUm->id, fn () => Device::query()->findOrFail($bId));
        $c = TenantAtual::comTenant($this->empresaUm->id, fn () => Device::query()->findOrFail($cId));

        foreach (['A' => $a, 'B' => $b, 'C' => $c] as $rotulo => $elo) {
            $cadeia = TenantAtual::comTenant(
                $this->empresaUm->id,
                fn () => $servico->historicoDoPonto($elo)->pluck('id')->all()
            );

            $this->assertSame(
                [$a->id, $bId, $cId],
                $cadeia,
                "historicoDoPonto() a partir do elo {$rotulo} (dispositivo {$elo->id}) não devolveu a cadeia completa"
            );
        }
    }

    public function test_substituir_um_ja_substituido_devolve_422(): void
    {
        $anterior = $this->criarDispositivo('003');

        $this->actingAs($this->administrador)->postJson("/devices/{$anterior->id}/substituir", [
            'motivo' => 'danificado',
            'substituido_em' => now()->subDay()->toDateString(),
        ])->assertOk();

        $segunda = $this->actingAs($this->administrador)->postJson("/devices/{$anterior->id}/substituir", [
            'motivo' => 'perdido',
            'substituido_em' => now()->subDay()->toDateString(),
        ]);

        $segunda->assertStatus(422);
        $segunda->assertJsonPath('success', false);
        $segunda->assertJsonPath('message', DeviceReplacementService::MENSAGEM_JA_SUBSTITUIDO);
    }

    public function test_data_futura_devolve_422(): void
    {
        $anterior = $this->criarDispositivo('004');

        $resposta = $this->actingAs($this->administrador)->postJson("/devices/{$anterior->id}/substituir", [
            'motivo' => 'danificado',
            'substituido_em' => now()->addDays(2)->toDateString(),
        ]);

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('substituido_em');
    }

    public function test_sem_permissao_devolve_403(): void
    {
        $anterior = $this->criarDispositivo('005');

        // Papel `leitura`: tem dispositivo-ver (termina em "-ver"), mas não
        // dispositivo-editar. `tecnico` não serve para este teste porque ele
        // tem dispositivo-editar (ver RolesAndPermissionsSeeder::permissoesTecnico).
        $usuarioLeitura = TenantAtual::comTenant(
            $this->empresaUm->id,
            fn () => User::factory()->create(['name' => 'Usuário Leitura', 'is_active' => true])
        );
        $usuarioLeitura->assignRole('leitura');

        $this->assertFalse(
            $usuarioLeitura->hasPermissionTo('dispositivo-editar'),
            'o papel leitura ganhou dispositivo-editar: escolha outro papel para este teste de 403'
        );

        $resposta = $this->actingAs($usuarioLeitura)->postJson("/devices/{$anterior->id}/substituir", [
            'motivo' => 'danificado',
            'substituido_em' => now()->subDay()->toDateString(),
        ]);

        $resposta->assertStatus(403);
    }

    private function criarDispositivo(string $numero): Device
    {
        return TenantAtual::comTenant($this->empresaUm->id, fn () => Device::create([
            'address_id' => $this->endereco->id,
            'bait_type_id' => $this->tipoIsca->id,
            'label' => 'Dispositivo '.$numero,
            'number' => $numero,
            'active' => true,
        ]));
    }
}
