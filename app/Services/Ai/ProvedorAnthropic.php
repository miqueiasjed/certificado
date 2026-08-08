<?php

namespace App\Services\Ai;

use App\Exceptions\IaIndisponivelException;
use App\Exceptions\IaLimiteDeTaxaException;
use App\Exceptions\IaRecusouException;
use App\Models\AiUsage;
use App\Support\Ai\RespostaDeTexto;
use App\Support\Ai\TabelaDePrecos;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Implementação de `ProvedorDeTexto` contra a API de mensagens da Anthropic
 * (Plano 25, Task 25.2).
 *
 * Esta classe traduz protocolo e mede consumo. Nenhuma regra de negócio mora
 * aqui: quem decide o que fazer com o texto gerado é
 * `RascunhoDeParecerService` e `SugestaoDePrecoService` (Tasks 25.3 e 25.4).
 *
 * ## Prefixo de sistema cacheado
 *
 * O bloco de instruções (`$sistema`) vai no campo `system` com
 * `cache_control` do tipo `ephemeral`, e o dado da origem vai depois, na
 * mensagem do usuário. O provedor cobra uma fração do preço pelos tokens que
 * leu do cache, e como o mesmo prefixo se repete em toda geração, é isso que
 * torna o custo do recurso viável.
 *
 * O cache é casamento de prefixo byte a byte. Interpolar data, nome do tenant
 * ou qualquer identificador dentro de `$sistema` invalida o cache em toda
 * chamada e multiplica a conta sem erro visível — a única pista seria
 * `cache_read_input_tokens` continuar zerado. Por isso o prefixo é constante
 * (ver `MontadorDeContexto`), e tudo que varia entra em `$entrada`.
 *
 * ## Parâmetros que não são enviados
 *
 * `temperature`, `top_p` e `top_k` foram removidos no modelo em uso e são
 * recusados com HTTP 400. Variação de estilo, quando desejada, vem da
 * instrução no prompt. Profundidade de raciocínio vem de `output_config.effort`.
 *
 * ## Credencial
 *
 * A chave sai só de `config('ai.anthropic.chave')`. Não aparece em log, em
 * mensagem de exceção nem em nada que volte para o domínio: o registro de cada
 * chamada leva o caminho do endpoint, o modelo, o status e o tempo, e nada
 * mais.
 *
 * ## Repetição
 *
 * `retry` só para falha de rede e 5xx. Nunca para 4xx: reenviar uma requisição
 * recusada devolve a mesma recusa e ainda assim é cobrada.
 *
 * ## Medição
 *
 * Toda tentativa grava uma linha em `ai_usages`, inclusive a que falhou:
 * chamada que consumiu token consumiu dinheiro, e um teto que ignora falha é
 * um teto furado. O `company_id` é preenchido pela trait `BelongsToCompany` a
 * partir do tenant corrente — este arquivo nunca recebe nem escolhe empresa.
 */
class ProvedorAnthropic implements ProvedorDeTexto
{
    /**
     * Nome do provedor, como gravado em log.
     */
    public const NOME = 'anthropic';

    private const CAMINHO_MENSAGENS = '/v1/messages';

    /**
     * Três tentativas no total, com meio segundo entre elas: o suficiente para
     * atravessar uma oscilação de rede sem segurar a requisição do usuário.
     */
    private const TENTATIVAS = 3;

    private const ESPERA_ENTRE_TENTATIVAS_MS = 500;

    private readonly ?string $chave;

    private readonly string $baseUrl;

    private readonly string $versao;

    private readonly string $modelo;

    private readonly int $maxTokens;

    private readonly string $esforco;

    private readonly int $tempoLimite;

    private readonly bool $fallback;

    public function __construct()
    {
        $this->chave = config('ai.anthropic.chave');
        $this->baseUrl = rtrim((string) config('ai.anthropic.base_url'), '/');
        $this->versao = (string) config('ai.anthropic.versao');
        $this->modelo = (string) config('ai.modelo');
        $this->maxTokens = (int) config('ai.max_tokens');
        $this->esforco = (string) config('ai.esforco');
        $this->tempoLimite = (int) config('ai.tempo_limite_segundos');
        $this->fallback = (bool) config('ai.anthropic.fallback');
    }

    public function modelo(): string
    {
        return $this->modelo;
    }

