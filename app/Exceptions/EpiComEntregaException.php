<?php

namespace App\Exceptions;

use App\Models\PersonalProtectiveEquipment;
use RuntimeException;
use Throwable;

/**
 * Recusa de exclusão de um modelo de EPI que já tem entrega registrada
 * (Plano 28, Task 28.2).
 *
 * A ficha antiga aponta para o cadastro: é dele que saem nome, tipo e fabricante
 * do que o técnico recebeu. Excluir o modelo esvaziaria um registro que a
 * fiscalização pode pedir vinte anos depois — e o banco já recusa, pelo
 * `restrictOnDelete` das chaves estrangeiras de `ppe_deliveries`. Esta exceção
 * existe para que a recusa chegue ao usuário em português, dizendo quantas
 * entregas dependem do cadastro e qual é o caminho certo, em vez de subir como
 * erro de integridade do MySQL e virar um 500 sem explicação.
 *
 * O caminho certo é **inativar**: o modelo sai da lista de escolha de novas
 * entregas e as fichas já emitidas continuam íntegras. Mesma lógica pela qual a
 * entrega se estorna em vez de se apagar.
 */
class EpiComEntregaException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $personalProtectiveEquipmentId = null,
        public readonly ?string $nomeDoEpi = null,
        public readonly int $entregas = 0,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Recusa citando o EPI e quantas entregas dependem dele.
     */
    public static function para(PersonalProtectiveEquipment $epi, int $entregas): self
    {
        $mensagem = sprintf(
            'O EPI "%s" não pode ser excluído porque já tem %s registrada%s na ficha de técnicos. '
            .'Inative o cadastro: ele deixa de aparecer para novas entregas e as fichas já emitidas '
            .'continuam válidas.',
            $epi->nome,
            $entregas === 1 ? '1 entrega' : $entregas.' entregas',
            $entregas === 1 ? '' : 's'
        );

        return new self($mensagem, $epi->getKey(), (string) $epi->nome, $entregas);
    }
}
