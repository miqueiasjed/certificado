<?php

namespace App\Services;

use App\Exceptions\EstoqueInsuficienteException;
use App\Exceptions\LoteVencidoException;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A única porta de escrita do estoque (Plano 17, Task 17.2).
 *
 * O sistema guarda a mesma informação em dois lugares de propósito: o razão
 * (`stock_movements`), que é histórico imutável, e o saldo corrente
 * (`stock_balances`), que responde "quanto tem" sem somar um ano de movimentos.
 * Manter os dois batendo é responsabilidade deste Service, e é por isso que
 * movimentação por Eloquent direto em controller ou em outro Service é recusada
 * em revisão: saldo e razão divergentes são impossíveis de reconciliar depois,
 * porque ninguém sabe qual dos dois estava certo.
 *
 * Garantias que este arquivo prende
 * ---------------------------------
 * - **Movimento e saldo na mesma transação.** Ou os dois são gravados, ou
 *   nenhum.
 * - **`lockForUpdate` na linha de saldo antes de qualquer cálculo.** Dois
 *   técnicos dando baixa do mesmo lote ao mesmo tempo é caso real. Sem o
 *   bloqueio, os dois leem o mesmo saldo antigo e o segundo grava por cima do
 *   primeiro, o que some com uma das baixas. O bloqueio é pedido também quando a
 *   linha ainda não existe: no índice único da tabela isso vira bloqueio de
 *   intervalo, e é o que serializa a criação da primeira linha de saldo, caso
 *   que o unique sozinho não cobre quando `product_batch_id` é nulo (o MySQL
 *   trata NULL como valor distinto em índice único).
 * - **Saldo negativo nunca é gravado.** A saída que não cabe no saldo lança
 *   `EstoqueInsuficienteException` com os números, e a transação inteira volta
 *   atrás.
 * - **`quantidade` sempre positiva.** O sinal aplicado ao saldo vem do `tipo`,
 *   como manda a convenção da tabela.
 * - **`saldo_apos` é o saldo daquele produto, lote e local logo depois do
 *   movimento.** É o que permite auditar a sequência sem recalcular a série
 *   inteira.
 *
 * Produto sem controle de estoque
 * -------------------------------
 * `controla_estoque = false` faz todo método de escrita devolver `null` sem
 * gravar nada e sem lançar erro. É o que permite ao tenant ligar o controle
 * produto por produto, e o que deixa a Task 17.4 chamar a baixa para todo
 * produto aplicado na OS sem precisar perguntar antes.
 *
 * O que o `ajuste` significa
 * --------------------------
 * `ajustar()` recebe a **quantidade contada** e acerta o saldo para ela. O
 * movimento grava em `quantidade` o tamanho da diferença (sempre positivo) e em
 * `saldo_apos` o valor contado; a direção do acerto se lê comparando com o
 * `saldo_apos` do movimento anterior. Delta com sinal na coluna quebraria a
 * convenção da tabela, e é o tipo de inversão de sinal que ninguém encontra
 * depois porque o total continua parecendo plausível. Contagem que confirma o
 * saldo não gera movimento: nada se moveu.
 *
 * Empresa do movimento
 * --------------------
 * `company_id` de movimento e saldo vem do produto, gravado explicitamente, e
 * não do tenant corrente. O Service não consulta contexto de requisição (a
 * regra da skill `laravel-arquitetura`), e a baixa também roda em fila e em
 * sincronização do aplicativo, onde não existe usuário autenticado.
 *
 * Datas
 * -----
 * `ocorrido_em` é instante, não dia: duas baixas do mesmo lote no mesmo dia
 * precisam de ordem. É gravado no fuso da aplicação (UTC), como todo instante do
 * projeto, e convertido só na exibição. Quem chama pode informar o instante da
 * aplicação em campo, que é diferente do instante da sincronização, e é essa
 * data que decide se o lote estava vencido no dia em que foi aplicado, por
 * `diaDaAplicacao()`.
 *
 * Lote vencido (Task 17.3)
 * ------------------------
 * `saida()` recusa lote vencido com `LoteVencidoException`, comparando a
 * validade com o **dia da aplicação** no fuso do negócio. Bloqueio sanitário,
 * não preferência de operação.
 *
 * Os outros movimentos não bloqueiam, e isso é decisão, não esquecimento:
 * `entrada()` precisa aceitar a devolução do técnico com produto já vencido,
 * senão o produto volta para a prateleira sem constar em lugar nenhum;
 * `transferir()` precisa aceitar a volta do lote vencido da van para o depósito,
 * que é o caminho físico até o descarte; `ajustar()` registra o que a contagem
 * encontrou, e a prateleira não deixa de ter o que tem; e `descartar()` é
 * justamente a saída do lote vencido, sempre com motivo. O que a regra impede é
 * a aplicação, e a aplicação é `saida()`.
 *
 * A escolha automática do lote é do `BatchSelectionService` (FEFO, Task 17.3),
 * que também nunca devolve lote vencido. Fora do escopo daqui: o inventário é a
 * Task 17.5, e usa `ajustar()`.
 */
