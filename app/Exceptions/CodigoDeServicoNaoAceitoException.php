<?php

namespace App\Exceptions;

class CodigoDeServicoNaoAceitoException extends FalhaFiscalException
{
    public function __construct()
    {
        parent::__construct(
            'O código de serviço não é aceito pelo município. Confira a configuração fiscal com a prefeitura.',
            false,
        );
    }
}
