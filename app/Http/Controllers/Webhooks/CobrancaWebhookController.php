<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\PaymentGatewayConfig;
use App\Services\Payments\ProcessadorDeEventoDeCobranca;
use App\Services\Payments\ResolvedorDeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Porta de entrada do webhook de cobrança do tenant ao cliente final (Plano
 * 19, Task 19.4).
 *
 * Irmã de `App\Http\Controllers\GatewayWebhookController` (Plano 7, webhook da
 * assinatura que o tenant paga à plataforma), que é a referência direta de
 * estilo desta classe. A diferença entre as duas é o que identifica de quem é
 * o webhook: lá é o nome do gateway na rota (`{gateway}`), fixo e conhecido de
 * antemão; aqui é o `{webhookToken}` da URL, específico de cada tenant, que
 * `PaymentGatewayConfig::paraToken()` resolve por um hash determinístico (a
 * coluna `webhook_token` é cifrada com IV aleatório e não permite `WHERE`
 * direto — decisão documentada no cabeçalho do model). Assim como lá, esta
 * rota não vem de um formulário nosso: quem chama é o gateway de pagamento,
 * sem sessão, sem usuário e sem token CSRF — por isso ela está fora da
 * verificação de CSRF (`bootstrap/app.php`), fora do grupo autenticado e fora
 * de qualquer resolução de tenant por middleware.
 *
 * ## Três respostas, e só três
 *
 * - **404** para token que não corresponde a nenhum tenant. Nada é lido, nada
 *   é validado, nada é gravado.
 * - **401** para requisição sem autenticidade. **Não grava nem o payload**:
 *   gravar corpo não autenticado é convite a encher a tabela por POST de
 *   terceiro, e pior aqui do que no webhook da plataforma — este token, uma
 *   vez descoberto, aponta para o tenant certo.
 * - **200** para todo o resto, inclusive evento de tipo desconhecido e evento
 *   que falhou no processamento. Devolver 500 faria o provedor reenviar o
 *   mesmo evento em laço, e o evento já está gravado com o payload inteiro,
 *   pronto para reprocessar depois que a causa for corrigida.
 *
 * Controller fino: quem conhece a idempotência e o efeito de cada tipo é
 * `ProcessadorDeEventoDeCobranca`. O que acontece aqui é a ordem dos passos e
 * a tradução deles em código HTTP.
 */
class CobrancaWebhookController extends Controller
{
    public function __construct(
        private readonly ResolvedorDeGateway $resolvedorDeGateway,
        private readonly ProcessadorDeEventoDeCobranca $processador,
    ) {}

    /**
     * Recebe, registra e processa um evento de cobrança do gateway do tenant.
     *
     * @param  string  $webhookToken  Token em texto puro, vindo da rota. Nunca comparado por igualdade direta contra o banco: `PaymentGatewayConfig::paraToken()` compara pelo hash determinístico.
     */
    public function handle(Request $requisicao, string $webhookToken): JsonResponse
    {
        $configuracao = PaymentGatewayConfig::paraToken($webhookToken);

        if (! $configuracao instanceof PaymentGatewayConfig) {
            return response()->json([
                'message' => 'Endereço de webhook desconhecido.',
            ], 404);
        }

        $gateway = $this->resolvedorDeGateway->paraConfiguracao($configuracao);

        if (! $gateway->validarWebhook($configuracao, $requisicao)) {
            return response()->json([
                'message' => 'Assinatura do webhook inválida.',
            ], 401);
        }

        // Corpo cru decodificado. É ele que é gravado em `gateway_events.payload`
        // e é dele que sai a interpretação: guardar o evento já traduzido
        // carregaria para sempre um eventual erro de tradução.
        $payload = $requisicao->json()->all();

        $eventoDeCobranca = $gateway->interpretarWebhook($payload);

        $evento = $this->processador->registrar($configuracao, $eventoDeCobranca, $payload);

        // Segunda barreira da idempotência, depois do unique de `evento_id`
        // (ver `ProcessadorDeEventoDeCobranca`). Reenvio de evento já
        // processado sai daqui sem tocar em cobrança nenhuma.
        if ($evento->processado_em !== null) {
            return response()->json([
                'message' => 'Evento já processado.',
            ]);
        }

        // Não lança: falha de processamento vira `erro` e `tentativas` na
        // própria linha do evento, e a resposta continua sendo 200.
        $this->processador->processar($evento, $eventoDeCobranca, $configuracao);

        return response()->json([
            'message' => 'Evento recebido.',
        ]);
    }
}
