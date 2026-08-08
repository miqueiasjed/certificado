<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\SignatureProviderConfig;
use App\Services\Signature\ProcessadorDeEventoDeAssinatura;
use App\Services\Signature\ResolvedorDeProvedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Porta de entrada do webhook do provedor de assinatura eletrônica (Plano 26,
 * Task 26.3).
 *
 * Irmão de `CobrancaWebhookController` (Plano 19, Task 19.4), que é a
 * referência direta de estilo desta classe. Como lá, quem identifica o tenant
 * é o `{webhookToken}` da URL, específico de cada empresa, que
 * `SignatureProviderConfig::paraToken()` resolve por um hash determinístico (a
 * coluna `webhook_token` é cifrada com IV aleatório e não permite `WHERE`
 * direto). Como lá, esta rota não vem de um formulário nosso: quem chama é o
 * provedor, sem sessão, sem usuário e sem token CSRF — por isso ela está fora
 * da verificação de CSRF (`bootstrap/app.php`), fora do grupo autenticado e
 * fora de qualquer resolução de tenant por middleware.
 *
 * ## A diferença que importa: o corpo não decide nada
 *
 * O provedor de assinatura não assina a requisição (ver o cabeçalho de
 * `ProvedorPadrao::validarWebhook()`), então este controller trata o corpo
 * como o que ele é: um aviso de que **algo mudou** em um documento. Dele sai
 * só o identificador do documento. O que mudou vem de uma consulta
 * autenticada ao provedor, com a credencial do tenant, feita por
 * `SignatureRequestService::sincronizar()`. Nenhum campo do corpo vira
 * situação de contrato.
 *
 * ## Três respostas, e só três
 *
 * - **404** para token que não corresponde a nenhum tenant. Nada é lido, nada
 *   é validado, nada é gravado.
 * - **401** para requisição sem autenticidade, quando o tenant configurou
 *   segredo compartilhado. **Não grava nem o payload**: gravar corpo não
 *   autenticado é convite a encher a tabela por POST de terceiro.
 * - **200** para todo o resto, inclusive evento de tipo desconhecido, evento
 *   de documento que não é desta empresa e evento que falhou no
 *   processamento. Devolver 500 faria o provedor reenviar o mesmo evento em
 *   laço, e o evento já está gravado com o payload inteiro, pronto para
 *   reprocessar.
 *
 * Controller fino: quem conhece a idempotência e o efeito de cada evento é
 * `ProcessadorDeEventoDeAssinatura`.
 */
class AssinaturaWebhookController extends Controller
{
    public function __construct(
        private readonly ResolvedorDeProvedor $resolvedorDeProvedor,
        private readonly ProcessadorDeEventoDeAssinatura $processador,
    ) {}

    /**
     * @param  string  $webhookToken  Token em texto puro, vindo da rota. Nunca comparado por igualdade direta contra o banco: `SignatureProviderConfig::paraToken()` compara pelo hash determinístico.
     */
    public function handle(Request $requisicao, string $webhookToken): JsonResponse
    {
        $configuracao = SignatureProviderConfig::paraToken($webhookToken);

        if (! $configuracao instanceof SignatureProviderConfig) {
            return response()->json([
                'message' => 'Endereço de webhook desconhecido.',
            ], 404);
        }

        $provedor = $this->resolvedorDeProvedor->paraConfiguracao($configuracao);

        if (! $provedor->validarWebhook($configuracao, $requisicao)) {
            return response()->json([
                'message' => 'Assinatura do webhook inválida.',
            ], 401);
        }

        $payload = $requisicao->json()->all();
        $payload = is_array($payload) ? $payload : [];

        $evento = $this->processador->registrar(
            $configuracao,
            $provedor->identificarDocumentoNoWebhook($payload),
            $provedor->tipoDoEventoNoWebhook($payload),
            $payload,
        );

        // Segunda barreira da idempotência, depois do unique de
        // `[company_id, evento_id]`. Reenvio de evento já processado sai daqui
        // sem tocar em pedido nenhum.
        if ($evento->processado_em !== null) {
            return response()->json([
                'message' => 'Evento já processado.',
            ]);
        }

        // Não lança: falha de processamento vira `erro` e `tentativas` na
        // própria linha do evento, e a resposta continua sendo 200.
        $this->processador->processar($evento, $configuracao);

        return response()->json([
            'message' => 'Evento recebido.',
        ]);
    }
}
