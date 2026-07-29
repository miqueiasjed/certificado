<?php

namespace Tests\Feature;

use App\Console\Commands\SyncPermissions;
use App\Models\ActiveIngredient;
use App\Models\Address;
use App\Models\Antidote;
use App\Models\ChemicalGroup;
use App\Models\Client;
use App\Models\Company;
use App\Models\Inventory;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\StockService;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 17.7 do Plano 17: a camada HTTP do estoque.
 *
 * O comportamento interno já está coberto por `StockServiceTest` (movimento e
 * saldo na mesma transação), `BatchSelectionServiceTest` (FEFO e validade) e
 * `InventoryServiceTest` (contagem e ajuste). O que este arquivo prende é o que
 * só existe na borda: rota, permissão, módulo, FormRequest, a tradução da
 * recusa de negócio em 422 e o isolamento entre empresas nos endpoints que
 * recebem um registro pela URL.
 *
 * Os oito critérios de aceitação da task, um por bloco:
 *
 * 1. A lista de saldo filtra por produto, local e validade (e por mínimo).
 * 2. Entrada, transferência e descarte funcionam e geram movimento.
 * 3. Excluir lote com movimento devolve 422.
 * 4. O inventário percorre abrir, contar e finalizar.
 * 5. A rastreabilidade lista as OS que aplicaram o lote.
 * 6. Usuário sem `estoque-movimentar` não movimenta.
 * 7. Tenant sem o módulo vê a página de indisponível.
 * 8. Um tenant não vê nem manipula estoque do outro.
 *
 * A empresa fundadora (id 1) é semeada por `ModulesSeeder` no Plano Interno,
 * que libera todos os módulos: é ela que representa o tenant **com** o módulo
 * `estoque`. O tenant sem o módulo nasce sem plano nenhum, no bloco 7.
 */
class StockEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private User $administrador;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ModulesSeeder::class);

        $this->empresa = Company::query()->firstOrFail();
        $this->administrador = $this->criarUsuario('administrador');
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // 1. A lista de saldo filtra por produto, local e validade
    // -----------------------------------------------------------------

    public function test_a_lista_de_saldo_filtra_por_produto_local_e_validade(): void
    {
        $cenario = $this->cenarioDeSaldo();

        $completa = $this->actingAs($this->administrador)->getJson('/estoque');
        $completa->assertOk();

        $this->assertSame(
            [15.0, 8.0],
            $this->saldos($completa->json('produtos'), ['Isca Parafinada', 'Gel Inseticida'])
        );

        // Quebra por local e por lote na mesma linha do produto.
        $isca = $this->produtoNaLista($completa->json('produtos'), 'Isca Parafinada');
        $this->assertCount(1, $isca['locais']);
        $this->assertSame('Depósito Central', $isca['locais'][0]['nome']);
        $this->assertCount(2, $isca['lotes']);
        $this->assertTrue($isca['tem_lote_vencido']);
        $this->assertTrue($isca['abaixo_do_minimo']);

        // Filtro por produto.
        $porProduto = $this->actingAs($this->administrador)
            ->getJson('/estoque?product_id='.$cenario['gel']->id);
        $porProduto->assertOk();
        $this->assertSame(['Gel Inseticida'], $this->nomes($porProduto->json('produtos')));

        // Filtro por local: a van só tem o gel.
        $porLocal = $this->actingAs($this->administrador)
            ->getJson('/estoque?stock_location_id='.$cenario['van']->id);
        $porLocal->assertOk();
        $this->assertSame(['Gel Inseticida'], $this->nomes($porLocal->json('produtos')));

        // Filtro por validade: vencido e vencendo caem em produtos diferentes.
        $vencidos = $this->actingAs($this->administrador)->getJson('/estoque?validade=vencido');
        $vencidos->assertOk();
        $this->assertSame(['Isca Parafinada'], $this->nomes($vencidos->json('produtos')));

        $vencendo = $this->actingAs($this->administrador)->getJson('/estoque?validade=vencendo');
        $vencendo->assertOk();
        $this->assertSame(['Gel Inseticida'], $this->nomes($vencendo->json('produtos')));

        // Filtro de "abaixo do mínimo": só a isca tem mínimo configurado, e o
        // saldo dela não o alcança.
        $abaixo = $this->actingAs($this->administrador)->getJson('/estoque?abaixo_do_minimo=1');
        $abaixo->assertOk();
        $this->assertSame(['Isca Parafinada'], $this->nomes($abaixo->json('produtos')));

        // Indicadores do topo, contados antes dos filtros de situação.
        $this->assertSame(1, $completa->json('indicadores.abaixo_do_minimo'));
        $this->assertSame(1, $completa->json('indicadores.lotes_vencendo'));
        $this->assertSame(1, $completa->json('indicadores.lotes_vencidos'));
    }

    // -----------------------------------------------------------------
    // 2. Entrada, transferência e descarte funcionam e geram movimento
    // -----------------------------------------------------------------

    public function test_entrada_transferencia_e_descarte_geram_movimento(): void
    {
        $produto = $this->criarProduto('Isca Parafinada');
        $lote = $this->criarLote($produto, 'L-001', BusinessDate::hoje()->addYear()->toDateString());
        $deposito = $this->criarLocal('Depósito Central');
        $van = $this->criarLocal('Van do técnico', 'tecnico');

        $entrada = $this->actingAs($this->administrador)->postJson('/estoque/entrada', [
            'product_id' => $produto->id,
            'stock_location_id' => $deposito->id,
            'product_batch_id' => $lote->id,
            'quantidade' => 10,
            'motivo' => 'Compra recebida',
        ]);

        $entrada->assertOk();
        $this->assertSame(10.0, $this->saldo($produto, $deposito));
        $this->assertSame(1, $this->movimentos($produto, 'entrada'));

        $transferencia = $this->actingAs($this->administrador)->postJson('/estoque/transferencia', [
            'product_id' => $produto->id,
            'origem_id' => $deposito->id,
            'destino_id' => $van->id,
            'product_batch_id' => $lote->id,
            'quantidade' => 4,
        ]);

        $transferencia->assertOk();
        $this->assertSame(6.0, $this->saldo($produto, $deposito));
        $this->assertSame(4.0, $this->saldo($produto, $van));
        $this->assertSame(1, $this->movimentos($produto, 'transferencia_saida'));
        $this->assertSame(1, $this->movimentos($produto, 'transferencia_entrada'));

        $descarte = $this->actingAs($this->administrador)->postJson('/estoque/descarte', [
            'product_id' => $produto->id,
            'stock_location_id' => $van->id,
            'product_batch_id' => $lote->id,
            'quantidade' => 1,
            'motivo' => 'Frasco quebrado na van',
        ]);

        $descarte->assertOk();
        $this->assertSame(3.0, $this->saldo($produto, $van));
        $this->assertSame(1, $this->movimentos($produto, 'descarte'));
    }

    public function test_descarte_sem_motivo_e_recusado_pela_validacao(): void
    {
        $produto = $this->criarProduto('Isca Parafinada');
        $deposito = $this->criarLocal('Depósito Central');

        $resposta = $this->actingAs($this->administrador)->postJson('/estoque/descarte', [
            'product_id' => $produto->id,
            'stock_location_id' => $deposito->id,
            'quantidade' => 1,
        ]);

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('motivo');
    }

    public function test_saida_maior_que_o_saldo_devolve_422_com_a_mensagem_da_excecao(): void
    {
        $produto = $this->criarProduto('Isca Parafinada');
        $lote = $this->criarLote($produto, 'L-001', BusinessDate::hoje()->addYear()->toDateString());
        $deposito = $this->criarLocal('Depósito Central');

        $this->registrarEntrada($produto, $deposito, 2, $lote);

        $resposta = $this->actingAs($this->administrador)->postJson('/estoque/descarte', [
            'product_id' => $produto->id,
            'stock_location_id' => $deposito->id,
            'product_batch_id' => $lote->id,
            'quantidade' => 5,
            'motivo' => 'Tentativa de descartar mais do que existe',
        ]);

        $resposta->assertStatus(422);
        $this->assertStringContainsString('Saldo insuficiente', (string) $resposta->json('message'));

        // Nada foi gravado: a transação inteira voltou atrás.
        $this->assertSame(2.0, $this->saldo($produto, $deposito));
        $this->assertSame(0, $this->movimentos($produto, 'descarte'));
    }

    // -----------------------------------------------------------------
    // 3. Excluir lote com movimento devolve 422
    // -----------------------------------------------------------------

    public function test_excluir_lote_com_movimento_devolve_422_e_lote_sem_movimento_e_excluido(): void
    {
        $produto = $this->criarProduto('Isca Parafinada');
        $comMovimento = $this->criarLote($produto, 'L-001', BusinessDate::hoje()->addYear()->toDateString());
        $semMovimento = $this->criarLote($produto, 'L-002', BusinessDate::hoje()->addYear()->toDateString());
        $deposito = $this->criarLocal('Depósito Central');

        $this->registrarEntrada($produto, $deposito, 10, $comMovimento);

        $recusado = $this->actingAs($this->administrador)->deleteJson('/lotes/'.$comMovimento->id);

        $recusado->assertStatus(422);
        $this->assertStringContainsString('movimentação registrada', (string) $recusado->json('message'));
        $this->assertDatabaseHas('product_batches', ['id' => $comMovimento->id]);

        $aceito = $this->actingAs($this->administrador)->deleteJson('/lotes/'.$semMovimento->id);

        $aceito->assertOk();
        $this->assertDatabaseMissing('product_batches', ['id' => $semMovimento->id]);
    }

    public function test_cadastro_de_lote_com_entrada_inicial_gera_saldo(): void
    {
        $produto = $this->criarProduto('Isca Parafinada');
        $deposito = $this->criarLocal('Depósito Central');

        $resposta = $this->actingAs($this->administrador)->postJson('/lotes', [
            'product_id' => $produto->id,
            'lote' => 'L-100',
            'validade' => BusinessDate::hoje()->addYear()->toDateString(),
            'recebido_em' => BusinessDate::hoje()->toDateString(),
            'custo_unitario' => 12.5,
            'fornecedor' => 'Fornecedor Teste',
            'nota_fiscal' => 'NF-9090',
            'quantidade' => 25,
            'stock_location_id' => $deposito->id,
        ]);

        $resposta->assertOk();

        $lote = ProductBatch::query()->where('lote', 'L-100')->sole();

        $this->assertSame($this->empresa->id, (int) $lote->company_id);
        $this->assertSame(25.0, $this->saldo($produto, $deposito));
        $this->assertSame(1, $this->movimentos($produto, 'entrada'));
    }

    public function test_lote_repetido_no_mesmo_produto_e_recusado_pela_validacao(): void
    {
        $produto = $this->criarProduto('Isca Parafinada');
        $this->criarLote($produto, 'L-001', BusinessDate::hoje()->addYear()->toDateString());

        $resposta = $this->actingAs($this->administrador)->postJson('/lotes', [
            'product_id' => $produto->id,
            'lote' => 'L-001',
            'validade' => BusinessDate::hoje()->addYear()->toDateString(),
            'recebido_em' => BusinessDate::hoje()->toDateString(),
            'custo_unitario' => 10,
        ]);

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('lote');
    }

    // -----------------------------------------------------------------
    // 4. O inventário percorre abrir, contar e finalizar
    // -----------------------------------------------------------------

    public function test_inventario_percorre_abrir_contar_e_finalizar(): void
    {
        $produto = $this->criarProduto('Isca Parafinada');
        $lote = $this->criarLote($produto, 'L-001', BusinessDate::hoje()->addYear()->toDateString());
        $deposito = $this->criarLocal('Depósito Central');

        $this->registrarEntrada($produto, $deposito, 10, $lote);

        $abertura = $this->actingAs($this->administrador)->postJson('/inventarios', [
            'stock_location_id' => $deposito->id,
            'observacao' => 'Contagem mensal',
        ]);

        $abertura->assertOk();

        $inventario = Inventory::query()->sole();
        $this->assertSame(Inventory::SITUACAO_ABERTO, $inventario->situacao);

        $detalhe = $this->actingAs($this->administrador)->getJson('/inventarios/'.$inventario->id);
        $detalhe->assertOk();
        $detalhe->assertJsonPath('inventario.itens_total', 1);
        $detalhe->assertJsonPath('inventario.itens_contados', 0);
        $this->assertSame(10.0, (float) $detalhe->json('itens.0.saldo_sistema'));

        $item = InventoryItem::query()->where('inventory_id', $inventario->id)->sole();

        $contagem = $this->actingAs($this->administrador)->putJson(
            "/inventarios/{$inventario->id}/itens/{$item->id}",
            ['contado' => 8, 'justificativa' => 'Contagem física apontou falta de 2 unidades']
        );

        $contagem->assertOk();
        $this->assertSame(8.0, (float) $item->fresh()->saldo_contado);

        $fechamento = $this->actingAs($this->administrador)
            ->postJson("/inventarios/{$inventario->id}/finalizar");

        $fechamento->assertOk();
        $this->assertSame(Inventory::SITUACAO_FINALIZADO, $inventario->fresh()->situacao);
        $this->assertSame(8.0, $this->saldo($produto, $deposito));

        $ajuste = StockMovement::query()->where('inventory_id', $inventario->id)->sole();
        $this->assertSame('ajuste', $ajuste->tipo);
        $this->assertSame(2.0, (float) $ajuste->quantidade);
        $this->assertSame('Contagem física apontou falta de 2 unidades', $ajuste->motivo);
    }

    public function test_contagem_com_diferenca_sem_justificativa_devolve_422(): void
    {
        $produto = $this->criarProduto('Isca Parafinada');
        $lote = $this->criarLote($produto, 'L-001', BusinessDate::hoje()->addYear()->toDateString());
        $deposito = $this->criarLocal('Depósito Central');

        $this->registrarEntrada($produto, $deposito, 10, $lote);

        $inventario = $this->abrirInventario($deposito);
        $item = InventoryItem::query()->where('inventory_id', $inventario->id)->sole();

        $resposta = $this->actingAs($this->administrador)->putJson(
            "/inventarios/{$inventario->id}/itens/{$item->id}",
            ['contado' => 8]
        );

        $resposta->assertStatus(422);
        $this->assertStringContainsString('justificativa', (string) $resposta->json('message'));
        $this->assertNull($item->fresh()->saldo_contado);
    }

    public function test_finalizar_com_item_nao_contado_devolve_422(): void
    {
        $produto = $this->criarProduto('Isca Parafinada');
        $lote = $this->criarLote($produto, 'L-001', BusinessDate::hoje()->addYear()->toDateString());
        $deposito = $this->criarLocal('Depósito Central');

        $this->registrarEntrada($produto, $deposito, 10, $lote);

        $inventario = $this->abrirInventario($deposito);

        $resposta = $this->actingAs($this->administrador)
            ->postJson("/inventarios/{$inventario->id}/finalizar");

        $resposta->assertStatus(422);
        $this->assertSame(Inventory::SITUACAO_ABERTO, $inventario->fresh()->situacao);
        $this->assertSame(10.0, $this->saldo($produto, $deposito));
    }

    public function test_cancelar_inventario_encerra_a_contagem_sem_mexer_no_saldo(): void
    {
        $produto = $this->criarProduto('Isca Parafinada');
        $lote = $this->criarLote($produto, 'L-001', BusinessDate::hoje()->addYear()->toDateString());
        $deposito = $this->criarLocal('Depósito Central');

        $this->registrarEntrada($produto, $deposito, 10, $lote);

        $inventario = $this->abrirInventario($deposito);
        $item = InventoryItem::query()->where('inventory_id', $inventario->id)->sole();

        $this->actingAs($this->administrador)->putJson(
            "/inventarios/{$inventario->id}/itens/{$item->id}",
            ['contado' => 4, 'justificativa' => 'Contagem interrompida']
        )->assertOk();

        $resposta = $this->actingAs($this->administrador)
            ->postJson("/inventarios/{$inventario->id}/cancelar");

        $resposta->assertOk();
        $this->assertSame(Inventory::SITUACAO_CANCELADO, $inventario->fresh()->situacao);
        $this->assertSame(10.0, $this->saldo($produto, $deposito), 'cancelar não pode gerar ajuste');
        $this->assertSame(0, StockMovement::query()->where('tipo', 'ajuste')->count());

        // Contagem encerrada é imutável, para os dois lados.
        $this->actingAs($this->administrador)
            ->postJson("/inventarios/{$inventario->id}/finalizar")
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // 5. A rastreabilidade lista as OS que aplicaram o lote
    // -----------------------------------------------------------------

    public function test_rastreabilidade_lista_as_os_que_aplicaram_o_lote(): void
    {
        $produto = $this->criarProduto('Isca Parafinada');
        $lote = $this->criarLote($produto, 'L-001', BusinessDate::hoje()->addYear()->toDateString());
        $deposito = $this->criarLocal('Depósito Central');

        $this->registrarEntrada($produto, $deposito, 20, $lote);

        $primeira = $this->criarOrdemDeServico('Padaria Alfa', 'Rua das Flores');
        $segunda = $this->criarOrdemDeServico('Mercado Beta', 'Avenida Central');

        $this->registrarSaida($produto, $deposito, 2, $lote, $primeira);
        $this->registrarSaida($produto, $deposito, 3, $lote, $segunda);

        // Ruído que não pode aparecer na resposta: saída sem OS e descarte.
        $this->registrarSaida($produto, $deposito, 1, $lote, null);
        TenantAtual::comTenant($this->empresa->id, fn () => app(StockService::class)->descartar(
            $produto,
            $deposito,
            1,
            $this->administrador,
            'Frasco quebrado',
            $lote
        ));

        $resposta = $this->actingAs($this->administrador)
            ->getJson("/lotes/{$lote->id}/rastreabilidade");

        $resposta->assertOk();

        $aplicacoes = $resposta->json('aplicacoes');

        $this->assertCount(2, $aplicacoes);
        $this->assertSame(5.0, (float) $resposta->json('total_aplicado'));
        $this->assertSame('L-001', $resposta->json('lote.lote'));
        $this->assertSame('Isca Parafinada', $resposta->json('lote.produto'));

        $this->assertSame(['Padaria Alfa', 'Mercado Beta'], array_column($aplicacoes, 'cliente'));
        $this->assertSame([2.0, 3.0], array_map('floatval', array_column($aplicacoes, 'quantidade')));
        $this->assertSame(
            [$primeira->order_number, $segunda->order_number],
            array_column($aplicacoes, 'numero')
        );

        foreach ($aplicacoes as $aplicacao) {
            $this->assertNotEmpty($aplicacao['data'], 'a data da aplicação é parte da resposta à fiscalização');
            $this->assertNotEmpty($aplicacao['endereco'], 'o endereço da aplicação é parte da resposta');
        }

        $this->assertStringContainsString('Rua das Flores', $aplicacoes[0]['endereco']);
    }

    /**
     * Task 17.9: a peça de backend que faltava para o critério "exporta em
     * PDF" - a mesma consulta de `rastreabilidade()`, servida como documento.
     */
    public function test_rastreabilidade_pdf_devolve_o_documento(): void
    {
        $produto = $this->criarProduto('Isca Parafinada');
        $lote = $this->criarLote($produto, 'L-001', BusinessDate::hoje()->addYear()->toDateString());
        $deposito = $this->criarLocal('Depósito Central');

        $this->registrarEntrada($produto, $deposito, 10, $lote);

        $ordem = $this->criarOrdemDeServico('Padaria Alfa', 'Rua das Flores');
        $this->registrarSaida($produto, $deposito, 2, $lote, $ordem);

        $resposta = $this->actingAs($this->administrador)
            ->get("/lotes/{$lote->id}/rastreabilidade/pdf");

        $resposta->assertOk();
        $resposta->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_rastreabilidade_e_consultavel_por_quem_tem_apenas_estoque_ver(): void
    {
        $produto = $this->criarProduto('Isca Parafinada');
        $lote = $this->criarLote($produto, 'L-001', BusinessDate::hoje()->addYear()->toDateString());

        $leitura = $this->criarUsuario('leitura');

        $this->assertTrue($leitura->can('estoque-ver'));
        $this->assertFalse($leitura->can('estoque-movimentar'));

        $this->actingAs($leitura)
            ->getJson("/lotes/{$lote->id}/rastreabilidade")
            ->assertOk();
    }

    // -----------------------------------------------------------------
    // 6. Usuário sem estoque-movimentar não movimenta
    // -----------------------------------------------------------------

    public function test_usuario_sem_estoque_movimentar_nao_movimenta(): void
    {
        $produto = $this->criarProduto('Isca Parafinada');
        $deposito = $this->criarLocal('Depósito Central');
        $leitura = $this->criarUsuario('leitura');

        // A leitura continua liberada: quem atende o telefone precisa ver o saldo.
        $this->actingAs($leitura)->getJson('/estoque')->assertOk();

        $this->actingAs($leitura)->postJson('/estoque/entrada', [
            'product_id' => $produto->id,
            'stock_location_id' => $deposito->id,
            'quantidade' => 5,
        ])->assertForbidden();

        $this->actingAs($leitura)->postJson('/lotes', [
            'product_id' => $produto->id,
            'lote' => 'L-999',
            'validade' => BusinessDate::hoje()->addYear()->toDateString(),
            'recebido_em' => BusinessDate::hoje()->toDateString(),
            'custo_unitario' => 10,
        ])->assertForbidden();

        // Inventário é outra permissão, e leitura também não a tem.
        $this->actingAs($leitura)->getJson('/inventarios')->assertForbidden();

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, ProductBatch::query()->count());
    }

    public function test_tecnico_nao_recebe_nenhuma_permissao_de_estoque_nesta_entrega(): void
    {
        $tecnico = $this->criarUsuario('tecnico');

        $this->assertFalse($tecnico->can('estoque-ver'));
        $this->assertFalse($tecnico->can('estoque-movimentar'));
        $this->assertFalse($tecnico->can('estoque-inventariar'));

        $this->actingAs($tecnico)->getJson('/estoque')->assertForbidden();
    }

    public function test_as_tres_permissoes_novas_estao_no_catalogo(): void
    {
        $catalogo = collect(SyncPermissions::catalogo())->flatten()->all();

        $this->assertContains('estoque-ver', $catalogo);
        $this->assertContains('estoque-movimentar', $catalogo);
        $this->assertContains('estoque-inventariar', $catalogo);
    }

    // -----------------------------------------------------------------
    // 7. Tenant sem o módulo vê a página de indisponível
    // -----------------------------------------------------------------

    public function test_tenant_sem_o_modulo_estoque_ve_a_pagina_de_indisponivel(): void
    {
        $administradorSemModulo = $this->administradorDeTenantSemPlano();

        $destino = route('modulo-indisponivel', ['modulo' => 'estoque']);

        foreach (['/estoque', '/lotes', '/inventarios'] as $uri) {
            $this->actingAs($administradorSemModulo)->get($uri)->assertRedirect($destino);
        }

        // A mesma rota buscada por fetch responde 403 com JSON, para o
        // interceptor do frontend tratar sem precisar entender um HTML.
        $json = $this->actingAs($administradorSemModulo)->getJson('/estoque');

        $json->assertStatus(403);
        $json->assertJson(['modulo' => 'estoque']);
        $this->assertStringContainsString('Estoque', (string) $json->json('message'));
    }

    public function test_toda_rota_de_estoque_registrada_carrega_os_dois_middlewares(): void
    {
        $rotas = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->filter(fn ($rota): bool => (bool) preg_match('#^(estoque|lotes|inventarios)(/|$)#', $rota->uri()));

        $this->assertGreaterThanOrEqual(15, $rotas->count(), 'a varredura de rotas de estoque encontrou rotas de menos');

        foreach ($rotas as $rota) {
            $middlewares = $rota->gatherMiddleware();

            $this->assertContains(
                'module:estoque',
                $middlewares,
                "a rota '{$rota->uri()}' [".implode(',', $rota->methods()).'] não tem o middleware module:estoque'
            );

            $this->assertTrue(
                collect($middlewares)->contains(fn ($middleware): bool => is_string($middleware)
                    && str_starts_with($middleware, 'permission:estoque-')),
                "a rota '{$rota->uri()}' [".implode(',', $rota->methods()).'] não tem permissão de estoque'
            );
        }
    }

    // -----------------------------------------------------------------
    // 8. Um tenant não vê nem manipula estoque do outro
    // -----------------------------------------------------------------

    public function test_um_tenant_nao_ve_nem_manipula_estoque_do_outro(): void
    {
        $outra = $this->cenarioDeOutraEmpresa();

        // Leitura: nada da outra empresa aparece nas listagens.
        $lotes = $this->actingAs($this->administrador)->getJson('/lotes');
        $lotes->assertOk();
        $this->assertSame([], $lotes->json('lotes'));
        $this->assertStringNotContainsString('Fornecedor da outra empresa', $lotes->getContent());

        $inventarios = $this->actingAs($this->administrador)->getJson('/inventarios');
        $inventarios->assertOk();
        $this->assertSame([], $inventarios->json('inventarios'));

        $estoque = $this->actingAs($this->administrador)->getJson('/estoque');
        $estoque->assertOk();
        $this->assertSame([], $estoque->json('produtos'));

        // Acesso direto por id: 404 em tudo, e não 403, porque confirmar a
        // existência do registro já vaza informação entre empresas.
        $this->actingAs($this->administrador)
            ->getJson("/lotes/{$outra['lote']->id}/rastreabilidade")
            ->assertNotFound();

        $this->actingAs($this->administrador)->putJson("/lotes/{$outra['lote']->id}", [
            'lote' => 'INVADIDO',
            'validade' => BusinessDate::hoje()->addYear()->toDateString(),
            'recebido_em' => BusinessDate::hoje()->toDateString(),
            'custo_unitario' => 1,
        ])->assertNotFound();

        $this->actingAs($this->administrador)
            ->deleteJson("/lotes/{$outra['lote']->id}")
            ->assertNotFound();

        $this->actingAs($this->administrador)
            ->getJson("/inventarios/{$outra['inventario']->id}")
            ->assertNotFound();

        $this->actingAs($this->administrador)
            ->postJson("/inventarios/{$outra['inventario']->id}/finalizar")
            ->assertNotFound();

        $this->actingAs($this->administrador)
            ->postJson("/inventarios/{$outra['inventario']->id}/cancelar")
            ->assertNotFound();

        // Id da outra empresa vindo pelo corpo da requisição: mesmo tratamento.
        $this->actingAs($this->administrador)->postJson('/estoque/entrada', [
            'product_id' => $outra['produto']->id,
            'stock_location_id' => $outra['local']->id,
            'quantidade' => 5,
        ])->assertNotFound();

        $this->actingAs($this->administrador)->postJson('/inventarios', [
            'stock_location_id' => $outra['local']->id,
        ])->assertNotFound();

        // Nada da outra empresa foi alterado: o lote, o inventário e o razão
        // continuam como estavam (o único movimento existente é a entrada de 30
        // que o próprio cenário da vizinha gravou).
        $this->assertSame('L-OUTRA', $outra['lote']->fresh()->lote);
        $this->assertSame(Inventory::SITUACAO_ABERTO, $outra['inventario']->fresh()->situacao);
        $this->assertSame(1, StockMovement::query()->deTodasAsEmpresas()->count());
        $this->assertSame(
            30.0,
            TenantAtual::comTenant(
                $outra['empresa']->id,
                fn (): float => app(StockService::class)->saldo($outra['produto'], $outra['local'])
            )
        );
    }

    /**
     * Task 17.10: a rastreabilidade lista as OS certas, e só as do próprio
     * tenant. O teste acima (`test_um_tenant_nao_ve_nem_manipula_estoque_do_outro`)
     * já prova que acessar a rastreabilidade pelo id do lote de outra empresa
     * devolve 404. Este prova o outro lado: mesmo com o **mesmo código de
     * lote** cadastrado nas duas empresas (código de lote não é único fora da
     * empresa, só o id é, e as duas OS aplicando "L-001" em empresas
     * diferentes é cenário real), a rastreabilidade do lote de uma empresa
     * nunca lista a OS aplicada pela outra.
     */
    public function test_rastreabilidade_nunca_lista_os_de_outra_empresa_mesmo_com_o_mesmo_codigo_de_lote(): void
    {
        $produto = $this->criarProduto('Isca Parafinada');
        $lote = $this->criarLote($produto, 'L-001', BusinessDate::hoje()->addYear()->toDateString());
        $deposito = $this->criarLocal('Depósito Central');

        $this->registrarEntrada($produto, $deposito, 10, $lote);

        $ordem = $this->criarOrdemDeServico('Padaria Alfa', 'Rua das Flores');
        $this->registrarSaida($produto, $deposito, 3, $lote, $ordem);

        $outra = $this->cenarioDeRastreabilidadeDeOutraEmpresaComMesmoCodigoDeLote();

        $resposta = $this->actingAs($this->administrador)
            ->getJson("/lotes/{$lote->id}/rastreabilidade");

        $resposta->assertOk();

        $aplicacoes = $resposta->json('aplicacoes');

        $this->assertCount(1, $aplicacoes);
        $this->assertSame(['Padaria Alfa'], array_column($aplicacoes, 'cliente'));
        $this->assertSame([$ordem->order_number], array_column($aplicacoes, 'numero'));
        $this->assertSame(3.0, (float) $resposta->json('total_aplicado'));

        // Nada da OS da vizinha, aplicada no lote homônimo dela, vaza para
        // cá: nem o cliente, nem o número da ordem, nem a quantidade dela.
        $this->assertStringNotContainsString('Cliente da vizinha com lote igual', $resposta->getContent());
        $this->assertStringNotContainsString($outra['ordem']->order_number, $resposta->getContent());
    }

    // -----------------------------------------------------------------
    // Apoio: cenários
    // -----------------------------------------------------------------

    /**
     * Dois produtos, dois locais e três lotes: um dentro da validade, um
     * vencido e um vencendo dentro da janela de 30 dias.
     *
     * @return array<string, mixed>
     */
    private function cenarioDeSaldo(): array
    {
        $isca = $this->criarProduto('Isca Parafinada', ['estoque_minimo' => 50]);
        $gel = $this->criarProduto('Gel Inseticida');

        $deposito = $this->criarLocal('Depósito Central');
        $van = $this->criarLocal('Van do técnico', 'tecnico');

        $normal = $this->criarLote($isca, 'L-NORMAL', BusinessDate::hoje()->addYear()->toDateString());
        $vencido = $this->criarLote($isca, 'L-VENCIDO', BusinessDate::hoje()->subDays(10)->toDateString());
        $vencendo = $this->criarLote($gel, 'L-VENCENDO', BusinessDate::hoje()->addDays(10)->toDateString());

        $this->registrarEntrada($isca, $deposito, 10, $normal);
        $this->registrarEntrada($isca, $deposito, 5, $vencido);
        $this->registrarEntrada($gel, $van, 8, $vencendo);

        return compact('isca', 'gel', 'deposito', 'van', 'normal', 'vencido', 'vencendo');
    }

    /**
     * Empresa vizinha com produto, local, lote, saldo e inventário aberto.
     *
     * @return array<string, mixed>
     */
    private function cenarioDeOutraEmpresa(): array
    {
        $empresa = Company::create([
            'name' => 'Dedetizadora Vizinha',
            'cnpj' => '99.999.999/0001-99',
            'email' => 'contato@vizinha.test',
        ]);

        return TenantAtual::comTenant($empresa->id, function () use ($empresa): array {
            $usuario = User::factory()->create([
                'name' => 'Administrador da vizinha',
                'email' => 'admin-vizinha@exemplo.test',
            ]);

            $produto = $this->criarProduto('Produto da outra empresa', [], false);
            $local = StockLocation::create(['nome' => 'Depósito da outra', 'tipo' => 'deposito']);

            $lote = ProductBatch::create([
                'product_id' => $produto->id,
                'lote' => 'L-OUTRA',
                'validade' => BusinessDate::hoje()->addYear()->toDateString(),
                'recebido_em' => BusinessDate::hoje()->toDateString(),
                'custo_unitario' => 5,
                'fornecedor' => 'Fornecedor da outra empresa',
            ]);

            app(StockService::class)->entrada($produto, $local, 30, $usuario, $lote);

            $inventario = Inventory::create([
                'stock_location_id' => $local->id,
                'situacao' => Inventory::SITUACAO_ABERTO,
                'aberto_por' => $usuario->id,
                'aberto_em' => now(),
            ]);

            InventoryItem::create([
                'inventory_id' => $inventario->id,
                'product_id' => $produto->id,
                'product_batch_id' => $lote->id,
                'saldo_sistema' => 30,
            ]);

            return compact('empresa', 'usuario', 'produto', 'local', 'lote', 'inventario');
        });
    }

    /**
     * Empresa vizinha com produto, local e lote próprios, usando de propósito
     * o **mesmo código de lote** ("L-001") de `criarLote()`, e uma OS que
     * aplica esse lote em um cliente da própria vizinha. Existe só para a
     * prova de que a rastreabilidade não confunde lotes de empresas
     * diferentes que compartilham código.
     *
     * @return array<string, mixed>
     */
    private function cenarioDeRastreabilidadeDeOutraEmpresaComMesmoCodigoDeLote(): array
    {
        $empresa = Company::create([
            'name' => 'Dedetizadora Vizinha Com Lote Igual',
            'cnpj' => '77.777.777/0001-77',
            'email' => 'contato@vizinha-lote.test',
        ]);

        return TenantAtual::comTenant($empresa->id, function () use ($empresa): array {
            $usuario = User::factory()->create([
                'name' => 'Administrador da vizinha com lote igual',
                'email' => 'admin-vizinha-lote@exemplo.test',
            ]);

            $produto = $this->criarProduto('Isca Parafinada da vizinha', [], false);
            $local = StockLocation::create(['nome' => 'Depósito da vizinha', 'tipo' => 'deposito']);

            // Mesmo código de lote da empresa principal ("L-001"): só o id e
            // o company_id diferem.
            $lote = ProductBatch::create([
                'product_id' => $produto->id,
                'lote' => 'L-001',
                'validade' => BusinessDate::hoje()->addYear()->toDateString(),
                'recebido_em' => BusinessDate::hoje()->toDateString(),
                'custo_unitario' => 5,
            ]);

            app(StockService::class)->entrada($produto, $local, 10, $usuario, $lote);

            $cliente = Client::create([
                'name' => 'Cliente da vizinha com lote igual',
                'email' => 'cliente-vizinha-lote@exemplo.test',
                'phone' => '11999990000',
                'cnpj' => '55.666.777/0001-88',
            ]);

            $endereco = Address::create([
                'client_id' => $cliente->id,
                'nickname' => 'Matriz',
                'street' => 'Rua da Vizinha',
                'number' => '200',
                'district' => 'Centro',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zip' => '02000-000',
                'active' => true,
            ]);

            $ordem = WorkOrder::create([
                'client_id' => $cliente->id,
                'address_id' => $endereco->id,
                'order_number' => 'OS-VIZ-'.strtoupper(substr(uniqid(), -6)),
                'scheduled_date' => BusinessDate::hoje()->toDateString(),
                'status' => 'completed',
                'total_cost' => 500,
                'final_amount' => 500,
                'payment_status' => 'pending',
                'active' => true,
            ]);

            app(StockService::class)->saida(
                produto: $produto,
                local: $local,
                quantidade: 4,
                usuario: $usuario,
                lote: $lote,
                motivo: 'Aplicação registrada em campo',
                ordemDeServico: $ordem
            );

            return compact('empresa', 'usuario', 'produto', 'local', 'lote', 'ordem');
        });
    }

    /**
     * Administrador de um tenant novo, sem plano nenhum e portanto sem o
     * módulo `estoque` por nenhuma via.
     */
    private function administradorDeTenantSemPlano(): User
    {
        $empresa = Company::create([
            'name' => 'Dedetizadora Sem Estoque',
            'cnpj' => '88.888.888/0001-88',
            'email' => 'contato@sem-estoque.test',
        ]);

        $usuario = TenantAtual::comTenant($empresa->id, fn () => User::factory()->create([
            'name' => 'Administrador sem estoque',
            'email' => 'admin-sem-estoque@exemplo.test',
        ]));

        $usuario->assignRole('administrador');

        return $usuario->fresh();
    }

    // -----------------------------------------------------------------
    // Apoio: fixtures
    // -----------------------------------------------------------------

    private function criarUsuario(string $papel): User
    {
        $usuario = TenantAtual::comTenant($this->empresa->id, fn () => User::factory()->create([
            'name' => 'Usuário '.$papel,
            'email' => $papel.'-'.uniqid().'@exemplo.test',
            'is_active' => true,
        ]));

        $usuario->assignRole($papel);

        return $usuario->fresh();
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function criarProduto(string $nome, array $atributos = [], bool $noTenantPadrao = true): Product
    {
        $criar = function () use ($nome, $atributos): Product {
            $sufixo = uniqid();

            return Product::create(array_merge([
                'name' => $nome,
                'active_ingredient_id' => ActiveIngredient::create(['name' => 'Princípio '.$sufixo])->id,
                'chemical_group_id' => ChemicalGroup::create(['name' => 'Grupo '.$sufixo])->id,
                'antidote_id' => Antidote::create(['name' => 'Antídoto '.$sufixo])->id,
                'controla_estoque' => true,
                'unidade' => 'un',
            ], $atributos));
        };

        return $noTenantPadrao
            ? TenantAtual::comTenant($this->empresa->id, $criar)
            : $criar();
    }

    private function criarLote(Product $produto, string $codigo, string $validade): ProductBatch
    {
        return TenantAtual::comTenant($this->empresa->id, fn (): ProductBatch => ProductBatch::create([
            'product_id' => $produto->id,
            'lote' => $codigo,
            'validade' => $validade,
            'recebido_em' => BusinessDate::hoje()->toDateString(),
            'custo_unitario' => 12.5,
            'fornecedor' => 'Fornecedor da casa',
        ]));
    }

    private function criarLocal(string $nome, string $tipo = 'deposito'): StockLocation
    {
        return TenantAtual::comTenant(
            $this->empresa->id,
            fn (): StockLocation => StockLocation::create(['nome' => $nome, 'tipo' => $tipo])
        );
    }

    private function criarOrdemDeServico(string $cliente, string $rua): WorkOrder
    {
        return TenantAtual::comTenant($this->empresa->id, function () use ($cliente, $rua): WorkOrder {
            $registro = Client::create([
                'name' => $cliente,
                'email' => strtolower(str_replace(' ', '-', $cliente)).'@exemplo.test',
                'phone' => '11999990000',
                // `clients.cnpj` não tem valor padrão no banco: cliente sem
                // documento não existe no cadastro real.
                'cnpj' => sprintf('%02d.%03d.%03d/0001-%02d', random_int(10, 99), random_int(100, 999), random_int(100, 999), random_int(10, 99)),
            ]);

            $endereco = Address::create([
                'client_id' => $registro->id,
                // `addresses.nickname` também não tem valor padrão no banco.
                'nickname' => 'Matriz',
                'street' => $rua,
                'number' => '100',
                'district' => 'Centro',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zip' => '01000-000',
                'active' => true,
            ]);

            return WorkOrder::create([
                'client_id' => $registro->id,
                'address_id' => $endereco->id,
                'order_number' => 'OS-'.strtoupper(substr(uniqid(), -6)),
                'scheduled_date' => BusinessDate::hoje()->toDateString(),
                'status' => 'completed',
                'total_cost' => 500,
                'final_amount' => 500,
                'payment_status' => 'pending',
                'active' => true,
            ]);
        });
    }

    private function registrarEntrada(Product $produto, StockLocation $local, float $quantidade, ?ProductBatch $lote = null): void
    {
        TenantAtual::comTenant($this->empresa->id, fn () => app(StockService::class)->entrada(
            $produto,
            $local,
            $quantidade,
            $this->administrador,
            $lote,
            'Carga inicial do cenário de teste'
        ));
    }

    private function registrarSaida(
        Product $produto,
        StockLocation $local,
        float $quantidade,
        ?ProductBatch $lote,
        ?WorkOrder $ordem
    ): void {
        TenantAtual::comTenant($this->empresa->id, fn () => app(StockService::class)->saida(
            produto: $produto,
            local: $local,
            quantidade: $quantidade,
            usuario: $this->administrador,
            lote: $lote,
            motivo: 'Aplicação registrada em campo',
            ordemDeServico: $ordem
        ));
    }

    private function abrirInventario(StockLocation $local): Inventory
    {
        $this->actingAs($this->administrador)
            ->postJson('/inventarios', ['stock_location_id' => $local->id])
            ->assertOk();

        return Inventory::query()->latest('id')->firstOrFail();
    }

    // -----------------------------------------------------------------
    // Apoio: asserções
    // -----------------------------------------------------------------

    private function saldo(Product $produto, ?StockLocation $local = null): float
    {
        return TenantAtual::comTenant(
            $this->empresa->id,
            fn (): float => app(StockService::class)->saldo($produto, $local)
        );
    }

    private function movimentos(Product $produto, string $tipo): int
    {
        return TenantAtual::comTenant(
            $this->empresa->id,
            fn (): int => StockMovement::query()
                ->where('product_id', $produto->id)
                ->where('tipo', $tipo)
                ->count()
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $produtos
     * @return array<int, string>
     */
    private function nomes(array $produtos): array
    {
        return array_column($produtos, 'nome');
    }

    /**
     * @param  array<int, array<string, mixed>>  $produtos
     * @param  array<int, string>  $nomes
     * @return array<int, float>
     */
    private function saldos(array $produtos, array $nomes): array
    {
        return array_map(
            fn (string $nome): float => (float) $this->produtoNaLista($produtos, $nome)['saldo'],
            $nomes
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $produtos
     * @return array<string, mixed>
     */
    private function produtoNaLista(array $produtos, string $nome): array
    {
        foreach ($produtos as $produto) {
            if ($produto['nome'] === $nome) {
                return $produto;
            }
        }

        $this->fail("Produto \"{$nome}\" não veio na listagem de estoque.");
    }
}
