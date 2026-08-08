<?php

namespace App\Exceptions;

/**
 * O PDF do contrato passa do limite de tamanho aceito pelo provedor
 * (Plano 26, Task 26.2).
 *
 * Vale a pena existir separada porque a correção é específica e não é do
 * usuário comum: reduzir o PDF (imagens do cabeçalho, anexos) ou dividir o
 * documento. Repetir o envio não muda nada.
 */
class AssinaturaEletronicaArquivoGrandeDemaisException extends AssinaturaEletronicaRecusouException
{
    /**
     * Recusa decidida antes de chamar o provedor, quando o arquivo já passa do
     * limite conhecido. Evita subir um arquivo grande à toa.
     */
    public static function acimaDoLimite(int $bytes, int $limiteEmBytes): static
    {
        return new static(sprintf(
            'O PDF do contrato tem %s e o provedor de assinatura aceita no máximo %s. '
            .'Reduza o tamanho do arquivo antes de enviar.',
            self::emMegabytes($bytes),
            self::emMegabytes($limiteEmBytes)
        ));
    }

    /**
     * Recusa vinda do provedor (413, ou 4xx cuja mensagem fala de tamanho).
     */
    public static function recusadoPeloProvedor(string $endpoint, int $status, ?string $mensagemDoProvedor = null): static
    {
        $texto = sprintf(
            'O provedor de assinatura eletrônica recusou o arquivo em "%s" por tamanho (HTTP %d). '
            .'Reduza o PDF do contrato antes de enviar.',
            $endpoint,
            $status
        );

        if ($mensagemDoProvedor !== null && trim($mensagemDoProvedor) !== '') {
            $texto .= ' Resposta do provedor: '.trim($mensagemDoProvedor);
        }

        return new static($texto);
    }

    private static function emMegabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, ',', '.').' MB';
    }
}
