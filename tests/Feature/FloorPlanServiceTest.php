<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Client;
use App\Models\Company;
use App\Models\Device;
use App\Models\DevicePosition;
use App\Models\User;
use App\Services\FloorPlanService;
use App\Support\TenantAtual;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Task 21.4 do Plano 21: planta versionada do endereço e posicionamento dos
 * dispositivos sobre ela.
 *
 * Segue o mesmo padrão de `DeviceReplacementTest`: dado de apoio criado fora
 * de requisição HTTP dentro de `TenantAtual::comTenant()`, e o disco público
 * fake para não gravar arquivo de teste no disco real.
 */
class FloorPlanServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private Address $enderecoA;

    private Address $enderecoB;

    private FloorPlanService $servico;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->empresa = Company::query()->firstOrFail();
        $this->servico = app(FloorPlanService::class);

        Storage::fake('public');

        [$this->enderecoA, $this->enderecoB] = TenantAtual::comTenant(
            $this->empresa->id,
            function (): array {
                $cliente = Client::create([
                    'name' => 'Cliente Plantas',
                    'email' => 'cliente-plantas@exemplo.test',
                    'phone' => '11912340001',
                    'cnpj' => '44.444.444/0001-01',
                ]);

                $enderecoA = Address::create([
                    'client_id' => $cliente->id,
                    'nickname' => 'Unidade A',
                    'street' => 'Rua A',
                    'number' => '100',
                    'district' => 'Bairro A',
                    'city' => 'Cidade A',
                    'state' => 'SP',
                    'zip' => '01000-000',
                    'active' => true,
                ]);

                $enderecoB = Address::create([
                    'client_id' => $cliente->id,
                    'nickname' => 'Unidade B',
                    'street' => 'Rua B',
                    'number' => '200',
                    'district' => 'Bairro B',
                    'city' => 'Cidade B',
                    'state' => 'SP',
                    'zip' => '02000-000',
                    'active' => true,
                ]);

                return [$enderecoA, $enderecoB];
            }
        );

        TenantAtual::limpar();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    public function test_enviar_cria_a_versao_1_com_as_dimensoes(): void
    {
        $planta = TenantAtual::comTenant(
            $this->empresa->id,
            fn () => $this->servico->enviar(
                $this->enderecoA,
                UploadedFile::fake()->image('planta.png', 800, 600),
                ['nome' => 'Térreo']
            )
        );

        $this->assertSame(1, $planta->versao);
        $this->assertTrue($planta->ativa);
        $this->assertSame(800, $planta->largura_px);
        $this->assertSame(600, $planta->altura_px);
        $this->assertNull($planta->substituida_em);

        Storage::disk('public')->assertExists($planta->arquivo_path);
    }

    public function test_substituir_cria_a_versao_2_copia_as_posicoes_e_a_versao_1_continua_intacta(): void
    {
        [$planta1, $deviceUm, $deviceDois] = TenantAtual::comTenant($this->empresa->id, function () {
            $planta1 = $this->servico->enviar(
                $this->enderecoA,
                UploadedFile::fake()->image('planta.png', 800, 600),
                ['nome' => 'Térreo']
            );

            $deviceUm = $this->criarDispositivo($this->enderecoA, '001');
            $deviceDois = $this->criarDispositivo($this->enderecoA, '002');

            $this->servico->salvarPosicoes($planta1, [
                ['device_id' => $deviceUm->id, 'x' => 0.25, 'y' => 0.30],
                ['device_id' => $deviceDois->id, 'x' => 0.75, 'y' => 0.80],
            ]);

            return [$planta1, $deviceUm, $deviceDois];
        });

        $planta2 = TenantAtual::comTenant(
            $this->empresa->id,
            fn () => $this->servico->substituir($planta1, UploadedFile::fake()->image('planta-v2.png', 900, 650))
        );

        $this->assertSame(2, $planta2->versao);
        $this->assertTrue($planta2->ativa);

        $planta1->refresh();
        $this->assertFalse((bool) $planta1->ativa);
        $this->assertNotNull($planta1->substituida_em);

        // As posições da versão 1 continuam intactas: mesma quantidade, mesmo
        // valor, presas à versão 1.
        $posicoesV1 = TenantAtual::comTenant(
            $this->empresa->id,
            fn () => DevicePosition::query()->where('floor_plan_id', $planta1->id)->orderBy('device_id')->get()
        );
        $this->assertCount(2, $posicoesV1);
        $this->assertEqualsWithDelta(0.25, (float) $posicoesV1[0]->x, 0.0001);
        $this->assertEqualsWithDelta(0.30, (float) $posicoesV1[0]->y, 0.0001);

        // A versão 2 nasceu com as mesmas posições copiadas, prontas para o
        // usuário ajustar.
        $posicoesV2 = TenantAtual::comTenant(
            $this->empresa->id,
            fn () => DevicePosition::query()->where('floor_plan_id', $planta2->id)->orderBy('device_id')->get()
        );
        $this->assertCount(2, $posicoesV2);
        $this->assertEqualsWithDelta(0.25, (float) $posicoesV2[0]->x, 0.0001);
        $this->assertEqualsWithDelta(0.75, (float) $posicoesV2[1]->x, 0.0001);
    }

    public function test_salvar_posicoes_recusa_dispositivo_de_outro_endereco(): void
    {
        [$planta, $deviceDeOutroEndereco] = TenantAtual::comTenant($this->empresa->id, function () {
            $planta = $this->servico->enviar(
                $this->enderecoA,
                UploadedFile::fake()->image('planta.png', 800, 600),
                ['nome' => 'Térreo']
            );

            $deviceDeOutroEndereco = $this->criarDispositivo($this->enderecoB, '901');

            return [$planta, $deviceDeOutroEndereco];
        });

        $this->expectException(ValidationException::class);

        TenantAtual::comTenant(
            $this->empresa->id,
            fn () => $this->servico->salvarPosicoes($planta, [
                ['device_id' => $deviceDeOutroEndereco->id, 'x' => 0.5, 'y' => 0.5],
            ])
        );
    }

    public function test_salvar_posicoes_recusa_x_ou_y_fora_do_intervalo_aberto_0_1(): void
    {
        [$planta, $device] = TenantAtual::comTenant($this->empresa->id, function () {
            $planta = $this->servico->enviar(
                $this->enderecoA,
                UploadedFile::fake()->image('planta.png', 800, 600),
                ['nome' => 'Térreo']
            );

            return [$planta, $this->criarDispositivo($this->enderecoA, '010')];
        });

        $casosInvalidos = [
            ['device_id' => $device->id, 'x' => 0.0, 'y' => 0.5],
            ['device_id' => $device->id, 'x' => 1.0, 'y' => 0.5],
            ['device_id' => $device->id, 'x' => 0.5, 'y' => -0.1],
            ['device_id' => $device->id, 'x' => 0.5, 'y' => 1.2],
        ];

        foreach ($casosInvalidos as $posicaoInvalida) {
            try {
                TenantAtual::comTenant(
                    $this->empresa->id,
                    fn () => $this->servico->salvarPosicoes($planta, [$posicaoInvalida])
                );

                $this->fail('Esperava ValidationException para x/y fora de (0, 1): '.json_encode($posicaoInvalida));
            } catch (ValidationException) {
                // esperado
            }
        }

        $quantidadeGravada = TenantAtual::comTenant(
            $this->empresa->id,
            fn () => DevicePosition::query()->where('floor_plan_id', $planta->id)->count()
        );
        $this->assertSame(0, $quantidadeGravada, 'nenhuma posição inválida deveria ter sido gravada');
    }

    public function test_dispositivos_nao_posicionados_lista_apenas_os_ativos_sem_posicao_na_versao_ativa(): void
    {
        $resultado = TenantAtual::comTenant($this->empresa->id, function () {
            $planta = $this->servico->enviar(
                $this->enderecoA,
                UploadedFile::fake()->image('planta.png', 800, 600),
                ['nome' => 'Térreo']
            );

            $posicionado = $this->criarDispositivo($this->enderecoA, '020');
            $naoPosicionado = $this->criarDispositivo($this->enderecoA, '021');
            $inativoNaoPosicionado = $this->criarDispositivo($this->enderecoA, '022');
            $inativoNaoPosicionado->update(['active' => false]);

            $this->servico->salvarPosicoes($planta, [
                ['device_id' => $posicionado->id, 'x' => 0.5, 'y' => 0.5],
            ]);

            return [
                'faltando' => $this->servico->dispositivosNaoPosicionados($planta)->pluck('id')->all(),
                'naoPosicionadoId' => $naoPosicionado->id,
            ];
        });

        $this->assertSame([$resultado['naoPosicionadoId']], $resultado['faltando']);
    }

    public function test_dispositivo_substituido_herda_a_posicao_do_anterior(): void
    {
        [$planta, $anterior] = TenantAtual::comTenant($this->empresa->id, function () {
            $planta = $this->servico->enviar(
                $this->enderecoA,
                UploadedFile::fake()->image('planta.png', 800, 600),
                ['nome' => 'Térreo']
            );

            $anterior = $this->criarDispositivo($this->enderecoA, '030');

            $this->servico->salvarPosicoes($planta, [
                ['device_id' => $anterior->id, 'x' => 0.42, 'y' => 0.58],
            ]);

            return [$planta, $anterior];
        });

        $administrador = TenantAtual::comTenant(
            $this->empresa->id,
            fn () => User::factory()->create(['name' => 'Administrador', 'is_active' => true])
        );
        $administrador->assignRole('administrador');

        $resposta = $this->actingAs($administrador)->postJson("/devices/{$anterior->id}/substituir", [
            'motivo' => 'danificado',
            'substituido_em' => now()->subDay()->toDateString(),
        ]);

        $resposta->assertOk();
        $novoId = $resposta->json('dispositivo_novo.id');

        $posicaoDoNovo = TenantAtual::comTenant(
            $this->empresa->id,
            fn () => DevicePosition::query()
                ->where('floor_plan_id', $planta->id)
                ->where('device_id', $novoId)
                ->first()
        );

        $this->assertNotNull($posicaoDoNovo, 'o dispositivo novo não herdou posição na planta ativa');
        $this->assertEqualsWithDelta(0.42, (float) $posicaoDoNovo->x, 0.0001);
        $this->assertEqualsWithDelta(0.58, (float) $posicaoDoNovo->y, 0.0001);
    }

    public function test_arquivo_de_tipo_nao_permitido_e_recusado_pelo_conteudo_mesmo_com_extensao_de_imagem(): void
    {
        $caminhoTemporario = tempnam(sys_get_temp_dir(), 'floorplan_falso_');
        file_put_contents($caminhoTemporario, 'isto e apenas texto simples, nao e uma imagem.');

        // Nome e mimetype "de fachada" (.png / image/png) para simular um
        // .txt renomeado: a recusa precisa vir do conteúdo real, detectado
        // via fileinfo, e não do que o cliente declarou.
        $arquivoFalso = new UploadedFile($caminhoTemporario, 'planta-falsa.png', 'image/png', null, true);

        $this->expectException(ValidationException::class);

        TenantAtual::comTenant(
            $this->empresa->id,
            fn () => $this->servico->enviar($this->enderecoA, $arquivoFalso, ['nome' => 'Térreo'])
        );
    }

    private function criarDispositivo(Address $endereco, string $numero): Device
    {
        return Device::create([
            'address_id' => $endereco->id,
            'label' => 'Dispositivo '.$numero,
            'number' => $numero,
            'active' => true,
        ]);
    }
}
