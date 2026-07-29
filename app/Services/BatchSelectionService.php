<?php

namespace App\Services;

use App\Exceptions\EstoqueInsuficienteException;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Escolha do lote na baixa e consultas de validade (Plano 17, Task 17.3).
 *
 * FEFO, e não FIFO
 * ----------------
 * A ordem é pela **validade mais próxima** (first expired, first out), nunca
 * pela ordem de chegada. Em saneante a data que importa é a de vencimento: lote
 * comprado depois pode vencer antes, e dar baixa pelo mais antigo de cadastro
 * deixaria o de vencimento próximo apodrecer na prateleira até virar descarte.
 * O desempate entre dois lotes de mesma validade é pelo id, que aí sim é ordem
 * de chegada.
 *
 * Lote vencido nunca é selecionado
 * --------------------------------
 * Bloqueio sanitário, não preferência: aplicar produto fora da validade é
 * infração. A seleção automática só enxerga lote dentro da validade, e a
 * escolha manual é recusada por `StockService::saida()` com
 * `LoteVencidoException`.
 *
 * O dia que vale é o da aplicação em campo
 * ----------------------------------------
 * A comparação de validade usa o dia do `ocorrido_em` no fuso do negócio, o
 * mesmo que `StockService` grava no movimento, e nunca o dia da sincronização.
 * OS executada dia 20 com lote que vence dia 20 é válida mesmo que o aplicativo
 * do técnico só suba o registro dia 25. A resolução do dia é delegada a
 * `StockService::diaDaAplicacao()` de propósito: seleção e baixa precisam
 * concordar sobre que dia é esse, senão a seleção entregaria um lote que a baixa
 * recusa em seguida.
 *
 * Lote vencido continua visível e com saldo
 * -----------------------------------------
 * `vencidos()` existe justamente para mostrá-lo. Sumir com o saldo esconderia
 * produto que ainda está fisicamente na prateleira e precisa ser descartado com
 * registro. O caminho de saída é `StockService::descartar()`, sempre com motivo,
 * e é o descarte que fecha o ciclo perante fiscalização.
 *
 * Empresa
 * -------
 * As consultas de leitura (`vencendoEm`, `vencidos`) respeitam o escopo global
 * por empresa dos models, como toda leitura do projeto: quem roda para todos os
 * tenants (a rotina de alertas da Task 17.6) itera com `TenantAtual::comTenant`.
 * `selecionar()` filtra também pela empresa do próprio produto, gravada
 * explicitamente na consulta, no mesmo critério do `StockService`: a seleção
 * roda em fila e em sincronização do aplicativo, onde não existe usuário
 * autenticado e o escopo global não tem tenant para aplicar.
 */
class BatchSelectionService
{
    /**
     * Mesmas 4 casas das colunas de quantidade, pelo mesmo motivo do
     * `StockService`: sem arredondar, a sobra binária de uma subtração de float
     * faria o pedido nunca fechar e sobraria um resíduo de 0,0000000001 para
     * cobrir com um segundo lote.
     */
    private const CASAS_DECIMAIS = 4;

    public function __construct(private readonly StockService $estoque) {}

    // -----------------------------------------------------------------
    // Seleção
    // -----------------------------------------------------------------

    /**
     * Lotes e quantidades que cobrem o pedido, por validade mais próxima.
     *
     * Quando um lote não cobre sozinho, o restante desce para o próximo, e os
     * dois voltam na lista. Quem chama dá uma saída por item devolvido, pelo
     * `StockService`, e é assim que a OS fica sabendo qual lote foi aplicado em
     * qual cliente.
     *
     * `$ocorridoEm` é o instante da aplicação em campo, o mesmo passado depois
     * para `StockService::saida()`. Omitido, vale agora.
     *
     * Casos de borda, e por que cada um responde assim:
     *
     * - **Produto sem nenhum lote cadastrado** devolve lista vazia. É o sinal de
     *   que aquele produto não trabalha com lote, e quem chama dá a baixa sem
     *   lote em vez de tratar como falta de saldo.
     * - **Saldo em lote válido insuficiente** lança
     *   `EstoqueInsuficienteException` com o disponível e o pedido. Devolver uma
     *   seleção parcial em silêncio faria a baixa registrar menos do que foi
     *   aplicado, e o estoque nunca fecharia. O saldo citado na exceção é o que
     *   está em lote dentro da validade: pode haver produto na prateleira em
     *   lote vencido, e é isso que `vencidos()` mostra para ser descartado.
     *
     * @return array<int, array{lote: ProductBatch, quantidade: float}>
     *
     * @throws EstoqueInsuficienteException
     */
    public function selecionar(
        Product $produto,
        StockLocation $local,
        float $quantidade,
        DateTimeInterface|string|null $ocorridoEm = null
    ): array {
        $empresa = $this->empresaDo($produto);

        if ((int) $local->company_id !== $empresa) {
            throw new InvalidArgumentException(
                'O local de estoque informado é de outra empresa e não pode ter lote selecionado para este produto.'
            );
        }

        $pedido = $this->normalizar($quantidade);

        if ($pedido <= 0) {
            throw new InvalidArgumentException('A quantidade a selecionar precisa ser maior que zero.');
        }

        $dia = $this->estoque->diaDaAplicacao($ocorridoEm);

        $saldos = $this->saldosValidosNoLocal($produto, $local, $empresa, $dia);

        if ($saldos->isEmpty()) {
            return $this->recusarOuIgnorar($produto, $local, $empresa, $pedido);
        }

        $lotes = ProductBatch::query()
            ->where('company_id', $empresa)
            ->whereKey($saldos->pluck('product_batch_id')->all())
            ->get()
            ->keyBy(fn (ProductBatch $lote): int => (int) $lote->getKey());

        $restante = $pedido;
        $disponivel = 0.0;
        $selecao = [];

        foreach ($saldos as $saldo) {
            $lote = $lotes->get((int) $saldo->product_batch_id);

            if ($lote === null) {
                continue;
            }

            $noLote = $this->normalizar((float) $saldo->quantidade);
            $disponivel = $this->normalizar($disponivel + $noLote);

            if ($restante <= 0) {
                continue;
            }

            $retirar = $this->normalizar(min($noLote, $restante));
            $restante = $this->normalizar($restante - $retirar);

            $selecao[] = ['lote' => $lote, 'quantidade' => $retirar];
        }

        if ($restante > 0) {
            throw EstoqueInsuficienteException::para($produto, $local, null, $disponivel, $pedido);
        }

        return $selecao;
    }

