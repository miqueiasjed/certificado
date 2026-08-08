<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * O provedor de assinatura eletrônica não respondeu, ou respondeu com erro do
 * lado dele (Plano 26, Task 26.2).
 *
 * Cobre falha de rede, tempo esgotado e 5xx. É o **único** caso em que não se
 * sabe se a operação aconteceu do lado do provedor, e por isso é o único que
 * pode ser repetido — mas com cuidado: repetir um envio pode criar um segundo
 * documento do mesmo contrato, então quem repete confere antes se o pedido já
 * tem `provedor_documento_id` gravado (Task 26.3).
 *
 * Separada de `AssinaturaEletronicaRecusouException` pelo mesmo motivo que
 * `GatewayIndisponivelException` é separada de `GatewayRecusouException`
 * (Plano 19): confundir "não sei se aconteceu" com "sei que não aconteceu" é
 * como se cria documento duplicado.
 */
class AssinaturaEletronicaIndisponivelException extends RuntimeException
{
    public const MENSAGEM_PADRAO = 'O provedor de assinatura eletrônica está indisponível. Tente novamente em alguns minutos.';

    public function __construct(
        string $message = self::MENSAGEM_PADRAO,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * A chamada não obteve resposta: rede fora, DNS, tempo esgotado.
     */
    public static function semResposta(string $endpoint, ?Throwable $anterior = null): self
    {
        return new self(
            sprintf(
                'Não foi possível falar com o provedor de assinatura eletrônica em "%s". '
                .'Tente novamente em alguns minutos.',
                $endpoint
            ),
            0,
            $anterior
        );
    }

    /**
     * O provedor respondeu com erro do lado dele (5xx), ou com um corpo que
     * não traz o que a resposta precisava trazer.
     */
    public static function erroDoProvedor(string $endpoint, int $status, ?string $mensagemDoProvedor = null): self
    {
        $texto = sprintf(
            'O provedor de assinatura eletrônica respondeu com erro em "%s" (HTTP %d). '
            .'Tente novamente em alguns minutos.',
            $endpoint,
            $status
        );

        if ($mensagemDoProvedor !== null && trim($mensagemDoProvedor) !== '') {
            $texto .= ' Resposta do provedor: '.trim($mensagemDoProvedor);
        }

        return new self($texto);
    }
}
