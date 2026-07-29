<?php

namespace App\Exceptions;

use App\Models\AppointmentRequest;
use RuntimeException;
use Throwable;

/**
 * Recusa de confirmar ou recusar um pedido de horário que já saiu de
 * `pendente` (Plano 16, Task 16.4).
 *
 * Existe porque dois atendentes podem abrir o mesmo pedido ao mesmo tempo: o
 * primeiro confirma ou recusa, e o segundo, alguns segundos depois, tenta
 * agir sobre um pedido que já tem resposta. `AppointmentRequestService`
 * relança esta exceção sempre a partir de uma releitura do registro feita sob
 * `lockForUpdate()`, dentro de uma transação, para o segundo atendente
 * receber a situação real (a que o primeiro acabou de gravar), nunca a que
 * ele via na tela quando abriu o pedido.
 *
 * `situacaoAtual` fica pública e tipada, e não só embutida na mensagem: é o
 * que permite ao controller devolver o 422 com a situação separada da frase
 * em português, como a Task 16.4 exige ("devolve 422 com a situação atual").
 */
class PedidoDeHorarioJaRespondidoException extends RuntimeException
{
    public function __construct(
        public readonly string $situacaoAtual,
        string $message,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function paraPedido(AppointmentRequest $pedido): self
    {
        $rotulo = match ($pedido->situacao) {
            AppointmentRequest::SITUACAO_CONFIRMADA => 'já foi confirmado',
            AppointmentRequest::SITUACAO_RECUSADA => 'já foi recusado',
            AppointmentRequest::SITUACAO_CANCELADA => 'foi cancelado',
            default => 'não está mais pendente',
        };

        return new self(
            (string) $pedido->situacao,
            "Este pedido {$rotulo} e não pode ser respondido de novo."
        );
    }
}