class StockService
{
    public const TIPO_ENTRADA = 'entrada';

    public const TIPO_SAIDA = 'saida';

    public const TIPO_TRANSFERENCIA_SAIDA = 'transferencia_saida';

    public const TIPO_TRANSFERENCIA_ENTRADA = 'transferencia_entrada';

    public const TIPO_AJUSTE = 'ajuste';

    public const TIPO_DESCARTE = 'descarte';

    /**
     * Mesmas 4 casas das colunas de quantidade. Toda conta passa por
     * `normalizar()` antes de comparar ou gravar, senão a sobra binária de uma
     * soma de float faria "6 menos 6" virar 0,0000000001 e o saldo nunca zerar.
     */
    private const CASAS_DECIMAIS = 4;

    // -----------------------------------------------------------------
    // Escrita
    // -----------------------------------------------------------------

    /**
     * Entrada de estoque: compra recebida, devolução do técnico ou saldo
     * inicial cadastrado depois da contagem física.
     *
     * Devolve `null` quando o produto não controla estoque.
     */
    public function entrada(
        Product $produto,
        StockLocation $local,
        float $quantidade,
        User $usuario,
        ?ProductBatch $lote = null,
        ?string $motivo = null,
        ?WorkOrder $ordemDeServico = null,
        DateTimeInterface|string|null $ocorridoEm = null
    ): ?StockMovement {
        return $this->registrarMovimento(
            tipo: self::TIPO_ENTRADA,
            produto: $produto,
            local: $local,
            quantidade: $quantidade,
            usuario: $usuario,
            lote: $lote,
            motivo: $motivo,
            ordemDeServico: $ordemDeServico,
            ocorridoEm: $ocorridoEm
        );
    }

    /**
     * Saída de estoque: produto aplicado no cliente ou consumido.
     *
     * Recusa com `EstoqueInsuficienteException` quando a quantidade não cabe no
     * saldo do produto (ou do lote) naquele local, e com `LoteVencidoException`
     * quando o lote informado já estava vencido no dia da aplicação.
     *
     * @throws EstoqueInsuficienteException
     * @throws LoteVencidoException
     */
    public function saida(
        Product $produto,
        StockLocation $local,
        float $quantidade,
        User $usuario,
        ?ProductBatch $lote = null,
        ?string $motivo = null,
        ?WorkOrder $ordemDeServico = null,
        DateTimeInterface|string|null $ocorridoEm = null
    ): ?StockMovement {
        if ($lote !== null) {
            // A coerência vem primeiro para que lote de outro produto ou de
            // outra empresa continue sendo recusado como erro de vínculo, e não
            // como vencimento. `registrarMovimento` repete a checagem: é barata,
            // não consulta banco, e vale para quem chegar por outro caminho.
            $this->garantirCoerencia($produto, $local, $lote);
            $this->garantirLoteNaoVencido($produto, $lote, $this->diaDaAplicacao($ocorridoEm));
        }

        return $this->registrarMovimento(
            tipo: self::TIPO_SAIDA,
            produto: $produto,
            local: $local,
            quantidade: $quantidade,
            usuario: $usuario,
            lote: $lote,
            motivo: $motivo,
            ordemDeServico: $ordemDeServico,
            ocorridoEm: $ocorridoEm
        );
    }

