<?php

namespace Tests\Feature;

use App\Models\ActiveIngredient;
use App\Models\Antidote;
use App\Models\ChemicalGroup;
use App\Models\Company;
use App\Models\Inventory;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\StockService;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Task 17.5 do Plano 17: inventário com ajuste justificado.
 *
 * O que este arquivo prende, e por que cada garantia importa:
 *
 * - Abrir fotografa o saldo do sistema, item a item, sem tocar em nada.
 * - Diferença sem justificativa nunca é gravada: "sobrou 3" sem explicação é
 *   o mesmo que não ter contado.
 * - Fechamento incompleto (item não contado) é recusado.
 * - O ajuste do fechamento é sempre contra o saldo atual, não contra o saldo
 *   congelado na abertura: a operação do local não para para a contagem
 *   acontecer, e um movimento no meio-tempo entra na conta.
 * - Inventário finalizado é imutável.
 * - O saldo depois do fechamento bate exatamente com o que foi contado.
 */
class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockService $estoque;

    private InventoryService $inventarios;

    private Company $empresa;

    private User $usuario;

    private Product $produto;

    private ProductBatch $lote;

    private StockLocation $deposito;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->estoque = app(StockService::class);
        $this->inventarios = app(InventoryService::class);
        $this->empresa = Company::query()->firstOrFail();

        [$this->usuario, $this->produto, $this->lote, $this->deposito] = TenantAtual::comTenant(
            $this->empresa->id,
            function (): array {
                $usuario = User::factory()->create(['name' => 'Estoquista', 'is_active' => true]);
                $produto = $this->criarProduto('Isca Parafinada');

                $lote = ProductBatch::create([
                    'product_id' => $produto->id,
                    'lote' => 'L-001',
                    'validade' => BusinessDate::hoje()->addYear()->toDateString(),
                    'recebido_em' => BusinessDate::hoje()->toDateString(),
                    'custo_unitario' => 12.5,
                ]);

                $deposito = StockLocation::create(['nome' => 'Depósito Central', 'tipo' => 'deposito']);

                return [$usuario, $produto, $lote, $deposito];
            }
        );

        TenantAtual::limpar();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Abertura
    // -----------------------------------------------------------------

    public function test_abrir_gera_um_item_por_produto_e_lote_com_saldo_no_local(): void
    {
        $this->estoque->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);

        $inventario = $this->inventarios->abrir($this->deposito, $this->usuario);

        $this->assertSame(Inventory::SITUACAO_ABERTO, $inventario->situacao);
        $this->assertSame($this->deposito->id, $inventario->stock_location_id);
        $this->assertSame($this->usuario->id, $inventario->aberto_por);
        $this->assertNotNull($inventario->aberto_em);
        $this->assertNull($inventario->finalizado_por);
        $this->assertNull($inventario->finalizado_em);
        $this->assertSame($this->empresa->id, (int) $inventario->company_id);

        $itens = InventoryItem::query()->where('inventory_id', $inventario->id)->get();

        $this->assertCount(1, $itens);
        $item = $itens->first();
        $this->assertSame($this->produto->id, $item->product_id);
        $this->assertSame($this->lote->id, $item->product_batch_id);
        $this->assertSame(10.0, (float) $item->saldo_sistema);
        $this->assertNull($item->saldo_contado);
        $this->assertNull($item->diferenca);
        $this->assertSame($this->empresa->id, (int) $item->company_id);
    }

    public function test_abrir_inclui_combinacao_com_saldo_zero_e_ignora_a_que_nunca_movimentou(): void
    {
        $segundoLote = TenantAtual::comTenant($this->empresa->id, fn (): ProductBatch => ProductBatch::create([
            'product_id' => $this->produto->id,
            'lote' => 'L-002',
            'validade' => BusinessDate::hoje()->addMonths(6)->toDateString(),
            'recebido_em' => BusinessDate::hoje()->toDateString(),
            'custo_unitario' => 13,
        ]));

        // Este lote zera o saldo, mas a linha em stock_balances continua
        // existindo: a contagem física pode achar sobra que o sistema não
        // sabe que existe.
        $this->estoque->entrada($this->produto, $this->deposito, 6, $this->usuario, $this->lote);
        $this->estoque->saida($this->produto, $this->deposito, 6, $this->usuario, $this->lote);

        // Este lote nunca movimentou neste local: não gera linha de saldo, e
        // por isso não pode virar item de inventário.
        $this->assertSame(0, $segundoLote->balances()->count());

        $inventario = $this->inventarios->abrir($this->deposito, $this->usuario);

        $itens = InventoryItem::query()->where('inventory_id', $inventario->id)->get();

        $this->assertCount(1, $itens);
        $this->assertSame($this->lote->id, $itens->first()->product_batch_id);
        $this->assertSame(0.0, (float) $itens->first()->saldo_sistema);
        $this->assertFalse($itens->contains('product_batch_id', $segundoLote->id));
    }

    // -----------------------------------------------------------------
    // Contagem
    // -----------------------------------------------------------------

    public function test_contar_com_diferenca_sem_justificativa_e_recusado(): void
    {
        $this->estoque->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);
        $inventario = $this->inventarios->abrir($this->deposito, $this->usuario);
        $item = $this->primeiroItem($inventario);

        try {
            $this->inventarios->contar($item, 8, null);
            $this->fail('Diferença sem justificativa deveria ter sido recusada.');
        } catch (InvalidArgumentException) {
            // esperado
        }

        try {
            $this->inventarios->contar($item, 8, '   ');
            $this->fail('Diferença com justificativa em branco deveria ter sido recusada.');
        } catch (InvalidArgumentException) {
            // esperado
        }

        $item->refresh();
        $this->assertNull($item->saldo_contado);
        $this->assertNull($item->diferenca);
    }

    public function test_contar_sem_diferenca_nao_exige_justificativa(): void
    {
        $this->estoque->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);
        $inventario = $this->inventarios->abrir($this->deposito, $this->usuario);
        $item = $this->primeiroItem($inventario);

        $contado = $this->inventarios->contar($item, 10, null);

        $this->assertSame(10.0, (float) $contado->saldo_contado);
        $this->assertSame(0.0, (float) $contado->diferenca);
    }

    public function test_contar_com_diferenca_e_justificativa_e_aceito(): void
    {
        $this->estoque->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);
        $inventario = $this->inventarios->abrir($this->deposito, $this->usuario);
        $item = $this->primeiroItem($inventario);

        $contado = $this->inventarios->contar($item, 8, 'Contagem física apontou falta de 2 unidades');

        $this->assertSame(8.0, (float) $contado->saldo_contado);
        $this->assertSame(-2.0, (float) $contado->diferenca);
        $this->assertSame('Contagem física apontou falta de 2 unidades', $contado->justificativa);
    }

    // -----------------------------------------------------------------
    // Fechamento: recusas
    // -----------------------------------------------------------------

    public function test_finalizar_com_item_nao_contado_e_recusado(): void
    {
        $this->estoque->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);
        $inventario = $this->inventarios->abrir($this->deposito, $this->usuario);

        try {
            $this->inventarios->finalizar($inventario, $this->usuario);
            $this->fail('Finalizar com item não contado deveria ter sido recusado.');
        } catch (InvalidArgumentException) {
            // esperado
        }

        $inventario->refresh();
        $this->assertSame(Inventory::SITUACAO_ABERTO, $inventario->situacao);
        $this->assertSame(0, StockMovement::query()->where('inventory_id', $inventario->id)->count());
    }

    // -----------------------------------------------------------------
    // Fechamento: gera o ajuste
    // -----------------------------------------------------------------

    public function test_finalizar_gera_um_movimento_de_ajuste_por_divergencia_com_o_motivo(): void
    {
        $this->estoque->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);
        $inventario = $this->inventarios->abrir($this->deposito, $this->usuario);
        $item = $this->primeiroItem($inventario);

        $this->inventarios->contar($item, 8, 'Contagem física apontou falta de 2 unidades');

        $finalizado = $this->inventarios->finalizar($inventario, $this->usuario);

        $this->assertSame(Inventory::SITUACAO_FINALIZADO, $finalizado->situacao);
        $this->assertSame($this->usuario->id, $finalizado->finalizado_por);
        $this->assertNotNull($finalizado->finalizado_em);

        $movimento = StockMovement::query()->where('inventory_id', $inventario->id)->sole();

        $this->assertSame('ajuste', $movimento->tipo);
        $this->assertSame(2.0, (float) $movimento->quantidade);
        $this->assertSame(8.0, (float) $movimento->saldo_apos);
        $this->assertSame('Contagem física apontou falta de 2 unidades', $movimento->motivo);
        $this->assertSame($this->usuario->id, (int) $movimento->user_id);

        $this->assertSame(8.0, $this->estoque->saldo($this->produto, $this->deposito, $this->lote));
    }

    public function test_finalizar_sem_divergencia_nao_gera_movimento_nenhum(): void
    {
        $this->estoque->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);
        $inventario = $this->inventarios->abrir($this->deposito, $this->usuario);
        $item = $this->primeiroItem($inventario);

        $this->inventarios->contar($item, 10, null);
        $this->inventarios->finalizar($inventario, $this->usuario);

        $this->assertSame(0, StockMovement::query()->where('inventory_id', $inventario->id)->count());
        $this->assertSame(10.0, $this->estoque->saldo($this->produto, $this->deposito, $this->lote));
    }

    // -----------------------------------------------------------------
    // Movimento durante o inventário
    // -----------------------------------------------------------------

    public function test_movimento_durante_o_inventario_e_considerado_no_fechamento(): void
    {
        $this->estoque->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);
        $inventario = $this->inventarios->abrir($this->deposito, $this->usuario);
        $item = $this->primeiroItem($inventario);

        // saldo_sistema do item continua congelado em 10, mas o local segue
        // operando: entra mais 5 depois da abertura, e o saldo real passa a
        // 15 antes do fechamento.
        $this->estoque->entrada($this->produto, $this->deposito, 5, $this->usuario, $this->lote, 'Compra recebida durante a contagem');
        $this->assertSame(15.0, $this->estoque->saldo($this->produto, $this->deposito, $this->lote));

        // A contagem física, feita sem saber do saldo congelado, encontra 12.
        // Contra o congelado (10) a diferença pareceria +2; contra o saldo
        // real no fechamento (15) a diferença é -3, e é esta que deve virar
        // movimento.
        $this->inventarios->contar($item, 12, 'Contagem física encontrou 12 unidades na prateleira');

        $item->refresh();
        $this->assertSame(2.0, (float) $item->diferenca, 'Diferença de exibição, calculada contra o saldo congelado.');

        $this->inventarios->finalizar($inventario, $this->usuario);

        $movimento = StockMovement::query()->where('inventory_id', $inventario->id)->sole();

        // O tamanho do ajuste reflete o saldo real (15 -> 12), não a
        // diferença de exibição calculada contra o congelado (10 -> 12).
        $this->assertSame(3.0, (float) $movimento->quantidade);
        $this->assertSame(12.0, (float) $movimento->saldo_apos);

        $this->assertSame(12.0, $this->estoque->saldo($this->produto, $this->deposito, $this->lote));
    }

    // -----------------------------------------------------------------
    // Imutabilidade
    // -----------------------------------------------------------------

    public function test_inventario_finalizado_nao_aceita_alteracao(): void
    {
        $this->estoque->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);
        $inventario = $this->inventarios->abrir($this->deposito, $this->usuario);
        $item = $this->primeiroItem($inventario);

        $this->inventarios->contar($item, 10, null);
        $this->inventarios->finalizar($inventario, $this->usuario);

        try {
            $this->inventarios->contar($item, 9, 'Tentativa de corrigir contagem depois de fechado');
            $this->fail('Contar em inventário finalizado deveria ter sido recusado.');
        } catch (InvalidArgumentException) {
            // esperado
        }

        try {
            $this->inventarios->finalizar($inventario, $this->usuario);
            $this->fail('Finalizar um inventário já finalizado deveria ter sido recusado.');
        } catch (InvalidArgumentException) {
            // esperado
        }

        $item->refresh();
        $this->assertSame(10.0, (float) $item->saldo_contado);
        $this->assertSame(10.0, $this->estoque->saldo($this->produto, $this->deposito, $this->lote));
    }

    // -----------------------------------------------------------------
    // Saldo final bate com o contado
    // -----------------------------------------------------------------

    public function test_saldo_apos_a_finalizacao_bate_com_o_contado(): void
    {
        $this->estoque->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);
        $inventario = $this->inventarios->abrir($this->deposito, $this->usuario);
        $item = $this->primeiroItem($inventario);

        // Sobra: a contagem física encontrou mais do que o sistema registrava.
        $this->inventarios->contar($item, 15, 'Contagem física encontrou sobra de 5 unidades');
        $this->inventarios->finalizar($inventario, $this->usuario);

        $this->assertSame(15.0, $this->estoque->saldo($this->produto, $this->deposito, $this->lote));

        $movimento = StockMovement::query()->where('inventory_id', $inventario->id)->sole();
        $this->assertSame(15.0, (float) $movimento->saldo_apos);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function primeiroItem(Inventory $inventario): InventoryItem
    {
        return InventoryItem::query()->where('inventory_id', $inventario->id)->firstOrFail();
    }

    private function criarProduto(string $nome): Product
    {
        $sufixo = uniqid();

        return Product::create([
            'name' => $nome,
            'active_ingredient_id' => ActiveIngredient::create(['name' => 'Princípio '.$sufixo])->id,
            'chemical_group_id' => ChemicalGroup::create(['name' => 'Grupo '.$sufixo])->id,
            'antidote_id' => Antidote::create(['name' => 'Antídoto '.$sufixo])->id,
            'controla_estoque' => true,
            'unidade' => 'un',
        ]);
    }
}
