<?php

namespace Tests\Feature;

use App\Models\ActiveIngredient;
use App\Models\Antidote;
use App\Models\ChemicalGroup;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\StockService;
use App\Services\WorkOrderService;
use App\Services\WorkOrderStockService;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Database\Factories\WorkOrderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 17.4 do Plano 17: a baixa de estoque pelo que o técnico registrou em
 * campo e o custo real de produto da visita.
 *
 * O que este arquivo prende:
 *
 * - **A baixa sai do lote certo, no local certo.** Certo é o lote que vence
 *   antes (FEFO) e o local é o estoque do técnico da OS, porque é lá que o
 *   produto está na hora da aplicação.
 * - **O custo é congelado no momento da baixa.** Recomprar o lote mais caro
 *   amanhã não pode mudar o custo da OS de ontem: se mudasse, o relatório de
 *   margem de um mês já fechado mudaria sozinho.
 * - **Saldo insuficiente vira pendência, nunca bloqueio.** O serviço já
 *   aconteceu no mundo real, e travar o fechamento por saldo desatualizado
 *   pararia a operação.
 * - **Movimento nunca é apagado.** Refazer a baixa estorna com `ajuste`, e o
 *   razão continua contando a história inteira, que é o que vale perante
 *   fiscalização.
 * - **Produto sem `controla_estoque` não movimenta nada**, que é o que permite
 *   ligar o controle produto por produto.
 */
class WorkOrderStockServiceTest extends TestCase
{
    use RefreshDatabase;

    private WorkOrderStockService $servico;

    private StockService $estoque;

    private WorkOrderService $ordens;

    private Company $empresa;

    private User $usuarioDoTecnico;

    private Technician $tecnico;

    private Product $produto;

    private StockLocation $van;

    private StockLocation $deposito;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->servico = app(WorkOrderStockService::class);
        $this->estoque = app(StockService::class);
        $this->ordens = app(WorkOrderService::class);
        $this->empresa = Company::query()->firstOrFail();

        [
            $this->usuarioDoTecnico,
            $this->tecnico,
            $this->produto,
            $this->van,
            $this->deposito,
        ] = TenantAtual::comTenant($this->empresa->id, function (): array {
            $usuario = User::factory()->create(['name' => 'João Técnico', 'is_active' => true]);

            $tecnico = Technician::create([
                'name' => 'João Técnico',
                'email' => 'joao@dedetizadora.test',
                'phone' => '11999990000',
                'is_active' => true,
                'user_id' => $usuario->id,
            ]);

            $produto = $this->criarProduto('Cupinicida Concentrado', controlaEstoque: true);

            $van = StockLocation::create([
                'nome' => 'Van do João',
                'tipo' => 'tecnico',
                'technician_id' => $tecnico->id,
            ]);

            $deposito = StockLocation::create(['nome' => 'Depósito Central', 'tipo' => 'deposito']);

            return [$usuario, $tecnico, $produto, $van, $deposito];
        });

        TenantAtual::limpar();
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Baixa na conclusão
    // -----------------------------------------------------------------

