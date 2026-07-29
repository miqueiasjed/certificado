<?php

namespace Tests\Feature;

use App\Exceptions\EstoqueInsuficienteException;
use App\Models\ActiveIngredient;
use App\Models\Antidote;
use App\Models\ChemicalGroup;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockService;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Task 17.2 do Plano 17: o Service que concentra toda escrita de estoque.
 *
 * O que este arquivo prende, e por que cada garantia importa:
 *
 * - Movimento e saldo andam juntos. Razão e saldo divergentes são impossíveis
 *   de reconciliar depois, porque ninguém sabe qual dos dois estava certo.
 * - Saldo negativo nunca é gravado, e a recusa vem com número na mensagem.
 * - Transferência grava os dois lados ou nenhum. Meia transferência é o erro que
 *   faz o estoque nunca fechar.
 * - Duas baixas simultâneas do mesmo lote não somem uma com a outra. Este é o
 *   teste de concorrência de verdade, com duas conexões abertas ao mesmo banco,
 *   descrito em detalhe no cabeçalho do próprio método.
 * - `saldo_apos` reproduz a sequência do razão, que é o que permite auditar sem
 *   recalcular a série inteira.
 */
class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private StockService $servico;

    private Company $empresa;

    private User $usuario;

    private Product $produto;

    private ProductBatch $lote;

    private StockLocation $deposito;

    private StockLocation $van;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->servico = app(StockService::class);
        $this->empresa = Company::query()->firstOrFail();

        [$this->usuario, $this->produto, $this->lote, $this->deposito, $this->van] = TenantAtual::comTenant(
            $this->empresa->id,
            function (): array {
                $usuario = User::factory()->create(['name' => 'Estoquista', 'is_active' => true]);
                $produto = $this->criarProduto('Isca Parafinada', controlaEstoque: true);

                // Validade relativa a hoje, e não uma data fixa: a partir da
                // Task 17.3 a saída recusa lote vencido, e uma data fixa
                // transformaria estes testes em bomba-relógio, quebrando por
                // vencimento no dia em que a data chegasse.
                $lote = ProductBatch::create([
                    'product_id' => $produto->id,
                    'lote' => 'L-001',
                    'validade' => BusinessDate::hoje()->addYear()->toDateString(),
                    'recebido_em' => BusinessDate::hoje()->toDateString(),
                    'custo_unitario' => 12.5,
                ]);

                $deposito = StockLocation::create(['nome' => 'Depósito Central', 'tipo' => 'deposito']);
                $van = StockLocation::create(['nome' => 'Van do João', 'tipo' => 'tecnico']);

                return [$usuario, $produto, $lote, $deposito, $van];
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
    // Entrada e saída
    // -----------------------------------------------------------------

    public function test_entrada_aumenta_o_saldo_e_grava_o_movimento(): void
    {
        $movimento = $this->servico->entrada(
            $this->produto,
            $this->deposito,
            10,
            $this->usuario,
            $this->lote,
            'Compra recebida'
        );

        $this->assertInstanceOf(StockMovement::class, $movimento);
        $this->assertSame('entrada', $movimento->tipo);
        $this->assertSame(10.0, (float) $movimento->quantidade);
        $this->assertSame(10.0, (float) $movimento->saldo_apos);
        $this->assertSame($this->empresa->id, (int) $movimento->company_id);
        $this->assertSame($this->usuario->id, (int) $movimento->user_id);
        $this->assertNotNull($movimento->ocorrido_em);

        $this->assertSame(10.0, $this->servico->saldo($this->produto, $this->deposito, $this->lote));

        $saldo = StockBalance::query()
            ->where('product_id', $this->produto->id)
            ->where('stock_location_id', $this->deposito->id)
            ->where('product_batch_id', $this->lote->id)
            ->firstOrFail();

        $this->assertSame($this->empresa->id, (int) $saldo->company_id);
        $this->assertSame(10.0, (float) $saldo->quantidade);
    }

    public function test_saida_reduz_o_saldo_e_grava_o_saldo_apos(): void
    {
        $this->servico->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);

        $movimento = $this->servico->saida(
            $this->produto,
            $this->deposito,
            2.5,
            $this->usuario,
            $this->lote,
            'Aplicação na visita'
        );

        $this->assertSame('saida', $movimento->tipo);
        // Quantidade é sempre positiva: o sinal vem do tipo.
        $this->assertSame(2.5, (float) $movimento->quantidade);
        $this->assertSame(7.5, (float) $movimento->saldo_apos);
        $this->assertSame(7.5, $this->servico->saldo($this->produto, $this->deposito, $this->lote));
    }

    public function test_saida_maior_que_o_saldo_lanca_excecao_com_os_numeros(): void
    {
        $this->servico->entrada($this->produto, $this->deposito, 4, $this->usuario, $this->lote);

        try {
            $this->servico->saida($this->produto, $this->deposito, 6, $this->usuario, $this->lote);
            $this->fail('A saída maior que o saldo deveria ter sido recusada.');
        } catch (EstoqueInsuficienteException $excecao) {
            $this->assertStringContainsString('Isca Parafinada', $excecao->getMessage());
            $this->assertStringContainsString('L-001', $excecao->getMessage());
            $this->assertStringContainsString('Depósito Central', $excecao->getMessage());
            $this->assertStringContainsString('há 4 un', $excecao->getMessage());
            $this->assertStringContainsString('6 un', $excecao->getMessage());
            $this->assertStringContainsString('faltam 2 un', $excecao->getMessage());

            $this->assertSame(4.0, $excecao->saldoAtual);
            $this->assertSame(6.0, $excecao->quantidadePedida);
            $this->assertSame(2.0, $excecao->faltante());
            $this->assertSame($this->produto->id, $excecao->productId);
            $this->assertSame($this->deposito->id, $excecao->stockLocationId);
            $this->assertSame($this->lote->id, $excecao->productBatchId);
        }

        // Nada gravado: nem o movimento recusado, nem saldo negativo.
        $this->assertSame(4.0, $this->servico->saldo($this->produto, $this->deposito, $this->lote));
        $this->assertSame(1, StockMovement::query()->where('product_id', $this->produto->id)->count());
    }

    public function test_saida_que_zera_o_saldo_e_aceita(): void
    {
        $this->servico->entrada($this->produto, $this->deposito, 6, $this->usuario, $this->lote);
        $movimento = $this->servico->saida($this->produto, $this->deposito, 6, $this->usuario, $this->lote);

        $this->assertSame(0.0, (float) $movimento->saldo_apos);
        $this->assertSame(0.0, $this->servico->saldo($this->produto, $this->deposito, $this->lote));
    }

    // -----------------------------------------------------------------
    // Transferência
    // -----------------------------------------------------------------

    public function test_transferencia_grava_os_dois_movimentos_com_o_mesmo_identificador(): void
    {
        $this->servico->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);

        $transferencia = $this->servico->transferir(
            $this->produto,
            $this->deposito,
            $this->van,
            4,
            $this->usuario,
            $this->lote,
            'Carga do dia'
        );

        $this->assertNotNull($transferencia['transferencia_id']);
        $this->assertSame('transferencia_saida', $transferencia['saida']->tipo);
        $this->assertSame('transferencia_entrada', $transferencia['entrada']->tipo);
        $this->assertSame(
            $transferencia['transferencia_id'],
            $transferencia['saida']->transferencia_id
        );
        $this->assertSame(
            $transferencia['transferencia_id'],
            $transferencia['entrada']->transferencia_id
        );

        $this->assertSame(6.0, (float) $transferencia['saida']->saldo_apos);
        $this->assertSame(4.0, (float) $transferencia['entrada']->saldo_apos);

        $this->assertSame(6.0, $this->servico->saldo($this->produto, $this->deposito, $this->lote));
        $this->assertSame(4.0, $this->servico->saldo($this->produto, $this->van, $this->lote));
        // O total do produto não muda: o produto mudou de lugar, não de quantidade.
        $this->assertSame(10.0, $this->servico->saldo($this->produto));

        $this->assertSame(
            2,
            StockMovement::query()
                ->where('transferencia_id', $transferencia['transferencia_id'])
                ->count()
        );
    }

    public function test_transferencia_sem_saldo_na_origem_nao_grava_nenhum_movimento(): void
    {
        $this->servico->entrada($this->produto, $this->deposito, 3, $this->usuario, $this->lote);

        try {
            $this->servico->transferir(
                $this->produto,
                $this->deposito,
                $this->van,
                5,
                $this->usuario,
                $this->lote
            );
            $this->fail('A transferência sem saldo na origem deveria ter sido recusada.');
        } catch (EstoqueInsuficienteException) {
            // esperado
        }

        // Ou grava os dois lados, ou nenhum: nada de produto sumir da origem sem
        // aparecer no destino.
        $this->assertSame(0, StockMovement::query()->whereNotNull('transferencia_id')->count());
        $this->assertSame(3.0, $this->servico->saldo($this->produto, $this->deposito, $this->lote));
        $this->assertSame(0.0, $this->servico->saldo($this->produto, $this->van, $this->lote));
    }

    public function test_transferencia_para_o_mesmo_local_e_recusada(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->servico->transferir(
            $this->produto,
            $this->deposito,
            $this->deposito,
            1,
            $this->usuario,
            $this->lote
        );
    }

    // -----------------------------------------------------------------
    // Ajuste e descarte
    // -----------------------------------------------------------------

    public function test_ajuste_acerta_o_saldo_para_a_quantidade_contada(): void
    {
        $this->servico->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);

        $paraMenos = $this->servico->ajustar(
            $this->produto,
            $this->deposito,
            8,
            $this->usuario,
            'Contagem física de julho',
            $this->lote
        );

        $this->assertSame('ajuste', $paraMenos->tipo);
        // A coluna guarda o tamanho da diferença, sempre positivo.
        $this->assertSame(2.0, (float) $paraMenos->quantidade);
        $this->assertSame(8.0, (float) $paraMenos->saldo_apos);
        $this->assertSame('Contagem física de julho', $paraMenos->motivo);
        $this->assertSame(8.0, $this->servico->saldo($this->produto, $this->deposito, $this->lote));

        $paraMais = $this->servico->ajustar(
            $this->produto,
            $this->deposito,
            9.5,
            $this->usuario,
            'Recontagem',
            $this->lote
        );

        $this->assertSame(1.5, (float) $paraMais->quantidade);
        $this->assertSame(9.5, (float) $paraMais->saldo_apos);
        $this->assertSame(9.5, $this->servico->saldo($this->produto, $this->deposito, $this->lote));
    }

    public function test_ajuste_que_confirma_o_saldo_nao_gera_movimento(): void
    {
        $this->servico->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);

        $movimento = $this->servico->ajustar(
            $this->produto,
            $this->deposito,
            10,
            $this->usuario,
            'Contagem confirmou o saldo',
            $this->lote
        );

        $this->assertNull($movimento);
        $this->assertSame(1, StockMovement::query()->where('product_id', $this->produto->id)->count());
    }

    public function test_descarte_tira_do_saldo_com_motivo(): void
    {
        $this->servico->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);

        $movimento = $this->servico->descartar(
            $this->produto,
            $this->deposito,
            2,
            $this->usuario,
            'Lote vencido',
            $this->lote
        );

        $this->assertSame('descarte', $movimento->tipo);
        $this->assertSame(2.0, (float) $movimento->quantidade);
        $this->assertSame(8.0, (float) $movimento->saldo_apos);
        $this->assertSame('Lote vencido', $movimento->motivo);
        $this->assertSame(8.0, $this->servico->saldo($this->produto, $this->deposito, $this->lote));
    }

    public function test_ajuste_e_descarte_sem_motivo_sao_recusados(): void
    {
        $this->servico->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);

        try {
            $this->servico->descartar($this->produto, $this->deposito, 1, $this->usuario, '   ', $this->lote);
            $this->fail('Descarte sem motivo deveria ser recusado.');
        } catch (InvalidArgumentException) {
            // esperado
        }

        try {
            $this->servico->ajustar($this->produto, $this->deposito, 5, $this->usuario, '', $this->lote);
            $this->fail('Ajuste sem motivo deveria ser recusado.');
        } catch (InvalidArgumentException) {
            // esperado
        }

        $this->assertSame(10.0, $this->servico->saldo($this->produto, $this->deposito, $this->lote));
    }

    // -----------------------------------------------------------------
    // Produto sem controle de estoque e validações de entrada
    // -----------------------------------------------------------------

    public function test_produto_sem_controle_de_estoque_nao_movimenta_e_nao_gera_erro(): void
    {
        $fichaTecnica = TenantAtual::comTenant(
            $this->empresa->id,
            fn (): Product => $this->criarProduto('Gel sem controle', controlaEstoque: false)
        );

        $this->assertNull($this->servico->entrada($fichaTecnica, $this->deposito, 10, $this->usuario));
        $this->assertNull($this->servico->saida($fichaTecnica, $this->deposito, 5, $this->usuario));
        $this->assertNull($this->servico->descartar($fichaTecnica, $this->deposito, 1, $this->usuario, 'Quebra'));
        $this->assertNull($this->servico->ajustar($fichaTecnica, $this->deposito, 3, $this->usuario, 'Contagem'));
        $this->assertNull(
            $this->servico->transferir($fichaTecnica, $this->deposito, $this->van, 1, $this->usuario)
        );

        $this->assertSame(0, StockMovement::query()->where('product_id', $fichaTecnica->id)->count());
        $this->assertSame(0, StockBalance::query()->where('product_id', $fichaTecnica->id)->count());
        $this->assertSame(0.0, $this->servico->saldo($fichaTecnica));
    }

    public function test_quantidade_zero_ou_negativa_e_recusada(): void
    {
        foreach ([0, -3] as $quantidade) {
            try {
                $this->servico->entrada($this->produto, $this->deposito, $quantidade, $this->usuario, $this->lote);
                $this->fail("Quantidade {$quantidade} deveria ter sido recusada.");
            } catch (InvalidArgumentException) {
                // esperado
            }
        }

        try {
            $this->servico->ajustar($this->produto, $this->deposito, -1, $this->usuario, 'Contagem', $this->lote);
            $this->fail('Contagem negativa deveria ter sido recusada.');
        } catch (InvalidArgumentException) {
            // esperado
        }

        $this->assertSame(0, StockMovement::query()->where('product_id', $this->produto->id)->count());
    }

    public function test_local_e_lote_de_outra_empresa_sao_recusados(): void
    {
        $empresaDois = Company::create([
            'name' => 'Dedetizadora Concorrente',
            'cnpj' => '22.222.222/0001-22',
            'email' => 'contato@concorrente.test',
        ]);

        [$localDaOutra, $produtoDaOutra, $loteDaOutra] = TenantAtual::comTenant(
            $empresaDois->id,
            function (): array {
                $local = StockLocation::create(['nome' => 'Depósito da Concorrente', 'tipo' => 'deposito']);
                $produto = $this->criarProduto('Produto da concorrente', controlaEstoque: true);

                $lote = ProductBatch::create([
                    'product_id' => $produto->id,
                    'lote' => 'L-999',
                    'validade' => BusinessDate::hoje()->addYear()->toDateString(),
                    'recebido_em' => BusinessDate::hoje()->toDateString(),
                    'custo_unitario' => 1,
                ]);

                return [$local, $produto, $lote];
            }
        );

        try {
            $this->servico->entrada($this->produto, $localDaOutra, 1, $this->usuario, $this->lote);
            $this->fail('Local de outra empresa deveria ter sido recusado.');
        } catch (InvalidArgumentException) {
            // esperado
        }

        try {
            $this->servico->entrada($this->produto, $this->deposito, 1, $this->usuario, $loteDaOutra);
            $this->fail('Lote de outro produto deveria ter sido recusado.');
        } catch (InvalidArgumentException) {
            // esperado
        }

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0.0, $this->servico->saldo($produtoDaOutra));
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas na consulta de saldo
    // -----------------------------------------------------------------

    /**
     * O teste anterior prova que operar com produto, local ou lote de outra
     * empresa é recusado. Este prova o lado da leitura: produto, local e lote
     * cadastrados por uma empresa não entram na soma de saldo de outra, mesmo
     * quando o nome do produto, o nome do local e o código do lote são
     * idênticos nas duas. Só `company_id` separa os dados, e é ele que
     * precisa segurar o isolamento, não a coincidência de nomes diferentes.
     */
    public function test_saldo_e_saldo_por_local_nao_alcancam_estoque_de_outra_empresa(): void
    {
        $empresaDois = Company::create([
            'name' => 'Dedetizadora Concorrente',
            'cnpj' => '44.444.444/0001-44',
            'email' => 'contato@concorrente-leitura.test',
        ]);

        [$usuarioDois, $produtoDois, $loteDois, $localDois] = TenantAtual::comTenant(
            $empresaDois->id,
            function (): array {
                $usuario = User::factory()->create(['name' => 'Estoquista da concorrente', 'is_active' => true]);
                // Nome de produto, nome de local e código de lote iguais aos
                // da empresa principal, de propósito: se o isolamento
                // dependesse de nome em vez de company_id, esta coincidência
                // derrubaria o teste.
                $produto = $this->criarProduto('Isca Parafinada', controlaEstoque: true);
                $local = StockLocation::create(['nome' => 'Depósito Central', 'tipo' => 'deposito']);

                $lote = ProductBatch::create([
                    'product_id' => $produto->id,
                    'lote' => 'L-001',
                    'validade' => BusinessDate::hoje()->addYear()->toDateString(),
                    'recebido_em' => BusinessDate::hoje()->toDateString(),
                    'custo_unitario' => 99,
                ]);

                return [$usuario, $produto, $lote, $local];
            }
        );

        $this->servico->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);

        TenantAtual::comTenant(
            $empresaDois->id,
            fn () => $this->servico->entrada($produtoDois, $localDois, 500, $usuarioDois, $loteDois)
        );

        // O saldo da empresa principal não soma a carga generosa da
        // concorrente, mesmo com produto, local e lote homônimos.
        $this->assertSame(10.0, $this->servico->saldo($this->produto, $this->deposito, $this->lote));
        $this->assertSame(10.0, $this->servico->saldo($this->produto));

        $porLocal = $this->servico->saldoPorLocal($this->produto);
        $this->assertCount(1, $porLocal);
        $this->assertSame(10.0, $porLocal[$this->deposito->id]['quantidade']);

        // E o inverso: de dentro do tenant da concorrente, o saldo dela é o
        // dela, não o da empresa principal.
        $saldoDaConcorrente = TenantAtual::comTenant(
            $empresaDois->id,
            fn (): float => $this->servico->saldo($produtoDois, $localDois, $loteDois)
        );
        $this->assertSame(500.0, $saldoDaConcorrente);

        $porLocalDaConcorrente = TenantAtual::comTenant(
            $empresaDois->id,
            fn () => $this->servico->saldoPorLocal($produtoDois)
        );
        $this->assertCount(1, $porLocalDaConcorrente);
        $this->assertSame(500.0, $porLocalDaConcorrente[$localDois->id]['quantidade']);
    }

    // -----------------------------------------------------------------
    // Consulta de saldo
    // -----------------------------------------------------------------

    public function test_saldo_por_local_soma_os_lotes_de_cada_local(): void
    {
        $segundoLote = TenantAtual::comTenant($this->empresa->id, fn (): ProductBatch => ProductBatch::create([
            'product_id' => $this->produto->id,
            'lote' => 'L-002',
            'validade' => BusinessDate::hoje()->addMonths(6)->toDateString(),
            'recebido_em' => BusinessDate::hoje()->toDateString(),
            'custo_unitario' => 13,
        ]));

        $this->servico->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);
        $this->servico->entrada($this->produto, $this->deposito, 5, $this->usuario, $segundoLote);
        $this->servico->transferir($this->produto, $this->deposito, $this->van, 4, $this->usuario, $this->lote);

        $porLocal = $this->servico->saldoPorLocal($this->produto);

        $this->assertCount(2, $porLocal);
        $this->assertSame(11.0, $porLocal[$this->deposito->id]['quantidade']);
        $this->assertSame('Depósito Central', $porLocal[$this->deposito->id]['nome']);
        $this->assertSame(4.0, $porLocal[$this->van->id]['quantidade']);
        $this->assertSame('tecnico', $porLocal[$this->van->id]['tipo']);

        $this->assertSame(15.0, $this->servico->saldo($this->produto));
        $this->assertSame(11.0, $this->servico->saldo($this->produto, $this->deposito));
        $this->assertSame(6.0, $this->servico->saldo($this->produto, $this->deposito, $this->lote));
        $this->assertSame(5.0, $this->servico->saldo($this->produto, $this->deposito, $segundoLote));
    }

    // -----------------------------------------------------------------
    // Razão, saldo_apos e recálculo
    // -----------------------------------------------------------------

    public function test_saldo_apos_bate_com_a_sequencia_do_razao(): void
    {
        $this->servico->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);
        $this->servico->saida($this->produto, $this->deposito, 2.5, $this->usuario, $this->lote);
        $this->servico->descartar($this->produto, $this->deposito, 0.5, $this->usuario, 'Frasco quebrado', $this->lote);
        $this->servico->ajustar($this->produto, $this->deposito, 6.25, $this->usuario, 'Contagem física', $this->lote);
        $this->servico->entrada($this->produto, $this->deposito, 3.75, $this->usuario, $this->lote);

        $sequencia = StockMovement::query()
            ->where('product_id', $this->produto->id)
            ->where('stock_location_id', $this->deposito->id)
            ->orderBy('ocorrido_em')
            ->orderBy('id')
            ->get()
            ->map(fn (StockMovement $movimento): float => (float) $movimento->saldo_apos)
            ->all();

        $this->assertSame([10.0, 7.5, 7.0, 6.25, 10.0], $sequencia);
        $this->assertSame(10.0, $this->servico->saldo($this->produto, $this->deposito, $this->lote));

        $relatorio = $this->servico->recalcularSaldo($this->produto);

        $this->assertSame([], $relatorio['movimentos_com_saldo_apos_divergente']);
        $this->assertSame([], $relatorio['divergencias']);
    }

    public function test_recalcular_saldo_reproduz_o_saldo_em_base_integra(): void
    {
        $this->servico->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);
        $this->servico->saida($this->produto, $this->deposito, 3, $this->usuario, $this->lote);
        $this->servico->transferir($this->produto, $this->deposito, $this->van, 2, $this->usuario, $this->lote);

        $relatorio = $this->servico->recalcularSaldo($this->produto);

        $this->assertSame($this->produto->id, $relatorio['product_id']);
        $this->assertSame(2, $relatorio['combinacoes']);
        $this->assertSame(0, $relatorio['saldos_corrigidos']);
        $this->assertSame([], $relatorio['divergencias']);

        $this->assertSame(5.0, $this->servico->saldo($this->produto, $this->deposito, $this->lote));
        $this->assertSame(2.0, $this->servico->saldo($this->produto, $this->van, $this->lote));
    }

    public function test_recalcular_saldo_corrige_e_aponta_a_divergencia(): void
    {
        $this->servico->entrada($this->produto, $this->deposito, 10, $this->usuario, $this->lote);
        $this->servico->saida($this->produto, $this->deposito, 4, $this->usuario, $this->lote);

        // Simula a divergência que o recálculo existe para encontrar: alguém
        // mexeu no saldo por fora do Service.
        DB::table('stock_balances')
            ->where('product_id', $this->produto->id)
            ->where('stock_location_id', $this->deposito->id)
            ->update(['quantidade' => 99]);

        $relatorio = $this->servico->recalcularSaldo($this->produto);

        $this->assertSame(1, $relatorio['saldos_corrigidos']);
        $this->assertCount(1, $relatorio['divergencias']);
        $this->assertSame(99.0, $relatorio['divergencias'][0]['saldo_gravado']);
        $this->assertSame(6.0, $relatorio['divergencias'][0]['saldo_do_razao']);
        $this->assertSame($this->deposito->id, $relatorio['divergencias'][0]['stock_location_id']);
        $this->assertSame($this->lote->id, $relatorio['divergencias'][0]['product_batch_id']);

        $this->assertSame(6.0, $this->servico->saldo($this->produto, $this->deposito, $this->lote));
    }

    // -----------------------------------------------------------------
    // Consistência com movimentos pseudoaleatórios
    // -----------------------------------------------------------------

    /**
     * O teste mais importante deste arquivo: 50 movimentos em sequência,
     * variando tipo, local e lote, e no fim `recalcularSaldo()` não pode
     * apontar nenhuma divergência. Saldo que diverge silenciosamente destrói
     * a confiança no módulo inteiro.
     *
     * A sequência é pseudoaleatória, mas reproduzível: a semente é fixa e o
     * gerador é um LCG simples, escrito aqui mesmo (`proximoPseudoAleatorio`),
     * em vez de `rand()`/`mt_rand()`. Isso tira a dependência da
     * implementação de aleatoriedade do PHP instalado e garante que o teste
     * sempre percorre exatamente o mesmo caminho, em qualquer ambiente.
     *
     * Cada operação obedece a regra de negócio (nunca deixa saldo negativo)
     * por construção, não por sorte: a quantidade de saída e de transferência
     * é sempre limitada ao saldo calculado em paralelo, e a contagem do
     * ajuste nunca fica abaixo de zero. Esse saldo esperado é somado à parte,
     * em PHP puro, como fonte da verdade independente do Service, e comparado
     * com `saldo()` e com `recalcularSaldo()` no final.
     */
    public function test_consistencia_do_saldo_apos_50_movimentos_pseudoaleatorios(): void
    {
        $loteB = TenantAtual::comTenant($this->empresa->id, fn (): ProductBatch => ProductBatch::create([
            'product_id' => $this->produto->id,
            'lote' => 'L-RAND-B',
            'validade' => BusinessDate::hoje()->addMonths(8)->toDateString(),
            'recebido_em' => BusinessDate::hoje()->toDateString(),
            'custo_unitario' => 15,
        ]));

        $loteC = TenantAtual::comTenant($this->empresa->id, fn (): ProductBatch => ProductBatch::create([
            'product_id' => $this->produto->id,
            'lote' => 'L-RAND-C',
            'validade' => BusinessDate::hoje()->addMonths(10)->toDateString(),
            'recebido_em' => BusinessDate::hoje()->toDateString(),
            'custo_unitario' => 20,
        ]));

        $lotes = [$this->lote, $loteB, $loteC];
        $locais = [$this->deposito, $this->van];

        $chave = fn (StockLocation $local, ProductBatch $lote): string => $local->id.':'.$lote->id;

        $saldoEsperado = [];

        foreach ($locais as $local) {
            foreach ($lotes as $lote) {
                $saldoEsperado[$chave($local, $lote)] = 0.0;
            }
        }

        // Carga inicial generosa em cada combinação de local e lote, para as
        // 50 operações seguintes terem o que consumir.
        foreach ($locais as $local) {
            foreach ($lotes as $lote) {
                $this->servico->entrada($this->produto, $local, 50, $this->usuario, $lote, 'Carga inicial da prova de consistência');
                $saldoEsperado[$chave($local, $lote)] += 50.0;
            }
        }

        $estado = 987654321; // semente fixa: a sequência é sempre a mesma.

        for ($i = 0; $i < 50; $i++) {
            $sorteioTipo = $this->proximoPseudoAleatorio($estado);
            $lote = $lotes[(int) floor($this->proximoPseudoAleatorio($estado) * count($lotes))];
            $local = $locais[(int) floor($this->proximoPseudoAleatorio($estado) * count($locais))];
            $quantidadeBase = 1 + (int) floor($this->proximoPseudoAleatorio($estado) * 12); // 1 a 12
            $saldoAtual = $saldoEsperado[$chave($local, $lote)];

            if ($sorteioTipo < 0.35) {
                // Entrada: sempre válida.
                $this->servico->entrada($this->produto, $local, (float) $quantidadeBase, $this->usuario, $lote, "Entrada pseudoaleatória #{$i}");
                $saldoEsperado[$chave($local, $lote)] += $quantidadeBase;
            } elseif ($sorteioTipo < 0.65) {
                // Saída: nunca maior que o saldo calculado em paralelo, para
                // a regra de negócio (nunca saldo negativo) valer por
                // construção, e não por sorte.
                $quantidade = min((float) $quantidadeBase, $saldoAtual);

                if ($quantidade <= 0.0) {
                    continue;
                }

                $this->servico->saida($this->produto, $local, $quantidade, $this->usuario, $lote, "Saída pseudoaleatória #{$i}");
                $saldoEsperado[$chave($local, $lote)] -= $quantidade;
            } elseif ($sorteioTipo < 0.85) {
                // Transferência: sempre para o outro local da lista de dois.
                $destino = $local === $locais[0] ? $locais[1] : $locais[0];
                $quantidade = min((float) $quantidadeBase, $saldoAtual);

                if ($quantidade <= 0.0) {
                    continue;
                }

                $this->servico->transferir($this->produto, $local, $destino, $quantidade, $this->usuario, $lote, "Transferência pseudoaleatória #{$i}");
                $saldoEsperado[$chave($local, $lote)] -= $quantidade;
                $saldoEsperado[$chave($destino, $lote)] += $quantidade;
            } else {
                // Ajuste: contagem sorteada ao redor do saldo atual, nunca
                // negativa.
                $delta = $quantidadeBase - 6; // entre -5 e 6
                $novaContagem = max(0.0, $saldoAtual + $delta);
                $this->servico->ajustar($this->produto, $local, $novaContagem, $this->usuario, "Ajuste pseudoaleatório #{$i}", $lote);
                $saldoEsperado[$chave($local, $lote)] = $novaContagem;
            }
        }

        foreach ($locais as $local) {
            foreach ($lotes as $lote) {
                $this->assertSame(
                    round($saldoEsperado[$chave($local, $lote)], 4),
                    $this->servico->saldo($this->produto, $local, $lote),
                    "Saldo divergente no local \"{$local->nome}\", lote \"{$lote->lote}\" depois dos 50 movimentos."
                );
            }
        }

        $relatorio = $this->servico->recalcularSaldo($this->produto);

        $this->assertSame(
            [],
            $relatorio['divergencias'],
            'recalcularSaldo() encontrou divergência entre o saldo gravado e o razão depois dos 50 movimentos pseudoaleatórios.'
        );
        $this->assertSame([], $relatorio['movimentos_com_saldo_apos_divergente']);
    }

    // -----------------------------------------------------------------
    // Concorrência
    // -----------------------------------------------------------------

    /**
     * Duas baixas do mesmo lote ao mesmo tempo, cada uma na sua conexão.
     *
     * Este é o caso real que o `lockForUpdate` existe para resolver: dois
     * técnicos dando baixa do mesmo produto no mesmo instante. Sem o bloqueio,
     * os dois leem o saldo antigo, os dois acham que cabe, e o segundo grava por
     * cima do primeiro. O estoque fica com uma baixa a menos e ninguém percebe
     * até a contagem física.
     *
     * O teste roda fora da transação do `RefreshDatabase`, em duas conexões
     * próprias apontando para o mesmo banco, porque dado não confirmado não é
     * visível de outra conexão, e sem visibilidade não existe disputa para
     * observar. Como isso grava de verdade, tudo que nasce aqui é apagado no
     * `finally`, comparando os identificadores com a marca tirada antes.
     *
     * A prova vem em duas partes, porque são dois efeitos diferentes do mesmo
     * bloqueio.
     *
     * **Parte 1, serialização.** Técnico dois dá baixa de 6 sobre um saldo de 10
     * e segura a transação. Técnico um tenta a mesma baixa e fica esperando, até
     * estourar o `innodb_lock_wait_timeout` reduzido para 2 segundos. Nada do
     * técnico um é gravado: as duas baixas não acontecem ao mesmo tempo, elas se
     * enfileiram.
     *
     * **Parte 2, leitura sempre atual.** Esta é a parte que falha se alguém
     * trocar o `lockForUpdate` por uma leitura comum. O técnico um abre uma
     * transação e lê o saldo (4) antes de decidir, que é o caso real da baixa
     * disparada pelo fechamento da OS, já dentro de uma transação. O técnico dois
     * então baixa 3 e confirma, deixando o saldo em 1. Quando o técnico um
     * finalmente pede a saída de 3, a leitura bloqueada enxerga o valor
     * confirmado mais recente (1) e a saída é recusada. Com leitura comum, o
     * técnico um continuaria enxergando o 4 congelado no início da transação
     * dele, a saída passaria, e o razão fecharia em -2 com o saldo mostrando 1.
     */
    public function test_duas_saidas_simultaneas_do_mesmo_lote_nao_geram_saldo_errado(): void
    {
        $conexaoPadrao = config('database.default');
        config([
            'database.connections.estoque_tecnico_um' => config('database.connections.'.$conexaoPadrao),
            'database.connections.estoque_tecnico_dois' => config('database.connections.'.$conexaoPadrao),
        ]);

        $marcas = $this->marcasDeLimpeza($conexaoPadrao);

        try {
            config(['database.default' => 'estoque_tecnico_um']);

            [$usuario, $produto, $lote, $local] = TenantAtual::comTenant(
                $this->empresa->id,
                function (): array {
                    $usuario = User::factory()->create(['name' => 'Técnico da concorrência', 'is_active' => true]);
                    $produto = $this->criarProduto('Produto da prova de concorrência', controlaEstoque: true);

                    $lote = ProductBatch::create([
                        'product_id' => $produto->id,
                        'lote' => 'L-CONCORRENCIA',
                        'validade' => BusinessDate::hoje()->addYear()->toDateString(),
                        'recebido_em' => BusinessDate::hoje()->toDateString(),
                        'custo_unitario' => 10,
                    ]);

                    $local = StockLocation::create([
                        'nome' => 'Depósito da prova de concorrência',
                        'tipo' => 'deposito',
                    ]);

                    return [$usuario, $produto, $lote, $local];
                }
            );

            $this->servico->entrada($produto, $local, 10, $usuario, $lote, 'Saldo inicial');

            // 1. Técnico dois dá baixa de 6 e segura a transação aberta.
            config(['database.default' => 'estoque_tecnico_dois']);
            DB::connection('estoque_tecnico_dois')->beginTransaction();
            $this->servico->saida($produto, $local, 6, $usuario, $lote, 'Baixa do técnico dois');

            // 2. Técnico um tenta a mesma baixa e esbarra no bloqueio.
            config(['database.default' => 'estoque_tecnico_um']);
            DB::connection('estoque_tecnico_um')->statement('SET SESSION innodb_lock_wait_timeout = 2');

            $esbarrouNoBloqueio = false;

            try {
                $this->servico->saida($produto, $local, 6, $usuario, $lote, 'Baixa do técnico um');
            } catch (QueryException $excecao) {
                $esbarrouNoBloqueio = str_contains(mb_strtolower($excecao->getMessage()), 'lock');
            }

            $this->assertTrue(
                $esbarrouNoBloqueio,
                'A segunda baixa simultânea passou sem esperar o bloqueio da linha de saldo. '
                .'Sem lockForUpdate, as duas leem o mesmo saldo antigo e uma das baixas some.'
            );

            $this->assertSame(
                1,
                DB::connection('estoque_tecnico_um')->table('stock_movements')
                    ->where('product_id', $produto->id)
                    ->count(),
                'A baixa recusada pelo bloqueio não pode ter deixado movimento gravado.'
            );

            // 3. Técnico dois confirma a baixa dele.
            config(['database.default' => 'estoque_tecnico_dois']);
            DB::connection('estoque_tecnico_dois')->commit();

            // Parte 2. O técnico um abre a transação dele e lê o saldo antes de
            // decidir qualquer coisa, que é o que acontece na baixa disparada
            // pelo fechamento de uma OS.
            config(['database.default' => 'estoque_tecnico_um']);
            DB::connection('estoque_tecnico_um')->beginTransaction();

            $saldoLidoNoComeco = (float) DB::connection('estoque_tecnico_um')->table('stock_balances')
                ->where('product_id', $produto->id)
                ->where('stock_location_id', $local->id)
                ->value('quantidade');

            $this->assertSame(4.0, $saldoLidoNoComeco);

            // Enquanto isso, o técnico dois baixa mais 3 e confirma: o saldo
            // real passa a 1, mas a transação do técnico um continua enxergando
            // o 4 em leitura comum.
            config(['database.default' => 'estoque_tecnico_dois']);
            $this->servico->saida($produto, $local, 3, $usuario, $lote, 'Segunda baixa do técnico dois');

            config(['database.default' => 'estoque_tecnico_um']);

            $recusaPorSaldo = null;

            try {
                $this->servico->saida($produto, $local, 3, $usuario, $lote, 'Baixa do técnico um');
            } catch (EstoqueInsuficienteException $excecao) {
                $recusaPorSaldo = $excecao;
            }

            $this->assertNotNull(
                $recusaPorSaldo,
                'A saída passou lendo o saldo congelado no início da transação. É assim que uma baixa '
                .'some: o saldo termina em 1 e o razão em -2.'
            );
            $this->assertSame(1.0, $recusaPorSaldo->saldoAtual);
            $this->assertSame(3.0, $recusaPorSaldo->quantidadePedida);

            DB::connection('estoque_tecnico_um')->rollBack();

            // O saldo fecha com o razão: 10 de entrada, menos 6, menos 3.
            $this->assertSame(1.0, $this->servico->saldo($produto, $local, $lote));

            $movimentos = DB::connection('estoque_tecnico_um')->table('stock_movements')
                ->where('product_id', $produto->id)
                ->orderBy('id')
                ->get();

            $this->assertCount(3, $movimentos);
            $this->assertSame(1.0, (float) $movimentos->last()->saldo_apos);

            $somaDoRazao = (float) $movimentos->sum(
                fn ($movimento): float => $movimento->tipo === 'entrada'
                    ? (float) $movimento->quantidade
                    : -(float) $movimento->quantidade
            );

            $this->assertSame(1.0, $somaDoRazao);

            $relatorio = $this->servico->recalcularSaldo($produto);
            $this->assertSame([], $relatorio['divergencias']);
            $this->assertSame([], $relatorio['movimentos_com_saldo_apos_divergente']);
        } finally {
            foreach (['estoque_tecnico_dois', 'estoque_tecnico_um'] as $conexao) {
                while (DB::connection($conexao)->transactionLevel() > 0) {
                    DB::connection($conexao)->rollBack();
                }
            }

            // A espera curta valia para provocar a disputa; a limpeza pode
            // esperar o tempo normal.
            DB::connection('estoque_tecnico_um')->statement('SET SESSION innodb_lock_wait_timeout = 50');

            $this->limparRegistrosConfirmados($marcas);

            config(['database.default' => $conexaoPadrao]);
            DB::purge('estoque_tecnico_um');
            DB::purge('estoque_tecnico_dois');
        }
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

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

    /**
     * Gerador congruente linear simples e determinístico, usado só pelo teste
     * de consistência com 50 movimentos: a mesma semente sempre produz a
     * mesma sequência, em qualquer ambiente, sem depender de
     * `rand()`/`mt_rand()` do PHP instalado.
     *
     * @param  int  $estado  Semente/estado do gerador, atualizado por
     *                       referência a cada chamada.
     * @return float Entre 0 (inclusive) e 1 (exclusive).
     */
    private function proximoPseudoAleatorio(int &$estado): float
    {
        $estado = ($estado * 1103515245 + 12345) % 2147483648;

        return $estado / 2147483648;
    }

    /**
     * Tabelas tocadas pelo teste de concorrência, na ordem em que podem ser
     * apagadas sem esbarrar em chave estrangeira.
     *
     * @return array<int, string>
     */
    private function tabelasDaLimpeza(): array
    {
        return [
            'audit_logs',
            'stock_movements',
            'stock_balances',
            'product_batches',
            'stock_locations',
            'products',
            'antidotes',
            'chemical_groups',
            'active_ingredients',
            'users',
        ];
    }

    /**
     * Maior id de cada tabela antes do teste de concorrência: tudo que nascer
     * depois disso é sujeira daquele teste e some no fim.
     *
     * A marca é lida pela conexão do próprio teste, e não por uma das conexões
     * concorrentes, porque só ela enxerga as linhas que o `setUp` criou dentro
     * da transação do `RefreshDatabase`. Lida de fora, a marca viria menor e a
     * limpeza tentaria apagar linha que não é dela, esbarrando no bloqueio da
     * transação do teste.
     *
     * @return array<string, int>
     */
    private function marcasDeLimpeza(string $conexao): array
    {
        $marcas = [];

        foreach ($this->tabelasDaLimpeza() as $tabela) {
            $marcas[$tabela] = (int) DB::connection($conexao)->table($tabela)->max('id');
        }

        return $marcas;
    }

    /**
     * @param  array<string, int>  $marcas
     */
    private function limparRegistrosConfirmados(array $marcas): void
    {
        foreach ($this->tabelasDaLimpeza() as $tabela) {
            DB::connection('estoque_tecnico_um')
                ->table($tabela)
                ->where('id', '>', $marcas[$tabela] ?? 0)
                ->delete();
        }
    }
}
