<?php

namespace App\Services\Signature;

use App\Exceptions\AssinaturaEletronicaArquivoGrandeDemaisException;
use App\Exceptions\AssinaturaEletronicaCredencialInvalidaException;
use App\Exceptions\AssinaturaEletronicaDocumentoJaAssinadoException;
use App\Exceptions\AssinaturaEletronicaIndisponivelException;
use App\Exceptions\AssinaturaEletronicaPrazoExpiradoException;
use App\Exceptions\AssinaturaEletronicaRecusouException;
use App\Exceptions\AssinaturaEletronicaSignatarioInvalidoException;
use App\Models\SignatureProviderConfig;
use App\Support\BusinessDate;
use App\Support\Signature\DocumentoNoProvedor;
use App\Support\Signature\SignatarioNoProvedor;
use App\Support\Signature\SignatarioParaEnvio;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Implementação de `ProvedorDeAssinatura` contra a API da ZapSign (Plano 26,
 * Task 26.2).
 *
 * ## Por que ZapSign
 *
 * É REST simples com autenticação por token estático (`Authorization: Bearer`),
 * envio do PDF em base64 no mesmo POST que cria o documento, sandbox com o
 * mesmo formato da produção e webhook por evento. Isso permite implementar a
 * integração inteira com `Illuminate\Support\Facades\Http`, sem SDK — que é
 * como o projeto já fala com o PagBank (Plano 7 e Plano 19) e com o Nominatim
 * (Plano 22). Provedor que exigisse upload em várias etapas ou biblioteca
 * própria acrescentaria dependência sem trazer nada que o domínio precise.
 *
 * Nada aqui prende o sistema a ela: o domínio só conhece
 * `ProvedorDeAssinatura`, e trocar de provedor é escrever outra classe atrás
 * da mesma interface e registrá-la em `ResolvedorDeProvedor::IMPLEMENTACOES`.
 *
 * ## Credencial
 *
 * O token vem só de `$configuracao->credenciais['token']`, nunca de
 * configuração fixa nem de propriedade da instância — ver o cabeçalho de
 * `ProvedorDeAssinatura` sobre por que isso importa. Chamada sem token
 * configurado é recusada antes de ir à rede. Nada da credencial aparece em
 * log, em mensagem de exceção ou em qualquer coisa que volte para o domínio:
 * o registro de cada chamada leva o método, o caminho do endpoint, o
 * ambiente, o status e o tempo, e nada mais — nem o corpo enviado (que carrega
 * o contrato inteiro e o e-mail do cliente), nem o corpo recebido.
 *
 * ## Ambiente por tenant
 *
 * `sandbox.api.zapsign.com.br` ou `api.zapsign.com.br`, conforme
 * `SignatureProviderConfig.ambiente`. Cada tenant escolhe testar antes de
 * ligar a assinatura de verdade, sem depender de configuração da plataforma.
 * Documento assinado em sandbox **não tem validade jurídica**, e é por isso
 * que a interface da Task 26.5 mostra o aviso enquanto o ambiente for
 * sandbox.
 *
 * ## Repetição
 *
 * `retry` só para falha de rede e 5xx, no máximo 2 tentativas no total.
 * **Nunca** para 4xx: reenviar um documento recusado por e-mail inválido gasta
 * uma segunda cobrança do provedor sem resolver nada e, se ele aceitar na
 * segunda vez, cria um segundo documento válido do mesmo contrato.
 *
 * ## Situação do documento
 *
 * A ZapSign devolve `status` do documento (`pending`, `signed`, `refused`,
 * `expired`...), mas quem decide se o pedido está assinado é a lista de
 * signatários (`DocumentoNoProvedor::todosAssinaram()`): assinatura parcial
 * não é contrato, e um documento com um `status` agregado otimista não pode
 * virar contrato assinado no sistema. `signed` do provedor só é aceito quando
 * todos os signatários estão como assinados.
 */
class ProvedorPadrao implements ProvedorDeAssinatura
{
    /**
     * Nome do provedor, como gravado em
     * `signature_provider_configs.provedor` e `signature_requests.provedor`.
     */
    public const NOME = 'zapsign';

    /**
     * Duas tentativas no total (a chamada original mais uma repetição), com
     * meio segundo entre elas — só quando vale repetir (ver `valeRepetir()`).
     */
    private const TENTATIVAS = 2;

    private const ESPERA_ENTRE_TENTATIVAS_MS = 500;

