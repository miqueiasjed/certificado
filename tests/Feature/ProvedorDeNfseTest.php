<?php

namespace Tests\Feature;

use App\Exceptions\CancelamentoNaoEncontradoException;
use App\Exceptions\CodigoDeServicoNaoAceitoException;
use App\Exceptions\CredencialFiscalInvalidaException;
use App\Exceptions\DadoFiscalInvalidoException;
use App\Exceptions\DadosDoTomadorIncompletosException;
use App\Exceptions\FalhaFiscalException;
use App\Exceptions\NotaJaCanceladaException;
use App\Exceptions\PrazoDeCancelamentoExpiradoException;
use App\Exceptions\PrefeituraIndisponivelException;
use App\Models\Company;
use App\Models\FiscalConfig;
use App\Services\Fiscal\ProvedorDeNfse;
use App\Services\Fiscal\ProvedorPadrao;
use App\Services\Fiscal\ResolvedorDeProvedor;
use App\Support\Fiscal\RespostaDeCancelamento;
use App\Support\Fiscal\RespostaDeNfse;
use App\Support\TenantAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class ProvedorDeNfseTest extends TestCase
{
    use RefreshDatabase;

    private const AUTH = 'https://auth.nuvemfiscal.com.br/oauth/token';

    private const API_HOMOLOGACAO = 'https://api.sandbox.nuvemfiscal.com.br';

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

    public function test_interface_cobre_emissao_consulta_cancelamento_substituicao_e_downloads(): void
    {
        $configuracao = $this->configuracao($this->empresa());
        $this->fakeComToken([
            self::API_HOMOLOGACAO.'/nfse/dps' => Http::sequence()
                ->push(['id' => 'nfse-1', 'status' => 'processando'])
                ->push(['id' => 'nfse-2', 'status' => 'processando']),
            self::API_HOMOLOGACAO.'/nfse/nfse-1' => Http::response([
                'id' => 'nfse-1',
                'status' => 'autorizada',
                'numero' => '123',
                'codigo_verificacao' => 'ABC123',
                'data_emissao' => '2026-08-07T13:20:00-03:00',
                'mensagens' => [],
            ]),
            self::API_HOMOLOGACAO.'/nfse/nfse-1/cancelamento' => Http::response([
                'id' => 'cancelamento-1',
                'status' => 'pendente',
                'codigo' => '1',
                'motivo' => 'Documento emitido com dado incorreto',
                'mensagens' => [],
            ]),
            self::API_HOMOLOGACAO.'/nfse/nfse-1/pdf' => Http::response('%PDF-conteudo', 200, [
                'Content-Type' => 'application/pdf',
            ]),
            self::API_HOMOLOGACAO.'/nfse/nfse-1/xml' => Http::sequence()
                ->push(
                    '<?xml version="1.0"?><NFSe xmlns="http://www.sped.fazenda.gov.br/nfse"><infNFSe><chNFSe>chave-fiscal-1</chNFSe></infNFSe></NFSe>',
                    200,
                    ['Content-Type' => 'application/xml'],
                )
                ->push('<NFS-e/>', 200, ['Content-Type' => 'application/xml']),
        ]);

        $provedor = new ProvedorPadrao;

        $id = $provedor->emitir($configuracao, ['ide' => ['dCompet' => '2026-08-07']], 'nota-1');
        $consulta = $provedor->consultar($configuracao, $id);
        $cancelamento = $provedor->cancelar(
            $configuracao,
            $id,
            'Documento emitido com dado incorreto',
            '1',
        );
        $consultaCancelamento = $provedor->consultarCancelamento($configuracao, $id);
        $idSubstituta = $provedor->substituir(
            $configuracao,
            'nfse-1',
            '01',
            'Correção dos dados do tomador',
            ['ide' => ['dCompet' => '2026-08-07']],
            'nota-2',
        );

        $this->assertInstanceOf(ProvedorDeNfse::class, $provedor);
        $this->assertSame('nfse-1', $id);
        $this->assertInstanceOf(RespostaDeNfse::class, $consulta);
        $this->assertSame('autorizada', $consulta->situacao);
        $this->assertSame('123', $consulta->numero);
        $this->assertSame('2026-08-07T13:20:00-03:00', $consulta->emitidaEm?->toIso8601String());
        $this->assertInstanceOf(RespostaDeCancelamento::class, $cancelamento);
        $this->assertSame('cancelamento-1', $cancelamento->id);
        $this->assertSame('cancelamento-1', $consultaCancelamento->id);
        $this->assertSame('nfse-2', $idSubstituta);
        $this->assertSame('%PDF-conteudo', $provedor->baixarPdf($configuracao, $id));
        $this->assertSame('<NFS-e/>', $provedor->baixarXml($configuracao, $id));

        Http::assertSent(function (Request $requisicao): bool {
            if ($requisicao->url() !== self::API_HOMOLOGACAO.'/nfse/dps') {
                return false;
            }

            $dados = $requisicao->data();

            return $dados['provedor'] === 'padrao'
                && $dados['ambiente'] === 'homologacao'
                && $dados['referencia'] === 'nota-2'
                && $dados['infDPS']['subst'] === [
                    'chSubstda' => 'chave-fiscal-1',
                    'cMotivo' => '01',
                    'xMotivo' => 'Correção dos dados do tomador',
                ];
        });

        Http::assertSent(fn (Request $requisicao): bool => $requisicao->url() === self::API_HOMOLOGACAO.'/nfse/nfse-1/xml'
            && $requisicao->method() === 'GET');

        Http::assertSent(fn (Request $requisicao): bool => $requisicao->url() === self::API_HOMOLOGACAO.'/nfse/nfse-1/cancelamento'
            && $requisicao->method() === 'POST'
            && $requisicao->data() === [
                'codigo' => '1',
                'motivo' => 'Documento emitido com dado incorreto',
            ]);
        Http::assertSent(fn (Request $requisicao): bool => $requisicao->url() === self::API_HOMOLOGACAO.'/nfse/nfse-1/cancelamento'
            && $requisicao->method() === 'GET');
    }

    public function test_emissao_e_substituicao_rejeitam_referencia_vazia_antes_de_oauth(): void
    {
        $configuracao = $this->configuracao($this->empresa());
        Http::fake();
        $provedor = new ProvedorPadrao;

        foreach ([
            fn () => $provedor->emitir($configuracao, [], ' '),
            fn () => $provedor->substituir($configuracao, 'nfse-1', '01', 'Correção', [], ''),
        ] as $operacao) {
            try {
                $operacao();
                $this->fail('Era esperada a recusa da referência vazia.');
            } catch (DadoFiscalInvalidoException $excecao) {
                $this->assertFalse($excecao->ehTemporaria());
                $this->assertStringContainsString('referência', $excecao->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_get_de_cancelamento_404_tem_excecao_especifica(): void
    {
        $configuracao = $this->configuracao($this->empresa());
        $this->fakeComToken([
            self::API_HOMOLOGACAO.'/nfse/nfse-sem-cancelamento/cancelamento' => Http::response([
                'message' => 'Cancelamento não encontrado',
            ], 404),
        ]);

        $this->expectException(CancelamentoNaoEncontradoException::class);

        (new ProvedorPadrao)->consultarCancelamento($configuracao, 'nfse-sem-cancelamento');
    }

    public function test_substituicao_recusa_xml_sem_chave_sem_expor_o_conteudo(): void
    {
        $configuracao = $this->configuracao($this->empresa());
        $conteudoSensivel = 'conteudo-fiscal-sensivel';
        $this->fakeComToken([
            self::API_HOMOLOGACAO.'/nfse/nfse-sem-chave/xml' => Http::response(
                '<NFSe xmlns="http://www.sped.fazenda.gov.br/nfse"><infNFSe>'.$conteudoSensivel.'</infNFSe></NFSe>',
                200,
                ['Content-Type' => 'application/xml'],
            ),
        ]);

        try {
            (new ProvedorPadrao)->substituir(
                $configuracao,
                'nfse-sem-chave',
                '01',
                'Correção dos dados do tomador',
                [],
                'nota-substituta',
            );
            $this->fail('Era esperada a recusa do XML sem chNFSe.');
        } catch (DadoFiscalInvalidoException $excecao) {
            $this->assertFalse($excecao->ehTemporaria());
            $this->assertStringContainsString('chNFSe', $excecao->getMessage());
            $this->assertStringNotContainsString($conteudoSensivel, $excecao->getMessage());
        }

        Http::assertNotSent(fn (Request $requisicao): bool => $requisicao->url() === self::API_HOMOLOGACAO.'/nfse/dps');
    }

    public function test_substituicao_recusa_xml_invalido_sem_vazar_o_xml(): void
    {
        $configuracao = $this->configuracao($this->empresa());
        $xmlInvalido = '<NFSe><chNFSe>chave-que-nao-deve-vazar</NFSe>';
        $this->fakeComToken([
            self::API_HOMOLOGACAO.'/nfse/nfse-xml-invalido/xml' => Http::response(
                $xmlInvalido,
                200,
                ['Content-Type' => 'application/xml'],
            ),
        ]);

        try {
            (new ProvedorPadrao)->substituir(
                $configuracao,
                'nfse-xml-invalido',
                '01',
                'Correção dos dados do tomador',
                [],
                'nota-substituta',
            );
            $this->fail('Era esperada a recusa do XML inválido.');
        } catch (DadoFiscalInvalidoException $excecao) {
            $this->assertFalse($excecao->ehTemporaria());
            $this->assertStringNotContainsString('chave-que-nao-deve-vazar', $excecao->getMessage());
            $this->assertStringNotContainsString($xmlInvalido, $excecao->getMessage());
        }

        Http::assertNotSent(fn (Request $requisicao): bool => $requisicao->url() === self::API_HOMOLOGACAO.'/nfse/dps');
    }

    public function test_oauth_usa_client_credentials_scope_nfse_e_bearer_sem_estado_entre_tenants(): void
    {
        $configuracaoA = $this->configuracao($this->empresa('Empresa A'), 'cliente-a', 'segredo-a');
        $configuracaoB = $this->configuracao($this->empresa('Empresa B'), 'cliente-b', 'segredo-b');
        $tokens = [];
        $autorizacoes = [];

        Http::fake(function (Request $requisicao) use (&$tokens, &$autorizacoes) {
            if ($requisicao->url() === self::AUTH) {
                $tokens[] = $requisicao->data();

                return Http::response([
                    'access_token' => $requisicao->data()['client_id'] === 'cliente-a' ? 'token-a' : 'token-b',
                ]);
            }

            $autorizacoes[] = $requisicao->header('Authorization')[0] ?? null;

            return Http::response(['id' => basename($requisicao->url()), 'status' => 'processando']);
        });

        $provedor = new ProvedorPadrao;
        $provedor->consultar($configuracaoA, 'nota-a');
        $provedor->consultar($configuracaoB, 'nota-b');

        $this->assertSame([
            'grant_type' => 'client_credentials',
            'client_id' => 'cliente-a',
            'client_secret' => 'segredo-a',
            'scope' => 'nfse',
        ], $tokens[0]);
        $this->assertSame('cliente-b', $tokens[1]['client_id']);
        $this->assertSame(['Bearer token-a', 'Bearer token-b'], $autorizacoes);
    }

    public function test_timeout_e_de_trinta_segundos(): void
    {
        $configuracao = $this->configuracao($this->empresa());
        $opcoes = [];

        Http::fake(function (Request $requisicao, array $options) use (&$opcoes) {
            $opcoes[] = $options;

            return $requisicao->url() === self::AUTH
                ? Http::response(['access_token' => 'token-temporario'])
                : Http::response(['id' => 'nfse-1', 'status' => 'processando']);
        });

        (new ProvedorPadrao)->consultar($configuracao, 'nfse-1');

        $this->assertNotEmpty($opcoes);

        foreach ($opcoes as $opcao) {
            $this->assertSame(30, $opcao['timeout']);
        }
    }

    public function test_erro_de_rede_e_repetido_duas_vezes(): void
    {
        $configuracao = $this->configuracao($this->empresa());

        $tentativasDaApi = 0;

        Http::fake(function (Request $requisicao) use (&$tentativasDaApi) {
            if ($requisicao->url() === self::AUTH) {
                return Http::response(['access_token' => 'token-temporario']);
            }

            $tentativasDaApi++;

            throw new ConnectionException('timeout de rede');
        });

        try {
            (new ProvedorPadrao)->consultar($configuracao, 'nfse-rede');
            $this->fail('Era esperada indisponibilidade após as duas tentativas de rede.');
        } catch (PrefeituraIndisponivelException $excecao) {
            $this->assertTrue($excecao->ehTemporaria());
        }

        $this->assertSame(2, $tentativasDaApi);
    }

    public function test_erro_http_nao_e_repetido(): void
    {
        $configuracao = $this->configuracao($this->empresa());

        $chamadasComErroHttp = 0;

        Http::fake(function (Request $requisicao) use (&$chamadasComErroHttp) {
            if ($requisicao->url() === self::AUTH) {
                return Http::response(['access_token' => 'token-temporario']);
            }

            $chamadasComErroHttp++;

            return Http::response(['message' => 'falha interna'], 503);
        });

        try {
            (new ProvedorPadrao)->consultar($configuracao, 'nfse-503');
            $this->fail('Era esperada indisponibilidade para HTTP 503.');
        } catch (PrefeituraIndisponivelException) {
            $this->assertSame(1, $chamadasComErroHttp);
        }
    }

    public function test_seis_erros_tem_mensagens_distintas_e_classificacao_temporaria_correta(): void
    {
        $configuracao = $this->configuracao($this->empresa());
        $cenarios = [
            [401, ['message' => 'invalid token'], CredencialFiscalInvalidaException::class],
            [422, ['mensagens' => [['descricao' => 'CPF do tomador é obrigatório']]], DadosDoTomadorIncompletosException::class],
            [422, ['mensagens' => [['descricao' => 'Código de serviço municipal não aceito']]], CodigoDeServicoNaoAceitoException::class],
            [503, ['message' => 'timeout'], PrefeituraIndisponivelException::class],
            [422, ['mensagens' => [['descricao' => 'Nota já cancelada']]], NotaJaCanceladaException::class],
            [422, ['mensagens' => [['descricao' => 'Prazo de cancelamento expirado']]], PrazoDeCancelamentoExpiradoException::class],
        ];
        $mensagens = [];
        $statusAtual = 200;
        $corpoAtual = [];

        Http::fake(function (Request $requisicao) use (&$statusAtual, &$corpoAtual) {
            return $requisicao->url() === self::AUTH
                ? Http::response(['access_token' => 'token-temporario'])
                : Http::response($corpoAtual, $statusAtual);
        });

        foreach ($cenarios as $indice => [$status, $corpo, $classe]) {
            $statusAtual = $status;
            $corpoAtual = $corpo;

            try {
                (new ProvedorPadrao)->consultar($configuracao, 'erro-'.$indice);
                $this->fail('Era esperada a exceção '.$classe.'.');
            } catch (FalhaFiscalException $excecao) {
                $this->assertInstanceOf($classe, $excecao);
                $this->assertSame($classe === PrefeituraIndisponivelException::class, $excecao->ehTemporaria());
                $mensagens[] = $excecao->getMessage();
            }
        }

        $this->assertCount(6, array_unique($mensagens));
    }

    public function test_status_erro_em_resposta_assincrona_e_traduzido(): void
    {
        $configuracao = $this->configuracao($this->empresa());
        $this->fakeComToken([
            self::API_HOMOLOGACAO.'/nfse/nfse-erro' => Http::response([
                'id' => 'nfse-erro',
                'status' => 'erro',
                'mensagens' => [['descricao' => 'Endereço do tomador incompleto']],
            ]),
        ]);

        $this->expectException(DadosDoTomadorIncompletosException::class);

        (new ProvedorPadrao)->consultar($configuracao, 'nfse-erro');
    }

    public function test_validacao_de_credenciais_retorna_booleano_sem_chamar_a_api_fiscal(): void
    {
        $configuracao = $this->configuracao($this->empresa());

        Http::fake([
            self::AUTH => Http::sequence()
                ->push(['access_token' => 'token-valido'])
                ->push(['error' => 'invalid_client'], 401),
        ]);
        $this->assertTrue((new ProvedorPadrao)->validarCredenciais($configuracao));
        $this->assertFalse((new ProvedorPadrao)->validarCredenciais($configuracao));
        Http::assertSentCount(2);
    }

    public function test_resolvedor_busca_somente_configuracao_ativa_do_tenant_corrente(): void
    {
        $empresaA = $this->empresa('Empresa A');
        $empresaB = $this->empresa('Empresa B');
        $configuracaoA = $this->configuracao($empresaA, ativo: true);
        $configuracaoB = $this->configuracao($empresaB, ativo: true);
        $resolvedor = new ResolvedorDeProvedor;

        TenantAtual::definir($empresaB->id);

        $this->assertSame($configuracaoB->id, $resolvedor->configuracaoAtiva()->id);
        $this->assertNotSame($configuracaoA->id, $resolvedor->configuracaoAtiva()->id);
        $this->assertInstanceOf(ProvedorPadrao::class, $resolvedor->para());

        $configuracaoB->forceFill(['ativo' => false])->save();

        try {
            $resolvedor->configuracaoAtiva();
            $this->fail('Era esperada a mensagem de configuração fiscal ausente.');
        } catch (RuntimeException $excecao) {
            $this->assertStringContainsString('configuração fiscal ativa', $excecao->getMessage());
        }
    }

    public function test_resolvedor_recusa_provedor_desconhecido_com_mensagem_clara(): void
    {
        $configuracao = $this->configuracao($this->empresa(), provedor: 'fornecedor_inexistente');

        try {
            (new ResolvedorDeProvedor)->paraConfiguracao($configuracao);
            $this->fail('Era esperada a recusa do provedor desconhecido.');
        } catch (RuntimeException $excecao) {
            $this->assertStringContainsString('fornecedor_inexistente', $excecao->getMessage());
            $this->assertStringContainsString('não possui implementação', $excecao->getMessage());
        }
    }

    public function test_logs_registram_requisicao_e_resposta_sem_qualquer_segredo(): void
    {
        $clientId = 'cliente-ultrassecreto';
        $clientSecret = 'segredo-ultrassecreto';
        $accessToken = 'access-token-ultrassecreto';
        $configuracao = $this->configuracao($this->empresa(), $clientId, $clientSecret);
        $registros = [];

        Log::listen(function (MessageLogged $mensagem) use (&$registros): void {
            if (str_starts_with($mensagem->message, 'nfse_nuvem_fiscal.')) {
                $registros[] = $mensagem;
            }
        });

        Http::fake([
            self::AUTH => Http::response([
                'access_token' => $accessToken,
                'client_id' => $clientId,
            ]),
            self::API_HOMOLOGACAO.'/nfse/nfse-log' => Http::response([
                'id' => 'nfse-log',
                'status' => 'processando',
                'message' => 'resposta sem segredo',
            ]),
        ]);

        (new ProvedorPadrao)->consultar($configuracao, 'nfse-log');

        $this->assertCount(4, $registros);

        foreach ($registros as $registro) {
            $serializado = $registro->message.' '.json_encode($registro->context);

            $this->assertStringNotContainsString($clientId, $serializado);
            $this->assertStringNotContainsString($clientSecret, $serializado);
            $this->assertStringNotContainsString($accessToken, $serializado);
            $this->assertStringNotContainsString('client_id', mb_strtolower($serializado, 'UTF-8'));
            $this->assertStringNotContainsString('client_secret', mb_strtolower($serializado, 'UTF-8'));
            $this->assertStringNotContainsString('access_token', mb_strtolower($serializado, 'UTF-8'));
            $this->assertStringNotContainsString('authorization', mb_strtolower($serializado, 'UTF-8'));
            $this->assertStringNotContainsString('credenciais', mb_strtolower($serializado, 'UTF-8'));
        }
    }

    private function empresa(string $nome = 'Empresa Fiscal'): Company
    {
        return Company::create(['name' => $nome]);
    }

    private function configuracao(
        Company $empresa,
        string $clientId = 'cliente-teste',
        string $clientSecret = 'segredo-teste',
        bool $ativo = true,
        string $provedor = ProvedorPadrao::NOME,
    ): FiscalConfig {
        return TenantAtual::comTenant($empresa->id, fn (): FiscalConfig => FiscalConfig::create([
            'provedor' => $provedor,
            'ambiente' => 'homologacao',
            'credenciais' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ],
            'regime_tributario' => 'simples_nacional',
            'codigo_servico' => '07.13',
            'aliquota_iss' => '5.00',
            'natureza_operacao' => 'tributacao_no_municipio',
            'ativo' => $ativo,
        ]));
    }

    /**
     * @param  array<string, mixed>  $respostas
     */
    private function fakeComToken(array $respostas): void
    {
        Http::fake([
            self::AUTH => Http::response(['access_token' => 'token-temporario']),
            ...$respostas,
        ]);
    }
}
