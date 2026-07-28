<?php

namespace App\Support\Gateway;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * O que o provedor devolveu sobre uma cobrança (fatura), no vocabulário do
 * domínio: link, linha digitável ou QR Pix conforme a forma de pagamento,
 * mais a situação atual.
 *
 * `GatewayAssinatura::gerarCobranca()` e `::consultarCobranca()` devolvem
 * este objeto. Os campos espelham exatamente as colunas de `Invoice` (Task
 * 7.1) que recebem o resultado: `idNoGateway` vai para
 * `gateway_invoice_id`, e assim por diante, para que `InvoiceService` (Task
 * 7.5) grave a fatura sem tradução no meio do caminho.
 *
 * Só um dos três campos de instrução de pagamento (`linkPagamento`,
 * `linhaDigitavel`, `qrCodePix`) costuma vir preenchido, de acordo com a
 * forma de pagamento da fatura. Nenhuma regra aqui obriga isso: uma cobrança
 * já paga ou cancelada não precisa de instrução nenhuma, e os três podem vir
 * nulos.
 *
 * `pagoEm`, como `Invoice.pago_em`, é o **dia** da confirmação, sem hora:
 * pagamento de fatura não tem hora relevante (ver a skill `datas-timezone`).
 *
 * Imutável e sem dependência de framework: dá para montar e afirmar sobre ele
 * em teste sem rede e sem container.
 */
final class RespostaDeCobranca
{
    public const SITUACAO_ABERTA = 'aberta';

    public const SITUACAO_PAGA = 'paga';

    public const SITUACAO_VENCIDA = 'vencida';

    public const SITUACAO_CANCELADA = 'cancelada';

    /**
     * @var array<int, string>
     */
    public const SITUACOES = [
        self::SITUACAO_ABERTA,
        self::SITUACAO_PAGA,
        self::SITUACAO_VENCIDA,
        self::SITUACAO_CANCELADA,
    ];

    /**
     * @param  string  $idNoGateway  Identificador da cobrança no provedor.
     * @param  string  $situacao  Um dos `self::SITUACAO_*`, já traduzido do vocabulário do provedor.
     * @param  string|null  $linkPagamento  Link de pagamento, tipicamente quando a forma é cartão.
     * @param  string|null  $linhaDigitavel  Linha digitável, quando a forma é boleto.
     * @param  string|null  $qrCodePix  Payload Pix ("copia e cola"), quando a forma é Pix. A imagem do QR não viaja aqui: o projeto já gera a imagem a partir deste payload (`bacon/bacon-qr-code`), então o provedor só precisa entregar o texto.
     * @param  CarbonImmutable|null  $pagoEm  Dia da confirmação de pagamento, sem hora. Preenchido só quando `situacao` é `SITUACAO_PAGA`.
     */
    public function __construct(
        public readonly string $idNoGateway,
        public readonly string $situacao,
        public readonly ?string $linkPagamento = null,
        public readonly ?string $linhaDigitavel = null,
        public readonly ?string $qrCodePix = null,
        public readonly ?CarbonImmutable $pagoEm = null,
    ) {
        if (! in_array($situacao, self::SITUACOES, true)) {
            throw new InvalidArgumentException(
                "Situação de cobrança desconhecida: \"{$situacao}\". "
                .'Situações válidas: '.implode(', ', self::SITUACOES).'.'
            );
        }
    }
}
