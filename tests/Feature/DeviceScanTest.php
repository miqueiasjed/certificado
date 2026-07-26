<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\BaitType;
use App\Models\Client;
use App\Models\Company;
use App\Models\Device;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\DeviceReplacementService;
use App\Support\TenantAtual;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 11.9 do Plano 11: leitura do código de um dispositivo (QR code ou
 * etiqueta digitada).
 *
 * O ponto mais importante deste arquivo é
 * `test_codigo_de_outra_empresa_devolve_nao_encontrado_igual_ao_codigo_inexistente`:
 * a leitura de um código de OUTRA empresa precisa devolver, byte a byte, a
 * mesma resposta que a leitura de um código que não existe em lugar nenhum.
 * Diferenciar as duas respostas permitiria a uma empresa varrer códigos e
 * descobrir quais existem na base de uma concorrente, que é o vazamento mais
 * grave possível neste sistema (ver `DeviceScanService`, cabeçalho da classe).
 *
 * O cenário segue o padrão de `VazamentoEntreEmpresasTest`: duas empresas, a
 * primeira nascida da migration de fundação (`Company::query()->firstOrFail()`)
 * e a segunda criada à mão. Todo dado montado fora de uma requisição HTTP usa
 * `TenantAtual::comTenant()`, porque não há usuário autenticado resolvendo o
 * tenant nesses pontos.
 */
class DeviceScanTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresaUm;

    private Company $empresaDois;

    private User $administrador;

    private Client $cliente;

    private Address $enderecoDaOrdem;

    private Address $outroEndereco;

    private BaitType $tipoIsca;

    private WorkOrder $ordem;

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

        [$this->cliente, $this->enderecoDaOrdem, $this->outroEndereco, $this->tipoIsca, $this->ordem] =
            TenantAtual::comTenant($this->empresaUm->id, function (): array {
                $cliente = Client::create([
                    'name' => 'Cliente Um',
                    'email' => 'cliente-um@exemplo.test',
                    'phone' => '11912340000',
                    'cnpj' => '33.333.333/0001-01',
                ]);

                $enderecoDaOrdem = Address::create([
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

                $outroEndereco = Address::create([
                    'client_id' => $cliente->id,
                    'nickname' => 'Unidade Vizinha',
                    'street' => 'Rua Dois',
                    'number' => '200',
                    'district' => 'Bairro Dois',
                    'city' => 'Cidade Um',
                    'state' => 'SP',
                    'zip' => '01000-001',
                    'active' => true,
                ]);

                $tipoIsca = BaitType::create(['name' => 'Isca Padrão', 'brand' => 'Marca X']);

                $ordem = WorkOrder::create([
                    'order_number' => 'OT-SCAN-0001',
                    'client_id' => $cliente->id,
                    'address_id' => $enderecoDaOrdem->id,
                    'scheduled_date' => now()->toDateString(),
                    'status' => 'pending',
                ]);

                return [$cliente, $enderecoDaOrdem, $outroEndereco, $tipoIsca, $ordem];
            });

        TenantAtual::limpar();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    public function test_codigo_valido_do_endereco_da_os_devolve_ok(): void
    {
        $dispositivo = $this->criarDispositivo($this->enderecoDaOrdem, '001');

        $resposta = $this->actingAs($this->administrador)
            ->getJson("/devices/ler/{$dispositivo->codigo_publico}?work_order_id={$this->ordem->id}");

        $resposta->assertOk();
        $resposta->assertJsonPath('situacao', 'ok');
        $resposta->assertJsonPath('dispositivo.id', $dispositivo->id);
        $resposta->assertJsonPath('endereco.id', $this->enderecoDaOrdem->id);
    }

    public function test_codigo_de_outro_endereco_devolve_fora_da_os_com_o_endereco_real(): void
    {
        $dispositivo = $this->criarDispositivo($this->outroEndereco, '002');

        $resposta = $this->actingAs($this->administrador)
            ->getJson("/devices/ler/{$dispositivo->codigo_publico}?work_order_id={$this->ordem->id}");

        $resposta->assertOk();
        $resposta->assertJsonPath('situacao', 'fora_da_os');
        $resposta->assertJsonPath('dispositivo.id', $dispositivo->id);
        $resposta->assertJsonPath('endereco.id', $this->outroEndereco->id);
        $resposta->assertJsonPath('endereco_da_ordem.id', $this->enderecoDaOrdem->id);
    }

    /**
     * O teste mais importante deste arquivo. Não pode ser afrouxado: veja o
     * docblock da classe e o cabeçalho de `DeviceScanService`.
     */
    public function test_codigo_de_outra_empresa_devolve_nao_encontrado_igual_ao_codigo_inexistente(): void
    {
        $dispositivoDeOutraEmpresa = TenantAtual::comTenant($this->empresaDois->id, function (): Device {
            $cliente = Client::create([
                'name' => 'Cliente da Concorrente',
                'email' => 'cliente-concorrente@exemplo.test',
                'phone' => '11987650000',
                'cnpj' => '44.444.444/0001-02',
            ]);

            $endereco = Address::create([
                'client_id' => $cliente->id,
                'nickname' => 'Unidade da Concorrente',
                'street' => 'Rua da Concorrente',
                'number' => '300',
                'district' => 'Bairro da Concorrente',
                'city' => 'Cidade Dois',
                'state' => 'RJ',
                'zip' => '02000-000',
                'active' => true,
            ]);

            $tipoIsca = BaitType::create(['name' => 'Isca da Concorrente', 'brand' => 'Marca Y']);

            return Device::create([
                'address_id' => $endereco->id,
                'bait_type_id' => $tipoIsca->id,
                'label' => 'Dispositivo da Concorrente',
                'number' => '001',
                'active' => true,
            ]);
        });

        // Código de formato válido (alfabeto e tamanho corretos) que nunca foi
        // sorteado neste teste: representa "código inexistente".
        $codigoInexistente = 'ZZZZZZZZZZ';

        $respostaDeOutraEmpresa = $this->actingAs($this->administrador)
            ->getJson("/devices/ler/{$dispositivoDeOutraEmpresa->codigo_publico}");

        $respostaInexistente = $this->actingAs($this->administrador)
            ->getJson("/devices/ler/{$codigoInexistente}");

        $respostaDeOutraEmpresa->assertOk();
        $respostaInexistente->assertOk();

        $respostaDeOutraEmpresa->assertJsonPath('situacao', 'nao_encontrado');
        $respostaInexistente->assertJsonPath('situacao', 'nao_encontrado');

        $this->assertSame(
            $respostaInexistente->getContent(),
            $respostaDeOutraEmpresa->getContent(),
            'a leitura de um código de outra empresa devolveu resposta diferente da leitura de um '
            .'código inexistente: isso vazaria a existência do dado entre empresas'
        );
    }

    public function test_codigo_de_dispositivo_substituido_aponta_para_o_atual(): void
    {
        $anterior = $this->criarDispositivo($this->enderecoDaOrdem, '003');

        $resultado = TenantAtual::comTenant(
            $this->empresaUm->id,
            fn () => app(DeviceReplacementService::class)->substituir(
                $anterior,
                ['motivo' => 'danificado', 'substituido_em' => now()->subDay()->toDateString()],
                $this->administrador
            )
        );

        $this->assertTrue($resultado['success'], 'a substituição de apoio ao cenário falhou: '.$resultado['message']);
        $novo = $resultado['data']['novo'];

        $resposta = $this->actingAs($this->administrador)
            ->getJson("/devices/ler/{$anterior->codigo_publico}");

        $resposta->assertOk();
        $resposta->assertJsonPath('situacao', 'substituido');
        $resposta->assertJsonPath('dispositivo.id', $novo->id);
        $resposta->assertJsonPath('dispositivo_lido.id', $anterior->id);
    }

    public function test_tecnico_sem_vinculo_com_a_os_recebe_403(): void
    {
        $dispositivo = $this->criarDispositivo($this->enderecoDaOrdem, '004');

        [$tecnico, $usuarioTecnico] = TenantAtual::comTenant($this->empresaUm->id, function (): array {
            $tecnico = Technician::create([
                'name' => 'Técnico Sem Vínculo',
                'email' => 'tecnico-sem-vinculo@exemplo.test',
                'phone' => '11955550000',
                'registration_number' => 'CRQ-0001',
                'is_active' => true,
            ]);

            $usuario = User::factory()->create(['name' => 'Usuário Técnico', 'is_active' => true]);

            return [$tecnico, $usuario];
        });

        $usuarioTecnico->assignRole('tecnico');
        $tecnico->update(['user_id' => $usuarioTecnico->id]);

        // A ordem de serviço não recebe este técnico nem por technician_id nem
        // pela pivot work_order_technicians: o vínculo simplesmente não existe.
        $resposta = $this->actingAs($usuarioTecnico)
            ->getJson("/devices/ler/{$dispositivo->codigo_publico}?work_order_id={$this->ordem->id}");

        $resposta->assertStatus(403);
    }

    public function test_codigo_malformado_devolve_nao_encontrado_sem_consultar_o_banco(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        // 10 caracteres, mas com "I", que está fora do alfabeto de
        // CodigoDeDispositivo::ALFABETO: nunca foi gerado, então não há o que
        // procurar no banco.
        $resposta = $this->actingAs($this->administrador)->getJson('/devices/ler/AAAAAAAAAI');

        $resposta->assertOk();
        $resposta->assertJsonPath('situacao', 'nao_encontrado');

        $tocouTabelaDevices = collect(DB::getQueryLog())
            ->contains(fn (array $registro): bool => str_contains($registro['query'], 'devices'));

        $this->assertFalse(
            $tocouTabelaDevices,
            'a leitura de um código malformado consultou a tabela devices: a validação de formato '
            .'deveria recusar antes de qualquer consulta'
        );

        DB::disableQueryLog();
    }

    private function criarDispositivo(Address $endereco, string $numero): Device
    {
        return TenantAtual::comTenant($this->empresaUm->id, fn () => Device::create([
            'address_id' => $endereco->id,
            'bait_type_id' => $this->tipoIsca->id,
            'label' => 'Dispositivo '.$numero,
            'number' => $numero,
            'active' => true,
        ]));
    }
}
