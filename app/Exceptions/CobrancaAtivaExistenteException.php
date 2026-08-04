<?php

namespace App\Exceptions;

use App\Models\Charge;
use App\Models\ReceivableInstallment;
use RuntimeException;

/**
 * Recusa de emissão de cobrança porque a parcela já tem uma cobrança ativa
 * (Plano 19, Task 19.3).
 *
 * Regra de negócio crítica deste plano: uma cobrança ativa por parcela.
 * Boleto antigo e novo abertos ao mesmo tempo geram pagamento em duplicidade,
 * e devolver dinheiro ao cliente final é problema da empresa, não da
 * plataforma. Lançada por `App\Services\ChargeService::emitir()` antes de
 * chamar o gateway; `reemitir()` cancela a cobrança ativa primeiro e só
 * então chama `emitir()`, então nunca esbarra nesta exceção.
 */
class CobrancaAtivaExistenteException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly Charge $cobrancaAtiva,
    ) {
        parent::__construct($message);
    }

    public static function paraParcela(ReceivableInstallment $parcela, Charge $cobrancaAtiva): self
    {
        return new self(
            sprintf(
                'A parcela #%d já tem uma cobrança de %s em aberto (#%d, situação "%s"). '
                .'Cancele ou reemita a cobrança existente antes de gerar outra, para o cliente não pagar em duplicidade.',
                $parcela->getKey(),
                $cobrancaAtiva->tipo,
                $cobrancaAtiva->getKey(),
                $cobrancaAtiva->situacao
            ),
            $cobrancaAtiva
        );
    }
}
