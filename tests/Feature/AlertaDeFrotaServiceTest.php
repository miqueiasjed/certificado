<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\NotificationQueue;
use App\Models\Refueling;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\VehicleMaintenance;
use App\Services\Fleet\AlertaDeFrotaService;
use App\Services\NotificationService;
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
 * Task 27.3 do Plano 27: alertas de manutenção preventiva e de documentação do
 * veículo.
 *
 * Cobre os critérios de aceitação da task, um por bloco:
 *
 * - manutenção vencendo por data (30 e 7 dias) gera um aviso por marco;
 * - manutenção vencendo por quilometragem (1.000 e 200 km) idem;
 * - com os dois critérios preenchidos, **só o que vier primeiro dispara**, e
 *   nunca os dois na mesma passada;
 * - documento vencendo em 60, 30 e 7 dias gera três avisos, um por marco;
 * - documento vencido reenvia semanalmente;
 * - veículo sem abastecimento há 35 dias gera o aviso de quilometragem
 *   desatualizada;
 * - rodar a rotina duas vezes no mesmo dia não duplica nada;
 * - falha em um tenant não interrompe os demais.
 */
class AlertaDeFrotaServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private User $usuario;

    private AlertaDeFrotaService $servico;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        // Mesmo motivo de `StockAlertServiceTest`: a empresa 1 vem da migration
        // de fundação sem e-mail, e sem e-mail o disparo para a empresa cairia
        // em "sem_destino" e nenhum aviso nasceria na fila.
        Company::query()->whereKey(1)->update([
            'name' => 'Dedetizadora A',
            'email' => 'contato@a.test',
        ]);

        $this->empresa = Company::query()->findOrFail(1);
        $this->servico = app(AlertaDeFrotaService::class);

        $this->usuario = $this->naEmpresa(fn (): User => User::factory()->create([
            'name' => 'Gestor de frota',
            'is_active' => true,
        ]));

        TenantAtual::limpar();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Manutenção por data
    // -----------------------------------------------------------------

    public function test_manutencao_vencendo_em_30_dias_gera_um_aviso_no_marco_de_30(): void
    {
        $veiculo = $this->criarVeiculo();
        $this->agendarManutencao($veiculo, proximaEmData: $this->emDias(30));

        $this->artisan('frota:verificar')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::MANUTENCAO_DE_VEICULO_A_VENCER);

        $this->assertCount(1, $avisos, 'só a janela de 30 dias foi alcançada');
        $this->assertMarco($avisos, 'data-30');
    }

    /**
     * Janelas cumulativas, mesmo comportamento do lote do Plano 17: uma
     * manutenção a 5 dias do vencimento está dentro da janela de 30 **e** da de
     * 7, e cada uma vira um marco próprio. Não é repetição: são dois avisos com
     * urgências diferentes.
     */
    public function test_manutencao_vencendo_em_7_dias_gera_avisos_nos_marcos_de_30_e_de_7(): void
    {
        $veiculo = $this->criarVeiculo();
        $this->agendarManutencao($veiculo, proximaEmData: $this->emDias(5));

        $this->artisan('frota:verificar')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::MANUTENCAO_DE_VEICULO_A_VENCER);

        $this->assertCount(2, $avisos);
        $this->assertMarco($avisos, 'data-30');
        $this->assertMarco($avisos, 'data-7');
    }

    public function test_manutencao_fora_das_janelas_nao_gera_aviso(): void
    {
        $veiculo = $this->criarVeiculo();
        $this->agendarManutencao($veiculo, proximaEmData: $this->emDias(120));

        $this->artisan('frota:verificar')->assertSuccessful();

        $this->assertCount(0, $this->itensDoEvento(EventosDeNotificacao::MANUTENCAO_DE_VEICULO_A_VENCER));
    }

    public function test_manutencao_ja_realizada_ou_cancelada_nao_gera_aviso(): void
    {
        $veiculo = $this->criarVeiculo();

        $this->naEmpresa(fn () => VehicleMaintenance::create([
            'vehicle_id' => $veiculo->id,
            'tipo' => 'preventiva',
            'descricao' => 'Troca de óleo já feita',
            'proxima_em_data' => $this->emDias(10),
            'situacao' => 'realizada',
        ]));

        $this->naEmpresa(fn () => VehicleMaintenance::create([
            'vehicle_id' => $veiculo->id,
            'tipo' => 'preventiva',
            'descricao' => 'Revisão cancelada',
            'proxima_em_data' => $this->emDias(10),
            'situacao' => 'cancelada',
        ]));

        $this->artisan('frota:verificar')->assertSuccessful();

        $this->assertCount(0, $this->itensDoEvento(EventosDeNotificacao::MANUTENCAO_DE_VEICULO_A_VENCER));
    }

    // -----------------------------------------------------------------
    // Manutenção por quilometragem
    // -----------------------------------------------------------------

    public function test_manutencao_faltando_1000_km_gera_um_aviso_no_marco_de_1000(): void
    {
        $veiculo = $this->criarVeiculo(kmAtual: 9000);
        $this->agendarManutencao($veiculo, proximaEmKm: 10000);

        $this->artisan('frota:verificar')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::MANUTENCAO_DE_VEICULO_A_VENCER);

        $this->assertCount(1, $avisos);
        $this->assertMarco($avisos, 'km-1000');
    }

    public function test_manutencao_faltando_200_km_gera_avisos_nos_marcos_de_1000_e_de_200(): void
    {
        $veiculo = $this->criarVeiculo(kmAtual: 9900);
        $this->agendarManutencao($veiculo, proximaEmKm: 10000);

        $this->artisan('frota:verificar')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::MANUTENCAO_DE_VEICULO_A_VENCER);

        $this->assertCount(2, $avisos);
        $this->assertMarco($avisos, 'km-1000');
        $this->assertMarco($avisos, 'km-200');
    }

    // -----------------------------------------------------------------
    // O que vier primeiro dispara, sem duplicar
    // -----------------------------------------------------------------

    /**
     * Data e quilometragem preenchidas, com a data muito mais perto: nenhum
     * aviso pelo critério de quilometragem, porque os dois diriam a mesma coisa
     * sobre a mesma manutenção.
     */
    public function test_com_os_dois_criterios_so_a_data_dispara_quando_ela_vem_primeiro(): void
    {
        $veiculo = $this->criarVeiculo(kmAtual: 9100);
        $this->agendarManutencao($veiculo, proximaEmData: $this->emDias(3), proximaEmKm: 10000);

        $this->artisan('frota:verificar')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::MANUTENCAO_DE_VEICULO_A_VENCER);

        // 3/30 = 0,10 contra 900/1000 = 0,90: a data chegou primeiro.
        $this->assertCount(2, $avisos, 'só as duas janelas do critério de data');
        $this->assertMarco($avisos, 'data-30');
        $this->assertMarco($avisos, 'data-7');
    }

    public function test_com_os_dois_criterios_so_a_quilometragem_dispara_quando_ela_vem_primeiro(): void
    {
        $veiculo = $this->criarVeiculo(kmAtual: 9950);
        $this->agendarManutencao($veiculo, proximaEmData: $this->emDias(28), proximaEmKm: 10000);

        $this->artisan('frota:verificar')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::MANUTENCAO_DE_VEICULO_A_VENCER);

        // 28/30 = 0,93 contra 50/1000 = 0,05: a quilometragem chegou primeiro.
        $this->assertCount(2, $avisos, 'só as duas janelas do critério de quilometragem');
        $this->assertMarco($avisos, 'km-1000');
        $this->assertMarco($avisos, 'km-200');
    }

    public function test_rodar_a_rotina_duas_vezes_no_mesmo_dia_nao_duplica_o_aviso_de_manutencao(): void
    {
        $veiculo = $this->criarVeiculo();
        $this->agendarManutencao($veiculo, proximaEmData: $this->emDias(20));

        $this->artisan('frota:verificar')->assertSuccessful();
        $this->artisan('frota:verificar')->assertSuccessful();

        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::MANUTENCAO_DE_VEICULO_A_VENCER));
    }

    // -----------------------------------------------------------------
    // Documentos do veículo
    // -----------------------------------------------------------------

    public function test_documento_vencendo_em_60_30_e_7_dias_gera_tres_avisos_um_por_marco(): void
    {
        $veiculo = $this->criarVeiculo();
        $this->criarDocumento($veiculo, $this->emDias(5));

        $this->artisan('frota:verificar')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::DOCUMENTO_DE_VEICULO_A_VENCER);

        $this->assertCount(3, $avisos);

        foreach (['60', '30', '7'] as $marco) {
            $this->assertMarco($avisos, $marco);
        }
    }

    public function test_documento_fora_das_janelas_nao_gera_aviso(): void
    {
        $veiculo = $this->criarVeiculo();
        $this->criarDocumento($veiculo, $this->emDias(180));

        $this->artisan('frota:verificar')->assertSuccessful();

        $this->assertCount(0, $this->itensDoEvento(EventosDeNotificacao::DOCUMENTO_DE_VEICULO_A_VENCER));
    }

    /**
     * Mesmo raciocínio do lote vencido do Plano 17: o marco muda a cada sete
     * dias corridos desde uma época fixa (1970-01-01), e não a cada virada de
     * semana do calendário. Viajar a partir da própria época garante que os
     * primeiros seis dias caem no mesmo marco.
     */
    public function test_documento_vencido_reenvia_semanalmente(): void
    {
        $this->viajarPara('1970-01-01 12:00:00');

        $veiculo = $this->criarVeiculo();
        $this->criarDocumento($veiculo, $this->emDias(-10));

        $this->artisan('frota:verificar')->assertSuccessful();
        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::DOCUMENTO_DE_VEICULO_A_VENCER));

        // Mesmo dia, rotina rodada de novo: não duplica.
        $this->artisan('frota:verificar')->assertSuccessful();
        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::DOCUMENTO_DE_VEICULO_A_VENCER));

        // Seis dias depois, ainda no mesmo marco semanal: nenhum aviso novo.
        $this->viajarPara('1970-01-07 12:00:00');
        $this->artisan('frota:verificar')->assertSuccessful();
        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::DOCUMENTO_DE_VEICULO_A_VENCER));

        // Sete dias no total: marco novo, aviso novo.
        $this->viajarPara('1970-01-08 12:00:00');
        $this->artisan('frota:verificar')->assertSuccessful();
        $this->assertCount(2, $this->itensDoEvento(EventosDeNotificacao::DOCUMENTO_DE_VEICULO_A_VENCER));
    }

    // -----------------------------------------------------------------
    // Quilometragem desatualizada
    // -----------------------------------------------------------------

    public function test_veiculo_sem_abastecimento_ha_35_dias_gera_o_aviso_de_quilometragem_desatualizada(): void
    {
        $veiculo = $this->criarVeiculo();
        $this->abastecer($veiculo, $this->emDias(-35), km: 9000);

        $this->artisan('frota:verificar')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::QUILOMETRAGEM_DE_VEICULO_DESATUALIZADA);

        $this->assertCount(1, $avisos);
        $this->assertStringContainsString($veiculo->placa, $avisos->first()->corpo);
    }

    public function test_veiculo_abastecido_ha_poucos_dias_nao_gera_o_aviso(): void
    {
        $veiculo = $this->criarVeiculo();
        $this->abastecer($veiculo, $this->emDias(-5), km: 9000);

        $this->artisan('frota:verificar')->assertSuccessful();

        $this->assertCount(0, $this->itensDoEvento(EventosDeNotificacao::QUILOMETRAGEM_DE_VEICULO_DESATUALIZADA));
    }

    /**
     * Veículo recém-cadastrado e sem nenhum abastecimento não está com a
     * quilometragem desatualizada: está só começando. A referência, nesse caso,
     * é a data de cadastro.
     */
    public function test_veiculo_recem_cadastrado_sem_abastecimento_nao_gera_o_aviso(): void
    {
        $this->criarVeiculo();

        $this->artisan('frota:verificar')->assertSuccessful();

        $this->assertCount(0, $this->itensDoEvento(EventosDeNotificacao::QUILOMETRAGEM_DE_VEICULO_DESATUALIZADA));
    }

    public function test_veiculo_inativo_nao_gera_o_aviso_de_quilometragem(): void
    {
        $veiculo = $this->criarVeiculo(situacao: 'inativo');
        $this->abastecer($veiculo, $this->emDias(-90), km: 9000);

        $this->artisan('frota:verificar')->assertSuccessful();

        $this->assertCount(0, $this->itensDoEvento(EventosDeNotificacao::QUILOMETRAGEM_DE_VEICULO_DESATUALIZADA));
    }

    // -----------------------------------------------------------------
    // Falha isolada por tenant
    // -----------------------------------------------------------------

    public function test_falha_em_um_tenant_nao_interrompe_os_demais(): void
    {
        $veiculoComDefeito = $this->criarVeiculo();
        $this->criarDocumento($veiculoComDefeito, $this->emDias(5));

        $segunda = Company::create(['name' => 'Dedetizadora B', 'email' => 'contato@b.test']);

        $veiculoB = TenantAtual::comTenant($segunda->id, fn (): Vehicle => Vehicle::create([
            'placa' => 'BBB'.random_int(1000, 9999),
            'modelo' => 'Strada',
            'marca' => 'Fiat',
            'tipo' => 'utilitario',
            'km_atual' => 1000,
            'situacao' => 'ativo',
        ]));

        TenantAtual::comTenant($segunda->id, fn () => VehicleDocument::create([
            'vehicle_id' => $veiculoB->id,
            'tipo' => 'seguro',
            'numero' => 'B-1',
            'validade' => $this->emDias(5),
        ]));

        $this->app->instance(
            NotificationService::class,
            new NotificationServiceQueFalhaNoVeiculo((int) $veiculoComDefeito->id)
        );

        $this->artisan('frota:verificar')
            ->expectsOutputToContain('Os demais registros continuam sendo processados.')
            ->assertExitCode(Command::FAILURE);

        $avisos = $this->itensDoEvento(EventosDeNotificacao::DOCUMENTO_DE_VEICULO_A_VENCER);
        $empresas = $avisos->map(fn (NotificationQueue $aviso): int => (int) $aviso->company_id)->unique()->all();

        $this->assertNotContains(
            (int) $this->empresa->id,
            $empresas,
            'o veículo com defeito não podia ter gerado aviso'
        );

        $this->assertContains(
            (int) $segunda->id,
            $empresas,
            'a segunda empresa precisava continuar sendo processada'
        );
    }

    // -----------------------------------------------------------------
    // A rotina nasce parada
    // -----------------------------------------------------------------

    /**
     * O módulo `frota` nasce desligado e esta rotina nasce sem agendamento, de
     * propósito (ver o cabeçalho de `VerificarFrota`). Ligar a verificação
     * antes de os veículos estarem cadastrados geraria uma leva de avisos sobre
     * dado incompleto, que é a forma mais rápida de a empresa aprender a
     * ignorar o alerta.
     *
     * Este teste existe para que ligar o agendamento seja uma decisão
     * consciente: quem acrescentar `frota:verificar` a
     * `RotinasAgendadas::DIARIAS` vai encontrar esta afirmação e precisar
     * atualizá-la junto.
     */
    public function test_a_rotina_nasce_sem_agendamento(): void
    {
        $this->assertArrayNotHasKey('frota:verificar', RotinasAgendadas::DIARIAS);
        $this->assertArrayNotHasKey('frota:verificar', RotinasAgendadas::POR_INTERVALO);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function naEmpresa(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->id, $callback);
    }

    private function criarVeiculo(int $kmAtual = 1000, string $situacao = 'ativo'): Vehicle
    {
        return $this->naEmpresa(fn (): Vehicle => Vehicle::create([
            'placa' => 'AAA'.random_int(1000, 9999),
            'modelo' => 'Saveiro',
            'marca' => 'Volkswagen',
            'ano' => 2022,
            'tipo' => 'utilitario',
            'km_atual' => $kmAtual,
            'situacao' => $situacao,
        ]));
    }

    private function agendarManutencao(
        Vehicle $veiculo,
        ?string $proximaEmData = null,
        ?int $proximaEmKm = null,
    ): VehicleMaintenance {
        return $this->naEmpresa(fn (): VehicleMaintenance => VehicleMaintenance::create([
            'vehicle_id' => $veiculo->id,
            'tipo' => 'preventiva',
            'descricao' => 'Troca de óleo',
            'proxima_em_data' => $proximaEmData,
            'proxima_em_km' => $proximaEmKm,
            'situacao' => 'agendada',
        ]));
    }

    private function criarDocumento(Vehicle $veiculo, string $validade): VehicleDocument
    {
        return $this->naEmpresa(fn (): VehicleDocument => VehicleDocument::create([
            'vehicle_id' => $veiculo->id,
            'tipo' => 'licenciamento',
            'numero' => 'LIC-'.uniqid(),
            'validade' => $validade,
        ]));
    }

    private function abastecer(Vehicle $veiculo, string $data, int $km): Refueling
    {
        return $this->naEmpresa(fn (): Refueling => Refueling::create([
            'vehicle_id' => $veiculo->id,
            'data' => $data,
            'km' => $km,
            'litros' => 40,
            'valor_total' => 200.00,
            'valor_litro' => 5.0,
            'tipo_combustivel' => 'gasolina',
            'tanque_cheio' => true,
            'user_id' => $this->usuario->id,
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
     * aplicação roda. Mesmo motivo do `viajarPara` de `StockAlertServiceTest`.
     */
    private function viajarPara(string $instanteEmUtc): void
    {
        $this->travelTo(CarbonImmutable::parse($instanteEmUtc, 'UTC'));
    }

    /**
     * @param  Collection<int, NotificationQueue>  $avisos
     */
    private function assertMarco(Collection $avisos, string $marco): void
    {
        $this->assertTrue(
            $avisos->pluck('chave_idempotencia')
                ->contains(fn (string $chave): bool => str_ends_with($chave, ":{$marco}")),
            "faltou o aviso do marco {$marco}"
        );
    }

    /**
     * Itens da fila deste evento, de todas as empresas: sem tenant explícito, o
     * escopo global de `NotificationQueue` não filtra nada, o que permite
     * conferir duas empresas de uma vez.
     *
     * @return Collection<int, NotificationQueue>
     */
    private function itensDoEvento(string $evento): Collection
    {
        return NotificationQueue::query()->where('evento', $evento)->orderBy('id')->get();
    }
}

/**
 * Service que estoura para um veículo escolhido e se comporta normalmente no
 * resto, no mesmo padrão de `NotificationServiceQueFalhaNoProduto` em
 * `StockAlertServiceTest`.
 *
 * A falha é acionada pelo veículo dono do registro, e não pelo próprio
 * registro: é assim que um dado inconsistente de um veículo derrubaria os
 * avisos dele sem tocar nos dos outros.
 */
class NotificationServiceQueFalhaNoVeiculo extends NotificationService
{
    public function __construct(private readonly int $veiculoQueFalha) {}

    /**
     * @param  array<string, mixed>  $opcoes
     * @return array{resultado: string, criado: bool, item: NotificationQueue|null, canal: string|null, chave_idempotencia: string|null, mensagem: string}
     */
    public function enfileirar(string $evento, Model $referencia, array $opcoes = []): array
    {
        $vehicleId = $referencia instanceof Vehicle
            ? (int) $referencia->getKey()
            : (int) ($referencia->vehicle_id ?? 0);

        if ($vehicleId === $this->veiculoQueFalha) {
            throw new RuntimeException("Dado inconsistente no veículo #{$this->veiculoQueFalha}.");
        }

        return parent::enfileirar($evento, $referencia, $opcoes);
    }
}
