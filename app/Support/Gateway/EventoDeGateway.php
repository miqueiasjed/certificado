<?php

namespace App\Support\Gateway;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Um evento de webhook do provedor, já traduzido para o vocabulário do
 * domínio.
 *
 * `GatewayAssinatura::interpretarWebhook()` devolve este objeto a partir do
 * payload cru do provedor, e é a única forma pela qual um webhook pode
 * afetar `Subscription` ou `Invoice` (Task 7.1): nada além deste objeto
 * atravessa a fronteira do gateway rumo a `GatewayEventService` (Task 7.6).
 *
 * Payload que a implementação não reconhece vira `tipo = self::TIPO_DESCONHECIDO`,
 * nunca uma exceção. Quem chama grava o evento do mesmo jeito: ignorar o que
 * não se entende é como se perde confirmação de pagamento, e um evento
 * desconhecido registrado dá para investigar depois, um evento descartado
 * não dá.
 *
 * Imutável e sem dependência de framework.
 */
final class EventoDeGateway
{
    public const TIPO_COBRANCA_PAGA = 'cobranca_paga';

    public const TIPO_COBRANCA_VENCIDA = 'cobranca_vencida';

    public const TIPO_COBRANCA_CANCELADA = 'cobranca_cancelada';

    public const TIPO_ASSINATURA_CANCELADA = 'assinatura_cancelada';

    public const TIPO_DESCONHECIDO = 'desconhecido';

    /**
     * @var array<int, string>
     */
    public const TIPOS = [
        self::TIPO_COBRANCA_PAGA,
        self::TIPO_COBRANCA_VENCIDA,
        self::TIPO_COBRANCA_CANCELADA,
        self::TIPO_ASSINATURA_CANCELADA,
        self::TIPO_DESCONHECIDO,
    ];

    /**
     * @param  string  $eventoId  Identificador do evento no provedor. É a chave de deduplicação (`GatewayEvent.evento_id`, unique): o mesmo evento pode chegar mais de uma vez, e reprocessar sem duplicar efeito depende de guardar este valor.
     * @param  string  $tipo  Um dos `self::TIPO_*`.
     * @param  string|null  $faturaIdNoGateway  Identificador, no provedor, do recurso a que o evento se refere. Para os tipos `cobranca_*` é o id da cobrança (`Invoice.gateway_invoice_id`); para `assinatura_cancelada` é o id da assinatura (`Subscription.gateway_subscription_id`), não de uma fatura. Nulo para `desconhecido`, quando o payload nem chega a indicar o recurso.
     * @param  string|null  $situacao  Um dos `RespostaDeCobranca::SITUACAO_*`, quando o evento é sobre uma cobrança. Nulo para `assinatura_cancelada` e `desconhecido`.
     * @param  CarbonImmutable|null  $pagoEm  Dia do pagamento, sem hora (mesmo critério de `RespostaDeCobranca::$pagoEm`), preenchido só em `TIPO_COBRANCA_PAGA`.
     */
    public function __construct(
        public readonly string $eventoId,
        public readonly string $tipo,
        public readonly ?string $faturaIdNoGateway = null,
        public readonly ?string $situacao = null,
        public readonly ?CarbonImmutable $pagoEm = null,
    ) {
        if (! in_array($tipo, self::TIPOS, true)) {
            throw new InvalidArgumentException(
                "Tipo de evento de gateway desconhecido: \"{$tipo}\". "
                .'Tipos válidos: '.implode(', ', self::TIPOS).'.'
            );
        }

        if ($situacao !== null && ! in_array($situacao, RespostaDeCobranca::SITUACOES, true)) {
            throw new InvalidArgumentException(
                "Situação de cobrança desconhecida: \"{$situacao}\". "
                .'Situações válidas: '.implode(', ', RespostaDeCobranca::SITUACOES).'.'
            );
        }
    }
}
