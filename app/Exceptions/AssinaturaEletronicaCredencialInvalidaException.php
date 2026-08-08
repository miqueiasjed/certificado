<?php

namespace App\Exceptions;

/**
 * O provedor recusou a credencial do tenant (Plano 26, Task 26.2): token
 * ausente, revogado, ou digitado errado ao configurar a integração.
 *
 * Estende `AssinaturaEletronicaRecusouException` de propósito: continua sendo
 * uma recusa 4xx que não pode ser repetida, e ganha o tipo próprio para quem
 * precisa distinguir "configure de novo a integração" das demais recusas de
 * negócio. Mesmo desenho de `GatewayCredencialInvalidaException` (Plano 19).
 *
 * Nunca carrega a credencial em si, nem parcial: só o endpoint, o status HTTP
 * e a mensagem que o provedor devolveu.
 */
class AssinaturaEletronicaCredencialInvalidaException extends AssinaturaEletronicaRecusouException
{
    /**
     * Recusa decidida antes de chamar o provedor: a configuração do tenant não
     * tem token nenhum guardado em `credenciais`.
     */
    public static function semCredencialConfigurada(): static
    {
        return new static(
            'Nenhuma credencial configurada para o provedor de assinatura eletrônica. '
            .'Cadastre o token na tela de integração antes de enviar contrato para assinatura.'
        );
    }

    /**
     * Recusa vinda de uma resposta 401/403 do provedor.
     */
    public static function recusadaPeloProvedor(string $endpoint, int $status, ?string $mensagemDoProvedor = null): static
    {
        $texto = sprintf(
            'O provedor de assinatura eletrônica recusou a credencial em "%s" (HTTP %d). '
            .'Confira o token configurado para esta integração.',
            $endpoint,
            $status
        );

        if ($mensagemDoProvedor !== null && trim($mensagemDoProvedor) !== '') {
            $texto .= ' Resposta do provedor: '.trim($mensagemDoProvedor);
        }

        return new static($texto);
    }
}
