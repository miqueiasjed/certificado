<?php

namespace Tests\Feature;

use App\Exceptions\AssinaturaEletronicaArquivoGrandeDemaisException;
use App\Exceptions\AssinaturaEletronicaCredencialInvalidaException;
use App\Exceptions\AssinaturaEletronicaDocumentoJaAssinadoException;
use App\Exceptions\AssinaturaEletronicaIndisponivelException;
use App\Exceptions\AssinaturaEletronicaPrazoExpiradoException;
use App\Exceptions\AssinaturaEletronicaSignatarioInvalidoException;
use App\Exceptions\ProvedorDeAssinaturaNaoConfiguradoException;
use App\Models\Company;
use App\Models\SignatureProviderConfig;
use App\Services\Signature\ProvedorDeAssinatura;
use App\Services\Signature\ProvedorPadrao;
use App\Services\Signature\ResolvedorDeProvedor;
use App\Support\Signature\DocumentoNoProvedor;
use App\Support\Signature\SignatarioNoProvedor;
use App\Support\Signature\SignatarioParaEnvio;
use App\Support\TenantAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as RequisicaoHttp;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 26.2 do Plano 26: `ProvedorDeAssinatura`, `ProvedorPadrao` e
 * `ResolvedorDeProvedor` — o provedor de assinatura eletrônica atrás de uma
 * interface própria, com a credencial do tenant a cada chamada.
 *
 * Nenhum teste toca a rede: todo tráfego passa por `Http::fake()` com
 * `preventStrayRequests()`. Além do caminho feliz de envio, consulta,
 * reenvio, cancelamento e download, o que está coberto é:
 *
 * - **Sem estado de tenant na instância**: a mesma instância atende duas
 *   empresas em sequência, cada uma com o próprio token.
 * - **`ResolvedorDeProvedor` resolve pela empresa recebida**, e não pelo
 *   tenant corrente da sessão — inclusive quando os dois divergem.
 * - **Assinatura parcial não é contrato**: `status: signed` do provedor com um
 *   signatário ainda pendente **não** vira documento assinado.
 * - **Os seis erros previstos** têm exceções e mensagens distintas.
 * - **Recusa 4xx nunca é repetida**; rede e 5xx são.
 * - **Credencial nunca aparece em log.**
 */
class ProvedorDeAssinaturaTest extends TestCase
{
    use RefreshDatabase;

    private const HOST_SANDBOX = 'https://sandbox.api.zapsign.com.br';

    private const HOST_PRODUCAO = 'https://api.zapsign.com.br';

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Envio, consulta, reenvio, cancelamento e download
    // -----------------------------------------------------------------