    /**
     * Transferência entre dois locais: sai de um, entra no outro.
     *
     * Os dois movimentos nascem na mesma transação e com o mesmo
     * `transferencia_id`. Transferência que grava metade é o erro que faz o
     * estoque nunca fechar: o produto some do depósito e não aparece na van, e
     * ninguém sabe se foi roubo, digitação ou defeito do sistema.
     *
     * @return array{transferencia_id: string, saida: StockMovement, entrada: StockMovement}|null
     *
     * @throws EstoqueInsuficienteException
     */
    public function transferir(
        Product $produto,
        StockLocation $origem,
        StockLocation $destino,
        float $quantidade,
        User $usuario,
        ?ProductBatch $lote = null,
        ?string $motivo = null,
        DateTimeInterface|string|null $ocorridoEm = null
    ): ?array {
        if ($origem->getKey() === $destino->getKey()) {
            throw new InvalidArgumentException('A transferência precisa de dois locais diferentes.');
        }

        $this->garantirCoerencia($produto, $destino, $lote);
        $this->garantirQuantidade(self::TIPO_TRANSFERENCIA_SAIDA, $quantidade);

        if (! $produto->controla_estoque) {
            return null;
        }

        // Instante único para os dois lados: são o mesmo evento, e horários
        // diferentes bagunçariam a ordem do razão na conferência.
        $instante = $this->instanteDoMovimento($ocorridoEm);
        $transferencia = (string) Str::uuid();

        return DB::transaction(function () use (
            $produto,
            $origem,
            $destino,
            $quantidade,
            $usuario,
            $lote,
            $motivo,
            $instante,
            $transferencia
        ): array {
            $saida = $this->registrarMovimento(
                tipo: self::TIPO_TRANSFERENCIA_SAIDA,
                produto: $produto,
                local: $origem,
                quantidade: $quantidade,
                usuario: $usuario,
                lote: $lote,
                motivo: $motivo,
                ocorridoEm: $instante,
                transferenciaId: $transferencia
            );

            $entrada = $this->registrarMovimento(
                tipo: self::TIPO_TRANSFERENCIA_ENTRADA,
                produto: $produto,
                local: $destino,
                quantidade: $quantidade,
                usuario: $usuario,
                lote: $lote,
                motivo: $motivo,
                ocorridoEm: $instante,
                transferenciaId: $transferencia
            );

            return [
                'transferencia_id' => $transferencia,
                'saida' => $saida,
                'entrada' => $entrada,
            ];
        });
    }

    /**
     * Acerta o saldo para a quantidade contada, gravando a diferença como
     * movimento de `ajuste` com motivo.
     *
     * É o caminho de correção do sistema: movimento não é apagado nem alterado,
     * e a divergência fica registrada com quem contou e por quê, que é o que
     * vale perante fiscalização. Contagem que confirma o saldo devolve `null`,
     * porque nada se moveu.
     *
     * `$inventoryId` liga o ajuste à contagem que o originou (Task 17.5).
     */
    public function ajustar(
        Product $produto,
        StockLocation $local,
        float $quantidadeContada,
        User $usuario,
        string $motivo,
        ?ProductBatch $lote = null,
        ?int $inventoryId = null,
        DateTimeInterface|string|null $ocorridoEm = null
    ): ?StockMovement {
        $this->garantirMotivo($motivo, 'O ajuste de estoque exige motivo registrado.');

        return $this->registrarMovimento(
            tipo: self::TIPO_AJUSTE,
            produto: $produto,
            local: $local,
            quantidade: $quantidadeContada,
            usuario: $usuario,
            lote: $lote,
            motivo: $motivo,
            inventoryId: $inventoryId,
            ocorridoEm: $ocorridoEm
        );
    }

    /**
     * Descarte: tira do saldo o que foi perdido, quebrado ou vencido.
     *
     * Sempre com motivo. O descarte é o que fecha o ciclo do lote vencido
     * perante fiscalização, e um descarte sem motivo é indistinguível de erro de
     * digitação.
     *
     * @throws EstoqueInsuficienteException
     */
    public function descartar(
        Product $produto,
        StockLocation $local,
        float $quantidade,
        User $usuario,
        string $motivo,
        ?ProductBatch $lote = null,
        DateTimeInterface|string|null $ocorridoEm = null
    ): ?StockMovement {
        $this->garantirMotivo($motivo, 'O descarte de estoque exige motivo registrado.');

        return $this->registrarMovimento(
            tipo: self::TIPO_DESCARTE,
            produto: $produto,
            local: $local,
            quantidade: $quantidade,
            usuario: $usuario,
            lote: $lote,
            motivo: $motivo,
            ocorridoEm: $ocorridoEm
        );
    }

