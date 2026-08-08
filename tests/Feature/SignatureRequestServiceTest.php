<?php

namespace Tests\Feature;

use App\Exceptions\ContratoEmAssinaturaException;
use App\Models\Company;
use App\Models\Contract;
use App\Models\NotificationQueue;
use App\Models\SignatureEvent;
use App\Models\SignatureProviderConfig;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Services\ContractService;
use App\Services\Signature\ProvedorPadrao;
use App\Services\SignatureRequestService;
use App\Support\EventosDeNotificacao;
use App\Support\TenantAtual;
use Closure;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 26.3 do Plano 26: envio do contrato para assinatura, acompanhamento da
 * situação e arquivamento do documento assinado.
 *
 * Nenhum teste toca a rede: todo tráfego passa por `Http::fake()` com
 * `preventStrayRequests()`, e o disco é falso (`Storage::fake`).
 *
 * O que está coberto, um bloco por critério de aceitação da task:
 *
 * - Enviar cria o pedido, os signatários e marca o contrato.
 * - Contrato em assinatura não aceita edição nem exclusão, e não aceita um
 *   segundo pedido.
 * - Todos assinados baixa o arquivo, marca o contrato e avisa as duas pontas.
 * - Assinatura parcial **não** marca o contrato como assinado.
 * - Recusa marca e avisa com o motivo.
 * - Reenviar notifica o mesmo documento, sem criar outro.
 * - Cancelar devolve o contrato ao estado editável.
 * - A sincronização recupera um pedido cujo webhook se perdeu.
 * - Pedido pendente há mais de cinco dias gera o aviso semanal, uma vez por
 *   semana.
 * - Falha do provedor no envio não deixa contrato marcado nem rascunho órfão.
 */
class SignatureRequestServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://sandbox.api.zapsign.com.br';

    private Company $empresa;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        Http::preventStrayRequests();
        Storage::fake(SignatureRequestService::DISCO);

        // A empresa 1 vem da migration de fundação sem nome nem e-mail: sem
        // e-mail, o aviso à empresa cairia em "sem_destino".
        Company::query()->whereKey(1)->update([
            'name' => 'Dedetizadora A',
            'email' => 'contato@a.test',
        ]);

        $this->empresa = Company::query()->findOrFail(1);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Envio
    // -----------------------------------------------------------------

    public function test_envio_cria_pedido_signatarios_arquivo_original_e_marca_o_contrato(): void
    {
        $this->fakeDoProvedor(['docs' => $this->documentoPendente()]);

        $contrato = $this->criarContrato();
        $this->configuracao();

        $pedido = $this->naEmpresa(fn (): SignatureRequest => $this->service()->enviar(
            $contrato,
            $this->signatarios(),
            10,
            $this->usuario(),
        ));

        $this->assertSame('enviado', $pedido->situacao);
        $this->assertSame('DOC-1', $pedido->provedor_documento_id);
        $this->assertSame(ProvedorPadrao::NOME, $pedido->provedor);
        $this->assertNotNull($pedido->enviado_em);
        $this->assertCount(2, $pedido->signers()->get());

        // O arquivo original prova o que foi enviado, e fica em disco privado.
        Storage::disk(SignatureRequestService::DISCO)->assertExists($pedido->arquivo_original_path);
        $this->assertStringStartsWith(
            'contratos/assinatura/'.$this->empresa->id.'/',
            (string) $pedido->arquivo_original_path
        );

        $this->assertSame('em_assinatura', $contrato->fresh()->situacao_assinatura);

        $this->assertTrue($this->existeAviso(EventosDeNotificacao::CONTRATO_ENVIADO_PARA_ASSINATURA));
    }

    public function test_contrato_em_assinatura_nao_aceita_segundo_pedido(): void
    {
        $this->fakeDoProvedor(['docs' => $this->documentoPendente()]);

        $contrato = $this->criarContrato();
        $this->configuracao();

        $this->naEmpresa(fn (): SignatureRequest => $this->service()->enviar($contrato, $this->signatarios()));

        $this->expectException(ContratoEmAssinaturaException::class);
        $this->expectExceptionMessage('já tem um pedido de assinatura em aberto');

        $this->naEmpresa(fn (): SignatureRequest => $this->service()->enviar($contrato->fresh(), $this->signatarios()));
    }

    public function test_contrato_em_assinatura_nao_pode_ser_alterado_nem_excluido(): void
    {
        $contrato = $this->criarContrato();
        $this->naEmpresa(fn () => $contrato->forceFill(['situacao_assinatura' => 'em_assinatura'])->save());

        $servico = app(ContractService::class);

        try {
            $this->naEmpresa(fn () => $servico->atualizar($contrato->fresh(), ['service_value' => '9999.00']));
            $this->fail('Esperava ContratoEmAssinaturaException na edição.');
        } catch (ContratoEmAssinaturaException $excecao) {
            $this->assertStringContainsString('não pode ser alterado', $excecao->getMessage());
        }

        $this->expectException(ContratoEmAssinaturaException::class);
        $this->naEmpresa(fn () => $servico->excluir($contrato->fresh()));
    }

    public function test_falha_do_provedor_no_envio_nao_deixa_contrato_marcado_nem_rascunho(): void
    {
        Http::fake([
            self::HOST.'/api/v1/docs/' => Http::response(['email' => ['Enter a valid email address.']], 400),
        ]);

        $contrato = $this->criarContrato();
        $this->configuracao();

        try {
            $this->naEmpresa(fn (): SignatureRequest => $this->service()->enviar($contrato, $this->signatarios()));
            $this->fail('Esperava a recusa do provedor.');
        } catch (\App\Exceptions\AssinaturaEletronicaRecusouException) {
            // Esperado.
        }

        $this->assertSame('nao_enviado', $contrato->fresh()->situacao_assinatura);
        $this->assertSame(0, $this->naEmpresa(fn (): int => SignatureRequest::query()->count()));
    }

    // -----------------------------------------------------------------
    // Conclusão
    // -----------------------------------------------------------------

    public function test_todos_assinados_baixa_o_arquivo_marca_o_contrato_e_avisa_as_duas_pontas(): void
    {
        [$contrato, $pedido] = $this->contratoEnviado();

        $this->fakeDoProvedor([
            'detalhe' => $this->documentoAssinado(),
            'arquivo' => '%PDF-assinado',
        ]);

        $atualizado = $this->naEmpresa(fn (): SignatureRequest => $this->service()->sincronizar($pedido));

        $this->assertSame('assinado', $atualizado->situacao);
        $this->assertNotNull($atualizado->concluido_em);

        // O arquivo é baixado e guardado no ato: o link do provedor expira.
        Storage::disk(SignatureRequestService::DISCO)->assertExists($atualizado->arquivo_assinado_path);
        $this->assertSame(
            '%PDF-assinado',
            Storage::disk(SignatureRequestService::DISCO)->get($atualizado->arquivo_assinado_path)
        );

        $contrato->refresh();
        $this->assertSame('assinado', $contrato->situacao_assinatura);
        $this->assertNotNull($contrato->assinado_em);

        // Trilha de auditoria de cada signatário, vinda do provedor.
        $cliente = $this->naEmpresa(fn () => $atualizado->signers()->where('papel', 'contratante')->first());
        $this->assertSame('assinou', $cliente->situacao);
        $this->assertSame('201.17.42.9', $cliente->ip);
        $this->assertStringContainsString('Chrome', (string) $cliente->user_agent);
        $this->assertNotNull($cliente->assinado_em);

        $avisos = $this->naEmpresa(fn () => NotificationQueue::query()
            ->where('evento', EventosDeNotificacao::CONTRATO_ASSINADO)
            ->get());

        $this->assertCount(2, $avisos, 'O aviso de contrato assinado sai para o cliente e para a empresa.');
        $this->assertEqualsCanonicalizing(
            ['cliente', 'empresa'],
            $avisos->pluck('destinatario_tipo')->all()
        );
    }

    public function test_assinatura_parcial_nao_marca_o_contrato_como_assinado(): void
    {
        [$contrato, $pedido] = $this->contratoEnviado();

        $corpo = $this->documentoAssinado();
        $corpo['signers'][1]['status'] = 'link-opened';
        $corpo['signers'][1]['signed_at'] = null;

        $this->fakeDoProvedor(['detalhe' => $corpo]);

        $atualizado = $this->naEmpresa(fn (): SignatureRequest => $this->service()->sincronizar($pedido));

        $this->assertSame('visualizado', $atualizado->situacao);
        $this->assertNull($atualizado->arquivo_assinado_path);
        $this->assertSame('em_assinatura', $contrato->fresh()->situacao_assinatura);
    }

    public function test_recusa_marca_o_contrato_e_avisa_a_empresa_com_o_motivo(): void
    {
        [$contrato, $pedido] = $this->contratoEnviado();

        $corpo = $this->documentoPendente();
        $corpo['status'] = 'refused';
        $corpo['rejected_reason'] = 'Valor acima do orçado.';
        $corpo['signers'][1]['status'] = 'refused';

        $this->fakeDoProvedor(['detalhe' => $corpo]);

        $atualizado = $this->naEmpresa(fn (): SignatureRequest => $this->service()->sincronizar($pedido));

        $this->assertSame('recusado', $atualizado->situacao);
        $this->assertSame('Valor acima do orçado.', $atualizado->motivo_recusa);
        $this->assertSame('recusado', $contrato->fresh()->situacao_assinatura);

        $aviso = $this->naEmpresa(fn () => NotificationQueue::query()
            ->where('evento', EventosDeNotificacao::CONTRATO_RECUSADO)
            ->first());

        $this->assertNotNull($aviso);
        $this->assertSame('empresa', $aviso->destinatario_tipo);
        $this->assertStringContainsString('Valor acima do orçado.', (string) $aviso->corpo);
    }

    public function test_sincronizacao_duas_vezes_nao_baixa_o_arquivo_duas_vezes(): void
    {
        [, $pedido] = $this->contratoEnviado();

        $downloads = 0;

        Http::fake([
            self::HOST.'/api/v1/docs/DOC-1/' => Http::response($this->documentoAssinado(), 200),
            'https://arquivos.zapsign.test/assinado.pdf' => function () use (&$downloads) {
                $downloads++;

                return Http::response('%PDF-assinado', 200);
            },
        ]);

        $this->naEmpresa(fn (): SignatureRequest => $this->service()->sincronizar($pedido));
        $this->naEmpresa(fn (): SignatureRequest => $this->service()->sincronizar($pedido->fresh()));

        // A segunda passada nem chega a consultar o provedor: `sincronizar()`
        // sai na primeira linha porque o pedido já está encerrado. O arquivo
        // que vale é baixado uma vez só.
        $this->assertSame(1, $downloads);
    }

    // -----------------------------------------------------------------
    // Reenvio e cancelamento
    // -----------------------------------------------------------------

    public function test_reenvio_notifica_o_mesmo_documento_sem_criar_pedido_novo(): void
    {
        [$contrato, $pedido] = $this->contratoEnviado();

        $this->fakeDoProvedor([
            'detalhe' => $this->documentoPendente(),
            'notificar' => ['success' => true],
        ]);

        $this->naEmpresa(fn (): SignatureRequest => $this->service()->reenviar($pedido));

        $this->assertSame(
            1,
            $this->naEmpresa(fn (): int => SignatureRequest::query()->where('contract_id', $contrato->id)->count())
        );
    }

    public function test_cancelamento_devolve_o_contrato_ao_estado_editavel(): void
    {
        [$contrato, $pedido] = $this->contratoEnviado();

        $this->fakeDoProvedor(['cancelar' => ['success' => true]]);

        $atualizado = $this->naEmpresa(fn (): SignatureRequest => $this->service()->cancelar($pedido, 'Renegociado.'));

        $this->assertSame('cancelado', $atualizado->situacao);
        $this->assertSame('Renegociado.', $atualizado->motivo_recusa);
        // `nao_enviado`, e não `recusado`: cancelar é decisão da empresa.
        $this->assertSame('nao_enviado', $contrato->fresh()->situacao_assinatura);
    }

    public function test_pedido_ja_encerrado_nao_aceita_reenvio_nem_cancelamento(): void
    {
        [, $pedido] = $this->contratoEnviado();
        $this->naEmpresa(fn () => $pedido->forceFill(['situacao' => 'assinado'])->save());

        $this->expectException(ContratoEmAssinaturaException::class);

        $this->naEmpresa(fn (): SignatureRequest => $this->service()->reenviar($pedido->fresh()));
    }

    // -----------------------------------------------------------------
    // Rotina de sincronização e aviso de pendência
    // -----------------------------------------------------------------

    public function test_rotina_recupera_pedido_cujo_webhook_se_perdeu(): void
    {
        [$contrato, ] = $this->contratoEnviado();

        // Nenhum evento chegou: a tabela de eventos está vazia, e mesmo assim
        // a rotina precisa fechar o contrato.
        $this->assertSame(0, $this->naEmpresa(fn (): int => SignatureEvent::query()->count()));

        $this->fakeDoProvedor([
            'detalhe' => $this->documentoAssinado(),
            'arquivo' => '%PDF-assinado',
        ]);

        $this->artisan('assinaturas:sincronizar')->assertSuccessful();

        $this->assertSame('assinado', $contrato->fresh()->situacao_assinatura);
    }

    public function test_pedido_pendente_ha_seis_dias_gera_um_aviso_por_semana(): void
    {
        [, $pedido] = $this->contratoEnviado();

        $this->naEmpresa(fn () => $pedido->forceFill([
            'enviado_em' => now()->subDays(6),
        ])->save());

        $this->fakeDoProvedor(['detalhe' => $this->documentoPendente()]);

        $this->artisan('assinaturas:sincronizar')->assertSuccessful();
        $this->artisan('assinaturas:sincronizar')->assertSuccessful();

        $avisos = $this->naEmpresa(fn (): int => NotificationQueue::query()
            ->where('evento', EventosDeNotificacao::CONTRATO_PENDENTE_DE_ASSINATURA)
            ->count());

        $this->assertSame(1, $avisos, 'O aviso de pendência é semanal, não uma vez por passada.');
    }

    public function test_pedido_enviado_ha_dois_dias_ainda_nao_gera_aviso_de_pendencia(): void
    {
        [, $pedido] = $this->contratoEnviado();

        $this->naEmpresa(fn () => $pedido->forceFill(['enviado_em' => now()->subDays(2)])->save());

        $this->fakeDoProvedor(['detalhe' => $this->documentoPendente()]);

        $this->artisan('assinaturas:sincronizar')->assertSuccessful();

        $this->assertFalse($this->existeAviso(EventosDeNotificacao::CONTRATO_PENDENTE_DE_ASSINATURA));
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function service(): SignatureRequestService
    {
        return app(SignatureRequestService::class);
    }

    /**
     * @return array{0: Contract, 1: SignatureRequest}
     */
    private function contratoEnviado(): array
    {
        $this->fakeDoProvedor(['docs' => $this->documentoPendente()]);

        $contrato = $this->criarContrato();
        $this->configuracao();

        $pedido = $this->naEmpresa(fn (): SignatureRequest => $this->service()->enviar(
            $contrato,
            $this->signatarios(),
            10,
            $this->usuario(),
        ));

        return [$contrato->fresh(), $pedido];
    }

    /**
     * @param  array<string, mixed>  $respostas
     */
    private function fakeDoProvedor(array $respostas): void
    {
        $mapa = [];

        if (isset($respostas['docs'])) {
            $mapa[self::HOST.'/api/v1/docs/'] = Http::response($respostas['docs'], 200);
        }

        if (isset($respostas['detalhe'])) {
            $mapa[self::HOST.'/api/v1/docs/DOC-1/'] = Http::response($respostas['detalhe'], 200);
        }

        if (isset($respostas['notificar'])) {
            $mapa[self::HOST.'/api/v1/signers/notify/'] = Http::response($respostas['notificar'], 200);
        }

        if (isset($respostas['cancelar'])) {
            $mapa[self::HOST.'/api/v1/refuse/'] = Http::response($respostas['cancelar'], 200);
        }

        if (isset($respostas['arquivo'])) {
            $mapa['https://arquivos.zapsign.test/assinado.pdf'] = Http::response($respostas['arquivo'], 200);
        }

        Http::fake($mapa);
    }

    /**
     * @return array<int, array{nome: string, email: string, papel: string, ordem: int}>
     */
    private function signatarios(): array
    {
        return [
            ['nome' => 'Dedetizadora A', 'email' => 'contratada@empresa.com.br', 'papel' => 'contratada', 'ordem' => 1],
            ['nome' => 'Cliente Fulano', 'email' => 'cliente@exemplo.com.br', 'papel' => 'contratante', 'ordem' => 2],
        ];
    }

    private function configuracao(): SignatureProviderConfig
    {
        return $this->naEmpresa(fn (): SignatureProviderConfig => SignatureProviderConfig::create([
            'provedor' => ProvedorPadrao::NOME,
            'ambiente' => 'sandbox',
            'credenciais' => ['token' => 'tok-a'],
            'webhook_token' => Str::random(40),
            'ativo' => true,
        ]));
    }

    private function usuario(): User
    {
        return $this->naEmpresa(fn (): User => User::query()->create([
            'name' => 'Operador',
            'email' => 'operador@a.test',
            'password' => bcrypt('segredo123'),
        ]));
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function criarContrato(array $atributos = []): Contract
    {
        return $this->naEmpresa(function () use ($atributos): Contract {
            $cliente = ClientFactory::new()->create(['email' => 'cliente@exemplo.com.br']);
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);

            return Contract::query()->create(array_merge([
                'address_id' => $endereco->id,
                'contract_number' => 'CONT-'.uniqid(),
                'service_type' => 'pontual',
                'service_value' => '1000.00',
                'start_date' => now()->subYear()->toDateString(),
                'end_date' => now()->addYear()->toDateString(),
            ], $atributos));
        });
    }

    private function existeAviso(string $evento): bool
    {
        return $this->naEmpresa(fn (): bool => NotificationQueue::query()->where('evento', $evento)->exists());
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
            'original_file' => 'https://arquivos.zapsign.test/original.pdf',
            'signed_file' => null,
            'signers' => [
                ['token' => 'S1', 'name' => 'Dedetizadora A', 'email' => 'contratada@empresa.com.br', 'status' => 'new'],
                ['token' => 'S2', 'name' => 'Cliente Fulano', 'email' => 'cliente@exemplo.com.br', 'status' => 'new'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentoAssinado(): array
    {
        return [
            'token' => 'DOC-1',
            'status' => 'signed',
            'original_file' => 'https://arquivos.zapsign.test/original.pdf',
            'signed_file' => 'https://arquivos.zapsign.test/assinado.pdf',
            'signers' => [
                [
                    'token' => 'S1',
                    'name' => 'Dedetizadora A',
                    'email' => 'contratada@empresa.com.br',
                    'status' => 'signed',
                    'signed_at' => '2026-08-06T16:10:00.000Z',
                    'ip' => '200.10.10.1',
                    'user_agent' => 'Mozilla/5.0 (Macintosh) Safari/605',
                ],
                [
                    'token' => 'S2',
                    'name' => 'Cliente Fulano',
                    'email' => 'cliente@exemplo.com.br',
                    'status' => 'signed',
                    'signed_at' => '2026-08-06T17:32:10.000Z',
                    'ip' => '201.17.42.9',
                    'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/128.0',
                ],
            ],
        ];
    }
}
