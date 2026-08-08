<?php

namespace App\Exceptions;

use App\Models\Contract;
use RuntimeException;

/**
 * O contrato tem um pedido de assinatura eletrônica em aberto, e a operação
 * pedida esbarra nisso (Plano 26, Task 26.3).
 *
 * Duas invariantes do plano passam por aqui, e as duas existem porque contrato
 * assinado tem valor perante fiscalização:
 *
 * 1. **Contrato em assinatura é imutável.** Alterar o texto enquanto o cliente
 *    lê o PDF já enviado significaria que ele assinou uma versão diferente da
 *    que foi aceita. Não há como consertar isso depois da assinatura.
 * 2. **Um pedido em aberto por contrato.** Dois pedidos abertos do mesmo
 *    contrato produziriam duas assinaturas válidas de textos possivelmente
 *    diferentes, e nada no sistema saberia dizer qual delas vale.
 *
 * O caminho em ambos os casos é o mesmo: cancelar o pedido em aberto e, aí
 * sim, alterar o texto ou enviar de novo.
 */
class ContratoEmAssinaturaException extends RuntimeException
{
    public static function jaTemPedidoEmAberto(Contract $contrato): self
    {
        return new self(sprintf(
            'O contrato %s já tem um pedido de assinatura em aberto. '
            .'Cancele o pedido atual antes de enviar outro: dois pedidos abertos do mesmo contrato '
            .'geram duas assinaturas válidas de textos possivelmente diferentes.',
            $contrato->contract_number ?? ('#'.$contrato->getKey())
        ));
    }

    public static function naoPodeSerAlterado(Contract $contrato): self
    {
        return new self(sprintf(
            'O contrato %s está em assinatura e não pode ser alterado nem excluído. '
            .'Cancele o pedido de assinatura antes: alterar o texto enquanto o cliente lê o PDF enviado '
            .'faria a assinatura valer para uma versão diferente da aceita.',
            $contrato->contract_number ?? ('#'.$contrato->getKey())
        ));
    }

    /**
     * A operação exige um pedido ainda vivo, e o pedido já se encerrou
     * (assinado, recusado, expirado ou cancelado).
     */
    public static function pedidoJaEncerrado(string $situacao): self
    {
        return new self(sprintf(
            'Este pedido de assinatura já está %s e não aceita mais esta operação. '
            .'Envie o contrato novamente para criar um pedido novo.',
            str_replace('_', ' ', $situacao)
        ));
    }
}