    /**
     * Tempo limite da chamada, em segundos. Trinta, e não os quinze da
     * cobrança (Plano 19): aqui o corpo carrega o PDF do contrato inteiro em
     * base64, e a subida de alguns megabytes em conexão ruim passa de quinze
     * segundos com facilidade.
     */
    private const TIMEOUT = 30;

    /**
     * Limite de arquivo da ZapSign, em bytes (10 MB). Conferido antes de
     * subir: mandar 30 MB para receber 413 é gastar minutos de espera para
     * chegar a uma conclusão que já se tinha.
     */
    private const LIMITE_DO_ARQUIVO_EM_BYTES = 10 * 1048576;

    /**
     * Endpoint de leitura sem efeito colateral, usado só para confirmar que o
     * token é aceito. Não cria nem altera nada no provedor.
     */
    private const ENDPOINT_VERIFICACAO = '/api/v1/docs/';

    // -----------------------------------------------------------------
    // Envio
    // -----------------------------------------------------------------

    public function enviar(
        SignatureProviderConfig $configuracao,
        string $nomeDoDocumento,
        string $conteudoDoPdf,
        array $signatarios,
        DateTimeInterface|string $expiraEm,
        ?string $mensagem = null,
        ?string $referenciaExterna = null,
    ): DocumentoNoProvedor {
        $this->exigirSignatarios($signatarios);
        $this->exigirArquivoDentroDoLimite($conteudoDoPdf);

        $corpo = $this->chamar($configuracao, 'POST', '/api/v1/docs/', [
            'name' => mb_substr($nomeDoDocumento, 0, 255),
            'base64_pdf' => base64_encode($conteudoDoPdf),
            'lang' => 'pt-br',
            'external_id' => $referenciaExterna ?? '',
            'date_limit_to_sign' => BusinessDate::diaDe($expiraEm),
            // Ordem de assinatura ligada sempre: `order_group` só é respeitado
            // com esta chave verdadeira, e é ela que garante que a contratada
            // assine antes de o documento chegar ao cliente quando o contrato
            // exigir isso. Signatários com o mesmo `order_group` continuam
            // assinando em paralelo, então ligar isto não muda nada no caso
            // simples de todo mundo na ordem 1.
            'signature_order_active' => true,
            // Recusa explícita faz parte do ciclo: sem isso, um cliente que
            // não concorda simplesmente não assina, e o contrato fica preso em
            // "em assinatura" até expirar, sem ninguém saber o motivo.
            'allow_refuse_signature' => true,
            'observers' => [],
            'signers' => array_map(
                fn (SignatarioParaEnvio $signatario): array => $this->signatarioParaCorpo($signatario, $mensagem),
                array_values($signatarios)
            ),
        ]);

        return $this->documentoDaResposta($corpo, '/api/v1/docs/', $configuracao);
    }

    // -----------------------------------------------------------------
    // Consulta e acompanhamento
    // -----------------------------------------------------------------

    public function consultar(SignatureProviderConfig $configuracao, string $idNoProvedor): DocumentoNoProvedor
    {
        $endpoint = $this->endpointDoDocumento($idNoProvedor);

        return $this->documentoDaResposta(
            $this->chamar($configuracao, 'GET', $endpoint),
            $endpoint,
            $configuracao
        );
    }

    /**
     * Manda a ZapSign notificar de novo quem ainda não assinou, pelo endpoint
     * de notificação em massa do documento. Não cria documento novo — ver o
     * cabeçalho de `ProvedorDeAssinatura::reenviar()`.
     *
     * Devolve a situação recém-consultada, e não a resposta do reenvio: o
     * endpoint de notificação devolve só uma confirmação, e quem chamou
     * precisa da situação para gravar.
     */
    public function reenviar(SignatureProviderConfig $configuracao, string $idNoProvedor): DocumentoNoProvedor
    {
        $atual = $this->consultar($configuracao, $idNoProvedor);

        if ($atual->situacao === DocumentoNoProvedor::SITUACAO_ASSINADO) {
            throw AssinaturaEletronicaDocumentoJaAssinadoException::naoPodeMaisSerAlterado('reenvio');
        }

        if ($atual->situacao === DocumentoNoProvedor::SITUACAO_EXPIRADO) {
            throw AssinaturaEletronicaPrazoExpiradoException::paraPedido(null);
        }

        $this->chamar($configuracao, 'POST', '/api/v1/signers/notify/', [
            'doc_token' => $idNoProvedor,
        ]);

        return $atual;
    }

