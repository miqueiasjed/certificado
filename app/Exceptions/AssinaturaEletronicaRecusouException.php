<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * O provedor de assinatura eletrônica respondeu e recusou a operação
 * (Plano 26, Task 26.2).
 *
 * Cobre resposta 4xx: signatário com e-mail inválido, arquivo acima do limite,
 * documento que já foi assinado, prazo vencido. O provedor respondeu, então
 * **sabe-se** que a operação não aconteceu, e o pedido não muda de resultado
 * se for reenviado igual.
 *
 * Por isso repetir é proibido aqui, e é a única razão de esta classe existir
 * separada de `AssinaturaEletronicaIndisponivelException`: reenviar um
 * documento recusado por e-mail inválido gera uma segunda cobrança do provedor
 * sem resolver nada, e — pior — pode gerar um segundo documento válido do
 * mesmo contrato. O cliente HTTP de `ProvedorPadrao` nunca repete 4xx, e quem
 * captura esta exceção também não pode.
 *
 * Espelha `GatewayRecusouException` (Plano 19), inclusive na hierarquia: as
 * exceções mais específicas estendem esta, então um `catch` genérico continua
 * funcionando.
 *
 * A mensagem do provedor é preservada porque é ela que diz o que corrigir.
 * Nunca carrega token nem credencial: só o caminho do endpoint, o status e o
 * texto devolvido.
 */
class AssinaturaEletronicaRecusouException extends RuntimeException
{
    /**
     * Texto exibido ao usuário quando o provedor recusa sem explicar.
     */
    public const MENSAGEM_PADRAO = 'O provedor de assinatura eletrônica recusou a operação.';

    public function __construct(
        string $message = self::MENSAGEM_PADRAO,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Recusa vinda de uma resposta 4xx do provedor.
     *
     * `$endpoint` é o caminho da chamada, nunca a URL completa com query.
     */
    public static function comRespostaDoProvedor(string $endpoint, int $status, ?string $mensagemDoProvedor = null): static
    {
        $texto = sprintf(
            'O provedor de assinatura eletrônica recusou a operação em "%s" (HTTP %d).',
            $endpoint,
            $status
        );

        if ($mensagemDoProvedor !== null && trim($mensagemDoProvedor) !== '') {
            $texto .= ' Resposta do provedor: '.trim($mensagemDoProvedor);
        }

        return new static($texto);
    }
}
