<?php

namespace App\Exceptions;

class RecusaFiscalException extends FalhaFiscalException
{
    public function __construct()
    {
        parent::__construct(
            'O provedor fiscal recusou a operação. Confira as mensagens da nota e corrija os dados informados.',
            false,
        );
    }
}
