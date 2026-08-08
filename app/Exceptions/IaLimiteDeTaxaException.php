<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * O provedor de IA recusou a chamada por limite de taxa (HTTP 429, Plano 25).
 *
 * Separada de `IaIndisponivelException` porque a orientação a quem chama é
 * diferente: aqui a chamada é válida e vai funcionar mais tarde, sem mudar
 * nada na entrada. Repetir imediatamente só piora.
 *
 * Não confundir com o teto de uso por tenant (Task 25.5): aquele é limite
 * comercial nosso, medido em `ai_usages`; este é limite da conta na
 * plataforma do provedor.
 */
class IaLimiteDeTaxaException extends RuntimeException
{
    public const MENSAGEM_PADRAO = 'O serviço de geração de texto atingiu o limite de chamadas. Tente novamente em alguns minutos.';

    public function __construct(
        string $message = self::MENSAGEM_PADRAO,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Quando o provedor informa em quantos segundos a chamada volta a ser
     * aceita, a espera vira parte da mensagem: o usuário precisa saber se
     * espera meio minuto ou meia hora.
     */
    public static function comEspera(?int $segundos = null): self
    {
        if ($segundos === null || $segundos <= 0) {
            return new self;
        }

        return new self(sprintf(
            'O serviço de geração de texto atingiu o limite de chamadas. Tente novamente em %d segundo(s).',
            $segundos
        ));
    }
}