    // -----------------------------------------------------------------
    // Consulta
    // -----------------------------------------------------------------

    /**
     * Saldo corrente, lido de `stock_balances`.
     *
     * Sem local, soma todos os locais; sem lote, soma todos os lotes. Produto
     * que nunca movimentou devolve 0.
     */
    public function saldo(Product $produto, ?StockLocation $local = null, ?ProductBatch $lote = null): float
    {
        $consulta = StockBalance::query()
            ->where('company_id', $this->empresaDo($produto))
            ->where('product_id', $produto->getKey());

        if ($local !== null) {
            $consulta->where('stock_location_id', $local->getKey());
        }

        if ($lote !== null) {
            $consulta->where('product_batch_id', $lote->getKey());
        }

        return $this->normalizar((float) $consulta->sum('quantidade'));
    }

    /**
     * Saldo do produto por local, somando os lotes de cada um.
     *
     * Devolve uma coleção indexada pelo id do local, com o nome e o tipo já
     * resolvidos, porque quem consome isto (tela de estoque e aplicativo do
     * técnico) sempre precisa mostrar de que local se trata.
     *
     * @return Collection<int, array{stock_location_id: int, nome: string, tipo: string, quantidade: float}>
     */
    public function saldoPorLocal(Product $produto): Collection
    {
        $empresa = $this->empresaDo($produto);

        $totais = StockBalance::query()
            ->where('company_id', $empresa)
            ->where('product_id', $produto->getKey())
            ->selectRaw('stock_location_id, SUM(quantidade) as total')
            ->groupBy('stock_location_id')
            ->get();

        if ($totais->isEmpty()) {
            return collect();
        }

        $locais = StockLocation::query()
            ->where('company_id', $empresa)
            ->whereKey($totais->pluck('stock_location_id')->all())
            ->get()
            ->keyBy(fn (StockLocation $local): int => (int) $local->getKey());

        return $totais
            ->map(function (StockBalance $linha) use ($locais): array {
                $localId = (int) $linha->stock_location_id;
                $local = $locais->get($localId);

                return [
                    'stock_location_id' => $localId,
                    'nome' => (string) ($local?->nome ?? ''),
                    'tipo' => (string) ($local?->tipo ?? ''),
                    'quantidade' => $this->normalizar((float) $linha->total),
                ];
            })
            ->sortBy('nome')
            ->values()
            ->keyBy('stock_location_id');
    }

    // -----------------------------------------------------------------
    // Auditoria
    // -----------------------------------------------------------------

    /**
     * Reconstrói o saldo do produto a partir do razão e devolve o que estava
     * divergente.
     *
     * Ferramenta de conferência e correção, chamada por comando ou por tela de
     * administração. **Nunca em rotina automática**: recálculo periódico
     * esconderia o defeito em vez de mostrá-lo, e o dia em que saldo e razão
     * divergirem é justamente o dia em que alguém precisa investigar por quê.
     *
     * A reconstrução é uma releitura do razão em ordem, e não uma soma solta,
     * porque `ajuste` não é um delta: é o saldo declarado na contagem, e a partir
     * dele a série recomeça. Por isso o método também confere, movimento a
     * movimento, se o `saldo_apos` gravado bate com a sequência, que é como se
     * descobre em que ponto a divergência começou.
     *
     * @return array{
     *     product_id: int,
     *     combinacoes: int,
     *     saldos_corrigidos: int,
     *     divergencias: array<int, array{stock_location_id: int, product_batch_id: int|null, saldo_gravado: float, saldo_do_razao: float}>,
     *     movimentos_com_saldo_apos_divergente: array<int, array{id: int, saldo_apos_gravado: float, saldo_apos_esperado: float}>
     * }
     */
    public function recalcularSaldo(Product $produto): array
    {
        $empresa = $this->empresaDo($produto);

        return DB::transaction(function () use ($produto, $empresa): array {
            $divergencias = [];
            $movimentosDivergentes = [];
            $corrigidos = 0;
            $combinacoes = $this->combinacoesDoProduto($produto, $empresa);

            foreach ($combinacoes as $combinacao) {
                [$localId, $loteId] = $combinacao;

                $saldoDoRazao = 0.0;

                foreach ($this->movimentosDaCombinacao($produto, $empresa, $localId, $loteId) as $movimento) {
                    $saldoDoRazao = $this->saldoDepoisDe(
                        (string) $movimento->tipo,
                        $saldoDoRazao,
                        (float) $movimento->quantidade,
                        (float) $movimento->saldo_apos
                    );

                    if ($saldoDoRazao !== $this->normalizar((float) $movimento->saldo_apos)) {
                        $movimentosDivergentes[] = [
                            'id' => (int) $movimento->getKey(),
                            'saldo_apos_gravado' => $this->normalizar((float) $movimento->saldo_apos),
                            'saldo_apos_esperado' => $saldoDoRazao,
                        ];
                    }
                }

                $linha = $this->linhaDeSaldoTravada($empresa, (int) $produto->getKey(), $localId, $loteId);
                $saldoGravado = $this->normalizar((float) $linha->quantidade);

                if ($saldoGravado === $saldoDoRazao) {
                    continue;
                }

                $divergencias[] = [
                    'stock_location_id' => $localId,
                    'product_batch_id' => $loteId,
                    'saldo_gravado' => $saldoGravado,
                    'saldo_do_razao' => $saldoDoRazao,
                ];

                $linha->quantidade = $saldoDoRazao;
                $linha->save();
                $corrigidos++;
            }

            return [
                'product_id' => (int) $produto->getKey(),
                'combinacoes' => count($combinacoes),
                'saldos_corrigidos' => $corrigidos,
                'divergencias' => $divergencias,
                'movimentos_com_saldo_apos_divergente' => $movimentosDivergentes,
            ];
        });
    }