    public function test_concluir_a_os_baixa_o_lote_de_validade_mais_proxima_no_local_do_tecnico(): void
    {
        // Cadastrados fora da ordem de validade: por ordem de cadastro sairia o
        // L-LONGE, e é justamente isso que o FEFO não pode fazer.
        $longe = $this->criarLote('L-LONGE', $this->emDias(180), 20.0);
        $perto = $this->criarLote('L-PERTO', $this->emDias(20), 12.5);

        $this->darEntrada($longe, 10, $this->van);
        $this->darEntrada($perto, 10, $this->van);

        // Saldo também no depósito, para provar que a baixa não sai de lá
        // quando o técnico tem local próprio.
        $this->darEntrada($perto, 30, $this->deposito);

        $ordem = $this->criarOrdemComProduto(4);

        TenantAtual::comTenant($this->empresa->id, fn () => $this->ordens->markAsCompleted($ordem));

        $ordem->refresh();
        $this->assertSame('completed', $ordem->status);

        $movimentos = $this->movimentosDaOrdem($ordem);

        $this->assertCount(1, $movimentos);
        $this->assertSame(StockService::TIPO_SAIDA, $movimentos[0]->tipo);
        $this->assertSame($perto->id, (int) $movimentos[0]->product_batch_id);
        $this->assertSame($this->van->id, (int) $movimentos[0]->stock_location_id);
        $this->assertSame(4.0, (float) $movimentos[0]->quantidade);
        $this->assertSame($this->usuarioDoTecnico->id, (int) $movimentos[0]->user_id);

        // Saldo cai só no lote e no local que foram usados.
        $this->assertSame(6.0, $this->saldo($perto, $this->van));
        $this->assertSame(10.0, $this->saldo($longe, $this->van));
        $this->assertSame(30.0, $this->saldo($perto, $this->deposito));
    }

    /**
     * A conclusão pela tela de edição do painel passa por
     * `WorkOrderService::updateWorkOrder()`, nunca por `markAsCompleted()`: é
     * o formulário de edição que marca `status = completed` e submete pelo
     * `update()` padrão. Sem o gancho em `updateWorkOrder()`, uma OS concluída
     * pelo painel nunca dava baixa, só a concluída pelo aplicativo do técnico.
     */
    public function test_concluir_a_os_pelo_painel_tambem_baixa_o_estoque(): void
    {
        $lote = $this->criarLote('L-PAINEL', $this->emDias(90), 12.5);
        $this->darEntrada($lote, 10, $this->van);

        $ordem = $this->criarOrdemComProduto(4, ['status' => 'in_progress']);

        TenantAtual::comTenant($this->empresa->id, function () use ($ordem): void {
            $this->ordens->updateWorkOrder($ordem, [
                'status' => 'completed',
                'end_time' => now(),
            ]);
        });

        $movimentos = $this->movimentosDaOrdem($ordem);

        $this->assertCount(1, $movimentos);
        $this->assertSame(StockService::TIPO_SAIDA, $movimentos[0]->tipo);
        $this->assertSame($lote->id, (int) $movimentos[0]->product_batch_id);
        $this->assertSame(6.0, $this->saldo($lote, $this->van));

        $pivot = $this->registroDoProduto($ordem);
        $this->assertSame($lote->id, (int) $pivot->product_batch_id);
        $this->assertSame(12.5, (float) $pivot->custo_unitario_aplicado);
    }

    /**
     * Reeditar pelo painel uma OS já concluída, corrigindo a quantidade
     * aplicada, refaz a baixa: estorna a anterior e sai de novo pela
     * quantidade nova. A pendência de refazer não é exclusiva do aplicativo
     * do técnico.
     */
    public function test_corrigir_quantidade_pelo_painel_em_os_ja_concluida_refaz_a_baixa(): void
    {
        $lote = $this->criarLote('L-CORRECAO', $this->emDias(90), 10.0);
        $this->darEntrada($lote, 10, $this->van);

        $ordem = $this->criarOrdemComProduto(4, ['status' => 'in_progress']);

        TenantAtual::comTenant($this->empresa->id, function () use ($ordem): void {
            $this->ordens->updateWorkOrder($ordem, ['status' => 'completed', 'end_time' => now()]);
        });

        $this->assertSame(6.0, $this->saldo($lote, $this->van));

        TenantAtual::comTenant($this->empresa->id, function () use ($ordem): void {
            $this->ordens->updateWorkOrder($ordem, [
                'status' => 'completed',
                'end_time' => now(),
                'products' => [['id' => $this->produto->id, 'quantity' => 7, 'unit' => 'un']],
            ]);
        });

        // 10 de entrada menos 7 aplicados de verdade, depois da correção.
        $this->assertSame(3.0, $this->saldo($lote, $this->van));

        // `movimentosDaOrdem()` filtra por `work_order_id`, e o estorno é um
        // `ajuste` sem esse vínculo: `StockService::ajustar()` não recebe
        // ordem de serviço (só `entrada()`/`saida()` recebem), então o
        // estorno só é reconhecível pelo prefixo do motivo, não pelo
        // `work_order_id`. Por isso aqui são 2 saídas ligadas à OS, e o
        // estorno é conferido à parte, por `tipo` e `motivo`.
        $movimentos = $this->movimentosDaOrdem($ordem);
        $this->assertCount(2, $movimentos);
        $this->assertSame(4.0, (float) $movimentos[0]->quantidade);
        $this->assertSame(7.0, (float) $movimentos[1]->quantidade);

        $estorno = StockMovement::query()
            ->where('product_id', $this->produto->id)
            ->where('tipo', StockService::TIPO_AJUSTE)
            ->where('motivo', 'like', "Estorno da baixa de estoque da OS #{$ordem->id}:%")
            ->first();

        $this->assertNotNull($estorno, 'O estorno da baixa anterior não foi encontrado no razão.');
        $this->assertSame(4.0, (float) $estorno->quantidade);
        $this->assertSame(10.0, (float) $estorno->saldo_apos);
    }

