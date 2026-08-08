<?php

namespace Tests\Feature;

use App\Listeners\RegistraExecucaoAgendada;
use App\Models\Company;
use App\Models\CompanyContractAlertSetting;
use App\Models\Contract;
use App\Models\NotificationQueue;
use App\Models\ScheduledTaskRun;
use App\Services\ContractAlertService;
use App\Services\NotificationService;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use App\Support\RotinasAgendadas;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 23.5 do Plano 23: aviso de contrato a vencer (marcos configuráveis por
 * tenant) e pendência de contrato vencido sem tratativa (aviso semanal até
 * decisão).
 *
 * Cobre os critérios de aceitação da task, um por bloco de testes, incluindo
 * o achado da própria sessão: um contrato renovado (Task 23.4 preserva o
 * anterior em `contracts`, com `end_date` no passado ou ainda no futuro,
 * marcado `situacao_renovacao = 'renovado'`) nunca pode voltar a aparecer
 * como "a vencer" ou "vencido sem tratativa".
 */
class ContractAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Contrato a vencer: um aviso por marco (60, 30 e 15 dias)
    // -----------------------------------------------------------------

    /**
     * O mesmo contrato avisa três vezes ao longo do tempo, uma por marco, e
     * nenhuma vez a mais. Mesmo critério de
     * `EventosDeNotificacaoTest::test_certificado_a_trinta_quinze_e_sete_dias_gera_tres_avisos_distintos`.
     */
    public function test_contrato_a_60_30_e_15_dias_do_vencimento_gera_tres_avisos_distintos(): void
    {
        $fim = '2026-10-01';
        $contrato = $this->criarContrato(['end_date' => $fim]);

        foreach ([60, 30, 15] as $dias) {
            $this->viajarParaDia(
                CarbonImmutable::parse($fim, BusinessDate::fuso())->subDays($dias)->toDateString()
            );

            $this->artisan('contratos:verificar-vencimento')->assertSuccessful();
        }

        $avisos = $this->itensDoEvento(EventosDeNotificacao::CONTRATO_A_VENCER);

        $this->assertCount(3, $avisos, 'os três marcos do mesmo contrato não geraram três avisos');

        $chaves = $avisos->pluck('chave_idempotencia')->all();
        $this->assertCount(3, array_unique($chaves), 'os três marcos saíram com a mesma chave de idempotência');

        foreach ([60, 30, 15] as $dias) {
            $this->assertTrue(
                $avisos->contains(
                    fn (NotificationQueue $aviso): bool => str_ends_with((string) $aviso->chave_idempotencia, ':'.$dias)
                ),
                "faltou o aviso do marco de {$dias} dias"
            );
        }

        $this->assertSame(
            [(int) $contrato->id],
            array_values(array_unique($avisos->map(
                fn (NotificationQueue $aviso): int => (int) ($aviso->contexto['referencia_id'] ?? 0)
            )->all()))
        );
    }

    public function test_rodar_a_rotina_duas_vezes_no_mesmo_dia_nao_duplica_o_aviso_de_a_vencer(): void
    {
        $this->criarContrato(['end_date' => $this->emDias(30)]);

        $this->artisan('contratos:verificar-vencimento')->assertSuccessful();
        $this->artisan('contratos:verificar-vencimento')
            ->expectsOutputToContain('Já estavam na fila')
            ->assertSuccessful();

        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::CONTRATO_A_VENCER));
    }

    public function test_contrato_fora_dos_marcos_configurados_nao_gera_aviso(): void
    {
        $this->criarContrato(['end_date' => $this->emDias(45)]);

        $this->artisan('contratos:verificar-vencimento')->assertSuccessful();

        $this->assertCount(0, $this->itensDoEvento(EventosDeNotificacao::CONTRATO_A_VENCER));
    }

    /**
     * O primeiro aviso declara a entrada do contrato na janela de alerta: sem
     * nenhuma tratativa registrada ainda, `situacao_renovacao` sai de `null`
     * para `pendente`. Ver o docblock da migration
     * `add_renovacao_to_contracts_table`.
     */
    public function test_primeiro_alerta_marca_o_contrato_como_pendente(): void
    {
        $contrato = $this->criarContrato(['end_date' => $this->emDias(30)]);
        $this->assertNull($contrato->situacao_renovacao);

        $this->artisan('contratos:verificar-vencimento')->assertSuccessful();

        $this->assertSame('pendente', $contrato->fresh()->situacao_renovacao);
    }

    // -----------------------------------------------------------------
    // Prazo configurável por tenant
    // -----------------------------------------------------------------

    public function test_o_prazo_configuravel_do_tenant_e_respeitado(): void
    {
        $this->naEmpresa(function (): void {
            CompanyContractAlertSetting::query()->create(['dias_antecedencia' => [45, 10]]);
        });

        $contratoNoMarcoConfigurado = $this->criarContrato(['end_date' => $this->emDias(45)]);
        // 30 é marco padrão do sistema, mas este tenant não o configurou.
        $this->criarContrato(['end_date' => $this->emDias(30)]);

        $this->artisan('contratos:verificar-vencimento')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::CONTRATO_A_VENCER);
        $this->assertCount(1, $avisos, 'só o contrato no marco configurado pelo tenant podia gerar aviso');

        $this->assertSame(
            (int) $contratoNoMarcoConfigurado->id,
            (int) ($avisos->first()->contexto['referencia_id'] ?? 0)
        );
    }

    public function test_marcos_devolve_o_padrao_do_sistema_quando_o_tenant_nao_configurou_nada(): void
    {
        $marcos = $this->naEmpresa(fn (): array => app(ContractAlertService::class)->marcos());

        $this->assertSame([60, 30, 15], $marcos);
    }

    // -----------------------------------------------------------------
    // Contrato vencido sem tratativa: pendência listável e aviso semanal
    // -----------------------------------------------------------------

    public function test_contrato_vencido_sem_tratativa_aparece_na_pendencia_e_avisa_semanalmente(): void
    {
        $this->viajarPara('1970-01-01 12:00:00');

        $contrato = $this->criarContrato(['end_date' => $this->emDias(-10)]);

        $pendencias = $this->naEmpresa(fn (): Collection => app(ContractAlertService::class)->vencidosSemTratativa());
        $this->assertCount(1, $pendencias);
        $this->assertSame($contrato->id, $pendencias->first()->id);

        $this->artisan('contratos:verificar-vencimento')->assertSuccessful();
        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::CONTRATO_A_VENCER));

        // Mesmo dia, rotina rodada de novo: não duplica.
        $this->artisan('contratos:verificar-vencimento')->assertSuccessful();
        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::CONTRATO_A_VENCER));

        // Seis dias depois, ainda dentro do mesmo marco semanal: nenhum aviso novo.
        $this->viajarPara('1970-01-07 12:00:00');
        $this->artisan('contratos:verificar-vencimento')->assertSuccessful();
        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::CONTRATO_A_VENCER));

        // Mais um dia (sete no total): marco novo, aviso novo.
        $this->viajarPara('1970-01-08 12:00:00');
        $this->artisan('contratos:verificar-vencimento')->assertSuccessful();
        $this->assertCount(2, $this->itensDoEvento(EventosDeNotificacao::CONTRATO_A_VENCER));
    }

    // -----------------------------------------------------------------
    // `em_negociacao`: pausa por 30 dias, depois volta
    // -----------------------------------------------------------------

    public function test_marcar_em_negociacao_interrompe_o_aviso_semanal_por_30_dias_e_depois_volta(): void
    {
        $this->viajarPara('1970-01-01 12:00:00');

        $contrato = $this->criarContrato(['end_date' => $this->emDias(-10)]);

        $this->artisan('contratos:verificar-vencimento')->assertSuccessful();
        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::CONTRATO_A_VENCER));

        // Marcado em negociação hoje (1970-01-01): `Contract::booted()` grava
        // `em_negociacao_em` sozinho.
        $contrato->situacao_renovacao = 'em_negociacao';
        $contrato->save();
        $this->assertNotNull($contrato->fresh()->em_negociacao_em);

        // Continua aparecendo na pendência do painel, mesmo pausado.
        $pendencias = $this->naEmpresa(fn (): Collection => app(ContractAlertService::class)->vencidosSemTratativa());
        $this->assertCount(1, $pendencias, 'em_negociacao continua sendo pendência, só o e-mail semanal pausa');

        // Sete dias depois: o marco semanal mudaria, mas a negociação pausa o aviso.
        $this->viajarPara('1970-01-08 12:00:00');
        $this->artisan('contratos:verificar-vencimento')
            ->expectsOutputToContain('Pausados por negociação em andamento: 1')
            ->assertSuccessful();
        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::CONTRATO_A_VENCER), 'em negociação, não podia reenviar');

        // 29 dias corridos depois de marcado: ainda dentro da pausa de 30 dias.
        $this->viajarPara('1970-01-30 12:00:00');
        $this->artisan('contratos:verificar-vencimento')->assertSuccessful();
        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::CONTRATO_A_VENCER), 'ainda dentro dos 30 dias de pausa');

        // 30 dias corridos depois: a pausa terminou e a negociação não virou
        // decisão final, então o aviso semanal volta sozinho.
        $this->viajarPara('1970-01-31 12:00:00');
        $this->artisan('contratos:verificar-vencimento')->assertSuccessful();
        $this->assertCount(2, $this->itensDoEvento(EventosDeNotificacao::CONTRATO_A_VENCER), 'passados 30 dias sem decisão final, o aviso precisa voltar');
    }

    // -----------------------------------------------------------------
    // `renovado` e `nao_renovado` encerram os avisos
    // -----------------------------------------------------------------

    public function test_contrato_nao_renovado_encerra_os_avisos(): void
    {
        $this->criarContrato([
            'end_date' => $this->emDias(-5),
            'situacao_renovacao' => 'nao_renovado',
        ]);

        $pendencias = $this->naEmpresa(fn (): Collection => app(ContractAlertService::class)->vencidosSemTratativa());
        $this->assertCount(0, $pendencias);

        $this->artisan('contratos:verificar-vencimento')->assertSuccessful();

        $this->assertCount(0, $this->itensDoEvento(EventosDeNotificacao::CONTRATO_A_VENCER));
    }

    /**
     * Achado desta sessão: contrato renovado continua na tabela (histórico
     * preservado, Task 23.4), com `end_date` no passado. Sem o filtro de
     * `situacao_renovacao`, ele voltaria a aparecer como pendência de
     * tratativa e a gerar aviso semanal para sempre.
     */
    public function test_contrato_renovado_com_end_date_no_passado_nao_gera_alerta_de_vencido_sem_tratativa(): void
    {
        $renovado = $this->criarContrato([
            'end_date' => $this->emDias(-15),
            'situacao_renovacao' => 'renovado',
        ]);

        $pendencias = $this->naEmpresa(fn (): Collection => app(ContractAlertService::class)->vencidosSemTratativa());
        $this->assertCount(0, $pendencias, 'contrato já renovado não podia aparecer como pendência de tratativa');

        $this->artisan('contratos:verificar-vencimento')->assertSuccessful();

        $this->assertCount(
            0,
            $this->itensDoEvento(EventosDeNotificacao::CONTRATO_A_VENCER),
            'contrato renovado, mesmo com end_date no passado, não podia gerar aviso'
        );

        // O anterior não é tocado pela rotina de alerta: continua `renovado`.
        $this->assertSame('renovado', $renovado->fresh()->situacao_renovacao);
    }

    /**
     * Mesmo achado, do lado do contrato ainda vigente: uma renovação
     * antecipada (dentro da janela de 90 dias da Task 23.4) deixa o anterior
     * com `end_date` ainda no futuro. Sem o filtro, esse `end_date` caindo
     * exatamente em um dos marcos voltaria a gerar aviso de "a vencer" para
     * um contrato que já foi decidido.
     */
    public function test_contrato_renovado_com_end_date_futuro_no_marco_nao_gera_alerta_de_a_vencer(): void
    {
        $this->criarContrato([
            'end_date' => $this->emDias(30),
            'situacao_renovacao' => 'renovado',
        ]);

        $this->artisan('contratos:verificar-vencimento')->assertSuccessful();

        $this->assertCount(0, $this->itensDoEvento(EventosDeNotificacao::CONTRATO_A_VENCER));
    }

    // -----------------------------------------------------------------
    // `ContractAlertService`: consultas puras
    // -----------------------------------------------------------------

    public function test_a_vencer_devolve_so_o_contrato_no_marco_exato_e_exclui_situacoes_encerradas(): void
    {
        $alvo = $this->criarContrato(['end_date' => $this->emDias(30)]);
        $this->criarContrato(['end_date' => $this->emDias(29)]);
        $this->criarContrato(['end_date' => $this->emDias(30), 'situacao_renovacao' => 'renovado']);

        $resultado = $this->naEmpresa(fn (): Collection => app(ContractAlertService::class)->aVencer(30));

        $this->assertSame([$alvo->id], $resultado->pluck('id')->all());
    }

    public function test_vencidos_sem_tratativa_inclui_pendente_e_em_negociacao_mas_exclui_encerrados(): void
    {
        $semTratativa = $this->criarContrato(['end_date' => $this->emDias(-3)]);
        $pendente = $this->criarContrato(['end_date' => $this->emDias(-3), 'situacao_renovacao' => 'pendente']);
        $emNegociacao = $this->criarContrato(['end_date' => $this->emDias(-3), 'situacao_renovacao' => 'em_negociacao']);
        $this->criarContrato(['end_date' => $this->emDias(-3), 'situacao_renovacao' => 'renovado']);
        $this->criarContrato(['end_date' => $this->emDias(-3), 'situacao_renovacao' => 'nao_renovado']);
        $this->criarContrato(['end_date' => $this->emDias(3)]); // ainda não venceu

        $resultado = $this->naEmpresa(fn (): Collection => app(ContractAlertService::class)->vencidosSemTratativa());

        $idsEsperados = [$semTratativa->id, $pendente->id, $emNegociacao->id];
        sort($idsEsperados);

        $idsObtidos = $resultado->pluck('id')->all();
        sort($idsObtidos);

        $this->assertSame($idsEsperados, $idsObtidos);
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
        $contratoComDefeito = $this->criarContrato(['end_date' => $this->emDias(30)]);

        $segunda = Company::create(['name' => 'Dedetizadora B', 'email' => 'contato@b.test']);

        $contratoB = TenantAtual::comTenant($segunda->id, function (): Contract {
            $cliente = ClientFactory::new()->create();
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);

            return Contract::query()->create([
                'address_id' => $endereco->id,
                'contract_number' => 'CONT-'.uniqid(),
                'service_type' => 'pontual',
                'service_value' => '1000.00',
                'start_date' => BusinessDate::hoje()->subYear()->toDateString(),
                'end_date' => BusinessDate::hoje()->addDays(30)->toDateString(),
            ]);
        });

        $this->app->instance(
            NotificationService::class,
            new NotificationServiceQueFalhaNoContrato((int) $contratoComDefeito->id)
        );

        $this->artisan('contratos:verificar-vencimento')
            ->expectsOutputToContain('Os demais registros continuam sendo processados.')
            ->assertExitCode(Command::FAILURE);

        $avisos = $this->itensDoEvento(EventosDeNotificacao::CONTRATO_A_VENCER);
        $referencias = $avisos->map(fn (NotificationQueue $aviso): int => (int) ($aviso->contexto['referencia_id'] ?? 0));

        $this->assertNotContains(
            (int) $contratoComDefeito->id,
            $referencias->all(),
            'o contrato com defeito não podia ter gerado aviso'
        );

        $this->assertContains(
            (int) $contratoB->id,
            $referencias->all(),
            'a segunda empresa precisava continuar sendo processada'
        );
    }

    // -----------------------------------------------------------------
    // Registro em scheduled_task_runs
    // -----------------------------------------------------------------

    public function test_rotina_esta_registrada_em_rotinas_agendadas_e_aparece_em_scheduled_task_runs(): void
    {
        $this->assertArrayHasKey('contratos:verificar-vencimento', RotinasAgendadas::DIARIAS);

        $this->assertSame(0, ScheduledTaskRun::query()->count());

        $this->artisan('schedule:test', ['--name' => 'contratos:verificar-vencimento'])->assertSuccessful();

        $rodada = ScheduledTaskRun::query()->daTarefa('contratos:verificar-vencimento')->first();

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

    /**
     * Contrato pontual, salvo com endereço e cliente próprios. Mesmo padrão
     * de `ContractRenewalServiceTest::criarContrato`.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function criarContrato(array $atributos = []): Contract
    {
        return $this->naEmpresa(function () use ($atributos): Contract {
            $cliente = ClientFactory::new()->create();
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);

            return Contract::query()->create(array_merge([
                'address_id' => $endereco->id,
                'contract_number' => 'CONT-'.uniqid(),
                'service_type' => 'pontual',
                'service_value' => '1000.00',
                'start_date' => BusinessDate::hoje()->subYear()->toDateString(),
                'end_date' => BusinessDate::hoje()->addDays(30)->toDateString(),
            ], $atributos));
        });
    }

    /**
     * Itens do evento em todas as empresas, na ordem em que entraram na fila.
     *
     * @return Collection<int, NotificationQueue>
     */
    private function itensDoEvento(string $evento): Collection
    {
        return NotificationQueue::query()->where('evento', $evento)->orderBy('id')->get();
    }

    /**
     * Dia no fuso do negócio, a tantos dias de hoje.
     */
    private function emDias(int $dias): string
    {
        return BusinessDate::hoje()->addDays($dias)->toDateString();
    }

    /**
     * Congela o relógio às 9h do dia informado, no fuso do negócio, já
     * convertido para o instante UTC equivalente (fuso em que a aplicação
     * roda). Mesmo critério de `EventosDeNotificacaoTest::fixarRelogioEm`.
     */
    private function viajarParaDia(string $diaEmBrasilia): void
    {
        $emUtc = CarbonImmutable::parse($diaEmBrasilia.' 09:00', BusinessDate::fuso())->setTimezone('UTC');

        Carbon::setTestNow(Carbon::parse($emUtc->format('Y-m-d H:i:s'), 'UTC'));
    }

    /**
     * Congela a hora corrente em um instante UTC, que é o fuso em que a
     * aplicação roda. Mesmo motivo do `viajarPara` de `StockAlertServiceTest`:
     * congelar em fuso de negócio faria o teste interpretar dado gravado em
     * UTC como se já estivesse em Brasília.
     */
    private function viajarPara(string $instanteEmUtc): void
    {
        $this->travelTo(CarbonImmutable::parse($instanteEmUtc, 'UTC'));
    }
}

/**
 * Service que estoura para um contrato escolhido e se comporta normalmente no
 * resto. Mesmo padrão de `NotificationServiceQueFalhaNoProduto`
 * (`StockAlertServiceTest`) e `NotificationServiceQueFalhaNoCertificado`
 * (`EventosDeNotificacaoTest`).
 */
class NotificationServiceQueFalhaNoContrato extends NotificationService
{
    public function __construct(private readonly int $contratoQueFalha) {}

    /**
     * @param  array<string, mixed>  $opcoes
     * @return array{resultado: string, criado: bool, item: NotificationQueue|null, canal: string|null, chave_idempotencia: string|null, mensagem: string}
     */
    public function enfileirar(string $evento, Model $referencia, array $opcoes = []): array
    {
        if ($referencia instanceof Contract && (int) $referencia->getKey() === $this->contratoQueFalha) {
            throw new RuntimeException("Dado inconsistente no contrato #{$this->contratoQueFalha}.");
        }

        return parent::enfileirar($evento, $referencia, $opcoes);
    }
}