    // -----------------------------------------------------------------
    // Núcleo da escrita
    // -----------------------------------------------------------------

    /**
     * Grava um movimento e o saldo correspondente na mesma transação, com a
     * linha de saldo bloqueada antes de qualquer cálculo.
     *
     * Devolve `null` quando o produto não controla estoque e quando o ajuste
     * apenas confirma o saldo.
     *
     * @throws EstoqueInsuficienteException
     */
    private function registrarMovimento(
        string $tipo,
        Product $produto,
        StockLocation $local,
        float $quantidade,
        User $usuario,
        ?ProductBatch $lote = null,
        ?string $motivo = null,
        ?WorkOrder $ordemDeServico = null,
        ?int $inventoryId = null,
        DateTimeInterface|string|null $ocorridoEm = null,
        ?string $transferenciaId = null
    ): ?StockMovement {
        $this->garantirCoerencia($produto, $local, $lote);
        $this->garantirQuantidade($tipo, $quantidade);

        // Produto sem controle de estoque não movimenta e não reclama: é o que
        // permite ligar o controle produto por produto.
        if (! $produto->controla_estoque) {
            return null;
        }

        $empresa = $this->empresaDo($produto);
        $quantidade = $this->normalizar($quantidade);
        $instante = $this->instanteDoMovimento($ocorridoEm);

        return DB::transaction(function () use (
            $tipo,
            $produto,
            $local,
            $lote,
            $quantidade,
            $usuario,
            $motivo,
            $ordemDeServico,
            $inventoryId,
            $instante,
            $transferenciaId,
            $empresa
        ): ?StockMovement {
            $linha = $this->linhaDeSaldoTravada(
                $empresa,
                (int) $produto->getKey(),
                (int) $local->getKey(),
                $lote !== null ? (int) $lote->getKey() : null
            );

            $saldoAtual = $this->normalizar((float) $linha->quantidade);

            [$quantidadeDoMovimento, $novoSaldo] = $this->calcular($tipo, $saldoAtual, $quantidade);

            // Contagem que confirma o saldo não move nada, e movimento de zero
            // só polui o extrato.
            if ($tipo === self::TIPO_AJUSTE && $quantidadeDoMovimento === 0.0) {
                return null;
            }

            if ($novoSaldo < 0) {
                throw EstoqueInsuficienteException::para($produto, $local, $lote, $saldoAtual, $quantidade);
            }

            $linha->quantidade = $novoSaldo;
            $linha->save();

            $movimento = new StockMovement([
                'product_id' => $produto->getKey(),
                'product_batch_id' => $lote?->getKey(),
                'stock_location_id' => $local->getKey(),
                'tipo' => $tipo,
                'quantidade' => $quantidadeDoMovimento,
                'saldo_apos' => $novoSaldo,
                'work_order_id' => $ordemDeServico?->getKey(),
                'transferencia_id' => $transferenciaId,
                'inventory_id' => $inventoryId,
                'motivo' => $motivo,
                'user_id' => $usuario->getKey(),
                'ocorrido_em' => $instante,
            ]);

            // `company_id` não é atributo de atribuição em massa em nenhum model
            // de domínio (há teste que cobra isso): vem do produto, gravado
            // direto.
            $movimento->company_id = $empresa;
            $movimento->save();

            return $movimento;
        });
    }

