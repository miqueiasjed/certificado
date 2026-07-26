<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractVisitJustification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\GeracaoDeVisitasService;
use App\Services\JustificativaDeVisitaService;
use App\Services\PendenciasDeContratoService;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Database\Factories\AddressFactory;
use Database\Factories\WorkOrderFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Justificar a data prevista de visita que não virou Ordem de Serviço.
 *
 * O buraco que esta funcionalidade fecha: o painel acusava contrato periódico
 * com data prevista vencida sem OS, e não havia saída. "Gerar visitas" não
 * resolve, porque a geração nunca cria OS com data no passado (decisão
 * deliberada de `GeracaoDeVisitasService::gerarPara`, protegida por teste
 * próprio em `GeracaoDeVisitasTest`). O contrato ficava no painel para sempre,
 * e painel que só cresce vira ruído que ninguém olha.
 *
 * Quatro garantias, e nenhuma delas pode ser afrouxada:
 *
 * 1. Justificar tira a data do painel, sem criar nada no passado.
 * 2. Remover a justificativa devolve a data ao painel.
 * 3. Motivo vazio é recusado. A justificativa É o documento perante
 *    fiscalização: sem texto, ela só apagaria a pendência e não deixaria nada
 *    no lugar.
 * 4. Justificativa de uma empresa não alcança a outra. Duas dedetizadoras
 *    concorrentes no mesmo banco: a decisão de uma não pode silenciar o painel
 *    da outra.
 *
 * O relógio é fixado às 00:30 de 26/07 em UTC, que ainda é 21:30 de 25/07 em
 * Brasília, o mesmo instante de `GeracaoDeVisitasTest`: o "hoje" de todo
 * cálculo é o dia do negócio (25/07).
 *
 * Banco: `DB_DATABASE=testing_plano9 php artisan test --filter=JustificativaDeVisitaTest`.
 */
class JustificativaDeVisitaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 00:30 de 26/07 em UTC, que é 21:30 de 25/07 em Brasília.
     */
    private const MOMENTO_VIRADA = '2026-07-26 00:30:00';

    private const HOJE = '2026-07-25';

    /**
     * Datas previstas vencidas há mais de 30 dias
     * (`config('contratos.dias_de_alerta_sem_visita')`) do contrato mensal que
     * começa em 25/01/2026: são exatamente as que o painel acusa.
     */
    private const DATAS_DO_PAINEL = ['2026-01-25', '2026-02-25', '2026-03-25', '2026-04-25', '2026-05-25'];

    private JustificativaDeVisitaService $justificativas;

    private GeracaoDeVisitasService $geracao;

    private PendenciasDeContratoService $painel;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::MOMENTO_VIRADA, 'UTC'));
        TenantAtual::limpar();

        $this->justificativas = app(JustificativaDeVisitaService::class);
        $this->geracao = app(GeracaoDeVisitasService::class);
        $this->painel = app(PendenciasDeContratoService::class);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // 1. Justificar tira do painel
    // -----------------------------------------------------------------

    public function test_justificar_todas_as_datas_tira_o_contrato_do_painel_de_pendencias(): void
    {
        $contrato = $this->criarContratoComLacunas();

        $this->assertCount(1, $this->painel->listar(), 'o cenário exige o contrato no painel antes de justificar');

        $resultado = $this->justificativas->justificar(
            $contrato,
            self::DATAS_DO_PAINEL,
            'Contrato suspenso pelo cliente no período, sem acesso ao local.'
        );

        $this->assertTrue($resultado['success']);
        $this->assertSame(5, $resultado['data']['registradas']);

        $this->assertSame([], $this->painel->listar(), 'o contrato continuou no painel depois de todas as datas justificadas');

        // A fonte continua acusando 25/06: ela venceu, mas há menos de 30 dias
        // (`config('contratos.dias_de_alerta_sem_visita')`), então ainda está
        // na folga que o painel dá para a rotina diária cobrir. Nada a ver com
        // a justificativa, e é o que prova que o filtro tirou só o que foi
        // justificado.
        $this->assertSame(
            [['numero' => 6, 'data' => '2026-06-25']],
            $this->geracao->pendenciasDeConformidade($contrato)
        );
    }

    /**
     * Justificar não pode criar Ordem de Serviço nenhuma. É o ponto inteiro da
     * decisão: documento datado de um dia em que ninguém foi ao local é pior
     * que a lacuna.
     */
    public function test_justificar_nao_cria_nenhuma_ordem_de_servico(): void
    {
        $contrato = $this->criarContratoComLacunas();

        $this->justificativas->justificar($contrato, self::DATAS_DO_PAINEL, 'Cliente não permitiu acesso.');

        $this->assertSame(0, WorkOrder::query()->count(), 'a justificativa criou OS');
        $this->assertSame(5, ContractVisitJustification::query()->count());
    }

    /**
     * Justificar parte das datas deixa o contrato no painel com o restante:
     * uma justificativa cobre uma data, nunca o contrato inteiro.
     */
    public function test_justificar_parte_das_datas_deixa_as_demais_no_painel(): void
    {
        $contrato = $this->criarContratoComLacunas();

        $this->justificativas->justificar(
            $contrato,
            ['2026-01-25', '2026-02-25'],
            'Vigência suspensa em janeiro e fevereiro por acordo com o cliente.'
        );

        $painel = $this->painel->listar();

        $this->assertCount(1, $painel);
        $this->assertSame(
            ['2026-03-25', '2026-04-25', '2026-05-25'],
            array_column($painel[0]['datas_sem_visita'], 'data'),
            'as datas não justificadas precisam continuar acusadas, uma a uma'
        );
        $this->assertStringContainsString(
            'Sem visita gerada no período configurado: 3 data(s) prevista(s)',
            implode(' ', $painel[0]['motivos'])
        );
    }

    /**
     * A lacuna continua visível na tela do contrato, agora com motivo, autor e
     * data. Sumir com a linha seria trocar um problema por outro: ninguém mais
     * saberia que a visita não aconteceu.
     */
    public function test_a_tela_do_contrato_mostra_a_lacuna_com_o_motivo_o_autor_e_a_data(): void
    {
        $contrato = $this->criarContratoComLacunas();
        $autor = User::factory()->create(['name' => 'Fiscal Responsável']);

        $this->justificativas->justificar(
            $contrato,
            ['2026-01-25'],
            'Visita realizada e registrada em planilha fora do sistema.',
            $autor
        );

        $linhas = collect($this->geracao->visitasComSituacao($contrato))->keyBy('data');

        $justificada = $linhas['25/01/2026'];

        $this->assertSame('justificada', $justificada['situacao']);
        $this->assertSame('Visita realizada e registrada em planilha fora do sistema.', $justificada['justificativa']['motivo']);
        $this->assertSame('Fiscal Responsável', $justificada['justificativa']['autor']);
        $this->assertSame('25/07/2026 21:30', $justificada['justificativa']['registrada_em'], 'o instante da decisão vai formatado no fuso do negócio');

        $this->assertSame('pendente', $linhas['25/02/2026']['situacao'], 'a data seguinte não foi justificada e continua pendente');
        $this->assertNull($linhas['25/02/2026']['justificativa']);
    }

    /**
     * Data prevista que não é pendência não aceita justificativa: o que não é
     * lacuna não precisa de desculpa, e aceitar a data errada abriria caminho
     * para justificar visita futura antes de ela vencer.
     */
    public function test_data_futura_e_data_com_os_gravada_sao_recusadas(): void
    {
        $contrato = $this->criarContratoComLacunas();

        // 25/06 já tem OS executada: a visita aconteceu, não há lacuna.
        $this->criarOs($contrato, '2026-06-25', 'completed', 6);

        foreach (['2026-08-25' => 'futura', '2026-06-25' => 'com OS gravada', '2026-01-13' => 'fora do calendário'] as $data => $rotulo) {
            $resultado = $this->justificativas->justificar($contrato, [$data], 'Motivo qualquer para teste.');

            $this->assertFalse($resultado['success'], "a data {$rotulo} foi aceita e não deveria");
            $this->assertSame([$data], $resultado['data']['recusadas']);
        }

        $this->assertSame(0, ContractVisitJustification::query()->count(), 'data recusada não pode gravar nada');
    }

    /**
     * Tudo ou nada: uma data recusada no meio derruba a chamada inteira, sem
     * gravar as válidas. Gravar metade deixaria o contrato em um estado que
     * ninguém pediu.
     */
    public function test_uma_data_invalida_no_lote_impede_a_gravacao_das_validas(): void
    {
        $contrato = $this->criarContratoComLacunas();

        $resultado = $this->justificativas->justificar(
            $contrato,
            ['2026-01-25', '2026-08-25'],
            'Motivo válido, com uma data inválida junto.'
        );

        $this->assertFalse($resultado['success']);
        $this->assertSame(0, ContractVisitJustification::query()->count());
        $this->assertCount(1, $this->painel->listar());
    }

    // -----------------------------------------------------------------
    // 2. Remover devolve ao painel
    // -----------------------------------------------------------------

    public function test_remover_a_justificativa_devolve_a_data_ao_painel(): void
    {
        $contrato = $this->criarContratoComLacunas();

        $this->justificativas->justificar($contrato, self::DATAS_DO_PAINEL, 'Contrato suspenso no período.');
        $this->assertSame([], $this->painel->listar());

        $justificativa = ContractVisitJustification::query()
            ->where('contract_id', $contrato->id)
            ->whereDate('expected_date', '2026-03-25')
            ->firstOrFail();

        $resultado = $this->justificativas->remover($contrato, $justificativa);

        $this->assertTrue($resultado['success']);
        $this->assertSame('2026-03-25', $resultado['data']['data']);

        $painel = $this->painel->listar();

        $this->assertCount(1, $painel, 'remover a justificativa não devolveu o contrato ao painel');
        $this->assertSame(
            ['2026-03-25'],
            array_column($painel[0]['datas_sem_visita'], 'data'),
            'voltou ao painel uma data diferente da que teve a justificativa removida'
        );

        $this->assertSame(4, ContractVisitJustification::query()->count(), 'a remoção levou junto justificativa de outra data');
    }

    public function test_remover_justificativa_de_outro_contrato_pela_rota_deste_devolve_404(): void
    {
        $contrato = $this->criarContratoComLacunas();
        $outroContrato = $this->criarContratoComLacunas();

        $this->justificativas->justificar($outroContrato, ['2026-01-25'], 'Motivo do outro contrato.');

        $doOutro = ContractVisitJustification::query()->where('contract_id', $outroContrato->id)->firstOrFail();

        $this->expectException(ModelNotFoundException::class);

        try {
            $this->justificativas->remover($contrato, $doOutro);
        } finally {
            $this->assertSame(1, ContractVisitJustification::query()->count(), 'a justificativa do outro contrato foi apagada');
        }
    }

    // -----------------------------------------------------------------
    // 3. Motivo vazio é recusado
    // -----------------------------------------------------------------

    public function test_motivo_vazio_ou_so_com_espacos_e_recusado_pelo_service(): void
    {
        $contrato = $this->criarContratoComLacunas();

        foreach (['', '   ', "\n\t"] as $motivo) {
            $resultado = $this->justificativas->justificar($contrato, ['2026-01-25'], $motivo);

            $this->assertFalse($resultado['success'], 'motivo vazio foi aceito');
            $this->assertStringContainsString('motivo é obrigatório', $resultado['message']);
        }

        $this->assertSame(0, ContractVisitJustification::query()->count());
        $this->assertCount(1, $this->painel->listar(), 'a pendência sumiu do painel sem motivo gravado');
    }

    public function test_motivo_vazio_pela_rota_devolve_erro_de_validacao_e_nao_grava_nada(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $contrato = $this->criarContratoComLacunas();
        $usuario = $this->usuarioComPapel('comercial');

        $resposta = $this->actingAs($usuario)->postJson("/contracts/{$contrato->id}/visitas/justificativas", [
            'datas' => ['2026-01-25'],
            'motivo' => '',
        ]);

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('motivo');

        $this->assertSame(0, ContractVisitJustification::query()->count());
        $this->assertCount(1, $this->painel->listar());
    }

    public function test_motivo_acima_do_limite_da_coluna_e_recusado_pela_rota(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $contrato = $this->criarContratoComLacunas();
        $usuario = $this->usuarioComPapel('comercial');

        $resposta = $this->actingAs($usuario)->postJson("/contracts/{$contrato->id}/visitas/justificativas", [
            'datas' => ['2026-01-25'],
            'motivo' => str_repeat('a', 501),
        ]);

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('motivo');
        $this->assertSame(0, ContractVisitJustification::query()->count());
    }

    // -----------------------------------------------------------------
    // Rota e permissão
    // -----------------------------------------------------------------

    public function test_papel_sem_permissao_de_editar_contrato_recebe_403_ao_justificar_e_ao_remover(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $contrato = $this->criarContratoComLacunas();
        $this->justificativas->justificar($contrato, ['2026-02-25'], 'Justificativa registrada antes do teste.');
        $justificativa = ContractVisitJustification::query()->firstOrFail();

        foreach (['tecnico', 'leitura'] as $papel) {
            $usuario = $this->usuarioComPapel($papel);

            $this->actingAs($usuario)
                ->postJson("/contracts/{$contrato->id}/visitas/justificativas", [
                    'datas' => ['2026-01-25'],
                    'motivo' => 'Tentativa sem permissão de editar contrato.',
                ])
                ->assertForbidden("papel '{$papel}' deveria receber 403 ao justificar");

            $this->actingAs($usuario)
                ->deleteJson("/contracts/{$contrato->id}/visitas/justificativas/{$justificativa->id}")
                ->assertForbidden("papel '{$papel}' deveria receber 403 ao remover justificativa");
        }

        $this->assertSame(1, ContractVisitJustification::query()->count(), 'a requisição sem permissão gravou ou apagou justificativa');
    }

    public function test_usuario_com_contrato_editar_justifica_e_remove_pela_rota(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $contrato = $this->criarContratoComLacunas();
        $usuario = $this->usuarioComPapel('comercial', 'Ana Comercial');

        $registro = $this->actingAs($usuario)->postJson("/contracts/{$contrato->id}/visitas/justificativas", [
            'datas' => self::DATAS_DO_PAINEL,
            'motivo' => 'Cliente não permitiu acesso ao local no período.',
        ]);

        $registro->assertOk();
        $registro->assertJson(['success' => true, 'registradas' => 5]);

        $this->assertSame([], $this->painel->listar());
        $this->assertSame(
            'Ana Comercial',
            ContractVisitJustification::query()->firstOrFail()->justified_by_name,
            'o autor da justificativa precisa ficar gravado por nome'
        );
        $this->assertSame(
            $usuario->id,
            (int) ContractVisitJustification::query()->firstOrFail()->justified_by
        );

        $justificativa = ContractVisitJustification::query()
            ->whereDate('expected_date', '2026-04-25')
            ->firstOrFail();

        $remocao = $this->actingAs($usuario)
            ->deleteJson("/contracts/{$contrato->id}/visitas/justificativas/{$justificativa->id}");

        $remocao->assertOk();
        $remocao->assertJson(['success' => true]);

        $painel = $this->painel->listar();

        $this->assertCount(1, $painel);
        $this->assertSame(['2026-04-25'], array_column($painel[0]['datas_sem_visita'], 'data'));
    }

    // -----------------------------------------------------------------
    // 4. Isolamento entre empresas
    // -----------------------------------------------------------------

    /**
     * Duas empresas com o mesmo cenário de contrato. A empresa 1 justifica
     * todas as datas; o painel da empresa 2 não pode se mexer. Vazamento aqui
     * significaria uma dedetizadora silenciando o alerta de conformidade da
     * concorrente.
     */
    public function test_justificativa_de_uma_empresa_nao_tira_a_pendencia_da_outra(): void
    {
        $empresaUm = Company::query()->firstOrFail();
        $empresaDois = Company::create(['name' => 'Dedetizadora Dois']);

        $contratoUm = TenantAtual::comTenant($empresaUm->id, fn () => $this->criarContratoComLacunas());
        $contratoDois = TenantAtual::comTenant($empresaDois->id, fn () => $this->criarContratoComLacunas());

        TenantAtual::comTenant($empresaUm->id, fn () => $this->justificativas->justificar(
            $contratoUm,
            self::DATAS_DO_PAINEL,
            'Contrato suspenso no período, decisão da empresa 1.'
        ));

        $painelUm = TenantAtual::comTenant($empresaUm->id, fn () => $this->painel->listar());
        $painelDois = TenantAtual::comTenant($empresaDois->id, fn () => $this->painel->listar());

        $this->assertSame([], $painelUm, 'a empresa 1 justificou e o painel dela deveria estar limpo');

        $this->assertCount(1, $painelDois, 'a justificativa da empresa 1 apagou a pendência da empresa 2');
        $this->assertSame($contratoDois->id, $painelDois[0]['contrato_id']);
        $this->assertSame(
            self::DATAS_DO_PAINEL,
            array_column($painelDois[0]['datas_sem_visita'], 'data'),
            'a empresa 2 perdeu datas do próprio painel por causa da decisão da empresa 1'
        );
    }

    public function test_justificativa_de_outra_empresa_nao_aparece_em_consulta_nem_na_tela_do_contrato(): void
    {
        $empresaUm = Company::query()->firstOrFail();
        $empresaDois = Company::create(['name' => 'Dedetizadora Dois']);

        $contratoUm = TenantAtual::comTenant($empresaUm->id, fn () => $this->criarContratoComLacunas());
        $contratoDois = TenantAtual::comTenant($empresaDois->id, fn () => $this->criarContratoComLacunas());

        TenantAtual::comTenant($empresaUm->id, fn () => $this->justificativas->justificar(
            $contratoUm,
            ['2026-01-25'],
            'Segredo comercial da empresa 1.'
        ));

        $vistasPelaDois = TenantAtual::comTenant(
            $empresaDois->id,
            fn () => ContractVisitJustification::query()->get()
        );

        $this->assertCount(0, $vistasPelaDois, 'a empresa 2 enxergou justificativa da empresa 1');

        $linhasDaDois = TenantAtual::comTenant(
            $empresaDois->id,
            fn () => $this->geracao->visitasComSituacao($contratoDois)
        );

        foreach ($linhasDaDois as $linha) {
            $this->assertNull(
                $linha['justificativa'],
                "a linha de {$linha['data']} do contrato da empresa 2 trouxe justificativa da empresa 1"
            );
        }

        $this->assertSame(
            'Segredo comercial da empresa 1.',
            TenantAtual::comTenant(
                $empresaUm->id,
                fn () => ContractVisitJustification::query()->firstOrFail()->reason
            ),
            'a empresa dona precisa continuar enxergando a própria justificativa'
        );
    }

    public function test_a_justificativa_nasce_carimbada_com_a_empresa_do_contrato(): void
    {
        $empresaDois = Company::create(['name' => 'Dedetizadora Dois']);

        $contrato = TenantAtual::comTenant($empresaDois->id, fn () => $this->criarContratoComLacunas());

        TenantAtual::comTenant($empresaDois->id, fn () => $this->justificativas->justificar(
            $contrato,
            ['2026-01-25'],
            'Motivo registrado dentro da empresa 2.'
        ));

        $justificativa = TenantAtual::comTenant(
            $empresaDois->id,
            fn () => ContractVisitJustification::query()->firstOrFail()
        );

        $this->assertSame($empresaDois->id, (int) $justificativa->company_id);
    }

    // -----------------------------------------------------------------
    // Uma decisão por data
    // -----------------------------------------------------------------

    public function test_justificar_a_mesma_data_duas_vezes_atualiza_o_motivo_sem_duplicar(): void
    {
        $contrato = $this->criarContratoComLacunas();

        $this->justificativas->justificar($contrato, ['2026-01-25'], 'Primeiro motivo registrado.');
        $resultado = $this->justificativas->justificar($contrato, ['2026-01-25'], 'Motivo corrigido depois de conferir a agenda.');

        $this->assertTrue($resultado['success']);
        $this->assertSame(0, $resultado['data']['registradas']);
        $this->assertSame(1, $resultado['data']['atualizadas']);

        $this->assertSame(1, ContractVisitJustification::query()->count(), 'a mesma data ficou com duas justificativas');
        $this->assertSame(
            'Motivo corrigido depois de conferir a agenda.',
            ContractVisitJustification::query()->firstOrFail()->reason
        );
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Contrato periódico mensal começando em 25/01/2026: em 25/07/2026 ele tem
     * cinco datas previstas vencidas há mais de 30 dias e nenhuma OS, que é
     * exatamente o cenário que o painel acusa e que a geração não resolve.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function criarContratoComLacunas(array $atributos = []): Contract
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
            'start_date' => '2026-01-25',
            'end_date' => '2026-12-25',
        ], $atributos));
    }

    private function criarOs(Contract $contrato, string $data, string $status, ?int $numero): WorkOrder
    {
        $contrato->loadMissing('address');

        return WorkOrderFactory::new()->create([
            'client_id' => $contrato->address->client_id,
            'address_id' => $contrato->address_id,
            'contract_id' => $contrato->id,
            'origem' => 'contrato',
            'visita_numero' => $numero,
            'scheduled_date' => $data,
            'status' => $status,
        ]);
    }

    private function usuarioComPapel(string $papel, ?string $nome = null): User
    {
        $usuario = User::factory()->create($nome === null ? [] : ['name' => $nome]);
        $usuario->assignRole($papel);

        return $usuario;
    }
}