    public function test_envia_contrato_com_pdf_signatarios_e_prazo(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/api/v1/docs/' => Http::response($this->documentoPendente(), 200),
        ]);

        $configuracao = $this->configuracao($this->empresa(), 'tok-empresa-a');

        $documento = $this->provedor()->enviar(
            $configuracao,
            'Contrato CONT-000042',
            '%PDF-1.4 conteudo do contrato',
            [
                new SignatarioParaEnvio('Dedetizadora Teste', 'contratada@empresa.com.br', 'contratada', 1),
                new SignatarioParaEnvio('Cliente Fulano', 'cliente@exemplo.com.br', 'contratante', 2),
            ],
            '2026-09-30',
            'Segue o contrato para assinatura.',
            'signature-request-7',
        );

        $this->assertSame('DOC-TOKEN-1', $documento->idNoProvedor);
        $this->assertSame(DocumentoNoProvedor::SITUACAO_EM_ANDAMENTO, $documento->situacao);
        $this->assertCount(2, $documento->signatarios);
        $this->assertFalse($documento->todosAssinaram());

        Http::assertSent(function (RequisicaoHttp $requisicao): bool {
            $corpo = $requisicao->data();

            return $requisicao->url() === self::HOST_SANDBOX.'/api/v1/docs/'
                && $corpo['name'] === 'Contrato CONT-000042'
                // O PDF vai em base64, e quem chamou nunca codificou nada.
                && base64_decode($corpo['base64_pdf'], true) === '%PDF-1.4 conteudo do contrato'
                && $corpo['date_limit_to_sign'] === '2026-09-30'
                && $corpo['external_id'] === 'signature-request-7'
                && $corpo['signature_order_active'] === true
                && $corpo['allow_refuse_signature'] === true
                && count($corpo['signers']) === 2
                && $corpo['signers'][0]['email'] === 'contratada@empresa.com.br'
                && $corpo['signers'][0]['order_group'] === 1
                && $corpo['signers'][1]['order_group'] === 2
                && $corpo['signers'][1]['custom_message'] === 'Segue o contrato para assinatura.'
                && $requisicao->hasHeader('Authorization', 'Bearer tok-empresa-a');
        });
    }

    public function test_consulta_traz_situacao_e_trilha_de_auditoria_de_cada_signatario(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/api/v1/docs/DOC-TOKEN-1/' => Http::response($this->documentoAssinado(), 200),
        ]);

        $documento = $this->provedor()->consultar(
            $this->configuracao($this->empresa(), 'tok-a'),
            'DOC-TOKEN-1'
        );

        $this->assertSame(DocumentoNoProvedor::SITUACAO_ASSINADO, $documento->situacao);
        $this->assertTrue($documento->todosAssinaram());
        $this->assertSame('https://arquivos.zapsign.test/assinado.pdf', $documento->urlDoArquivoAssinado);

        $cliente = $documento->signatarios[1];
        $this->assertSame('cliente@exemplo.com.br', $cliente->emailNoProvedor);
        $this->assertSame(SignatarioNoProvedor::SITUACAO_ASSINOU, $cliente->situacao);
        // Trilha de auditoria: é o que dá valor jurídico à assinatura a
        // distância, e vem do provedor, nunca da requisição de quem olha.
        $this->assertSame('201.17.42.9', $cliente->ip);
        $this->assertStringContainsString('Chrome', (string) $cliente->userAgent);
        $this->assertNotNull($cliente->assinadoEm);
        $this->assertSame('2026-08-06 14:32:10', $cliente->assinadoEm->format('Y-m-d H:i:s'));
    }

    public function test_documento_assinado_pelo_provedor_com_signatario_pendente_nao_vira_assinado(): void
    {
        // Assinatura parcial não é contrato: o estado agregado do provedor não
        // pode, sozinho, arquivar como assinado um documento que uma das
        // partes ainda não assinou.
        $corpo = $this->documentoAssinado();
        $corpo['signers'][1]['status'] = 'link-opened';
        $corpo['signers'][1]['signed_at'] = null;

        Http::fake([
            self::HOST_SANDBOX.'/api/v1/docs/DOC-TOKEN-1/' => Http::response($corpo, 200),
        ]);

        $documento = $this->provedor()->consultar($this->configuracao($this->empresa(), 'tok-a'), 'DOC-TOKEN-1');

        $this->assertSame(DocumentoNoProvedor::SITUACAO_EM_ANDAMENTO, $documento->situacao);
        $this->assertFalse($documento->todosAssinaram());
        $this->assertTrue($documento->algumVisualizou());
    }

    public function test_reenvio_notifica_o_mesmo_documento_sem_criar_outro(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/api/v1/docs/DOC-TOKEN-1/' => Http::response($this->documentoPendente(), 200),
            self::HOST_SANDBOX.'/api/v1/signers/notify/' => Http::response(['success' => true], 200),
        ]);

        $documento = $this->provedor()->reenviar($this->configuracao($this->empresa(), 'tok-a'), 'DOC-TOKEN-1');

        $this->assertSame('DOC-TOKEN-1', $documento->idNoProvedor);

        // Nenhuma chamada de criação: dois documentos abertos do mesmo
        // contrato gerariam duas assinaturas válidas.
        Http::assertNotSent(fn (RequisicaoHttp $requisicao): bool => $requisicao->method() === 'POST'
            && $requisicao->url() === self::HOST_SANDBOX.'/api/v1/docs/');

        Http::assertSent(fn (RequisicaoHttp $requisicao): bool => $requisicao->url() === self::HOST_SANDBOX.'/api/v1/signers/notify/'
            && $requisicao->data()['doc_token'] === 'DOC-TOKEN-1');
    }

    public function test_reenvio_de_documento_ja_assinado_e_recusado(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/api/v1/docs/DOC-TOKEN-1/' => Http::response($this->documentoAssinado(), 200),
        ]);

        $this->expectException(AssinaturaEletronicaDocumentoJaAssinadoException::class);

        $this->provedor()->reenviar($this->configuracao($this->empresa(), 'tok-a'), 'DOC-TOKEN-1');
    }

    public function test_reenvio_de_documento_expirado_e_recusado(): void
    {
        $corpo = $this->documentoPendente();
        $corpo['status'] = 'expired';

        Http::fake([
            self::HOST_SANDBOX.'/api/v1/docs/DOC-TOKEN-1/' => Http::response($corpo, 200),
        ]);

        $this->expectException(AssinaturaEletronicaPrazoExpiradoException::class);

        $this->provedor()->reenviar($this->configuracao($this->empresa(), 'tok-a'), 'DOC-TOKEN-1');
    }

    public function test_cancelamento_envia_o_motivo_ao_provedor(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/api/v1/refuse/' => Http::response(['success' => true], 200),
        ]);

        $this->provedor()->cancelar(
            $this->configuracao($this->empresa(), 'tok-a'),
            'DOC-TOKEN-1',
            'Contrato renegociado.'
        );

        Http::assertSent(fn (RequisicaoHttp $requisicao): bool => $requisicao->url() === self::HOST_SANDBOX.'/api/v1/refuse/'
            && $requisicao->data()['doc_token'] === 'DOC-TOKEN-1'
            && $requisicao->data()['rejected_reason'] === 'Contrato renegociado.');
    }

    public function test_baixa_o_pdf_assinado_sem_mandar_o_token_do_tenant_para_o_armazenamento(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/api/v1/docs/DOC-TOKEN-1/' => Http::response($this->documentoAssinado(), 200),
            'https://arquivos.zapsign.test/assinado.pdf' => Http::response('%PDF-assinado', 200),
        ]);

        $conteudo = $this->provedor()->baixarAssinado($this->configuracao($this->empresa(), 'tok-a'), 'DOC-TOKEN-1');

        $this->assertSame('%PDF-assinado', $conteudo);

        // O link do arquivo aponta para o armazenamento do provedor, não para
        // a API: mandar o token do tenant para lá seria vazar credencial.
        Http::assertSent(fn (RequisicaoHttp $requisicao): bool => $requisicao->url() === 'https://arquivos.zapsign.test/assinado.pdf'
            && ! $requisicao->hasHeader('Authorization'));
    }

    // -----------------------------------------------------------------
    // Credencial por tenant, sem estado na instância
    // -----------------------------------------------------------------

    public function test_mesma_instancia_atende_dois_tenants_com_credenciais_diferentes(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/api/v1/docs/DOC-TOKEN-1/' => Http::response($this->documentoPendente(), 200),
        ]);

        $provedor = $this->provedor();
        $primeira = $this->configuracao($this->empresa('Empresa A'), 'tok-empresa-a');
        $segunda = $this->configuracao($this->empresa('Empresa B'), 'tok-empresa-b');

        $provedor->consultar($primeira, 'DOC-TOKEN-1');
        $provedor->consultar($segunda, 'DOC-TOKEN-1');

        Http::assertSent(fn (RequisicaoHttp $requisicao): bool => $requisicao->hasHeader('Authorization', 'Bearer tok-empresa-a'));
        Http::assertSent(fn (RequisicaoHttp $requisicao): bool => $requisicao->hasHeader('Authorization', 'Bearer tok-empresa-b'));
    }

    public function test_ambiente_do_tenant_decide_o_host(): void
    {
        Http::fake([
            self::HOST_PRODUCAO.'/api/v1/docs/DOC-TOKEN-1/' => Http::response($this->documentoPendente(), 200),
        ]);

        $configuracao = $this->configuracao($this->empresa(), 'tok-a', 'producao');

        $this->provedor()->consultar($configuracao, 'DOC-TOKEN-1');

        Http::assertSent(fn (RequisicaoHttp $requisicao): bool => str_starts_with($requisicao->url(), self::HOST_PRODUCAO));
    }

    public function test_resolvedor_devolve_a_configuracao_da_empresa_recebida_mesmo_com_outro_tenant_corrente(): void
    {
        $primeira = $this->empresa('Empresa A');
        $segunda = $this->empresa('Empresa B');

        $this->configuracao($primeira, 'tok-empresa-a');
        $configuracaoB = $this->configuracao($segunda, 'tok-empresa-b');

        // Cenário da rotina em lote: o tenant corrente é a empresa A, mas a
        // pergunta é sobre a empresa B.
        $resolvida = TenantAtual::comTenant(
            $primeira->id,
            fn (): SignatureProviderConfig => app(ResolvedorDeProvedor::class)->configuracaoAtiva($segunda)
        );

        $this->assertSame($configuracaoB->id, $resolvida->id);
        $this->assertSame($segunda->id, $resolvida->company_id);
    }

    public function test_empresa_sem_provedor_ativo_recebe_mensagem_clara(): void
    {
        $empresa = $this->empresa('Sem Integração');
        $this->configuracao($empresa, 'tok-a', 'sandbox', ativo: false);

        $this->expectException(ProvedorDeAssinaturaNaoConfiguradoException::class);
        $this->expectExceptionMessage('não tem nenhum provedor de assinatura eletrônica configurado e ativo');

        app(ResolvedorDeProvedor::class)->para($empresa);
    }

    public function test_configuracao_sem_token_e_recusada_antes_da_rede(): void
    {
        Http::fake();

        $empresa = $this->empresa();
        $configuracao = TenantAtual::comTenant($empresa->id, fn (): SignatureProviderConfig => SignatureProviderConfig::create([
            'provedor' => ProvedorPadrao::NOME,
            'ambiente' => 'sandbox',
            'credenciais' => [],
            'webhook_token' => Str::random(40),
            'ativo' => true,
        ]));

        $this->expectException(AssinaturaEletronicaCredencialInvalidaException::class);
        $this->expectExceptionMessage('Nenhuma credencial configurada');

        $this->provedor()->consultar($configuracao, 'DOC-TOKEN-1');

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Erros traduzidos
    // -----------------------------------------------------------------

    public function test_credencial_recusada_pelo_provedor_vira_excecao_propria(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/api/v1/docs/DOC-TOKEN-1/' => Http::response(['detail' => 'Invalid token.'], 401),
        ]);

        $this->expectException(AssinaturaEletronicaCredencialInvalidaException::class);
        $this->expectExceptionMessage('recusou a credencial');

        $this->provedor()->consultar($this->configuracao($this->empresa(), 'tok-errado'), 'DOC-TOKEN-1');
    }

    public function test_email_de_signatario_recusado_vira_excecao_propria_e_nao_e_repetido(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/api/v1/docs/' => Http::response(['email' => ['Enter a valid email address.']], 400),
        ]);

        try {
            $this->provedor()->enviar(
                $this->configuracao($this->empresa(), 'tok-a'),
                'Contrato CONT-000042',
                '%PDF',
                [new SignatarioParaEnvio('Cliente', 'cliente@exemplo.com.br', 'contratante')],
                '2026-09-30',
            );

            $this->fail('Esperava AssinaturaEletronicaSignatarioInvalidoException.');
        } catch (AssinaturaEletronicaSignatarioInvalidoException $excecao) {
            $this->assertStringContainsString('recusou um signatário', $excecao->getMessage());
        }

        // Recusa 4xx nunca é repetida: uma segunda tentativa aceita geraria um
        // segundo documento válido do mesmo contrato.
        Http::assertSentCount(1);
    }

    public function test_email_invalido_e_barrado_antes_da_rede(): void
    {
        Http::fake();

        $this->expectException(AssinaturaEletronicaSignatarioInvalidoException::class);
        $this->expectExceptionMessage('não é um endereço válido');

        new SignatarioParaEnvio('Cliente Fulano', 'cliente-sem-arroba', 'contratante');
    }

    public function test_arquivo_acima_do_limite_e_barrado_antes_da_rede(): void
    {
        Http::fake();

        $this->expectException(AssinaturaEletronicaArquivoGrandeDemaisException::class);
        $this->expectExceptionMessage('aceita no máximo');

        $this->provedor()->enviar(
            $this->configuracao($this->empresa(), 'tok-a'),
            'Contrato grande',
            str_repeat('A', 11 * 1048576),
            [new SignatarioParaEnvio('Cliente', 'cliente@exemplo.com.br', 'contratante')],
            '2026-09-30',
        );

        Http::assertNothingSent();
    }

    public function test_arquivo_recusado_por_tamanho_pelo_provedor_vira_excecao_propria(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/api/v1/docs/' => Http::response(['detail' => 'File too large.'], 413),
        ]);

        $this->expectException(AssinaturaEletronicaArquivoGrandeDemaisException::class);

        $this->provedor()->enviar(
            $this->configuracao($this->empresa(), 'tok-a'),
            'Contrato',
            '%PDF',
            [new SignatarioParaEnvio('Cliente', 'cliente@exemplo.com.br', 'contratante')],
            '2026-09-30',
        );
    }

    public function test_prazo_expirado_informado_pelo_provedor_vira_excecao_propria(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/api/v1/refuse/' => Http::response(['detail' => 'Document expired.'], 400),
        ]);

        $this->expectException(AssinaturaEletronicaPrazoExpiradoException::class);

        $this->provedor()->cancelar($this->configuracao($this->empresa(), 'tok-a'), 'DOC-TOKEN-1');
    }

    public function test_documento_ja_assinado_informado_pelo_provedor_vira_excecao_propria(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/api/v1/refuse/' => Http::response(['detail' => 'Document already signed.'], 400),
        ]);

        $this->expectException(AssinaturaEletronicaDocumentoJaAssinadoException::class);

        $this->provedor()->cancelar($this->configuracao($this->empresa(), 'tok-a'), 'DOC-TOKEN-1');
    }

    public function test_falha_de_rede_vira_indisponibilidade_e_e_repetida(): void
    {
        Http::fake(fn (): never => throw new ConnectionException('Connection timed out'));

        try {
            $this->provedor()->consultar($this->configuracao($this->empresa(), 'tok-a'), 'DOC-TOKEN-1');

            $this->fail('Esperava AssinaturaEletronicaIndisponivelException.');
        } catch (AssinaturaEletronicaIndisponivelException $excecao) {
            $this->assertStringContainsString('Não foi possível falar com o provedor', $excecao->getMessage());
        }

        // Duas tentativas no total: só aqui não se sabe se a operação
        // aconteceu do lado do provedor.
        Http::assertSentCount(2);
    }

    public function test_erro_do_servidor_vira_indisponibilidade(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/api/v1/docs/DOC-TOKEN-1/' => Http::response(['detail' => 'Server error'], 500),
        ]);

        $this->expectException(AssinaturaEletronicaIndisponivelException::class);

        $this->provedor()->consultar($this->configuracao($this->empresa(), 'tok-a'), 'DOC-TOKEN-1');
    }

    // -----------------------------------------------------------------
    // Validação da credencial e log
    // -----------------------------------------------------------------

    public function test_validar_credenciais_grava_verificado_em_quando_o_provedor_confirma(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/api/v1/docs*' => Http::response(['results' => []], 200),
        ]);

        $configuracao = $this->configuracao($this->empresa(), 'tok-a');
        $this->assertNull($configuracao->verificado_em);

        $valida = app(ResolvedorDeProvedor::class)->validar($configuracao);

        $this->assertTrue($valida);
        $this->assertNotNull($configuracao->fresh()->verificado_em);
    }

    public function test_validar_credenciais_devolve_falso_sem_gravar_quando_o_token_e_recusado(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/api/v1/docs*' => Http::response(['detail' => 'Invalid token.'], 401),
        ]);

        $configuracao = $this->configuracao($this->empresa(), 'tok-errado');

        $valida = app(ResolvedorDeProvedor::class)->validar($configuracao);

        $this->assertFalse($valida);
        $this->assertNull($configuracao->fresh()->verificado_em);
    }

    public function test_credencial_nunca_aparece_em_log(): void
    {
        Http::fake([
            self::HOST_SANDBOX.'/api/v1/docs/' => Http::response($this->documentoPendente(), 200),
            self::HOST_SANDBOX.'/api/v1/docs/DOC-TOKEN-1/' => Http::response(['detail' => 'Invalid token.'], 401),
        ]);

        $registrado = [];
        Log::listen(function (MessageLogged $evento) use (&$registrado): void {
            $registrado[] = $evento->message.' '.json_encode($evento->context);
        });

        $configuracao = $this->configuracao($this->empresa(), 'tok-super-secreto');

        $this->provedor()->enviar(
            $configuracao,
            'Contrato CONT-000042',
            '%PDF conteudo sigiloso do contrato',
            [new SignatarioParaEnvio('Cliente', 'cliente@exemplo.com.br', 'contratante')],
            '2026-09-30',
        );

        try {
            $this->provedor()->consultar($configuracao, 'DOC-TOKEN-1');
        } catch (AssinaturaEletronicaCredencialInvalidaException) {
            // Esperado: interessa o que foi registrado, não a exceção.
        }

        $this->assertNotEmpty($registrado, 'A integração precisa registrar as chamadas.');

        foreach ($registrado as $linha) {
            $this->assertStringNotContainsString('tok-super-secreto', $linha);
            $this->assertStringNotContainsString('sigiloso', $linha);
            $this->assertStringNotContainsString('cliente@exemplo.com.br', $linha);
        }
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function provedor(): ProvedorDeAssinatura
    {
        return app(ProvedorPadrao::class);
    }

    private function empresa(string $nome = 'Dedetizadora Teste'): Company
    {
        return Company::create(['name' => $nome]);
    }

    private function configuracao(
        Company $empresa,
        string $token,
        string $ambiente = 'sandbox',
        bool $ativo = true,
    ): SignatureProviderConfig {
        return TenantAtual::comTenant($empresa->id, fn (): SignatureProviderConfig => SignatureProviderConfig::create([
            'provedor' => ProvedorPadrao::NOME,
            'ambiente' => $ambiente,
            'credenciais' => ['token' => $token],
            'webhook_token' => Str::random(40),
            'ativo' => $ativo,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function documentoPendente(): array
    {
        return [
            'open_id' => 1,
            'token' => 'DOC-TOKEN-1',
            'status' => 'pending',
            'name' => 'Contrato CONT-000042',
            'original_file' => 'https://arquivos.zapsign.test/original.pdf',
            'signed_file' => null,
            'signers' => [
                [
                    'token' => 'SIG-1',
                    'name' => 'Dedetizadora Teste',
                    'email' => 'contratada@empresa.com.br',
                    'status' => 'new',
                    'sign_url' => 'https://app.zapsign.test/verificar/SIG-1',
                ],
                [
                    'token' => 'SIG-2',
                    'name' => 'Cliente Fulano',
                    'email' => 'cliente@exemplo.com.br',
                    'status' => 'new',
                    'sign_url' => 'https://app.zapsign.test/verificar/SIG-2',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentoAssinado(): array
    {
        return [
            'open_id' => 1,
            'token' => 'DOC-TOKEN-1',
            'status' => 'signed',
            'name' => 'Contrato CONT-000042',
            'original_file' => 'https://arquivos.zapsign.test/original.pdf',
            'signed_file' => 'https://arquivos.zapsign.test/assinado.pdf',
            'signers' => [
                [
                    'token' => 'SIG-1',
                    'name' => 'Dedetizadora Teste',
                    'email' => 'contratada@empresa.com.br',
                    'status' => 'signed',
                    'signed_at' => '2026-08-06T16:10:00.000Z',
                    'ip' => '200.10.10.1',
                    'user_agent' => 'Mozilla/5.0 (Macintosh) Safari/605',
                ],
                [
                    'token' => 'SIG-2',
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
