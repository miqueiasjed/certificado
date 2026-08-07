<?php

namespace App\Exceptions;

class ArmazenamentoFiscalIndisponivelException extends FalhaFiscalException
{
    public function __construct()
    {
        parent::__construct(
            'Não foi possível salvar o PDF ou o XML da nota no armazenamento privado. Uma nova tentativa será feita automaticamente.',
            true,
        );
    }
}
