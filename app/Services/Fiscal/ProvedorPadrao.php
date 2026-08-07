<?php

namespace App\Services\Fiscal;

use App\Exceptions\CancelamentoNaoEncontradoException;
use App\Exceptions\CodigoDeServicoNaoAceitoException;
use App\Exceptions\CredencialFiscalInvalidaException;
use App\Exceptions\DadoFiscalInvalidoException;
use App\Exceptions\DadosDoTomadorIncompletosException;
use App\Exceptions\NotaJaCanceladaException;
use App\Exceptions\PrazoDeCancelamentoExpiradoException;
use App\Exceptions\PrefeituraIndisponivelException;
use App\Exceptions\RecusaFiscalException;
use App\Models\FiscalConfig;
use App\Support\Fiscal\RespostaDeCancelamento;
use App\Support\Fiscal\RespostaDeNfse;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Integração com a API 2.70.0 da Nuvem Fiscal.
 *
 * A API recebe uma DPS e devolve um identificador. O processamento municipal
 * continua de forma assíncrona e deve ser acompanhado por `consultar()`.
 */
class ProvedorPadrao implements ProvedorDeNfse
{
    public const NOME = 'nuvem_fiscal';

    private const URL_AUTENTICACAO = 'https://auth.nuvemfiscal.com.br/oauth/token';

    private const URL_PRODUCAO = 'https://api.nuvemfiscal.com.br';

    private const URL_HOMOLOGACAO = 'https://api.sandbox.nuvemfiscal.com.br';

    private const TIMEOUT = 30;

    private const TENTATIVAS = 2;

    public function emitir(FiscalConfig $configuracao, array $dadosDaDps, string $referencia): string
    {
        $referencia = $this->exigirReferencia($referencia);
        $corpo = $this->envelopeDaDps($configuracao, $dadosDaDps, $referencia);
        $resposta = $this->chamarJson($configuracao, 'POST', '/nfse/dps', $corpo, true);

        $this->traduzirFalhaAssincrona($resposta);

        return $this->exigirTexto($resposta, 'id');
    }

    public function consultar(FiscalConfig $configuracao, string $idNoProvedor): RespostaDeNfse
    {
        $corpo = $this->chamarJson($configuracao, 'GET', '/nfse/'.$idNoProvedor);

        $this->traduzirFalhaAssincrona($corpo);

        return new RespostaDeNfse(
            id: $this->exigirTexto($corpo, 'id'),
            situacao: $this->texto($corpo['status'] ?? null) ?? RespostaDeNfse::SITUACAO_PROCESSANDO,
            numero: $this->texto($corpo['numero'] ?? null),
            codigoVerificacao: $this->texto($corpo['codigo_verificacao'] ?? null),
            emitidaEm: $this->dataHora($corpo['data_emissao'] ?? null),
            mensagens: $this->mensagens($corpo),
        );
    }

    public function cancelar(
        FiscalConfig $configuracao,
        string $idNoProvedor,
        string $motivo,
        ?string $codigo = null,
    ): RespostaDeCancelamento {
        $dados = array_filter([
            'codigo' => $codigo,
            'motivo' => $motivo,
        ], static fn (mixed $valor): bool => $valor !== null);

        $corpo = $this->chamarJson(
            $configuracao,
            'POST',
            '/nfse/'.$idNoProvedor.'/cancelamento',
            $dados,
            false,
        );

        $this->traduzirFalhaAssincrona($corpo);

        return $this->respostaDeCancelamento($corpo);
    }

    public function consultarCancelamento(
        FiscalConfig $configuracao,
        string $idNoProvedor,
    ): RespostaDeCancelamento {
        $corpo = $this->chamarJson(
            $configuracao,
            'GET',
            '/nfse/'.$idNoProvedor.'/cancelamento',
            [],
            true,
            true,
        );

        $this->traduzirFalhaAssincrona($corpo);

        return $this->respostaDeCancelamento($corpo);
    }

