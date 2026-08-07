<?php

namespace App\Exceptions;

class DadosDoTomadorIncompletosException extends FalhaFiscalException
{
    public function __construct()
    {
        parent::__construct(
            'Os dados do tomador estão incompletos ou inválidos. Confira documento, inscrição municipal e endereço.',
            false,
        );
    }
}
