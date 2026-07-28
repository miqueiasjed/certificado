<?php

namespace App\Http\Controllers;

use App\Contracts\GatewayAssinatura;
use App\Services\Gateway\PagBankGateway;
use App\Services\GatewayEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Porta de entrada dos webhooks do gateway de pagamento (Plano 7, Task 7.6).
 *
 * Única rota POST do sistema que não vem de um formulário nosso: quem chama é o
 * provedor, sem sessão, sem usuário e sem token CSRF. Por isso ela está fora da
 * verificação de CSRF (`bootstrap/app.php`), fora do grupo autenticado e fora de
 * qualquer resolução de tenant. A autorização vem da assinatura do próprio
 * provedor, conferida em `GatewayAssinatura::validarWebhook()`.
 *
 * ## Três respostas, e só três
 *
 * - **404** para gateway que o sistema não conhece. Nada é lido, nada é
 *   validado, nada é gravado.
 * - **401** para requisição sem autenticidade. **Não grava nem o payload**:
 *   gravar corpo não autenticado é convite a encher a tabela por POST de
 *   terceiro.
 * - **200** para todo o resto, inclusive evento de tipo desconhecido e evento
 *   que falhou no processamento. Devolver 500 faria o provedor reenviar o mesmo
 *   evento em laço, e o evento já está gravado com o payload inteiro, pronto
 *   para `plataforma:reprocessar-eventos` depois que a causa for corrigida.
 *
 * Controller fino: quem conhece a idempotência e o efeito de cada tipo é
 * `GatewayEventService`. O que acontece aqui é a ordem dos passos e a tradução
 * deles em código HTTP.
 */
class GatewayWebhookController extends Controller
{
    /**
     * Nomes de provedor aceitos no `{gateway}` da rota.
     *
     * A lista fica na borda HTTP, e não em `GatewayEventService`, de propósito:
     * é a única camada em que citar a implementação concreta não amarra o
     * domínio a ela. Qual classe atende o contrato continua sendo decisão de
     * `config('services.gateway_assinatura')`; esta constante só diz quais
     * endereços de webhook existem.
     *
     * @var array<int, string>
     */
    public const GATEWAYS_CONHECIDOS = [
        PagBankGateway::NOME,
    ];

    public function __construct(
        private readonly GatewayAssinatura $gateway,
        private readonly GatewayEventService $gatewayEventService,
    ) {}

    /**
     * Recebe, registra e processa um evento do provedor.
     *
     * @param  string  $gateway  Nome do provedor, vindo da rota. Confirmado contra `self::GATEWAYS_CONHECIDOS` antes de qualquer outra coisa.
     */
    public function handle(Request $requisicao, string $gateway): JsonResponse
    {
        if (! in_array($gateway, self::GATEWAYS_CONHECIDOS, true)) {
            return response()->json([
                'message' => 'Gateway de pagamento desconhecido.',
            ], 404);
        }

        if (! $this->gateway->validarWebhook($requisicao)) {
            return response()->json([
                'message' => 'Assinatura do webhook inválida.',
            ], 401);
        }

        // Corpo cru decodificado. É ele que é gravado em `gateway_events.payload`
        // e é dele que sai a interpretação: guardar o evento já traduzido
        // carregaria para sempre um eventual erro de tradução.
        $payload = $requisicao->json()->all();

        $eventoDeGateway = $this->gateway->interpretarWebhook($payload);

        $evento = $this->gatewayEventService->registrar($gateway, $eventoDeGateway, $payload);

        // Segunda barreira da idempotência, depois do unique de `evento_id`
        // (ver `GatewayEventService`). Reenvio de evento já processado sai daqui
        // sem tocar em fatura nenhuma.
        if ($evento->processado_em !== null) {
            return response()->json([
                'message' => 'Evento já processado.',
            ]);
        }

        // Não lança: falha de processamento vira `erro` e `tentativas` na
        // própria linha do evento, e a resposta continua sendo 200.
        $this->gatewayEventService->processar($evento, $eventoDeGateway);

        return response()->json([
            'message' => 'Evento recebido.',
        ]);
    }
}
