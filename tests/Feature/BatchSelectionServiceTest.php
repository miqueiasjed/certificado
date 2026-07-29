<?php

namespace Tests\Feature;

use App\Exceptions\EstoqueInsuficienteException;
use App\Exceptions\LoteVencidoException;
use App\Models\ActiveIngredient;
use App\Models\Antidote;
use App\Models\ChemicalGroup;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\BatchSelectionService;
use App\Services\StockService;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 17.3 do Plano 17: FEFO, bloqueio de lote vencido e consultas de validade.
 *
 * O que este arquivo prende:
 *
 * - A saída sai pelo lote que **vence antes**, não pelo que chegou antes. Lote
 *   comprado depois pode vencer antes, e dar baixa por ordem de cadastro deixa o
 *   de vencimento próximo apodrecer na prateleira.
 * - Lote vencido nunca é escolhido pela seleção automática nem aceito na mão.
 *   Aplicar saneante fora da validade é infração sanitária.
 * - O dia comparado é o da **aplicação em campo**, nunca o da sincronização nem
 *   `now()` em UTC. Os dois testes que fixam data existem por isso, e o de 22h é
 *   o que pega o erro de um dia: às 22h de Brasília do dia do vencimento, em UTC
 *   já é o dia seguinte.
 * - Lote vencido continua com saldo e visível em `vencidos()`. O caminho de sair
 *   dele é o descarte com motivo, que é o que fecha o ciclo perante
 *   fiscalização.
 */
class BatchSelectionServiceTest extends TestCase
{
    use RefreshDatabase;

    private BatchSelectionService $selecao;

    private StockService $estoque;

    private Company $empresa;

    private User $usuario;

    private Product $produto;

    private StockLocation $deposito;

    private StockLocation $van;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->selecao = app(BatchSelectionService::class);
        $this->estoque = app(StockService::class);
        $this->empresa = Company::query()->firstOrFail();

