<?php

namespace App\Support\Fiscal;

use Carbon\CarbonImmutable;

final readonly class RespostaDeNfse
{
    public const SITUACAO_PROCESSANDO = 'processando';

    public const SITUACAO_AUTORIZADA = 'autorizada';

    public const SITUACAO_CANCELADA = 'cancelada';

    public const SITUACAO_ERRO = 'erro';

    /**
     * @param  array<int, array{codigo: ?string, descricao: ?string, correcao: ?string}>  $mensagens
     */
    public function __construct(
        public string $id,
        public string $situacao,
        public ?string $numero = null,
        public ?string $codigoVerificacao = null,
        public ?CarbonImmutable $emitidaEm = null,
        public array $mensagens = [],
    ) {}
}