    /**
     * {@inheritDoc}
     */
    public function gerar(string $sistema, string $entrada, array $opcoes = []): RespostaDeTexto
    {
        $modelo = (string) ($opcoes['modelo'] ?? $this->modelo);
        $tipo = (string) ($opcoes['tipo'] ?? 'geracao');
        $inicio = microtime(true);

        if (blank($this->chave)) {
            $erro = IaIndisponivelException::semChaveConfigurada();
            $this->registrarFalha($tipo, $modelo, $erro, $inicio);

            throw $erro;
        }

        try {
            $resposta = $this->enviar($this->montarCorpo($sistema, $entrada, $modelo, $opcoes));
        } catch (ConnectionException $e) {
            $erro = IaIndisponivelException::semResposta(self::CAMINHO_MENSAGENS, $e);
            $this->registrarFalha($tipo, $modelo, $erro, $inicio);

            throw $erro;
        } catch (Throwable $e) {
            $erro = IaIndisponivelException::semResposta(self::CAMINHO_MENSAGENS, $e);
            $this->registrarFalha($tipo, $modelo, $erro, $inicio);

            throw $erro;
        }

        $duracaoMs = $this->duracaoMs($inicio);
        $corpo = $this->corpoEmArray($resposta);

        if ($erro = $this->erroDoStatus($resposta, $corpo)) {
            $this->registrarUso($tipo, $modelo, $this->usoDoCorpo($corpo), $duracaoMs, false, $erro->getMessage());

            throw $erro;
        }

        $uso = $this->usoDoCorpo($corpo);
        $modeloServido = (string) ($corpo['model'] ?? $modelo);

        // `stop_reason` é conferido antes de ler `content`: numa recusa o
        // conteúdo vem vazio ou parcial, e indexar `content[0]` direto é o
        // erro clássico desta integração.
        if (($corpo['stop_reason'] ?? null) === 'refusal') {
            $erro = IaRecusouException::modeloRecusou($corpo['stop_details']['category'] ?? null);
            $this->registrarUso($tipo, $modeloServido, $uso, $duracaoMs, false, $erro->getMessage());

            throw $erro;
        }

        $texto = $this->textoDoCorpo($corpo);

        if ($texto === '') {
            $erro = IaRecusouException::respostaVazia();
            $this->registrarUso($tipo, $modeloServido, $uso, $duracaoMs, false, $erro->getMessage());

            throw $erro;
        }

        $this->registrarUso($tipo, $modeloServido, $uso, $duracaoMs, true, null);

        $this->registrarEmLog($modeloServido, $resposta->status(), $duracaoMs, $uso);

        return new RespostaDeTexto(
            texto: $texto,
            modelo: $modeloServido,
            tokensEntrada: $uso['entrada'],
            tokensSaida: $uso['saida'],
            tokensCacheLeitura: $uso['cache_leitura'],
            tokensCacheEscrita: $uso['cache_escrita'],
            duracaoMs: $duracaoMs,
        );
    }

