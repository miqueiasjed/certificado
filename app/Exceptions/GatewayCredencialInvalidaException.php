<?php

namespace App\Exceptions;

/**
 * O provedor recusou a credencial do tenant (Plano 19, Task 19.2): token
 * ausente, revogado, ou digitado errado ao configurar o gateway.
 *
 * Estende `GatewayRecusouException` de propósito: continua sendo uma recusa
 * 4xx que não pode ser repetida (ver o cabeçalho da classe-mãe), e ganha o
 * tipo próprio para quem precisa distinguir "configure de novo o gateway" das
 * demais recusas de negócio (dado do cliente incompleto, valor abaixo do
 * mínimo). Um `catch (GatewayRecusouException)` genérico continua
 * funcionando, porque esta classe é uma delas.
 *
 * Nunca carrega a credencial em si, nem parcial: só o endpoint, o status HTTP
 * e a mensagem que o provedor devolveu.
 */
class GatewayCredencialInvalidaException extends GatewayRecusouException
{
    /**
     * Recusa decidida antes de chamar o provedor: a configuração do tenant
     * não tem token nenhum guardado em `credenciais`.
     *
     * Mandar a chamada assim mesmo só gastaria uma ida à rede para receber a
     * mesma recusa, com uma mensagem do provedor mais obscura que esta.
     */
    public static function semCredencialConfigurada(): self
    {
        return new self(
            'Nenhuma credencial configurada para este gateway. '
            .'Configure o token na tela de integração antes de emitir cobrança.'
        );
    }

    /**
     * Recusa vinda de uma resposta 401/403 do provedor.
     *
     * `$endpoint` é o caminho da chamada, nunca a URL completa com query.
     */
    public static function recusadaPeloProvedor(string $endpoint, int $status, ?string $mensagemDoProvedor = null): self
    {
        $texto = sprintf(
            'O provedor de pagamento recusou a credencial em "%s" (HTTP %d). '
            .'Confira o token configurado para este gateway.',
            $endpoint,
            $status
        );

        if ($mensagemDoProvedor !== null && trim($mensagemDoProvedor) !== '') {
            $texto .= ' Resposta do provedor: '.trim($mensagemDoProvedor);
        }

        return new self($texto);
    }
}
