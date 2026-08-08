<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\SignatureEvent;
use App\Models\SignatureProviderConfig;
use App\Models\SignatureRequest;
use App\Services\Signature\ProvedorPadrao;
use App\Support\TenantAtual;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 26.6 do Plano 26: webhook do provedor de assinatura —
 * `AssinaturaWebhookController`, `ProcessadorDeEventoDeAssinatura` e a
 * resolução do tenant por `SignatureProviderConfig::paraToken()`.
 *
 * É a superfície mais sensível do plano: um evento forjado marcando contrato
 * de outra empresa como assinado é fraude viabilizada pelo sistema. O teste
 * mais importante do arquivo é
 * `test_webhook_de_um_tenant_nao_alcanca_pedido_de_outro`, com dois tenants
 * reais e um identificador de documento que existe nos dois.
 *
 * O provedor nunca é chamado de verdade: `Http::fake()` responde a consulta
 * que o processamento faz depois de reconhecer o evento. É justamente essa
 * consulta que torna o webhook seguro — o corpo da requisição não decide nada.
 */
class AssinaturaWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://sandbox.api.zapsign.com.br';

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        Http::preventStrayRequests();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Caminho feliz e idempotência
    // -----------------------------------------------------------------

    public function test_evento_valido_atualiza_o_signatario_a_partir_da_consulta_ao_provedor(): void
    {
        $tenant = $this->cenario('Empresa A');
        $pedido = $this->pedido($tenant, 'DOC-A');

        $this->fakeConsulta('DOC-A', $this->documentoComClienteQueVisualizou());

        $resposta = $this->postJson($this->rota($tenant), $this->payload('doc_viewed', 'DOC-A'));

        $resposta->assertOk();

        $signatario = TenantAtual::comTenant(
            $tenant['empresa']->id,
            fn () => $pedido->signers()->where('papel', 'contratante')->first()
        );

        $this->assertSame('visualizou', $signatario->situacao);
        $this->assertSame(
            'visualizado',
            TenantAtual::comTenant($tenant['empresa']->id, fn () => $pedido->fresh()->situacao)
        );
    }

    public function test_o_mesmo_evento_duas_vezes_processa_uma_vez(): void
    {
        $tenant = $this->cenario('Empresa A');
        $this->pedido($tenant, 'DOC-A');

        $this->fakeConsulta('DOC-A', $this->documentoComClienteQueVisualizou());

        $this->postJson($this->rota($tenant), $this->payload('doc_viewed', 'DOC-A'))->assertOk();
        $this->postJson($this->rota($tenant), $this->payload('doc_viewed', 'DOC-A'))
            ->assertOk()
            ->assertJsonPath('message', 'Evento já processado.');

        $this->assertSame(
            1,
            TenantAtual::comTenant($tenant['empresa']->id, fn (): int => SignatureEvent::query()->count())
        );

        // A segunda entrega não chega a consultar o provedor: sai na barreira
        // de `processado_em`.
        Http::assertSentCount(1);
    }

    /**
     * O teste que mais importa deste arquivo.
     *
     * Os dois tenants têm um pedido com o **mesmo** `provedor_documento_id`,
     * situação perfeitamente possível (o identificador é do provedor, não
     * nosso). O webhook chega com o token do tenant A. Nada do tenant B pode
     * mudar — nem o pedido, nem o contrato, nem o signatário.
     */
    public function test_webhook_de_um_tenant_nao_alcanca_pedido_de_outro(): void
    {
        $tenantA = $this->cenario('Empresa A');
        $tenantB = $this->cenario('Empresa B');

        $pedidoA = $this->pedido($tenantA, 'DOC-COMPARTILHADO');
        $pedidoB = $this->pedido($tenantB, 'DOC-COMPARTILHADO');

        $this->fakeConsulta('DOC-COMPARTILHADO', $this->documentoComClienteQueVisualizou());

        $this->postJson($this->rota($tenantA), $this->payload('doc_viewed', 'DOC-COMPARTILHADO'))->assertOk();

        $this->assertSame(
            'visualizado',
            TenantAtual::comTenant($tenantA['empresa']->id, fn () => $pedidoA->fresh()->situacao)
        );

        // O tenant B não foi tocado por nada.
        $this->assertSame(
            'enviado',
            TenantAtual::comTenant($tenantB['empresa']->id, fn () => $pedidoB->fresh()->situacao)
        );
        $this->assertSame(
            'pendente',
            TenantAtual::comTenant(
                $tenantB['empresa']->id,
                fn () => $pedidoB->signers()->where('papel', 'contratante')->first()->situacao
            )
        );

        // O evento é do tenant do token, e de mais nenhum.
        $evento = SignatureEvent::query()->deTodasAsEmpresas()->firstOrFail();
        $this->assertSame($tenantA['empresa']->id, $evento->company_id);
        $this->assertSame($pedidoA->id, $evento->signature_request_id);
    }

    public function test_evento_de_documento_que_nao_e_desta_empresa_nao_altera_nada(): void
    {
        $tenant = $this->cenario('Empresa A');
        $pedido = $this->pedido($tenant, 'DOC-A');

        Http::fake();

        $this->postJson($this->rota($tenant), $this->payload('doc_signed', 'DOC-DE-OUTRO-LUGAR'))->assertOk();

        $this->assertSame(
            'enviado',
            TenantAtual::comTenant($tenant['empresa']->id, fn () => $pedido->fresh()->situacao)
        );

        // Nenhuma consulta ao provedor: sem pedido correspondente, não há o
        // que sincronizar.
        Http::assertNothingSent();

        $evento = SignatureEvent::query()->deTodasAsEmpresas()->firstOrFail();
        $this->assertNull($evento->signature_request_id);
        $this->assertNotNull($evento->processado_em);
    }

    // -----------------------------------------------------------------
    // Token e eventos desconhecidos
    // -----------------------------------------------------------------

    public function test_token_invalido_devolve_404_sem_gravar_nada(): void
    {
        $this->cenario('Empresa A');

        Http::fake();

        $this->postJson('/webhooks/assinatura/'.Str::random(40), $this->payload('doc_signed', 'DOC-A'))
            ->assertNotFound();

        $this->assertSame(0, SignatureEvent::query()->deTodasAsEmpresas()->count());
        Http::assertNothingSent();
    }

    public function test_evento_de_tipo_desconhecido_e_gravado_sem_erro(): void
    {
        $tenant = $this->cenario('Empresa A');
        $this->pedido($tenant, 'DOC-A');

        $this->fakeConsulta('DOC-A', $this->documentoPendente());

        $this->postJson($this->rota($tenant), $this->payload('evento_que_ninguem_conhece', 'DOC-A'))
            ->assertOk();

        $evento = SignatureEvent::query()->deTodasAsEmpresas()->firstOrFail();
        $this->assertSame('evento_que_ninguem_conhece', $evento->tipo);
        $this->assertNotNull($evento->processado_em);
        $this->assertNull($evento->erro);
    }

    public function test_segredo_de_webhook_configurado_passa_a_ser_exigido(): void
    {
        $tenant = $this->cenario('Empresa A', segredoDoWebhook: 'segredo-combinado');
        $this->pedido($tenant, 'DOC-A');

        Http::fake();

        $this->postJson($this->rota($tenant), $this->payload('doc_signed', 'DOC-A'))
            ->assertUnauthorized();

        // 401 não grava nem o payload: corpo não autenticado não entra na
        // tabela.
        $this->assertSame(0, SignatureEvent::query()->deTodasAsEmpresas()->count());

        $this->fakeConsulta('DOC-A', $this->documentoPendente());

        $this->withHeader('X-Zapsign-Secret', 'segredo-combinado')
            ->postJson($this->rota($tenant), $this->payload('doc_signed', 'DOC-A'))
            ->assertOk();
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * @return array{empresa: Company, config: SignatureProviderConfig, webhookToken: string}
     */
    private function cenario(string $nome, ?string $segredoDoWebhook = null): array
    {
        $empresa = Company::query()->where('name', $nome)->first() ?? Company::create(['name' => $nome]);
        $empresa->forceFill(['name' => $nome, 'email' => Str::slug($nome).'@exemplo.test'])->save();

        // Alfanumérico puro: casa com a restrição da rota
        // (`where('webhookToken', '[A-Za-z0-9]+')`).
        $webhookToken = Str::random(40);

        $credenciais = ['token' => 'tok-'.Str::slug($nome)];

        if ($segredoDoWebhook !== null) {
            $credenciais['webhook_secret'] = $segredoDoWebhook;
        }

        $config = TenantAtual::comTenant($empresa->id, fn (): SignatureProviderConfig => SignatureProviderConfig::create([
            'provedor' => ProvedorPadrao::NOME,
            'ambiente' => 'sandbox',
            'credenciais' => $credenciais,
            'webhook_token' => $webhookToken,
            'ativo' => true,
        ]));

        return ['empresa' => $empresa, 'config' => $config, 'webhookToken' => $webhookToken];
    }

    /**
     * Pedido `enviado`, com dois signatários pendentes, do tenant informado.
     *
     * @param  array{empresa: Company}  $tenant
     */
    private function pedido(array $tenant, string $documentoNoProvedor): SignatureRequest
    {
        return TenantAtual::comTenant($tenant['empresa']->id, function () use ($documentoNoProvedor): SignatureRequest {
            $cliente = ClientFactory::new()->create(['email' => 'cliente@exemplo.com.br']);
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);

            $contrato = Contract::query()->create([
                'address_id' => $endereco->id,
                'contract_number' => 'CONT-'.uniqid(),
                'service_type' => 'pontual',
                'service_value' => '1000.00',
                'start_date' => now()->subMonth()->toDateString(),
                'end_date' => now()->addYear()->toDateString(),
            ]);

            $contrato->forceFill(['situacao_assinatura' => 'em_assinatura'])->save();

            $pedido = SignatureRequest::create([
                'contract_id' => $contrato->id,
                'provedor' => ProvedorPadrao::NOME,
                'provedor_documento_id' => $documentoNoProvedor,
                'situacao' => 'enviado',
                'enviado_em' => now()->subDay(),
                'expira_em' => now()->addDays(10)->toDateString(),
            ]);

            $pedido->signers()->create([
                'nome' => 'Contratada',
                'email' => 'contratada@empresa.com.br',
                'papel' => 'contratada',
                'ordem' => 1,
                'situacao' => 'pendente',
            ]);

            $pedido->signers()->create([
                'nome' => 'Cliente Fulano',
                'email' => 'cliente@exemplo.com.br',
                'papel' => 'contratante',
                'ordem' => 2,
                'situacao' => 'pendente',
            ]);

            return $pedido->fresh();
        });
    }

    /**
     * @param  array{webhookToken: string}  $tenant
     */
    private function rota(array $tenant): string
    {
        return '/webhooks/assinatura/'.$tenant['webhookToken'];
    }

    /**
     * @param  array<string, mixed>  $documento
     */
    private function fakeConsulta(string $documentoNoProvedor, array $documento): void
    {
        Http::fake([
            self::HOST.'/api/v1/docs/'.$documentoNoProvedor.'/' => Http::response($documento, 200),
        ]);
    }

    /**
     * Corpo do webhook. Repare que ele não carrega situação de signatário
     * nenhuma que o sistema use: só o tipo do evento e o documento.
     *
     * @return array<string, mixed>
     */
    private function payload(string $tipo, string $documentoNoProvedor): array
    {
        return [
            'event_type' => $tipo,
            'token' => $documentoNoProvedor,
            'status' => 'pending',
            'name' => 'Contrato',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentoPendente(): array
    {
        return [
            'token' => 'DOC-A',
            'status' => 'pending',
            'signers' => [
                ['token' => 'S1', 'name' => 'Contratada', 'email' => 'contratada@empresa.com.br', 'status' => 'new'],
                ['token' => 'S2', 'name' => 'Cliente Fulano', 'email' => 'cliente@exemplo.com.br', 'status' => 'new'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentoComClienteQueVisualizou(): array
    {
        $documento = $this->documentoPendente();
        $documento['signers'][1]['status'] = 'link-opened';

        return $documento;
    }
}