    public function test_lote_e_custo_ficam_gravados_no_registro_do_produto_aplicado(): void
    {
        $lote = $this->criarLote('L-001', $this->emDias(90), 12.5);
        $this->darEntrada($lote, 10, $this->van);

        $ordem = $this->criarOrdemComProduto(4);

        $resultado = $this->baixar($ordem);

        $pivot = $this->registroDoProduto($ordem);

        $this->assertSame($lote->id, (int) $pivot->product_batch_id);
        $this->assertSame(12.5, (float) $pivot->custo_unitario_aplicado);
        $this->assertNull($pivot->quantidade_pendente);

        $this->assertSame([], $resultado['pendencias']);
        $this->assertSame(50.0, $resultado['custo_total']);
        $this->assertSame(50.0, $this->servico->custoDeProdutos($ordem));
    }

    public function test_quantidade_dividida_entre_dois_lotes_congela_o_custo_medio_ponderado(): void
    {
        $perto = $this->criarLote('L-PERTO', $this->emDias(10), 10.0);
        $longe = $this->criarLote('L-LONGE', $this->emDias(100), 20.0);

        $this->darEntrada($perto, 4, $this->van);
        $this->darEntrada($longe, 10, $this->van);

        // 4 do lote que vence antes e 2 do seguinte: (4 x 10 + 2 x 20) / 6.
        $ordem = $this->criarOrdemComProduto(6);

        $resultado = $this->baixar($ordem);

        $this->assertCount(2, $resultado['movimentos']);

        $pivot = $this->registroDoProduto($ordem);

        // O lote gravado é o de maior parcela; o detalhe lote a lote fica no
        // razão, que é a fonte da rastreabilidade.
        $this->assertSame($perto->id, (int) $pivot->product_batch_id);
        $this->assertSame(13.3333, (float) $pivot->custo_unitario_aplicado);

        $this->assertSame(0.0, $this->saldo($perto, $this->van));
        $this->assertSame(8.0, $this->saldo($longe, $this->van));

        // 6 x 13,3333 = 79,9998, que arredondado em dinheiro fecha nos R$ 80,00
        // realmente gastos (4 x 10 + 2 x 20).
        $this->assertSame(80.0, $this->servico->custoDeProdutos($ordem));
    }