    // -----------------------------------------------------------------
    // Consultas de validade
    // -----------------------------------------------------------------

    /**
     * Lotes com saldo que vencem dentro de `$dias` dias, contados a partir de
     * hoje no fuso do negócio.
     *
     * Inclui o que vence hoje e o que vence no último dia da janela; não inclui
     * o que já venceu, que é assunto de `vencidos()`. Consumido pelos alertas da
     * Task 17.6, que chama com 60, 30 e 7, e pela tela de estoque.
     *
     * @return Collection<int, array{lote: ProductBatch, validade: string, dias_para_vencer: int, saldo: float, locais: array<int, array{stock_location_id: int, nome: string, quantidade: float}>}>
     */
    public function vencendoEm(int $dias): Collection
    {
        if ($dias < 0) {
            throw new InvalidArgumentException('A janela de vencimento não pode ser negativa.');
        }

        $hoje = BusinessDate::hoje();

        return $this->lotesComSaldo($hoje->toDateString(), $hoje->addDays($dias)->toDateString());
    }

    /**
     * Lotes já vencidos que ainda têm saldo.
     *
     * Continuam aparecendo de propósito: o produto está fisicamente na
     * prateleira e precisa sair por descarte registrado, não por sumiço. É esta
     * lista que a Task 17.6 reenvia semanalmente até o descarte acontecer.
     *
     * `dias_para_vencer` vem negativo aqui: é há quantos dias o lote venceu.
     *
     * @return Collection<int, array{lote: ProductBatch, validade: string, dias_para_vencer: int, saldo: float, locais: array<int, array{stock_location_id: int, nome: string, quantidade: float}>}>
     */
    public function vencidos(): Collection
    {
        return $this->lotesComSaldo(null, BusinessDate::hoje()->subDay()->toDateString());
    }

    // -----------------------------------------------------------------
    // Consultas de apoio
    // -----------------------------------------------------------------

    /**
     * Saldos do produto no local, só em lote dentro da validade no dia da
     * aplicação, já na ordem do FEFO.
     *
     * @return Collection<int, StockBalance>
     */
    private function saldosValidosNoLocal(
        Product $produto,
        StockLocation $local,
        int $empresa,
        CarbonImmutable $dia
    ): Collection {
        return StockBalance::query()
            ->join('product_batches', function (JoinClause $juncao): void {
                $juncao->on('product_batches.id', '=', 'stock_balances.product_batch_id')
                    // O lote é sempre da mesma empresa do saldo. A condição fica
                    // explícita porque o escopo global não alcança tabela em
                    // join, e junção entre empresas seria vazamento.
                    ->whereColumn('product_batches.company_id', 'stock_balances.company_id');
            })
            ->where('stock_balances.company_id', $empresa)
            ->where('stock_balances.product_id', $produto->getKey())
            ->where('stock_balances.stock_location_id', $local->getKey())
            ->whereNotNull('stock_balances.product_batch_id')
            ->where('stock_balances.quantidade', '>', 0)
            // Vencido é validade anterior ao dia da aplicação: o lote que vence
            // no próprio dia ainda vale.
            ->where('product_batches.validade', '>=', $dia->toDateString())
            ->orderBy('product_batches.validade')
            ->orderBy('product_batches.id')
            ->select(['stock_balances.product_batch_id', 'stock_balances.quantidade'])
            ->get();
    }

