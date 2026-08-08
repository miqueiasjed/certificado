<?php

namespace App\Exceptions;

use App\Support\BusinessDate;
use DateTimeInterface;

/**
 * O prazo de assinatura do documento já passou (Plano 26, Task 26.2).
 *
 * Documento expirado no provedor não aceita assinatura nem reenvio: o caminho
 * é cancelar o pedido e criar um novo, com prazo novo. Repetir a chamada não
 * muda nada, por isso é uma recusa e não uma indisponibilidade.
 *
 * O prazo é comparado por **dia**, no fuso do negócio (ver a skill
 * `datas-timezone`): `signature_requests.expira_em` é `date`, e um pedido que
 * expira hoje ainda pode ser assinado hoje.
 */
class AssinaturaEletronicaPrazoExpiradoException extends AssinaturaEletronicaRecusouException
{
    /**
     * Recusa decidida antes de chamar o provedor, a partir de
     * `signature_requests.expira_em`.
     */
    public static function paraPedido(DateTimeInterface|string|null $expiraEm): static
    {
        $dia = $expiraEm === null ? null : BusinessDate::paraFusoNegocio($expiraEm);

        if ($dia === null) {
            return new static(
                'O prazo de assinatura deste pedido já passou. '
                .'Cancele o pedido e envie o contrato novamente, com um prazo novo.'
            );
        }

        return new static(sprintf(
            'O prazo de assinatura deste pedido venceu em %s. '
            .'Cancele o pedido e envie o contrato novamente, com um prazo novo.',
            $dia->format('d/m/Y')
        ));
    }

    /**
     * Recusa vinda do provedor, quando é ele quem informa que o prazo passou.
     */
    public static function recusadoPeloProvedor(string $endpoint, int $status, ?string $mensagemDoProvedor = null): static
    {
        $texto = sprintf(
            'O provedor de assinatura eletrônica informou que o prazo do documento já passou, em "%s" (HTTP %d). '
            .'Cancele o pedido e envie o contrato novamente, com um prazo novo.',
            $endpoint,
            $status
        );

        if ($mensagemDoProvedor !== null && trim($mensagemDoProvedor) !== '') {
            $texto .= ' Resposta do provedor: '.trim($mensagemDoProvedor);
        }

        return new static($texto);
    }
}
