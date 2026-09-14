<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\User;
use App\Services\ContractService;
use Database\Factories\AddressFactory;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regressão do erro de produção de 14/09/2026: criar contrato quebrava com
 * "Column 'address_id' in where clause is ambiguous".
 *
 * O Plano 23 trocou `Address::contract()` para `latestOfMany()`, que junta
 * `contracts` com uma subconsulta que também expõe `address_id`.
 * `ContractService::criar()` chamava `updateOrCreate(['address_id' => ...])`
 * nessa relação, e o `where` sem prefixo de tabela ficava ambíguo. A consulta
 * roda antes do insert, então nenhum contrato novo podia ser criado.
 */
class ContractCriacaoTest extends TestCase
{
    use RefreshDatabase;

    private ContractService $contractService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractService = app(ContractService::class);
    }

    public function test_criar_contrato_para_endereco_sem_contrato(): void
    {
        $endereco = AddressFactory::new()->create();

        $contrato = $this->contractService->criar($endereco, $this->dadosDoFormulario());

        $this->assertTrue($contrato->wasRecentlyCreated);
        $this->assertSame($endereco->id, $contrato->address_id);
        $this->assertSame(1, Contract::query()->where('address_id', $endereco->id)->count());
    }

    public function test_reenviar_o_formulario_atualiza_o_contrato_em_vez_de_duplicar(): void
    {
        $endereco = AddressFactory::new()->create();

        $primeiro = $this->contractService->criar($endereco, $this->dadosDoFormulario());
        $segundo = $this->contractService->criar($endereco, $this->dadosDoFormulario(['visit_count' => 6]));

        $this->assertSame($primeiro->id, $segundo->id);
        $this->assertSame(1, Contract::query()->where('address_id', $endereco->id)->count());
        $this->assertSame(6, (int) $segundo->fresh()->visit_count);
    }

    /**
     * Endereço renovado tem mais de uma linha em `contracts`. `criar()` mexe
     * só no contrato mais recente e deixa o histórico intacto.
     */
    public function test_endereco_com_historico_atualiza_so_o_contrato_mais_recente(): void
    {
        $endereco = AddressFactory::new()->create();

        $antigo = $this->contractService->criarNovo($endereco, $this->dadosResolvidos(['contract_number' => 'CONT-ANTIGO']));
        $vigente = $this->contractService->criarNovo($endereco, $this->dadosResolvidos(['contract_number' => 'CONT-VIGENTE']));

        $resultado = $this->contractService->criar($endereco, $this->dadosDoFormulario(['contract_number' => 'CONT-EDITADO']));

        $this->assertSame($vigente->id, $resultado->id);
        $this->assertSame('CONT-EDITADO', $vigente->fresh()->contract_number);
        $this->assertSame('CONT-ANTIGO', $antigo->fresh()->contract_number, 'o contrato do histórico foi sobrescrito');
    }

    public function test_rota_de_criar_contrato_pelo_endereco_cria_e_redireciona(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ModulesSeeder::class);

        $endereco = AddressFactory::new()->create();
        $usuario = $this->criarUsuarioComPapel('comercial');

        $resposta = $this->actingAs($usuario)->post("/addresses/{$endereco->id}/contracts", $this->dadosDoFormulario());

        $resposta->assertRedirect(route('contracts.index'));
        $resposta->assertSessionHas('success');
        $this->assertSame(1, Contract::query()->where('address_id', $endereco->id)->count());
    }

    public function test_rota_de_criar_contrato_com_endereco_no_corpo_cria_e_redireciona(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ModulesSeeder::class);

        $endereco = AddressFactory::new()->create();
        $usuario = $this->criarUsuarioComPapel('comercial');

        $resposta = $this->actingAs($usuario)->post('/contracts', $this->dadosDoFormulario([
            'address_id' => $endereco->id,
        ]));

        $resposta->assertRedirect(route('contracts.index'));
        $resposta->assertSessionHas('success');
        $this->assertSame(1, Contract::query()->where('address_id', $endereco->id)->count());
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Campos como chegam do formulário de contrato (`ContractRequest`).
     *
     * @param  array<string, mixed>  $atributos
     * @return array<string, mixed>
     */
    private function dadosDoFormulario(array $atributos = []): array
    {
        return array_merge([
            'start_date' => '2026-09-14',
            'end_date' => '2027-09-14',
            'service_type' => 'periodico',
            'visit_frequency' => 'monthly',
            'visit_count' => 12,
        ], $atributos);
    }

    /**
     * Campos já resolvidos, no formato que `criarNovo()` espera.
     *
     * @param  array<string, mixed>  $atributos
     * @return array<string, mixed>
     */
    private function dadosResolvidos(array $atributos = []): array
    {
        return array_merge([
            'start_date' => '2026-09-14',
            'end_date' => '2027-09-14',
            'service_type' => 'periodico',
            'visit_frequency' => 'monthly',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'meses',
            'visit_count' => 12,
        ], $atributos);
    }

    private function criarUsuarioComPapel(string $papel): User
    {
        // Mesmo motivo de ContractEncerramentoTest: `company_id` explícito
        // para `actingAs()` reaproveitar um objeto com o tenant preenchido.
        $usuario = User::factory()->create(['company_id' => 1]);
        $usuario->assignRole($papel);

        return $usuario;
    }
}