    /**
     * Lotes com saldo em uma faixa de validade, com o total da empresa e a
     * quebra por local.
     *
     * O local vem junto porque quem recebe a lista precisa saber onde o produto
     * está: para o alerta, é o que diz de que depósito ou van tirar o lote; para
     * a tela, é o que permite disparar o descarte no lugar certo.
     *
     * @return Collection<int, array{lote: ProductBatch, validade: string, dias_para_vencer: int, saldo: float, locais: array<int, array{stock_location_id: int, nome: string, quantidade: float}>}>
     */
    private function lotesComSaldo(?string $validadeDe, ?string $validadeAte): Collection
    {
        $linhas = StockBalance::query()
            ->join('product_batches', function (JoinClause $juncao): void {
                $juncao->on('product_batches.id', '=', 'stock_balances.product_batch_id')
                    ->whereColumn('product_batches.company_id', 'stock_balances.company_id');
            })
            ->join('stock_locations', function (JoinClause $juncao): void {
                $juncao->on('stock_locations.id', '=', 'stock_balances.stock_location_id')
                    ->whereColumn('stock_locations.company_id', 'stock_balances.company_id');
            })
            ->whereNotNull('stock_balances.product_batch_id')
            ->where('stock_balances.quantidade', '>', 0)
            ->when(
                $validadeDe !== null,
                fn ($consulta) => $consulta->where('product_batches.validade', '>=', $validadeDe)
            )
            ->when(
                $validadeAte !== null,
                fn ($consulta) => $consulta->where('product_batches.validade', '<=', $validadeAte)
            )
            ->orderBy('product_batches.validade')
            ->orderBy('product_batches.id')
            ->orderBy('stock_locations.nome')
            ->get([
                'stock_balances.product_batch_id',
                'stock_balances.quantidade',
                'stock_balances.stock_location_id',
                'stock_locations.nome as local_nome',
            ]);

        if ($linhas->isEmpty()) {
            return collect();
        }

        $lotes = ProductBatch::query()
            ->with('product')
            ->whereKey($linhas->pluck('product_batch_id')->unique()->all())
            ->get()
            ->keyBy(fn (ProductBatch $lote): int => (int) $lote->getKey());

        $hoje = BusinessDate::hoje();
        $agrupado = [];

        foreach ($linhas as $linha) {
            $loteId = (int) $linha->product_batch_id;
            $lote = $lotes->get($loteId);

            if ($lote === null) {
                continue;
            }

            if (! isset($agrupado[$loteId])) {
                $validade = BusinessDate::paraFusoNegocio($lote->validade)?->startOfDay();

                $agrupado[$loteId] = [
                    'lote' => $lote,
                    'validade' => $validade?->toDateString() ?? '',
                    'dias_para_vencer' => $validade !== null
                        ? (int) $hoje->diffInDays($validade, false)
                        : 0,
                    'saldo' => 0.0,
                    'locais' => [],
                ];
            }

            $quantidade = $this->normalizar((float) $linha->quantidade);

            $agrupado[$loteId]['saldo'] = $this->normalizar($agrupado[$loteId]['saldo'] + $quantidade);
            $agrupado[$loteId]['locais'][] = [
                'stock_location_id' => (int) $linha->stock_location_id,
                'nome' => (string) $linha->local_nome,
                'quantidade' => $quantidade,
            ];
        }

        return collect(array_values($agrupado));
    }

    /**
     * Produto sem nenhum lote cadastrado não é falta de saldo: é produto que não
     * trabalha com lote, e quem chama dá a baixa sem informar lote.
     *
     * Com lote cadastrado e nada disponível, a recusa vem com os números. O
     * saldo citado é o de lote dentro da validade naquele local: pode haver
     * produto na prateleira em lote vencido, e é `vencidos()` que mostra isso
     * para ser descartado.
     *
     * @return array<int, array{lote: ProductBatch, quantidade: float}>
     *
     * @throws EstoqueInsuficienteException
     */
    private function recusarOuIgnorar(Product $produto, StockLocation $local, int $empresa, float $pedido): array
    {
        $temLote = ProductBatch::query()
            ->where('company_id', $empresa)
            ->where('product_id', $produto->getKey())
            ->exists();

        if (! $temLote) {
            return [];
        }

        throw EstoqueInsuficienteException::para($produto, $local, null, 0.0, $pedido);
    }

    private function empresaDo(Product $produto): int
    {
        $empresa = $produto->company_id;

        if ($empresa === null) {
            throw new InvalidArgumentException(
                'O produto informado não tem empresa vinculada e não pode ter lote selecionado.'
            );
        }

        return (int) $empresa;
    }

    private function normalizar(float $valor): float
    {
        return round($valor, self::CASAS_DECIMAIS);
    }
}
