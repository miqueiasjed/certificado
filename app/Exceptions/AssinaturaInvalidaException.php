<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * O pedido de assinatura não é válido no domínio, e por isso não chega ao
 * provedor.
 *
 * É a recusa que acontece **antes** da rede: cartão obrigatório que veio vazio,
 * forma de pagamento fora do vocabulário, periodicidade de plano que ninguém
 * sabe converter em data de cobrança, empresa que já assina. Em todos esses
 * casos o erro é de quem chamou o Service, e mandar o pedido assim mesmo só
 * gastaria uma ida ao provedor para receber uma mensagem pior.
 *
 * Por que não `GatewayRecusouException`: aquela significa "o provedor
 * respondeu e disse não", e quem a captura sabe que a operação existiu do lado
 * de lá. Aqui nada aconteceu no provedor, nem chegou perto: confundir as duas
 * faria a tela dizer ao tenant que o cartão dele foi recusado quando o
 * formulário é que não enviou o cartão.
 *
 * Quem lança: `App\Services\SubscriptionService`, sempre antes da primeira
 * chamada ao gateway e antes de qualquer escrita.
 */
class AssinaturaInvalidaException extends RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Forma de pagamento `cartao` sem o texto cifrado do cartão.
     *
     * O cartão é cifrado no navegador com a chave pública da conta no provedor,
     * e é esse texto que precisa chegar ao Service: sem ele não há meio de
     * pagamento a cadastrar, e a assinatura no cartão nasceria sem com o que
     * cobrar a recorrência.
     */
    public static function cartaoCifradoAusente(): self
    {
        return new self(
            'A forma de pagamento "cartao" exige o cartão cifrado no navegador. '
            .'Nenhum dado de cartão em claro chega ao servidor: o formulário cifra com a chave '
            .'pública da conta no provedor e envia o resultado dessa cifragem.'
        );
    }

    /**
     * Forma de pagamento fora do vocabulário de `subscriptions.forma_pagamento`.
     *
     * A coluna é string sem restrição no banco, então esta é a única barreira
     * fora do FormRequest. Um valor estranho gravado ali quebraria a emissão da
     * fatura meses depois, longe de onde o erro foi cometido.
     *
     * @param  array<int, string>  $formasValidas
     */
    public static function formaDePagamentoDesconhecida(string $forma, array $formasValidas): self
    {
        return new self(sprintf(
            'Forma de pagamento "%s" não existe. Formas aceitas: %s.',
            $forma,
            implode(', ', $formasValidas)
        ));
    }

    /**
     * Plano com periodicidade que não se sabe converter em data de cobrança.
     *
     * @param  array<int, string>  $periodicidadesValidas
     */
    public static function periodicidadeDesconhecida(string $periodicidade, array $periodicidadesValidas): self
    {
        return new self(sprintf(
            'O plano tem periodicidade "%s", que não define quando cobrar de novo. Periodicidades aceitas: %s.',
            $periodicidade,
            implode(', ', $periodicidadesValidas)
        ));
    }

    /**
     * A empresa já tem assinatura em vigor.
     *
     * `subscriptions.company_id` é unique: assinar de novo criaria uma segunda
     * assinatura no provedor (cobrando o tenant duas vezes) para depois colidir
     * na inserção local. Recusar antes do gateway é o que impede a cobrança
     * duplicada.
     */
    public static function empresaJaAssinou(?string $nomeDaEmpresa, string $situacaoAtual): self
    {
        $empresa = ($nomeDaEmpresa === null || trim($nomeDaEmpresa) === '')
            ? 'A empresa'
            : sprintf('A empresa "%s"', trim($nomeDaEmpresa));

        return new self(sprintf(
            '%s já tem assinatura com situação "%s". Para mudar de plano use a troca de plano, '
            .'e para reativar depois de cancelar assine de novo só após o cancelamento estar registrado.',
            $empresa,
            $situacaoAtual
        ));
    }

    /**
     * Operação que depende do identificador da assinatura no provedor, numa
     * assinatura que não tem esse identificador gravado.
     *
     * Trocar o plano só no banco deixaria o tenant sendo cobrado pelo valor
     * antigo (ou pelo novo sem que o provedor saiba), que é erro de dinheiro.
     * Cancelar é a exceção deliberada: cancelamento nunca é bloqueado por isso.
     */
    public static function semIdentificadorNoGateway(string $operacao): self
    {
        return new self(sprintf(
            'A assinatura não tem identificador no provedor de pagamento, então "%s" não pode ser feito lá. '
            .'Concilie a assinatura com o provedor antes de tentar de novo.',
            $operacao
        ));
    }
}
