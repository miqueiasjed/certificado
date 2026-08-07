<?php

namespace App\Support\Fiscal;

final readonly class RespostaDeCancelamento
{
    /**
     * @param  array<int, array{codigo: ?string, descricao: ?string, correcao: ?string}>  $mensagens
     */
    public function __construct(
        public string $id,
        public string $situacao,
        public ?string $codigo = null,
        public ?string $motivo = null,
        public array $mensagens = [],
    ) {}
}
