<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A empresa já usou todas as gerações de IA que o plano dela permite no mês
 * (Plano 25, Task 25.5).
 *
 * Recusa **apenas a geração**. Ordem de serviço, certificado, financeiro e
 * qualquer outra parte do sistema continuam funcionando: limite de um recurso
 * opcional que derruba o resto do sistema é pior que não ter limite nenhum.
 *
 * Não confundir com `IaLimiteDeTaxaException`, que é o limite de chamadas da
 * conta na plataforma do provedor. Este aqui é o teto comercial do plano,
 * medido em `ai_usages`.
 */
class TetoDeIaAtingidoException extends RuntimeException
{
    public const MENSAGEM_PADRAO = 'O limite de gerações por inteligência artificial do seu plano foi atingido neste mês. O parecer pode ser escrito manualmente, e o limite volta a zerar no próximo mês.';

    public function __construct(string $message = self::MENSAGEM_PADRAO)
    {
        parent::__construct($message);
    }

    public static function doMes(int $teto): self
    {
        return new self(sprintf(
            'O limite de %d gerações por inteligência artificial do seu plano foi atingido neste mês. O parecer pode ser escrito manualmente, e o limite volta a zerar no próximo mês.',
            $teto
        ));
    }
}
