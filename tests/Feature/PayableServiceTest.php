<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\FinancialEntry;
use App\Models\Payable;
use App\Models\PayableInstallment;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PayableService;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Closure;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Task 18.5 do Plano 18: contas a pagar com recorrência.
 *
 * O que este arquivo protege, em ordem de gravidade:
 *
 * - A janela de 3 competências: criar um recorrente gera 3, a rotina sempre
 *   mantém 3 à frente conforme o tempo passa, e rodar de novo nunca duplica
 *   competência.
 * - Alterar valor de recorrente nunca reescreve o que já foi pago.
 * - Baixa e estorno do lado de pagar seguem exatamente a mesma disciplina do
 *   lado de receber (Task 18.4): mesmo dinheiro não sai duas vezes, saldo
 *   devedor é respeitado, estorno é lançamento novo.
 * - Cancelar um título da série interrompe a geração das competências
 *   seguintes.
 *
 * O relógio fica fixo em `2026-01-15` no início de cada teste (avançado onde
 * o próprio teste precisa simular a passagem do tempo), para que "hoje" seja
 * um dia conhecido e a matemática de competência seja conferível à mão.
 */
class PayableServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-01-15';

    private PayableService $servico;

    private Company $empresa;

    private User $operador;

    private User $semPermissao;

    private Supplier $fornecedor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::HOJE.' 12:00:00');

        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->servico = app(PayableService::class);
        $this->empresa = Company::query()->firstOrFail();

        $this->operador = User::factory()->create(['company_id' => $this->empresa->id]);
        $this->operador->givePermissionTo(PayableService::PERMISSAO_ESTORNO);

        $this->semPermissao = User::factory()->create(['company_id' => $this->empresa->id]);

        $this->fornecedor = $this->comTenant(fn () => Supplier::create([
            'nome' => 'Imobiliária Central',
            'ativo' => true,
        ]));
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Recorrência: janela de 3 competências
    // -----------------------------------------------------------------

    public function test_criar_titulo_mensal_gera_as_tres_competencias_seguintes(): void
    {
        $raiz = $this->criarRecorrenteMensal();

        $this->assertSame('nenhuma', Payable::RECORRENCIAS[0]); // sanity: enum conhecido
        $this->assertNull($raiz->payable_origem_id, 'o título criado manualmente é a raiz da série');

        $serie = $this->serieCompleta($raiz);

        $this->assertCount(3, $serie, 'criar um título mensal precisa deixar 3 competências geradas');

        $competencias = $serie->pluck('emitido_em')->map(fn ($data) => BusinessDate::diaDe($data))->values()->all();
        $this->assertSame(['2026-01-15', '2026-02-15', '2026-03-15'], $competencias);

        $vencimentos = $serie
            ->map(fn (Payable $titulo) => $titulo->installments()->firstOrFail())
            ->map(fn (PayableInstallment $parcela) => BusinessDate::diaDe($parcela->vencimento))
            ->values()
            ->all();
        $this->assertSame(['2026-01-20', '2026-02-20', '2026-03-20'], $vencimentos, 'o dia do vencimento precisa se repetir mês a mês');

        foreach ($serie as $titulo) {
            $this->assertSame('1000.00', $titulo->valor_total);
            $this->assertSame('aberto', $titulo->situacao);
        }
    }

    public function test_a_rotina_mantem_sempre_tres_competencias_a_frente(): void
    {
        $raiz = $this->criarRecorrenteMensal();
        $this->assertCount(3, $this->serieCompleta($raiz));

        // Um mês se passa: fevereiro vira "hoje". A janela de 3 à frente
        // (fev, mar, abr) precisa empurrar a geração para abril, sem apagar
        // janeiro, que já existia.
        Carbon::setTestNow('2026-02-15 12:00:00');

        $gerados = $this->comTenant(fn () => $this->servico->manterJanelaDeCompetencias($raiz));

        $this->assertSame(1, $gerados, 'só abril precisava ser gerado para completar a janela de 3 a partir de fevereiro');

        $serie = $this->serieCompleta($raiz);
        $this->assertCount(4, $serie, 'janeiro continua existindo, e abril entra para completar a janela');

        $competencias = $serie->pluck('emitido_em')->map(fn ($data) => BusinessDate::diaDe($data))->sort()->values()->all();
        $this->assertSame(['2026-01-15', '2026-02-15', '2026-03-15', '2026-04-15'], $competencias);
    }

    public function test_rodar_a_rotina_duas_vezes_nao_duplica_competencia(): void
    {
        $raiz = $this->criarRecorrenteMensal();

        $primeiraChamada = $this->comTenant(fn () => $this->servico->manterJanelaDeCompetencias($raiz));
        $this->assertSame(0, $primeiraChamada, 'a janela já estava satisfeita pela criação; nada deveria ser gerado de novo');
        $this->assertCount(3, $this->serieCompleta($raiz));

        // Mesmo rodando via o comando de verdade, mesma competência, sem duplicar.
        $codigo = $this->comTenant(fn () => $this->artisan('financeiro:gerar-pagamentos-recorrentes')->run());

        $this->assertSame(0, $codigo);
        $this->assertCount(3, $this->serieCompleta($raiz), 'rodar a rotina de novo não pode duplicar competência');
    }

    // -----------------------------------------------------------------
    // Alterar valor de recorrente
    // -----------------------------------------------------------------

    public function test_alterar_valor_este_e_futuros_nao_muda_os_ja_pagos(): void
    {
        $raiz = $this->criarRecorrenteMensal();

        // Janeiro (a raiz) é pago integralmente antes da mudança de valor.
        $parcelaDeJaneiro = $raiz->installments()->firstOrFail();
        $this->comTenant(fn () => $this->servico->baixar($parcelaDeJaneiro, [
            'valor' => 1000.00,
            'data' => '2026-01-18',
            'usuario' => $this->operador,
        ]));

        $atualizados = $this->comTenant(fn () => $this->servico->alterarValor(
            $raiz->fresh(),
            '1200.00',
            PayableService::ALCANCE_ESTE_E_FUTUROS
        ));

        $this->assertCount(2, $atualizados, 'janeiro já pago precisa ficar de fora da lista de alterados');

        $janeiro = Payable::query()->findOrFail($raiz->id);
        $this->assertSame('1000.00', $janeiro->valor_total, 'título com parcela paga nunca muda de valor');
        $this->assertSame('1000.00', $janeiro->installments()->firstOrFail()->valor);

        $serie = $this->serieCompleta($raiz);
        $fevereiro = $serie->first(fn (Payable $t) => BusinessDate::diaDe($t->emitido_em) === '2026-02-15');
        $marco = $serie->first(fn (Payable $t) => BusinessDate::diaDe($t->emitido_em) === '2026-03-15');

        $this->assertSame('1200.00', $fevereiro->valor_total);
        $this->assertSame('1200.00', $fevereiro->installments()->firstOrFail()->valor);
        $this->assertSame('1200.00', $marco->valor_total);
        $this->assertSame('1200.00', $marco->installments()->firstOrFail()->valor);

        // Alcance "apenas este" muda só fevereiro, março continua em 1200.
        $this->comTenant(fn () => $this->servico->alterarValor(
            $fevereiro->fresh(),
            '1300.00',
            PayableService::ALCANCE_APENAS_ESTE
        ));

        $this->assertSame('1300.00', Payable::query()->findOrFail($fevereiro->id)->valor_total);
        $this->assertSame('1200.00', Payable::query()->findOrFail($marco->id)->valor_total, 'apenas_este não pode alterar os demais');
    }

    // -----------------------------------------------------------------
    // Cancelamento interrompe a recorrência
    // -----------------------------------------------------------------

    public function test_cancelar_a_raiz_interrompe_a_geracao_futura(): void
    {
        $raiz = $this->criarRecorrenteMensal();

        $canceladoRaiz = $this->comTenant(fn () => $this->servico->cancelar($raiz));
        $this->assertSame('cancelado', $canceladoRaiz->situacao);
        $this->assertSame('cancelada', $canceladoRaiz->installments()->firstOrFail()->situacao);

        Carbon::setTestNow('2026-02-15 12:00:00');

        $gerados = $this->comTenant(fn () => $this->servico->manterJanelaDeCompetencias($raiz->fresh()));

        $this->assertSame(0, $gerados, 'série cancelada não gera competência nova');
        $this->assertCount(3, $this->serieCompleta($raiz), 'nenhum abril deveria ter sido criado');
    }

    public function test_cancelar_uma_ocorrencia_gerada_tambem_interrompe_a_serie(): void
    {
        $raiz = $this->criarRecorrenteMensal();
        $fevereiro = $this->serieCompleta($raiz)->first(fn (Payable $t) => BusinessDate::diaDe($t->emitido_em) === '2026-02-15');

        $this->comTenant(fn () => $this->servico->cancelar($fevereiro));

        Carbon::setTestNow('2026-02-15 12:00:00');
        $gerados = $this->comTenant(fn () => $this->servico->manterJanelaDeCompetencias($raiz));

        $this->assertSame(0, $gerados, 'cancelar qualquer título da série precisa parar a geração das próximas competências');
        $this->assertCount(3, $this->serieCompleta($raiz));
    }

    // -----------------------------------------------------------------
    // Baixa parcial e estorno (mesma disciplina da Task 18.4)
    // -----------------------------------------------------------------

    public function test_baixa_parcial_soma_e_a_segunda_marca_paga(): void
    {
        $parcela = $this->parcelaAvulsa(500.00);

        $primeira = $this->comTenant(fn () => $this->servico->baixar($parcela, [
            'valor' => 200.00,
            'data' => '2026-01-10',
            'usuario' => $this->operador,
        ]));

        $this->assertSame('parcial', $primeira->situacao);
        $this->assertSame('200.00', $primeira->valor_pago);
        $this->assertNull($primeira->pago_em);
        $this->assertSame('parcial', $primeira->payable()->firstOrFail()->situacao);

        $segunda = $this->comTenant(fn () => $this->servico->baixar($primeira, [
            'valor' => 300.00,
            'data' => '2026-01-10',
            'usuario' => $this->operador,
        ]));

        $this->assertSame('paga', $segunda->situacao);
        $this->assertSame('500.00', $segunda->valor_pago);
        $this->assertSame('2026-01-10', BusinessDate::diaDe($segunda->pago_em));
        $this->assertSame('quitado', $segunda->payable()->firstOrFail()->situacao);

        $lancamentos = FinancialEntry::query()->get();
        $this->assertCount(2, $lancamentos, 'cada baixa cria exatamente um lançamento');
        $this->assertSame('500.00', number_format((float) $lancamentos->sum('amount'), 2, '.', ''));

        foreach ($lancamentos as $lancamento) {
            $this->assertContains(
                $lancamento->source,
                ['payment_reopen', 'manual_withdrawal'],
                'o pagamento precisa ter origem que o caixa atual classifica como saída'
            );
        }
    }

    public function test_baixa_acima_do_saldo_devedor_e_recusada_com_o_valor_na_mensagem(): void
    {
        $parcela = $this->parcelaAvulsa(500.00);

        $parcela = $this->comTenant(fn () => $this->servico->baixar($parcela, [
            'valor' => 250.00,
            'data' => '2026-01-10',
            'usuario' => $this->operador,
        ]));

        try {
            $this->comTenant(fn () => $this->servico->baixar($parcela, [
                'valor' => 300.00,
                'data' => '2026-01-10',
                'usuario' => $this->operador,
            ]));

            $this->fail('a baixa acima do saldo devedor precisava ser recusada');
        } catch (ValidationException $excecao) {
            $mensagem = $excecao->errors()['valor'][0] ?? '';
            $this->assertStringContainsString('R$ 250,00', $mensagem);
            $this->assertStringContainsString('R$ 300,00', $mensagem);
        }

        $this->assertSame('250.00', $parcela->refresh()->valor_pago, 'a recusa não pode ter somado nada');
    }

    public function test_estorno_cria_lancamento_de_reversao_e_mantem_o_original(): void
    {
        $parcela = $this->parcelaAvulsa(500.00);

        $baixada = $this->comTenant(fn () => $this->servico->baixar($parcela, [
            'valor' => 500.00,
            'data' => '2026-01-10',
            'forma_pagamento' => 'pix',
            'usuario' => $this->operador,
        ]));

        $original = FinancialEntry::query()->findOrFail($baixada->financial_entry_id);

        $estornada = $this->comTenant(fn () => $this->servico->estornar(
            $baixada,
            'Fornecedor devolveu o valor por duplicidade de cobrança',
            $this->operador
        ));

        $this->assertDatabaseHas('financial_entries', [
            'id' => $original->id,
            'amount' => '500.00',
            'status' => 'confirmed',
        ]);

        $this->assertSame(2, FinancialEntry::query()->count());

        $estorno = FinancialEntry::query()->findOrFail($estornada->financial_entry_id);
        $this->assertNotSame($original->id, $estorno->id);
        $this->assertSame('500.00', $estorno->amount);
        $this->assertSame(self::HOJE, BusinessDate::diaDe($estorno->entry_date), 'o estorno acontece no dia de hoje');
        $this->assertContains(
            $estorno->source,
            ['work_order', 'manual'],
            'o estorno do pagamento precisa ter origem que o caixa atual classifica como entrada'
        );

        $this->assertSame('aberta', $estornada->situacao);
        $this->assertSame('0.00', $estornada->valor_pago);
        $this->assertNull($estornada->pago_em);
        $this->assertSame('aberto', $estornada->payable()->firstOrFail()->situacao);
    }

    public function test_estorno_sem_permissao_devolve_403(): void
    {
        $parcela = $this->parcelaAvulsa(500.00);

        $baixada = $this->comTenant(fn () => $this->servico->baixar($parcela, [
            'valor' => 500.00,
            'data' => '2026-01-10',
            'usuario' => $this->operador,
        ]));

        try {
            $this->comTenant(fn () => $this->servico->estornar(
                $baixada,
                'Motivo suficientemente longo para passar na validação',
                $this->semPermissao
            ));

            $this->fail('o estorno sem permissão precisava ser barrado');
        } catch (AuthorizationException $excecao) {
            $resposta = app(ExceptionHandler::class)->render(
                Request::create('/', 'POST', server: ['HTTP_ACCEPT' => 'application/json']),
                $excecao
            );

            $this->assertSame(403, $resposta->getStatusCode());
        }

        $this->assertSame('paga', $baixada->refresh()->situacao);
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas
    // -----------------------------------------------------------------

    public function test_parcela_de_outra_empresa_nao_e_baixada(): void
    {
        $parcela = $this->parcelaAvulsa(500.00);

        $outraEmpresa = Company::create([
            'name' => 'Dedetizadora Concorrente',
            'cnpj' => '22.222.222/0001-22',
            'email' => 'contato@concorrente.test',
        ]);

        $this->expectException(ModelNotFoundException::class);

        TenantAtual::comTenant($outraEmpresa->id, fn () => $this->servico->baixar($parcela, [
            'valor' => 500.00,
            'data' => '2026-01-10',
            'usuario' => $this->operador,
        ]));
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function comTenant(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->id, $callback);
    }

    /**
     * Título recorrente mensal de 1000,00, vencendo dia 20, sem
     * `recorrencia_ate` (série sem fim planejado).
     */
    private function criarRecorrenteMensal(): Payable
    {
        return $this->comTenant(fn () => $this->servico->criar([
            'supplier_id' => $this->fornecedor->id,
            'descricao' => 'Aluguel do galpão',
            'valor' => 1000.00,
            'vencimento' => '2026-01-20',
            'recorrencia' => 'mensal',
        ]));
    }

    /**
     * Título avulso (sem recorrência), de uma parcela só, no valor informado.
     */
    private function parcelaAvulsa(float $valor): PayableInstallment
    {
        $titulo = $this->comTenant(fn () => $this->servico->criar([
            'supplier_id' => $this->fornecedor->id,
            'descricao' => 'Compra de material de aplicação',
            'valor' => $valor,
            'vencimento' => '2026-02-01',
        ]));

        return $titulo->installments()->firstOrFail();
    }

    /**
     * Todos os títulos da série (a raiz e as ocorrências geradas a partir
     * dela), lidos direto do banco para não confiar em relação em memória.
     *
     * @return \Illuminate\Support\Collection<int, Payable>
     */
    private function serieCompleta(Payable $raiz): \Illuminate\Support\Collection
    {
        return $this->comTenant(fn () => Payable::query()
            ->where(fn ($q) => $q->where('id', $raiz->id)->orWhere('payable_origem_id', $raiz->id))
            ->get());
    }
}
