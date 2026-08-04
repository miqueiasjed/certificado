<?php

namespace App\Exceptions;

/**
 * O provedor recusou a cobrança porque o valor está abaixo do mínimo que ele
 * aceita (Plano 19, Task 19.2).
 *
 * Estende `GatewayRecusouException` pelo mesmo motivo das outras recusas
 * traduzidas desta task: é 4xx, não se repete, e ganha o tipo próprio para
 * quem precisa orientar o tenant a rever o valor do título, em vez de mostrar
 * a recusa genérica do provedor.
 */
class GatewayValorAbaixoDoMinimoException extends GatewayRecusouException
{
    /**
     * Recusa vinda de uma resposta do provedor cujo erro aponta para o valor
     * da cobrança.
     *
     * `$endpoint` é o caminho da chamada, nunca a URL completa com query.
     */
    public static function comRespostaDoProvedor(string $endpoint, int $status, ?string $mensagemDoProvedor = null): self
    {
        $texto = sprintf(
            'O provedor de pagamento recusou por valor abaixo do mínimo aceito em "%s" (HTTP %d).',
            $endpoint,
            $status
        );

        if ($mensagemDoProvedor !== null && trim($mensagemDoProvedor) !== '') {
            $texto .= ' Resposta do provedor: '.trim($mensagemDoProvedor);
        }

        return new self($texto);
    }
}