    /**
     * Linha de saldo do produto, lote e local, bloqueada para escrita.
     *
     * O `lockForUpdate` vem antes de qualquer leitura de valor, inclusive quando
     * a linha ainda não existe: nesse caso o bloqueio recai sobre o intervalo do
     * índice único, e é o que impede duas transações de criarem a mesma linha ao
     * mesmo tempo. A criação ainda assim é protegida por `try/catch`, porque
     * outra transação pode ter acabado de confirmar a inserção; nesse caso a
     * releitura já enxerga a linha dela.
     */
    private function linhaDeSaldoTravada(int $empresa, int $produtoId, int $localId, ?int $loteId): StockBalance
    {
        $linha = $this->consultaDeSaldo($empresa, $produtoId, $localId, $loteId)->lockForUpdate()->first();

        if ($linha !== null) {
            return $linha;
        }

        try {
            $nova = new StockBalance([
                'product_id' => $produtoId,
                'product_batch_id' => $loteId,
                'stock_location_id' => $localId,
                'quantidade' => 0,
            ]);

            $nova->company_id = $empresa;
            $nova->save();
        } catch (QueryException) {
            // Outra transação criou a mesma linha primeiro. A releitura abaixo
            // pega a linha dela, já bloqueada para esta transação.
        }

        return $this->consultaDeSaldo($empresa, $produtoId, $localId, $loteId)->lockForUpdate()->firstOrFail();
    }

    /**
     * @return Builder<StockBalance>
     */
    private function consultaDeSaldo(int $empresa, int $produtoId, int $localId, ?int $loteId): Builder
    {
        return StockBalance::query()
            ->where('company_id', $empresa)
            ->where('product_id', $produtoId)
            ->where('stock_location_id', $localId)
            ->when(
                $loteId === null,
                fn (Builder $consulta): Builder => $consulta->whereNull('product_batch_id'),
                fn (Builder $consulta): Builder => $consulta->where('product_batch_id', $loteId)
            );
    }

    /**
     * Quantidade do movimento e saldo resultante, por tipo.
     *
     * O sinal aplicado ao saldo vem daqui, nunca do sinal recebido: `quantidade`
     * é sempre positiva na tabela.
     *
     * @return array{0: float, 1: float}
     */
    private function calcular(string $tipo, float $saldoAtual, float $quantidade): array
    {
        return match ($tipo) {
            self::TIPO_ENTRADA, self::TIPO_TRANSFERENCIA_ENTRADA => [
                $quantidade,
                $this->normalizar($saldoAtual + $quantidade),
            ],
            self::TIPO_SAIDA, self::TIPO_TRANSFERENCIA_SAIDA, self::TIPO_DESCARTE => [
                $quantidade,
                $this->normalizar($saldoAtual - $quantidade),
            ],
            // Ajuste recebe a quantidade contada: o movimento registra o tamanho
            // da diferença e o saldo passa a ser o valor contado.
            self::TIPO_AJUSTE => [
                $this->normalizar(abs($quantidade - $saldoAtual)),
                $quantidade,
            ],
        };
    }

    // -----------------------------------------------------------------
    // Releitura do razão
    // -----------------------------------------------------------------

    /**
     * Combinações de local e lote que este produto tem no razão ou no saldo.
     *
     * O saldo entra na lista para o recálculo alcançar também a linha que existe
     * sem nenhum movimento por trás, que é exatamente o tipo de sujeira que a
     * conferência precisa mostrar.
     *
     * @return array<int, array{0: int, 1: int|null}>
     */
    private function combinacoesDoProduto(Product $produto, int $empresa): array
    {
        $doRazao = StockMovement::query()
            ->where('company_id', $empresa)
            ->where('product_id', $produto->getKey())
            ->select('stock_location_id', 'product_batch_id')
            ->distinct()
            ->get();

        $doSaldo = StockBalance::query()
            ->where('company_id', $empresa)
            ->where('product_id', $produto->getKey())
            ->select('stock_location_id', 'product_batch_id')
            ->distinct()
            ->get();

        $combinacoes = [];

        foreach ($doRazao->concat($doSaldo) as $linha) {
            $localId = (int) $linha->stock_location_id;
            $loteId = $linha->product_batch_id !== null ? (int) $linha->product_batch_id : null;

            $combinacoes[$localId.':'.($loteId ?? 'sem-lote')] = [$localId, $loteId];
        }

        return array_values($combinacoes);
    }

