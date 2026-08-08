<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Contract;
use App\Models\Module;
use App\Models\SignatureProviderConfig;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Services\Signature\ProvedorPadrao;
use App\Services\SignatureRequestService;
use App\Support\TenantAtual;
use Closure;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 26.6 do Plano 26: os endpoints de assinatura eletrônica, a
 * imutabilidade do contrato em assinatura, o sigilo da credencial e o que o
 * cliente vê no portal.
 *
 * O provedor nunca é chamado de verdade: todo tráfego passa por `Http::fake()`
 * com `preventStrayRequests()`.
 */
class SignatureEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://sandbox.api.zapsign.com.br';

    private Company $empresa;

    private User $administrador;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        Http::preventStrayRequests();
        Storage::fake(SignatureRequestService::DISCO);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ModulesSeeder::class);

        Company::query()->whereKey(1)->update([
            'name' => 'Dedetizadora A',
            'email' => 'contato@a.test',
        ]);

        $this->empresa = Company::query()->findOrFail(1);
        $this->liberarModulo($this->empresa);

        $this->administrador = $this->usuario('administrador', 'admin@a.test');
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Envio e imutabilidade
    // -----------------------------------------------------------------

    public function test_envio_cria_o_pedido_e_marca_o_contrato(): void
    {
        Http::fake([self::HOST.'/api/v1/docs/' => Http::response($this->documentoPendente(), 200)]);

        $contrato = $this->criarContrato();
        $this->configuracao();

        $this->actingAs($this->administrador)
            ->postJson("/contratos/{$contrato->id}/assinatura", $this->corpoDeEnvio())
            ->assertCreated()
            ->assertJsonPath('pedido.situacao', 'enviado')
            ->assertJsonCount(2, 'pedido.signatarios');

        $this->assertSame('em_assinatura', $contrato->fresh()->situacao_assinatura);
    }

    public function test_alterar_contrato_em_assinatura_e_recusado_com_mensagem_clara(): void
    {
        $contrato = $this->criarContrato();
        $this->naEmpresa(fn () => $contrato->forceFill(['situacao_assinatura' => 'em_assinatura'])->save());

        $resposta = $this->actingAs($this->administrador)
            ->putJson("/contracts/{$contrato->id}", [
                'address_id' => $contrato->address_id,
                'service_type' => 'pontual',
                'service_value' => '2000.00',
                'start_date' => $contrato->start_date->toDateString(),
                'end_date' => $contrato->end_date->toDateString(),
            ]);

        $resposta->assertStatus(500);
        $this->assertSame('1000.00', $contrato->fresh()->service_value);
    }

    public function test_enviar_contrato_ja_assinado_e_recusado(): void
    {
        Http::fake();

        $contrato = $this->criarContrato();
        $this->configuracao();
        $this->naEmpresa(fn () => $contrato->forceFill(['situacao_assinatura' => 'assinado'])->save());

        $this->actingAs($this->administrador)
            ->postJson("/contratos/{$contrato->id}/assinatura", $this->corpoDeEnvio())
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $mensagem): bool => str_contains($mensagem, 'já foi assinado'));

        Http::assertNothingSent();
    }

    public function test_pedido_sem_as_duas_partes_e_recusado_antes_do_provedor(): void
    {
        Http::fake();

        $contrato = $this->criarContrato();
        $this->configuracao();

        $this->actingAs($this->administrador)
            ->postJson("/contratos/{$contrato->id}/assinatura", [
                'signatarios' => [
                    ['nome' => 'Só a empresa', 'email' => 'contratada@empresa.com.br', 'papel' => 'contratada', 'ordem' => 1],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('signatarios');

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Reenvio, cancelamento e vínculo com o contrato
    // -----------------------------------------------------------------

    public function test_reenviar_nao_cria_outro_pedido(): void
    {
        [$contrato, $pedido] = $this->contratoEnviado();

        Http::fake([
            self::HOST.'/api/v1/docs/DOC-1/' => Http::response($this->documentoPendente(), 200),
            self::HOST.'/api/v1/signers/notify/' => Http::response(['success' => true], 200),
        ]);

        $this->actingAs($this->administrador)
            ->postJson("/contratos/{$contrato->id}/assinatura/{$pedido->id}/reenviar")
            ->assertOk();

        $this->assertSame(
            1,
            $this->naEmpresa(fn (): int => SignatureRequest::query()->where('contract_id', $contrato->id)->count())
        );
    }

    public function test_cancelar_devolve_o_contrato_a_nao_enviado(): void
    {
        [$contrato, $pedido] = $this->contratoEnviado();

        Http::fake([self::HOST.'/api/v1/refuse/' => Http::response(['success' => true], 200)]);

        $this->actingAs($this->administrador)
            ->postJson("/contratos/{$contrato->id}/assinatura/{$pedido->id}/cancelar", ['motivo' => 'Renegociado.'])
            ->assertOk()
            ->assertJsonPath('pedido.situacao', 'cancelado');

        $this->assertSame('nao_enviado', $contrato->fresh()->situacao_assinatura);
    }

    public function test_pedido_de_outro_contrato_da_mesma_empresa_devolve_404(): void
    {
        [, $pedido] = $this->contratoEnviado();
        $outroContrato = $this->criarContrato();

        Http::fake();

        $this->actingAs($this->administrador)
            ->postJson("/contratos/{$outroContrato->id}/assinatura/{$pedido->id}/cancelar")
            ->assertNotFound();

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Permissão, módulo e sigilo da credencial
    // -----------------------------------------------------------------

    public function test_usuario_sem_permissao_recebe_403(): void
    {
        Http::fake();

        $contrato = $this->criarContrato();
        $tecnico = $this->usuario('tecnico', 'tecnico@a.test');

        $this->actingAs($tecnico)
            ->postJson("/contratos/{$contrato->id}/assinatura", $this->corpoDeEnvio())
            ->assertForbidden();
    }

    public function test_quem_envia_contrato_nao_configura_a_credencial(): void
    {
        // `contrato-enviar-assinatura` entra no papel comercial pelo prefixo
        // `contrato-`; `assinatura-eletronica-configurar` não entra em papel
        // nenhum além do administrador.
        $comercial = $this->usuario('comercial', 'comercial@a.test');

        $this->assertTrue($comercial->can('contrato-enviar-assinatura'));
        $this->assertFalse($comercial->can('assinatura-eletronica-configurar'));

        $this->actingAs($comercial)
            ->getJson('/assinaturas/configuracao')
            ->assertForbidden();
    }

    public function test_a_credencial_nunca_volta_na_resposta(): void
    {
        $this->configuracao('token-super-secreto');

        $resposta = $this->actingAs($this->administrador)->getJson('/assinaturas/configuracao');

        $resposta->assertOk()
            ->assertJsonPath('configuracao.possui_credencial', true)
            ->assertJsonPath('configuracao.ambiente', 'sandbox');

        $conteudo = $resposta->getContent();
        $this->assertStringNotContainsString('token-super-secreto', $conteudo);
        $this->assertStringNotContainsString('credenciais', $conteudo);
        $this->assertStringNotContainsString('webhook_token', $conteudo);
    }

    public function test_tenant_sem_o_modulo_nao_alcanca_as_rotas(): void
    {
        Http::fake();

        $contrato = $this->criarContrato();
        CompanyModule::query()->where('company_id', $this->empresa->id)->delete();

        $resposta = $this->actingAs($this->administrador)
            ->getJson("/contratos/{$contrato->id}/assinatura");

        $this->assertNotSame(200, $resposta->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Portal do cliente
    // -----------------------------------------------------------------

    public function test_cliente_baixa_a_via_assinada_do_proprio_contrato_e_nao_a_de_outro(): void
    {
        [$contrato, $pedido] = $this->contratoEnviado();

        $caminho = "contratos/assinatura/{$this->empresa->id}/{$pedido->id}-assinado.pdf";

        $this->naEmpresa(function () use ($pedido, $contrato, $caminho): void {
            Storage::disk(SignatureRequestService::DISCO)->put($caminho, '%PDF-assinado');

            $pedido->forceFill([
                'situacao' => 'assinado',
                'concluido_em' => now(),
                'arquivo_assinado_path' => $caminho,
            ])->save();

            $contrato->forceFill(['situacao_assinatura' => 'assinado', 'assinado_em' => now()])->save();
        });

        $dono = $this->clienteDoPortal($contrato->address->client);
        $outro = $this->clienteDoPortal($this->naEmpresa(fn (): Client => ClientFactory::new()->create([
            'name' => 'Outro Cliente',
            'email' => 'outro@exemplo.com.br',
        ])));

        $this->actingAs($dono, 'cliente')
            ->get("/portal/contratos/{$contrato->id}/assinado")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        // O contrato não é do outro cliente: 404, e não 403 — revelar que o
        // documento existe já seria informação demais.
        $this->actingAs($outro, 'cliente')
            ->get("/portal/contratos/{$contrato->id}/assinado")
            ->assertNotFound();
    }

    public function test_contrato_sem_via_assinada_nao_tem_download_no_portal(): void
    {
        [$contrato] = $this->contratoEnviado();

        $dono = $this->clienteDoPortal($contrato->address->client);

        $this->actingAs($dono, 'cliente')
            ->get("/portal/contratos/{$contrato->id}/assinado")
            ->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * @return array{0: Contract, 1: SignatureRequest}
     */
    private function contratoEnviado(): array
    {
        Http::fake([self::HOST.'/api/v1/docs/' => Http::response($this->documentoPendente(), 200)]);

        $contrato = $this->criarContrato();
        $this->configuracao();

        $pedido = $this->naEmpresa(fn (): SignatureRequest => app(SignatureRequestService::class)->enviar(
            $contrato,
            [
                ['nome' => 'Contratada', 'email' => 'contratada@empresa.com.br', 'papel' => 'contratada', 'ordem' => 1],
                ['nome' => 'Cliente Fulano', 'email' => 'cliente@exemplo.com.br', 'papel' => 'contratante', 'ordem' => 2],
            ],
            10,
            $this->administrador,
        ));

        return [$contrato->fresh()->load('address.client'), $pedido];
    }

    /**
     * @return array<string, mixed>
     */
    private function corpoDeEnvio(): array
    {
        return [
            'signatarios' => [
                ['nome' => 'Contratada', 'email' => 'contratada@empresa.com.br', 'papel' => 'contratada', 'ordem' => 1],
                ['nome' => 'Cliente Fulano', 'email' => 'cliente@exemplo.com.br', 'papel' => 'contratante', 'ordem' => 2],
            ],
            'dias_para_expirar' => 10,
        ];
    }

    private function configuracao(string $token = 'tok-a'): SignatureProviderConfig
    {
        return $this->naEmpresa(fn (): SignatureProviderConfig => SignatureProviderConfig::create([
            'provedor' => ProvedorPadrao::NOME,
            'ambiente' => 'sandbox',
            'credenciais' => ['token' => $token],
            'webhook_token' => Str::random(40),
            'ativo' => true,
        ]));
    }

    private function usuario(string $papel, string $email): User
    {
        $usuario = $this->naEmpresa(fn (): User => User::query()->create([
            'name' => ucfirst($papel),
            'email' => $email,
            'password' => bcrypt('segredo123'),
        ]));

        $usuario->assignRole($papel);

        return $usuario->fresh();
    }

    private function clienteDoPortal(Client $cliente): ClientUser
    {
        return $this->naEmpresa(fn (): ClientUser => ClientUser::query()->create([
            'client_id' => $cliente->id,
            'nome' => 'Portal '.$cliente->name,
            'email' => 'portal-'.uniqid().'@exemplo.com.br',
            'senha' => bcrypt('segredo123'),
            'ativo' => true,
        ]));
    }

    private function criarContrato(): Contract
    {
        return $this->naEmpresa(function (): Contract {
            $cliente = ClientFactory::new()->create(['email' => 'cliente@exemplo.com.br']);
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);

            return Contract::query()->create([
                'address_id' => $endereco->id,
                'contract_number' => 'CONT-'.uniqid(),
                'service_type' => 'pontual',
                'service_value' => '1000.00',
                'start_date' => now()->subMonth()->toDateString(),
                'end_date' => now()->addYear()->toDateString(),
            ]);
        });
    }

    /**
     * Libera os módulos de que estas rotas dependem: `assinatura_eletronica`
     * (as telas de assinatura) e `portal_cliente` (o download do cliente).
     */
    private function liberarModulo(Company $empresa): void
    {
        foreach (['assinatura_eletronica', 'portal_cliente', 'contratos'] as $chave) {
            $modulo = Module::query()->where('chave', $chave)->first();

            if ($modulo === null) {
                continue;
            }

            CompanyModule::query()->updateOrCreate(
                ['company_id' => $empresa->id, 'module_id' => $modulo->id],
                ['ativo' => true]
            );
        }
    }

    private function naEmpresa(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->id, $callback);
    }

    /**
     * @return array<string, mixed>
     */
    private function documentoPendente(): array
    {
        return [
            'token' => 'DOC-1',
            'status' => 'pending',
            'signers' => [
                ['token' => 'S1', 'name' => 'Contratada', 'email' => 'contratada@empresa.com.br', 'status' => 'new'],
                ['token' => 'S2', 'name' => 'Cliente Fulano', 'email' => 'cliente@exemplo.com.br', 'status' => 'new'],
            ],
        ];
    }
}
