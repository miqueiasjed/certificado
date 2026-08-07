<?php

namespace App\Exceptions;

class CancelamentoNaoEncontradoException extends FalhaFiscalException
{
    public function __construct()
    {
        parent::__construct(
            'O provedor não encontrou uma solicitação de cancelamento para esta nota fiscal.',
            false,
        );
    }
}
