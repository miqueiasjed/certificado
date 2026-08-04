<?php

namespace App\Support\Gateway;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Um evento de webhook de cobrança do tenant ao cliente final, já traduzido
 * para o vocabulário do domínio (Plano 19, Task 19.4).
 *
 * `GatewayDeCobranca::interpretarWebhook()` devolve este objeto a partir do
 * payload cru do provedor, e é a única forma pela qual um webhook de cobrança
 * pode afetar `Charge`: nada além deste objeto atravessa a fronteira do
 * gateway rumo a `ProcessadorDeEventoDeCobranca`.
 *
 * Irmão de `App\Support\Gateway\EventoDeGateway` (Plano 7), e deliberadamente
 * uma classe própria, não uma extensão dela: `EventoDeGateway` é o vocabulário
 * da cobrança da plataforma ao tenant (fatura/assinatura), e misturar os dois
 * fluxos no mesmo objeto obrigaria `GatewayEventService` (Plano 7) a saber
 * interpretar `estorno`, que não existe do lado de `Invoice`/`Subscription` —
 * ou o contrário, `TIPO_ASSINATURA_CANCELADA` aparecendo onde só cobrança de
 * parcela faz sentido. Dois contratos (`GatewayAssinatura` e
 * `GatewayDeCobranca`) já são deliberadamente separados na Task 19.2 pelo
 * mesmo motivo; o vocabulário do evento segue a mesma separação.
 *
 * Payload que a implementação não reconhece vira `tipo = self::TIPO_DESCONHECIDO`,
 * nunca uma exceção: quem chama grava o evento do mesmo jeito, e ignorar o que
 * não se entende é como se perde confirmação de pagamento.
 *
 * Imutável e sem dependência de framework.
 */
final class EventoDeCobranca
{
    public const TIPO_PAGA = 'cobranca_paga';

    public const TIPO_VENCIDA = 'cobranca_vencida';

    public const TIPO_CANCELADA = 'cobranca_cancelada';

    public const TIPO_ESTORNADA = 'cobranca_estornada';

    public const TIPO_DESCONHECIDO = 'desconhecido';

    /**
     * @var array<int, string>
     */
    public const TIPOS = [
        self::TIPO_PAGA,
        self::TIPO_VENCIDA,
        self::TIPO_CANCELADA,
        self::TIPO_ESTORNADA,
        self::TIPO_DESCONHECIDO,
    ];

    /**
     * @param  string  $eventoId  Identificador do evento no provedor. É a chave de deduplicação (`GatewayEvent.evento_id`, unique): o mesmo evento pode chegar mais de uma vez, e reprocessar sem duplicar efeito depende de guardar este valor.
     * @param  string  $tipo  Um dos `self::TIPO_*`.
     * @param  string|null  $chargeIdNoGateway  Identificador, no provedor, da cobrança a que o evento se refere (`Charge.gateway_charge_id`). Nulo para `desconhecido`, quando o payload nem chega a indicar o recurso.
     * @param  string|null  $valorPago  Valor confirmado pelo provedor, decimal com até duas casas ("150.00"), nunca float. Preenchido só em `TIPO_PAGA`.
     * @param  CarbonImmutable|null  $pagoEm  Dia do pagamento, sem hora, preenchido só em `TIPO_PAGA`.
     */
    public function __construct(
        public readonly string $eventoId,
        public readonly string $tipo,
        public readonly ?string $chargeIdNoGateway = null,
        public readonly ?string $valorPago = null,
        public readonly ?CarbonImmutable $pagoEm = null,
    ) {
        if (! in_array($tipo, self::TIPOS, true)) {
            throw new InvalidArgumentException(
                "Tipo de evento de cobrança desconhecido: \"{$tipo}\". "
                .'Tipos válidos: '.implode(', ', self::TIPOS).'.'
            );
        }
    }
}
