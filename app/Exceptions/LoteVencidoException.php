<?php

namespace App\Exceptions;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Recusa de aplicação de lote vencido (Plano 17, Task 17.3).
 *
 * Bloqueio sanitário, não preferência de operação: aplicar saneante fora da
 * validade é infração, e o sistema não pode deixar acontecer por engano de
 * digitação. `StockService::saida()` lança esta exceção quando o lote informado
 * já estava vencido **no dia em que a aplicação aconteceu**, e a transação
 * inteira volta atrás.
 *
 * O dia comparado é o do `ocorrido_em`, no fuso do negócio
 * -------------------------------------------------------
 * Não é o dia da sincronização, e não é `now()` em UTC. OS executada dia 20 com
 * lote que vence dia 20 é válida, mesmo que o aplicativo do técnico só suba o
 * registro dia 25: condenar retroativamente uma aplicação que era legítima
 * quando aconteceu inventaria uma infração que não existiu. E comparar em UTC
 * daria o lote como vencido a partir das 21h de Brasília do dia anterior, que é
 * o erro de um dia que a skill `datas-timezone` existe para evitar.
 *
 * Por que classe própria
 * ----------------------
 * A recusa precisa chegar ao usuário dizendo qual lote, qual validade e para
 * que dia a aplicação foi registrada, em português. "Erro ao salvar" faria o
 * operador tentar de novo sem entender o problema. Os dados brutos ficam em
 * propriedades para quem trata a exceção montar a resposta sem reprocessar a
 * mensagem: a Task 17.4 usa isso para transformar a recusa em pendência da OS, e
 * a 17.7 para devolver o erro na tela junto com o caminho de saída, que é
 * registrar o descarte do saldo vencido (`StockService::descartar()`, sempre com
 * motivo).
 *
 * O saldo do lote vencido continua onde está. Sumir com ele esconderia produto
 * que ainda está fisicamente na prateleira e precisa ser descartado com
 * registro, que é o que fecha o ciclo perante fiscalização.
 */
class LoteVencidoException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $productId = null,
        public readonly ?int $productBatchId = null,
        public readonly ?string $lote = null,
        /** Validade do lote, em `Y-m-d`, no fuso do negócio. */
        public readonly ?string $validade = null,
        /** Dia da aplicação registrada, em `Y-m-d`, no fuso do negócio. */
        public readonly ?string $diaDaAplicacao = null,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Recusa citando produto, lote, validade e o dia da aplicação.
     *
     * `$diaDaAplicacao` já chega resolvido no fuso do negócio por
     * `StockService`, que é quem conhece o `ocorrido_em` do movimento.
     */
    public static function para(Product $produto, ProductBatch $lote, CarbonImmutable $diaDaAplicacao): self
    {
        $validade = BusinessDate::paraFusoNegocio($lote->validade)?->startOfDay();
        $dia = $diaDaAplicacao->startOfDay();

        $mensagem = sprintf(
            'O lote %s de "%s" venceu em %s e não pode ser aplicado na data de %s. '
            .'Escolha outro lote ou registre o descarte do saldo vencido.',
            $lote->lote,
            $produto->name,
            $validade?->format('d/m/Y') ?? 'data não informada',
            $dia->format('d/m/Y')
        );

        return new self(
            $mensagem,
            $produto->getKey(),
            $lote->getKey(),
            (string) $lote->lote,
            $validade?->toDateString(),
            $dia->toDateString()
        );
    }

    /**
     * Há quantos dias o lote estava vencido no dia da aplicação.
     *
     * Zero quando falta alguma das duas datas. Serve para a mensagem da tela
     * separar "venceu ontem" de "venceu há oito meses", que são conversas
     * diferentes com o operador.
     */
    public function diasVencido(): int
    {
        if ($this->validade === null || $this->diaDaAplicacao === null) {
            return 0;
        }

        $validade = BusinessDate::paraFusoNegocio($this->validade);
        $aplicacao = BusinessDate::paraFusoNegocio($this->diaDaAplicacao);

        if ($validade === null || $aplicacao === null) {
            return 0;
        }

        return (int) $validade->startOfDay()->diffInDays($aplicacao->startOfDay(), false);
    }
}