    public function cancelar(SignatureProviderConfig $configuracao, string $idNoProvedor, ?string $motivo = null): void
    {
        $this->chamar($configuracao, 'POST', '/api/v1/refuse/', [
            'doc_token' => $idNoProvedor,
            'rejected_reason' => $motivo ?? 'Cancelado pela empresa contratada.',
        ]);
    }

    public function baixarAssinado(SignatureProviderConfig $configuracao, string $idNoProvedor): string
    {
        $documento = $this->consultar($configuracao, $idNoProvedor);

        if ($documento->urlDoArquivoAssinado === null) {
            throw AssinaturaEletronicaRecusouException::comRespostaDoProvedor(
                $this->endpointDoDocumento($idNoProvedor),
                200,
                'o documento ainda não tem arquivo assinado disponível.'
            );
        }

        return $this->baixarArquivo($configuracao, $documento->urlDoArquivoAssinado);
    }

    public function validarCredenciais(SignatureProviderConfig $configuracao): bool
    {
        try {
            // Listagem paginada, sem efeito colateral: o que interessa é o
            // provedor aceitar (ou recusar) o token.
            $this->chamar($configuracao, 'GET', self::ENDPOINT_VERIFICACAO, ['page' => 1]);
        } catch (AssinaturaEletronicaCredencialInvalidaException) {
            // Token errado é o caso comum de quem está configurando, não uma
            // situação excepcional: vira `false`, não exceção. Ver o cabeçalho
            // do método na interface.
            return false;
        }

        return true;
    }

    // -----------------------------------------------------------------
    // Webhook
    // -----------------------------------------------------------------

    /**
     * ## Autenticidade sem assinatura de requisição
     *
     * A ZapSign **não** assina o webhook: não há cabeçalho de HMAC como o
     * `x-authenticity-token` do PagBank (Plano 19). Fingir que há, aceitando
     * qualquer requisição, seria pior do que reconhecer o problema, então a
     * autenticidade aqui é sustentada por duas coisas, e é importante que
     * quem mexer neste arquivo entenda as duas:
     *
     * 1. **O `webhook_token` da URL é o segredo.** São 40 caracteres
     *    aleatórios por tenant, gravados cifrados, e é ele que decide de qual
     *    empresa é o webhook (`SignatureProviderConfig::paraToken()`). Quem
     *    não o conhece não chega a este método: a rota responde 404.
     * 2. **O corpo não decide nada.** Deste payload sai apenas o
     *    identificador do documento (`identificarDocumentoNoWebhook()`). Toda
     *    a situação — quem assinou, quando, de qual IP, se o documento está
     *    concluído — vem de uma consulta autenticada com a credencial do
     *    tenant (`consultar()`). Um POST forjado com o token certo, no pior
     *    caso, provoca uma consulta ao provedor sobre um documento daquele
     *    mesmo tenant e converge para o estado real. Ele não consegue marcar
     *    contrato como assinado.
     *
     * Quando o tenant cadastrar um segredo compartilhado em
     * `credenciais['webhook_secret']` (possível hoje, e recomendável se o
     * provedor passar a permitir cabeçalho próprio), ele passa a ser exigido
     * no cabeçalho `X-Zapsign-Secret`, comparado em tempo constante.
     */
    public function validarWebhook(SignatureProviderConfig $configuracao, Request $requisicao): bool
    {
        $credenciais = $configuracao->credenciais;
        $segredo = is_array($credenciais) ? ($credenciais['webhook_secret'] ?? null) : null;
        $segredo = is_string($segredo) ? trim($segredo) : '';

        if ($segredo === '') {
            return true;
        }

        return hash_equals($segredo, (string) $requisicao->header('X-Zapsign-Secret', ''));
    }