    public function substituir(
        FiscalConfig $configuracao,
        string $idNoProvedorDaNotaSubstituida,
        string $codigoMotivo,
        string $motivo,
        array $dadosDaDps,
        string $referencia,
    ): string {
        $referencia = $this->exigirReferencia($referencia);
        $chaveSubstituida = $this->chaveDaNfse(
            $this->baixarXml($configuracao, $idNoProvedorDaNotaSubstituida),
        );

        $dadosDaDps['subst'] = [
            'chSubstda' => $chaveSubstituida,
            'cMotivo' => $codigoMotivo,
            'xMotivo' => $motivo,
        ];

        return $this->emitir($configuracao, $dadosDaDps, $referencia);
    }

    public function baixarPdf(FiscalConfig $configuracao, string $idNoProvedor): string
    {
        return $this->baixar($configuracao, '/nfse/'.$idNoProvedor.'/pdf');
    }

    public function baixarXml(FiscalConfig $configuracao, string $idNoProvedor): string
    {
        return $this->baixar($configuracao, '/nfse/'.$idNoProvedor.'/xml');
    }

    public function validarCredenciais(FiscalConfig $configuracao): bool
    {
        try {
            $this->obterToken($configuracao);

            return true;
        } catch (CredencialFiscalInvalidaException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $dadosDaDps
     * @return array<string, mixed>
     */
    private function envelopeDaDps(FiscalConfig $configuracao, array $dadosDaDps, string $referencia): array
    {
        return [
            'provedor' => 'padrao',
            'ambiente' => $configuracao->ambiente,
            'referencia' => $referencia,
            'infDPS' => $dadosDaDps,
        ];
    }

    /** @param array<string, mixed> $corpo */
    private function respostaDeCancelamento(array $corpo): RespostaDeCancelamento
    {
        return new RespostaDeCancelamento(
            id: $this->exigirTexto($corpo, 'id'),
            situacao: $this->texto($corpo['status'] ?? null) ?? 'pendente',
            codigo: $this->texto($corpo['codigo'] ?? null),
            motivo: $this->texto($corpo['motivo'] ?? null),
            mensagens: $this->mensagens($corpo),
        );
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function chamarJson(
        FiscalConfig $configuracao,
        string $metodo,
        string $endpoint,
        array $dados = [],
        bool $repetirErroDeRede = true,
        bool $cancelamentoNaoEncontradoEspecifico = false,
    ): array {
        $token = $this->obterToken($configuracao);
        $url = $this->baseUrl($configuracao).$endpoint;
        $segredos = [...$this->segredosDa($configuracao), $token];

        $this->registrarRequisicao($configuracao, $metodo, $endpoint, $dados, $segredos);

        try {
            $requisicao = $this->clienteHttp($repetirErroDeRede)
                ->withToken($token)
                ->acceptJson()
                ->asJson();

            $resposta = $metodo === 'GET'
                ? $requisicao->get($url, $dados)
                : $requisicao->post($url, $dados);
        } catch (ConnectionException $excecao) {
            $this->registrarResposta($configuracao, $metodo, $endpoint, null, [], $segredos);

            throw new PrefeituraIndisponivelException($excecao);
        }

        $corpo = $resposta->json();
        $corpo = is_array($corpo) ? $corpo : [];

        $this->registrarResposta($configuracao, $metodo, $endpoint, $resposta->status(), $corpo, $segredos);

        if ($cancelamentoNaoEncontradoEspecifico && $resposta->status() === 404) {
            throw new CancelamentoNaoEncontradoException;
        }

        $this->tratarStatusHttp($resposta, $corpo);

        return $corpo;
    }

    private function baixar(FiscalConfig $configuracao, string $endpoint): string
    {
        $token = $this->obterToken($configuracao);
        $segredos = [...$this->segredosDa($configuracao), $token];

        $this->registrarRequisicao($configuracao, 'GET', $endpoint, [], $segredos);

        try {
            $resposta = $this->clienteHttp()
                ->withToken($token)
                ->accept('*/*')
                ->get($this->baseUrl($configuracao).$endpoint);
        } catch (ConnectionException $excecao) {
            $this->registrarResposta($configuracao, 'GET', $endpoint, null, [], $segredos);

            throw new PrefeituraIndisponivelException($excecao);
        }

        $corpoJson = $resposta->json();
        $corpoJson = is_array($corpoJson) ? $corpoJson : [];

        $this->registrarResposta($configuracao, 'GET', $endpoint, $resposta->status(), [
            'content_type' => $resposta->header('Content-Type'),
            'bytes' => strlen($resposta->body()),
        ], $segredos);
        $this->tratarStatusHttp($resposta, $corpoJson);

        return $resposta->body();
    }

    private function obterToken(FiscalConfig $configuracao): string
    {
        [$clientId, $clientSecret] = $this->credenciaisDa($configuracao);
        $dados = [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'nfse',
        ];
        $segredos = $this->segredosDa($configuracao);

        $this->registrarRequisicao($configuracao, 'POST', '/oauth/token', $dados, $segredos);

        try {
            $resposta = $this->clienteHttp(false)->asForm()->post(self::URL_AUTENTICACAO, $dados);
        } catch (ConnectionException $excecao) {
            $this->registrarResposta($configuracao, 'POST', '/oauth/token', null, [], $segredos);

            throw new PrefeituraIndisponivelException($excecao);
        }

        $corpo = $resposta->json();
        $corpo = is_array($corpo) ? $corpo : [];
        $token = $this->texto($corpo['access_token'] ?? null);
        $segredosDaResposta = $token === null ? $segredos : [...$segredos, $token];

        $this->registrarResposta(
            $configuracao,
            'POST',
            '/oauth/token',
            $resposta->status(),
            $corpo,
            $segredosDaResposta,
        );

        if (in_array($resposta->status(), [400, 401, 403], true)) {
            throw new CredencialFiscalInvalidaException;
        }

        if ($resposta->serverError()) {
            throw new PrefeituraIndisponivelException;
        }

        if (! $resposta->successful() || $token === null) {
            throw new CredencialFiscalInvalidaException;
        }

        return $token;
    }

    /**
     * @return array{string, string}
     */
    private function credenciaisDa(FiscalConfig $configuracao): array
    {
        $credenciais = is_array($configuracao->credenciais) ? $configuracao->credenciais : [];
        $clientId = $this->texto($credenciais['client_id'] ?? null);
        $clientSecret = $this->texto($credenciais['client_secret'] ?? null);

        if ($clientId === null || $clientSecret === null) {
            throw new CredencialFiscalInvalidaException;
        }

        return [$clientId, $clientSecret];
    }

    private function clienteHttp(bool $repetirErroDeRede = true): PendingRequest
    {
        $requisicao = Http::timeout(self::TIMEOUT)->connectTimeout(10);

        return $repetirErroDeRede
            ? $requisicao->retry(
                times: self::TENTATIVAS,
                sleepMilliseconds: 100,
                when: static fn (Throwable $excecao): bool => $excecao instanceof ConnectionException,
                throw: false,
            )
            : $requisicao;
    }

    private function exigirReferencia(string $referencia): string
    {
        $referencia = trim($referencia);

        if ($referencia === '') {
            throw new DadoFiscalInvalidoException(
                'A referência da emissão da NFS-e é obrigatória para garantir uma operação idempotente.',
            );
        }

        return $referencia;
    }

    private function chaveDaNfse(string $xml): string
    {
        $estadoAnterior = libxml_use_internal_errors(true);

        try {
            $documento = new DOMDocument;

            if (! $documento->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
                throw $this->excecaoDeXmlSemChave();
            }

            $nos = (new DOMXPath($documento))->query('//*[local-name() = "chNFSe"]');

            if ($nos !== false) {
                foreach ($nos as $no) {
                    $chave = trim($no->textContent);

                    if ($chave !== '') {
                        return $chave;
                    }
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($estadoAnterior);
        }

        throw $this->excecaoDeXmlSemChave();
    }

    private function excecaoDeXmlSemChave(): DadoFiscalInvalidoException
    {
        return new DadoFiscalInvalidoException(
            'O XML autorizado da NFS-e substituída é inválido ou não contém a chave chNFSe.',
        );
    }

    /**
     * @param  array<string, mixed>  $corpo
     */
    private function tratarStatusHttp(Response $resposta, array $corpo): void
    {
        if (in_array($resposta->status(), [401, 403], true)) {
            throw new CredencialFiscalInvalidaException;
        }

        if ($resposta->serverError()) {
            throw new PrefeituraIndisponivelException;
        }

        if ($resposta->clientError()) {
            $this->traduzirMensagem($this->textoDasMensagens($corpo));
        }

        if (! $resposta->successful()) {
            throw new RecusaFiscalException;
        }
    }

    /**
     * @param  array<string, mixed>  $corpo
     */
    private function traduzirFalhaAssincrona(array $corpo): void
    {
        if (mb_strtolower($this->texto($corpo['status'] ?? null) ?? '', 'UTF-8') !== 'erro') {
            return;
        }

        $this->traduzirMensagem($this->textoDasMensagens($corpo));
    }

    private function traduzirMensagem(string $mensagem): never
    {
        $normalizada = mb_strtolower(Str::ascii($mensagem), 'UTF-8');

        if ($this->contem($normalizada, ['ja cancelad', 'já cancelad'])) {
            throw new NotaJaCanceladaException;
        }

        if ($this->contem($normalizada, ['prazo', 'extemporane']) && str_contains($normalizada, 'cancel')) {
            throw new PrazoDeCancelamentoExpiradoException;
        }

        if ($this->contem($normalizada, ['prefeitura indisponivel', 'municipio indisponivel', 'timeout', 'temporariamente indisponivel'])) {
            throw new PrefeituraIndisponivelException;
        }

        if ($this->contem($normalizada, ['tomador', 'intermediario', 'cpf', 'cnpj', 'endereco'])) {
            throw new DadosDoTomadorIncompletosException;
        }

        if ($this->contem($normalizada, ['codigo de servico', 'codigo_servico', 'ctribnac', 'item da lista', 'servico municipal'])) {
            throw new CodigoDeServicoNaoAceitoException;
        }

        throw new RecusaFiscalException;
    }

    /**
     * @param  array<int, string>  $pistas
     */
    private function contem(string $texto, array $pistas): bool
    {
        foreach ($pistas as $pista) {
            if (str_contains($texto, $pista)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $corpo
     */
    private function textoDasMensagens(array $corpo): string
    {
        $textos = [];

        foreach (['message', 'mensagem', 'detail', 'title'] as $campo) {
            $texto = $this->texto($corpo[$campo] ?? null);

            if ($texto !== null) {
                $textos[] = $texto;
            }
        }

        foreach ($this->mensagens($corpo) as $mensagem) {
            $textos = [...$textos, ...array_filter($mensagem)];
        }

        $erros = is_array($corpo['errors'] ?? null) ? $corpo['errors'] : [];

        foreach ($erros as $erro) {
            if (is_string($erro)) {
                $textos[] = $erro;
            } elseif (is_array($erro)) {
                $textos[] = implode(' ', array_filter(array_map(
                    fn (mixed $valor): ?string => $this->texto($valor),
                    $erro,
                )));
            }
        }

        return implode(' ', $textos);
    }

    /**
     * @param  array<string, mixed>  $corpo
     * @return array<int, array{codigo: ?string, descricao: ?string, correcao: ?string}>
     */
    private function mensagens(array $corpo): array
    {
        $resultado = [];
        $mensagens = is_array($corpo['mensagens'] ?? null) ? $corpo['mensagens'] : [];

        foreach ($mensagens as $mensagem) {
            if (! is_array($mensagem)) {
                continue;
            }

            $resultado[] = [
                'codigo' => $this->texto($mensagem['codigo'] ?? null),
                'descricao' => $this->texto($mensagem['descricao'] ?? null),
                'correcao' => $this->texto($mensagem['correcao'] ?? null),
            ];
        }

        return $resultado;
    }

    private function dataHora(mixed $valor): ?CarbonImmutable
    {
        $texto = $this->texto($valor);

        return $texto === null ? null : CarbonImmutable::parse($texto);
    }

    /**
     * @param  array<string, mixed>  $corpo
     */
    private function exigirTexto(array $corpo, string $campo): string
    {
        $valor = $this->texto($corpo[$campo] ?? null);

        if ($valor === null) {
            throw new PrefeituraIndisponivelException;
        }

        return $valor;
    }

    private function texto(mixed $valor): ?string
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    private function baseUrl(FiscalConfig $configuracao): string
    {
        return $configuracao->ambiente === FiscalConfig::AMBIENTES[0]
            ? self::URL_HOMOLOGACAO
            : self::URL_PRODUCAO;
    }

    /**
     * @return array<int, string>
     */
    private function segredosDa(FiscalConfig $configuracao): array
    {
        $credenciais = is_array($configuracao->credenciais) ? $configuracao->credenciais : [];
        $segredos = [];

        array_walk_recursive($credenciais, static function (mixed $valor) use (&$segredos): void {
            if (is_scalar($valor) && trim((string) $valor) !== '') {
                $segredos[] = (string) $valor;
            }
        });

        return $segredos;
    }

    /**
     * @param  array<string, mixed>  $dados
     * @param  array<int, string>  $segredos
     */
    private function registrarRequisicao(
        FiscalConfig $configuracao,
        string $metodo,
        string $endpoint,
        array $dados,
        array $segredos,
    ): void {
        Log::info('nfse_nuvem_fiscal.requisicao', [
            'fiscal_config_id' => $configuracao->getKey(),
            'company_id' => $configuracao->company_id,
            'ambiente' => $configuracao->ambiente,
            'metodo' => $metodo,
            'endpoint' => $endpoint,
            'requisicao' => $this->sanitizar($dados, $segredos),
        ]);
    }

    /**
     * @param  array<string, mixed>  $dados
     * @param  array<int, string>  $segredos
     */
    private function registrarResposta(
        FiscalConfig $configuracao,
        string $metodo,
        string $endpoint,
        ?int $status,
        array $dados,
        array $segredos,
    ): void {
        $contexto = [
            'fiscal_config_id' => $configuracao->getKey(),
            'company_id' => $configuracao->company_id,
            'ambiente' => $configuracao->ambiente,
            'metodo' => $metodo,
            'endpoint' => $endpoint,
            'status' => $status,
            'resposta' => $this->sanitizar($dados, $segredos),
        ];

        if ($status === null || $status >= 400) {
            Log::warning('nfse_nuvem_fiscal.resposta', $contexto);

            return;
        }

        Log::info('nfse_nuvem_fiscal.resposta', $contexto);
    }

    /**
     * @param  array<string, mixed>  $dados
     * @param  array<int, string>  $segredos
     * @return array<string, mixed>
     */
    private function sanitizar(array $dados, array $segredos): array
    {
        $sensitivas = ['client_id', 'client_secret', 'access_token', 'authorization', 'credenciais'];

        $limpar = function (mixed $valor) use (&$limpar, $sensitivas, $segredos): mixed {
            if (is_array($valor)) {
                $resultado = [];

                foreach ($valor as $subChave => $subValor) {
                    if (is_string($subChave)
                        && in_array(mb_strtolower($subChave, 'UTF-8'), $sensitivas, true)) {
                        continue;
                    }

                    $resultado[$subChave] = $limpar($subValor);
                }

                return $resultado;
            }

            if (is_string($valor)) {
                return str_replace($segredos, '[REMOVIDO]', $valor);
            }

            return $valor;
        };

        return $limpar($dados);
    }
}
