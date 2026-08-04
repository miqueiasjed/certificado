<?php

namespace App\Exceptions;

/**
 * O provedor recusou a cobrança por dado do cliente final incompleto ou
 * inválido: CPF ou CNPJ ausente, e-mail ou endereço que o provedor não aceita
 * (Plano 19, Task 19.2).
 *
 * Estende `GatewayRecusouException` pelo mesmo motivo de
 * `GatewayCredencialInvalidaException`: é uma recusa 4xx que não se repete, e
 * ganha o tipo próprio para quem precisa mostrar ao tenant "corrija o
 * cadastro do cliente", em vez de "provedor recusou" genérico.
 *
 * `App\Services\Payments\ValidadorDeDadosDeCobranca` (Task 19.3) confere o
 * cadastro do cliente **antes** de chamar o gateway, para que este caso seja
 * raro: o que sobra para cá é o que a validação local não pega, como CPF que
 * passa no formato mas o provedor recusa por outro motivo.
 */
class GatewayDadosClienteInvalidosException extends GatewayRecusouException
{
    /**
     * Recusa vinda de uma resposta do provedor cujo erro aponta para o
     * cadastro do cliente final (CPF/CNPJ, e-mail, endereço).
     *
     * `$endpoint` é o caminho da chamada, nunca a URL completa com query.
     */
    public static function comRespostaDoProvedor(string $endpoint, int $status, ?string $mensagemDoProvedor = null): self
    {
        $texto = sprintf(
            'O provedor de pagamento recusou por dado do cliente incompleto ou inválido em "%s" (HTTP %d). '
            .'Confira CPF/CNPJ, e-mail e endereço do cliente.',
            $endpoint,
            $status
        );

        if ($mensagemDoProvedor !== null && trim($mensagemDoProvedor) !== '') {
            $texto .= ' Resposta do provedor: '.trim($mensagemDoProvedor);
        }

        return new self($texto);
    }
}
