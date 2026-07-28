<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * O gateway de pagamento não respondeu, ou respondeu com erro do lado dele.
 *
 * Cobre falha de rede (DNS, conexão recusada, timeout) e resposta 5xx. As duas
 * têm a mesma consequência para quem chama: **não se sabe** se a operação
 * aconteceu no provedor, e por isso repetir é seguro só enquanto a chamada for
 * idempotente. É essa incerteza que separa esta exceção de
 * `GatewayRecusouException`, em que o provedor respondeu e disse não.
 *
 * A distinção não é decorativa. `SubscriptionService` e `InvoiceService` (Tasks
 * 7.4 e 7.5) precisam repetir a chamada mais tarde quando ela cai aqui, e
 * precisam **não** repetir quando cai em `GatewayRecusouException`: reenviar
 * uma cobrança recusada gera cobrança duplicada no cartão do cliente.
 *
 * Nunca carrega token, credencial ou dado de cartão: a mensagem é montada a
 * partir do endpoint (só o caminho, sem query) e do status HTTP.
 */
class GatewayIndisponivelException extends RuntimeException
{
    /**
     * Texto exibido ao usuário quando não há detalhe do provedor para citar.
     */
    public const MENSAGEM_PADRAO = 'O provedor de pagamento não respondeu. Tente novamente em alguns minutos.';

    public function __construct(
        string $message = self::MENSAGEM_PADRAO,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Falha de rede antes de o provedor responder qualquer coisa.
     *
     * `$endpoint` é o caminho da chamada (`/subscriptions`, por exemplo),
     * nunca a URL completa com query: parâmetro de query é onde credencial
     * acaba vazando para dentro de mensagem de erro.
     */
    public static function semResposta(string $endpoint, ?Throwable $anterior = null): self
    {
        return new self(
            sprintf(
                'Não foi possível falar com o provedor de pagamento em "%s". Tente novamente em alguns minutos.',
                $endpoint
            ),
            0,
            $anterior
        );
    }

    /**
     * O provedor respondeu, mas com erro do lado dele (5xx).
     *
     * A mensagem do provedor é preservada quando existe, porque é ela que
     * permite abrir chamado citando o que ele mesmo devolveu.
     */
    public static function erroDoProvedor(string $endpoint, int $status, ?string $mensagemDoProvedor = null): self
    {
        $texto = sprintf(
            'O provedor de pagamento falhou em "%s" (HTTP %d).',
            $endpoint,
            $status
        );

        if ($mensagemDoProvedor !== null && trim($mensagemDoProvedor) !== '') {
            $texto .= ' Resposta do provedor: '.trim($mensagemDoProvedor);
        }

        return new self($texto);
    }
}