    /**
     * O documento vem em `token` no corpo do evento. Alguns eventos de
     * signatário aninham o documento em `doc`, por isso as duas leituras.
     *
     * @param  array<string, mixed>  $payload
     */
    public function identificarDocumentoNoWebhook(array $payload): ?string
    {
        foreach (['token', 'doc_token', 'doc.token', 'document.token'] as $caminho) {
            $valor = $this->textoOuNulo(data_get($payload, $caminho));

            if ($valor !== null) {
                return $valor;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function tipoDoEventoNoWebhook(array $payload): string
    {
        return $this->textoOuNulo($payload['event_type'] ?? null) ?? 'desconhecido';
    }

    // -----------------------------------------------------------------
    // Tradução da resposta
    // -----------------------------------------------------------------

    /**
     * Documento da ZapSign traduzido para `DocumentoNoProvedor`.
     *
     * @param  array<string, mixed>  $corpo
     */
    private function documentoDaResposta(array $corpo, string $endpoint, SignatureProviderConfig $configuracao): DocumentoNoProvedor
    {
        $signatarios = $this->signatariosDaResposta($corpo);

        return new DocumentoNoProvedor(
            idNoProvedor: $this->exigirTexto($corpo, 'token', $endpoint),
            situacao: $this->situacaoDoDocumento($corpo['status'] ?? null, $signatarios, $configuracao),
            signatarios: $signatarios,
            urlDoArquivoAssinado: $this->textoOuNulo($corpo['signed_file'] ?? null),
            urlDoArquivoOriginal: $this->textoOuNulo($corpo['original_file'] ?? null),
            motivoRecusa: $this->textoOuNulo($corpo['rejected_reason'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $corpo
     * @return array<int, SignatarioNoProvedor>
     */
    private function signatariosDaResposta(array $corpo): array
    {
        $lista = is_array($corpo['signers'] ?? null) ? $corpo['signers'] : [];
        $signatarios = [];

        foreach ($lista as $bruto) {
            if (! is_array($bruto)) {
                continue;
            }

            $email = $this->textoOuNulo($bruto['email'] ?? null);

            if ($email === null) {
                continue;
            }

            $situacao = $this->situacaoDoSignatario($bruto['status'] ?? null);

            $signatarios[] = new SignatarioNoProvedor(
                emailNoProvedor: mb_strtolower($email),
                situacao: $situacao,
                nome: $this->textoOuNulo($bruto['name'] ?? null),
                tokenNoProvedor: $this->textoOuNulo($bruto['token'] ?? null),
                linkParaAssinar: $this->textoOuNulo($bruto['sign_url'] ?? null),
                assinadoEm: $situacao === SignatarioNoProvedor::SITUACAO_ASSINOU
                    ? $this->instante($bruto['signed_at'] ?? null)
                    : null,
                // A ZapSign devolve IP e navegador da assinatura em campos
                // próprios, e é isso que compõe a trilha de auditoria. Quando
                // não vierem, ficam nulos: dado de auditoria inventado é pior
                // que dado de auditoria ausente.
                ip: $this->textoOuNulo($bruto['ip'] ?? null),
                userAgent: $this->textoOuNulo($bruto['user_agent'] ?? null),
            );
        }

        return $signatarios;
    }

    /**
     * Situação do documento no vocabulário do domínio.
     *
     * `signed` do provedor só vira `SITUACAO_ASSINADO` quando **todos** os
     * signatários estão assinados: assinatura parcial não é contrato, e é isso
     * que impede um estado agregado otimista do provedor de virar contrato
     * assinado no sistema. Status desconhecido vira `em_andamento`, com
     * registro no log — o que não se pode fazer é ler palavra desconhecida
     * como "assinado" e arquivar como contrato válido um documento que ninguém
     * assinou.
     *
     * @param  array<int, SignatarioNoProvedor>  $signatarios
     */
    private function situacaoDoDocumento(mixed $status, array $signatarios, SignatureProviderConfig $configuracao): string
    {
        $normalizado = mb_strtolower((string) (is_scalar($status) ? $status : ''));

        return match ($normalizado) {
            'signed' => $this->todosAssinaram($signatarios)
                ? DocumentoNoProvedor::SITUACAO_ASSINADO
                : DocumentoNoProvedor::SITUACAO_EM_ANDAMENTO,
            'refused', 'rejected' => DocumentoNoProvedor::SITUACAO_RECUSADO,
            'expired' => DocumentoNoProvedor::SITUACAO_EXPIRADO,
            'deleted', 'canceled', 'cancelled' => DocumentoNoProvedor::SITUACAO_CANCELADO,
            'pending', '' => DocumentoNoProvedor::SITUACAO_EM_ANDAMENTO,
            default => $this->documentoComStatusDesconhecido($normalizado, $configuracao),
        };
    }

    /**
     * Mesma apuração de `DocumentoNoProvedor::todosAssinaram()`, aplicada
     * antes de o objeto existir: é ela que decide a `situacao` passada ao
     * construtor. Lista vazia devolve falso — documento sem signatário nenhum
     * não está assinado.
     *
     * @param  array<int, SignatarioNoProvedor>  $signatarios
     */
    private function todosAssinaram(array $signatarios): bool
    {
        if ($signatarios === []) {
            return false;
        }

        foreach ($signatarios as $signatario) {
            if ($signatario->situacao !== SignatarioNoProvedor::SITUACAO_ASSINOU) {
                return false;
            }
        }

        return true;
    }

    private function documentoComStatusDesconhecido(string $status, SignatureProviderConfig $configuracao): string
    {
        Log::warning('provedor_zapsign.status_de_documento_nao_mapeado', [
            'signature_provider_config_id' => $configuracao->getKey(),
            'company_id' => $configuracao->company_id,
            'status' => $status,
            'ambiente' => $configuracao->ambiente,
        ]);

        return DocumentoNoProvedor::SITUACAO_EM_ANDAMENTO;
    }

    /**
     * Situação de um signatário no vocabulário do domínio. Status desconhecido
     * vira `pendente`, pelo mesmo motivo do documento: nunca subir de estado
     * por engano.
     */
    private function situacaoDoSignatario(mixed $status): string
    {
        return match (mb_strtolower((string) (is_scalar($status) ? $status : ''))) {
            'signed' => SignatarioNoProvedor::SITUACAO_ASSINOU,
            'refused', 'rejected' => SignatarioNoProvedor::SITUACAO_RECUSOU,
            'link-opened', 'link_opened', 'viewed' => SignatarioNoProvedor::SITUACAO_VISUALIZOU,
            default => SignatarioNoProvedor::SITUACAO_PENDENTE,
        };
    }

    // -----------------------------------------------------------------
    // Montagem do corpo
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function signatarioParaCorpo(SignatarioParaEnvio $signatario, ?string $mensagem): array
    {
        $corpo = [
            'name' => $signatario->nome,
            'email' => $signatario->email,
            // Assinatura desenhada na tela, com confirmação por token de
            // e-mail: é o par que sustenta a comprovação de autoria a
            // distância, que é o ponto todo deste plano.
            'auth_mode' => 'assinaturaTela',
            'send_automatic_email' => true,
            'send_automatic_whatsapp' => false,
            // O e-mail é a identidade do signatário e é por ele que a
            // sincronização casa a resposta do provedor com a linha de
            // `signature_signers`: deixar o próprio signatário trocá-lo
            // quebraria esse casamento.
            'lock_email' => true,
            'lock_name' => true,
            'order_group' => $signatario->ordem,
        ];

        if ($signatario->documento !== null && trim($signatario->documento) !== '') {
            $corpo['cpf'] = $this->somenteDigitos($signatario->documento);
        }

        if ($mensagem !== null && trim($mensagem) !== '') {
            $corpo['custom_message'] = trim($mensagem);
        }

        return $corpo;
    }

    /**
     * @param  array<int, SignatarioParaEnvio>  $signatarios
     *
     * @throws AssinaturaEletronicaSignatarioInvalidoException
     */
    private function exigirSignatarios(array $signatarios): void
    {
        if ($signatarios === []) {
            throw AssinaturaEletronicaSignatarioInvalidoException::listaInvalida(
                'O pedido de assinatura precisa de pelo menos um signatário.'
            );
        }

        foreach ($signatarios as $signatario) {
            if (! $signatario instanceof SignatarioParaEnvio) {
                throw new InvalidArgumentException(
                    'A lista de signatários só aceita objetos SignatarioParaEnvio.'
                );
            }
        }
    }

    /**
     * @throws AssinaturaEletronicaArquivoGrandeDemaisException
     */
    private function exigirArquivoDentroDoLimite(string $conteudoDoPdf): void
    {
        $tamanho = strlen($conteudoDoPdf);

        if ($tamanho > self::LIMITE_DO_ARQUIVO_EM_BYTES) {
            throw AssinaturaEletronicaArquivoGrandeDemaisException::acimaDoLimite(
                $tamanho,
                self::LIMITE_DO_ARQUIVO_EM_BYTES
            );
        }
    }

    // -----------------------------------------------------------------
    // Cliente HTTP
    // -----------------------------------------------------------------

    /**
     * Executa a chamada, registra o que aconteceu e traduz o erro do provedor
     * em exceção de domínio.
     *
     * @param  array<array-key, mixed>  $dados
     * @return array<string, mixed>
     *
     * @throws AssinaturaEletronicaIndisponivelException
     * @throws AssinaturaEletronicaRecusouException
     */
    private function chamar(SignatureProviderConfig $configuracao, string $metodo, string $endpoint, array $dados = []): array
    {
        $inicio = microtime(true);
        $requisicao = $this->clienteHttp($configuracao);

        try {
            $resposta = match ($metodo) {
                'GET' => $requisicao->get($endpoint, $dados),
                'POST' => $requisicao->post($endpoint, $dados),
                default => throw new InvalidArgumentException(
                    sprintf('Método HTTP "%s" não é usado nesta integração.', $metodo)
                ),
            };
        } catch (ConnectionException $excecao) {
            $this->registrarChamada($configuracao, $metodo, $endpoint, null, $inicio);

            throw AssinaturaEletronicaIndisponivelException::semResposta($endpoint, $excecao);
        }

        $this->registrarChamada($configuracao, $metodo, $endpoint, $resposta->status(), $inicio);

        $corpo = $resposta->json();
        $corpo = is_array($corpo) ? $corpo : [];

        if ($resposta->serverError()) {
            throw AssinaturaEletronicaIndisponivelException::erroDoProvedor(
                $endpoint,
                $resposta->status(),
                $this->mensagemDoProvedor($corpo)
            );
        }

        if ($resposta->clientError()) {
            $this->tratarClientError($resposta->status(), $corpo, $endpoint);
        }

        return $corpo;
    }

    /**
     * Baixa um arquivo de uma URL temporária devolvida pelo provedor.
     *
     * Sem `Authorization`: a URL do arquivo é assinada e aponta para o
     * armazenamento do provedor, não para a API. Mandar o token do tenant para
     * um host que não é o da API seria vazar credencial para terceiro.
     *
     * @throws AssinaturaEletronicaIndisponivelException
     * @throws AssinaturaEletronicaRecusouException
     */
    private function baixarArquivo(SignatureProviderConfig $configuracao, string $url): string
    {
        $inicio = microtime(true);

        try {
            $resposta = Http::timeout(self::TIMEOUT)
                ->connectTimeout(min(self::TIMEOUT, 10))
                ->retry(
                    times: self::TENTATIVAS,
                    sleepMilliseconds: self::ESPERA_ENTRE_TENTATIVAS_MS,
                    when: fn (Throwable $excecao): bool => $this->valeRepetir($excecao),
                    throw: false,
                )
                ->get($url);
        } catch (ConnectionException $excecao) {
            $this->registrarChamada($configuracao, 'GET', 'arquivo-assinado', null, $inicio);

            throw AssinaturaEletronicaIndisponivelException::semResposta('arquivo-assinado', $excecao);
        }

        // O caminho registrado é o rótulo `arquivo-assinado`, e não a URL: ela
        // é assinada e carrega credencial de acesso ao armazenamento.
        $this->registrarChamada($configuracao, 'GET', 'arquivo-assinado', $resposta->status(), $inicio);

        if ($resposta->serverError()) {
            throw AssinaturaEletronicaIndisponivelException::erroDoProvedor('arquivo-assinado', $resposta->status());
        }

        if ($resposta->clientError()) {
            // 403/404 aqui quase sempre é link expirado, e não credencial
            // errada: a URL vale 60 minutos.
            throw AssinaturaEletronicaRecusouException::comRespostaDoProvedor(
                'arquivo-assinado',
                $resposta->status(),
                'o link do arquivo assinado expirou. Sincronize o pedido para gerar um link novo.'
            );
        }

        return $resposta->body();
    }

    private function clienteHttp(SignatureProviderConfig $configuracao): PendingRequest
    {
        return Http::baseUrl($this->baseUrl($configuracao))
            ->withToken($this->tokenDe($configuracao))
            ->acceptJson()
            ->asJson()
            ->timeout(self::TIMEOUT)
            ->connectTimeout(min(self::TIMEOUT, 10))
            ->retry(
                times: self::TENTATIVAS,
                sleepMilliseconds: self::ESPERA_ENTRE_TENTATIVAS_MS,
                when: fn (Throwable $excecao): bool => $this->valeRepetir($excecao),
                throw: false,
            );
    }

    /**
     * Token do tenant, lido de `$configuracao` nesta chamada e em nenhum outro
     * lugar. Nunca fica em propriedade da classe.
     *
     * @throws AssinaturaEletronicaCredencialInvalidaException Sem token configurado.
     */
    private function tokenDe(SignatureProviderConfig $configuracao): string
    {
        $credenciais = $configuracao->credenciais;
        $token = is_array($credenciais) ? ($credenciais['token'] ?? null) : null;
        $token = is_string($token) ? trim($token) : '';

        if ($token === '') {
            throw AssinaturaEletronicaCredencialInvalidaException::semCredencialConfigurada();
        }

        return $token;
    }

    /**
     * Falha de rede e erro do servidor podem ser repetidos, porque em nenhum
     * dos dois se sabe se o provedor processou o pedido. Recusa 4xx **nunca**
     * é repetida — ver o cabeçalho da classe.
     */
    private function valeRepetir(Throwable $excecao): bool
    {
        if ($excecao instanceof ConnectionException) {
            return true;
        }

        if ($excecao instanceof RequestException) {
            return $excecao->response->serverError();
        }

        return false;
    }

    /**
     * Traduz uma resposta 4xx do provedor na exceção de domínio mais
     * específica que a situação permite.
     *
     * A classificação por status (401/403 -> credencial, 413 -> tamanho) é
     * confiável. A classificação por texto é heurística, e quando nada bate cai
     * no `AssinaturaEletronicaRecusouException` genérico, com a mensagem do
     * provedor preservada — nunca descartada. Mesmo desenho de
     * `GatewayPagBank::tratarClientError()` (Plano 19).
     *
     * @param  array<string, mixed>  $corpo
     *
     * @throws AssinaturaEletronicaCredencialInvalidaException
     * @throws AssinaturaEletronicaSignatarioInvalidoException
     * @throws AssinaturaEletronicaArquivoGrandeDemaisException
     * @throws AssinaturaEletronicaDocumentoJaAssinadoException
     * @throws AssinaturaEletronicaPrazoExpiradoException
     * @throws AssinaturaEletronicaRecusouException
     */
    private function tratarClientError(int $status, array $corpo, string $endpoint): never
    {
        $mensagem = $this->mensagemDoProvedor($corpo);

        if (in_array($status, [401, 403], true)) {
            throw AssinaturaEletronicaCredencialInvalidaException::recusadaPeloProvedor($endpoint, $status, $mensagem);
        }

        if ($status === 413) {
            throw AssinaturaEletronicaArquivoGrandeDemaisException::recusadoPeloProvedor($endpoint, $status, $mensagem);
        }

        $texto = mb_strtolower($mensagem ?? '', 'UTF-8');

        if ($this->pareceTamanhoDeArquivo($texto)) {
            throw AssinaturaEletronicaArquivoGrandeDemaisException::recusadoPeloProvedor($endpoint, $status, $mensagem);
        }

        if ($this->pareceSignatarioInvalido($texto)) {
            throw new AssinaturaEletronicaSignatarioInvalidoException(sprintf(
                'O provedor de assinatura eletrônica recusou um signatário em "%s" (HTTP %d). '
                .'Confira o e-mail e o telefone informados. Resposta do provedor: %s',
                $endpoint,
                $status,
                $mensagem ?? 'sem detalhe.'
            ));
        }

        if ($this->pareceDocumentoJaAssinado($texto)) {
            throw AssinaturaEletronicaDocumentoJaAssinadoException::naoPodeMaisSerAlterado('esta operação');
        }

        if ($this->parecePrazoExpirado($texto)) {
            throw AssinaturaEletronicaPrazoExpiradoException::recusadoPeloProvedor($endpoint, $status, $mensagem);
        }

        throw AssinaturaEletronicaRecusouException::comRespostaDoProvedor($endpoint, $status, $mensagem);
    }

    private function pareceTamanhoDeArquivo(string $mensagem): bool
    {
        foreach (['too large', 'file size', 'tamanho', 'limite de 10', '10mb', '10 mb'] as $pista) {
            if (str_contains($mensagem, $pista)) {
                return true;
            }
        }

        return false;
    }

    private function pareceSignatarioInvalido(string $mensagem): bool
    {
        foreach (['email', 'e-mail', 'signer', 'signatário', 'signatario', 'phone', 'telefone'] as $pista) {
            if (str_contains($mensagem, $pista)) {
                return true;
            }
        }

        return false;
    }

    private function pareceDocumentoJaAssinado(string $mensagem): bool
    {
        foreach (['already signed', 'já assinado', 'ja assinado', 'already completed'] as $pista) {
            if (str_contains($mensagem, $pista)) {
                return true;
            }
        }

        return false;
    }

    private function parecePrazoExpirado(string $mensagem): bool
    {
        foreach (['expired', 'expirado', 'prazo', 'date_limit'] as $pista) {
            if (str_contains($mensagem, $pista)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Registro da chamada. Sem token, sem corpo enviado, sem corpo recebido:
     * o corpo enviado carrega o contrato inteiro e o e-mail do cliente, e o
     * recebido carrega os links assinados do arquivo.
     */
    private function registrarChamada(
        SignatureProviderConfig $configuracao,
        string $metodo,
        string $endpoint,
        ?int $status,
        float $inicio,
    ): void {
        $contexto = [
            'signature_provider_config_id' => $configuracao->getKey(),
            'company_id' => $configuracao->company_id,
            'metodo' => $metodo,
            'endpoint' => $endpoint,
            'ambiente' => $configuracao->ambiente,
            'status' => $status,
            'ms' => (int) round((microtime(true) - $inicio) * 1000),
        ];

        if ($status === null || $status >= 400) {
            Log::warning('provedor_zapsign.chamada_falhou', $contexto);

            return;
        }

        Log::info('provedor_zapsign.chamada', $contexto);
    }

    /**
     * Texto que o provedor devolveu explicando a recusa.
     *
     * Só campos conhecidos do formato de erro da ZapSign são lidos, e o corpo
     * cru nunca é repassado por inteiro: é nele que trafega o e-mail do
     * cliente final e o link assinado do arquivo, e mensagem de erro é
     * justamente por onde esse tipo de dado escaparia para o log e para a
     * tela.
     *
     * @param  array<string, mixed>  $corpo
     */
    private function mensagemDoProvedor(array $corpo): ?string
    {
        foreach (['message', 'detail', 'error'] as $campo) {
            $texto = $this->textoOuNulo($corpo[$campo] ?? null);

            if ($texto !== null) {
                return $texto;
            }
        }

        // Erro de validação campo a campo: {"email": ["Enter a valid email."]}
        $mensagens = [];

        foreach ($corpo as $campo => $valor) {
            if (! is_string($campo) || ! is_array($valor)) {
                continue;
            }

            foreach ($valor as $item) {
                $texto = $this->textoOuNulo($item);

                if ($texto !== null) {
                    $mensagens[] = $campo.': '.$texto;
                }
            }
        }

        return $mensagens === [] ? null : implode('; ', $mensagens);
    }

    // -----------------------------------------------------------------
    // Configuração e utilidades
    // -----------------------------------------------------------------

    /**
     * Base URL conforme o ambiente **do tenant**
     * (`SignatureProviderConfig.ambiente`), nunca de uma flag fixa da
     * plataforma.
     */
    private function baseUrl(SignatureProviderConfig $configuracao): string
    {
        $chave = $configuracao->ambiente === SignatureProviderConfig::AMBIENTES[0] // 'sandbox'
            ? 'services.zapsign.base_url_sandbox'
            : 'services.zapsign.base_url_producao';

        return rtrim((string) config($chave), '/');
    }

    private function endpointDoDocumento(string $idNoProvedor): string
    {
        return '/api/v1/docs/'.rawurlencode($idNoProvedor).'/';
    }

    /**
     * Instante devolvido pelo provedor, no fuso do negócio. `assinado_em` é um
     * instante (hora importa na trilha de auditoria), não um dia.
     */
    private function instante(mixed $valor): ?CarbonImmutable
    {
        $texto = $this->textoOuNulo($valor);

        return $texto === null ? null : BusinessDate::paraFusoNegocio($texto);
    }

    private function somenteDigitos(mixed $valor): string
    {
        return preg_replace('/\D/', '', (string) $valor) ?? '';
    }

    private function textoOuNulo(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $limpo = trim($valor);

        return $limpo === '' ? null : $limpo;
    }

    /**
     * Campo de texto que a resposta precisava trazer. Resposta bem-sucedida
     * sem o identificador é anomalia do provedor, não recusa: entra como
     * indisponibilidade, que é o lado que permite tentar de novo mais tarde.
     *
     * @param  array<string, mixed>  $corpo
     */
    private function exigirTexto(array $corpo, string $campo, string $endpoint): string
    {
        $valor = $this->textoOuNulo($corpo[$campo] ?? null);

        if ($valor === null) {
            throw AssinaturaEletronicaIndisponivelException::erroDoProvedor(
                $endpoint,
                200,
                sprintf('resposta sem o campo obrigatório "%s".', $campo)
            );
        }

        return $valor;
    }
}