    public function test_sem_local_proprio_do_tecnico_a_baixa_cai_no_deposito_padrao(): void
    {
        $lote = $this->criarLote('L-001', $this->emDias(60), 9.0);
        $this->darEntrada($lote, 10, $this->deposito);

        // Técnico sem van cadastrada: o produto só pode ter saído do depósito.
        $outroTecnico = TenantAtual::comTenant($this->empresa->id, fn (): Technician => Technician::create([
            'name' => 'Maria Técnica',
            'email' => 'maria@dedetizadora.test',
            'phone' => '11988880000',
            'is_active' => true,
            'user_id' => User::factory()->create(['name' => 'Maria Técnica', 'is_active' => true])->id,
        ]));

        $ordem = $this->criarOrdemComProduto(3, ['technician_id' => $outroTecnico->id]);

        $this->baixar($ordem);

        $movimentos = $this->movimentosDaOrdem($ordem);

        $this->assertCount(1, $movimentos);
        $this->assertSame($this->deposito->id, (int) $movimentos[0]->stock_location_id);
        $this->assertSame(7.0, $this->saldo($lote, $this->deposito));
    }

    // -----------------------------------------------------------------
    // Custo congelado
    // -----------------------------------------------------------------

    public function test_alterar_o_custo_do_lote_depois_nao_muda_o_custo_da_os(): void
    {
        $lote = $this->criarLote('L-001', $this->emDias(90), 12.5);
        $this->darEntrada($lote, 10, $this->van);

        $ordem = $this->criarOrdemComProduto(4);
        $this->baixar($ordem);

        $this->assertSame(50.0, $this->servico->custoDeProdutos($ordem));

        // Compra nova do mesmo lote, mais cara. O custo do lote muda; o da OS
        // de ontem, não.
        TenantAtual::comTenant($this->empresa->id, function () use ($lote): void {
            $lote->update(['custo_unitario' => 40.0]);
        });

        $this->assertSame(40.0, (float) $lote->fresh()->custo_unitario);
        $this->assertSame(12.5, (float) $this->registroDoProduto($ordem)->custo_unitario_aplicado);
        $this->assertSame(50.0, $this->servico->custoDeProdutos($ordem));
    }

    // -----------------------------------------------------------------
    // Pendência
    // -----------------------------------------------------------------

    public function test_saldo_insuficiente_gera_pendencia_e_nao_impede_a_conclusao(): void
    {
        $lote = $this->criarLote('L-001', $this->emDias(90), 12.5);
        $this->darEntrada($lote, 4, $this->van);

        // O técnico aplicou 10; o sistema achava que havia 4.
        $ordem = $this->criarOrdemComProduto(10);

        TenantAtual::comTenant($this->empresa->id, fn () => $this->ordens->markAsCompleted($ordem));

        $ordem->refresh();

        // O serviço já aconteceu: a conclusão não pode ser travada por causa
        // do saldo.
        $this->assertSame('completed', $ordem->status);

        $pivot = $this->registroDoProduto($ordem);

        $this->assertSame(10.0, (float) $pivot->quantidade_pendente);
        $this->assertNull($pivot->product_batch_id);
        $this->assertNull($pivot->custo_unitario_aplicado);

        // Nada foi movimentado: baixa de item é tudo ou nada, senão a OS diria
        // que aplicou 10 enquanto o razão mostra 4.
        $this->assertCount(0, $this->movimentosDaOrdem($ordem));
        $this->assertSame(4.0, $this->saldo($lote, $this->van));

        $pendencias = $this->servico->pendenciasDaOs($ordem);

        $this->assertCount(1, $pendencias);
        $this->assertSame($this->produto->id, $pendencias[0]['product_id']);
        $this->assertSame(10.0, $pendencias[0]['quantidade_pendente']);
    }

    public function test_pendencia_e_resolvida_quando_o_estoque_chega_e_a_baixa_roda_de_novo(): void
    {
        $lote = $this->criarLote('L-001', $this->emDias(90), 12.5);
        $this->darEntrada($lote, 4, $this->van);

        $ordem = $this->criarOrdemComProduto(10);
        $resultado = $this->baixar($ordem);

        $this->assertCount(1, $resultado['pendencias']);
        $this->assertSame(10.0, $resultado['pendencias'][0]['quantidade_pendente']);
        $this->assertSame(4.0, $resultado['pendencias'][0]['saldo_disponivel']);

        // O escritório lança a compra que faltava e a baixa é tentada de novo.
        $this->darEntrada($lote, 20, $this->van);

        $segundaTentativa = $this->baixar($ordem);

        $this->assertSame([], $segundaTentativa['pendencias']);
        $this->assertCount(1, $segundaTentativa['movimentos']);

        $pivot = $this->registroDoProduto($ordem);

        $this->assertNull($pivot->quantidade_pendente);
        $this->assertSame($lote->id, (int) $pivot->product_batch_id);
        $this->assertSame(14.0, $this->saldo($lote, $this->van));
    }