        [$this->usuario, $this->produto, $this->deposito, $this->van] = TenantAtual::comTenant(
            $this->empresa->id,
            function (): array {
                $usuario = User::factory()->create(['name' => 'Estoquista', 'is_active' => true]);
                $produto = $this->criarProduto('Cupinicida Concentrado');

                $deposito = StockLocation::create(['nome' => 'Depósito Central', 'tipo' => 'deposito']);
                $van = StockLocation::create(['nome' => 'Van do João', 'tipo' => 'tecnico']);

                return [$usuario, $produto, $deposito, $van];
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
    // FEFO
    // -----------------------------------------------------------------

    public function test_selecao_escolhe_o_lote_de_validade_mais_proxima(): void
    {
        // Cadastrados fora da ordem de validade de propósito: o L-PERTO é o
        // último a entrar e o primeiro a vencer. Por FIFO sairia o L-LONGE.
        $longe = $this->criarLote('L-LONGE', $this->emDias(90));
        $meio = $this->criarLote('L-MEIO', $this->emDias(45));
        $perto = $this->criarLote('L-PERTO', $this->emDias(10));

        $this->estoque->entrada($this->produto, $this->deposito, 5, $this->usuario, $longe);
        $this->estoque->entrada($this->produto, $this->deposito, 6, $this->usuario, $meio);
        $this->estoque->entrada($this->produto, $this->deposito, 4, $this->usuario, $perto);

        $escolhidos = $this->selecao->selecionar($this->produto, $this->deposito, 3);

        $this->assertCount(1, $escolhidos);
        $this->assertSame($perto->id, $escolhidos[0]['lote']->id);
        $this->assertSame(3.0, $escolhidos[0]['quantidade']);
    }

    public function test_quantidade_maior_que_um_lote_e_dividida_entre_os_seguintes(): void
    {
        $longe = $this->criarLote('L-LONGE', $this->emDias(90));
        $meio = $this->criarLote('L-MEIO', $this->emDias(45));
        $perto = $this->criarLote('L-PERTO', $this->emDias(10));

        $this->estoque->entrada($this->produto, $this->deposito, 5, $this->usuario, $longe);
        $this->estoque->entrada($this->produto, $this->deposito, 6, $this->usuario, $meio);
        $this->estoque->entrada($this->produto, $this->deposito, 4, $this->usuario, $perto);

        // 4 do L-PERTO esgotam o lote, e o restante desce para o próximo a
        // vencer.
        $doisLotes = $this->selecao->selecionar($this->produto, $this->deposito, 7);

        $this->assertCount(2, $doisLotes);
        $this->assertSame($perto->id, $doisLotes[0]['lote']->id);
        $this->assertSame(4.0, $doisLotes[0]['quantidade']);
        $this->assertSame($meio->id, $doisLotes[1]['lote']->id);
        $this->assertSame(3.0, $doisLotes[1]['quantidade']);

        // O pedido que consome tudo se espalha pelos três, sempre na ordem de
        // validade.
        $tresLotes = $this->selecao->selecionar($this->produto, $this->deposito, 15);

        $this->assertSame(
            [$perto->id, $meio->id, $longe->id],
            array_map(fn (array $item): int => $item['lote']->id, $tresLotes)
        );
        $this->assertSame([4.0, 6.0, 5.0], array_map(fn (array $item): float => $item['quantidade'], $tresLotes));
    }

    public function test_pedido_maior_que_o_disponivel_e_recusado_com_os_numeros(): void
    {
        $lote = $this->criarLote('L-UNICO', $this->emDias(30));
        $this->estoque->entrada($this->produto, $this->deposito, 4, $this->usuario, $lote);

        try {
            $this->selecao->selecionar($this->produto, $this->deposito, 6);
            $this->fail('A seleção sem saldo suficiente deveria ter sido recusada.');
        } catch (EstoqueInsuficienteException $excecao) {
            $this->assertSame(4.0, $excecao->saldoAtual);
            $this->assertSame(6.0, $excecao->quantidadePedida);
            $this->assertSame(2.0, $excecao->faltante());
        }
    }

    public function test_produto_sem_lote_cadastrado_devolve_selecao_vazia(): void
    {
        // Sinal de que o produto não trabalha com lote: quem chama dá a baixa
        // sem lote, em vez de tratar como falta de saldo.
        $this->estoque->entrada($this->produto, $this->deposito, 10, $this->usuario);

        $this->assertSame([], $this->selecao->selecionar($this->produto, $this->deposito, 2));
    }

    // -----------------------------------------------------------------
    // Lote vencido
    // -----------------------------------------------------------------

    public function test_lote_vencido_nunca_e_selecionado(): void
    {
        $vencido = $this->criarLote('L-VENCIDO', $this->emDias(-1));
        $valido = $this->criarLote('L-VALIDO', $this->emDias(60));

        $this->estoque->entrada($this->produto, $this->deposito, 20, $this->usuario, $vencido);
        $this->estoque->entrada($this->produto, $this->deposito, 5, $this->usuario, $valido);

        // O vencido vence antes e tem o maior saldo: por FEFO puro seria o
        // primeiro. Não é escolhido porque o bloqueio é sanitário.
        $escolhidos = $this->selecao->selecionar($this->produto, $this->deposito, 5);

        $this->assertCount(1, $escolhidos);
        $this->assertSame($valido->id, $escolhidos[0]['lote']->id);
        $this->assertSame(5.0, $escolhidos[0]['quantidade']);

        // E o saldo do vencido não entra na conta do disponível, mesmo estando
        // fisicamente na prateleira.
        try {
            $this->selecao->selecionar($this->produto, $this->deposito, 6);
            $this->fail('O saldo do lote vencido não pode cobrir a seleção.');
        } catch (EstoqueInsuficienteException $excecao) {
            $this->assertSame(5.0, $excecao->saldoAtual);
        }
    }

    public function test_lote_vencido_informado_manualmente_lanca_a_excecao(): void
    {
        $vencido = $this->criarLote('L-VENCIDO', $this->emDias(-5));

        // A entrada do lote vencido é aceita: a devolução do técnico com produto
        // vencido precisa constar em algum lugar para poder ser descartada.
        $this->estoque->entrada($this->produto, $this->deposito, 10, $this->usuario, $vencido);

        try {
            $this->estoque->saida($this->produto, $this->deposito, 2, $this->usuario, $vencido, 'Aplicação na visita');
            $this->fail('A aplicação de lote vencido deveria ter sido recusada.');
        } catch (LoteVencidoException $excecao) {
            $this->assertSame($this->produto->id, $excecao->productId);
            $this->assertSame($vencido->id, $excecao->productBatchId);
            $this->assertSame('L-VENCIDO', $excecao->lote);
            $this->assertSame($this->emDias(-5), $excecao->validade);
            $this->assertSame(BusinessDate::hoje()->toDateString(), $excecao->diaDaAplicacao);
            $this->assertSame(5, $excecao->diasVencido());
            $this->assertStringContainsString('L-VENCIDO', $excecao->getMessage());
            $this->assertStringContainsString('Cupinicida Concentrado', $excecao->getMessage());
        }

        // Nada foi gravado, e o saldo do vencido continua onde estava: sumir com
        // ele esconderia produto que ainda precisa ser descartado com registro.
        $this->assertSame(10.0, $this->estoque->saldo($this->produto, $this->deposito, $vencido));
        $this->assertSame(1, StockMovement::query()->where('product_id', $this->produto->id)->count());
    }

    public function test_lote_vencido_pode_voltar_da_van_para_o_deposito(): void
    {
        $vencido = $this->criarLote('L-VENCIDO', $this->emDias(-2));

        $this->estoque->entrada($this->produto, $this->van, 8, $this->usuario, $vencido);

        // O caminho físico até o descarte passa pela volta ao depósito: bloquear
        // a transferência deixaria o produto vencido preso na van do técnico.
        $this->estoque->transferir($this->produto, $this->van, $this->deposito, 8, $this->usuario, $vencido);

        $this->assertSame(0.0, $this->estoque->saldo($this->produto, $this->van, $vencido));
        $this->assertSame(8.0, $this->estoque->saldo($this->produto, $this->deposito, $vencido));
    }

    // -----------------------------------------------------------------
    // A data que vale é a da aplicação em campo
    // -----------------------------------------------------------------

    public function test_aplicacao_anterior_ao_vencimento_e_aceita_mesmo_sincronizada_depois(): void
    {
        // As duas datas ficam fixas: este teste não pode depender da hora
        // corrente.
        $lote = $this->criarLote('L-CAMPO', '2026-07-20');

        $this->viajarPara('2026-07-18 12:00:00');
        $this->estoque->entrada($this->produto, $this->van, 10, $this->usuario, $lote);

        // A sincronização do aplicativo do técnico só acontece dia 25, cinco
        // dias depois do vencimento.
        $this->viajarPara('2026-07-25 11:00:00');

        $movimento = $this->estoque->saida(
            $this->produto,
            $this->van,
            3,
            $this->usuario,
            $lote,
            'Aplicação registrada em campo',
            null,
            '2026-07-20 16:30:00'
        );

        $this->assertSame(3.0, (float) $movimento->quantidade);
        $this->assertSame(7.0, $this->estoque->saldo($this->produto, $this->van, $lote));
        // O movimento fica no dia em que a aplicação aconteceu, não no da
        // sincronização.
        $this->assertSame('2026-07-20', BusinessDate::diaDe($movimento->ocorrido_em));

        // A mesma baixa lançada como acontecendo hoje (dia 25) é recusada: o
        // lote está vencido agora.
        $this->expectException(LoteVencidoException::class);
        $this->estoque->saida($this->produto, $this->van, 1, $this->usuario, $lote, 'Aplicação de hoje');
    }

    public function test_aplicacao_as_22h_do_dia_do_vencimento_e_aceita(): void
    {
        // 22h de Brasília do dia 20 já é dia 21 em UTC. Comparar em UTC daria o
        // lote como vencido e inventaria uma infração que não aconteceu.
        $lote = $this->criarLote('L-VIRADA', '2026-07-20');

        $this->viajarPara('2026-07-20 11:00:00');
        $this->estoque->entrada($this->produto, $this->deposito, 5, $this->usuario, $lote);

        $movimento = $this->estoque->saida(
            $this->produto,
            $this->deposito,
            1,
            $this->usuario,
            $lote,
            'Aplicação da noite',
            null,
            '2026-07-20 22:00:00'
        );

        $this->assertSame(4.0, (float) $movimento->saldo_apos);
        $this->assertSame('2026-07-20', BusinessDate::diaDe($movimento->ocorrido_em));
    }

    public function test_selecao_usa_o_dia_da_aplicacao_e_nao_o_dia_de_hoje(): void
    {
        $lote = $this->criarLote('L-CAMPO', '2026-07-20');

        $this->viajarPara('2026-07-18 12:00:00');
        $this->estoque->entrada($this->produto, $this->van, 10, $this->usuario, $lote);

        $this->viajarPara('2026-07-25 11:00:00');

        // Hoje o lote está vencido e não há o que selecionar.
        try {
            $this->selecao->selecionar($this->produto, $this->van, 2);
            $this->fail('Hoje o lote está vencido e não deveria ser selecionado.');
        } catch (EstoqueInsuficienteException $excecao) {
            $this->assertSame(0.0, $excecao->saldoAtual);
        }

        // Para a aplicação registrada no dia 20, ele ainda valia.
        $escolhidos = $this->selecao->selecionar($this->produto, $this->van, 2, '2026-07-20 16:30:00');

        $this->assertCount(1, $escolhidos);
        $this->assertSame($lote->id, $escolhidos[0]['lote']->id);
    }

    // -----------------------------------------------------------------
    // Consultas de validade
    // -----------------------------------------------------------------

    public function test_vencendo_em_30_lista_os_lotes_corretos(): void
    {
        $vencido = $this->criarLote('L-VENCIDO', $this->emDias(-3));
        $hoje = $this->criarLote('L-HOJE', $this->emDias(0));
        $dezDias = $this->criarLote('L-10', $this->emDias(10));
        $trintaDias = $this->criarLote('L-30', $this->emDias(30));
        $trintaEUm = $this->criarLote('L-31', $this->emDias(31));
        $semSaldo = $this->criarLote('L-SEM-SALDO', $this->emDias(15));

        foreach ([$vencido, $hoje, $dezDias, $trintaDias, $trintaEUm, $semSaldo] as $lote) {
            $this->estoque->entrada($this->produto, $this->deposito, 4, $this->usuario, $lote);
        }

        // Lote zerado sai da lista: não há o que vencer na prateleira.
        $this->estoque->saida($this->produto, $this->deposito, 4, $this->usuario, $semSaldo);

        // Parte do L-10 está na van: o alerta precisa dizer onde o produto está.
        $this->estoque->transferir($this->produto, $this->deposito, $this->van, 1, $this->usuario, $dezDias);

        $vencendo = $this->selecao->vencendoEm(30);

        $this->assertSame(
            ['L-HOJE', 'L-10', 'L-30'],
            $vencendo->map(fn (array $item): string => $item['lote']->lote)->all()
        );

        $this->assertSame(0, $vencendo[0]['dias_para_vencer']);
        $this->assertSame(10, $vencendo[1]['dias_para_vencer']);
        $this->assertSame(30, $vencendo[2]['dias_para_vencer']);

        $this->assertSame(4.0, $vencendo[1]['saldo']);
        $this->assertSame(
            [
                ['stock_location_id' => $this->deposito->id, 'nome' => 'Depósito Central', 'quantidade' => 3.0],
                ['stock_location_id' => $this->van->id, 'nome' => 'Van do João', 'quantidade' => 1.0],
            ],
            $vencendo[1]['locais']
        );

        // Janela mais curta não alcança o que vence depois dela.
        $this->assertSame(
            ['L-HOJE'],
            $this->selecao->vencendoEm(7)->map(fn (array $item): string => $item['lote']->lote)->all()
        );

        // O que já venceu é assunto de `vencidos()`, não de `vencendoEm()`.
        $vencidos = $this->selecao->vencidos();

        $this->assertCount(1, $vencidos);
        $this->assertSame($vencido->id, $vencidos[0]['lote']->id);
        $this->assertSame(-3, $vencidos[0]['dias_para_vencer']);
        $this->assertSame(4.0, $vencidos[0]['saldo']);
    }

    public function test_descarte_tira_o_lote_vencido_do_saldo_e_da_lista(): void
    {
        $vencido = $this->criarLote('L-VENCIDO', $this->emDias(-1));
        $this->estoque->entrada($this->produto, $this->deposito, 8, $this->usuario, $vencido);

        $this->assertCount(1, $this->selecao->vencidos());

        $movimento = $this->estoque->descartar(
            $this->produto,
            $this->deposito,
            8,
            $this->usuario,
            'Lote vencido, descarte registrado em ata',
            $vencido
        );

        $this->assertSame('descarte', $movimento->tipo);
        $this->assertSame('Lote vencido, descarte registrado em ata', $movimento->motivo);
        $this->assertSame(0.0, (float) $movimento->saldo_apos);
        $this->assertSame(0.0, $this->estoque->saldo($this->produto, $this->deposito, $vencido));

        // Sem saldo, o lote deixa de ser pendência sanitária e sai do alerta.
        $this->assertCount(0, $this->selecao->vencidos());

        // O movimento continua no razão: é ele que prova o descarte perante
        // fiscalização.
        $this->assertSame(
            1,
            StockMovement::query()
                ->where('product_batch_id', $vencido->id)
                ->where('tipo', 'descarte')
                ->count()
        );
    }

    public function test_consulta_de_validade_nao_alcanca_lote_de_outra_empresa(): void
    {
        $meuLote = $this->criarLote('L-MINHA', $this->emDias(5));
        $this->estoque->entrada($this->produto, $this->deposito, 3, $this->usuario, $meuLote);

        $outraEmpresa = Company::create([
            'name' => 'Dedetizadora Concorrente',
            'cnpj' => '22.222.222/0001-22',
            'email' => 'contato@concorrente.test',
        ]);

        TenantAtual::comTenant($outraEmpresa->id, function (): void {
            $usuario = User::factory()->create(['name' => 'Estoquista da concorrente', 'is_active' => true]);
            $produto = $this->criarProduto('Produto da concorrente');
            $local = StockLocation::create(['nome' => 'Depósito da Concorrente', 'tipo' => 'deposito']);

            $lote = ProductBatch::create([
                'product_id' => $produto->id,
                'lote' => 'L-DELA',
                'validade' => $this->emDias(5),
                'recebido_em' => BusinessDate::hoje()->toDateString(),
                'custo_unitario' => 1,
            ]);

            $this->estoque->entrada($produto, $local, 9, $usuario, $lote);
        });

        $minhaLista = TenantAtual::comTenant(
            $this->empresa->id,
            fn () => $this->selecao->vencendoEm(30)
        );

        $this->assertSame(
            ['L-MINHA'],
            $minhaLista->map(fn (array $item): string => $item['lote']->lote)->all()
        );

        $listaDaOutra = TenantAtual::comTenant(
            $outraEmpresa->id,
            fn () => $this->selecao->vencendoEm(30)
        );

        $this->assertSame(
            ['L-DELA'],
            $listaDaOutra->map(fn (array $item): string => $item['lote']->lote)->all()
        );
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function criarLote(string $codigo, string $validade): ProductBatch
    {
        return TenantAtual::comTenant($this->empresa->id, fn (): ProductBatch => ProductBatch::create([
            'product_id' => $this->produto->id,
            'lote' => $codigo,
            'validade' => $validade,
            'recebido_em' => BusinessDate::hoje()->subDays(30)->toDateString(),
            'custo_unitario' => 9.9,
        ]));
    }

    /**
     * Congela a hora corrente em um instante **UTC**, que é o fuso em que a
     * aplicação roda.
     *
     * O fuso importa aqui por um detalhe do Carbon: com `setTestNow` ativo, todo
     * `Carbon::parse` sem fuso explícito herda o fuso do instante congelado, e é
     * assim que o cast `datetime` do model lê o que veio do banco. Congelar em
     * America/Sao_Paulo faria o teste ler um `ocorrido_em` gravado em UTC como
     * se fosse horário de Brasília, deslocando o instante em três horas e
     * escondendo justamente o erro de um dia que estes testes existem para
     * pegar. Congelado em UTC, o teste se comporta como produção.
     */
    private function viajarPara(string $instanteEmUtc): void
    {
        $this->travelTo(CarbonImmutable::parse($instanteEmUtc, 'UTC'));
    }

    /**
     * Dia no fuso do negócio, deslocado de hoje. Negativo é passado.
     */
    private function emDias(int $dias): string
    {
        return BusinessDate::hoje()->addDays($dias)->toDateString();
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
