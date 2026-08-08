<?php

namespace App\Exceptions;

/**
 * O provedor recusou um signatário do pedido (Plano 26, Task 26.2): e-mail mal
 * formado, e-mail ausente, telefone inválido para o modo de autenticação
 * escolhido.
 *
 * É o erro de negócio mais comum do envio, e é justamente o que **não** pode
 * ser repetido automaticamente: reenviar o mesmo documento com o mesmo e-mail
 * errado gera uma segunda cobrança do provedor sem resolver nada. Quem corrige
 * é a pessoa que digitou o e-mail, na tela do contrato.
 */
class AssinaturaEletronicaSignatarioInvalidoException extends AssinaturaEletronicaRecusouException
{
    /**
     * Recusa decidida antes de chamar o provedor, quando o e-mail do
     * signatário nem chega perto de um endereço válido. Mandar assim mesmo só
     * gastaria uma ida à rede para receber uma mensagem pior.
     */
    public static function emailInvalido(string $nome, string $email): static
    {
        return new static(sprintf(
            'O e-mail "%s", do signatário "%s", não é um endereço válido. '
            .'Corrija o e-mail antes de enviar o contrato para assinatura.',
            $email,
            $nome
        ));
    }

    /**
     * Recusa decidida antes de chamar o provedor: pedido sem signatário
     * nenhum, ou sem uma das duas partes.
     */
    public static function listaInvalida(string $motivo): static
    {
        return new static($motivo);
    }
}
