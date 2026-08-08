<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Module;
use App\Models\NotificationQueue;
use App\Models\PersonalProtectiveEquipment;
use App\Models\PpeDelivery;
use App\Models\Technician;
use App\Services\ModuleService;
use App\Services\Ppe\PpeService;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use App\Support\RotinasAgendadas;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\PersonalProtectiveEquipmentFactory;
use Database\Factories\PpeDeliveryFactory;
use Database\Seeders\ModulesSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 28.7 do Plano 28: os alertas de EPI, com o foco no **critério de
 * parada**.
 *
 * Aviso que se repete para sempre é aviso que o usuário aprende a ignorar — e
 * junto com ele, os avisos que importam. Foi o defeito real corrigido no commit
 * `d9a3a9c` (documento de veículo renovado por linha nova reenviava aviso
 * semanal eternamente), e metade desta classe existe para que ele não volte
 * pela porta do EPI:
 *
 * - CA vencido para quando a validade do cadastro é atualizada **ou** quando o
 *   modelo é inativado;
 * - troca vencida para quando a entrega é devolvida, estornada **ou
 *   substituída por uma entrega mais recente do mesmo EPI ao mesmo técnico** —
 *   inclusive quando a substituição acontece **no mesmo dia**, que é o caso do
 *   item danificado e reposto na mesma manhã. Comparando só `entregue_em`, a
 *   linha velha continuaria avisando para sempre: o desempate por `id` é o que
 *   fecha esse buraco.
 *
 * O outro eixo é o silêncio devido: **campo em branco nunca é irregularidade**.
 * EPI sem CA cadastrado e entrega sem troca programada não geram alerta nenhum,
 * porque acusar de irregular quem apenas não preencheu destrói a confiança no
 * aviso — mesma regra do checklist do Plano 24.
 *
 * O tempo é sempre fixado. Os testes de cadência semanal viajam para
 * 1970-01-01 porque o marco muda a cada sete dias corridos contados daquela
 * época, e não a cada virada de semana do calendário: partir da própria época
 * garante que os primeiros seis dias caem no mesmo marco.
 */
class AlertaDeEpiServiceTest extends TestCase
{
    use RefreshDatabase;

    private const AGORA = '2026-07-15 12:00:00';

    private Company $empresa;

