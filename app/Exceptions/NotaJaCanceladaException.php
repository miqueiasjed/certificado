<?php

namespace App\Exceptions;

class NotaJaCanceladaException extends FalhaFiscalException
{
    public function __construct()
    {
        parent::__construct('A nota fiscal já está cancelada.', false);
    }
}
