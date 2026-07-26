<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\BaitType;
use App\Models\Client;
use App\Models\Company;
use App\Models\Device;
use App\Models\User;
use App\Support\TenantAtual;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 11.9 do Plano 11: cadastro em lote dos dispositivos de um endereço.
 *
 * O que este arquivo prende, além do caminho feliz: o lote é tudo ou nada. A
 * recusa por colisão de número e a recusa por tipo de isca de outra empresa
 * não podem deixar nenhum dispositivo criado, e é por isso que os dois testes
 * de recusa comparam `Device::count()` antes e depois, e não apenas o status
 * da resposta (ver `DeviceBatchService`, cabeçalho da classe).
 *
 * O cenário segue o padrão de `VazamentoEntreEmpresasTest`: duas empresas, a
 * primeira nascida da fundação do tenant e a segunda criada à mão, com todo
 * dado de apoio criado fora de requisição HTTP dentro de
 * `TenantAtual::comTenant()`.
 */
class DeviceBatchTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresaUm;

    private Company $empresaDois;

    private User $administrador;

    private Address $endereco;

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

        $this->endereco = TenantAtual::comTenant($this->empresaUm->id, function (): Address {
            $cliente = Client::create([
                'name' => 'Cliente Um',
                'email' => 'cliente-um@exemplo.test',
                'phone' => '11912340000',
                'cnpj' => '33.333.333/0001-01',
            ]);

            return Address::create([
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
        });

        TenantAtual::limpar();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    public function test_lote_de_40_cria_40_com_numeracao_sequencial_e_codigos_distintos(): void
    {
        $resposta = $this->actingAs($this->administrador)->postJson("/addresses/{$this->endereco->id}/devices/lote", [
            'quantidade' => 40,
            'prefixo' => 'PCE',
            'numero_inicial' => 1,
        ]);

        $resposta->assertOk();
        $resposta->assertJsonPath('success', true);

        $dispositivos = $resposta->json('dispositivos');
        $this->assertCount(40, $dispositivos, 'o lote não devolveu os 40 dispositivos esperados');

        $numeros = collect($dispositivos)->pluck('number')->sort()->values()->all();
        $numerosEsperados = collect(range(1, 40))
            ->map(fn (int $numero): string => str_pad((string) $numero, 3, '0', STR_PAD_LEFT))
            ->sort()
            ->values()
            ->all();

        $this->assertSame($numerosEsperados, $numeros, 'a numeração sequencial do lote não bateu com o esperado (001 a 040)');

        $codigos = collect($dispositivos)->pluck('codigo_publico');
        $this->assertCount(40, $codigos->unique(), 'o lote gerou códigos públicos repetidos');

        $totalNoBanco = TenantAtual::comTenant($this->empresaUm->id, fn () => Device::query()->count());
        $this->assertSame(40, $totalNoBanco);
    }

    /**
     * O lote é tudo ou nada: a colisão com um único número existente não pode
     * deixar nenhum dos outros 9 criado.
     */
    public function test_colisao_de_numero_nao_cria_nada(): void
    {
        TenantAtual::comTenant($this->empresaUm->id, fn () => Device::create([
            'address_id' => $this->endereco->id,
            'label' => 'Dispositivo Existente',
            'number' => '005',
            'active' => true,
        ]));

        $contagemAntes = TenantAtual::comTenant($this->empresaUm->id, fn () => Device::query()->count());

        $resposta = $this->actingAs($this->administrador)->postJson("/addresses/{$this->endereco->id}/devices/lote", [
            'quantidade' => 10,
            'prefixo' => 'PCE',
            'numero_inicial' => 1,
        ]);

        $resposta->assertStatus(422);
        $resposta->assertJsonPath('success', false);
        $this->assertContains('005', $resposta->json('numeros_em_conflito'));

        $contagemDepois = TenantAtual::comTenant($this->empresaUm->id, fn () => Device::query()->count());

        $this->assertSame(
            $contagemAntes,
            $contagemDepois,
            'a recusa por colisão de número criou dispositivo(s) parcialmente: o lote deveria ser tudo ou nada'
        );
    }

    public function test_quantidade_201_devolve_422(): void
    {
        $resposta = $this->actingAs($this->administrador)->postJson("/addresses/{$this->endereco->id}/devices/lote", [
            'quantidade' => 201,
            'prefixo' => 'PCE',
            'numero_inicial' => 1,
        ]);

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('quantidade');
    }

    /**
     * Vazamento entre empresas: um `bait_type_id` de outra empresa não pode
     * nem criar dispositivo (nada é gravado) nem estourar 500 de violação de
     * chave estrangeira. A validação escopada por empresa em
     * `StoreDeviceBatchRequest` precisa recusar antes de o Service rodar.
     */
    public function test_tipo_de_isca_de_outra_empresa_devolve_422(): void
    {
        $tipoIscaDeOutraEmpresa = TenantAtual::comTenant(
            $this->empresaDois->id,
            fn () => BaitType::create(['name' => 'Isca da Concorrente', 'brand' => 'Marca Y'])
        );

        $contagemAntes = TenantAtual::comTenant($this->empresaUm->id, fn () => Device::query()->count());

        $resposta = $this->actingAs($this->administrador)->postJson("/addresses/{$this->endereco->id}/devices/lote", [
            'quantidade' => 5,
            'prefixo' => 'PCE',
            'numero_inicial' => 1,
            'bait_type_id' => $tipoIscaDeOutraEmpresa->id,
        ]);

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('bait_type_id');

        $contagemDepois = TenantAtual::comTenant($this->empresaUm->id, fn () => Device::query()->count());

        $this->assertSame(
            $contagemAntes,
            $contagemDepois,
            'a recusa por tipo de isca de outra empresa criou dispositivo(s)'
        );
    }
}
