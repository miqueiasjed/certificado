<?php

namespace App\Exceptions;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockLocation;
use RuntimeException;
use Throwable;

/**
 * Recusa de baixa de estoque por saldo insuficiente (Plano 17, Task 17.2).
 *
 * Saldo negativo nunca é gravado. Quando a saída pedida é maior que o saldo do
 * produto (ou do lote) naquele local, `StockService` aborta a transação inteira:
 * nem o movimento nem o saldo são alterados, e quem chamou recebe esta exceção.
 *
 * Por que classe própria, e não um erro genérico: a recusa precisa chegar ao
 * usuário com número, em português, dizendo quanto tem e quanto foi pedido.
 * "Erro ao salvar" faria o operador tentar de novo sem entender que o problema é
 * o saldo. A mensagem também cita o lote quando existe, porque o saldo do
 * produto no depósito pode ser suficiente enquanto o saldo daquele lote não é.
 *
 * Os dados brutos ficam expostos em propriedades (`saldoAtual`,
 * `quantidadePedida`, os identificadores), para quem trata a exceção montar a
 * resposta que precisa sem reprocessar a mensagem. É o que a Task 17.4 usa para
 * transformar a recusa em pendência de estoque na OS, em vez de bloquear o
 * fechamento de um serviço que já aconteceu no mundo real.
 */
class EstoqueInsuficienteException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $productId = null,
        public readonly ?int $stockLocationId = null,
        public readonly ?int $productBatchId = null,
        public readonly float $saldoAtual = 0.0,
        public readonly float $quantidadePedida = 0.0,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Recusa citando produto, lote, local, saldo atual e quantidade pedida.
     */
    public static function para(
        Product $produto,
        StockLocation $local,
        ?ProductBatch $lote,
        float $saldoAtual,
        float $quantidadePedida
    ): self {
        $unidade = self::unidadeDe($produto);

        $mensagem = sprintf(
            'Saldo insuficiente de "%s"%s em "%s": há %s %s e a saída pedida é de %s %s (faltam %s %s).',
            $produto->name,
            $lote !== null ? sprintf(' (lote %s)', $lote->lote) : '',
            $local->nome,
            self::formatarQuantidade($saldoAtual),
            $unidade,
            self::formatarQuantidade($quantidadePedida),
            $unidade,
            self::formatarQuantidade($quantidadePedida - $saldoAtual),
            $unidade
        );

        return new self(
            $mensagem,
            $produto->getKey(),
            $local->getKey(),
            $lote?->getKey(),
            $saldoAtual,
            $quantidadePedida
        );
    }

    /**
     * Quanto falta para a saída pedida caber no saldo.
     */
    public function faltante(): float
    {
        return round($this->quantidadePedida - $this->saldoAtual, 4);
    }

    /**
     * Quantidade legível: vírgula decimal, sem zeros à direita sobrando.
     *
     * As quantidades têm 4 casas porque saneante concentrado é dosado em fração
     * de mililitro, mas mostrar "6,0000 un" para uma isca contada por peça só
     * atrapalha a leitura da mensagem.
     */
    private static function formatarQuantidade(float $quantidade): string
    {
        $texto = rtrim(rtrim(number_format(round($quantidade, 4), 4, ',', '.'), '0'), ',');

        return $texto === '' || $texto === '-' ? '0' : $texto;
    }

    /**
     * Unidade do produto, com o mesmo padrão da coluna (`un`) quando vier vazia.
     */
    private static function unidadeDe(Product $produto): string
    {
        $unidade = trim((string) ($produto->unidade ?? ''));

        return $unidade !== '' ? $unidade : 'un';
    }
}