    /**
     * Movimentos de uma combinação, na ordem em que aconteceram.
     *
     * O desempate por `id` importa: duas baixas registradas no mesmo instante
     * (sincronização do aplicativo em lote) precisam de ordem estável, senão a
     * conferência de `saldo_apos` acusaria divergência inexistente.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, StockMovement>
     */
    private function movimentosDaCombinacao(
        Product $produto,
        int $empresa,
        int $localId,
        ?int $loteId
    ): \Illuminate\Database\Eloquent\Collection {
        return StockMovement::query()
            ->where('company_id', $empresa)
            ->where('product_id', $produto->getKey())
            ->where('stock_location_id', $localId)
            ->when(
                $loteId === null,
                fn (Builder $consulta): Builder => $consulta->whereNull('product_batch_id'),
                fn (Builder $consulta): Builder => $consulta->where('product_batch_id', $loteId)
            )
            ->orderBy('ocorrido_em')
            ->orderBy('id')
            ->get();
    }

    /**
     * Saldo depois de aplicar um movimento do razão.
     *
     * `ajuste` reancora a série no valor contado, que é o único dado que aquele
     * movimento carrega sobre o saldo: a diferença gravada em `quantidade` é
     * módulo, sem direção.
     */
    private function saldoDepoisDe(string $tipo, float $saldoAnterior, float $quantidade, float $saldoApos): float
    {
        return match ($tipo) {
            self::TIPO_ENTRADA, self::TIPO_TRANSFERENCIA_ENTRADA => $this->normalizar($saldoAnterior + $quantidade),
            self::TIPO_SAIDA, self::TIPO_TRANSFERENCIA_SAIDA, self::TIPO_DESCARTE => $this->normalizar($saldoAnterior - $quantidade),
            self::TIPO_AJUSTE => $this->normalizar($saldoApos),
            default => $this->normalizar($saldoAnterior),
        };
    }

    // -----------------------------------------------------------------
    // Guardas e utilitários
    // -----------------------------------------------------------------

    /**
     * Produto, local e lote precisam ser da mesma empresa, e o lote precisa ser
     * do produto.
     *
     * O escopo global por empresa cobre o caminho comum, em que os três vêm de
     * consulta já escopada. Esta guarda cobre o resto: id vindo do corpo da
     * requisição, fila com payload montado à mão e sincronização do aplicativo.
     * Movimentar o lote de um produto na conta de outro é divergência que só
     * aparece na fiscalização, quando alguém pergunta qual lote foi aplicado.
     */
    private function garantirCoerencia(Product $produto, StockLocation $local, ?ProductBatch $lote): void
    {
        $empresa = $this->empresaDo($produto);

        if ((int) $local->company_id !== $empresa) {
            throw new InvalidArgumentException(
                'O local de estoque informado é de outra empresa e não pode receber movimentação deste produto.'
            );
        }

        if ($lote === null) {
            return;
        }

        if ((int) $lote->product_id !== (int) $produto->getKey()) {
            throw new InvalidArgumentException('O lote informado não pertence a este produto.');
        }

        if ((int) $lote->company_id !== $empresa) {
            throw new InvalidArgumentException('O lote informado é de outra empresa.');
        }
    }

