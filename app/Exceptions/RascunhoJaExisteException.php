<?php

namespace App\Exceptions;

use App\Models\AiDraft;
use RuntimeException;

/**
 * A origem já tem um rascunho de parecer em andamento (Plano 25, Task 25.3).
 *
 * Uma origem tem um parecer, não vários: gerar de novo por cima criaria dois
 * textos concorrentes e nenhuma resposta para "qual deles é o parecer deste
 * documento". Quem quer começar do zero descarta o anterior — o descarte
 * preserva o texto gerado, então nada se perde.
 *
 * A exceção carrega o rascunho existente para que a tela possa levar o usuário
 * direto a ele, em vez de só informar que não deu.
 */
class RascunhoJaExisteException extends RuntimeException
{
    public function __construct(public readonly AiDraft $existente)
    {
        parent::__construct(
            'Já existe um rascunho de parecer para este registro. Revise ou descarte o rascunho existente antes de gerar outro.'
        );
    }
}