    /**
     * Corpo da requisição.
     *
     * A ordem dos campos não importa para o cache (o provedor renderiza
     * `tools` → `system` → `messages`), mas o conteúdo de `system` importa: é
     * ele que precisa ser estável.
     *
     * @param  array<string, mixed>  $opcoes
     * @return array<string, mixed>
     */
    private function montarCorpo(string $sistema, string $entrada, string $modelo, array $opcoes): array
    {
        $corpo = [
            'model' => $modelo,
            'max_tokens' => (int) ($opcoes['max_tokens'] ?? $this->maxTokens),
            'system' => [
                [
                    'type' => 'text',
                    'text' => $sistema,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ],
            'messages' => [
                ['role' => 'user', 'content' => $entrada],
            ],
            'output_config' => [
                'effort' => (string) ($opcoes['esforco'] ?? $this->esforco),
            ],
        ];

        if ($this->fallback) {
            $corpo['fallbacks'] = 'default';
        }

        return $corpo;
    }

    /**
     * @param  array<string, mixed>  $corpo
     */
    private function enviar(array $corpo): Response
    {
        return Http::withHeaders($this->cabecalhos())
            ->timeout($this->tempoLimite)
            ->retry(
                self::TENTATIVAS,
                self::ESPERA_ENTRE_TENTATIVAS_MS,
                // Só repete falha de rede e erro do lado do provedor. 4xx
                // (inclusive 429) sobe na primeira tentativa: repetir uma
                // requisição recusada devolve a mesma recusa e é cobrada de
                // novo.
                function (Throwable $excecao): bool {
                    if ($excecao instanceof ConnectionException) {
                        return true;
                    }

                    return $excecao instanceof RequestException
                        && $excecao->response->serverError();
                },
                throw: false
            )
            ->post($this->baseUrl.self::CAMINHO_MENSAGENS, $corpo);
    }

    /**
     * @return array<string, string>
     */
    private function cabecalhos(): array
    {
        $cabecalhos = [
            'x-api-key' => (string) $this->chave,
            'anthropic-version' => $this->versao,
            'content-type' => 'application/json',
        ];

        if ($this->fallback) {
            $cabecalhos['anthropic-beta'] = (string) config('ai.anthropic.beta_fallback');
        }

        return $cabecalhos;
    }

    /**
     * @param  array<string, mixed>  $corpo
     */
    private function erroDoStatus(Response $resposta, array $corpo): ?Throwable
    {
        if ($resposta->successful()) {
            return null;
        }

        $detalhe = $corpo['error']['message'] ?? null;

        if ($resposta->status() === 429) {
            $espera = $resposta->header('retry-after');

            return IaLimiteDeTaxaException::comEspera($espera !== '' ? (int) $espera : null);
        }

        if ($resposta->serverError()) {
            return IaIndisponivelException::erroDoProvedor(self::CAMINHO_MENSAGENS, $resposta->status(), $detalhe);
        }

        return IaRecusouException::requisicaoInvalida(self::CAMINHO_MENSAGENS, $resposta->status(), $detalhe);
    }

    /**
     * @return array<string, mixed>
     */
    private function corpoEmArray(Response $resposta): array
    {
        $corpo = $resposta->json();

        return is_array($corpo) ? $corpo : [];
    }

    /**
     * Concatena os blocos de texto da resposta.
     *
     * A resposta pode trazer blocos que não são texto (raciocínio do modelo,
     * marcador de desvio para o modelo de reserva); só `type = text` entra no
     * rascunho.
     *
     * @param  array<string, mixed>  $corpo
     */
    private function textoDoCorpo(array $corpo): string
    {
        $partes = [];

        foreach ($corpo['content'] ?? [] as $bloco) {
            if (($bloco['type'] ?? null) === 'text' && isset($bloco['text'])) {
                $partes[] = (string) $bloco['text'];
            }
        }

        return trim(implode('', $partes));
    }

    /**
     * @param  array<string, mixed>  $corpo
     * @return array{entrada: int, saida: int, cache_leitura: int, cache_escrita: int}
     */
    private function usoDoCorpo(array $corpo): array
    {
        $uso = $corpo['usage'] ?? [];

        return [
            'entrada' => (int) ($uso['input_tokens'] ?? 0),
            'saida' => (int) ($uso['output_tokens'] ?? 0),
            'cache_leitura' => (int) ($uso['cache_read_input_tokens'] ?? 0),
            'cache_escrita' => (int) ($uso['cache_creation_input_tokens'] ?? 0),
        ];
    }

    /**
     * Grava a tentativa em `ai_usages`.
     *
     * Falha ao medir nunca derruba a geração: o consumo já aconteceu, e perder
     * o registro é menos grave que perder o texto. O erro vai para o log da
     * aplicação, mesmo critério da trait `Auditavel`.
     *
     * @param  array{entrada: int, saida: int, cache_leitura: int, cache_escrita: int}  $uso
     */
    private function registrarUso(
        string $tipo,
        string $modelo,
        array $uso,
        int $duracaoMs,
        bool $sucesso,
        ?string $erro,
    ): ?AiUsage {
        try {
            return AiUsage::create([
                'tipo' => $tipo,
                'modelo' => $modelo,
                'tokens_entrada' => $uso['entrada'],
                'tokens_saida' => $uso['saida'],
                'tokens_cache_leitura' => $uso['cache_leitura'],
                'custo_estimado' => $this->custoEstimado($modelo, $uso),
                'duracao_ms' => $duracaoMs,
                'sucesso' => $sucesso,
                'erro' => $erro,
            ]);
        } catch (Throwable $e) {
            Log::error('Falha ao registrar uso de IA.', [
                'provedor' => self::NOME,
                'modelo' => $modelo,
                'excecao' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Atalho para a tentativa que nem chegou a receber resposta.
     */
    private function registrarFalha(string $tipo, string $modelo, Throwable $erro, float $inicio): void
    {
        $this->registrarUso(
            $tipo,
            $modelo,
            ['entrada' => 0, 'saida' => 0, 'cache_leitura' => 0, 'cache_escrita' => 0],
            $this->duracaoMs($inicio),
            false,
            $erro->getMessage(),
        );
    }

    /**
     * Custo da chamada em dólar.
     *
     * A conta mora em `TabelaDePrecos`, compartilhada com
     * `MedicaoDeUsoService`: duas implementações da mesma tarifa divergiriam
     * no primeiro reajuste de preço, e a divergência apareceria como custo
     * apurado diferente do custo gravado.
     *
     * @param  array{entrada: int, saida: int, cache_leitura: int, cache_escrita: int}  $uso
     */
    private function custoEstimado(string $modelo, array $uso): string
    {
        return number_format(TabelaDePrecos::custoDaChamada($modelo, $uso), 6, '.', '');
    }

    private function duracaoMs(float $inicio): int
    {
        return (int) round((microtime(true) - $inicio) * 1000);
    }

    /**
     * @param  array{entrada: int, saida: int, cache_leitura: int, cache_escrita: int}  $uso
     */
    private function registrarEmLog(string $modelo, int $status, int $duracaoMs, array $uso): void
    {
        Log::info('Geração de texto por IA concluída.', [
            'provedor' => self::NOME,
            'endpoint' => self::CAMINHO_MENSAGENS,
            'modelo' => $modelo,
            'status' => $status,
            'duracao_ms' => $duracaoMs,
            'tokens_entrada' => $uso['entrada'],
            'tokens_saida' => $uso['saida'],
            'tokens_cache_leitura' => $uso['cache_leitura'],
        ]);
    }
}
