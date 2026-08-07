<?php

namespace App\Exceptions;

class DadoFiscalInvalidoException extends FalhaFiscalException
{
    public function __construct(string $mensagem)
    {
        parent::__construct($mensagem, false);
    }
}
