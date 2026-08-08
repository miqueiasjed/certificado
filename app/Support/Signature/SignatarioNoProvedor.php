<?php

namespace App\Support\Signature;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * O que o provedor de assinatura eletrônica sabe sobre um signatário
 * (Plano 26, Task 26.2), no vocabulário do domínio.
 *
 * Os campos espelham exatamente as colunas de `signature_signers` (Task 26.1)
 * que recebem o resultado: `situacao` vai para `situacao`, `assinadoEm` para
 * `assinado_em`, `ip` para `ip` e `userAgent` para `user_agent`, para que a
 * sincronização (Task 26.3) grave sem tradução no meio do caminho.
 *
 * `ip` e `userAgent` são o núcleo da trilha de auditoria e vêm sempre do
 * provedor, nunca da requisição de quem abre a tela: quem assina é o cliente,
 * de onde ele estiver, e não quem está olhando o pedido no painel. Provedor
 * que não devolve esses campos deixa os dois nulos, e isso é registrado como
 * está — inventar dado de auditoria é pior que não ter.
 *
 * `emailNoProvedor` é o que casa este signatário com a linha de
 * `signature_signers`: o identificador próprio do signatário
 * (`tokenNoProvedor`) só existe depois do envio, e nem todo provedor o mantém
 * estável entre reenvios.
 *
 * Imutável e sem dependência de framework: dá para montar e afirmar sobre ele
 * em teste sem rede e sem container.
 */
final class SignatarioNoProvedor
{
    public const SITUACAO_PENDENTE = 'pendente';

    public const SITUACAO_VISUALIZOU = 'visualizou';

    public const SITUACAO_ASSINOU = 'assinou';

    public const SITUACAO_RECUSOU = 'recusou';

    /**
     * Mesma lista do enum de `signature_signers.situacao`.
     *
     * @var array<int, string>
     */
    public const SITUACOES = [
        self::SITUACAO_PENDENTE,
        self::SITUACAO_VISUALIZOU,
        self::SITUACAO_ASSINOU,
        self::SITUACAO_RECUSOU,
    ];

    /**
     * @param  string  $emailNoProvedor  E-mail com que o signatário foi cadastrado. É a chave que casa este objeto com a linha de `signature_signers`.
     * @param  string  $situacao  Um dos `self::SITUACAO_*`, já traduzido do vocabulário do provedor.
     * @param  string|null  $nome  Nome como o provedor o conhece. Só informativo: o nome gravado é o do domínio.
     * @param  string|null  $tokenNoProvedor  Identificador do signatário no provedor, quando houver.
     * @param  string|null  $linkParaAssinar  URL que o signatário abre para assinar. Expira; nunca é guardada como se fosse permanente.
     * @param  CarbonImmutable|null  $assinadoEm  Instante da assinatura, no fuso do negócio. Preenchido só quando `situacao` é `SITUACAO_ASSINOU`.
     * @param  string|null  $ip  IP de onde a assinatura partiu, informado pelo provedor. Parte da trilha de auditoria.
     * @param  string|null  $userAgent  Navegador de onde a assinatura partiu, informado pelo provedor. Parte da trilha de auditoria.
     */
    public function __construct(
        public readonly string $emailNoProvedor,
        public readonly string $situacao,
        public readonly ?string $nome = null,
        public readonly ?string $tokenNoProvedor = null,
        public readonly ?string $linkParaAssinar = null,
        public readonly ?CarbonImmutable $assinadoEm = null,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {
        if (! in_array($situacao, self::SITUACOES, true)) {
            throw new InvalidArgumentException(
                "Situação de signatário desconhecida: \"{$situacao}\". "
                .'Situações válidas: '.implode(', ', self::SITUACOES).'.'
            );
        }
    }
}
