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
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Baixa de estoque pelo que foi aplicado na ordem de serviço e custo real de
 * produto da visita (Plano 17, Task 17.4).
 *
 * É a ponte entre dois mundos que hoje não se falam: o produto que o técnico
 * registra em campo (`work_order_product`, pelo painel ou pela sincronização do
 * Plano 13) e o razão de estoque (`stock_movements`, Task 17.2). Sem esta
 * ponte, o saldo só cai quando alguém lembra de lançar a saída na mão, e o
 * custo do serviço continua sendo chute.
 *
 * Quando roda
 * -----------
 * **Só no fechamento da OS**, nunca a cada toque de edição durante a visita. O
 * técnico corrige a quantidade três, quatro vezes enquanto aplica; baixar a
 * cada correção encheria o razão de movimentos de acerto e deixaria o extrato
 * ilegível justamente para quem precisa auditá-lo. Duas portas chamam esta
 * classe, pelo mesmo `WorkOrderService::baixarEstoqueDaOs()`: o fechamento
 * pelo aplicativo do técnico (`markAsCompleted()`, via
 * `Sync\AplicadorDeExecucao`) e a edição da OS pelo painel
 * (`updateWorkOrder()`, quando o formulário marca `status = completed`, e
 * também quando o escritório corrige quantidade de produto numa OS que já
 * estava concluída).
 *
 * Garantias que este arquivo prende
 * ---------------------------------
 * - **Custo congelado.** `custo_unitario_aplicado` é copiado do lote no
 *   instante da baixa. Compra futura muda o custo do lote e não mexe no custo
 *   da OS de ontem; do contrário o relatório de margem de um mês já fechado
 *   mudaria sozinho.
 * - **Saldo insuficiente vira pendência, nunca bloqueio.** O serviço já
 *   aconteceu no mundo real, e travar o fechamento por causa de um saldo
 *   desatualizado pararia a operação. A pendência fica gravada em
 *   `work_order_product.quantidade_pendente` e volta também na resposta do
 *   método, para quem chamou avisar na tela.
 * - **Movimento nunca é apagado.** Refazer a baixa estorna a anterior com
 *   `StockService::ajustar()` e motivo, e o estorno é mais uma linha no razão.
 * - **Produto sem `controla_estoque` não gera movimento algum.** O tenant liga
 *   o controle produto por produto, e o produto que ainda é só ficha técnica
 *   passa direto.
 * - **Baixa do item é tudo ou nada.** Os lotes de um mesmo item entram na mesma
 *   transação: meia baixa deixaria a OS dizendo que aplicou 6 enquanto o razão
 *   mostra 4, e o custo congelado sairia errado.
 *
 * Onde cada informação fica
 * -------------------------
 * O registro do produto aplicado guarda o **resumo**: o lote de maior parcela e
 * o custo unitário já congelado. O **detalhe** por lote fica no razão, uma
 * linha de `saida` por lote, com `work_order_id` preenchido. Essa divisão é de
 * propósito: a rastreabilidade que vai à fiscalização ("quais OS aplicaram o
 * lote L-042") se responde pelo razão, que nunca é apagado, enquanto o pivot é
 * reescrito toda vez que o escritório edita a OS.
 *
 * Quando a seleção por FEFO precisa de dois lotes para cobrir a quantidade, o
 * custo gravado é a **média ponderada** dos lotes usados, para que
 * `quantidade x custo_unitario_aplicado` continue devolvendo o custo real do
 * item.
 *
 * Datas
 * -----
 * O instante da baixa é o do fim da execução (`end_time`), com recuo para o
 * início (`start_time`) e, na falta dos dois, para agora. É esse instante que
 * decide se o lote estava dentro da validade **no dia da aplicação**, e não no
 * dia em que o aplicativo do técnico sincronizou: OS executada dia 20 com lote
 * que vence dia 20 é válida mesmo que o registro suba dia 25.
 * `scheduled_date` fica fora da lista de propósito: é campo `date`, um dia sem
 * hora, e transformá-lo em instante é exatamente o que produz o erro de um dia
 * que a skill `datas-timezone` existe para evitar.
 */
class WorkOrderStockService
{
    /**
     * Mesmas 4 casas das colunas de quantidade e de custo unitário, pelo mesmo
     * motivo do `StockService`: sem arredondar, a sobra binária de uma soma de
     * float faz "6 menos 6" virar 0,0000000001 e a comparação de "já foi
     * baixado tudo" nunca fecha.
     */
    private const CASAS_DECIMAIS = 4;

    /**
     * Casas do custo total de produto da OS. É dinheiro, e o consumidor (a
     * margem do Plano 18) trabalha em reais e centavos; a precisão de fração de
     * centavo vive no custo unitário, que tem 4 casas.
     */
    private const CASAS_DE_DINHEIRO = 2;

    public function __construct(
        private readonly StockService $estoque,
        private readonly BatchSelectionService $lotes,
    ) {}

    // -----------------------------------------------------------------
    // Baixa
    // -----------------------------------------------------------------

    /**
     * Dá baixa no estoque de tudo que a OS registra como aplicado.
     *
     * Para cada produto com `controla_estoque`, escolhe os lotes por FEFO no
     * local do técnico da OS, dá uma saída por lote e grava lote e custo
     * congelado no registro do produto aplicado.
     *
     * O que **não** acontece aqui: bloqueio. Falta de saldo em um item vira
     * pendência e os demais itens seguem normalmente.
     *
     * `$usuario` é quem assina a movimentação no razão. Omitido, vale o usuário
     * do técnico da OS, que é quem consumiu o produto. Sem nenhum dos dois a
     * baixa é recusada com `InvalidArgumentException`, porque movimento sem
     * responsável não serve para auditoria; quem chama no fechamento trata essa
     * recusa sem impedir a conclusão.
     *
     * `$refazer` força o estorno e a nova baixa mesmo quando nada mudou. Sem
     * ele, item cuja baixa registrada já corresponde à quantidade atual é
     * pulado: nada se moveu, e movimento de zero só polui o extrato (é a mesma
     * regra do `ajuste` que confirma o saldo, no `StockService`).
     *
     * @return array{
     *     movimentos: array<int, StockMovement>,
     *     estornos: array<int, StockMovement>,
     *     pendencias: array<int, array{product_id: int, produto: string, quantidade_aplicada: float, quantidade_pendente: float, saldo_disponivel: float, motivo: string}>,
     *     custo_total: float
     * }
     *
     * @throws InvalidArgumentException Quando não há usuário responsável nem
     *                                  local de estoque para a baixa.
     */
    public function baixarProdutosDaOs(WorkOrder $os, ?User $usuario = null, bool $refazer = false): array
    {
        $itens = $this->itensQueControlamEstoque($os);

        $resultado = ['movimentos' => [], 'estornos' => [], 'pendencias' => [], 'custo_total' => 0.0];

        if ($itens === []) {
            $resultado['custo_total'] = $this->custoDeProdutos($os);

            return $resultado;
        }

        $responsavel = $usuario ?? $this->responsavelPelaBaixa($os);
        $local = $this->localDaBaixa($os);
        $instante = $this->instanteDaAplicacao($os);

        foreach ($itens as $produto) {
            $quantidade = $this->normalizar((float) ($produto->pivot->quantity ?? 0));
            $baixado = $this->baixadoLiquido($os, $produto);

            // Nada mudou desde a última baixa: não há o que estornar nem o que
            // baixar de novo, e movimento de zero só polui o extrato.
            if (! $refazer && $baixado === $quantidade) {
                continue;
            }

            try {
                [$estornos, $movimentos] = DB::transaction(function () use (
                    $os,
                    $produto,
                    $local,
                    $quantidade,
                    $responsavel,
                    $instante
                ): array {
                    // O estorno vem primeiro: a baixa nova precisa do saldo de
                    // volta na prateleira para poder sair de novo.
                    $estornos = $this->estornarItem($os, $produto, $responsavel, $instante);
                    $movimentos = $this->baixarItem($os, $produto, $local, $quantidade, $responsavel, $instante);

                    return [$estornos, $movimentos];
                });
            } catch (EstoqueInsuficienteException|LoteVencidoException $recusa) {
                // A transação inteira do item voltou atrás, inclusive o
                // estorno: o que já estava baixado continua baixado, e a
                // pendência é só o pedaço que ficou sem baixa.
                $resultado['pendencias'][] = $this->registrarPendencia($os, $produto, $quantidade, $baixado, $recusa);

                continue;
            }

            $resultado['movimentos'] = [...$resultado['movimentos'], ...$movimentos];
            $resultado['estornos'] = [...$resultado['estornos'], ...$estornos];
        }

        $resultado['custo_total'] = $this->custoDeProdutos($os);

        return $resultado;
    }

    /**
     * Custo real de produto da OS: soma de `quantidade x custo congelado` de
     * cada produto aplicado.
     *
     * Consumido pelo cálculo de margem do Plano 18. Item sem custo congelado
     * (produto sem controle de estoque, produto sem lote cadastrado ou baixa
     * que virou pendência) entra como zero: o custo dele é desconhecido, e
     * inventar um valor daria margem falsa.
     */
    public function custoDeProdutos(WorkOrder $os): float
    {
        $total = 0.0;

        foreach ($os->products()->get() as $produto) {
            $custo = $produto->pivot->custo_unitario_aplicado;

            if ($custo === null) {
                continue;
            }

            $total += (float) ($produto->pivot->quantity ?? 0) * (float) $custo;
        }

        return round($total, self::CASAS_DE_DINHEIRO);
    }

    /**
     * Pendências de estoque abertas da OS, para a tela do escritório.
     *
     * @return array<int, array{product_id: int, produto: string, quantidade_pendente: float}>
     */
    public function pendenciasDaOs(WorkOrder $os): array
    {
        $pendencias = [];

        foreach ($os->products()->get() as $produto) {
            $pendente = $this->normalizar((float) ($produto->pivot->quantidade_pendente ?? 0));

            if ($pendente <= 0) {
                continue;
            }

            $pendencias[] = [
                'product_id' => (int) $produto->getKey(),
                'produto' => (string) $produto->name,
                'quantidade_pendente' => $pendente,
            ];
        }

        return $pendencias;
    }

    // -----------------------------------------------------------------
    // Baixa de um item
    // -----------------------------------------------------------------

    /**
     * Dá baixa da quantidade aplicada de um produto, uma saída por lote
     * escolhido pelo FEFO, e grava o resumo no registro do produto aplicado.
     *
     * Roda dentro da transação do item, junto com o estorno da baixa anterior:
     * ou todas as saídas dos lotes são gravadas, ou nenhuma. Meia baixa
     * deixaria a OS dizendo que aplicou 6 enquanto o razão mostra 4, e o custo
     * congelado sairia errado.
     *
     * @return array<int, StockMovement>
     *
     * @throws EstoqueInsuficienteException
     * @throws LoteVencidoException
     */
    private function baixarItem(
        WorkOrder $os,
        Product $produto,
        StockLocation $local,
        float $quantidade,
        User $usuario,
        DateTimeInterface $instante
    ): array {
        if ($quantidade <= 0) {
            // Produto que ficou na OS sem quantidade não movimenta nada, e o
            // registro anterior (se havia) é limpo junto com o estorno.
            $this->gravarNoRegistro($os, $produto, null, null, null);

            return [];
        }

        $selecao = $this->lotes->selecionar($produto, $local, $quantidade, $instante);

        if ($selecao === []) {
            // Produto sem nenhum lote cadastrado: não trabalha com lote, e a
            // baixa sai sem lote. Sem lote não há custo de aquisição a
            // congelar, e o custo do item fica desconhecido em vez de chutado.
            $movimento = $this->estoque->saida(
                $produto,
                $local,
                $quantidade,
                $usuario,
                null,
                $this->motivoDaBaixa($os),
                $os,
                $instante
            );

            $this->gravarNoRegistro($os, $produto, null, null, null);

            return $movimento !== null ? [$movimento] : [];
        }

        $movimentos = [];
        $custoTotal = 0.0;
        $quantidadeTotal = 0.0;
        $principal = null;
        $quantidadeDoPrincipal = 0.0;

        foreach ($selecao as $escolha) {
            /** @var ProductBatch $lote */
            $lote = $escolha['lote'];
            $daqui = $this->normalizar((float) $escolha['quantidade']);

            $movimento = $this->estoque->saida(
                $produto,
                $local,
                $daqui,
                $usuario,
                $lote,
                $this->motivoDaBaixa($os),
                $os,
                $instante
            );

            if ($movimento !== null) {
                $movimentos[] = $movimento;
            }

            // O custo é lido do lote **agora** e congelado: é este o valor que
            // vale para esta OS, mesmo que o lote seja recomprado mais caro
            // amanhã.
            $custoTotal += $daqui * (float) $lote->custo_unitario;
            $quantidadeTotal += $daqui;

            if ($daqui > $quantidadeDoPrincipal) {
                $principal = $lote;
                $quantidadeDoPrincipal = $daqui;
            }
        }

        $this->gravarNoRegistro(
            $os,
            $produto,
            $principal?->getKey(),
            $quantidadeTotal > 0 ? round($custoTotal / $quantidadeTotal, self::CASAS_DECIMAIS) : null,
            null
        );

        return $movimentos;
    }

    /**
     * Devolve ao saldo o que a baixa anterior desta OS tirou, com movimento de
     * `ajuste` e motivo.
     *
     * Nunca apaga movimento: o estorno é mais uma linha no razão, e a
     * sequência inteira continua auditável. O quanto estornar sai do próprio
     * razão (saídas desta OS menos estornos já feitos), e não do registro do
     * produto aplicado, porque o pivot é reescrito quando o escritório edita a
     * OS enquanto o razão nunca muda.
     *
     * @return array<int, StockMovement>
     */
    private function estornarItem(
        WorkOrder $os,
        Product $produto,
        User $usuario,
        DateTimeInterface $instante
    ): array {
        $estornos = [];

        foreach ($this->baixasPorLoteELocal($os, $produto) as $baixa) {
            $restante = $this->normalizar((float) $baixa->baixado - (float) $baixa->estornado);

            if ($restante <= 0) {
                continue;
            }

            $local = StockLocation::query()->find($baixa->stock_location_id);
            $lote = $baixa->product_batch_id !== null
                ? ProductBatch::query()->find($baixa->product_batch_id)
                : null;

            if ($local === null) {
                continue;
            }

            // `ajustar()` recebe a quantidade **contada**, não o delta: o saldo
            // passa a ser o que está lá agora mais o que a baixa tirou.
            $movimento = $this->estoque->ajustar(
                $produto,
                $local,
                $this->normalizar($this->saldoDaCombinacao($produto, $local, $lote) + $restante),
                $usuario,
                $this->motivoDoEstorno($os).' baixa refeita a partir do que a OS registra como aplicado.',
                $lote,
                null,
                $instante
            );

            if ($movimento !== null) {
                $estornos[] = $movimento;
            }
        }

        return $estornos;
    }

    /**
     * Grava no registro do produto aplicado o lote, o custo congelado e a
     * pendência.
     *
     * Escreve sempre os três, inclusive com nulo, porque este método também é o
     * que **limpa** o registro de uma baixa anterior que foi estornada.
     */
    private function gravarNoRegistro(
        WorkOrder $os,
        Product $produto,
        ?int $loteId,
        ?float $custo,
        ?float $pendente
    ): void {
        $os->products()->updateExistingPivot($produto->getKey(), [
            'product_batch_id' => $loteId,
            'custo_unitario_aplicado' => $custo,
            'quantidade_pendente' => $pendente,
        ]);
    }

    /**
     * Transforma a recusa de saldo em pendência visível, sem impedir nada.
     *
     * @return array{product_id: int, produto: string, quantidade_aplicada: float, quantidade_pendente: float, saldo_disponivel: float, motivo: string}
     */
    private function registrarPendencia(
        WorkOrder $os,
        Product $produto,
        float $quantidade,
        float $baixado,
        EstoqueInsuficienteException|LoteVencidoException $recusa
    ): array {
        $pendente = $this->normalizar(max($quantidade - $baixado, 0));

        $this->gravarNoRegistro(
            $os,
            $produto,
            $produto->pivot->product_batch_id !== null ? (int) $produto->pivot->product_batch_id : null,
            $produto->pivot->custo_unitario_aplicado !== null ? (float) $produto->pivot->custo_unitario_aplicado : null,
            $pendente > 0 ? $pendente : null
        );

        return [
            'product_id' => (int) $produto->getKey(),
            'produto' => (string) $produto->name,
            'quantidade_aplicada' => $quantidade,
            'quantidade_pendente' => $pendente,
            'saldo_disponivel' => $recusa instanceof EstoqueInsuficienteException
                ? $this->normalizar($recusa->saldoAtual)
                : 0.0,
            'motivo' => $recusa->getMessage(),
        ];
    }

    // -----------------------------------------------------------------
    // Leitura do razão
    // -----------------------------------------------------------------

    /**
     * Quanto desta OS já está baixado deste produto, líquido dos estornos.
     *
     * Lido do razão, que é a única fonte que não é reescrita quando o
     * escritório edita a OS.
     */
    private function baixadoLiquido(WorkOrder $os, Product $produto): float
    {
        $total = 0.0;

        foreach ($this->baixasPorLoteELocal($os, $produto) as $baixa) {
            $total += (float) $baixa->baixado - (float) $baixa->estornado;
        }

        return $this->normalizar($total);
    }

    /**
     * Saídas desta OS para este produto, agrupadas por lote e local, com o
     * quanto de cada combinação já foi estornado.
     *
     * O estorno é reconhecido pelo prefixo do motivo, que carrega o id da OS.
     * O motivo é o único elo possível: `StockService::ajustar()` não recebe
     * ordem de serviço (ajuste é acerto de contagem, não movimento da visita),
     * e mudar aquela assinatura sairia do escopo desta task. O prefixo é
     * gerado por `motivoDoEstorno()`, filtrado por igualdade de início de
     * texto, sem nenhuma extração de valor de dentro da frase.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function baixasPorLoteELocal(WorkOrder $os, Product $produto): \Illuminate\Support\Collection
    {
        $prefixo = $this->motivoDoEstorno($os);

        return StockMovement::query()
            ->where('company_id', $this->empresaDa($os))
            ->where('work_order_id', $os->getKey())
            ->where('product_id', $produto->getKey())
            ->where('tipo', StockService::TIPO_SAIDA)
            ->selectRaw('product_batch_id, stock_location_id, SUM(quantidade) as baixado')
            ->groupBy('product_batch_id', 'stock_location_id')
            ->get()
            ->map(function (object $linha) use ($os, $produto, $prefixo): object {
                $linha->estornado = (float) StockMovement::query()
                    ->where('company_id', $this->empresaDa($os))
                    ->where('product_id', $produto->getKey())
                    ->where('stock_location_id', $linha->stock_location_id)
                    ->when(
                        $linha->product_batch_id === null,
                        fn (Builder $consulta): Builder => $consulta->whereNull('product_batch_id'),
                        fn (Builder $consulta): Builder => $consulta->where('product_batch_id', $linha->product_batch_id)
                    )
                    ->where('tipo', StockService::TIPO_AJUSTE)
                    ->where('motivo', 'like', $prefixo.'%')
                    ->sum('quantidade');

                return $linha;
            });
    }

    /**
     * Saldo exato de uma combinação de produto, local e lote.
     *
     * Não usa `StockService::saldo()` com lote nulo de propósito: lá, sem lote,
     * a soma passa por **todos** os lotes do local, e aqui o que interessa é a
     * linha sem lote. Somar as duas coisas inflaria a quantidade contada do
     * ajuste e o estorno devolveria saldo que não existia.
     *
     * `lockForUpdate` porque quem chama está dentro da transação do item e vai
     * usar este valor como a quantidade **contada** do ajuste: sem o bloqueio,
     * uma baixa de outro técnico entre esta leitura e a escrita do ajuste seria
     * apagada pelo valor lido antes dela. O `StockService` pede a mesma linha
     * logo depois, e o bloqueio já é desta transação.
     */
    private function saldoDaCombinacao(Product $produto, StockLocation $local, ?ProductBatch $lote): float
    {
        $saldo = StockBalance::query()
            ->where('company_id', $produto->company_id)
            ->where('product_id', $produto->getKey())
            ->where('stock_location_id', $local->getKey())
            ->when(
                $lote === null,
                fn (Builder $consulta): Builder => $consulta->whereNull('product_batch_id'),
                fn (Builder $consulta): Builder => $consulta->where('product_batch_id', $lote->getKey())
            )
            ->lockForUpdate()
            ->value('quantidade');

        return $this->normalizar((float) ($saldo ?? 0));
    }

    // -----------------------------------------------------------------
    // Resolução do contexto da baixa
    // -----------------------------------------------------------------

    /**
     * Produtos aplicados na OS que controlam estoque.
     *
     * Produto sem `controla_estoque` fica de fora antes de qualquer consulta:
     * é o que permite ao tenant ligar o controle produto por produto, e é
     * também o que garante que a OS de quem ainda não usa estoque conclua sem
     * tocar em nada.
     *
     * @return array<int, Product>
     */
    private function itensQueControlamEstoque(WorkOrder $os): array
    {
        return $os->products()
            ->get()
            ->filter(fn (Product $produto): bool => (bool) $produto->controla_estoque)
            ->values()
            ->all();
    }

    /**
     * Local de onde o produto sai.
     *
     * Regra: o estoque do técnico da OS, ou seja, o local do tipo `tecnico`
     * vinculado ao `technician_id` da ordem. É onde o produto realmente está no
     * momento da aplicação, porque ele sai do depósito e viaja na van antes de
     * ser usado.
     *
     * Sem local próprio (técnico que não tem van, empresa que ainda não separou
     * o estoque por técnico, ou OS sem técnico definido), cai no **depósito
     * padrão da empresa**. Como ainda não existe marcação de "padrão" no
     * cadastro (fora do escopo desta task), o critério é o primeiro depósito
     * ativo por ordem de cadastro, que na prática é o depósito principal, o
     * único que a maioria das empresas tem. Escolher pelo mais antigo é
     * previsível e não muda sozinho quando alguém cadastra um segundo depósito.
     *
     * @throws InvalidArgumentException Quando a empresa não tem nenhum depósito
     *                                  onde lançar a baixa.
     */
    private function localDaBaixa(WorkOrder $os): StockLocation
    {
        $empresa = $this->empresaDa($os);

        if ($os->technician_id !== null) {
            $doTecnico = StockLocation::query()
                ->where('company_id', $empresa)
                ->where('tipo', 'tecnico')
                ->where('technician_id', $os->technician_id)
                ->where('ativo', true)
                ->orderBy('id')
                ->first();

            if ($doTecnico !== null) {
                return $doTecnico;
            }
        }

        $deposito = StockLocation::query()
            ->where('company_id', $empresa)
            ->where('tipo', 'deposito')
            ->where('ativo', true)
            ->orderBy('id')
            ->first();

        if ($deposito === null) {
            throw new InvalidArgumentException(
                'Não há depósito de estoque ativo cadastrado para lançar a baixa dos produtos desta ordem de serviço.'
            );
        }

        return $deposito;
    }

    /**
     * Quem assina a movimentação no razão.
     *
     * O usuário do técnico da OS, porque foi ele quem consumiu o produto.
     * O Service não consulta o usuário autenticado (regra da skill
     * `laravel-arquitetura`, e a baixa também roda em sincronização do
     * aplicativo, onde não existe sessão): quem quiser registrar outro
     * responsável passa no parâmetro.
     *
     * @throws InvalidArgumentException
     */
    private function responsavelPelaBaixa(WorkOrder $os): User
    {
        $usuario = $os->technician?->user;

        if ($usuario === null) {
            throw new InvalidArgumentException(
                'A ordem de serviço não tem técnico com usuário vinculado para responder pela baixa de estoque.'
            );
        }

        return $usuario;
    }

    /**
     * Instante em que a aplicação aconteceu em campo.
     *
     * Ver o cabeçalho da classe sobre por que `scheduled_date` não entra aqui.
     */
    private function instanteDaAplicacao(WorkOrder $os): DateTimeInterface
    {
        return $os->end_time ?? $os->start_time ?? now();
    }

    private function motivoDaBaixa(WorkOrder $os): string
    {
        return sprintf('Aplicação registrada na OS %s.', $os->order_number ?? '#'.$os->getKey());
    }

    /**
     * Prefixo estável do motivo do estorno, com o id da OS.
     *
     * É por ele que `baixasPorLoteELocal()` reconhece o que já foi estornado,
     * então o texto até os dois-pontos não muda sem migrar o histórico junto.
     */
    private function motivoDoEstorno(WorkOrder $os): string
    {
        return sprintf('Estorno da baixa de estoque da OS #%d:', $os->getKey());
    }

    private function empresaDa(WorkOrder $os): int
    {
        $empresa = $os->company_id;

        if ($empresa === null) {
            throw new InvalidArgumentException(
                'A ordem de serviço não tem empresa vinculada e não pode movimentar estoque.'
            );
        }

        return (int) $empresa;
    }

    private function normalizar(float $valor): float
    {
        return round($valor, self::CASAS_DECIMAIS);
    }
}