    private Technician $tecnico;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::AGORA);
        TenantAtual::limpar();

        // O módulo `epi` nasce desligado (`sempre_ativo = false`), e a rotina
        // pula o tenant sem ele. `ModulesSeeder` semeia o catálogo e vincula o
        // tenant fundador ao Plano Interno, que carrega todos os módulos.
        $this->seed(ModulesSeeder::class);

        // Mesmo motivo de `AlertaDeFrotaServiceTest`: a empresa 1 vem da
        // migration de fundação sem e-mail, e sem e-mail o disparo para a
        // empresa cairia em "sem_destino" e nenhum aviso nasceria na fila.
        Company::query()->whereKey(1)->update([
            'name' => 'Dedetizadora do EPI',
            'email' => 'contato@epi.test',
        ]);

        $this->empresa = Company::query()->findOrFail(1);
        $this->tecnico = $this->criarTecnico('Joana da Silva');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Validade do CA no cadastro
    // -----------------------------------------------------------------

    /**
     * Janelas cumulativas, como no documento de veículo do Plano 27: um CA a 5
     * dias do vencimento está dentro de 60, de 30 e de 7, e cada uma vira um
     * marco próprio. Não é repetição — são três avisos com urgências
     * diferentes.
     */
    public function test_ca_vencendo_em_5_dias_gera_avisos_nos_marcos_de_60_30_e_7(): void
    {
        $this->criarEpi(['validade_ca' => $this->emDias(5)]);

        $this->artisan('epi:verificar')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::EPI_COM_CA_A_VENCER);

        $this->assertCount(3, $avisos);

        foreach (['60', '30', '7'] as $marco) {
            $this->assertMarco($avisos, $marco);
        }
    }

    public function test_ca_vencendo_em_45_dias_gera_so_o_marco_de_60(): void
    {
        $this->criarEpi(['validade_ca' => $this->emDias(45)]);

        $this->artisan('epi:verificar')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::EPI_COM_CA_A_VENCER);

        $this->assertCount(1, $avisos);
        $this->assertMarco($avisos, '60');
    }

    public function test_ca_fora_das_janelas_nao_gera_aviso(): void
    {
        $this->criarEpi(['validade_ca' => $this->emDias(180)]);

        $this->artisan('epi:verificar')->assertSuccessful();

        $this->assertCount(0, $this->itensDoEvento(EventosDeNotificacao::EPI_COM_CA_A_VENCER));
    }

    /**
     * Campo em branco é estado neutro, nunca vermelho. O EPI sem certificado
     * cadastrado fica inteiramente fora da varredura, nos dois eventos.
     */
    public function test_epi_sem_ca_cadastrado_nunca_gera_alerta(): void
    {
        $this->criarEpi(['numero_ca' => null, 'validade_ca' => null]);

        $this->artisan('epi:verificar')->assertSuccessful();

        $this->assertCount(0, $this->itensDoEvento(EventosDeNotificacao::EPI_COM_CA_A_VENCER));
        $this->assertCount(0, $this->itensDoEvento(EventosDeNotificacao::EPI_COM_CA_VENCIDO));
    }

    /**
     * Cadastro pela metade também não vira alerta: sem o número do CA o aviso
     * não teria o que mandar renovar, e cobrar preenchimento é assunto de
     * checklist, não de alerta de vencimento.
     */
    public function test_epi_com_validade_mas_sem_numero_de_ca_nao_gera_alerta(): void
    {
        $this->criarEpi(['numero_ca' => null, 'validade_ca' => $this->emDias(-10)]);

        $this->artisan('epi:verificar')->assertSuccessful();

        $this->assertCount(0, $this->itensDoEvento(EventosDeNotificacao::EPI_COM_CA_VENCIDO));
    }

    public function test_epi_inativo_nao_gera_alerta_de_ca(): void
    {
        $this->criarEpi(['validade_ca' => $this->emDias(-10), 'ativo' => false]);

        $this->artisan('epi:verificar')->assertSuccessful();

        $this->assertCount(0, $this->itensDoEvento(EventosDeNotificacao::EPI_COM_CA_VENCIDO));
    }

    // -----------------------------------------------------------------
    // Idempotência e cadência semanal
    // -----------------------------------------------------------------

    /**
     * O que este teste prova é que a **segunda** passada não acrescenta nada, e
     * é por isso que ele compara o depois com o antes em vez de fixar um número.
     *
     * Fixar "1 aviso" mediria outra coisa: as janelas de `JANELAS_DE_CA` são
     * cumulativas, e um CA a 20 dias alcança a de 60 **e** a de 30, gerando uma
     * linha por marco — o mesmo que `AlertaDeFrotaService` faz com
     * `JANELAS_DE_DOCUMENTO`. Isso é o comportamento desejado (o aviso volta
     * mais urgente conforme a data se aproxima) e só aparece todo de uma vez na
     * primeira passada sobre cadastro retroativo, que é o cenário previsto na
     * "Ordem de aplicação em produção" do plano.
     *
     * A duplicação que este teste existe para pegar é outra: a do aviso que
     * reenvia a cada execução porque ninguém marcou que ele já saiu. Foi defeito
     * real no commit `d9a3a9c`, no alerta de documento de veículo.
     */
    public function test_rodar_a_rotina_duas_vezes_no_mesmo_dia_nao_duplica_o_aviso(): void
    {
        $this->criarEpi(['validade_ca' => $this->emDias(20)]);
        $this->entregaVencida(diasAtras: 60, vidaUtil: 30);

        $this->artisan('epi:verificar')->assertSuccessful();

        $aVencerNaPrimeira = count($this->itensDoEvento(EventosDeNotificacao::EPI_COM_CA_A_VENCER));
        $trocaNaPrimeira = count($this->itensDoEvento(EventosDeNotificacao::EPI_COM_TROCA_VENCIDA));

        $this->assertGreaterThan(0, $aVencerNaPrimeira, 'a primeira passada precisa avisar alguma coisa');
        $this->assertGreaterThan(0, $trocaNaPrimeira, 'a primeira passada precisa avisar alguma coisa');

        $this->artisan('epi:verificar')->assertSuccessful();

        $this->assertCount(
            $aVencerNaPrimeira,
            $this->itensDoEvento(EventosDeNotificacao::EPI_COM_CA_A_VENCER),
            'a segunda passada no mesmo dia não pode acrescentar aviso de CA a vencer'
        );
        $this->assertCount(
            $trocaNaPrimeira,
            $this->itensDoEvento(EventosDeNotificacao::EPI_COM_TROCA_VENCIDA),
            'a segunda passada no mesmo dia não pode acrescentar aviso de troca vencida'
        );
    }

    public function test_ca_vencido_reenvia_semanalmente_e_nunca_mais_de_uma_vez_por_semana(): void
    {
        $this->viajarPara('1970-01-01 12:00:00');

        $this->criarEpi(['validade_ca' => $this->emDias(-10)]);

        $this->artisan('epi:verificar')->assertSuccessful();
        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::EPI_COM_CA_VENCIDO));

        // Seis dias depois, ainda no mesmo marco semanal: nenhum aviso novo.
        $this->viajarPara('1970-01-07 12:00:00');
        $this->artisan('epi:verificar')->assertSuccessful();
        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::EPI_COM_CA_VENCIDO));

        // Sete dias no total: marco novo, aviso novo.
        $this->viajarPara('1970-01-08 12:00:00');
        $this->artisan('epi:verificar')->assertSuccessful();
        $this->assertCount(2, $this->itensDoEvento(EventosDeNotificacao::EPI_COM_CA_VENCIDO));
    }

    /**
     * Critério de parada nº 1: a validade nova salva no cadastro cala o aviso.
     * É o que a própria mensagem do e-mail promete ao usuário.
     */
    public function test_renovar_a_validade_do_ca_faz_o_aviso_de_vencido_parar(): void
    {
        $this->viajarPara('1970-01-01 12:00:00');

        $epi = $this->criarEpi(['validade_ca' => $this->emDias(-10)]);

        $this->artisan('epi:verificar')->assertSuccessful();
        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::EPI_COM_CA_VENCIDO));

        // Validade bem longe, para não cair em nenhuma janela de "a vencer" e o
        // teste medir só o reenvio do vencido.
        $this->naEmpresa(fn () => app(PpeService::class)->atualizar($epi, [
            'numero_ca' => 'CA-RENOVADO',
            'validade_ca' => $this->emDias(400),
        ]));

        $this->viajarPara('1970-01-08 12:00:00');
        $this->artisan('epi:verificar')->assertSuccessful();

        $this->assertCount(
            1,
            $this->itensDoEvento(EventosDeNotificacao::EPI_COM_CA_VENCIDO),
            'o CA renovado não podia continuar reenviando'
        );
    }

    /**
     * Regressão: o aviso de antecedência precisa voltar no ciclo seguinte do
     * certificado.
     *
     * O CA do EPI é renovado **na própria linha** do cadastro, ao contrário do
     * documento de veículo, que ganha linha nova a cada renovação. Enquanto o
     * marco era só a janela (`60`), a chave de idempotência de um modelo de EPI
     * era a mesma pela vida inteira do registro: os três avisos de antecedência
     * saíam uma única vez na história daquele cadastro, e do segundo ciclo em
     * diante a empresa só era avisada **depois** do vencimento — justamente o
     * que este alerta existe para evitar.
     *
     * Achado por revisão independente, depois de a implementação e os testes
     * originais darem o alerta por correto.
     */
    public function test_o_aviso_de_antecedencia_volta_quando_o_ca_renovado_se_aproxima_de_vencer(): void
    {
        $this->viajarPara('1970-01-01 12:00:00');

        $epi = $this->criarEpi(['validade_ca' => $this->emDias(50)]);

        $this->artisan('epi:verificar')->assertSuccessful();
        $this->assertCount(
            1,
            $this->itensDoEvento(EventosDeNotificacao::EPI_COM_CA_A_VENCER),
            'a primeira aproximação precisa avisar'
        );

        // A empresa renova o certificado no mesmo cadastro, como manda o fluxo.
        $this->naEmpresa(fn () => app(PpeService::class)->atualizar($epi, [
            'numero_ca' => 'CA-RENOVADO',
            'validade_ca' => $this->emDias(800),
        ]));

        // Dois anos depois, o CA renovado volta a estar a 50 dias de vencer.
        $this->viajarPara('1972-02-01 12:00:00');
        $this->artisan('epi:verificar')->assertSuccessful();

        $this->assertCount(
            2,
            $this->itensDoEvento(EventosDeNotificacao::EPI_COM_CA_A_VENCER),
            'o ciclo seguinte do certificado precisa avisar de novo'
        );
    }

    /**
     * Critério de parada nº 2: inativar é como a empresa aposenta o modelo
     * substituído por outro cadastro. Cobrar a renovação do certificado de um
     * modelo fora de uso é ruído garantido.
     */
    public function test_inativar_o_modelo_faz_o_aviso_de_ca_vencido_parar(): void
    {
        $this->viajarPara('1970-01-01 12:00:00');

        $epi = $this->criarEpi(['validade_ca' => $this->emDias(-10)]);

        $this->artisan('epi:verificar')->assertSuccessful();
        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::EPI_COM_CA_VENCIDO));

        $this->naEmpresa(fn () => app(PpeService::class)->inativar($epi));

        $this->viajarPara('1970-01-08 12:00:00');
        $this->artisan('epi:verificar')->assertSuccessful();

        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::EPI_COM_CA_VENCIDO));
    }

    // -----------------------------------------------------------------
    // Troca do item entregue
    // -----------------------------------------------------------------

    public function test_entrega_com_troca_vencida_gera_aviso_com_o_tecnico_e_o_epi(): void
    {
        $this->entregaVencida(diasAtras: 60, vidaUtil: 30);

        $this->artisan('epi:verificar')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::EPI_COM_TROCA_VENCIDA);

        $this->assertCount(1, $avisos);
        $this->assertStringContainsString('Joana da Silva', (string) $avisos->first()->corpo);
        $this->assertStringContainsString('há 30 dia(s)', (string) $avisos->first()->corpo);
    }

    public function test_troca_vencida_reenvia_semanalmente(): void
    {
        $this->viajarPara('1970-02-01 12:00:00');

        $this->entregaVencida(diasAtras: 60, vidaUtil: 30);

        $this->artisan('epi:verificar')->assertSuccessful();
        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::EPI_COM_TROCA_VENCIDA));

        $this->viajarPara('1970-02-08 12:00:00');
        $this->artisan('epi:verificar')->assertSuccessful();
        $this->assertCount(2, $this->itensDoEvento(EventosDeNotificacao::EPI_COM_TROCA_VENCIDA));
    }

    /**
     * `trocar_ate` nulo é "sem troca programada", não pendência. Inventar um
     * prazo padrão produziria alerta falso, e alerta falso faz o usuário
     * desligar o alerta inteiro.
     */
    public function test_entrega_sem_troca_programada_nunca_gera_aviso(): void
    {
        $epi = $this->criarEpi(['vida_util_dias' => null]);

        $this->naEmpresa(fn (): PpeDelivery => PpeDeliveryFactory::new()
            ->entregueA($this->tecnico, $epi, $this->emDias(-800))
            ->create());

        $this->artisan('epi:verificar')->assertSuccessful();

        $this->assertCount(0, $this->itensDoEvento(EventosDeNotificacao::EPI_COM_TROCA_VENCIDA));
    }

    public function test_entrega_devolvida_nao_gera_aviso_de_troca(): void
    {
        $entrega = $this->entregaVencida(diasAtras: 60, vidaUtil: 30);

        $this->naEmpresa(fn () => $entrega->update([
            'devolvido_em' => $this->emDias(-5),
            'motivo_devolucao' => 'Desligamento',
        ]));

        $this->artisan('epi:verificar')->assertSuccessful();

        $this->assertCount(0, $this->itensDoEvento(EventosDeNotificacao::EPI_COM_TROCA_VENCIDA));
    }

    public function test_entrega_estornada_nao_gera_aviso_de_troca(): void
    {
        $entrega = $this->entregaVencida(diasAtras: 60, vidaUtil: 30);

        $this->naEmpresa(fn () => $entrega->update([
            'estornada_em' => now(),
            'motivo_estorno' => 'Lançada no técnico errado.',
        ]));

        $this->artisan('epi:verificar')->assertSuccessful();

        $this->assertCount(0, $this->itensDoEvento(EventosDeNotificacao::EPI_COM_TROCA_VENCIDA));
    }

    /**
     * Critério de parada nº 3, e o mais importante: ninguém edita a entrega
     * antiga (ela é imutável, por ser documento oponível). Registra-se a
     * entrega do substituto — e a linha velha precisa calar.
     *
     * Sem isto, a entrega de 2024 avisaria toda semana para sempre, que é
     * exatamente o defeito do `d9a3a9c`.
     */
    public function test_entrega_substituida_por_outra_mais_recente_para_de_avisar(): void
    {
        $epi = $this->criarEpi(['vida_util_dias' => 30]);

        $this->entregar($epi, $this->emDias(-60));

        $this->artisan('epi:verificar')->assertSuccessful();
        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::EPI_COM_TROCA_VENCIDA));

        // O técnico recebe o substituto hoje. A entrega antiga continua no
        // banco, intocada, e para de avisar.
        $this->entregar($epi, $this->emDias(0), ['motivo' => 'substituicao']);

        $this->viajarPara('2026-07-30 12:00:00');
        $this->artisan('epi:verificar')->assertSuccessful();

        $this->assertCount(
            1,
            $this->itensDoEvento(EventosDeNotificacao::EPI_COM_TROCA_VENCIDA),
            'a entrega substituída não podia continuar reenviando'
        );
    }

    /**
     * O caso que só o desempate por `id` resolve: item danificado e reposto na
     * **mesma manhã**. As duas entregas têm o mesmo `entregue_em`, e comparar
     * apenas a data deixaria a linha velha avisando para sempre.
     *
     * As duas estão com a troca vencida, então o aviso certo é um só, e é o da
     * entrega mais nova — distinguida aqui pela quantidade, que sai no corpo do
     * e-mail.
     */
    public function test_substituicao_no_mesmo_dia_para_de_avisar_pela_ordem_de_registro(): void
    {
        $epi = $this->criarEpi(['vida_util_dias' => 30]);

        $this->entregar($epi, $this->emDias(-60), ['quantidade' => 1]);
        $this->entregar($epi, $this->emDias(-60), ['quantidade' => 7, 'motivo' => 'dano']);

        $this->artisan('epi:verificar')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::EPI_COM_TROCA_VENCIDA);

        $this->assertCount(1, $avisos, 'a entrega reposta no mesmo dia precisava calar a anterior');
        $this->assertStringContainsString('7 unidade(s)', (string) $avisos->first()->corpo);
    }

    /**
     * Estorno é a declaração de que aquela entrega nunca existiu. Deixá-la
     * calar a anterior apagaria justamente o aviso que o estorno faz voltar a
     * valer.
     */
    public function test_entrega_estornada_nao_conta_como_substituta_da_anterior(): void
    {
        $epi = $this->criarEpi(['vida_util_dias' => 30]);

        $this->entregar($epi, $this->emDias(-60), ['quantidade' => 1]);
        $substituta = $this->entregar($epi, $this->emDias(-2), ['quantidade' => 7]);

        $this->naEmpresa(fn () => $substituta->update([
            'estornada_em' => now(),
            'motivo_estorno' => 'Registrada no técnico errado.',
        ]));

        $this->artisan('epi:verificar')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::EPI_COM_TROCA_VENCIDA);

        $this->assertCount(1, $avisos, 'a entrega antiga voltou a valer quando a substituta foi estornada');
        $this->assertStringContainsString('1 unidade(s)', (string) $avisos->first()->corpo);
    }

    /**
     * A substituição é por técnico **e** por modelo de EPI: o respirador
     * entregue a outro técnico não cala a pendência deste.
     */
    public function test_entrega_a_outro_tecnico_nao_cala_a_troca_vencida_deste(): void
    {
        $epi = $this->criarEpi(['vida_util_dias' => 30]);
        $outro = $this->criarTecnico('Carlos Pereira');

        $this->entregar($epi, $this->emDias(-60));

        $this->naEmpresa(fn (): PpeDelivery => PpeDeliveryFactory::new()
            ->entregueA($outro, $epi, $this->emDias(0))
            ->create());

        $this->artisan('epi:verificar')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::EPI_COM_TROCA_VENCIDA);

        $this->assertCount(1, $avisos);
        $this->assertStringContainsString('Joana da Silva', (string) $avisos->first()->corpo);
    }

    /**
     * O buraco entre os dois critérios de parada, achado por revisão
     * independente: substituto **devolvido** não cala a entrega anterior.
     *
     * A regra original dizia que a entrega devolvida contava como substituta,
     * porque "o item chegou às mãos do técnico" e a devolução dele seria
     * pendência da linha nova. Não era: a linha nova está fora da varredura de
     * troca vencida justamente por estar devolvida, então ninguém alertava nada.
     * O técnico ficava sem respirador e o sistema calado — falha silenciosa, que
     * é pior que o aviso repetido, porque ninguém percebe que ele não veio.
     */
    public function test_substituto_devolvido_nao_cala_a_entrega_antiga(): void
    {
        Carbon::setTestNow(self::AGORA);

        $epi = $this->criarEpi(['vida_util_dias' => 30]);

        // Entregue há 200 dias: a troca venceu há 170.
        $this->entregar($epi, $this->emDias(-200), ['quantidade' => 1]);

        // Substituto entregue há 10 dias e devolvido há 5, por dano. Ele mesmo
        // não tem troca vencida (trocaria daqui a 20 dias) e, por estar
        // devolvido, não aparece na varredura por caminho nenhum.
        $this->entregar($epi, $this->emDias(-10), [
            'quantidade' => 7,
            'motivo' => 'dano',
            'devolvido_em' => $this->emDias(-5),
            'motivo_devolucao' => 'Danificado em campo.',
        ]);

        $this->artisan('epi:verificar')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::EPI_COM_TROCA_VENCIDA);

        $this->assertCount(
            1,
            $avisos,
            'o técnico está sem o equipamento: a entrega antiga precisava voltar a avisar'
        );
        $this->assertStringContainsString('1 unidade(s)', (string) $avisos->first()->corpo);
        $this->assertStringContainsString('há 170 dia(s)', (string) $avisos->first()->corpo);
    }

    /**
     * Contraprova do caso acima: o substituto que **continua com o técnico**
     * cala a entrega antiga, como sempre calou. O critério de parada não foi
     * removido — só deixou de valer para o item que voltou ao estoque.
     */
    public function test_substituto_nao_devolvido_continua_calando_a_entrega_antiga(): void
    {
        Carbon::setTestNow(self::AGORA);

        $epi = $this->criarEpi(['vida_util_dias' => 30]);

        $this->entregar($epi, $this->emDias(-200), ['quantidade' => 1]);
        $this->entregar($epi, $this->emDias(-10), ['quantidade' => 7, 'motivo' => 'dano']);

        $this->artisan('epi:verificar')->assertSuccessful();

        $this->assertCount(
            0,
            $this->itensDoEvento(EventosDeNotificacao::EPI_COM_TROCA_VENCIDA),
            'o substituto está na mão do técnico: a linha antiga tinha de continuar calada'
        );
    }

    /**
     * Alerta sem critério de parada, achado por revisão independente: o técnico
     * desligado com entrega antiga e sem devolução registrada recebia
     * `EPI_COM_TROCA_VENCIDA` toda semana, para sempre, sobre alguém que não
     * trabalha mais na empresa.
     *
     * É simetria com o `ativo` do modelo de EPI, que já saía da varredura de CA.
     * O item que ninguém deu baixa no desligamento continua sendo problema de
     * escritório — acerto de rescisão e conferência da ficha —, e não pendência
     * de troca: alertar troca eternamente não recupera equipamento nenhum.
     */
    public function test_entrega_de_tecnico_desligado_nao_gera_aviso_de_troca(): void
    {
        Carbon::setTestNow(self::AGORA);

        $desligado = $this->criarTecnico('Rafael Antunes', ativo: false);
        $epi = $this->criarEpi(['vida_util_dias' => 30]);

        $this->entregarPara($desligado, $epi, $this->emDias(-200));

        $this->artisan('epi:verificar')->assertSuccessful();

        $this->assertCount(
            0,
            $this->itensDoEvento(EventosDeNotificacao::EPI_COM_TROCA_VENCIDA),
            'quem não trabalha mais na empresa não tem troca de EPI a fazer'
        );
    }

    /**
     * Contraprova: o mesmo cenário, mudando só `is_active`, avisa. É o que
     * garante que o filtro novo não calou a varredura inteira.
     */
    public function test_o_mesmo_cenario_com_tecnico_ativo_gera_aviso_de_troca(): void
    {
        Carbon::setTestNow(self::AGORA);

        $ativo = $this->criarTecnico('Rafael Antunes');
        $epi = $this->criarEpi(['vida_util_dias' => 30]);

        $this->entregarPara($ativo, $epi, $this->emDias(-200));

        $this->artisan('epi:verificar')->assertSuccessful();

        $avisos = $this->itensDoEvento(EventosDeNotificacao::EPI_COM_TROCA_VENCIDA);

        $this->assertCount(1, $avisos);
        $this->assertStringContainsString('Rafael Antunes', (string) $avisos->first()->corpo);
    }

    // -----------------------------------------------------------------
    // Simulação e interruptor do módulo
    // -----------------------------------------------------------------

    /**
     * `--dry-run` existe para medir o volume da primeira passada depois de
     * ligar o módulo — toda entrega retroativa nasce com a troca vencida.
     * Enfileirar "só para conferir" mandaria os e-mails que a simulação deveria
     * evitar.
     */
    public function test_dry_run_conta_e_nao_grava_nada(): void
    {
        $this->criarEpi(['validade_ca' => $this->emDias(5)]);
        $this->entregaVencida(diasAtras: 60, vidaUtil: 30);

        $this->artisan('epi:verificar --dry-run')
            ->expectsOutputToContain('Modo simulação')
            ->expectsOutputToContain('Nada foi gravado.')
            ->assertSuccessful();

        $this->assertSame(0, NotificationQueue::query()->count());

        // Contraprova: sem a simulação, os mesmos dados geram avisos.
        $this->artisan('epi:verificar')->assertSuccessful();
        $this->assertGreaterThan(0, NotificationQueue::query()->count());
    }

    /**
     * O módulo nasce desligado, e é isso que permite subir a rotina já agendada
     * sem que nenhum aviso saia antes de a empresa cadastrar os EPIs com CA e
     * validade reais.
     */
    public function test_modulo_de_epi_desligado_nao_gera_aviso_nenhum(): void
    {
        $this->criarEpi(['validade_ca' => $this->emDias(-10)]);
        $this->entregaVencida(diasAtras: 60, vidaUtil: 30);

        $modulo = Module::query()->where('chave', 'epi')->firstOrFail();
        app(ModuleService::class)->bloquearPara($this->empresa, $modulo, 'Desligado no teste.');

        $this->artisan('epi:verificar')
            ->expectsOutputToContain('Módulo de EPI desligado nesta empresa')
            ->assertSuccessful();

        $this->assertSame(0, NotificationQueue::query()->count());
    }

    /**
     * A rotina é diária e roda às 05:45, antes do expediente. Quem mudar o
     * horário encontra esta afirmação e precisa atualizá-la junto.
     */
    public function test_a_rotina_esta_agendada_diariamente_as_05_45(): void
    {
        $this->assertSame('05:45', RotinasAgendadas::DIARIAS['epi:verificar'] ?? null);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function naEmpresa(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->getKey(), $callback);
    }

    private function criarTecnico(string $nome, bool $ativo = true): Technician
    {
        return $this->naEmpresa(fn (): Technician => Technician::create([
            'name' => $nome,
            'email' => 'tecnico-'.uniqid().'@dedetizadora.test',
            'phone' => '11999990000',
            'is_active' => $ativo,
        ]));
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function criarEpi(array $atributos = []): PersonalProtectiveEquipment
    {
        return $this->naEmpresa(
            fn (): PersonalProtectiveEquipment => PersonalProtectiveEquipmentFactory::new()->create($atributos)
        );
    }

    /**
     * Uma entrega com a troca já vencida, montada pela fábrica: o cenário é
     * retroativo de propósito, e é o que a primeira passada da rotina encontra
     * quando a empresa liga o módulo com o histórico já lançado.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function entregaVencida(int $diasAtras, int $vidaUtil, array $atributos = []): PpeDelivery
    {
        $epi = $this->criarEpi(['vida_util_dias' => $vidaUtil]);

        return $this->entregar($epi, $this->emDias(-$diasAtras), $atributos);
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function entregar(
        PersonalProtectiveEquipment $epi,
        string $dia,
        array $atributos = []
    ): PpeDelivery {
        return $this->entregarPara($this->tecnico, $epi, $dia, $atributos);
    }

    /**
     * A mesma entrega, para um técnico qualquer: os cenários de técnico
     * desligado precisam de outro cadastro, porque o `$this->tecnico` do `setUp`
     * nasce ativo e é usado pelo resto do arquivo.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function entregarPara(
        Technician $tecnico,
        PersonalProtectiveEquipment $epi,
        string $dia,
        array $atributos = []
    ): PpeDelivery {
        return $this->naEmpresa(fn (): PpeDelivery => PpeDeliveryFactory::new()
            ->entregueA($tecnico, $epi, $dia)
            ->create($atributos));
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
     * aplicação roda.
     */
    private function viajarPara(string $instanteEmUtc): void
    {
        $this->travelTo(CarbonImmutable::parse($instanteEmUtc, 'UTC'));
    }

    /**
     * O marco do aviso de antecedência é `{janela}-{validade}`, e não só a
     * janela: a validade entra na chave porque o CA é renovado na própria linha
     * do cadastro, e sem ela o aviso sairia uma vez só na vida do registro (ver
     * `test_o_aviso_de_antecedencia_volta_quando_o_ca_renovado_se_aproxima_de_vencer`).
     *
     * Por isso a conferência é pelo trecho `:{janela}-`, e não pelo fim da
     * chave.
     *
     * @param  Collection<int, NotificationQueue>  $avisos
     */
    private function assertMarco(Collection $avisos, string $marco): void
    {
        $this->assertTrue(
            $avisos->pluck('chave_idempotencia')
                ->contains(fn (string $chave): bool => str_contains($chave, ":{$marco}-")),
            "faltou o aviso do marco {$marco}"
        );
    }

    /**
     * Itens da fila deste evento, de todas as empresas: sem tenant explícito o
     * escopo global de `NotificationQueue` não filtra nada.
     *
     * @return Collection<int, NotificationQueue>
     */
    private function itensDoEvento(string $evento): Collection
    {
        return NotificationQueue::query()->where('evento', $evento)->orderBy('id')->get();
    }
}
