<?php

namespace App\Exceptions;

class ArquivoFiscalIndisponivelException extends FalhaFiscalException
{
    public function __construct()
    {
        parent::__construct('O arquivo fiscal ainda não está disponível para download.', false);
    }
}
