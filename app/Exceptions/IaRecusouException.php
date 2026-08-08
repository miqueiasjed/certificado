<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * O provedor de IA respondeu e disse não (Plano 25).
 *
 * Dois caminhos chegam aqui:
 *
 * 1. **Recusa do modelo**: a resposta volta com HTTP 200 e
 *    `stop_reason = "refusal"`. O conteúdo pode vir vazio ou parcial, e ler
 *    `content[0]` sem conferir `stop_reason` antes é justamente o erro que
 *    esta exceção existe para evitar.
 * 2. **Requisição inválida** (4xx que não seja 429): parâmetro não aceito,
 *    entrada longa demais, modelo inexistente.
 *
 * Nos dois casos, repetir a mesma entrada devolve o mesmo resultado — é o que
 * separa esta exceção de `IaIndisponivelException`. Quem chama deve mudar a
 * entrada ou desistir da geração, nunca insistir.
 */
class IaRecusouException extends RuntimeException
{
    public const MENSAGEM_PADRAO = 'O serviço de geração de texto não conseguiu produzir o rascunho para estes dados.';

    public function __construct(
        string $message = self::MENSAGEM_PADRAO,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * O modelo recusou a geração (`stop_reason = "refusal"`).
     */
    public static function modeloRecusou(?string $categoria = null): self
    {
        if ($categoria === null || trim($categoria) === '') {
            return new self;
        }

        return new self(sprintf(
            'O serviço de geração de texto recusou produzir o rascunho (motivo informado: %s). Revise os dados da origem e escreva o texto manualmente.',
            trim($categoria)
        ));
    }

    /**
     * O provedor recusou a requisição (4xx). O corpo do erro entra na
     * mensagem porque é ele que permite corrigir a chamada; a chave de API
     * nunca faz parte dele.
     */
    public static function requisicaoInvalida(string $endpoint, int $status, ?string $detalhe = null): self
    {
        $texto = sprintf(
            'O serviço de geração de texto recusou a requisição em "%s" (HTTP %d).',
            $endpoint,
            $status
        );

        if ($detalhe !== null && trim($detalhe) !== '') {
            $texto .= ' Resposta do provedor: '.trim($detalhe);
        }

        return new self($texto);
    }

    /**
     * A resposta veio bem formada, mas sem nenhum bloco de texto aproveitável.
     */
    public static function respostaVazia(): self
    {
        return new self('O serviço de geração de texto devolveu uma resposta vazia. Tente novamente.');
    }
}