    // -----------------------------------------------------------------
    // Estorno
    // -----------------------------------------------------------------

    public function test_refazer_a_baixa_estorna_com_ajuste_sem_apagar_movimento(): void
    {
        $lote = $this->criarLote('L-001', $this->emDias(90), 12.5);
        $this->darEntrada($lote, 20, $this->van);

        $ordem = $this->criarOrdemComProduto(4);
        $this->baixar($ordem);

        $this->assertSame(16.0, $this->saldo($lote, $this->van));

        // OS reaberta: o escritório corrige a quantidade aplicada para 6.
        $this->definirQuantidade($ordem, 6);

        $resultado = $this->baixar($ordem);

        $this->assertCount(1, $resultado['estornos']);
        $this->assertCount(1, $resultado['movimentos']);

        $movimentos = StockMovement::query()
            ->where('product_id', $this->produto->id)
            ->orderBy('id')
            ->get();

        // Entrada, primeira saída, ajuste de estorno e saída nova: nenhuma
        // linha some, e é a sequência inteira que vale perante fiscalização.
        $this->assertSame(
            [StockService::TIPO_ENTRADA, StockService::TIPO_SAIDA, StockService::TIPO_AJUSTE, StockService::TIPO_SAIDA],
            $movimentos->pluck('tipo')->all()
        );

        $estorno = $movimentos[2];

        $this->assertSame(4.0, (float) $estorno->quantidade);
        $this->assertSame(20.0, (float) $estorno->saldo_apos);
        $this->assertStringContainsString('Estorno da baixa de estoque da OS', (string) $estorno->motivo);

        // O saldo final reflete só a baixa que vale: 20 menos 6.
        $this->assertSame(14.0, $this->saldo($lote, $this->van));
        $this->assertSame(6.0, (float) $movimentos[3]->quantidade);
        $this->assertSame(75.0, $this->servico->custoDeProdutos($ordem));
    }

    public function test_baixa_repetida_sem_mudanca_nao_gera_movimento_novo(): void
    {
        $lote = $this->criarLote('L-001', $this->emDias(90), 12.5);
        $this->darEntrada($lote, 20, $this->van);

        $ordem = $this->criarOrdemComProduto(4);

        $this->baixar($ordem);
        $segunda = $this->baixar($ordem);

        // Nada mudou desde a última baixa: movimento de estorno seguido de
        // movimento igual só poluiria o extrato.
        $this->assertSame([], $segunda['movimentos']);
        $this->assertSame([], $segunda['estornos']);
        $this->assertCount(1, $this->movimentosDaOrdem($ordem));
        $this->assertSame(16.0, $this->saldo($lote, $this->van));
    }

    public function test_produto_removido_da_os_tem_a_baixa_estornada(): void
    {
        $lote = $this->criarLote('L-001', $this->emDias(90), 12.5);
        $this->darEntrada($lote, 20, $this->van);

        $ordem = $this->criarOrdemComProduto(4);
        $this->baixar($ordem);

        // O escritório descobre que o produto não foi aplicado e zera a
        // quantidade.
        $this->definirQuantidade($ordem, 0);

        $resultado = $this->baixar($ordem);

        $this->assertCount(1, $resultado['estornos']);
        $this->assertSame([], $resultado['movimentos']);
        $this->assertSame(20.0, $this->saldo($lote, $this->van));

        $pivot = $this->registroDoProduto($ordem);

        $this->assertNull($pivot->product_batch_id);
        $this->assertNull($pivot->custo_unitario_aplicado);
        $this->assertSame(0.0, $this->servico->custoDeProdutos($ordem));
    }

