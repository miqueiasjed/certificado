<?php

namespace Tests\Feature;

use App\Listeners\RegistraExecucaoAgendada;
use App\Models\ActiveIngredient;
use App\Models\Antidote;
use App\Models\ChemicalGroup;
use App\Models\Company;
use App\Models\NotificationQueue;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ScheduledTaskRun;
use App\Models\StockLocation;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\StockService;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use App\Support\RotinasAgendadas;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 17.6 do Plano 17: alertas de estoque mínimo e de validade de lote.
 *
 * Cobre os critérios de aceitação da task, um por bloco de testes:
 *
 * - produto abaixo do mínimo gera um aviso, e a rotina rodada duas vezes no
 *   mesmo dia não duplica;
 * - o saldo comparado é o total da empresa, somando todos os locais;
 * - lote vencendo em 60, 30 e 7 dias gera três avisos distintos, um por
 *   marco, porque `BatchSelectionService::vencendoEm()` devolve janelas
 *   cumulativas e o marco de cada disparo é a janela chamada;
 * - lote vencido com saldo reenvia semanalmente, e o descarte encerra o
 *   reenvio;
 * - falha em um tenant não interrompe os demais;
 * - a rotina aparece em `scheduled_task_runs`.
 */
class StockAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private User $usuario;

    private StockLocation $deposito;

    private StockService $estoque;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        // A empresa 1 vem da migration de fundação do tenant, sem nome nem
        // e-mail: sem preencher o e-mail, o disparo para a empresa cairia em
        // "sem_destino" e nenhum aviso nasceria na fila.
        Company::query()->whereKey(1)->update([
            'name' => 'Dedetizadora A',
            'email' => 'contato@a.test',
        ]);

        $this->empresa = Company::query()->findOrFail(1);
        $this->estoque = app(StockService::class);

        [$this->usuario, $this->deposito] = TenantAtual::comTenant($this->empresa->id, function (): array {
            $usuario = User::factory()->create(['name' => 'Estoquista', 'is_active' => true]);
            $deposito = StockLocation::create(['nome' => 'Depósito Central', 'tipo' => 'deposito']);

            return [$usuario, $deposito];
        });

        TenantAtual::limpar();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Estoque abaixo do mínimo
    // -----------------------------------------------------------------

    public function test_produto_abaixo_do_minimo_gera_um_aviso(): void
    {
        $produto = $this->naEmpresa(fn (): Product => $this->criarProduto('Cupinicida Concentrado', 10));
        $this->estoque->entrada($produto, $this->deposito, 4, $this->usuario);

        $this->artisan('estoque:verificar')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::ESTOQUE_ABAIXO_DO_MINIMO);

        $this->assertCount(1, $avisos);

        $aviso = $avisos->first();
        $this->assertSame(NotificationQueue::DESTINATARIO_EMPRESA, $aviso->destinatario_tipo);
        $this->assertSame('contato@a.test', $aviso->destino);
        $this->assertStringContainsString('Cupinicida Concentrado', $aviso->corpo);
        $this->assertStringContainsString('4', $aviso->corpo);
        $this->assertStringContainsString('10', $aviso->corpo);
        $this->assertSame((int) $produto->id, (int) ($aviso->contexto['referencia_id'] ?? 0));
    }

    public function test_rodar_a_rotina_duas_vezes_no_mesmo_dia_nao_duplica_o_aviso_de_estoque(): void
    {
        $produto = $this->naEmpresa(fn (): Product => $this->criarProduto('Cupinicida Concentrado', 10));
        $this->estoque->entrada($produto, $this->deposito, 4, $this->usuario);

        $this->artisan('estoque:verificar')->assertSuccessful();
        $this->artisan('estoque:verificar')->assertSuccessful();

        $this->assertSame(1, $this->itensDoEvento(EventosDeNotificacao::ESTOQUE_ABAIXO_DO_MINIMO)->count());
    }

    public function test_produto_com_saldo_igual_ou_acima_do_minimo_nao_gera_aviso(): void
    {
        $produto = $this->naEmpresa(fn (): Product => $this->criarProduto('Formicida em Gel', 10));
        $this->estoque->entrada($produto, $this->deposito, 10, $this->usuario);

        $this->artisan('estoque:verificar')->assertSuccessful();

        $this->assertSame(0, $this->itensDoEvento(EventosDeNotificacao::ESTOQUE_ABAIXO_DO_MINIMO)->count());
    }

    public function test_produto_com_estoque_minimo_nulo_nunca_gera_aviso(): void
    {
        // Sem nenhuma entrada de estoque: o produto nasce com saldo zero, e
        // mesmo assim não pode gerar aviso, porque `estoque_minimo` nulo
        // significa "sem ponto de reposição definido", nunca "mínimo zero".
        $this->naEmpresa(fn (): Product => $this->criarProduto('Produto sem ponto de reposição', null));

        $this->artisan('estoque:verificar')->assertSuccessful();

        $this->assertSame(0, $this->itensDoEvento(EventosDeNotificacao::ESTOQUE_ABAIXO_DO_MINIMO)->count());
    }

    /**
     * O saldo comparado com o mínimo é o total da empresa. Alertar por local
     * geraria aviso toda vez que um técnico saísse com produto para a rota do
     * dia, mesmo com o depósito abastecido.
     */
    public function test_saldo_considerado_e_o_total_da_empresa_somando_todos_os_locais(): void
    {
        $produto = $this->naEmpresa(fn (): Product => $this->criarProduto('Cupinicida Concentrado', 10));
        $van = $this->naEmpresa(fn (): StockLocation => StockLocation::create(['nome' => 'Van do João', 'tipo' => 'tecnico']));

        // 6 no depósito, abaixo do mínimo sozinho, mas 5 na van: o total de
        // 11 está acima do mínimo, e não pode gerar aviso.
        $this->estoque->entrada($produto, $this->deposito, 6, $this->usuario);
        $this->estoque->entrada($produto, $van, 5, $this->usuario);

        $this->artisan('estoque:verificar')->assertSuccessful();

        $this->assertSame(0, $this->itensDoEvento(EventosDeNotificacao::ESTOQUE_ABAIXO_DO_MINIMO)->count());
    }

    // -----------------------------------------------------------------
    // Lote vencendo em 60, 30 e 7 dias
    // -----------------------------------------------------------------

    /**
     * Um lote a 5 dias do vencimento está dentro das três janelas ao mesmo
     * tempo (60, 30 e 7 dias), porque `vencendoEm()` é cumulativa. O marco de
     * cada disparo é a janela chamada, e não o `dias_para_vencer` do lote, e
     * é isso que garante três avisos distintos numa passada só.
     */
    public function test_lote_vencendo_em_60_30_e_7_dias_gera_tres_avisos_um_por_marco(): void
    {
        $produto = $this->naEmpresa(fn (): Product => $this->criarProduto('Cupinicida Concentrado', null));
        $lote = $this->criarLote($produto, $this->emDias(5));
        $this->estoque->entrada($produto, $this->deposito, 4, $this->usuario, $lote);

        $this->artisan('estoque:verificar')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::LOTE_PROXIMO_DO_VENCIMENTO);

        $this->assertCount(3, $avisos);

        $chaves = $avisos->pluck('chave_idempotencia');

        foreach (['60', '30', '7'] as $marco) {
            $this->assertTrue(
                $chaves->contains(fn (string $chave): bool => str_ends_with($chave, ":{$marco}")),
                "faltou o aviso do marco de {$marco} dias"
            );
        }
    }

    public function test_rodar_a_rotina_duas_vezes_nao_duplica_os_avisos_de_lote(): void
    {
        $produto = $this->naEmpresa(fn (): Product => $this->criarProduto('Cupinicida Concentrado', null));
        $lote = $this->criarLote($produto, $this->emDias(5));
        $this->estoque->entrada($produto, $this->deposito, 4, $this->usuario, $lote);

        $this->artisan('estoque:verificar')->assertSuccessful();
        $this->artisan('estoque:verificar')->assertSuccessful();

        $this->assertSame(3, $this->itensDoEvento(EventosDeNotificacao::LOTE_PROXIMO_DO_VENCIMENTO)->count());
    }

    public function test_lote_com_saldo_fora_das_tres_janelas_nao_gera_aviso(): void
    {
        $produto = $this->naEmpresa(fn (): Product => $this->criarProduto('Cupinicida Concentrado', null));
        $lote = $this->criarLote($produto, $this->emDias(90));
        $this->estoque->entrada($produto, $this->deposito, 4, $this->usuario, $lote);

        $this->artisan('estoque:verificar')->assertSuccessful();

        $this->assertSame(0, $this->itensDoEvento(EventosDeNotificacao::LOTE_PROXIMO_DO_VENCIMENTO)->count());
    }

    // -----------------------------------------------------------------
    // Lote vencido: reenvio semanal até o descarte
    // -----------------------------------------------------------------

    /**
     * O marco do lote vencido muda a cada sete dias corridos desde uma época
     * fixa (1970-01-01), e não a cada virada de semana do calendário. Viajar
     * a partir da própria época garante que os primeiros seis dias caem no
     * mesmo marco e o sétimo cai no marco seguinte, sem depender de em que
     * dia do calendário a suíte é executada.
     */
    public function test_lote_vencido_com_saldo_reenvia_semanalmente(): void
    {
        $this->viajarPara('1970-01-01 12:00:00');

        $produto = $this->naEmpresa(fn (): Product => $this->criarProduto('Cupinicida Concentrado', null));
        $lote = $this->criarLote($produto, $this->emDias(-10));
        $this->estoque->entrada($produto, $this->deposito, 4, $this->usuario, $lote);

        $this->artisan('estoque:verificar')->assertSuccessful();
        $this->assertSame(1, $this->itensDoEvento(EventosDeNotificacao::LOTE_PROXIMO_DO_VENCIMENTO)->count());

        // Mesmo dia, rotina rodada de novo: não duplica.
        $this->artisan('estoque:verificar')->assertSuccessful();
        $this->assertSame(1, $this->itensDoEvento(EventosDeNotificacao::LOTE_PROXIMO_DO_VENCIMENTO)->count());

        // Seis dias depois, ainda dentro do mesmo marco semanal: nenhum aviso novo.
        $this->viajarPara('1970-01-07 12:00:00');
        $this->artisan('estoque:verificar')->assertSuccessful();
        $this->assertSame(1, $this->itensDoEvento(EventosDeNotificacao::LOTE_PROXIMO_DO_VENCIMENTO)->count());

        // Mais um dia (sete no total): marco novo, aviso novo.
        $this->viajarPara('1970-01-08 12:00:00');
        $this->artisan('estoque:verificar')->assertSuccessful();
        $this->assertSame(2, $this->itensDoEvento(EventosDeNotificacao::LOTE_PROXIMO_DO_VENCIMENTO)->count());
    }

    public function test_registrar_o_descarte_encerra_o_reenvio(): void
    {
        $this->viajarPara('1970-01-01 12:00:00');

        $produto = $this->naEmpresa(fn (): Product => $this->criarProduto('Cupinicida Concentrado', null));
        $lote = $this->criarLote($produto, $this->emDias(-10));
        $this->estoque->entrada($produto, $this->deposito, 4, $this->usuario, $lote);

        $this->artisan('estoque:verificar')->assertSuccessful();
        $this->assertSame(1, $this->itensDoEvento(EventosDeNotificacao::LOTE_PROXIMO_DO_VENCIMENTO)->count());

        $this->estoque->descartar(
            $produto,
            $this->deposito,
            4,
            $this->usuario,
            'Lote vencido, descarte registrado em ata',
            $lote
        );

        // Sete dias depois, quando o reenvio aconteceria se o lote ainda
        // tivesse saldo.
        $this->viajarPara('1970-01-08 12:00:00');
        $this->artisan('estoque:verificar')->assertSuccessful();

        $this->assertSame(
            1,
            $this->itensDoEvento(EventosDeNotificacao::LOTE_PROXIMO_DO_VENCIMENTO)->count(),
            'sem saldo, o lote descartado não pode gerar aviso novo'
        );
    }

    // -----------------------------------------------------------------
    // Falha isolada por tenant
    // -----------------------------------------------------------------

    /**
     * Um dado ruim de uma empresa não pode calar o aviso das demais. O teste
     * afirma a fila da segunda empresa, e não apenas que o comando não
     * estourou.
     */
    public function test_falha_em_um_tenant_nao_interrompe_os_demais(): void
    {
        $produtoComDefeito = $this->naEmpresa(fn (): Product => $this->criarProduto('Produto com defeito', 10));
        $this->estoque->entrada($produtoComDefeito, $this->deposito, 2, $this->usuario);

        $segunda = Company::create(['name' => 'Dedetizadora B', 'email' => 'contato@b.test']);

        [$usuarioB, $depositoB, $produtoB] = TenantAtual::comTenant($segunda->id, function (): array {
            $usuario = User::factory()->create(['name' => 'Estoquista B', 'is_active' => true]);
            $deposito = StockLocation::create(['nome' => 'Depósito B', 'tipo' => 'deposito']);
            $produto = $this->criarProduto('Produto saudável', 10);

            return [$usuario, $deposito, $produto];
        });

        $this->estoque->entrada($produtoB, $depositoB, 3, $usuarioB);

        $this->app->instance(
            NotificationService::class,
            new NotificationServiceQueFalhaNoProduto((int) $produtoComDefeito->id)
        );

        $this->artisan('estoque:verificar')
            ->expectsOutputToContain('Os demais registros continuam sendo processados.')
            ->assertExitCode(Command::FAILURE);

        $avisos = $this->itensDoEvento(EventosDeNotificacao::ESTOQUE_ABAIXO_DO_MINIMO);
        $referencias = $avisos->map(fn (NotificationQueue $aviso): int => (int) ($aviso->contexto['referencia_id'] ?? 0));

        $this->assertNotContains(
            (int) $produtoComDefeito->id,
            $referencias->all(),
            'o produto com defeito não podia ter gerado aviso'
        );

        $this->assertContains(
            (int) $produtoB->id,
            $referencias->all(),
            'a segunda empresa precisava continuar sendo processada'
        );
    }

    // -----------------------------------------------------------------
    // Registro em scheduled_task_runs
    // -----------------------------------------------------------------

    public function test_rotina_esta_registrada_em_rotinas_agendadas_e_aparece_em_scheduled_task_runs(): void
    {
        $this->assertArrayHasKey('estoque:verificar', RotinasAgendadas::DIARIAS);

        $this->assertSame(0, ScheduledTaskRun::query()->count());

        $this->artisan('schedule:test', ['--name' => 'estoque:verificar'])->assertSuccessful();

        $rodada = ScheduledTaskRun::query()->daTarefa('estoque:verificar')->first();

        $this->assertNotNull($rodada, 'a rodada agendada não foi registrada em scheduled_task_runs');
        $this->assertSame(RegistraExecucaoAgendada::STATUS_SUCESSO, $rodada->status);
        $this->assertNotNull($rodada->started_at);
        $this->assertNotNull($rodada->finished_at);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function naEmpresa(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->id, $callback);
    }

    private function criarProduto(string $nome, ?float $estoqueMinimo): Product
    {
        $sufixo = uniqid();

        return Product::create([
            'name' => $nome,
            'active_ingredient_id' => ActiveIngredient::create(['name' => 'Princípio '.$sufixo])->id,
            'chemical_group_id' => ChemicalGroup::create(['name' => 'Grupo '.$sufixo])->id,
            'antidote_id' => Antidote::create(['name' => 'Antídoto '.$sufixo])->id,
            'controla_estoque' => true,
            'estoque_minimo' => $estoqueMinimo,
            'unidade' => 'un',
        ]);
    }

    private function criarLote(Product $produto, string $validade): ProductBatch
    {
        return $this->naEmpresa(fn (): ProductBatch => ProductBatch::create([
            'product_id' => $produto->id,
            'lote' => 'L-'.uniqid(),
            'validade' => $validade,
            'recebido_em' => BusinessDate::hoje()->subDays(30)->toDateString(),
            'custo_unitario' => 9.9,
        ]));
    }

    /**
     * Dia no fuso do negócio, deslocado de hoje. Negativo é passado.
     */
    private function emDias(int $dias): string
    {
        return BusinessDate::hoje()->addDays($dias)->toDateString();
    }

    /**
     * Congela a hora corrente em um instante UTC, que é o fuso em que a
     * aplicação roda. Mesmo motivo do `viajarPara` de
     * `BatchSelectionServiceTest`: congelar em fuso de negócio faria o teste
     * interpretar dado gravado em UTC como se já estivesse em Brasília.
     */
    private function viajarPara(string $instanteEmUtc): void
    {
        $this->travelTo(CarbonImmutable::parse($instanteEmUtc, 'UTC'));
    }

    /**
     * Itens da fila deste evento, de todas as empresas: sem tenant explícito,
     * o escopo global de `NotificationQueue` não filtra nada, o que permite
     * conferir duas empresas de uma vez (usado no teste de falha isolada).
     *
     * @return Collection<int, NotificationQueue>
     */
    private function itensDoEvento(string $evento): Collection
    {
        return NotificationQueue::query()->where('evento', $evento)->orderBy('id')->get();
    }
}

/**
 * Service que estoura para um produto escolhido e se comporta normalmente no
 * resto. É como o teste reproduz "dado inconsistente em um registro" sem
 * precisar corromper o banco de propósito, no mesmo padrão de
 * `NotificationServiceQueFalhaNoCertificado` em `EventosDeNotificacaoTest`.
 */
class NotificationServiceQueFalhaNoProduto extends NotificationService
{
    public function __construct(private readonly int $produtoQueFalha) {}

    /**
     * @param  array<string, mixed>  $opcoes
     * @return array{resultado: string, criado: bool, item: NotificationQueue|null, canal: string|null, chave_idempotencia: string|null, mensagem: string}
     */
    public function enfileirar(string $evento, Model $referencia, array $opcoes = []): array
    {
        if ($referencia instanceof Product && (int) $referencia->getKey() === $this->produtoQueFalha) {
            throw new RuntimeException("Dado inconsistente no produto #{$this->produtoQueFalha}.");
        }

        return parent::enfileirar($evento, $referencia, $opcoes);
    }
}
