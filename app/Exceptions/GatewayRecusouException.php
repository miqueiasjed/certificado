<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * O gateway de pagamento respondeu e recusou a operação.
 *
 * Cobre resposta 4xx: dado inválido, plano inexistente no provedor, cartão
 * negado, assinatura já cancelada, forma de pagamento não suportada. O provedor
 * respondeu, então **sabe-se** que a operação não aconteceu, e o pedido não
 * muda de resultado se for reenviado igual.
 *
 * Por isso repetir é proibido aqui, e é a única razão de esta classe existir
 * separada de `GatewayIndisponivelException`: reenviar uma cobrança recusada é
 * como se gera cobrança duplicada no cartão do cliente. O cliente HTTP de
 * `PagBankGateway` nunca faz retry de 4xx, e quem captura esta exceção também
 * não pode fazer.
 *
 * A mensagem do provedor é preservada porque é ela que diz o que corrigir
 * ("tax_id inválido", "plano inativo"). Nunca carrega token, credencial ou
 * dado de cartão: só o caminho do endpoint, o status e o texto que o provedor
 * devolveu.
 */
class GatewayRecusouException extends RuntimeException
{
    /**
     * Texto exibido ao usuário quando o provedor recusa sem explicar.
     */
    public const MENSAGEM_PADRAO = 'O provedor de pagamento recusou a operação.';

    public function __construct(
        string $message = self::MENSAGEM_PADRAO,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Recusa vinda de uma resposta 4xx do provedor.
     *
     * `$endpoint` é o caminho da chamada, nunca a URL completa com query.
     */
    public static function comRespostaDoProvedor(string $endpoint, int $status, ?string $mensagemDoProvedor = null): self
    {
        $texto = sprintf(
            'O provedor de pagamento recusou a operação em "%s" (HTTP %d).',
            $endpoint,
            $status
        );

        if ($mensagemDoProvedor !== null && trim($mensagemDoProvedor) !== '') {
            $texto .= ' Resposta do provedor: '.trim($mensagemDoProvedor);
        }

        return new self($texto);
    }

    /**
     * Recusa decidida antes de chamar o provedor, quando a operação pedida não
     * existe na API dele.
     *
     * O caso concreto de hoje: o PagBank não cobra assinatura recorrente por
     * Pix, e não cobra fatura de cartão fora do motor de recorrência. Mandar o
     * pedido assim mesmo só gastaria uma ida à rede para receber o mesmo não.
     */
    public static function operacaoNaoSuportada(string $motivo): self
    {
        return new self($motivo);
    }
}
