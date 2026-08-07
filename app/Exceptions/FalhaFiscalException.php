<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

abstract class FalhaFiscalException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly bool $temporaria,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function ehTemporaria(): bool
    {
        return $this->temporaria;
    }
}
