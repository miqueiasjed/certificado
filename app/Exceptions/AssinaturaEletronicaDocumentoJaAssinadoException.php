<?php

namespace App\Exceptions;

/**
 * A operação pedida não cabe porque o documento já foi assinado no provedor
 * (Plano 26, Task 26.2): cancelar, reenviar ou alterar um documento concluído.
 *
 * Não é falha: é o provedor protegendo o que já tem valor jurídico. Quem
 * captura mostra a mensagem e recarrega a situação do pedido, que
 * provavelmente ficou para trás em relação ao provedor — e é exatamente o que
 * a sincronização periódica da Task 26.3 corrige.
 */
class AssinaturaEletronicaDocumentoJaAssinadoException extends AssinaturaEletronicaRecusouException
{
    public static function naoPodeMaisSerAlterado(string $operacao): static
    {
        return new static(sprintf(
            'O documento já foi assinado no provedor e não aceita mais %s. '
            .'Atualize a situação do pedido para ver o contrato assinado.',
            $operacao
        ));
    }
}
