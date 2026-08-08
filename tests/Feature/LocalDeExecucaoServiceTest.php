<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Company;
use App\Models\Technician;
use App\Models\TechnicianTrackingSetting;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AppSyncService;
use App\Services\Routing\LocalDeExecucaoService;
use App\Support\Geo\ResultadoGeo;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Factories\WorkOrderFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 22.4 do Plano 22: registro do local de início/fim da execução,
 * divergência contra o endereço do cliente e consentimento de rastreamento
 * contínuo.
 *
 * Cobre os oito critérios de aceitação da task: (a) início/fim gravam
 * coordenada quando autorizada, (b) sem autorização só o instante é gravado
 * sem erro, (c) divergência acima de 300 m é sinalizada, (d) divergência de
 * 200 m não é sinalizada, (e) endereço sem coordenada ou com precisão
 * `cidade` não gera sinalização, (f) rastreamento contínuo nasce desligado,
 * (g) ligar exige consentimento registrado, (h) divergência não impede
 * concluir a OS.
 *
 * As coordenadas de "fim" usadas nos testes de divergência variam só a
 * latitude em relação ao endereço, mantendo a longitude igual: com
 * `deltaLongitude = 0`, a fórmula de Haversine se reduz a
 * `raio * deltaLatitudeEmRadianos`, o que permite calcular o delta de
 * latitude necessário para uma distância alvo em metros sem depender de
 * aproximação, e conferir os dois lados do limiar de 300 m com precisão de
 * centímetros (bem abaixo do metro que `calcularDivergencia()` arredonda).
 */
class LocalDeExecucaoServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $empresa;

    private LocalDeExecucaoService $service;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->empresa = Company::query()->firstOrFail();
        $this->service = app(LocalDeExecucaoService::class);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // (a) Início e fim gravam coordenada quando autorizada
    // -----------------------------------------------------------------

    public function test_registrar_inicio_grava_coordenada_e_instante_quando_autorizado(): void
    {
        $ordem = $this->criarOrdem();
        $instante = Carbon::parse('2026-08-10 08:00:00');

        $ordem = $this->service->registrarInicio($ordem, -23.5505000, -46.6333000, $instante);

        $this->assertEqualsWithDelta(-23.5505000, (float) $ordem->inicio_latitude, 0.0000001);
        $this->assertEqualsWithDelta(-46.6333000, (float) $ordem->inicio_longitude, 0.0000001);
        $this->assertNotNull($ordem->inicio_registrado_em);
        $this->assertTrue($instante->eq($ordem->inicio_registrado_em));
    }

    public function test_registrar_fim_grava_coordenada_e_instante_quando_autorizado(): void
    {
        $ordem = $this->criarOrdem();
        $instante = Carbon::parse('2026-08-10 17:30:00');

        $ordem = $this->service->registrarFim($ordem, -23.5510000, -46.6340000, $instante);

        $this->assertEqualsWithDelta(-23.5510000, (float) $ordem->fim_latitude, 0.0000001);
        $this->assertEqualsWithDelta(-46.6340000, (float) $ordem->fim_longitude, 0.0000001);
        $this->assertNotNull($ordem->fim_registrado_em);
        $this->assertTrue($instante->eq($ordem->fim_registrado_em));
    }

    // -----------------------------------------------------------------
    // (b) Sem autorização, só o instante é gravado, sem erro
    // -----------------------------------------------------------------

    public function test_registrar_inicio_sem_coordenada_grava_so_o_instante_sem_erro(): void
    {
        $ordem = $this->criarOrdem();
        $instante = Carbon::parse('2026-08-10 08:00:00');

        $ordem = $this->service->registrarInicio($ordem, null, null, $instante);

        $this->assertNull($ordem->inicio_latitude);
        $this->assertNull($ordem->inicio_longitude);
        $this->assertNotNull($ordem->inicio_registrado_em);
        $this->assertTrue($instante->eq($ordem->inicio_registrado_em));
    }

    public function test_registrar_fim_sem_coordenada_grava_so_o_instante_sem_erro(): void
    {
        $ordem = $this->criarOrdem();
        $instante = Carbon::parse('2026-08-10 17:30:00');

        $ordem = $this->service->registrarFim($ordem, null, null, $instante);

        $this->assertNull($ordem->fim_latitude);
        $this->assertNull($ordem->fim_longitude);
        $this->assertNotNull($ordem->fim_registrado_em);
        $this->assertTrue($instante->eq($ordem->fim_registrado_em));
        $this->assertNull($ordem->divergencia_local_metros, 'sem coordenada de fim não há o que comparar');
    }

    /**
     * Payload truncado (só uma das duas chaves) conta como "sem
     * autorização", não como erro de validação: metade de um par de
     * coordenada não é uma coordenada.
     */
    public function test_registrar_inicio_com_apenas_uma_coordenada_nao_grava_nenhuma(): void
    {
        $ordem = $this->criarOrdem();

        $ordem = $this->service->registrarInicio($ordem, -23.5505000, null);

        $this->assertNull($ordem->inicio_latitude);
        $this->assertNull($ordem->inicio_longitude);
        $this->assertNotNull($ordem->inicio_registrado_em);
    }

    // -----------------------------------------------------------------
    // (c) Divergência acima de 300 m é sinalizada / (d) 200 m não é
    // -----------------------------------------------------------------

    public function test_divergencia_de_quinhentos_metros_e_sinalizada(): void
    {
        $endereco = $this->criarEnderecoComCoordenada(-23.5505000, -46.6333000, ResultadoGeo::PRECISAO_EXATA);
        $ordem = $this->criarOrdem($endereco);

        // 500 m ao norte do endereço (delta de latitude derivado da fórmula
        // de Haversine com deltaLongitude = 0, ver docblock da classe).
        $ordem = $this->service->registrarFim($ordem, -23.5460034, -46.6333000);

        $this->assertEqualsWithDelta(500, $ordem->divergencia_local_metros, 2, 'o valor bruto é sempre gravado, sinalizado ou não');
        $this->assertTrue($this->service->divergenciaSinalizada($ordem), 'divergência acima de 300 m precisa ser sinalizada');
    }

    /**
     * O valor bruto continua gravado abaixo do limiar - é a distância real,
     * útil para quem revisa a OS, mesmo sem acender o alerta padrão de
     * 300 m. "Não sinalizada" é sobre o alerta, não sobre apagar o dado.
     */
    public function test_divergencia_de_duzentos_metros_e_gravada_mas_nao_e_sinalizada(): void
    {
        $endereco = $this->criarEnderecoComCoordenada(-23.5505000, -46.6333000, ResultadoGeo::PRECISAO_EXATA);
        $ordem = $this->criarOrdem($endereco);

        // 200 m ao norte do endereço.
        $ordem = $this->service->registrarFim($ordem, -23.5487014, -46.6333000);

        $this->assertEqualsWithDelta(200, $ordem->divergencia_local_metros, 2, 'o valor bruto continua gravado abaixo do limiar');
        $this->assertFalse($this->service->divergenciaSinalizada($ordem), 'divergência de 200 m, abaixo do limiar de 300 m, não deve acender o alerta');
    }

    /**
     * Os dois lados exatos do limiar de 300 m: os dois valores são gravados,
     * só a sinalização (o alerta) muda de lado.
     */
    public function test_divergencia_nos_dois_lados_exatos_do_limiar_de_trezentos_metros(): void
    {
        $endereco = $this->criarEnderecoComCoordenada(-23.5505000, -46.6333000, ResultadoGeo::PRECISAO_EXATA);

        $ordemAcima = $this->criarOrdem($endereco);
        $ordemAcima = $this->service->registrarFim($ordemAcima, -23.5477930, -46.6333000); // ~301 m
        $this->assertNotNull($ordemAcima->divergencia_local_metros);
        $this->assertTrue($this->service->divergenciaSinalizada($ordemAcima), '301 m está acima do limiar e precisa sinalizar');

        $ordemAbaixo = $this->criarOrdem($endereco);
        $ordemAbaixo = $this->service->registrarFim($ordemAbaixo, -23.5478110, -46.6333000); // ~299 m
        $this->assertNotNull($ordemAbaixo->divergencia_local_metros, 'o valor bruto continua gravado mesmo abaixo do limiar');
        $this->assertFalse($this->service->divergenciaSinalizada($ordemAbaixo), '299 m está abaixo do limiar e não deve sinalizar');
    }

    // -----------------------------------------------------------------
    // (e) Endereço sem coordenada ou com precisão "cidade" não sinaliza
    // -----------------------------------------------------------------

    public function test_endereco_sem_coordenada_nao_gera_sinalizacao_mesmo_longe(): void
    {
        // AddressFactory não preenche latitude/longitude: nasce sem
        // coordenada, exatamente o cenário deste teste.
        $endereco = $this->comTenant(fn () => AddressFactory::new()->create([
            'client_id' => $this->comTenant(fn () => ClientFactory::new()->create())->id,
        ]));
        $ordem = $this->criarOrdem($endereco);

        // Bem longe (~11 km), para deixar claro que a distância não é o que
        // está impedindo a sinalização.
        $ordem = $this->service->registrarFim($ordem, -23.6500000, -46.6333000);

        $this->assertNull($ordem->divergencia_local_metros, 'endereço sem coordenada não pode gerar divergência calculada');
    }

    public function test_endereco_com_precisao_cidade_nao_gera_sinalizacao_mesmo_longe(): void
    {
        $endereco = $this->criarEnderecoComCoordenada(-23.5505000, -46.6333000, ResultadoGeo::PRECISAO_CIDADE);
        $ordem = $this->criarOrdem($endereco);

        $ordem = $this->service->registrarFim($ordem, -23.6500000, -46.6333000);

        $this->assertNull($ordem->divergencia_local_metros, 'precisão "cidade" é dado ruim demais para comparar, mesmo longe');
    }

    // -----------------------------------------------------------------
    // (f) Rastreamento contínuo nasce desligado
    // -----------------------------------------------------------------

    public function test_rastreamento_continuo_nasce_desligado_para_tecnico_sem_configuracao(): void
    {
        [, $tecnico] = $this->criarTecnico('Rastreio1');

        $this->assertFalse($this->service->rastreamentoContinuoLigado($tecnico));
    }

    public function test_coluna_rastreamento_continuo_tem_padrao_falso(): void
    {
        [, $tecnico] = $this->criarTecnico('Rastreio2');

        $configuracao = $this->comTenant(fn () => TechnicianTrackingSetting::create([
            'technician_id' => $tecnico->id,
        ]));

        // O atributo em memória não reflete o DEFAULT aplicado pelo banco:
        // a coluna nem entrou no INSERT, porque não foi informada. refresh()
        // relê a linha gravada, incluindo o DEFAULT(false) do MySQL.
        $configuracao->refresh();

        $this->assertFalse($configuracao->rastreamento_continuo);
    }

    // -----------------------------------------------------------------
    // (g) Ligar exige consentimento registrado
    // -----------------------------------------------------------------

    public function test_ligar_rastreamento_sem_consentimento_registrado_falha(): void
    {
        [, $tecnico] = $this->criarTecnico('Rastreio3');

        $this->expectException(RuntimeException::class);

        // Tentativa de ligar por fora do único caminho autorizado
        // (`LocalDeExecucaoService::ligarRastreamentoContinuo()`, que exige um
        // `User` autorizador e por isso nem compila sem um): grava a chave
        // ligada direto no model, sem consentido_em/consentido_por. A
        // garantia mora em `TechnicianTrackingSetting::booted()`, não só na
        // disciplina de quem chama.
        $this->comTenant(fn () => TechnicianTrackingSetting::create([
            'technician_id' => $tecnico->id,
            'rastreamento_continuo' => true,
        ]));
    }

    public function test_ligar_rastreamento_com_consentimento_liga_e_grava_quem_autorizou(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('Rastreio4');
        $instante = Carbon::parse('2026-08-10 09:00:00');
        Carbon::setTestNow($instante);

        // `ligarRastreamentoContinuo()` grava uma linha nova (INSERT): precisa
        // do tenant corrente resolvido para `company_id`, mesmo critério de
        // toda escrita de domínio nesta suíte fora de uma requisição HTTP
        // real autenticada.
        $configuracao = $this->comTenant(fn () => $this->service->ligarRastreamentoContinuo($tecnico, $usuario));

        $this->assertTrue($configuracao->rastreamento_continuo);
        $this->assertNotNull($configuracao->consentido_em);
        $this->assertTrue($instante->eq($configuracao->consentido_em));
        $this->assertSame($usuario->id, $configuracao->consentido_por);
        $this->assertTrue($this->service->rastreamentoContinuoLigado($tecnico));
    }

    public function test_desligar_rastreamento_desliga_sem_apagar_o_historico_de_consentimento(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('Rastreio5');
        $this->comTenant(fn () => $this->service->ligarRastreamentoContinuo($tecnico, $usuario));

        $configuracao = $this->comTenant(fn () => $this->service->desligarRastreamentoContinuo($tecnico));

        $this->assertFalse($configuracao->rastreamento_continuo);
        $this->assertNotNull($configuracao->consentido_em, 'desligar não apaga quando o consentimento foi registrado');
        $this->assertNotNull($configuracao->consentido_por);
        $this->assertFalse($this->service->rastreamentoContinuoLigado($tecnico));
    }

    // -----------------------------------------------------------------
    // (h) Divergência não impede concluir a OS (fluxo completo via
    // AplicadorDeExecucao::concluir(), pela sincronização do Plano 12/13)
    // -----------------------------------------------------------------

    public function test_concluir_via_sincronizacao_termina_a_os_mesmo_com_divergencia_grande(): void
    {
        $endereco = $this->criarEnderecoComCoordenada(-23.5505000, -46.6333000, ResultadoGeo::PRECISAO_EXATA);

        [$usuario, $tecnico] = $this->criarTecnico('Conclusao1');
        $ordem = $this->comTenant(fn () => WorkOrderFactory::new()->create([
            'technician_id' => $tecnico->id,
            'address_id' => $endereco->id,
            'status' => 'in_progress',
        ]));

        $service = app(AppSyncService::class);

        // ~11 km do endereço: divergência bem acima do limiar de 300 m.
        $operacao = [
            'uuid' => (string) Str::uuid(),
            'tipo' => 'execucao',
            'work_order_id' => $ordem->id,
            'registrada_em' => now()->toJSON(),
            'updated_at_conhecido' => null,
            'payload' => [
                'acao' => 'concluir',
                'latitude' => -23.6500000,
                'longitude' => -46.6333000,
            ],
        ];

        $resultado = $service->aplicar($operacao, $usuario->fresh());

        $this->assertTrue($resultado->foiAplicada(), (string) $resultado->mensagem);

        $ordem->refresh();

        $this->assertSame('completed', $ordem->status, 'a divergência de local não pode impedir a conclusão da OS');
        $this->assertNotNull($ordem->fim_latitude);
        $this->assertNotNull($ordem->fim_longitude);
        $this->assertNotNull(
            $ordem->divergencia_local_metros,
            'a divergência (bem acima do limiar) precisa ter sido calculada e gravada, sem impedir a conclusão'
        );
        $this->assertGreaterThan(300, $ordem->divergencia_local_metros);
    }

    /**
     * Mesmo fluxo de sincronização, cobrindo o contrato do payload também
     * para `iniciar`: `latitude`/`longitude` chegam pelas mesmas duas chaves,
     * e `AplicadorDeExecucao` grava em `inicio_latitude`/`inicio_longitude`
     * sem quebrar `start_time`/status já existentes.
     */
    public function test_iniciar_via_sincronizacao_grava_coordenada_do_payload(): void
    {
        [$usuario, $tecnico] = $this->criarTecnico('Conclusao2');
        $ordem = $this->comTenant(fn () => WorkOrderFactory::new()->create([
            'technician_id' => $tecnico->id,
            'status' => 'scheduled',
            'start_time' => null,
        ]));

        $service = app(AppSyncService::class);

        $operacao = [
            'uuid' => (string) Str::uuid(),
            'tipo' => 'execucao',
            'work_order_id' => $ordem->id,
            'registrada_em' => now()->toJSON(),
            'updated_at_conhecido' => null,
            'payload' => [
                'acao' => 'iniciar',
                'latitude' => -23.5505000,
                'longitude' => -46.6333000,
            ],
        ];

        $resultado = $service->aplicar($operacao, $usuario->fresh());

        $this->assertTrue($resultado->foiAplicada(), (string) $resultado->mensagem);

        $ordem->refresh();

        $this->assertSame('in_progress', $ordem->status);
        $this->assertNotNull($ordem->start_time);
        $this->assertEqualsWithDelta(-23.5505000, (float) $ordem->inicio_latitude, 0.0000001);
        $this->assertEqualsWithDelta(-46.6333000, (float) $ordem->inicio_longitude, 0.0000001);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function comTenant(\Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->id, $callback);
    }

    private function criarEnderecoComCoordenada(float $latitude, float $longitude, string $precisao): Address
    {
        $endereco = $this->comTenant(fn () => AddressFactory::new()->create([
            'client_id' => $this->comTenant(fn () => ClientFactory::new()->create())->id,
        ]));

        // latitude/longitude/precisao_geocodificacao ficam fora de $fillable
        // de propósito (mesma regra que este Service documenta para
        // `WorkOrder`); forceFill() é o caminho de teste para simular um
        // endereço já geocodificado, mesmo padrão usado em
        // GeocodificacaoServiceTest.
        $this->comTenant(function () use ($endereco, $latitude, $longitude, $precisao) {
            $endereco->forceFill([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'precisao_geocodificacao' => $precisao,
                'geocodificado_em' => now(),
                'origem_coordenada' => Address::ORIGEM_COORDENADA_AUTOMATICA,
            ])->save();
        });

        return $endereco->refresh();
    }

    private function criarOrdem(?Address $endereco = null): WorkOrder
    {
        return $this->comTenant(function () use ($endereco) {
            $atributos = [];

            if ($endereco !== null) {
                $atributos['client_id'] = $endereco->client_id;
                $atributos['address_id'] = $endereco->id;
            }

            return WorkOrderFactory::new()->create($atributos);
        });
    }

    /**
     * @return array{0: User, 1: Technician}
     */
    private function criarTecnico(string $marca): array
    {
        return $this->comTenant(function () use ($marca) {
            $usuario = User::factory()->create([
                'name' => 'Técnico '.$marca,
                'is_active' => true,
            ]);
            $usuario->assignRole('tecnico');

            $tecnico = Technician::create([
                'name' => 'Cadastro Técnico '.$marca,
                'email' => 'tecnico-'.strtolower($marca).'-'.uniqid().'@exemplo.test',
                'phone' => '11999990000',
                'is_active' => true,
                'user_id' => $usuario->id,
            ]);

            return [$usuario, $tecnico];
        });
    }
}
