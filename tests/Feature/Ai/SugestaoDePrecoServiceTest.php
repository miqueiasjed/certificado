<?php

namespace Tests\Feature\Ai;

use App\Models\AiDraft;
use App\Models\Budget;
use App\Models\Company;
use App\Models\Service;
use App\Models\User;
use App\Services\Ai\ProvedorDeTexto;
use App\Services\Ai\SugestaoDePrecoService;
use App\Support\Ai\RespostaDeTexto;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 25.7 do Plano 25: a sugestão de preço.
 *
 * Três coisas travadas aqui:
 *
 * 1. **O número não passa pelo modelo.** Mediana e quartis são estatística
 *    determinística, conferível à mão, e o teste afirma que o provedor
 *    simulado não foi chamado para calculá-los.
 * 2. **Amostra menor que 5 não vira sugestão.** Preço a partir de dois
 *    orçamentos leva a empresa a errar com confiança.
 * 3. **Isolamento absoluto**, inclusive em agregação: preço praticado é
 *    informação estratégica, e os tenants são concorrentes entre si.
 */
class SugestaoDePrecoServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresaA;

    private Company $empresaB;

    private SugestaoDePrecoService $servico;

    private object $provedorFake;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->empresaA = Company::query()->firstOrFail();
        $this->empresaB = Company::create([
            'name' => 'Dedetizadora Concorrente',
            'email' => 'contato@concorrente-preco.test',
        ]);

        $this->provedorFake = $this->ligarProvedorFake();
        $this->servico = app(SugestaoDePrecoService::class);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Cálculo
    // -----------------------------------------------------------------

    public function test_com_dez_referencias_a_mediana_e_os_quartis_conferem_com_o_calculo_manual(): void
    {
        // 100, 200, 300, ..., 1000. Por interpolação linear sobre os índices
        // (o mesmo método do PERCENTILE.INC de planilha, que é o que a pessoa
        // usaria para conferir):
        //   mediana = média de 500 e 600           = 550
        //   Q1 = posição 2,25 entre 300 e 400      = 325
        //   Q3 = posição 6,75 entre 800 e 900      = 775
        $valores = [100, 200, 300, 400, 500, 600, 700, 800, 900, 1000];

        $sugestao = TenantAtual::comTenant($this->empresaA->id, function () use ($valores): array {
            foreach ($valores as $valor) {
                $this->criarOrcamentoAprovado((float) $valor);
            }

            return $this->servico->sugerir([], $this->empresaA);
        });

        $this->assertTrue($sugestao['suficiente']);
        $this->assertSame(10, $sugestao['quantidade']);
        $this->assertSame(550.0, $sugestao['mediana']);
        $this->assertSame(325.0, $sugestao['primeiro_quartil']);
        $this->assertSame(775.0, $sugestao['terceiro_quartil']);
    }

    public function test_o_numero_nao_passa_pelo_provedor(): void
    {
        TenantAtual::comTenant($this->empresaA->id, function (): void {
            foreach ([100, 200, 300, 400, 500, 600] as $valor) {
                $this->criarOrcamentoAprovado((float) $valor);
            }

            $this->servico->sugerir([], $this->empresaA);
        });

        $this->assertSame(
            [],
            $this->provedorFake->chamadas,
            'O cálculo da faixa não pode passar pelo modelo: pedir a média de vinte números a um modelo de '
            .'linguagem é o uso mais caro e menos confiável possível.'
        );
    }

    public function test_com_quatro_referencias_devolve_historico_insuficiente_sem_valor(): void
    {
        $sugestao = TenantAtual::comTenant($this->empresaA->id, function (): array {
            foreach ([100, 200, 300, 400] as $valor) {
                $this->criarOrcamentoAprovado((float) $valor);
            }

            return $this->servico->sugerir([], $this->empresaA);
        });

        $this->assertFalse($sugestao['suficiente']);
        $this->assertSame('histórico insuficiente', $sugestao['motivo']);
        $this->assertSame(4, $sugestao['quantidade']);
        $this->assertNull($sugestao['mediana']);
        $this->assertNull($sugestao['primeiro_quartil']);
        $this->assertNull($sugestao['terceiro_quartil']);
        // As referências encontradas continuam voltando: elas ajudam a pessoa
        // a decidir mesmo sem faixa calculada.
        $this->assertCount(4, $sugestao['referencias']);
    }

    // -----------------------------------------------------------------
    // Isolamento
    // -----------------------------------------------------------------

    public function test_orcamento_de_outra_empresa_nunca_entra_na_busca(): void
    {
        TenantAtual::comTenant($this->empresaB->id, function (): void {
            foreach ([9100, 9200, 9300, 9400, 9500, 9600, 9700] as $valor) {
                $this->criarOrcamentoAprovado((float) $valor);
            }
        });

        $sugestao = TenantAtual::comTenant($this->empresaA->id, function (): array {
            foreach ([100, 200, 300, 400, 500] as $valor) {
                $this->criarOrcamentoAprovado((float) $valor);
            }

            return $this->servico->sugerir([], $this->empresaA);
        });

        $this->assertSame(5, $sugestao['quantidade']);
        $this->assertSame(300.0, $sugestao['mediana']);

        foreach ($sugestao['referencias'] as $referencia) {
            $this->assertLessThan(
                9000,
                $referencia['valor'],
                'Um orçamento da empresa B entrou na busca da empresa A: preço praticado é informação estratégica.'
            );
        }
    }

    public function test_orcamento_nao_aprovado_fica_de_fora(): void
    {
        $sugestao = TenantAtual::comTenant($this->empresaA->id, function (): array {
            foreach ([100, 200, 300, 400, 500] as $valor) {
                $this->criarOrcamentoAprovado((float) $valor);
            }

            $this->criarOrcamentoAprovado(9999.0, 'refused');
            $this->criarOrcamentoAprovado(8888.0, 'draft');

            return $this->servico->sugerir([], $this->empresaA);
        });

        $this->assertSame(5, $sugestao['quantidade']);
    }

    // -----------------------------------------------------------------
    // Nada é preenchido automaticamente
    // -----------------------------------------------------------------

    public function test_a_sugestao_nao_altera_o_valor_do_orcamento(): void
    {
        TenantAtual::comTenant($this->empresaA->id, function (): void {
            foreach ([100, 200, 300, 400, 500] as $valor) {
                $this->criarOrcamentoAprovado((float) $valor);
            }

            $emAberto = $this->criarOrcamentoAprovado(0.0, 'draft');
            $antes = $emAberto->fresh()->toArray();

            $sugestao = $this->servico->sugerir([], $this->empresaA);

            $usuario = User::create([
                'name' => 'Vendedor',
                'email' => 'vendedor@exemplo.test',
                'password' => bcrypt('senha-de-teste'),
            ]);

            $this->servico->justificar($sugestao, $emAberto, $usuario);

            $depois = $emAberto->fresh()->toArray();

            unset($antes['updated_at'], $depois['updated_at']);

            $this->assertSame($antes, $depois, 'A sugestão de preço não pode escrever no orçamento.');
        });
    }

    public function test_a_justificativa_em_texto_e_gravada_como_rascunho(): void
    {
        TenantAtual::comTenant($this->empresaA->id, function (): void {
            foreach ([100, 200, 300, 400, 500] as $valor) {
                $this->criarOrcamentoAprovado((float) $valor);
            }

            $orcamento = $this->criarOrcamentoAprovado(0.0, 'draft');

            $usuario = User::create([
                'name' => 'Vendedor Dois',
                'email' => 'vendedor2@exemplo.test',
                'password' => bcrypt('senha-de-teste'),
            ]);

            $sugestao = $this->servico->sugerir([], $this->empresaA);
            $rascunho = $this->servico->justificar($sugestao, $orcamento, $usuario);

            $this->assertNotNull($rascunho);
            $this->assertSame(AiDraft::TIPO_SUGESTAO_PRECO, $rascunho->tipo);
            $this->assertSame(Budget::class, $rascunho->origem_tipo);
            $this->assertSame($orcamento->id, $rascunho->origem_id);
            $this->assertSame(AiDraft::SITUACAO_GERADO, $rascunho->situacao);
            $this->assertCount(1, $this->provedorFake->chamadas);
        });
    }

    public function test_sem_amostra_suficiente_a_justificativa_nao_e_pedida(): void
    {
        TenantAtual::comTenant($this->empresaA->id, function (): void {
            $orcamento = $this->criarOrcamentoAprovado(0.0, 'draft');

            $usuario = User::create([
                'name' => 'Vendedor Três',
                'email' => 'vendedor3@exemplo.test',
                'password' => bcrypt('senha-de-teste'),
            ]);

            $sugestao = $this->servico->sugerir([], $this->empresaA);

            $this->assertNull($this->servico->justificar($sugestao, $orcamento, $usuario));
            $this->assertSame([], $this->provedorFake->chamadas);
        });
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function ligarProvedorFake(): object
    {
        $fake = new class implements ProvedorDeTexto
        {
            /** @var array<int, array{sistema: string, entrada: string}> */
            public array $chamadas = [];

            public function gerar(string $sistema, string $entrada, array $opcoes = []): RespostaDeTexto
            {
                $this->chamadas[] = ['sistema' => $sistema, 'entrada' => $entrada];

                return new RespostaDeTexto(
                    texto: 'Justificativa simulada da faixa de preço.',
                    modelo: 'claude-opus-5',
                );
            }

            public function modelo(): string
            {
                return 'claude-opus-5';
            }
        };

        $this->app->instance(ProvedorDeTexto::class, $fake);

        return $fake;
    }

    /**
     * Orçamento com um serviço cujo subtotal é o valor informado.
     *
     * `budgets` não tem coluna de total: o valor vive no pivot
     * `budget_services.subtotal`, congelado no momento do orçamento.
     */
    private function criarOrcamentoAprovado(float $valor, string $situacao = 'approved'): Budget
    {
        $servico = Service::create([
            'name' => 'Serviço '.str()->random(6),
            'description' => 'Serviço de controle de pragas para teste.',
            'is_active' => true,
        ]);

        $orcamento = Budget::create([
            'status' => $situacao,
            'date' => BusinessDate::hoje()->subMonths(1)->toDateString(),
            'environment_type' => 'commercial',
            'size' => '120',
            'rooms' => '5',
            'discount' => 0,
        ]);

        if ($valor > 0) {
            $orcamento->services()->attach($servico->id, [
                'quantity' => 1,
                'unit_price' => $valor,
                'subtotal' => $valor,
            ]);
        }

        return $orcamento->fresh();
    }
}
