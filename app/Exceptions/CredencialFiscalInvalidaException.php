<?php

namespace App\Exceptions;

class CredencialFiscalInvalidaException extends FalhaFiscalException
{
    public function __construct()
    {
        parent::__construct(
            'A credencial fiscal é inválida. Confira o Client ID e o Client Secret da Nuvem Fiscal.',
            false,
        );
    }
}
