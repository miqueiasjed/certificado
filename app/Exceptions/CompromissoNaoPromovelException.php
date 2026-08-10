<?php

namespace App\Exceptions;

use App\Models\Compromisso;
use RuntimeException;
use Throwable;

/**
 * Recusa de promoção de um `Compromisso` para uma ordem de serviço de verdade
 * (Plano 30, Task 30.2).
 *
 * A promoção (`CompromissoService::promoverParaOs()`) é opcional e acontece
 * uma única vez: um compromisso já promovido tem uma OS de verdade
 * representando aquele atendimento, e promover de novo criaria uma segunda OS
 * para a mesma coisa. Um compromisso cancelado nunca devia ter existido como
 * atendimento a acontecer, então também não vira OS. Esta exceção existe para
 * que as duas recusas cheguem à tela em português, com o motivo específico,
 * mesmo padrão de `EpiComEntregaException`.
 */
class CompromissoNaoPromovelException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $compromissoId = null,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Recusa por já existir uma OS gerada a partir deste compromisso.
     */
    public static function jaPromovido(Compromisso $compromisso): self
    {
        $mensagem = sprintf(
            'O compromisso "%s" já foi promovido para a ordem de serviço #%d e não pode ser promovido novamente.',
            $compromisso->titulo,
            $compromisso->work_order_id
        );

        return new self($mensagem, $compromisso->getKey());
    }

    /**
     * Recusa por o compromisso estar cancelado.
     */
    public static function cancelado(Compromisso $compromisso): self
    {
        $mensagem = sprintf(
            'O compromisso "%s" está cancelado e não pode ser promovido para uma ordem de serviço.',
            $compromisso->titulo
        );

        return new self($mensagem, $compromisso->getKey());
    }
}
