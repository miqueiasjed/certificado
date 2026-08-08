<?php

namespace Tests\Feature;

use App\Console\Commands\SyncPermissions;
use App\Models\Company;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\Technician;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Closure;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Task 27.4 do Plano 27: endpoints de frota e integração com estoque e
 * financeiro.
 *
 * Cobre os oito critérios de aceitação da task: (a) CRUD de veículo com a
 * ficha trazendo consumo, custo e próximas manutenções, (b) criar veículo cria
 * o local de estoque quando o módulo está ativo, (c) abastecimento e manutenção
 * podem gerar o título a pagar vinculado, (d) quilometragem menor que a
 * anterior é recusada com a última registrada na mensagem, (e) documentos são
 * cadastrados com arquivo e validade, (f) o veículo da OS vem do técnico e é
 * alterável, (g) usuário sem `frota-gerenciar` não registra, (h) tenant sem o
 * módulo vê a página de indisponível.
 */
class FleetEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-07-01';

    private Company $empresa;

    private User $administrador;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::HOJE.' 12:00:00');
        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ModulesSeeder::class);

        $this->empresa = Company::query()->firstOrFail();

        $this->administrador = $this->comTenant(function (): User {
            $admin = User::factory()->create(['is_active' => true]);
            $admin->assignRole('administrador');

            return $admin;
        });
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_catalogo_tem_as_duas_permissoes_novas(): void
    {
        $catalogo = collect(SyncPermissions::catalogo())->flatten()->all();

        $this->assertContains('frota-ver', $catalogo);
        $this->assertContains('frota-gerenciar', $catalogo);
    }

    // -----------------------------------------------------------------
    // (a) CRUD de veículo e ficha
    // -----------------------------------------------------------------

    public function test_cadastra_veiculo_e_a_ficha_traz_consumo_custo_e_proximas_manutencoes(): void
    {
        $resposta = $this->actingAs($this->administrador)->postJson('/veiculos', [
            'placa' => 'ABC1D23',
            'modelo' => 'Saveiro',
            'marca' => 'Volkswagen',
            'ano' => 2022,
            'tipo' => 'utilitario',
            'custo_km_padrao' => 0.9,
        ]);

        $resposta->assertCreated();
        $veiculoId = $resposta->json('veiculo.id');

        $this->assertDatabaseHas('vehicles', [
            'id' => $veiculoId,
            'placa' => 'ABC1D23',
            'company_id' => $this->empresa->id,
        ]);

        // Quatro tanques cheios: 3 intervalos, o mínimo para o consumo ser
        // apurado em vez de cair no custo padrão.
        foreach ([[1000, '2026-03-01'], [1400, '2026-03-15'], [1800, '2026-04-01'], [2200, '2026-04-15']] as [$km, $data]) {
            $this->actingAs($this->administrador)->postJson("/veiculos/{$veiculoId}/abastecimentos", [
                'data' => $data,
                'km' => $km,
                'litros' => 40,
                'valor_total' => 200.00,
                'tipo_combustivel' => 'gasolina',
                'tanque_cheio' => true,
            ])->assertCreated();
        }

        $this->actingAs($this->administrador)->postJson("/veiculos/{$veiculoId}/manutencoes", [
            'tipo' => 'preventiva',
            'descricao' => 'Troca de óleo',
            'proxima_em_data' => '2026-09-01',
            'proxima_em_km' => 12000,
            'situacao' => 'agendada',
        ])->assertCreated();

        $ficha = $this->actingAs($this->administrador)->getJson("/veiculos/{$veiculoId}");

        $ficha->assertOk();
        $ficha->assertJsonPath('consumo.intervalos', 3);
        // 10.0 volta como 10 no JSON: PHP serializa float sem parte decimal
        // como inteiro, e o assert compara tipo também.
        $ficha->assertJsonPath('consumo.km_por_litro', 10);
        $ficha->assertJsonPath('custo_por_km.origem', 'medido');
        $ficha->assertJsonPath('custo_por_km.total_por_km', '0.5000');
        $this->assertCount(1, $ficha->json('proximas_manutencoes'));

        // O hodômetro acompanhou o último abastecimento.
        $this->assertSame(2200, (int) Vehicle::query()->findOrFail($veiculoId)->km_atual);
    }

    public function test_placa_repetida_na_mesma_empresa_e_recusada(): void
    {
        $this->criarVeiculo(['placa' => 'XYZ9A88']);

        $this->actingAs($this->administrador)
            ->postJson('/veiculos', [
                'placa' => 'XYZ9A88',
                'modelo' => 'Strada',
                'marca' => 'Fiat',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('placa');
    }

    public function test_veiculo_com_historico_nao_pode_ser_excluido(): void
    {
        $veiculo = $this->criarVeiculo();

        $this->actingAs($this->administrador)->postJson("/veiculos/{$veiculo->id}/abastecimentos", [
            'data' => self::HOJE,
            'km' => 5000,
            'litros' => 40,
            'valor_total' => 200.00,
            'tipo_combustivel' => 'gasolina',
        ])->assertCreated();

        $this->actingAs($this->administrador)
            ->deleteJson("/veiculos/{$veiculo->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('vehicles', ['id' => $veiculo->id]);
    }

    // -----------------------------------------------------------------
    // (b) Local de estoque criado junto com o veículo
    // -----------------------------------------------------------------

    public function test_criar_veiculo_cria_o_local_de_estoque_do_tipo_veiculo(): void
    {
        $resposta = $this->actingAs($this->administrador)->postJson('/veiculos', [
            'placa' => 'EST0Q01',
            'modelo' => 'Kangoo',
            'marca' => 'Renault',
        ]);

        $resposta->assertCreated();

        $veiculo = Vehicle::query()->findOrFail($resposta->json('veiculo.id'));

        $this->assertNotNull($veiculo->stock_location_id, 'o local de estoque precisava nascer junto com o veículo');

        $local = $this->comTenant(fn (): StockLocation => StockLocation::query()->findOrFail($veiculo->stock_location_id));

        $this->assertSame('veiculo', $local->tipo);
        $this->assertSame('Veículo EST0Q01', $local->nome);
        $this->assertNull($local->technician_id, 'local de veículo não é van de técnico');
    }

    // -----------------------------------------------------------------
    // (c) Título a pagar: oferecido, não automático
    // -----------------------------------------------------------------

    public function test_abastecimento_sem_pedir_titulo_nao_cria_titulo_e_devolve_a_oferta(): void
    {
        $veiculo = $this->criarVeiculo();

        $resposta = $this->actingAs($this->administrador)->postJson("/veiculos/{$veiculo->id}/abastecimentos", [
            'data' => self::HOJE,
            'km' => 5000,
            'litros' => 40,
            'valor_total' => 320.00,
            'tipo_combustivel' => 'gasolina',
            'posto' => 'Posto da Esquina',
        ]);

        $resposta->assertCreated();
        $resposta->assertJsonPath('titulo', null);
        $resposta->assertJsonPath('oferta_de_titulo.disponivel', true);
        $resposta->assertJsonPath('oferta_de_titulo.valor', '320.00');

        $this->assertSame(0, $this->comTenant(fn (): int => \App\Models\Payable::query()->count()));
    }

    public function test_abastecimento_gera_o_titulo_a_pagar_vinculado_quando_pedido(): void
    {
        $veiculo = $this->criarVeiculo();
        $fornecedor = $this->criarFornecedor();

        $resposta = $this->actingAs($this->administrador)->postJson("/veiculos/{$veiculo->id}/abastecimentos", [
            'data' => self::HOJE,
            'km' => 5000,
            'litros' => 40,
            'valor_total' => 320.00,
            'tipo_combustivel' => 'gasolina',
            'gerar_titulo' => true,
            'supplier_id' => $fornecedor->id,
        ]);

        $resposta->assertCreated();

        $tituloId = $resposta->json('titulo.id');
        $this->assertNotNull($tituloId);

        $this->assertDatabaseHas('payables', [
            'id' => $tituloId,
            'supplier_id' => $fornecedor->id,
            'valor_total' => '320.00',
        ]);

        $this->assertDatabaseHas('refuelings', [
            'id' => $resposta->json('abastecimento.id'),
            'payable_id' => $tituloId,
        ]);
    }

    public function test_manutencao_gera_o_titulo_a_pagar_vinculado_quando_pedido(): void
    {
        $veiculo = $this->criarVeiculo();
        $fornecedor = $this->criarFornecedor();

        $resposta = $this->actingAs($this->administrador)->postJson("/veiculos/{$veiculo->id}/manutencoes", [
            'tipo' => 'corretiva',
            'descricao' => 'Troca de embreagem',
            'data' => self::HOJE,
            'valor' => 1800.00,
            'oficina' => 'Oficina do Zé',
            'situacao' => 'realizada',
            'gerar_titulo' => true,
            'supplier_id' => $fornecedor->id,
        ]);

        $resposta->assertCreated();

        $tituloId = $resposta->json('titulo.id');
        $this->assertNotNull($tituloId);

        $this->assertDatabaseHas('vehicle_maintenances', [
            'id' => $resposta->json('manutencao.id'),
            'payable_id' => $tituloId,
        ]);
    }

    public function test_titulo_pode_ser_gerado_depois_para_quem_recusou_a_oferta(): void
    {
        $veiculo = $this->criarVeiculo();
        $fornecedor = $this->criarFornecedor();

        $abastecimento = $this->actingAs($this->administrador)
            ->postJson("/veiculos/{$veiculo->id}/abastecimentos", [
                'data' => self::HOJE,
                'km' => 5000,
                'litros' => 40,
                'valor_total' => 320.00,
                'tipo_combustivel' => 'gasolina',
            ])
            ->assertCreated()
            ->json('abastecimento.id');

        $this->actingAs($this->administrador)
            ->postJson("/veiculos/{$veiculo->id}/abastecimentos/{$abastecimento}/titulo", [
                'supplier_id' => $fornecedor->id,
            ])
            ->assertCreated();

        // Segunda tentativa é recusada: dois títulos para a mesma nota é
        // despesa dobrada no fluxo de caixa.
        $this->actingAs($this->administrador)
            ->postJson("/veiculos/{$veiculo->id}/abastecimentos/{$abastecimento}/titulo", [
                'supplier_id' => $fornecedor->id,
            ])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // (d) Quilometragem retroativa
    // -----------------------------------------------------------------

    public function test_quilometragem_menor_que_a_anterior_e_recusada_com_a_ultima_na_mensagem(): void
    {
        $veiculo = $this->criarVeiculo();

        $this->actingAs($this->administrador)->postJson("/veiculos/{$veiculo->id}/abastecimentos", [
            'data' => '2026-06-01',
            'km' => 98500,
            'litros' => 40,
            'valor_total' => 320.00,
            'tipo_combustivel' => 'gasolina',
        ])->assertCreated();

        $resposta = $this->actingAs($this->administrador)->postJson("/veiculos/{$veiculo->id}/abastecimentos", [
            'data' => '2026-06-20',
            'km' => 9850,
            'litros' => 40,
            'valor_total' => 320.00,
            'tipo_combustivel' => 'gasolina',
        ]);

        $resposta->assertStatus(422);
        $this->assertStringContainsString('98.500 km', $resposta->json('mensagem'));
        $this->assertStringContainsString('9.850 km', $resposta->json('mensagem'));

        // Nada foi gravado, e o hodômetro não andou para trás.
        $this->assertSame(1, $this->comTenant(fn (): int => $veiculo->refuelings()->count()));
        $this->assertSame(98500, (int) $veiculo->fresh()->km_atual);
    }

    public function test_manutencao_com_quilometragem_retroativa_tambem_e_recusada(): void
    {
        $veiculo = $this->criarVeiculo(['km_atual' => 50000]);

        $this->actingAs($this->administrador)
            ->postJson("/veiculos/{$veiculo->id}/manutencoes", [
                'tipo' => 'preventiva',
                'descricao' => 'Revisão',
                'data' => self::HOJE,
                'km' => 4000,
                'situacao' => 'realizada',
            ])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // (e) Documentos com arquivo e validade
    // -----------------------------------------------------------------

    public function test_documento_e_cadastrado_com_arquivo_e_validade(): void
    {
        Storage::fake('public');

        $veiculo = $this->criarVeiculo();

        $resposta = $this->actingAs($this->administrador)->post("/veiculos/{$veiculo->id}/documentos", [
            'tipo' => 'licenciamento',
            'numero' => 'CRLV-2026',
            'validade' => '2026-12-31',
            'arquivo' => UploadedFile::fake()->create('crlv.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $resposta->assertCreated();

        $caminho = $resposta->json('documento.arquivo_path');
        $this->assertNotNull($caminho);
        Storage::disk('public')->assertExists($caminho);

        $this->assertDatabaseHas('vehicle_documents', [
            'vehicle_id' => $veiculo->id,
            'tipo' => 'licenciamento',
            'numero' => 'CRLV-2026',
        ]);
    }

    public function test_documento_sem_validade_e_recusado(): void
    {
        $veiculo = $this->criarVeiculo();

        $this->actingAs($this->administrador)
            ->postJson("/veiculos/{$veiculo->id}/documentos", [
                'tipo' => 'seguro',
                'numero' => 'AP-1',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('validade');
    }

    // -----------------------------------------------------------------
    // (f) Veículo da OS vem do técnico e é alterável
    // -----------------------------------------------------------------

    public function test_os_nasce_com_o_veiculo_do_tecnico_designado(): void
    {
        $tecnico = $this->criarTecnico();
        $veiculo = $this->criarVeiculo(['technician_id' => $tecnico->id]);

        $os = $this->comTenant(fn (): WorkOrder => app(WorkOrderService::class)->createWorkOrder(
            $this->dadosDaOs($tecnico)
        ));

        $this->assertSame((int) $veiculo->id, (int) $os->vehicle_id);
    }

    public function test_veiculo_informado_na_os_vence_o_do_tecnico(): void
    {
        $tecnico = $this->criarTecnico();
        $this->criarVeiculo(['technician_id' => $tecnico->id]);
        $outro = $this->criarVeiculo(['placa' => 'OUT9Z99']);

        $os = $this->comTenant(fn (): WorkOrder => app(WorkOrderService::class)->createWorkOrder(
            $this->dadosDaOs($tecnico) + ['vehicle_id' => $outro->id]
        ));

        $this->assertSame((int) $outro->id, (int) $os->vehicle_id);
    }

    public function test_tecnico_com_dois_veiculos_ativos_nao_tem_padrao(): void
    {
        $tecnico = $this->criarTecnico();
        $this->criarVeiculo(['technician_id' => $tecnico->id, 'placa' => 'AAA1111']);
        $this->criarVeiculo(['technician_id' => $tecnico->id, 'placa' => 'BBB2222']);

        $os = $this->comTenant(fn (): WorkOrder => app(WorkOrderService::class)->createWorkOrder(
            $this->dadosDaOs($tecnico)
        ));

        $this->assertNull($os->vehicle_id, 'escolher um entre dois seria adivinhar');
    }

    // -----------------------------------------------------------------
    // (g) Permissão
    // -----------------------------------------------------------------

    public function test_usuario_sem_frota_gerenciar_nao_registra_mas_continua_lendo(): void
    {
        $veiculo = $this->criarVeiculo();

        $leitor = $this->comTenant(function (): User {
            $usuario = User::factory()->create(['is_active' => true]);
            $usuario->assignRole('leitura');

            return $usuario;
        });

        $this->actingAs($leitor)->getJson('/veiculos')->assertOk();

        $this->actingAs($leitor)
            ->postJson("/veiculos/{$veiculo->id}/abastecimentos", [
                'data' => self::HOJE,
                'km' => 5000,
                'litros' => 40,
                'valor_total' => 200.00,
                'tipo_combustivel' => 'gasolina',
            ])
            ->assertStatus(403);

        $this->actingAs($leitor)
            ->postJson('/veiculos', ['placa' => 'NAO0000', 'modelo' => 'X', 'marca' => 'Y'])
            ->assertStatus(403);
    }

    // -----------------------------------------------------------------
    // (h) Módulo desligado
    // -----------------------------------------------------------------

    public function test_tenant_sem_o_modulo_frota_ve_pagina_de_indisponivel(): void
    {
        $empresaSemModulo = Company::create([
            'name' => 'Empresa Sem Frota',
            'cnpj' => '66.666.666/0001-66',
            'email' => 'contato@sem-frota.test',
        ]);

        $admin = TenantAtual::comTenant($empresaSemModulo->id, function (): User {
            $usuario = User::factory()->create(['is_active' => true]);
            $usuario->assignRole('administrador');

            return $usuario;
        });

        $this->actingAs($admin)
            ->get('/veiculos')
            ->assertRedirect(route('modulo-indisponivel', ['modulo' => 'frota']));

        $this->actingAs($admin)
            ->getJson('/veiculos')
            ->assertStatus(403)
            ->assertJsonPath('modulo', 'frota');
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas
    // -----------------------------------------------------------------

    public function test_veiculo_de_outra_empresa_responde_404(): void
    {
        $outra = Company::create([
            'name' => 'Concorrente',
            'cnpj' => '55.555.555/0001-55',
            'email' => 'contato@concorrente.test',
        ]);

        $veiculoDaOutra = TenantAtual::comTenant($outra->id, fn (): Vehicle => Vehicle::create([
            'placa' => 'CON1234',
            'modelo' => 'Uno',
            'marca' => 'Fiat',
            'situacao' => 'ativo',
        ]));

        $this->actingAs($this->administrador)
            ->getJson("/veiculos/{$veiculoDaOutra->id}")
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function comTenant(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->id, $callback);
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function criarVeiculo(array $atributos = []): Vehicle
    {
        return $this->comTenant(fn (): Vehicle => Vehicle::create(array_merge([
            'placa' => 'PLA'.random_int(1000, 9999),
            'modelo' => 'Saveiro',
            'marca' => 'Volkswagen',
            'tipo' => 'utilitario',
            'km_atual' => 0,
            'situacao' => 'ativo',
        ], $atributos)));
    }

    private function criarFornecedor(): Supplier
    {
        return $this->comTenant(fn (): Supplier => Supplier::create([
            'nome' => 'Posto Central',
            'ativo' => true,
        ]));
    }

    private function criarTecnico(): Technician
    {
        return $this->comTenant(fn (): Technician => Technician::create([
            'name' => 'Técnico de teste',
            'email' => 'tecnico'.uniqid().'@dedetizadora.test',
            'phone' => '11999990000',
            'is_active' => true,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function dadosDaOs(Technician $tecnico): array
    {
        $cliente = ClientFactory::new()->create();
        // `work_orders.address_id` é NOT NULL: endereço pertence a cliente, e
        // a OS precisa do endereço em que o serviço acontece.
        $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);

        return [
            'client_id' => $cliente->id,
            'address_id' => $endereco->id,
            'technician_id' => $tecnico->id,
            'order_number' => 'OS-'.random_int(100000, 999999),
            'priority_level' => 'medium',
            'scheduled_date' => BusinessDate::hoje()->toDateString(),
            'status' => 'scheduled',
            'total_cost' => 500.00,
            'final_amount' => 500.00,
            'active' => true,
        ];
    }
}
