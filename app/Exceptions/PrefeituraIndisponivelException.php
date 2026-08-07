<?php

namespace App\Exceptions;

use Throwable;

class PrefeituraIndisponivelException extends FalhaFiscalException
{
    public function __construct(?Throwable $anterior = null)
    {
        parent::__construct(
            'A prefeitura ou o serviço fiscal está indisponível. Tente novamente em alguns minutos.',
            true,
            previous: $anterior,
        );
    }
}
