<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Um documento tentou sair com parecer gerado por modelo e ainda não revisado
 * por uma pessoa (Plano 25, Task 25.3).
 *
 * Laudo técnico tem responsabilidade profissional: o responsável técnico
 * assina o que sai, e publicar parecer gerado automaticamente transferiria ao
 * cliente um risco que não é dele.
 *
 * A guarda que lança esta exceção vive nos Services de emissão
 * (`WorkOrderService::preparePdfData()` e
 * `CertificateService::preparePdfData()`), nunca na tela: bloqueio só no
 * frontend é bloqueio que a primeira rota nova fura.
 */
class ParecerNaoRevisadoException extends RuntimeException
{
    public const MENSAGEM_PADRAO = 'O parecer foi gerado automaticamente e ainda não foi revisado. O responsável técnico precisa revisar e aprovar o texto antes da emissão.';

    public function __construct(string $message = self::MENSAGEM_PADRAO)
    {
        parent::__construct($message);
    }

    /**
     * Recusa nomeando o documento, para o usuário saber qual dos dois
     * caminhos (OS ou certificado) travou.
     */
    public static function paraDocumento(string $documento): self
    {
        return new self(sprintf(
            'Este %s tem um parecer gerado automaticamente que ainda não foi revisado. O responsável técnico precisa revisar e aprovar o texto antes da emissão.',
            $documento
        ));
    }
}
