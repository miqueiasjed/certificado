<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\SyncOperation;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPhoto;
use App\Support\TenantAtual;
use Database\Factories\WorkOrderFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Task 12.5 do Plano 12: endpoint de sincronização em lote e envio de foto do
 * aplicativo do técnico.
 *
 * Cobre os sete critérios de aceitação da task: lote de 50 operações devolve
 * 50 resultados, uma operação em conflito não impede as demais, reenviar o
 * lote inteiro devolve `duplicada` para as já aplicadas, lote de 51 devolve
 * 422, foto acima de 5 MB é recusada com mensagem clara, a mesma foto enviada
 * duas vezes grava uma, e passar de 60 chamadas por minuto devolve 429.
 */
class AppSyncUploadTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'senha-do-tecnico-123';

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);

        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Lote de 50 operações
    // -----------------------------------------------------------------

    public function test_lote_de_50_operacoes_devolve_50_resultados_um_por_uuid(): void
    {
        [$empresa, $usuario, $tecnico] = $this->criarEmpresaComTecnico('Cinquenta');
        $ordem = $this->criarOrdem($empresa, $tecnico);
        $token = $this->logar($usuario, 'Aparelho do lote');

        $operacoes = [];
        for ($i = 1; $i <= 50; $i++) {
            $operacoes[] = $this->operacaoDeFoto($ordem, "foto-lote-{$i}.jpg");
        }

        $resposta = $this->withToken($token)->postJson('/api/app/sincronizar', ['operacoes' => $operacoes]);

        $resposta->assertOk();

        $resultados = $resposta->json('resultados');

        $this->assertCount(50, $resultados);
        $this->assertSame(
            collect($operacoes)->pluck('uuid')->sort()->values()->all(),
            collect($resultados)->pluck('uuid')->sort()->values()->all(),
            'a resposta deveria trazer exatamente um resultado por uuid enviado'
        );

        foreach ($resultados as $resultado) {
            $this->assertSame('aplicada', $resultado['situacao']);
        }
    }

    // -----------------------------------------------------------------
    // Conflito não impede as demais
    // -----------------------------------------------------------------

    public function test_operacao_em_conflito_nao_impede_as_demais_de_serem_aplicadas(): void
    {
        [$empresa, $usuario, $tecnico] = $this->criarEmpresaComTecnico('Conflito');
        $ordem = $this->criarOrdem($empresa, $tecnico);
        $token = $this->logar($usuario, 'Aparelho do conflito');

        $operacaoValidaUm = $this->operacaoDeFoto($ordem, 'foto-valida-um.jpg');
        $operacaoEmConflito = [
            'uuid' => (string) Str::uuid(),
            'tipo' => 'foto',
            // Ordem de serviço que não existe: gera o conflito "registro_removido"
            // sem derrubar as demais operações do mesmo lote.
            'work_order_id' => 999999,
            'registrada_em' => now()->toJSON(),
            'updated_at_conhecido' => null,
            'payload' => ['entity_type' => 'room_event', 'path' => 'nao-importa.jpg'],
        ];
        $operacaoValidaDois = $this->operacaoDeFoto($ordem, 'foto-valida-dois.jpg');

        $resposta = $this->withToken($token)->postJson('/api/app/sincronizar', [
            'operacoes' => [$operacaoValidaUm, $operacaoEmConflito, $operacaoValidaDois],
        ]);

        $resposta->assertOk();

        $resultados = collect($resposta->json('resultados'))->keyBy('uuid');

        $this->assertSame('aplicada', $resultados[$operacaoValidaUm['uuid']]['situacao']);
        $this->assertSame('aplicada', $resultados[$operacaoValidaDois['uuid']]['situacao']);

        $conflito = $resultados[$operacaoEmConflito['uuid']];
        $this->assertSame('conflito', $conflito['situacao']);
        $this->assertNotNull($conflito['conflito_id']);
        $this->assertSame('registro_removido', $conflito['motivo']);

        $this->assertSame(
            2,
            WorkOrderPhoto::where('work_order_id', $ordem->id)->count(),
            'as duas operações válidas deveriam ter sido aplicadas apesar do conflito'
        );
    }

    // -----------------------------------------------------------------
    // Reenviar o lote inteiro
    // -----------------------------------------------------------------

    public function test_reenviar_o_lote_inteiro_devolve_duplicada_para_as_ja_aplicadas(): void
    {
        [$empresa, $usuario, $tecnico] = $this->criarEmpresaComTecnico('Duplicada');
        $ordem = $this->criarOrdem($empresa, $tecnico);
        $token = $this->logar($usuario, 'Aparelho da duplicidade');

        $operacoes = [
            $this->operacaoDeFoto($ordem, 'foto-dup-um.jpg'),
            $this->operacaoDeFoto($ordem, 'foto-dup-dois.jpg'),
            $this->operacaoDeFoto($ordem, 'foto-dup-tres.jpg'),
        ];

        $primeiraResposta = $this->withToken($token)->postJson('/api/app/sincronizar', ['operacoes' => $operacoes]);
        $primeiraResposta->assertOk();

        foreach ($primeiraResposta->json('resultados') as $resultado) {
            $this->assertSame('aplicada', $resultado['situacao']);
        }

        $segundaResposta = $this->withToken($token)->postJson('/api/app/sincronizar', ['operacoes' => $operacoes]);
        $segundaResposta->assertOk();

        foreach ($segundaResposta->json('resultados') as $resultado) {
            $this->assertSame('duplicada', $resultado['situacao']);
        }

        $this->assertSame(
            3,
            WorkOrderPhoto::where('work_order_id', $ordem->id)->count(),
            'reenviar o lote inteiro não pode reaplicar nem duplicar nenhuma operação'
        );
    }

    // -----------------------------------------------------------------
    // Lote de 51 operações
    // -----------------------------------------------------------------

    public function test_lote_de_51_operacoes_devolve_422(): void
    {
        [$empresa, $usuario, $tecnico] = $this->criarEmpresaComTecnico('CinquentaEUm');
        $ordem = $this->criarOrdem($empresa, $tecnico);
        $token = $this->logar($usuario, 'Aparelho do lote grande');

        $operacoes = [];
        for ($i = 1; $i <= 51; $i++) {
            $operacoes[] = $this->operacaoDeFoto($ordem, "foto-grande-{$i}.jpg");
        }

        $resposta = $this->withToken($token)->postJson('/api/app/sincronizar', ['operacoes' => $operacoes]);

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('operacoes');

        $this->assertSame(
            0,
            SyncOperation::count(),
            'lote recusado por validação não pode gravar nenhuma operação'
        );
    }

    // -----------------------------------------------------------------
    // Foto acima de 5 MB
    // -----------------------------------------------------------------

    public function test_foto_acima_de_5mb_e_recusada_com_mensagem_clara(): void
    {
        [$empresa, $usuario, $tecnico] = $this->criarEmpresaComTecnico('FotoGrande');
        $ordem = $this->criarOrdem($empresa, $tecnico);
        $token = $this->logar($usuario, 'Aparelho da foto grande');

        // Imagem "de verdade" (mime por extensão passa na regra `image`),
        // com o tamanho reportado forçado acima do limite de 5 MB (5120 KB):
        // isola a falha na regra de tamanho, e não na de tipo de arquivo.
        $arquivo = UploadedFile::fake()->image('foto-grande.jpg')->size(6000);

        $resposta = $this->withToken($token)->post('/api/app/fotos', [
            'uuid' => (string) Str::uuid(),
            'work_order_id' => $ordem->id,
            'entity_type' => 'room_event',
            'legenda' => 'Foto grande demais',
            'foto' => $arquivo,
        ], ['Accept' => 'application/json']);

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('foto');

        $mensagem = mb_strtolower((string) collect($resposta->json('errors.foto'))->first());
        $this->assertStringContainsString('5 mb', $mensagem);

        $this->assertSame(
            0,
            WorkOrderPhoto::where('work_order_id', $ordem->id)->count(),
            'foto recusada por tamanho não pode gravar nenhum registro'
        );
    }

    // -----------------------------------------------------------------
    // Mesma foto duas vezes
    // -----------------------------------------------------------------

    public function test_mesma_foto_enviada_duas_vezes_grava_uma(): void
    {
        [$empresa, $usuario, $tecnico] = $this->criarEmpresaComTecnico('FotoDuplicada');
        $ordem = $this->criarOrdem($empresa, $tecnico);
        $token = $this->logar($usuario, 'Aparelho da foto duplicada');

        $uuid = (string) Str::uuid();

        $dadosDaFoto = [
            'uuid' => $uuid,
            'work_order_id' => $ordem->id,
            'entity_type' => 'room_event',
            'legenda' => 'Foto de teste',
        ];

        $primeiraResposta = $this->withToken($token)->post('/api/app/fotos', array_merge($dadosDaFoto, [
            'foto' => UploadedFile::fake()->image('foto.jpg', 200, 200),
        ]), ['Accept' => 'application/json']);

        $primeiraResposta->assertOk();
        $this->assertSame('aplicada', $primeiraResposta->json('situacao'));
        $this->assertNotNull($primeiraResposta->json('foto.id'));

        $segundaResposta = $this->withToken($token)->post('/api/app/fotos', array_merge($dadosDaFoto, [
            'foto' => UploadedFile::fake()->image('foto.jpg', 200, 200),
        ]), ['Accept' => 'application/json']);

        $segundaResposta->assertOk();
        $this->assertSame('duplicada', $segundaResposta->json('situacao'));

        $this->assertSame(
            1,
            WorkOrderPhoto::where('work_order_id', $ordem->id)->count(),
            'a mesma foto enviada duas vezes deveria gravar apenas um registro'
        );

        $this->assertCount(
            1,
            Storage::disk('public')->allFiles("work-orders/{$ordem->id}/photos"),
            'a mesma foto enviada duas vezes deveria gravar apenas um arquivo em disco'
        );
    }

    // -----------------------------------------------------------------
    // Limite de taxa
    // -----------------------------------------------------------------

    public function test_passar_de_60_chamadas_por_minuto_devolve_429(): void
    {
        [$empresa, $usuario, $tecnico] = $this->criarEmpresaComTecnico('LimiteDeTaxa');
        $ordem = $this->criarOrdem($empresa, $tecnico);
        $token = $this->logar($usuario, 'Aparelho do limite de taxa');

        $ultimaResposta = null;

        for ($i = 1; $i <= 61; $i++) {
            $operacao = $this->operacaoDeFoto($ordem, "foto-limite-{$i}.jpg");

            /** @var TestResponse $ultimaResposta */
            $ultimaResposta = $this->withToken($token)->postJson('/api/app/sincronizar', [
                'operacoes' => [$operacao],
            ]);

            if ($i <= 60) {
                $this->assertNotSame(
                    429,
                    $ultimaResposta->getStatusCode(),
                    "a chamada número {$i}, dentro do limite de 60 por minuto, não deveria ser bloqueada"
                );
            }
        }

        $ultimaResposta->assertStatus(429);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * @return array{0: Company, 1: User, 2: Technician}
     */
    private function criarEmpresaComTecnico(string $marca): array
    {
        $empresa = Company::create(['name' => 'Empresa '.$marca]);

        [$usuario, $tecnico] = TenantAtual::comTenant($empresa->id, function () use ($marca) {
            $usuario = User::factory()->create([
                'name' => 'Tecnico '.$marca,
                'email' => 'tecnico-'.strtolower($marca).'-'.uniqid().'@exemplo.test',
                'password' => self::SENHA,
                'is_active' => true,
            ]);

            $usuario->assignRole('tecnico');

            $tecnico = Technician::create([
                'name' => 'Cadastro Tecnico '.$marca,
                'email' => 'cadastro-'.strtolower($marca).'-'.uniqid().'@exemplo.test',
                'phone' => '11999990000',
                'specialty' => 'Controle de pragas',
                'is_active' => true,
                'user_id' => $usuario->id,
            ]);

            return [$usuario, $tecnico];
        });

        return [$empresa, $usuario->fresh(), $tecnico];
    }

    private function criarOrdem(Company $empresa, Technician $tecnico): WorkOrder
    {
        return TenantAtual::comTenant(
            $empresa->id,
            fn () => WorkOrderFactory::new()->create(['technician_id' => $tecnico->id])
        );
    }

    private function logar(User $usuario, string $dispositivo): string
    {
        $resposta = $this->postJson('/api/app/login', [
            'email' => $usuario->email,
            'password' => self::SENHA,
            'dispositivo' => $dispositivo,
        ]);

        $resposta->assertOk();

        return (string) $resposta->json('token');
    }

    /**
     * Operação de sincronização do tipo `foto`, com o arquivo já "gravado em
     * disco" no path informado, do mesmo jeito que `POST /api/app/fotos`
     * deixaria antes do lote chegar.
     */
    private function operacaoDeFoto(WorkOrder $ordem, string $nomeDoArquivo): array
    {
        $path = "work-orders/{$ordem->id}/photos/{$nomeDoArquivo}";
        Storage::disk('public')->put($path, 'conteudo-fake-de-teste');

        return [
            'uuid' => (string) Str::uuid(),
            'tipo' => 'foto',
            'work_order_id' => $ordem->id,
            'registrada_em' => now()->toJSON(),
            'updated_at_conhecido' => null,
            'payload' => [
                'entity_type' => 'room_event',
                'entity_id' => null,
                'room_id' => null,
                'path' => $path,
                'caption' => 'Foto de teste',
            ],
        ];
    }
}
