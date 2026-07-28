<?php

namespace App\Support\Gateway;

use InvalidArgumentException;

/**
 * O que o provedor devolveu depois de criar ou alterar uma assinatura, no
 * vocabulário do domínio.
 *
 * `GatewayAssinatura::criarAssinatura()` e `::trocarPlano()` devolvem este
 * objeto, nunca o corpo cru da resposta HTTP do provedor. É essa fronteira
 * que permite ao futuro `SubscriptionService` (Task 7.4) gravar
 * `Subscription.gateway_subscription_id` e `Subscription.situacao` sem nunca
 * ter importado nada do provedor concreto.
 *
 * `SITUACAO_*` é o mesmo conjunto de valores documentado na migration de
 * `subscriptions` (Task 7.1) para a coluna `situacao`: esta classe é onde
 * esse vocabulário nasce, e `SubscriptionService` grava o campo com o valor
 * que sair daqui, sem tradução no meio do caminho.
 *
 * Imutável e sem dependência de framework: dá para montar e afirmar sobre ele
 * em teste sem rede e sem container.
 */
final class RespostaDeAssinatura
{
    public const SITUACAO_ATIVA = 'ativa';

    public const SITUACAO_EM_ATRASO = 'em_atraso';

    public const SITUACAO_SUSPENSA = 'suspensa';

    public const SITUACAO_CANCELADA = 'cancelada';

    /**
     * @var array<int, string>
     */
    public const SITUACOES = [
        self::SITUACAO_ATIVA,
        self::SITUACAO_EM_ATRASO,
        self::SITUACAO_SUSPENSA,
        self::SITUACAO_CANCELADA,
    ];

    /**
     * @param  string  $idNoGateway  Identificador da assinatura no provedor. É o valor que `cancelarAssinatura()` e `trocarPlano()` recebem de volta como `$idNoGateway`.
     * @param  string  $situacao  Um dos `self::SITUACAO_*`, já traduzido do vocabulário do provedor.
     */
    public function __construct(
        public readonly string $idNoGateway,
        public readonly string $situacao,
    ) {
        if (! in_array($situacao, self::SITUACOES, true)) {
            throw new InvalidArgumentException(
                "Situação de assinatura desconhecida: \"{$situacao}\". "
                .'Situações válidas: '.implode(', ', self::SITUACOES).'.'
            );
        }
    }
}