    // -----------------------------------------------------------------
    // Produto sem controle de estoque
    // -----------------------------------------------------------------

    public function test_produto_sem_controle_de_estoque_nao_gera_movimento(): void
    {
        $semControle = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): Product => $this->criarProduto('Gel Baraticida', controlaEstoque: false)
        );

        $ordem = $this->criarOrdemComProduto(4);

        TenantAtual::comTenant($this->empresa->id, function () use ($ordem, $semControle): void {
            $ordem->products()->attach($semControle->id, ['quantity' => 7, 'unit' => 'un']);
        });

        // O produto controlado nem tem lote: o que este teste cobra é que o
        // não controlado passa direto, sem movimento e sem pendência.
        $resultado = $this->baixar($ordem);

        $this->assertSame(
            0,
            StockMovement::query()->where('product_id', $semControle->id)->count()
        );

        $pivot = $this->registroDoProduto($ordem, $semControle);

        $this->assertNull($pivot->product_batch_id);
        $this->assertNull($pivot->custo_unitario_aplicado);
        $this->assertNull($pivot->quantidade_pendente);

        $this->assertSame(
            [$this->produto->id],
            array_map(fn (array $pendencia): int => $pendencia['product_id'], $resultado['pendencias'])
        );
    }

    // -----------------------------------------------------------------
    // Custo de produtos
    // -----------------------------------------------------------------

    public function test_custo_de_produtos_soma_os_itens_e_ignora_o_que_nao_tem_custo(): void
    {
        $segundoProduto = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): Product => $this->criarProduto('Raticida Bloco', controlaEstoque: true)
        );

        $loteA = $this->criarLote('L-A', $this->emDias(90), 12.5);
        $loteB = $this->criarLote('L-B', $this->emDias(60), 3.75, $segundoProduto);

        $this->darEntrada($loteA, 20, $this->van);
        $this->darEntrada($loteB, 20, $this->van, $segundoProduto);

        $ordem = $this->criarOrdemComProduto(4);

        TenantAtual::comTenant($this->empresa->id, function () use ($ordem, $segundoProduto): void {
            $ordem->products()->attach($segundoProduto->id, ['quantity' => 8, 'unit' => 'un']);
        });

        $this->baixar($ordem);

        // 4 x 12,50 + 8 x 3,75 = 80,00.
        $this->assertSame(80.0, $this->servico->custoDeProdutos($ordem));

        // Item sem custo congelado entra como zero: inventar valor daria margem
        // falsa.
        $semControle = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): Product => $this->criarProduto('Gel Baraticida', controlaEstoque: false)
        );

        TenantAtual::comTenant($this->empresa->id, function () use ($ordem, $semControle): void {
            $ordem->products()->attach($semControle->id, ['quantity' => 5, 'unit' => 'un']);
        });

        $this->assertSame(80.0, $this->servico->custoDeProdutos($ordem));
    }

    // -----------------------------------------------------------------
    // Multiempresa
    // -----------------------------------------------------------------

    public function test_baixa_nao_alcanca_estoque_de_outra_empresa(): void
    {
        $lote = $this->criarLote('L-001', $this->emDias(90), 12.5);
        $this->darEntrada($lote, 20, $this->van);

        $outraEmpresa = Company::create([
            'name' => 'Dedetizadora Concorrente',
            'cnpj' => '22.222.222/0001-22',
            'email' => 'contato@concorrente.test',
        ]);

        $ordem = $this->criarOrdemComProduto(4);

        $this->baixar($ordem);

        // Todo movimento nasce na empresa do produto, nunca na de quem estava
        // no contexto.
        $this->assertSame(
            0,
            StockMovement::query()->where('company_id', $outraEmpresa->id)->count()
        );
        $this->assertSame(
            1,
            StockMovement::query()
                ->where('company_id', $this->empresa->id)
                ->where('work_order_id', $ordem->id)
                ->count()
        );
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * @return array{movimentos: array<int, StockMovement>, estornos: array<int, StockMovement>, pendencias: array<int, array<string, mixed>>, custo_total: float}
     */
    private function baixar(WorkOrder $ordem): array
    {
        return TenantAtual::comTenant(
            $this->empresa->id,
            fn (): array => $this->servico->baixarProdutosDaOs($ordem)
        );
    }

    private function criarOrdemComProduto(float $quantidade, array $atributos = []): WorkOrder
    {
        return TenantAtual::comTenant($this->empresa->id, function () use ($quantidade, $atributos): WorkOrder {
            $ordem = WorkOrderFactory::new()->create(array_merge([
                'technician_id' => $this->tecnico->id,
                'status' => 'in_progress',
                // Instante da execução em campo: é ele que decide o dia da
                // aplicação para a validade do lote.
                'start_time' => now()->subHour(),
                'end_time' => now(),
            ], $atributos));

            $ordem->products()->attach($this->produto->id, ['quantity' => $quantidade, 'unit' => 'un']);

            return $ordem;
        });
    }

    private function definirQuantidade(WorkOrder $ordem, float $quantidade): void
    {
        TenantAtual::comTenant($this->empresa->id, function () use ($ordem, $quantidade): void {
            $ordem->products()->updateExistingPivot($this->produto->id, ['quantity' => $quantidade]);
        });
    }

    private function registroDoProduto(WorkOrder $ordem, ?Product $produto = null): object
    {
        $produto ??= $this->produto;

        return TenantAtual::comTenant(
            $this->empresa->id,
            fn (): object => $ordem->products()->where('products.id', $produto->id)->firstOrFail()->pivot
        );
    }

    /**
     * @return array<int, StockMovement>
     */
    private function movimentosDaOrdem(WorkOrder $ordem): array
    {
        return StockMovement::query()
            ->where('work_order_id', $ordem->id)
            ->orderBy('id')
            ->get()
            ->all();
    }

    private function saldo(ProductBatch $lote, StockLocation $local): float
    {
        return $this->estoque->saldo($lote->product, $local, $lote);
    }

    private function darEntrada(
        ProductBatch $lote,
        float $quantidade,
        StockLocation $local,
        ?Product $produto = null
    ): void {
        TenantAtual::comTenant($this->empresa->id, function () use ($lote, $quantidade, $local, $produto): void {
            $this->estoque->entrada(
                $produto ?? $this->produto,
                $local,
                $quantidade,
                $this->usuarioDoTecnico,
                $lote,
                'Compra recebida'
            );
        });
    }

    private function criarLote(
        string $codigo,
        string $validade,
        float $custo,
        ?Product $produto = null
    ): ProductBatch {
        return TenantAtual::comTenant(
            $this->empresa->id,
            fn (): ProductBatch => ProductBatch::create([
                'product_id' => ($produto ?? $this->produto)->id,
                'lote' => $codigo,
                'validade' => $validade,
                'recebido_em' => BusinessDate::hoje()->subDays(10)->toDateString(),
                'custo_unitario' => $custo,
            ])
        );
    }

    /**
     * Dia no fuso do negócio, deslocado de hoje. Negativo é passado.
     */
    private function emDias(int $dias): string
    {
        return BusinessDate::hoje()->addDays($dias)->toDateString();
    }

    private function criarProduto(string $nome, bool $controlaEstoque): Product
    {
        $sufixo = uniqid();

        return Product::create([
            'name' => $nome,
            'active_ingredient_id' => ActiveIngredient::create(['name' => 'Princípio '.$sufixo])->id,
            'chemical_group_id' => ChemicalGroup::create(['name' => 'Grupo '.$sufixo])->id,
            'antidote_id' => Antidote::create(['name' => 'Antídoto '.$sufixo])->id,
            'controla_estoque' => $controlaEstoque,
            'unidade' => 'un',
        ]);
    }
}
