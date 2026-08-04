<?php

namespace App\Exceptions;

use App\Models\Charge;
use RuntimeException;

/**
 * Recusa de cancelamento ou reemissão de uma cobrança já paga (Plano 19,
 * Task 19.3).
 *
 * Cobrança paga é fato consumado: o cliente final já pagou o boleto ou o
 * Pix, e cancelar ou reemitir uma cobrança paga não desfaz esse pagamento no
 * mundo real, só criaria um registro divergente do que de fato aconteceu.
 * Lançada por `App\Services\ChargeService::cancelar()` e, por dentro dela,
 * também por `reemitir()`.
 */
class CobrancaJaPagaException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly Charge $cobranca,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  string  $acao  Particípio da ação recusada, ex.: "cancelada", "reemitida".
     */
    public static function paraCobranca(Charge $cobranca, string $acao): self
    {
        return new self(
            sprintf('A cobrança #%d já está paga e não pode ser %s.', $cobranca->getKey(), $acao),
            $cobranca
        );
    }
}
