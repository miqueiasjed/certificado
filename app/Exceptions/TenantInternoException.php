<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Operação recusada porque o alvo é o tenant interno.
 *
 * O tenant interno (`companies.is_internal = true`) é a empresa que opera o
 * sistema hoje e gera a receita atual do negócio. Suspendê-la derrubaria a
 * operação do cliente real por um clique errado na área da plataforma, e é
 * exatamente isso que esta exceção existe para impedir.
 *
 * Por que classe própria, e não um `RuntimeException` solto: o controller da
 * Task 5.7 precisa distinguir esta recusa (regra de negócio, vira mensagem
 * clara na tela) de qualquer outro erro de execução (que vira 500 e vai para o
 * log). Com `RuntimeException` genérico, o `catch` do controller pegaria junto
 * falhas que ele não sabe tratar.
 *
 * Quem lança: `App\Services\TenantService::suspender()`,
 * `App\Services\SubscriptionService` e `App\Services\AvaliacaoService`, sempre
 * antes de qualquer escrita e antes de qualquer chamada ao provedor de
 * pagamento. Lançar depois de um `update()` parcial, ou depois de já ter
 * criado a assinatura no gateway, seria pior que não lançar.
 */
class TenantInternoException extends RuntimeException
{
    /**
     * Texto exibido ao super admin quando não há nome de empresa para citar.
     */
    public const MENSAGEM_PADRAO = 'O tenant interno não pode ser suspenso: ele é o cliente que sustenta a operação atual do sistema.';

    public function __construct(
        string $message = self::MENSAGEM_PADRAO,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Recusa de suspensão, citando a empresa quando o nome é conhecido.
     */
    public static function naoPodeSerSuspenso(?string $nomeDaEmpresa = null): self
    {
        if ($nomeDaEmpresa === null || trim($nomeDaEmpresa) === '') {
            return new self;
        }

        return new self(sprintf(
            'A empresa "%s" é o tenant interno e não pode ser suspensa: ela é o cliente que sustenta a operação atual do sistema.',
            trim($nomeDaEmpresa)
        ));
    }

    /**
     * Recusa de contratação de plano (`SubscriptionService::assinar()`).
     *
     * O tenant interno usa o plano interno, que libera tudo e não é cobrado.
     * Criar assinatura para ele significaria mandar o cliente que sustenta a
     * operação para dentro da régua de inadimplência, onde uma fatura não paga
     * termina em bloqueio de acesso.
     */
    public static function naoPodeAssinar(?string $nomeDaEmpresa = null): self
    {
        return new self(self::comEmpresa(
            $nomeDaEmpresa,
            'O tenant interno não contrata plano e não é cobrado: ele é o cliente que sustenta a operação atual do sistema.',
            'A empresa "%s" é o tenant interno: ela não contrata plano e não é cobrada, porque é o cliente que sustenta a operação atual do sistema.'
        ));
    }

    /**
     * Recusa de qualquer operação sobre assinatura de um tenant interno:
     * troca de plano, troca de forma de pagamento, cancelamento.
     *
     * Defesa em profundidade. O tenant interno não deveria ter assinatura
     * nenhuma, já que `assinar()` o recusa; se uma linha aparecer por
     * importação, correção manual em banco ou bug futuro, mexer nela pelo
     * caminho normal continua barrado.
     */
    public static function naoTemAssinatura(?string $nomeDaEmpresa = null): self
    {
        return new self(self::comEmpresa(
            $nomeDaEmpresa,
            'O tenant interno não tem assinatura a alterar: ele não é cobrado, porque é o cliente que sustenta a operação atual do sistema.',
            'A empresa "%s" é o tenant interno e não tem assinatura a alterar: ela não é cobrada, porque é o cliente que sustenta a operação atual do sistema.'
        ));
    }

    /**
     * Recusa de entrada em avaliação (`AvaliacaoService::iniciar()` e
     * `AvaliacaoService::converter()`).
     *
     * O tenant interno já opera sem limite e sem cobrança: colocá-lo em
     * avaliação o exporia à suspensão de `AvaliacaoService::avaliarVencimentos()`
     * quando o prazo vencesse, e é exatamente o bloqueio que este tenant não
     * pode sofrer.
     */
    public static function naoEntraEmAvaliacao(?string $nomeDaEmpresa = null): self
    {
        return new self(self::comEmpresa(
            $nomeDaEmpresa,
            'O tenant interno não entra em avaliação: ele é o cliente que sustenta a operação atual do sistema.',
            'A empresa "%s" é o tenant interno: ela não entra em avaliação, porque é o cliente que sustenta a operação atual do sistema.'
        ));
    }

    /**
     * Monta a mensagem citando a empresa quando o nome é conhecido.
     *
     * `$comNome` recebe o nome em `%s`; `$semNome` é usado inteiro quando não
     * há nome que valha a pena citar.
     */
    private static function comEmpresa(?string $nomeDaEmpresa, string $semNome, string $comNome): string
    {
        if ($nomeDaEmpresa === null || trim($nomeDaEmpresa) === '') {
            return $semNome;
        }

        return sprintf($comNome, trim($nomeDaEmpresa));
    }
}