    /**
     * O lote informado não pode estar vencido no dia da aplicação.
     *
     * Comparação por dia, no fuso do negócio: o lote que vence hoje ainda vale
     * hoje, inclusive às 21h de Brasília, quando em UTC já é amanhã. É o erro de
     * um dia que a skill `datas-timezone` existe para evitar, e aqui ele
     * inventaria uma infração sanitária que não aconteceu.
     *
     * @throws LoteVencidoException
     */
    private function garantirLoteNaoVencido(Product $produto, ProductBatch $lote, CarbonImmutable $dia): void
    {
        $validade = BusinessDate::paraFusoNegocio($lote->validade);

        // Lote sem validade não é bloqueado: não há data para comparar, e
        // recusar por ausência de dado pararia a operação sem motivo sanitário.
        // A coluna é obrigatória no banco; isto cobre o model montado em
        // memória.
        if ($validade === null) {
            return;
        }

        if ($validade->startOfDay()->lessThan($dia->startOfDay())) {
            throw LoteVencidoException::para($produto, $lote, $dia);
        }
    }

    /**
     * Quantidade de movimento é maior que zero. No ajuste, a contagem pode ser
     * zero (prateleira vazia é resultado legítimo de contagem), mas nunca
     * negativa.
     */
    private function garantirQuantidade(string $tipo, float $quantidade): void
    {
        if ($tipo === self::TIPO_AJUSTE) {
            if ($quantidade < 0) {
                throw new InvalidArgumentException('A quantidade contada no ajuste não pode ser negativa.');
            }

            return;
        }

        if ($this->normalizar($quantidade) <= 0) {
            throw new InvalidArgumentException('A quantidade de um movimento de estoque precisa ser maior que zero.');
        }
    }

    private function garantirMotivo(string $motivo, string $mensagem): void
    {
        if (trim($motivo) === '') {
            throw new InvalidArgumentException($mensagem);
        }
    }

    private function empresaDo(Product $produto): int
    {
        $empresa = $produto->company_id;

        if ($empresa === null) {
            throw new InvalidArgumentException(
                'O produto informado não tem empresa vinculada e não pode movimentar estoque.'
            );
        }

        return (int) $empresa;
    }

    /**
     * Dia em que a movimentação conta, no fuso do negócio.
     *
     * É o dia do `ocorrido_em`, ou seja, o dia em que a aplicação aconteceu em
     * campo, e nunca o dia em que o aplicativo do técnico sincronizou. OS
     * executada dia 20 com lote que vence dia 20 é válida mesmo que o registro
     * suba dia 25: julgar pelo dia da sincronização condenaria retroativamente
     * uma aplicação que era legítima quando aconteceu.
     *
     * Público porque o `BatchSelectionService` precisa do mesmo dia para o FEFO.
     * Fossem dois cálculos, a seleção poderia entregar um lote que a baixa
     * recusa em seguida, e a divergência apareceria só na virada do dia.
     */
    public function diaDaAplicacao(DateTimeInterface|string|null $ocorridoEm = null): CarbonImmutable
    {
        return $this->instanteDoMovimento($ocorridoEm)
            ->setTimezone(BusinessDate::fuso())
            ->startOfDay();
    }

    /**
     * Instante do movimento, no fuso da aplicação (UTC), que é como todo campo
     * `datetime` do projeto é gravado.
     *
     * Texto passa por `BusinessDate`, porque "2026-07-20" digitado por um
     * usuário é o dia 20 em Brasília, e interpretá-lo em UTC jogaria a
     * movimentação para as 21h do dia 19. Objeto de data já carrega o próprio
     * fuso, e aí a conversão é `setTimezone` puro: reinterpretar um instante
     * como dia é o que produz o erro de um dia.
     */
    private function instanteDoMovimento(DateTimeInterface|string|null $ocorridoEm): CarbonImmutable
    {
        $fusoDaAplicacao = config('app.timezone') ?: 'UTC';

        if ($ocorridoEm === null) {
            return BusinessDate::agora()->setTimezone($fusoDaAplicacao);
        }

        if ($ocorridoEm instanceof DateTimeInterface) {
            return CarbonImmutable::instance($ocorridoEm)->setTimezone($fusoDaAplicacao);
        }

        $instante = BusinessDate::paraFusoNegocio($ocorridoEm);

        if ($instante === null) {
            throw new InvalidArgumentException('Data de movimentação inválida.');
        }

        return $instante->setTimezone($fusoDaAplicacao);
    }

    /**
     * Arredonda para as 4 casas das colunas de quantidade.
     *
     * Sem isto, a sobra binária de uma soma de float faria uma saída de 6 sobre
     * um saldo de 6 terminar em 0,0000000001, e o saldo nunca zeraria.
     */
    private function normalizar(float $valor): float
    {
        return round($valor, self::CASAS_DECIMAIS);
    }
}
