<?php

namespace Tests\Feature;

use App\Exceptions\ContratoEmAssinaturaException;
use App\Models\Contract;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ContractService;
use App\Services\GeracaoDeVisitasService;
use App\Services\PendenciasDeContratoService;
use App\Support\BusinessDate;
use Carbon\Carbon;
use Database\Factories\AddressFactory;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fecha o gap descrito na dívida técnica: `ContractService::encerrar()` já
 * existia, mas sem rota, controller nem botão. `ContractService::excluir()`
 * recusa apagar um contrato com visita já executada e manda o usuário
 * "encerrar o contrato para cancelar apenas as visitas futuras", uma ação
 * que ele não tinha como executar.
 *
 * Três garantias cobertas aqui, as mesmas exigidas ao fechar o gap:
 *
 * 1. Encerrar cancela as visitas futuras e preserva as já executadas. A
 *    regra de cancelamento em si (`ManutencaoDeVisitasService::cancelarFuturas`)
 *    já é coberta a fundo em GeracaoDeVisitasTest; aqui o alvo é o caminho
 *    inteiro por `ContractService::encerrar()`, inclusive pela rota HTTP.
 * 2. Encerrar grava `end_date`. Sem isso o contrato continua "vigente" para
 *    `PendenciasDeContratoService` (critério de lá é `end_date` nulo ou
 *    >= hoje) e, conforme as datas que acabaram de ser canceladas forem
 *    vencendo, o painel de conformidade passa a apontá-las como pendência de
 *    um contrato que já foi encerrado de propósito.
 * 3. A rota `POST /contracts/{contract}/encerrar` exige `contrato-editar`:
 *    não pode ficar mais frouxa do que editar o contrato, porque encerrar
 *    cancela visita em cascata na agenda do cliente.
 * 4. Contrato em assinatura (Plano 26) não aceita encerramento nem exclusão,
 *    e a recusa chega ao usuário como mensagem, não como página de erro.
 *
 * Banco: esta suíte roda isolada, em `testing_plano9`
 * (`DB_DATABASE=testing_plano9 php artisan test --filter=ContractEncerramentoTest`).
 */
class ContractEncerramentoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 00:30 de 26/07 em UTC, que é 21:30 de 25/07 em Brasília. Mesmo instante
     * fixado em GeracaoDeVisitasTest, para os dois arquivos concordarem sobre
     * o que é "hoje" quando rodam na mesma suíte.
     */
    private const MOMENTO_VIRADA = '2026-07-26 00:30:00';

    private const HOJE = '2026-07-25';

    private const ATE_TRES_MESES = '2026-10-25';

    private ContractService $contractService;

    private GeracaoDeVisitasService $geracao;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::MOMENTO_VIRADA, 'UTC'));

        $this->contractService = app(ContractService::class);
        $this->geracao = app(GeracaoDeVisitasService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Encerrar cancela futuras e preserva executadas
    // -----------------------------------------------------------------

    public function test_encerrar_cancela_as_visitas_futuras_e_preserva_as_ja_executadas(): void
    {
        $contrato = $this->criarContrato();
        $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);

        $executada = $this->osNaData($contrato, '2026-08-25');
        $executada->update(['status' => 'completed']);
        $antesDaExecutada = WorkOrder::query()->findOrFail($executada->id)->getAttributes();

        $resultado = $this->contractService->encerrar($contrato, 'Cliente cancelou o contrato');

        $this->assertTrue($resultado['success']);
        $this->assertSame(3, $resultado['data']['canceladas']);
        $this->assertSame(1, $resultado['data']['executadas_preservadas']);

        foreach (['2026-07-25', '2026-09-25', '2026-10-25'] as $data) {
            $cancelada = $this->osNaData($contrato, $data);

            $this->assertSame('cancelled', $cancelada->status);
            $this->assertStringContainsString(
                'Cliente cancelou o contrato',
                (string) $cancelada->completion_notes,
                'o motivo informado não foi anexado à OS cancelada'
            );
        }

        $this->assertSame(
            $antesDaExecutada,
            WorkOrder::query()->findOrFail($executada->id)->getAttributes(),
            'o encerramento tocou uma visita já executada'
        );
        $this->assertSame('completed', $executada->fresh()->status);
    }

    public function test_encerrar_sem_motivo_usa_o_motivo_padrao_de_encerramento(): void
    {
        $contrato = $this->criarContrato();
        $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);

        $this->contractService->encerrar($contrato);

        $cancelada = $this->osNaData($contrato, '2026-08-25');

        $this->assertStringContainsString('Encerramento do contrato', (string) $cancelada->completion_notes);
    }

    // -----------------------------------------------------------------
    // Encerrar grava end_date
    // -----------------------------------------------------------------

    public function test_encerrar_fecha_a_vigencia_gravando_end_date_de_hoje(): void
    {
        $contrato = $this->criarContrato(['end_date' => self::ATE_TRES_MESES]);

        $this->contractService->encerrar($contrato);

        $this->assertSame(self::HOJE, BusinessDate::diaDe($contrato->fresh()->end_date));
    }

    public function test_encerrar_grava_end_date_mesmo_quando_o_contrato_nao_tinha_vigencia_definida(): void
    {
        $contrato = $this->criarContrato(['end_date' => null]);

        $this->contractService->encerrar($contrato);

        $this->assertSame(self::HOJE, BusinessDate::diaDe($contrato->fresh()->end_date));
    }

    public function test_encerrar_nao_alonga_um_contrato_cuja_vigencia_ja_tinha_terminado(): void
    {
        $contrato = $this->criarContrato(['end_date' => '2026-07-10']);

        $this->contractService->encerrar($contrato);

        $this->assertSame(
            '2026-07-10',
            BusinessDate::diaDe($contrato->fresh()->end_date),
            'encerrar moveu para a frente a vigência de um contrato que já tinha terminado antes de hoje'
        );
    }

    /**
     * O alerta que motivou a task: sem `end_date` gravado, o contrato
     * encerrado continua "vigente" e, conforme as visitas canceladas vencem,
     * volta a poluir o painel de conformidade.
     */
    public function test_contrato_encerrado_para_de_aparecer_como_vigente_no_painel_apos_o_dia_do_encerramento(): void
    {
        $contrato = $this->criarContrato();
        $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);

        $this->contractService->encerrar($contrato);

        $pendencias = app(PendenciasDeContratoService::class);

        // Um mês depois do encerramento: se end_date não tivesse sido
        // gravado, as visitas canceladas por aqui apareceriam como "sem
        // visita gerada no período configurado".
        Carbon::setTestNow(Carbon::parse('2026-08-26 00:30:00', 'UTC'));

        $painel = $pendencias->listar();

        $this->assertNotContains(
            $contrato->id,
            array_column($painel, 'contrato_id'),
            'contrato encerrado sem end_date continuou vigente e voltou a aparecer no painel de pendências'
        );
    }

    // -----------------------------------------------------------------
    // Rota: permissão exigida
    // -----------------------------------------------------------------

    public function test_papel_sem_permissao_de_editar_contrato_recebe_403_ao_encerrar(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $contrato = $this->criarContrato();

        foreach (['tecnico', 'leitura'] as $papel) {
            $usuario = $this->criarUsuarioComPapel($papel);

            $resposta = $this->actingAs($usuario)->post("/contracts/{$contrato->id}/encerrar", [
                'motivo' => 'Tentativa sem permissão',
            ]);

            $resposta->assertForbidden("papel '{$papel}' deveria receber 403 ao encerrar contrato");
        }

        $this->assertNull($contrato->fresh()->end_date, 'a requisição sem permissão alterou o contrato');
        $this->assertSame(0, WorkOrder::query()->where('status', 'cancelled')->count());
    }

    public function test_usuario_com_permissao_contrato_editar_encerra_o_contrato_pela_rota(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        // Task 6.4: a rota acumula `module:contratos`. Sem o catálogo
        // semeado, ninguém passa por ela, nem quem tem `contrato-editar`.
        $this->seed(ModulesSeeder::class);

        $contrato = $this->criarContrato(['end_date' => self::ATE_TRES_MESES]);
        $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);

        // 'comercial' tem contrato-editar (todo o prefixo contrato-*), sem
        // depender do papel administrador, para provar que a checagem é por
        // permissão e não por papel específico.
        $usuario = $this->criarUsuarioComPapel('comercial');

        $resposta = $this->actingAs($usuario)->post("/contracts/{$contrato->id}/encerrar", [
            'motivo' => 'Encerramento solicitado pelo cliente',
        ]);

        $resposta->assertRedirect();
        $resposta->assertSessionHas('success');

        $atualizado = $contrato->fresh();
        $this->assertSame(self::HOJE, BusinessDate::diaDe($atualizado->end_date));

        foreach (['2026-08-25', '2026-09-25', '2026-10-25'] as $data) {
            $cancelada = $this->osNaData($contrato, $data);

            $this->assertSame('cancelled', $cancelada->status);
            $this->assertStringContainsString('Encerramento solicitado pelo cliente', (string) $cancelada->completion_notes);
        }
    }

    // -----------------------------------------------------------------
    // Contrato em assinatura: encerrar e excluir são recusados
    // -----------------------------------------------------------------

    /**
     * Regressão do Plano 26: `encerrar()` grava `end_date`, ou seja, muda a
     * vigência do contrato. Feito com o pedido de assinatura em aberto, o
     * cliente assinaria em seguida um PDF cuja vigência não é mais a do
     * registro — documento oponível divergindo do sistema, que é justamente o
     * que a invariante "contrato em assinatura é imutável" existe para impedir.
     */
    public function test_encerrar_contrato_em_assinatura_e_recusado_sem_tocar_na_vigencia_nem_na_agenda(): void
    {
        $contrato = $this->criarContrato();
        $this->geracao->gerarPara($contrato, self::ATE_TRES_MESES);
        $contrato->forceFill(['situacao_assinatura' => 'em_assinatura'])->save();

        try {
            $this->contractService->encerrar($contrato, 'Tentativa durante a assinatura');
            $this->fail('Esperava ContratoEmAssinaturaException ao encerrar contrato em assinatura.');
        } catch (ContratoEmAssinaturaException $excecao) {
            $this->assertStringContainsString('está em assinatura', $excecao->getMessage());
        }

        $this->assertNull(
            $contrato->fresh()->end_date,
            'o encerramento recusado ainda assim fechou a vigência do contrato em assinatura'
        );
        $this->assertSame(
            0,
            WorkOrder::query()->where('status', 'cancelled')->count(),
            'o encerramento recusado ainda assim cancelou visitas da agenda'
        );
    }

    public function test_rota_de_encerrar_contrato_em_assinatura_devolve_erro_na_sessao(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ModulesSeeder::class);

        $contrato = $this->criarContrato();
        $contrato->forceFill(['situacao_assinatura' => 'em_assinatura'])->save();

        $usuario = $this->criarUsuarioComPapel('comercial');

        $resposta = $this->actingAs($usuario)->post("/contracts/{$contrato->id}/encerrar", [
            'motivo' => 'Tentativa durante a assinatura',
        ]);

        $resposta->assertRedirect();
        $resposta->assertSessionHas('error');
        $this->assertNull($contrato->fresh()->end_date);
    }

    /**
     * Regressão do Plano 26: `excluir()` passou a recusar contrato em
     * assinatura por exceção, e `destroy()` chamava o Service sem `try/catch`.
     * A recusa chegava ao usuário como página de erro genérica em vez da
     * mensagem que explica o caminho de saída (cancelar o pedido antes).
     */
    public function test_rota_de_excluir_contrato_em_assinatura_devolve_erro_na_sessao_e_preserva_o_contrato(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ModulesSeeder::class);

        $contrato = $this->criarContrato();
        $contrato->forceFill(['situacao_assinatura' => 'em_assinatura'])->save();

        $usuario = $this->criarUsuarioComPapel('comercial');

        $resposta = $this->actingAs($usuario)->delete("/contracts/{$contrato->id}");

        $resposta->assertRedirect();
        $resposta->assertSessionHas('error');
        $this->assertStringContainsString(
            'está em assinatura',
            (string) session('error'),
            'a mensagem da exceção não chegou ao usuário'
        );
        $this->assertNotNull(Contract::query()->find($contrato->id), 'o contrato em assinatura foi excluído');
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Contrato periódico mensal começando hoje, salvo com o endereço dele.
     * Mesmo padrão de `GeracaoDeVisitasTest::criarContrato`.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function criarContrato(array $atributos = []): Contract
    {
        $addressId = AddressFactory::new()->create()->id;

        return Contract::query()->create(array_merge([
            'address_id' => $addressId,
            'contract_number' => 'CONT-'.str_pad((string) $addressId, 6, '0', STR_PAD_LEFT),
            'service_type' => 'periodico',
            'visit_frequency' => 'mensal',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'meses',
            'visit_count' => 12,
            'start_date' => self::HOJE,
            'end_date' => null,
        ], $atributos));
    }

    private function osNaData(Contract $contrato, string $data): WorkOrder
    {
        $os = WorkOrder::query()
            ->where('contract_id', $contrato->id)
            ->where('scheduled_date', $data)
            ->first();

        $this->assertNotNull($os, "não existe OS do contrato na data {$data}");

        return $os;
    }

    private function criarUsuarioComPapel(string $papel): User
    {
        // `company_id` explícito: sem tenant fixado no momento da criação, o
        // atributo fica ausente no objeto PHP (só a linha do banco recebe o
        // default da coluna), e `actingAs()` reaproveita esse mesmo objeto em
        // toda chamada HTTP do teste.
        $usuario = User::factory()->create(['company_id' => 1]);
        $usuario->assignRole($papel);

        return $usuario;
    }
}
