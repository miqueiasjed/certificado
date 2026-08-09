<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PersonalProtectiveEquipment;
use App\Models\PpeDelivery;
use App\Models\Technician;
use App\Services\Ppe\AlertaDeEpiService;
use App\Services\Ppe\SituacaoDeEpiService;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\PersonalProtectiveEquipmentFactory;
use Database\Factories\PpeDeliveryFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 29.6 do Plano 29: a situação de EPI do técnico — o que ele deveria ter
 * na mão hoje, o que ele tem e o que falta.
 *
 * Três garantias, e cada uma existe por um motivo concreto.
 *
 * 1. `sem_assinatura` nunca colapsa em `em_dia`
 * --------------------------------------------
 * É a decisão do plano que mais muda o resultado prático da classe, e a única
 * pendência que a NR-6 (item 6.5.1) realmente cobra: a entrega existe, o técnico
 * está com o equipamento, e falta exatamente a prova de que a empresa forneceu.
 * Agrupar as duas pintaria de verde o único caso em que a empresa perde a
 * reclamatória trabalhista. Um `em_dia` a mais aqui não é um detalhe de rótulo.
 *
 * 2. Uma só regra de troca vencida
 * --------------------------------
 * `SituacaoDeEpiService` não compara data nenhuma: o vencimento vem de
 * `AlertaDeEpiService::trocaVencida()`, o mesmo predicado do alerta semanal da
 * Task 28.3. `test_a_situacao_e_o_alerta_nunca_discordam_sobre_a_mesma_entrega`
 * confronta as duas leituras sobre os mesmos registros e falha no dia em que
 * alguém escrever a segunda comparação — que é como o alerta passaria a cobrar
 * a troca de um respirador que a ficha do técnico mostra em dia.
 *
 * 3. O relógio é fixado, sempre
 * -----------------------------
 * Metade destes testes roda às **21:30 de Brasília**, que já é o dia seguinte em
 * UTC. É o instante em que trocar `BusinessDate` por `now()` deixa de ser
 * equivalente, e um item com a troca marcada para hoje passaria a aparecer como
 * vencido para o técnico que ainda está em campo. Nenhum teste depende do fuso
 * do sistema operacional nem do relógio da máquina.
 */
class SituacaoDeEpiServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Meio-dia de Brasília: o dia em UTC e o dia no fuso do negócio coincidem, e
     * o cenário não fica dependendo da virada. Quem exercita a virada diz isso
     * explicitamente, com `fixarRelogioEm(..., '21:30')`.
     */
    private const HOJE = '2026-08-08';

    private Company $empresa;

    private Technician $tecnico;

    private SituacaoDeEpiService $situacoes;

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        $this->fixarRelogioEm(self::HOJE, '12:00');

        $this->empresa = Company::query()->findOrFail(1);
        $this->situacoes = app(SituacaoDeEpiService::class);

        $this->tecnico = $this->criarTecnico('Joana da Silva');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // As quatro situações
    // -----------------------------------------------------------------

    public function test_entrega_assinada_e_dentro_do_prazo_fica_em_dia(): void
    {
        $epi = $this->criarEpi();
        $this->entregar($epi, $this->emDias(-10), assinada: true);

        $item = $this->itemDoEpi($epi);

        $this->assertSame(SituacaoDeEpiService::EM_DIA, $item['situacao']);
        $this->assertFalse($item['pendente']);
        $this->assertTrue($item['assinada']);
        $this->assertSame($this->emDias(170), $item['trocar_ate'], 'a troca sai de vida_util_dias, contada do dia da entrega');
        $this->assertNull($item['dias_vencido']);

        $situacao = $this->doTecnico();

        $this->assertTrue($situacao['regular']);
        $this->assertSame([], $situacao['pendencias']);
    }

    /**
     * A garantia mais importante desta classe. A entrega existe e está dentro do
     * prazo; o que falta é a confirmação de recebimento, que é a única coisa que
     * a NR-6 aceita como prova do fornecimento.
     */
    public function test_entrega_sem_assinatura_nunca_aparece_como_em_dia(): void
    {
        $epi = $this->criarEpi();
        $this->entregar($epi, $this->emDias(-10));

        $item = $this->itemDoEpi($epi);

        $this->assertNotSame(
            SituacaoDeEpiService::EM_DIA,
            $item['situacao'],
            'entrega sem assinatura não pode ser "em dia": falta justamente a prova que a NR-6 cobra'
        );
        $this->assertSame(SituacaoDeEpiService::SEM_ASSINATURA, $item['situacao']);
        $this->assertTrue($item['pendente']);
        $this->assertFalse($item['assinada']);
        $this->assertNotNull($item['entrega_id'], 'a entrega existe, e é o que separa este caso de "nunca recebeu"');

        $situacao = $this->doTecnico();

        $this->assertFalse($situacao['regular']);
        $this->assertSame(1, $situacao['resumo'][SituacaoDeEpiService::SEM_ASSINATURA]);
        $this->assertSame(0, $situacao['resumo'][SituacaoDeEpiService::EM_DIA]);
    }

    public function test_entrega_com_a_troca_ja_passada_fica_com_troca_vencida(): void
    {
        $epi = $this->criarEpi(['vida_util_dias' => 30]);
        $this->entregar($epi, $this->emDias(-40), assinada: true);

        $item = $this->itemDoEpi($epi);

        $this->assertSame(SituacaoDeEpiService::TROCA_VENCIDA, $item['situacao']);
        $this->assertSame($this->emDias(-10), $item['trocar_ate']);
        $this->assertSame(10, $item['dias_vencido']);
    }

    public function test_sem_entrega_nenhuma_o_tecnico_nunca_recebeu(): void
    {
        $epi = $this->criarEpi();

        $item = $this->itemDoEpi($epi);

        $this->assertSame(SituacaoDeEpiService::NUNCA_RECEBEU, $item['situacao']);
        $this->assertNull($item['entrega_id']);
        $this->assertNull($item['entregue_em']);
        $this->assertFalse($item['tem_troca_programada']);
    }

    /**
     * Devolvida é item que voltou ao estoque; estornada é entrega declarada
     * inexistente. Nenhuma das duas está na mão do técnico, e é o que ele tem na
     * mão que a situação descreve — deixar qualquer uma sustentar `em_dia` seria
     * afirmar que o trabalhador está protegido por um equipamento que ele
     * devolveu.
     */
    public function test_entrega_devolvida_ou_estornada_nao_sustenta_em_dia(): void
    {
        $devolvido = $this->criarEpi(['nome' => 'Luva devolvida']);
        $estornado = $this->criarEpi(['nome' => 'Luva estornada']);

        $this->comTenant(function () use ($devolvido, $estornado): void {
            PpeDeliveryFactory::new()
                ->entregueA($this->tecnico, $devolvido, $this->emDias(-10))
                ->assinada()
                ->devolvida($this->emDias(-2))
                ->create();

            PpeDeliveryFactory::new()
                ->entregueA($this->tecnico, $estornado, $this->emDias(-10))
                ->assinada()
                ->estornada()
                ->create();
        });

        $this->assertSame(
            SituacaoDeEpiService::NUNCA_RECEBEU,
            $this->itemDoEpi($devolvido)['situacao'],
            'entrega devolvida não protege ninguém hoje e não pode sustentar "em dia"'
        );
        $this->assertSame(
            SituacaoDeEpiService::NUNCA_RECEBEU,
            $this->itemDoEpi($estornado)['situacao'],
            'estorno é a declaração de que a entrega nunca existiu'
        );
        $this->assertFalse($this->doTecnico()['regular']);
    }

    /**
     * A precedência não esconde nada: o equipamento vencido em campo não protege
     * ninguém **hoje**, enquanto a assinatura que falta é prova de um fato do
     * passado. Por isso `troca_vencida` ganha — e `assinada` continua saindo como
     * campo próprio, para a tela não precisar deduzir.
     */
    public function test_entrega_vencida_e_sem_assinatura_sai_como_troca_vencida_sem_esconder_a_falta_da_assinatura(): void
    {
        $epi = $this->criarEpi(['vida_util_dias' => 30]);
        $this->entregar($epi, $this->emDias(-90));

        $item = $this->itemDoEpi($epi);

        $this->assertSame(SituacaoDeEpiService::TROCA_VENCIDA, $item['situacao']);
        $this->assertFalse($item['assinada'], 'a falta da assinatura precisa continuar visível no item');
    }

    // -----------------------------------------------------------------
    // Datas: a virada do dia e a regra única de vencimento
    // -----------------------------------------------------------------

    /**
     * 21:30 de Brasília já é 00:30 do dia seguinte em UTC. O item que deve ser
     * trocado **hoje** ainda está em dia: quem trocar `BusinessDate` por `now()`
     * passa a acusar troca vencida para o técnico que ainda está em campo, e é
     * este teste que derruba a troca.
     */
    public function test_as_21h30_de_brasilia_a_troca_marcada_para_hoje_ainda_nao_venceu(): void
    {
        $this->fixarRelogioEm(self::HOJE, '21:30');

        $epi = $this->criarEpi(['vida_util_dias' => 30]);
        $this->entregar($epi, $this->emDias(-30), assinada: true);

        $item = $this->itemDoEpi($epi);

        $this->assertSame(
            self::HOJE,
            $item['trocar_ate'],
            'o cenário só vale se a troca cair exatamente no dia de hoje em Brasília'
        );
        $this->assertSame(
            SituacaoDeEpiService::EM_DIA,
            $item['situacao'],
            'às 21:30 de Brasília ainda é hoje, e o item que deve ser trocado hoje não está vencido'
        );
        $this->assertNull($item['dias_vencido']);
    }

    public function test_a_troca_marcada_para_ontem_ja_venceu_as_21h30_de_brasilia(): void
    {
        $this->fixarRelogioEm(self::HOJE, '21:30');

        $epi = $this->criarEpi(['vida_util_dias' => 30]);
        $this->entregar($epi, $this->emDias(-31), assinada: true);

        $item = $this->itemDoEpi($epi);

        $this->assertSame($this->emDias(-1), $item['trocar_ate']);
        $this->assertSame(SituacaoDeEpiService::TROCA_VENCIDA, $item['situacao']);
        $this->assertSame(1, $item['dias_vencido']);
    }

    /**
     * O teste que existe para pegar a segunda comparação de data.
     *
     * As duas leituras do sistema que respondem "esta troca venceu?" são
     * confrontadas sobre os mesmos registros, no instante em que o dia de
     * Brasília e o dia em UTC divergem. Hoje elas concordam porque são a mesma
     * implementação — `SituacaoDeEpiService` chama
     * `AlertaDeEpiService::trocaVencida()` e não calcula nada. No dia em que
     * alguém escrever a comparação de novo, este teste falha antes de a
     * divergência chegar à produção como um alerta semanal cobrando a troca de um
     * respirador que a ficha mostra em dia.
     */
    public function test_a_situacao_e_o_alerta_nunca_discordam_sobre_a_mesma_entrega(): void
    {
        $this->fixarRelogioEm(self::HOJE, '21:30');

        // Quatro casos de propósito, e os dois do meio são os que importam: a
        // troca de hoje (o limite) e a de ontem (o primeiro dia vencido).
        $cenarios = [
            'vencida ontem' => ['vida' => 30, 'entrega' => -31],
            'vence hoje' => ['vida' => 30, 'entrega' => -30],
            'vence amanhã' => ['vida' => 30, 'entrega' => -29],
            'sem troca programada' => ['vida' => null, 'entrega' => -30],
        ];

        $entregas = [];

        foreach ($cenarios as $rotulo => $cenario) {
            $epi = $this->criarEpi(['nome' => 'EPI '.$rotulo, 'vida_util_dias' => $cenario['vida']]);
            $entregas[$rotulo] = $this->entregar($epi, $this->emDias($cenario['entrega']), assinada: true);
        }

        // Lado 1: a situação de EPI do técnico (Task 29.2).
        $vencidasPelaSituacao = collect($this->doTecnico()['itens'])
            ->filter(fn (array $item): bool => $item['situacao'] === SituacaoDeEpiService::TROCA_VENCIDA)
            ->pluck('entrega_id')
            ->sort()
            ->values()
            ->all();

        // Lado 2: o alerta semanal (Task 28.3), pela consulta que a rotina usa.
        $vencidasPeloAlerta = $this->comTenant(fn (): array => app(AlertaDeEpiService::class)
            ->trocasVencidas()
            ->map(static fn (array $linha): int => (int) $linha['entrega']->getKey())
            ->sort()
            ->values()
            ->all());

        $this->assertSame(
            [(int) $entregas['vencida ontem']->getKey()],
            $vencidasPelaSituacao,
            'só a entrega cujo trocar_ate é anterior ao dia de Brasília está vencida'
        );
        $this->assertSame(
            $vencidasPelaSituacao,
            $vencidasPeloAlerta,
            'a situação do técnico e o alerta semanal precisam apontar exatamente as mesmas entregas vencidas: '
            .'uma segunda regra de vencimento no sistema divergiria aqui'
        );
    }

    /**
     * `trocar_ate` nulo é "sem troca programada", nunca um prazo padrão
     * inventado. Campo em branco é estado neutro — a mesma regra do CA sem
     * validade no Plano 28.
     */
    public function test_entrega_sem_troca_programada_fica_em_dia_e_sem_prazo(): void
    {
        $epi = $this->criarEpi(['vida_util_dias' => null]);
        $this->entregar($epi, $this->emDias(-900), assinada: true);

        $item = $this->itemDoEpi($epi);

        $this->assertSame(SituacaoDeEpiService::EM_DIA, $item['situacao']);
        $this->assertFalse($item['tem_troca_programada']);
        $this->assertNull($item['trocar_ate']);
        $this->assertNull($item['dias_vencido']);
    }

    // -----------------------------------------------------------------
    // Qual entrega responde pela situação
    // -----------------------------------------------------------------

    /**
     * O item danificado e reposto na mesma manhã: duas entregas no mesmo dia, e
     * quem responde é a mais recente pela ordem de registro. Sem o desempate por
     * `id`, a linha velha continuaria mandando na situação enquanto o técnico já
     * está com o substituto na mão.
     */
    public function test_a_entrega_mais_recente_em_vigor_e_quem_responde_pela_situacao(): void
    {
        $epi = $this->criarEpi(['vida_util_dias' => 30]);

        $antiga = $this->entregar($epi, $this->emDias(-40), assinada: true);
        $substituta = $this->entregar($epi, $this->emDias(-40), assinada: true);

        $item = $this->itemDoEpi($epi);

        $this->assertGreaterThan($antiga->getKey(), $substituta->getKey());
        $this->assertSame(
            (int) $substituta->getKey(),
            $item['entrega_id'],
            'entre duas entregas do mesmo dia, responde a de registro mais recente'
        );
    }

    // -----------------------------------------------------------------
    // Quem entra e quem fica de fora
    // -----------------------------------------------------------------

    /**
     * Desligado não vai a campo. A ficha dele continua existindo, de propósito,
     * mas cobrar EPI de quem não trabalha mais na empresa é pendência que
     * ninguém tem como resolver — mesmo critério do alerta semanal da Task 28.3.
     */
    public function test_tecnico_inativo_nao_e_avaliado_e_nao_vira_pendencia(): void
    {
        $epi = $this->criarEpi();
        $this->comTenant(fn () => $this->tecnico->update(['is_active' => false]));

        $situacao = $this->doTecnico();

        $this->assertFalse($situacao['avaliado']);
        $this->assertFalse($situacao['tecnico']['ativo']);
        $this->assertSame([], $situacao['itens']);
        $this->assertSame([], $situacao['pendencias']);
        $this->assertTrue($situacao['regular'], 'técnico desligado não pode aparecer como irregular');
        $this->assertSame(0, $situacao['resumo']['total']);
        $this->assertNotNull($epi->fresh(), 'o EPI obrigatório continua cadastrado: o que mudou foi só o técnico');
    }

    /**
     * Dois recortes do cadastro, e nenhum dos dois é irregularidade: EPI que a
     * empresa não marcou como obrigatório, e EPI que ela aposentou. Cobrar
     * "nunca recebeu" de um modelo tirado de uso seria pendência sem saída — o
     * próprio sistema recusa a entrega dele.
     */
    public function test_epi_nao_obrigatorio_e_epi_inativo_ficam_fora_da_situacao(): void
    {
        $obrigatorio = $this->criarEpi(['nome' => 'Respirador obrigatório']);
        $recomendado = $this->criarEpi(['nome' => 'Boné recomendado', 'obrigatorio' => false]);
        $aposentado = $this->criarEpi(['nome' => 'Máscara aposentada', 'ativo' => false]);

        $ids = collect($this->doTecnico()['itens'])->pluck('epi.id')->all();

        $this->assertContains((int) $obrigatorio->getKey(), $ids);
        $this->assertNotContains((int) $recomendado->getKey(), $ids);
        $this->assertNotContains((int) $aposentado->getKey(), $ids);
    }

    /**
     * Empresa que acabou de ligar o módulo não tem EPI obrigatório cadastrado, e
     * isso é o estado normal — nunca irregularidade. É a mesma regra do cadastro
     * regulatório do Plano 24, e a que o checklist da Task 29.4 herda.
     */
    public function test_empresa_sem_epi_obrigatorio_cadastrado_e_regular_e_sem_itens(): void
    {
        $situacao = $this->doTecnico();

        $this->assertTrue($situacao['avaliado']);
        $this->assertSame([], $situacao['itens']);
        $this->assertTrue($situacao['regular'], 'cadastro em branco nunca é pendência');
        $this->assertSame(0, $situacao['resumo']['total']);
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas
    // -----------------------------------------------------------------

    /**
     * Com o tenant resolvido e um técnico de outra empresa, as consultas desta
     * classe voltariam vazias e a resposta seria "nunca recebeu" para tudo — uma
     * afirmação falsa sobre o trabalhador de um concorrente. Falhar alto é o
     * comportamento desejado.
     */
    public function test_situacao_de_tecnico_de_outra_empresa_falha_alto(): void
    {
        $concorrente = Company::create(['name' => 'Concorrente da situação']);

        $tecnicoDoConcorrente = TenantAtual::comTenant(
            (int) $concorrente->getKey(),
            fn (): Technician => Technician::create([
                'name' => 'Técnico da concorrente',
                'email' => 'tecnico-concorrente-'.uniqid().'@concorrente.test',
                'phone' => '11988887777',
                'is_active' => true,
            ])
        );

        $this->expectException(RuntimeException::class);

        $this->comTenant(fn () => $this->situacoes->doTecnico($tecnicoDoConcorrente));
    }

    /**
     * A rede contra o teste acima: o mesmo cenário, cada empresa lendo o próprio
     * técnico, não pode misturar nada. O EPI obrigatório de uma não vira
     * pendência do técnico da outra.
     */
    public function test_a_situacao_de_uma_empresa_nao_enxerga_o_epi_da_outra(): void
    {
        $concorrente = Company::create(['name' => 'Concorrente do cadastro']);

        $meuEpi = $this->criarEpi(['nome' => 'Respirador da minha empresa']);

        [$tecnicoDoConcorrente, $epiDoConcorrente] = TenantAtual::comTenant(
            (int) $concorrente->getKey(),
            fn (): array => [
                Technician::create([
                    'name' => 'Técnico da concorrente',
                    'email' => 'tecnico-concorrente-'.uniqid().'@concorrente.test',
                    'phone' => '11988887777',
                    'is_active' => true,
                ]),
                PersonalProtectiveEquipmentFactory::new()->create(['nome' => 'Respirador da concorrente']),
            ]
        );

        $meus = collect($this->doTecnico()['itens'])->pluck('epi.id')->all();

        $doConcorrente = collect(TenantAtual::comTenant(
            (int) $concorrente->getKey(),
            fn (): array => $this->situacoes->doTecnico($tecnicoDoConcorrente)
        )['itens'])->pluck('epi.id')->all();

        $this->assertSame([(int) $meuEpi->getKey()], $meus);
        $this->assertSame([(int) $epiDoConcorrente->getKey()], $doConcorrente);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function doTecnico(): array
    {
        return $this->comTenant(fn (): array => $this->situacoes->doTecnico($this->tecnico->fresh()));
    }

    /**
     * O item da situação referente a um modelo de EPI.
     *
     * @return array<string, mixed>
     */
    private function itemDoEpi(PersonalProtectiveEquipment $epi): array
    {
        $item = collect($this->doTecnico()['itens'])
            ->firstWhere('epi.id', (int) $epi->getKey());

        $this->assertNotNull($item, "o EPI obrigatório {$epi->nome} deveria ter um item na situação do técnico");

        return $item;
    }

    private function criarTecnico(string $nome): Technician
    {
        return $this->comTenant(fn (): Technician => Technician::create([
            'name' => $nome,
            'email' => 'tecnico-'.uniqid().'@dedetizadora.test',
            'phone' => '11999990000',
            'is_active' => true,
        ]));
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function criarEpi(array $atributos = []): PersonalProtectiveEquipment
    {
        return $this->comTenant(
            fn (): PersonalProtectiveEquipment => PersonalProtectiveEquipmentFactory::new()->create($atributos)
        );
    }

    /**
     * Entrega histórica montada pela fábrica: o cenário desta classe é sempre o
     * retroativo, que o `PpeDeliveryService` não produz (ele recusa data futura,
     * e o interessante aqui é o passado).
     */
    private function entregar(
        PersonalProtectiveEquipment $epi,
        string $dia,
        bool $assinada = false,
    ): PpeDelivery {
        return $this->comTenant(function () use ($epi, $dia, $assinada): PpeDelivery {
            $fabrica = PpeDeliveryFactory::new()->entregueA($this->tecnico, $epi, $dia);

            return ($assinada ? $fabrica->assinada() : $fabrica)->create();
        });
    }

    /**
     * O relógio do teste, montado a partir de uma hora **de Brasília** e
     * convertido para UTC: é assim que o cenário das 21:30 vira 00:30 do dia
     * seguinte em UTC, que é o instante em que a virada de dia importa.
     */
    private function fixarRelogioEm(string $diaEmBrasilia, string $hora): void
    {
        $emUtc = CarbonImmutable::parse($diaEmBrasilia.' '.$hora, BusinessDate::fuso())->setTimezone('UTC');

        Carbon::setTestNow(Carbon::parse($emUtc->format('Y-m-d H:i:s'), 'UTC'));
    }

    private function emDias(int $dias): string
    {
        return BusinessDate::hoje()->addDays($dias)->toDateString();
    }

    private function comTenant(Closure $callback): mixed
    {
        return TenantAtual::comTenant((int) $this->empresa->getKey(), $callback);
    }
}
