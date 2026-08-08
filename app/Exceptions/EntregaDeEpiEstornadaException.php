<?php

namespace App\Exceptions;

use App\Models\PpeDelivery;
use App\Support\BusinessDate;
use RuntimeException;
use Throwable;

/**
 * Recusa de operação sobre uma entrega de EPI já estornada (Plano 28,
 * Task 28.2).
 *
 * A entrega é documento oponível: não se edita nem se exclui, e a correção de
 * erro é o estorno com motivo, na convenção do movimento de ajuste do razão de
 * estoque do Plano 17. O estorno é o ponto final daquela linha — ela sai dos
 * cálculos de situação e de todo alerta, e **continua** na extração por período,
 * marcada como estornada, porque apagar o rastro do erro é o que a norma não
 * permite.
 *
 * Daí a recusa: assinar, devolver ou estornar de novo uma linha estornada
 * ressuscitaria um registro que já foi declarado errado. Quem precisa registrar
 * a entrega de verdade lança uma entrega nova, e a ficha passa a mostrar as
 * duas: a errada, estornada com motivo, e a certa. É essa sequência que prova
 * que a correção foi correção, e não reescrita.
 */
class EntregaDeEpiEstornadaException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $ppeDeliveryId = null,
        /** Instante do estorno, em `Y-m-d H:i:s`, no fuso do negócio. */
        public readonly ?string $estornadaEm = null,
        public readonly ?string $motivoEstorno = null,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Recusa citando quando a entrega foi estornada e com que motivo.
     *
     * `$operacao` completa a frase "não pode mais ser usada para ..." e por isso
     * chega já no formato que cabe ali ("assinar", "registrar a devolução",
     * "um novo estorno"): o texto precisa dizer o que exatamente foi recusado,
     * e não apenas que a entrega está estornada.
     */
    public static function para(PpeDelivery $entrega, string $operacao): self
    {
        $estorno = BusinessDate::paraFusoNegocio($entrega->estornada_em);
        $motivo = trim((string) $entrega->motivo_estorno);

        $mensagem = sprintf(
            'Esta entrega de EPI foi estornada em %s%s e não pode mais ser usada para %s. '
            .'Registre uma nova entrega: a entrega estornada permanece na ficha como histórico.',
            $estorno?->format('d/m/Y H:i') ?? 'data não registrada',
            $motivo !== '' ? ' ('.$motivo.')' : '',
            $operacao
        );

        return new self(
            $mensagem,
            $entrega->getKey(),
            $estorno?->format('Y-m-d H:i:s'),
            $motivo !== '' ? $motivo : null
        );
    }
}
