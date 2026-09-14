<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\User;
use App\Services\ContractService;
use App\Support\TenantAtual;
use Database\Factories\AddressFactory;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
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
    // Frequência trimestral
    // -----------------------------------------------------------------

    /**
     * `quarterly` precisa virar 3 meses nas colunas que o calendário de
     * visitas lê. Sem o mapeamento, o contrato seria salvo mas cairia no
     * painel de pendências como periodicidade não reconhecida.
     */
    public function test_rota_de_criar_contrato_aceita_frequencia_trimestral_e_grava_tres_meses(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ModulesSeeder::class);

        $endereco = AddressFactory::new()->create();
        $usuario = $this->criarUsuarioComPapel('comercial');

        $resposta = $this->actingAs($usuario)->post("/addresses/{$endereco->id}/contracts", $this->dadosDoFormulario([
            'visit_frequency' => 'quarterly',
            'visit_count' => 4,
        ]));

        $resposta->assertRedirect(route('contracts.index'));
        $resposta->assertSessionHasNoErrors();

        $contrato = Contract::query()->where('address_id', $endereco->id)->firstOrFail();
        $this->assertSame('quarterly', $contrato->visit_frequency);
        $this->assertSame(3, (int) $contrato->visit_frequency_valor);
        $this->assertSame('meses', $contrato->visit_frequency_unidade);
    }

    public function test_editar_contrato_mensal_para_trimestral_atualiza_a_periodicidade(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ModulesSeeder::class);

        $endereco = AddressFactory::new()->create();
        $contrato = $this->contractService->criar($endereco, $this->dadosDoFormulario());
        $usuario = $this->criarUsuarioComPapel('comercial');

        $resposta = $this->actingAs($usuario)->put("/contracts/{$contrato->id}", $this->dadosDoFormulario([
            'visit_frequency' => 'quarterly',
            'visit_count' => 4,
        ]));

        $resposta->assertRedirect();
        $resposta->assertSessionHasNoErrors();

        $atualizado = $contrato->fresh();
        $this->assertSame('quarterly', $atualizado->visit_frequency);
        $this->assertSame(3, (int) $atualizado->visit_frequency_valor);
        $this->assertSame('meses', $atualizado->visit_frequency_unidade);
    }

    /**
     * O mapa de rótulos do PDF cai em "mensais" quando não reconhece a
     * frequência. Contrato trimestral impresso como mensal é documento
     * divergente do sistema perante a fiscalização.
     */
    public function test_pdf_do_contrato_trimestral_descreve_visitas_trimestrais(): void
    {
        $html = TenantAtual::comTenant(1, function (): string {
            $endereco = AddressFactory::new()->create();
            $contrato = $this->contractService->criar($endereco, $this->dadosDoFormulario([
                'visit_frequency' => 'quarterly',
                'visit_count' => 4,
            ]));

            $dados = $this->contractService->preparePdfData($contrato);
            $dados['address'] = $endereco;
            $dados['client'] = $endereco->client;

            return View::make('pdf.contract', $dados)->render();
        });

        $this->assertStringContainsString('4 visitas trimestrais', $html);
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
