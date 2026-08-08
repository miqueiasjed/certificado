<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Company;
use App\Models\Route;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Services\Routing\EstimadorDeDeslocamento;
use App\Services\Routing\OtimizadorDeRota;
use App\Services\Routing\RouteService;
use App\Support\BusinessDate;
use App\Support\Geo\Coordenada;
use App\Support\Geo\ResultadoGeo;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Database\Factories\AddressFactory;
use Database\Factories\ClientFactory;
use Database\Factories\WorkOrderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 22.3 do Plano 22: roteiro do dia por proximidade e estimativa de
 * deslocamento (`RouteService`, `OtimizadorDeRota`, `EstimadorDeDeslocamento`).
 *
 * Todas as coordenadas dos cenários usam a sede em `(0, 0)` e deslocamentos
 * pequenos em graus (não em metros): não representam nenhum lugar real, só
 * servem de referência geométrica estável e fácil de raciocinar (perto do
 * equador, 1 grau de longitude e 1 grau de latitude valem praticamente o
 * mesmo tanto de distância, o que simplifica montar cenários de "ao longo de
 * uma linha" e "num círculo" sem a distorção de `cos(latitude)`).
 *
 * `RouteStop` não tem uma coluna própria para "endereço sem coordenada boa"
 * (fora do escopo de schema da Task 22.3, que é só os três Services). A
 * marcação exigida pelo critério de aceitação é coberta em duas frentes:
 * `OtimizadorDeRota::ordenar()` devolve a chave `sem_coordenada_boa` no
 * array de cada parada (testada diretamente, sem banco), e, de ponta a
 * ponta pelo `RouteService`, a parada sem coordenada boa é verificável pela
 * posição (sempre a última) e por `distancia_anterior_km`/
 * `duracao_anterior_min` nulos (não há como calcular deslocamento sem
 * coordenada).
 */
class RouteServiceTest extends TestCase
{
    use RefreshDatabase;

    private const DATA_DO_ROTEIRO = '2026-08-10';

    private Company $empresa;

    private RouteService $service;

    private EstimadorDeDeslocamento $estimador;

    private Coordenada $sede;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();

        $this->empresa = Company::query()->firstOrFail();
        $this->service = app(RouteService::class);
        $this->estimador = new EstimadorDeDeslocamento;
        $this->sede = new Coordenada(0.0, 0.0);
    }

    protected function tearDown(): void
    {
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // (a) O roteiro ordena as paradas por proximidade a partir da sede
    // -----------------------------------------------------------------

    public function test_roteiro_ordena_paradas_por_proximidade_a_partir_da_sede(): void
    {
        $tecnico = $this->criarTecnico('ProxA');

        // Criadas fora de ordem de proximidade, de propósito: se o roteiro
        // saísse na ordem de criação (ou na ordem do id), este teste
        // falharia sem exercitar otimização nenhuma.
        $longe = $this->criarOrdem($tecnico, latOffset: 0.03, lngOffset: 0.0);
        $perto = $this->criarOrdem($tecnico, latOffset: 0.01, lngOffset: 0.0);
        $meio = $this->criarOrdem($tecnico, latOffset: 0.02, lngOffset: 0.0);

        $rota = $this->montar($tecnico);

        $this->assertSame(
            [$perto->id, $meio->id, $longe->id],
            $rota->stops->pluck('work_order_id')->all(),
            'as paradas precisam sair ordenadas da mais próxima para a mais distante da sede'
        );
    }

    // -----------------------------------------------------------------
    // (b) OS com horário fixo permanece na posição do horário
    // -----------------------------------------------------------------

    /**
     * Duas âncoras e uma parada livre. Geometricamente, partindo da sede, a
     * âncora da tarde (`$ancoraTarde`) está mais perto e seria visitada
     * primeiro por um otimizador que ignorasse horário - é exatamente o que
     * este teste teria acusado se `fixarAncorasPorHorario()` não existisse.
     * Com a regra da âncora, a ordem final segue o horário combinado
     * (manhã antes de tarde), não a proximidade.
     */
    public function test_os_com_horario_combinado_fica_na_posicao_do_horario_mesmo_contra_a_geometria(): void
    {
        $tecnico = $this->criarTecnico('AncoraB');

        $ancoraTarde = $this->criarOrdem($tecnico, latOffset: 0.0, lngOffset: 0.01, horario: '14:00:00');
        $livre = $this->criarOrdem($tecnico, latOffset: 0.0, lngOffset: 0.03);
        $ancoraManha = $this->criarOrdem($tecnico, latOffset: 0.0, lngOffset: 0.05, horario: '08:00:00');

        $rota = $this->montar($tecnico);

        $this->assertSame(
            [$ancoraManha->id, $livre->id, $ancoraTarde->id],
            $rota->stops->pluck('work_order_id')->all(),
            'a âncora da manhã precisa vir antes da âncora da tarde, mesmo estando geometricamente mais longe da sede'
        );
    }

    /**
     * Mesma regra, verificada diretamente em `OtimizadorDeRota`, sem banco:
     * cenário mínimo para não depender de nenhum detalhe de persistência.
     */
    public function test_otimizador_nao_reordena_paradas_ancoradas_entre_si_por_geometria(): void
    {
        $otimizador = new OtimizadorDeRota($this->estimador);

        $ancoraTarde = $this->parada(1, latOffset: 0.0, lngOffset: 0.01, ancorada: true, horario: '14:00:00');
        $ancoraManha = $this->parada(2, latOffset: 0.0, lngOffset: 0.05, ancorada: true, horario: '08:00:00');

        $resultado = $otimizador->ordenar([$ancoraTarde, $ancoraManha], $this->sede);

        $this->assertSame([2, 1], array_column($resultado, 'work_order_id'));
    }

    // -----------------------------------------------------------------
    // (c) A distância total cai em relação à ordem original (10 paradas)
    // -----------------------------------------------------------------

    /**
     * Dez pontos num círculo ao redor da sede, criados numa ordem que
     * alterna lados opostos do círculo de propósito (a pior ordem possível
     * para percorrer um círculo: cruza de um lado a outro repetidamente).
     * O otimizador (vizinho mais próximo + 2-opt) tende a reconstruir algo
     * perto da volta em sequência angular, que é a rota curta para esse
     * formato - o teste não assume o valor exato, só que o total otimizado
     * é menor que o da ordem de criação.
     */
    public function test_distancia_total_cai_em_relacao_a_ordem_original_com_dez_paradas(): void
    {
        $tecnico = $this->criarTecnico('DistC');
        $raio = 0.05;

        // Ângulos em graus, na ordem de criação: 0, 180, 36, 216, 72, 252...
        // (alterna lado oposto do círculo a cada nova parada).
        $angulosNaOrdemDeCriacao = [];
        for ($k = 0; $k < 5; $k++) {
            $angulosNaOrdemDeCriacao[] = $k * 36;
            $angulosNaOrdemDeCriacao[] = $k * 36 + 180;
        }

        $ordens = [];

        foreach ($angulosNaOrdemDeCriacao as $anguloGraus) {
            $anguloRad = deg2rad($anguloGraus);

            $ordens[] = $this->criarOrdem(
                $tecnico,
                latOffset: $raio * sin($anguloRad),
                lngOffset: $raio * cos($anguloRad),
            );
        }

        $distanciaDaOrdemOriginal = $this->distanciaTotalDaSequencia($ordens);

        $rota = $this->montar($tecnico);

        $this->assertNotNull($rota->distancia_total_km);
        $this->assertLessThan(
            $distanciaDaOrdemOriginal,
            (float) $rota->distancia_total_km,
            'a distância otimizada precisa ser menor que a da ordem de criação (que cruza o círculo de propósito)'
        );
    }

    // -----------------------------------------------------------------
    // (d) A chegada estimada é calculada por parada
    // -----------------------------------------------------------------

    public function test_chegada_estimada_e_calculada_por_parada_a_partir_do_inicio_do_tecnico(): void
    {
        $tecnico = $this->criarTecnico('ChegadaD');

        $os1 = $this->criarOrdem($tecnico, latOffset: 0.0, lngOffset: 0.01);
        $os2 = $this->criarOrdem($tecnico, latOffset: 0.0, lngOffset: 0.02);
        $os3 = $this->criarOrdem($tecnico, latOffset: 0.0, lngOffset: 0.03);

        $rota = $this->montar($tecnico);
        $rota = $this->service->chegadaEstimada($rota, '08:00:00', 30);

        // Valores esperados recalculados com o mesmo estimador, para não
        // depender de constante mágica de distância/duração escrita à mão:
        // o que este teste verifica é a ORQUESTRAÇÃO (soma de deslocamento
        // + visita, encadeada, gravada por parada), não a fórmula de
        // Haversine (já coberta pelos testes de `EstimadorDeDeslocamento`).
        $coord1 = new Coordenada(0.0, 0.01);
        $coord2 = new Coordenada(0.0, 0.02);
        $coord3 = new Coordenada(0.0, 0.03);

        $instante = Carbon::parse(self::DATA_DO_ROTEIRO.' 08:00:00', BusinessDate::fuso());

        $instante = $instante->copy()->addMinutes($this->estimador->estimar($this->sede, $coord1)->duracaoMin);
        $chegada1Esperada = $instante->format('H:i:s');
        $instante = $instante->addMinutes(30);

        $instante = $instante->addMinutes($this->estimador->estimar($coord1, $coord2)->duracaoMin);
        $chegada2Esperada = $instante->format('H:i:s');
        $instante = $instante->addMinutes(30);

        $instante = $instante->addMinutes($this->estimador->estimar($coord2, $coord3)->duracaoMin);
        $chegada3Esperada = $instante->format('H:i:s');

        $paradas = $rota->stops->keyBy('work_order_id');

        $this->assertSame($chegada1Esperada, $this->horario($paradas[$os1->id]->chegada_estimada));
        $this->assertSame($chegada2Esperada, $this->horario($paradas[$os2->id]->chegada_estimada));
        $this->assertSame($chegada3Esperada, $this->horario($paradas[$os3->id]->chegada_estimada));

        // As três crescem no tempo: chegada calculada de fato por parada,
        // não um valor único repetido.
        $this->assertTrue($chegada1Esperada < $chegada2Esperada);
        $this->assertTrue($chegada2Esperada < $chegada3Esperada);
    }

    /**
     * Parada âncora: a chegada estimada é o próprio horário combinado, não
     * uma soma acumulada a partir da estimativa de deslocamento.
     */
    public function test_chegada_estimada_de_parada_ancorada_e_o_proprio_horario_combinado(): void
    {
        $tecnico = $this->criarTecnico('ChegadaAncora');

        // Bem longe da sede: se a chegada estimada fosse calculada por
        // deslocamento acumulado, ela chegaria muito depois de 10h.
        $ancora = $this->criarOrdem($tecnico, latOffset: 0.0, lngOffset: 2.0, horario: '10:00:00');

        $rota = $this->montar($tecnico);
        $rota = $this->service->chegadaEstimada($rota, '08:00:00', 30);

        $this->assertSame('10:00:00', $this->horario($rota->stops->first()->chegada_estimada));
    }

    // -----------------------------------------------------------------
    // (e) Endereço sem coordenada vai para o fim, marcado
    // -----------------------------------------------------------------

    public function test_endereco_sem_coordenada_vai_para_o_fim_do_roteiro(): void
    {
        $tecnico = $this->criarTecnico('SemCoordE');

        $comCoordenada1 = $this->criarOrdem($tecnico, latOffset: 0.0, lngOffset: 0.02);
        $semCoordenada = $this->criarOrdem($tecnico, latOffset: null, lngOffset: null);
        $comCoordenada2 = $this->criarOrdem($tecnico, latOffset: 0.0, lngOffset: 0.01);

        $rota = $this->montar($tecnico);
        $paradas = $rota->stops;

        $this->assertSame(
            $semCoordenada->id,
            $paradas->last()->work_order_id,
            'a parada sem coordenada precisa ser a última do roteiro'
        );

        $ultimaParada = $paradas->last();
        $this->assertNull(
            $ultimaParada->distancia_anterior_km,
            'sem coordenada não há como calcular a distância do trecho até ela'
        );
        $this->assertNull($ultimaParada->duracao_anterior_min);

        // As duas paradas com coordenada boa continuam ordenadas por
        // proximidade entre si, sem distorção pela parada sem coordenada.
        $this->assertSame(
            [$comCoordenada2->id, $comCoordenada1->id],
            $paradas->take(2)->pluck('work_order_id')->all()
        );
    }

    /**
     * Mesma regra, endereço com precisão "cidade" (tem coordenada, mas não
     * serve para roteiro - ver `App\Support\Geo\ResultadoGeo::PRECISAO_CIDADE`).
     */
    public function test_endereco_com_precisao_cidade_tambem_vai_para_o_fim(): void
    {
        $tecnico = $this->criarTecnico('PrecisaoCidade');

        $comCoordenada = $this->criarOrdem($tecnico, latOffset: 0.0, lngOffset: 0.01);
        $precisaoCidade = $this->criarOrdem(
            $tecnico,
            latOffset: 0.0,
            lngOffset: 0.02,
            precisao: ResultadoGeo::PRECISAO_CIDADE,
        );

        $rota = $this->montar($tecnico);

        $this->assertSame($precisaoCidade->id, $rota->stops->last()->work_order_id);
        $this->assertSame($comCoordenada->id, $rota->stops->first()->work_order_id);
    }

    /**
     * Verificação direta em `OtimizadorDeRota`, sem banco: confere a chave
     * `sem_coordenada_boa` que a especificação da Task 22.3 pede como
     * marcação explícita.
     */
    public function test_otimizador_marca_explicitamente_paradas_sem_coordenada_boa(): void
    {
        $otimizador = new OtimizadorDeRota($this->estimador);

        $boa = $this->parada(1, latOffset: 0.0, lngOffset: 0.01);
        $semCoordenada = $this->parada(2, latOffset: null, lngOffset: null);
        $precisaoCidade = $this->parada(3, latOffset: 0.0, lngOffset: 0.02, precisao: ResultadoGeo::PRECISAO_CIDADE);

        $resultado = $otimizador->ordenar([$semCoordenada, $boa, $precisaoCidade], $this->sede);

        $porId = collect($resultado)->keyBy('work_order_id');

        $this->assertFalse($porId[1]['sem_coordenada_boa']);
        $this->assertTrue($porId[2]['sem_coordenada_boa']);
        $this->assertTrue($porId[3]['sem_coordenada_boa']);

        // As marcadas vão para o fim, a boa vem primeiro.
        $this->assertSame(1, $resultado[0]['work_order_id']);
    }

    // -----------------------------------------------------------------
    // (f) A estimativa vem marcada como estimativa
    // -----------------------------------------------------------------

    public function test_estimativa_de_deslocamento_vem_sempre_marcada_como_estimativa(): void
    {
        $estimativa = $this->estimador->estimar(new Coordenada(0.0, 0.0), new Coordenada(0.0, 0.05));

        $this->assertTrue($estimativa->estimativa);
        $this->assertGreaterThan(0.0, $estimativa->distanciaKm);
        $this->assertGreaterThan(0, $estimativa->duracaoMin);
    }

    public function test_estimativa_entre_coordenadas_iguais_e_zero_mas_continua_marcada(): void
    {
        $ponto = new Coordenada(-23.5505, -46.6333);

        $estimativa = $this->estimador->estimar($ponto, $ponto);

        $this->assertSame(0.0, $estimativa->distanciaKm);
        $this->assertSame(0, $estimativa->duracaoMin);
        $this->assertTrue($estimativa->estimativa);
    }

    /**
     * O fator de correção viário (1,3) precisa estar de fato aplicado: a
     * distância da estimativa não pode ser igual à linha reta.
     */
    public function test_estimativa_aplica_fator_de_correcao_viaria_sobre_a_linha_reta(): void
    {
        $de = new Coordenada(0.0, 0.0);
        $para = new Coordenada(0.0, 0.05);

        $linhaReta = $this->estimador->distanciaEmLinhaReta($de, $para);
        $estimativa = $this->estimador->estimar($de, $para);

        $this->assertEqualsWithDelta($linhaReta * 1.3, $estimativa->distanciaKm, 0.01);
    }

    // -----------------------------------------------------------------
    // (g) Roteiro de 15 paradas calculado em menos de 500 ms
    // -----------------------------------------------------------------

    public function test_roteiro_de_quinze_paradas_e_calculado_em_menos_de_quinhentos_ms(): void
    {
        $tecnico = $this->criarTecnico('Perf15');

        for ($i = 0; $i < 15; $i++) {
            $anguloRad = deg2rad($i * 24);
            $raio = 0.02 + ($i % 4) * 0.01;

            // Duas âncoras no meio do lote, e uma parada sem coordenada, para
            // o cenário de desempenho não ser artificialmente mais simples
            // que um dia real.
            $horario = in_array($i, [3, 9], true) ? sprintf('%02d:00:00', 8 + $i) : null;
            $semCoordenada = $i === 12;

            $this->criarOrdem(
                $tecnico,
                latOffset: $semCoordenada ? null : $raio * sin($anguloRad),
                lngOffset: $semCoordenada ? null : $raio * cos($anguloRad),
                horario: $horario,
            );
        }

        $inicio = microtime(true);
        $rota = $this->montar($tecnico);
        $decorridoMs = (microtime(true) - $inicio) * 1000;

        $this->assertCount(15, $rota->stops);
        // Margem confortável abaixo do limite de 500 ms da Task 22.3, para
        // não flakar por variação de máquina/CI: qualquer valor abaixo de
        // 500 já comprova o critério, e o valor medido aparece na mensagem
        // de falha caso um dia isso regrida.
        $this->assertLessThan(500, $decorridoMs, "roteiro de 15 paradas levou {$decorridoMs}ms, acima do limite de 500ms");
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function criarTecnico(string $sufixo): Technician
    {
        return $this->comTenant(fn () => Technician::create([
            'name' => "Técnico {$sufixo}",
            'email' => "tecnico-{$sufixo}-".uniqid().'@exemplo.test',
            'phone' => '11999990000',
            'is_active' => true,
        ]));
    }

    private function criarOrdem(
        Technician $tecnico,
        ?float $latOffset,
        ?float $lngOffset,
        ?string $horario = null,
        ?string $precisao = null,
    ): WorkOrder {
        return $this->comTenant(function () use ($tecnico, $latOffset, $lngOffset, $horario, $precisao) {
            $cliente = ClientFactory::new()->create();
            $endereco = AddressFactory::new()->create(['client_id' => $cliente->id]);

            if ($latOffset !== null && $lngOffset !== null) {
                $endereco->forceFill([
                    'latitude' => $this->sede->latitude + $latOffset,
                    'longitude' => $this->sede->longitude + $lngOffset,
                    'precisao_geocodificacao' => $precisao ?? ResultadoGeo::PRECISAO_EXATA,
                    'origem_coordenada' => Address::ORIGEM_COORDENADA_AUTOMATICA,
                    'geocodificado_em' => now(),
                ])->save();
            }

            return WorkOrderFactory::new()->create([
                'client_id' => $cliente->id,
                'address_id' => $endereco->id,
                'technician_id' => $tecnico->id,
                'scheduled_date' => self::DATA_DO_ROTEIRO,
                'status' => 'scheduled',
                // Eloquent não converte fuso ao gravar um cast `datetime`
                // (`fromDateTime()` só formata os números do Carbon como
                // estão, sem `setTimezone`): o valor precisa já chegar em
                // UTC, mesma conversão que o frontend faz em
                // `inputDateTimeParaUtc` antes de enviar o formulário.
                'start_time' => $horario !== null
                    ? Carbon::parse(self::DATA_DO_ROTEIRO.' '.$horario, BusinessDate::fuso())
                        ->setTimezone(config('app.timezone'))
                    : null,
            ]);
        });
    }

    private function montar(Technician $tecnico): Route
    {
        return $this->comTenant(fn () => $this->service->montar($tecnico, self::DATA_DO_ROTEIRO, $this->sede));
    }

    /**
     * Monta uma "parada" no formato que `OtimizadorDeRota::ordenar()` espera,
     * sem passar pelo banco - para os testes que exercitam o otimizador
     * diretamente.
     *
     * @return array{work_order_id: int, coordenada: ?Coordenada, precisao_geocodificacao: ?string, ancorada: bool, horario_ancora: ?string}
     */
    private function parada(
        int $workOrderId,
        ?float $latOffset,
        ?float $lngOffset,
        bool $ancorada = false,
        ?string $horario = null,
        ?string $precisao = null,
    ): array {
        return [
            'work_order_id' => $workOrderId,
            'coordenada' => $latOffset !== null && $lngOffset !== null
                ? new Coordenada($this->sede->latitude + $latOffset, $this->sede->longitude + $lngOffset)
                : null,
            'precisao_geocodificacao' => $precisao,
            'ancorada' => $ancorada,
            'horario_ancora' => $horario,
        ];
    }

    /**
     * Distância total (km) de visitar as OS informadas na ordem dada,
     * partindo da sede - usada para comparar contra a ordem otimizada.
     *
     * @param  array<int, WorkOrder>  $ordens
     */
    private function distanciaTotalDaSequencia(array $ordens): float
    {
        $anterior = $this->sede;
        $total = 0.0;

        foreach ($ordens as $ordem) {
            $endereco = $ordem->address;
            $coordenada = Coordenada::apartirDe($endereco->latitude, $endereco->longitude);

            $total += $this->estimador->estimar($anterior, $coordenada)->distanciaKm;
            $anterior = $coordenada;
        }

        return $total;
    }

    /**
     * `chegada_estimada` é `time` no banco: normaliza para `H:i:s` de forma
     * estável, independente de o driver devolver `Carbon` ou string.
     */
    private function horario(mixed $valor): string
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('H:i:s');
        }

        // MySQL pode devolver a coluna `time` com microssegundos
        // (`14:00:00.000000`); `Carbon::parse` absorve os dois formatos.
        return Carbon::parse((string) $valor)->format('H:i:s');
    }

    private function comTenant(\Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->id, $callback);
    }
}
