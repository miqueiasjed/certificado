<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\InventoryItem;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * O processo de inventário com ajuste justificado (Plano 17, Task 17.5).
 *
 * `StockService::ajustar()` (Task 17.2) já sabe gravar a diferença de uma
 * contagem como movimento de `ajuste`, sempre com motivo. O que faltava era o
 * processo que produz essa diferença a partir de uma contagem física de
 * verdade, e é isso que este Service faz, em três passos:
 *
 * 1. `abrir()` fotografa o saldo do sistema, item a item.
 * 2. `contar()` grava o que foi encontrado na prateleira, exigindo
 *    justificativa sempre que houver diferença.
 * 3. `finalizar()` recusa fechar contagem incompleta ou divergência sem
 *    justificativa, e só então chama `StockService::ajustar()` por item
 *    divergente.
 *
 * O saldo do sistema é congelado, a operação não é
 * ------------------------------------------------
 * `saldo_sistema` é gravado uma vez, na abertura, e nunca recalculado. Mas o
 * local continua recebendo entrada, saída e transferência normalmente
 * enquanto a contagem acontece: travar o estoque para contar pararia a
 * operação da empresa, e a especificação é explícita nisso. A consequência é
 * que a diferença **de exibição**, calculada em `contar()` contra o saldo
 * congelado, pode não ser exatamente o que `finalizar()` vai gravar: quem
 * decide o tamanho e até a existência do ajuste é sempre
 * `StockService::ajustar()`, que relê o saldo atual com `lockForUpdate` no
 * momento do fechamento. Um movimento acontecido durante a contagem é assim
 * naturalmente absorvido, e uma contagem que já bate com o saldo real no
 * fechamento não gera movimento nenhum, mesmo que tivesse divergido do
 * congelado na abertura.
 *
 * Por que `finalizar()` só chama `ajustar()` para item com diferença
 * -------------------------------------------------------------------
 * Divergência sem justificativa nunca chega a existir neste Service:
 * `contar()` já recusa gravar diferença sem motivo. Chamar `ajustar()` também
 * para item sem diferença exigiria inventar um motivo para uma linha que
 * ninguém apontou como discrepante, o que é o próprio "ajuste silencioso" que
 * a regra de negócio proíbe. Por isso o item que a contagem confirmou fica
 * como está: se um movimento no meio-tempo abriu uma divergência que ninguém
 * viu, ela aparece na próxima contagem, que é o caminho que a especificação
 * desenhou para corrigir contagem errada ("contagem errada exige novo
 * inventário").
 *
 * Quem abriu e quem fechou
 * ------------------------
 * `abrir()` e `finalizar()` recebem o usuário explicitamente, como todo
 * método de escrita do estoque (`StockService`): o Service não consulta
 * `Auth::user()` internamente, porque também roda fora de requisição HTTP.
 *
 * Imutabilidade do inventário finalizado
 * ---------------------------------------
 * `contar()` e `finalizar()` recusam qualquer inventário que não esteja
 * `aberto`. Contagem errada não é corrigida alterando um inventário já
 * fechado: abre-se um novo, e o anterior fica no histórico.
 */
class InventoryService
{
    /**
     * Mesmas 4 casas das colunas de quantidade do estoque (Task 17.1), para a
     * comparação de diferença não confundir sobra binária de float com
     * divergência real.
     */
    private const CASAS_DECIMAIS = 4;

    public function __construct(private readonly StockService $stockService) {}

    // -----------------------------------------------------------------
    // Abertura
    // -----------------------------------------------------------------

    /**
     * Abre uma contagem no local, com um item por combinação de produto e
     * lote que tenha uma linha de saldo ali, saldo zero incluído.
     *
     * Saldo zero entra porque a linha existe em `stock_balances` (produto que
     * já movimentou naquele local), e é exatamente o tipo de combinação em
     * que a contagem física pode encontrar sobra que o sistema não sabe que
     * existe. Combinação que nunca teve movimento no local não tem linha de
     * saldo e não entra: não há o que contar contra ela.
     */
    public function abrir(StockLocation $local, User $usuario): Inventory
    {
        $empresa = $this->empresaDo($local);

        return DB::transaction(function () use ($local, $usuario, $empresa): Inventory {
            $inventario = new Inventory([
                'stock_location_id' => $local->getKey(),
                'situacao' => Inventory::SITUACAO_ABERTO,
                'aberto_por' => $usuario->getKey(),
                'aberto_em' => now(),
            ]);

            // company_id não é atributo de atribuição em massa em nenhum
            // model de domínio: vem do local, gravado direto, mesmo critério
            // de StockService::registrarMovimento().
            $inventario->company_id = $empresa;
            $inventario->save();

            $saldos = StockBalance::query()
                ->where('company_id', $empresa)
                ->where('stock_location_id', $local->getKey())
                ->get();

            foreach ($saldos as $saldo) {
                $item = new InventoryItem([
                    'inventory_id' => $inventario->getKey(),
                    'product_id' => $saldo->product_id,
                    'product_batch_id' => $saldo->product_batch_id,
                    'saldo_sistema' => $this->normalizar((float) $saldo->quantidade),
                ]);

                $item->company_id = $empresa;
                $item->save();
            }

            return $inventario;
        });
    }

    // -----------------------------------------------------------------
    // Contagem
    // -----------------------------------------------------------------

    /**
     * Grava o que a contagem física encontrou e calcula a diferença contra o
     * saldo congelado na abertura.
     *
     * Justificativa é obrigatória sempre que houver diferença: "sobrou 3" sem
     * explicação é o mesmo que não ter contado. Contagem que confirma o saldo
     * não exige nada além do número.
     *
     * Pode ser chamado mais de uma vez para o mesmo item enquanto o
     * inventário estiver aberto (recontagem antes do fechamento); depois de
     * finalizado, é sempre recusado.
     *
     * @throws InvalidArgumentException
     */
    public function contar(InventoryItem $item, float $contado, ?string $justificativa = null): InventoryItem
    {
        $this->garantirInventarioAberto($this->inventarioDoItem($item));

        if ($contado < 0) {
            throw new InvalidArgumentException('A quantidade contada não pode ser negativa.');
        }

        $contado = $this->normalizar($contado);
        $diferenca = $this->normalizar($contado - (float) $item->saldo_sistema);

        if ($diferenca !== 0.0 && trim((string) $justificativa) === '') {
            throw new InvalidArgumentException(
                'Toda diferença encontrada na contagem exige justificativa registrada.'
            );
        }

        $item->saldo_contado = $contado;
        $item->diferenca = $diferenca;
        $item->justificativa = $justificativa;
        $item->save();

        return $item;
    }

    // -----------------------------------------------------------------
    // Fechamento
    // -----------------------------------------------------------------

    /**
     * Fecha a contagem: recusa se houver item não contado ou item com
     * diferença sem justificativa, e gera um movimento de `ajuste` por item
     * divergente.
     *
     * O ajuste em si é sempre calculado contra o saldo atual do produto e
     * lote no local, não contra `saldo_sistema` congelado: é
     * `StockService::ajustar()` quem decide o tamanho do movimento, com
     * `lockForUpdate`, e é assim que um movimento acontecido durante a
     * contagem entra na conta em vez de ser silenciosamente perdido ou
     * duplicado.
     *
     * @throws InvalidArgumentException
     */
    public function finalizar(Inventory $inventario, User $usuario): Inventory
    {
        $this->garantirInventarioAberto($inventario);

        $itens = $this->itensDoInventario($inventario);

        $naoContado = $itens->first(fn (InventoryItem $item): bool => ! $item->foiContado());

        if ($naoContado !== null) {
            throw new InvalidArgumentException(
                'Existe item não contado neste inventário. Conte todos os itens antes de finalizar.'
            );
        }

        $semJustificativa = $itens->first(
            fn (InventoryItem $item): bool => $this->normalizar((float) $item->diferenca) !== 0.0
                && trim((string) $item->justificativa) === ''
        );

        if ($semJustificativa !== null) {
            throw new InvalidArgumentException(
                'Existe item com diferença sem justificativa neste inventário.'
            );
        }

        return DB::transaction(function () use ($inventario, $itens, $usuario): Inventory {
            foreach ($itens as $item) {
                $diferenca = $this->normalizar((float) $item->diferenca);

                // Contagem que confirmou o saldo congelado não gera ajuste:
                // nada foi apontado como divergente para justificar um
                // movimento.
                if ($diferenca === 0.0) {
                    continue;
                }

                $this->stockService->ajustar(
                    produto: $item->product,
                    local: $inventario->stockLocation,
                    quantidadeContada: (float) $item->saldo_contado,
                    usuario: $usuario,
                    motivo: (string) $item->justificativa,
                    lote: $item->product_batch_id !== null ? $item->productBatch : null,
                    inventoryId: $inventario->getKey()
                );
            }

            $inventario->situacao = Inventory::SITUACAO_FINALIZADO;
            $inventario->finalizado_por = $usuario->getKey();
            $inventario->finalizado_em = now();
            $inventario->save();

            return $inventario;
        });
    }

    // -----------------------------------------------------------------
    // Cancelamento
    // -----------------------------------------------------------------

    /**
     * Descarta a contagem sem tocar no saldo: nenhum ajuste é gerado e nenhum
     * movimento nasce.
     *
     * É a saída para a contagem interrompida (o depósito precisou voltar a
     * operar, o local foi contado errado desde o começo, a rodada foi aberta
     * por engano). O cabeçalho fica no histórico com a situação `cancelado`,
     * porque apagar a contagem esconderia que ela existiu, e quem contou o quê
     * é justamente o que se pergunta depois.
     *
     * `finalizado_por` e `finalizado_em` guardam quem encerrou e quando,
     * mesmas colunas do fechamento: são o par de encerramento do cabeçalho, e
     * a diferença entre um caso e outro está em `situacao`, não em quem
     * assinou.
     *
     * Recusa qualquer inventário que não esteja aberto, no mesmo critério de
     * `contar()` e `finalizar()`: contagem já encerrada é imutável, para os
     * dois lados.
     *
     * @throws InvalidArgumentException
     */
    public function cancelar(Inventory $inventario, User $usuario): Inventory
    {
        $this->garantirInventarioAberto($inventario);

        $inventario->situacao = Inventory::SITUACAO_CANCELADO;
        $inventario->finalizado_por = $usuario->getKey();
        $inventario->finalizado_em = now();
        $inventario->save();

        return $inventario;
    }

    // -----------------------------------------------------------------
    // Guardas e utilitários
    // -----------------------------------------------------------------

    /**
     * O inventário do item, sem depender de lazy loading já ter acontecido
     * antes desta chamada.
     */
    private function inventarioDoItem(InventoryItem $item): ?Inventory
    {
        return $item->relationLoaded('inventory') ? $item->inventory : $item->inventory()->first();
    }

    /**
     * Itens do inventário, escopados explicitamente por empresa: mesmo
     * critério defensivo do `StockService`, que não confia só no escopo global
     * porque também roda fora de contexto de requisição.
     *
     * @return Collection<int, InventoryItem>
     */
    private function itensDoInventario(Inventory $inventario): Collection
    {
        return InventoryItem::query()
            ->where('company_id', $inventario->company_id)
            ->where('inventory_id', $inventario->getKey())
            ->get();
    }

    /**
     * Recusa qualquer operação em inventário que não esteja aberto: contagem
     * errada se corrige com um inventário novo, nunca alterando um já
     * fechado.
     *
     * @throws InvalidArgumentException
     */
    private function garantirInventarioAberto(?Inventory $inventario): void
    {
        if ($inventario === null || ! $inventario->estaAberto()) {
            throw new InvalidArgumentException(
                'Este inventário não está aberto. Contagem e alteração só valem enquanto o inventário estiver em aberto.'
            );
        }
    }

    private function empresaDo(StockLocation $local): int
    {
        $empresa = $local->company_id;

        if ($empresa === null) {
            throw new InvalidArgumentException(
                'O local de estoque informado não tem empresa vinculada e não pode abrir inventário.'
            );
        }

        return (int) $empresa;
    }

    /**
     * Arredonda para as 4 casas das colunas de quantidade, mesmo critério do
     * `StockService`: sem isto, a sobra binária de uma soma de float faria uma
     * diferença de zero aparecer como 0,0000000001 e nunca fechar.
     */
    private function normalizar(float $valor): float
    {
        return round($valor, self::CASAS_DECIMAIS);
    }
}
