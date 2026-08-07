<?php

namespace App\Exceptions;

class PrazoDeCancelamentoExpiradoException extends FalhaFiscalException
{
    public function __construct()
    {
        parent::__construct(
            'A prefeitura informou que o prazo de cancelamento expirou neste município. Consulte a contabilidade para avaliar a substituição da nota ou uma carta de correção.',
            false,
        );
    }
}
