<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * O provedor de IA não respondeu, ou respondeu com erro do lado dele
 * (Plano 25).
 *
 * Cobre falha de rede (DNS, conexão recusada, tempo esgotado) e resposta 5xx.
 * A consequência para quem chama é a mesma nos dois casos: **não se sabe** se
 * a geração aconteceu, e repetir é seguro porque nenhum documento é emitido
 * por esta chamada — o que sai dela é rascunho.
 *
 * Separada de `IaRecusouException` de propósito: ali o provedor respondeu e
 * disse não, e repetir a mesma entrada devolve a mesma recusa.
 *
 * Nunca carrega a chave de API: a mensagem é montada com o caminho do endpoint
 * e o status HTTP, e nada mais.
 */
class IaIndisponivelException extends RuntimeException
{
    public const MENSAGEM_PADRAO = 'O serviço de geração de texto não respondeu. Tente novamente em alguns minutos.';

    public function __construct(
        string $message = self::MENSAGEM_PADRAO,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Falha de rede antes de o provedor responder qualquer coisa.
     */
    public static function semResposta(string $endpoint, ?Throwable $anterior = null): self
    {
        return new self(
            sprintf(
                'Não foi possível falar com o serviço de geração de texto em "%s". Tente novamente em alguns minutos.',
                $endpoint
            ),
            0,
            $anterior
        );
    }

    /**
     * O provedor respondeu, mas com erro do lado dele (5xx).
     */
    public static function erroDoProvedor(string $endpoint, int $status, ?string $detalhe = null): self
    {
        $texto = sprintf('O serviço de geração de texto falhou em "%s" (HTTP %d).', $endpoint, $status);

        if ($detalhe !== null && trim($detalhe) !== '') {
            $texto .= ' Resposta do provedor: '.trim($detalhe);
        }

        return new self($texto);
    }

    /**
     * O recurso está ligado, mas a chave de API não foi configurada no
     * servidor. Cai aqui, e não em `IaRecusouException`, porque é falha de
     * ambiente: nenhuma entrada do usuário muda o resultado.
     */
    public static function semChaveConfigurada(): self
    {
        return new self('O serviço de geração de texto não está configurado neste servidor.');
    }
}
