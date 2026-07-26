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
 * Quem lança: `App\Services\TenantService::suspender()`, antes de qualquer
 * escrita. Lançar depois de um `update()` parcial seria pior que não lançar.
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
}
