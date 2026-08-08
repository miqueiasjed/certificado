<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ComplianceCheck;
use App\Models\Module;
use App\Models\NormativeReference;
use App\Models\OrganRegistration;
use App\Models\PersonalProtectiveEquipment;
use App\Models\PpeDelivery;
use App\Models\Technician;
use App\Services\Compliance\ChecklistService;
use App\Services\ModuleService;
use App\Services\Ppe\SituacaoDeEpiService;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\PersonalProtectiveEquipmentFactory;
use Database\Factories\PpeDeliveryFactory;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\NormativeReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 24.7 do Plano 24: o checklist mostra todos os itens, distingue "não
 * aplicável" de "irregular", carrega a ressalva e não mistura tenants.
 *
 * A ressalva não é decoração. O checklist **informa, não certifica**: dizer
 * "sua empresa está regular" seria assumir responsabilidade que não é da
 * plataforma, porque parte do que a RDC 622/2022 exige (transporte dos
 * produtos, treinamento, licença afixada, letreiro na fachada) acontece fora do
 * sistema. Por isso existe teste cobrando que ela esteja na resposta.
 *
 * Task 29.4 do Plano 29: EPI saiu dessa lista
 * -------------------------------------------
 * O bloco no fim do arquivo cobre o item `epi_dos_tecnicos`, que substituiu a
 * ressalva antiga de que EPI é parte do que "o sistema nem enxerga". A garantia
 * que mais importa ali é a mesma do Plano 24, e é a que o plano registrou como
 * a regra mais importante da task: empresa que ligou o módulo e ainda não
 * marcou nenhum EPI como obrigatório é "não aplicável", **nunca** irregular.
 */
class ChecklistServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-07-26';

    private Company $empresaA;

    private Company $empresaB;

    private ChecklistService $checklist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixarRelogioEm(self::HOJE, '09:00');

        $this->checklist = app(ChecklistService::class);

        Company::query()->whereKey(1)->update(['name' => 'Dedetizadora A']);

        $this->empresaA = Company::query()->findOrFail(1);
        $this->empresaB = Company::create(['name' => 'Dedetizadora B']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    public function test_o_checklist_traz_todos_os_itens_com_situacao_detalhe_exigencia_e_acao(): void
    {
        $checklist = $this->naEmpresa($this->empresaA, fn () => $this->checklist->montar($this->empresaA));

        $itens = collect($checklist['itens'])->pluck('item')->all();

        $this->assertEqualsCanonicalizing([
            'registro_conselho',
            'licenca_sanitaria',
            'licenca_ambiental',
            'licenca_funcionamento',
            'registros_de_produto',
            ChecklistService::ITEM_REFERENCIA_NORMATIVA,
            ChecklistService::ITEM_DOCUMENTACAO_DA_EXECUCAO,
            ChecklistService::ITEM_VISITAS_DE_CONTRATO,
        ], $itens);

        foreach ($checklist['itens'] as $item) {
            $this->assertNotSame('', $item['rotulo']);
            $this->assertNotSame('', $item['detalhe'], "o item {$item['item']} precisa dizer o que está acontecendo");
            $this->assertNotSame('', $item['exigencia'], "o item {$item['item']} precisa dizer o que a norma pede");
            $this->assertArrayHasKey('acao', $item, "o item {$item['item']} precisa apontar o caminho de correção");
            $this->assertNotSame('', $item['acao']['texto']);
        }
    }

    public function test_a_ressalva_de_que_o_checklist_nao_certifica_esta_na_resposta(): void
    {
        $checklist = $this->naEmpresa($this->empresaA, fn () => $this->checklist->montar($this->empresaA));

        $this->assertArrayHasKey('ressalva', $checklist);
        $this->assertStringContainsString('não atesta', $checklist['ressalva']);
        $this->assertStringContainsString('RDC 622/2022', $checklist['ressalva']);
    }

    /**
     * O segundo teste mais importante do plano, junto com o de "não informado
     * não é irregular" em `ValidadeServiceTest`.
     */
    public function test_item_nao_aplicavel_e_distinguido_de_irregular(): void
    {
        // A referência padrão da plataforma é semeada no Deploy 1, antes de
        // qualquer tela existir: este é o estado real de um tenant recém
        // migrado, e é sobre ele que a garantia precisa valer.
        (new NormativeReferenceSeeder)->run();

        // Empresa sem nada preenchido e sem produto cadastrado.
        $checklist = $this->naEmpresa($this->empresaA, fn () => $this->checklist->montar($this->empresaA));

        $porItem = collect($checklist['itens'])->keyBy('item');

        $this->assertSame(
            ComplianceCheck::SITUACAO_NAO_APLICAVEL,
            $porItem['licenca_sanitaria']['situacao'],
            'licença sem validade cadastrada é lacuna de cadastro, nunca irregularidade'
        );
        $this->assertSame(
            ComplianceCheck::SITUACAO_NAO_APLICAVEL,
            $porItem['registros_de_produto']['situacao'],
            'empresa sem produto cadastrado não descumpre nada'
        );

        $this->assertSame(
            0,
            $checklist['resumo']['irregular'],
            'empresa que só ainda não preencheu o cadastro não pode ter item irregular'
        );

        // Agora com uma licença de fato vencida: aí sim é irregular.
        $this->empresaA->update(['licenca_sanitaria_validade' => $this->diaEmBrasilia(-1)]);

        $comVencida = $this->naEmpresa($this->empresaA, fn () => $this->checklist->montar($this->empresaA->fresh()));
        $porItem = collect($comVencida['itens'])->keyBy('item');

        $this->assertSame(ComplianceCheck::SITUACAO_IRREGULAR, $porItem['licenca_sanitaria']['situacao']);
        $this->assertSame(1, $comVencida['resumo']['irregular']);
    }

    public function test_registro_de_produto_vencido_deixa_o_item_agregado_irregular(): void
    {
        $this->naEmpresa($this->empresaA, function (): void {
            OrganRegistration::create(['record' => '3.0123.4567.001-8', 'validade' => $this->diaEmBrasilia(365)]);
            OrganRegistration::create(['record' => '3.0123.4567.002-6', 'validade' => $this->diaEmBrasilia(-5)]);
        });

        $checklist = $this->naEmpresa($this->empresaA, fn () => $this->checklist->montar($this->empresaA));
        $item = collect($checklist['itens'])->firstWhere('item', 'registros_de_produto');

        $this->assertSame(
            ComplianceCheck::SITUACAO_IRREGULAR,
            $item['situacao'],
            'vale a pior situação: um registro vencido já é o que a fiscalização enxerga'
        );
        $this->assertStringContainsString('3.0123.4567.002-6', $item['detalhe']);
    }

    /**
     * Sem referência nenhuma o item é irregular, e isso **não** contradiz a
     * regra de que "não informado nunca é irregular": aqui não se trata de um
     * campo em branco no cadastro do tenant, e sim de o documento estar sendo
     * emitido sem citar a resolução — um defeito concreto, com consequência
     * perante fiscalização. Esse estado não existe em produção depois do
     * Deploy 1, que semeia a referência padrão da plataforma.
     */
    public function test_referencia_normativa_do_tenant_deixa_o_item_regular(): void
    {
        // Sem referência nenhuma: irregular, porque o documento sai sem citar a
        // resolução.
        $semNada = $this->naEmpresa($this->empresaA, fn () => $this->checklist->montar($this->empresaA));
        $this->assertSame(
            ComplianceCheck::SITUACAO_IRREGULAR,
            collect($semNada['itens'])->firstWhere('item', ChecklistService::ITEM_REFERENCIA_NORMATIVA)['situacao']
        );

        // Só a da plataforma: atenção, e nunca irregular. Usar o padrão do
        // sistema não é irregularidade.
        NormativeReference::create([
            'company_id' => null,
            'chave' => NormativeReference::CHAVE_PRINCIPAL,
            'texto' => 'RDC nº 622, de 9 de março de 2022, da Anvisa',
            'ativo' => true,
        ]);

        $soPlataforma = $this->naEmpresa($this->empresaA, fn () => $this->checklist->montar($this->empresaA));
        $this->assertSame(
            ComplianceCheck::SITUACAO_ATENCAO,
            collect($soPlataforma['itens'])->firstWhere('item', ChecklistService::ITEM_REFERENCIA_NORMATIVA)['situacao']
        );

        // Com a do tenant: regular.
        NormativeReference::create([
            'company_id' => $this->empresaA->id,
            'chave' => NormativeReference::CHAVE_PRINCIPAL,
            'texto' => 'RDC nº 622/2022 (texto da empresa)',
            'ativo' => true,
        ]);

        $comDoTenant = $this->naEmpresa($this->empresaA, fn () => $this->checklist->montar($this->empresaA));
        $item = collect($comDoTenant['itens'])->firstWhere('item', ChecklistService::ITEM_REFERENCIA_NORMATIVA);

        $this->assertSame(ComplianceCheck::SITUACAO_REGULAR, $item['situacao']);
        $this->assertStringContainsString('texto da empresa', $item['detalhe']);
    }

    public function test_verificar_grava_o_estado_atual_sem_acumular_historico(): void
    {
        $this->empresaA->update(['licenca_sanitaria_validade' => $this->diaEmBrasilia(-1)]);

        $this->naEmpresa($this->empresaA, fn () => $this->checklist->verificar($this->empresaA->fresh()));

        $primeira = $this->naEmpresa($this->empresaA, fn () => ComplianceCheck::query()->count());
        $this->assertSame(8, $primeira);

        $this->assertSame(
            ComplianceCheck::SITUACAO_IRREGULAR,
            $this->naEmpresa($this->empresaA, fn () => ComplianceCheck::query()->where('item', 'licenca_sanitaria')->value('situacao'))
        );

        // Renova e recalcula: a mesma linha muda de situação, e nenhuma linha
        // nova aparece. `compliance_checks` guarda o estado atual, não o
        // histórico — o histórico é a auditoria do Plano 3.
        $this->empresaA->update(['licenca_sanitaria_validade' => $this->diaEmBrasilia(365)]);
        $this->naEmpresa($this->empresaA, fn () => $this->checklist->verificar($this->empresaA->fresh()));

        $this->assertSame($primeira, $this->naEmpresa($this->empresaA, fn () => ComplianceCheck::query()->count()));
        $this->assertSame(
            ComplianceCheck::SITUACAO_REGULAR,
            $this->naEmpresa($this->empresaA, fn () => ComplianceCheck::query()->where('item', 'licenca_sanitaria')->value('situacao'))
        );
    }

    public function test_o_checklist_de_um_tenant_nao_considera_dado_do_outro(): void
    {
        $this->empresaA->update(['licenca_sanitaria_validade' => $this->diaEmBrasilia(-1)]);

        $this->naEmpresa($this->empresaB, function (): void {
            OrganRegistration::create(['record' => 'REGISTRO-DA-B', 'validade' => $this->diaEmBrasilia(-30)]);
        });

        $daA = $this->naEmpresa($this->empresaA, fn () => $this->checklist->montar($this->empresaA->fresh()));
        $itensDaA = collect($daA['itens'])->keyBy('item');

        $this->assertSame(ComplianceCheck::SITUACAO_IRREGULAR, $itensDaA['licenca_sanitaria']['situacao']);
        $this->assertSame(
            ComplianceCheck::SITUACAO_NAO_APLICAVEL,
            $itensDaA['registros_de_produto']['situacao'],
            'o registro vencido é da empresa B e não pode aparecer no checklist da A'
        );
        $this->assertStringNotContainsString('REGISTRO-DA-B', $itensDaA['registros_de_produto']['detalhe']);

        $daB = $this->naEmpresa($this->empresaB, fn () => $this->checklist->montar($this->empresaB->fresh()));
        $itensDaB = collect($daB['itens'])->keyBy('item');

        $this->assertSame(
            ComplianceCheck::SITUACAO_NAO_APLICAVEL,
            $itensDaB['licenca_sanitaria']['situacao'],
            'a licença vencida é da empresa A e não pode aparecer no checklist da B'
        );
        $this->assertSame(ComplianceCheck::SITUACAO_IRREGULAR, $itensDaB['registros_de_produto']['situacao']);
    }

    public function test_resumo_do_painel_sai_do_que_esta_gravado(): void
    {
        (new NormativeReferenceSeeder)->run();

        $vazio = $this->naEmpresa($this->empresaA, fn () => $this->checklist->resumoParaPainel());

        $this->assertSame(0, $vazio['total']);
        $this->assertNull(
            $vazio['verificado_em'],
            'sem verificação registrada o painel precisa dizer que ninguém verificou, e não "0 irregulares"'
        );

        $this->empresaA->update(['licenca_sanitaria_validade' => $this->diaEmBrasilia(-1)]);
        $this->naEmpresa($this->empresaA, fn () => $this->checklist->verificar($this->empresaA->fresh()));

        $resumo = $this->naEmpresa($this->empresaA, fn () => $this->checklist->resumoParaPainel());

        $this->assertSame(8, $resumo['total']);
        $this->assertSame(1, $resumo['irregular']);
        $this->assertNotNull($resumo['verificado_em']);
    }

    // -----------------------------------------------------------------
    // Item de EPI (Plano 29, Task 29.4)
    // -----------------------------------------------------------------

    /**
     * O módulo nasce desligado, e o Deploy 1 do Plano 29 sobe com ele assim em
     * todo mundo: nesse estado o item nem aparece, em vez de ocupar uma linha
     * "não aplicável" falando de funcionalidade que a empresa não contratou.
     *
     * É também o que mantém os oito itens dos testes acima intactos.
     */
    public function test_sem_o_modulo_de_epi_ligado_o_item_nao_entra_no_checklist(): void
    {
        $checklist = $this->naEmpresa($this->empresaA, fn () => $this->checklist->montar($this->empresaA));

        $this->assertNull(
            collect($checklist['itens'])->firstWhere('item', ChecklistService::ITEM_EPI_DOS_TECNICOS),
            'tenant sem o módulo epi não pode ver o item de EPI no checklist'
        );
    }

    public function test_o_item_de_epi_traz_exigencia_detalhe_e_acao(): void
    {
        $this->ligarModuloDeEpi($this->empresaA);

        $tecnico = $this->criarTecnico($this->empresaA, 'Ana Souza');
        $epi = $this->criarEpi($this->empresaA);
        $this->entregar($this->empresaA, $tecnico, $epi, $this->diaEmBrasilia(-10), assinada: true);

        $item = $this->itemDeEpi($this->empresaA);

        $this->assertNotNull($item, 'com o módulo ligado o item precisa aparecer');
        $this->assertSame(ComplianceCheck::SITUACAO_REGULAR, $item['situacao']);
        $this->assertNotSame('', $item['rotulo']);
        $this->assertStringContainsString('NR-6', $item['exigencia'], 'o item precisa dizer o que a norma pede');
        $this->assertStringContainsString('RDC 622/2022', $item['exigencia']);
        $this->assertStringContainsString('1 técnico(s) ativo(s)', $item['detalhe']);
        $this->assertNotSame('', $item['acao']['texto'], 'o item precisa apontar o caminho de correção');
    }

    /**
     * A garantia mais importante desta task, e a razão declarada do plano:
     * acusar de irregular quem apenas não preencheu destruiria a confiança no
     * checklist inteiro. Empresa que acabou de ligar o módulo, sem exigência de
     * EPI cadastrada, é estado normal.
     */
    public function test_sem_epi_obrigatorio_cadastrado_o_item_e_nao_aplicavel_e_nunca_irregular(): void
    {
        (new NormativeReferenceSeeder)->run();
        $this->ligarModuloDeEpi($this->empresaA);

        $this->criarTecnico($this->empresaA, 'Ana Souza');

        // Nem "nenhum EPI cadastrado" nem "EPI cadastrado sem ser obrigatório"
        // são irregularidade: os dois são cadastro que ainda não foi preenchido.
        $semNada = $this->itemDeEpi($this->empresaA);

        $this->assertSame(ComplianceCheck::SITUACAO_NAO_APLICAVEL, $semNada['situacao']);
        $this->assertStringContainsString('ainda não foi preenchido', $semNada['detalhe']);

        $this->criarEpi($this->empresaA, ['obrigatorio' => false]);

        $checklist = $this->naEmpresa($this->empresaA, fn () => $this->checklist->montar($this->empresaA));
        $item = collect($checklist['itens'])->firstWhere('item', ChecklistService::ITEM_EPI_DOS_TECNICOS);

        $this->assertSame(
            ComplianceCheck::SITUACAO_NAO_APLICAVEL,
            $item['situacao'],
            'EPI cadastrado sem ser obrigatório continua sendo cadastro em branco, não irregularidade'
        );
        $this->assertSame(
            0,
            $checklist['resumo']['irregular'],
            'empresa que só ligou o módulo e ainda não preencheu o cadastro não pode ter item irregular'
        );
    }

    /**
     * Sem técnico ativo não há a quem fornecer EPI. Também não é irregularidade.
     */
    public function test_sem_tecnico_ativo_o_item_e_nao_aplicavel(): void
    {
        $this->ligarModuloDeEpi($this->empresaA);

        $this->criarEpi($this->empresaA);
        $this->criarTecnico($this->empresaA, 'Carlos Desligado', ativo: false);

        $item = $this->itemDeEpi($this->empresaA);

        $this->assertSame(ComplianceCheck::SITUACAO_NAO_APLICAVEL, $item['situacao']);
        $this->assertStringContainsString('Nenhum técnico ativo', $item['detalhe']);
    }

    /**
     * Troca vencida é irregular, e o vencimento vem de
     * `AlertaDeEpiService::trocaVencida()` pelo `SituacaoDeEpiService` — a
     * mesma regra do alerta semanal da Task 28.3, nunca uma segunda comparação
     * de data escrita no checklist.
     */
    public function test_tecnico_com_troca_vencida_deixa_o_item_irregular(): void
    {
        $this->ligarModuloDeEpi($this->empresaA);

        $tecnico = $this->criarTecnico($this->empresaA, 'Ana Souza');
        $epi = $this->criarEpi($this->empresaA, ['vida_util_dias' => 30]);
        $this->entregar($this->empresaA, $tecnico, $epi, $this->diaEmBrasilia(-60), assinada: true);

        $item = $this->itemDeEpi($this->empresaA);

        $this->assertSame(ComplianceCheck::SITUACAO_IRREGULAR, $item['situacao']);
        $this->assertStringContainsString('1 com troca vencida', $item['detalhe']);
        $this->assertStringContainsString('Ana Souza', $item['detalhe']);
        $this->assertStringContainsString(
            SituacaoDeEpiService::ROTULOS_DE_SITUACAO[SituacaoDeEpiService::TROCA_VENCIDA],
            $item['detalhe']
        );
    }

    /**
     * Entrega sem assinatura é irregular porque é **justamente a prova que
     * falta**: a confirmação de recebimento é o que a NR-6 (item 6.5.1) aceita
     * como registro do fornecimento. Agrupá-la em "em dia" pintaria de verde o
     * único caso em que a empresa perde a reclamatória trabalhista.
     */
    public function test_entrega_sem_assinatura_deixa_o_item_irregular(): void
    {
        $this->ligarModuloDeEpi($this->empresaA);

        $tecnico = $this->criarTecnico($this->empresaA, 'Bruno Lima');
        $epi = $this->criarEpi($this->empresaA);
        $this->entregar($this->empresaA, $tecnico, $epi, $this->diaEmBrasilia(-5));

        $item = $this->itemDeEpi($this->empresaA);

        $this->assertSame(ComplianceCheck::SITUACAO_IRREGULAR, $item['situacao']);
        $this->assertStringContainsString('1 com entrega sem assinatura', $item['detalhe']);
        $this->assertStringContainsString('Bruno Lima', $item['detalhe']);
    }

    public function test_tecnico_que_nunca_recebeu_epi_obrigatorio_deixa_o_item_irregular(): void
    {
        $this->ligarModuloDeEpi($this->empresaA);

        $this->criarEpi($this->empresaA);
        $this->criarTecnico($this->empresaA, 'Carla Dias');

        $item = $this->itemDeEpi($this->empresaA);

        $this->assertSame(ComplianceCheck::SITUACAO_IRREGULAR, $item['situacao']);
        $this->assertStringContainsString('1 que nunca recebeu EPI obrigatório', $item['detalhe']);
        $this->assertStringContainsString('Carla Dias', $item['detalhe']);
    }

    /**
     * Técnico desligado não vai a campo. A ficha dele continua existindo, de
     * propósito, mas cobrar EPI de quem não trabalha mais na empresa é
     * pendência que ninguém tem como resolver — mesmo critério do alerta
     * semanal da Task 28.3.
     */
    public function test_tecnico_inativo_nao_conta_no_item_de_epi(): void
    {
        $this->ligarModuloDeEpi($this->empresaA);

        $epi = $this->criarEpi($this->empresaA);

        $ativo = $this->criarTecnico($this->empresaA, 'Ana Souza');
        $this->entregar($this->empresaA, $ativo, $epi, $this->diaEmBrasilia(-10), assinada: true);

        // Desligado, sem entrega nenhuma: seria "nunca recebeu" se contasse.
        $this->criarTecnico($this->empresaA, 'Zeca Desligado', ativo: false);

        $item = $this->itemDeEpi($this->empresaA);

        $this->assertSame(
            ComplianceCheck::SITUACAO_REGULAR,
            $item['situacao'],
            'a pendência é de um técnico desligado e não pode deixar o item irregular'
        );
        $this->assertStringNotContainsString('Zeca Desligado', $item['detalhe']);
        $this->assertStringContainsString('Os 1 técnico(s) ativo(s)', $item['detalhe']);
    }

    public function test_o_item_de_epi_de_um_tenant_nao_considera_tecnico_do_outro(): void
    {
        $this->ligarModuloDeEpi($this->empresaA);
        $this->ligarModuloDeEpi($this->empresaB);

        $epiDaA = $this->criarEpi($this->empresaA);
        $tecnicoDaA = $this->criarTecnico($this->empresaA, 'Ana da A');
        $this->entregar($this->empresaA, $tecnicoDaA, $epiDaA, $this->diaEmBrasilia(-10), assinada: true);

        // A empresa B tem EPI obrigatório e um técnico que nunca recebeu nada.
        $this->criarEpi($this->empresaB);
        $this->criarTecnico($this->empresaB, 'Bruno da B');

        $daA = $this->itemDeEpi($this->empresaA);
        $daB = $this->itemDeEpi($this->empresaB);

        $this->assertSame(ComplianceCheck::SITUACAO_REGULAR, $daA['situacao']);
        $this->assertStringNotContainsString('Bruno da B', $daA['detalhe']);

        $this->assertSame(ComplianceCheck::SITUACAO_IRREGULAR, $daB['situacao']);
        $this->assertStringContainsString('Bruno da B', $daB['detalhe']);
    }

    /**
     * `compliance_checks` guarda o **estado atual**. Desligar o módulo tira o
     * item do checklist, e a linha gravada precisa sair junto: senão o resumo
     * do painel continuaria contando um "irregular" de EPI que nenhuma tela
     * mostra e que ninguém teria como resolver.
     */
    public function test_desligar_o_modulo_de_epi_remove_a_linha_gravada_do_item(): void
    {
        $this->ligarModuloDeEpi($this->empresaA);

        $this->criarEpi($this->empresaA);
        $this->criarTecnico($this->empresaA, 'Carla Dias');

        $this->naEmpresa($this->empresaA, fn () => $this->checklist->verificar($this->empresaA->fresh()));

        $this->assertSame(
            ComplianceCheck::SITUACAO_IRREGULAR,
            $this->naEmpresa($this->empresaA, fn () => ComplianceCheck::query()
                ->where('item', ChecklistService::ITEM_EPI_DOS_TECNICOS)
                ->value('situacao'))
        );
        $this->assertSame(9, $this->naEmpresa($this->empresaA, fn () => ComplianceCheck::query()->count()));

        $modulo = Module::query()->where('chave', 'epi')->firstOrFail();
        app(ModuleService::class)->bloquearPara($this->empresaA, $modulo, 'Desligado no teste.');

        $this->naEmpresa($this->empresaA, fn () => $this->checklist->verificar($this->empresaA->fresh()));

        $this->assertSame(8, $this->naEmpresa($this->empresaA, fn () => ComplianceCheck::query()->count()));
        $this->assertNull(
            $this->naEmpresa($this->empresaA, fn () => ComplianceCheck::query()
                ->where('item', ChecklistService::ITEM_EPI_DOS_TECNICOS)
                ->value('situacao')),
            'item que saiu do checklist não pode deixar estado velho no painel'
        );
    }

    /**
     * O contrato que o sistema emite promete ao cliente que a equipe usa EPI. A
     * ressalva não pode mais dizer que EPI é parte do que o sistema não
     * enxerga — e ao mesmo tempo continua obrigatória, porque o item comprova o
     * fornecimento registrado, não a regularidade em segurança do trabalho.
     */
    public function test_a_ressalva_nao_lista_mais_epi_como_fora_do_sistema(): void
    {
        $this->ligarModuloDeEpi($this->empresaA);

        $checklist = $this->naEmpresa($this->empresaA, fn () => $this->checklist->montar($this->empresaA));

        $this->assertStringNotContainsString('EPI', $checklist['ressalva']);
        $this->assertStringContainsString('não atesta', $checklist['ressalva']);
        $this->assertStringContainsString('RDC 622/2022', $checklist['ressalva']);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Liga o módulo `epi` para a empresa.
     *
     * `ModulesSeeder` precisa rodar antes porque o catálogo de módulos não é
     * semeado no `setUp` desta classe — é justamente essa ausência que mantém
     * os oito itens originais do checklist nos testes do Plano 24.
     */
    private function ligarModuloDeEpi(Company $empresa): void
    {
        $this->seed(ModulesSeeder::class);

        $modulo = Module::query()->where('chave', 'epi')->firstOrFail();

        app(ModuleService::class)->liberarPara($empresa, $modulo, 'Ligado no teste.', null);
    }

    /**
     * O item de EPI do checklist da empresa, ou `null` quando ele não aparece.
     *
     * @return array<string, mixed>|null
     */
    private function itemDeEpi(Company $empresa): ?array
    {
        $checklist = $this->naEmpresa($empresa, fn () => $this->checklist->montar($empresa->fresh()));

        return collect($checklist['itens'])->firstWhere('item', ChecklistService::ITEM_EPI_DOS_TECNICOS);
    }

    private function criarTecnico(Company $empresa, string $nome, bool $ativo = true): Technician
    {
        return $this->naEmpresa($empresa, fn (): Technician => Technician::create([
            'name' => $nome,
            'email' => 'tecnico-'.uniqid().'@dedetizadora.test',
            'phone' => '11999990000',
            'is_active' => $ativo,
        ]));
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function criarEpi(Company $empresa, array $atributos = []): PersonalProtectiveEquipment
    {
        return $this->naEmpresa(
            $empresa,
            fn (): PersonalProtectiveEquipment => PersonalProtectiveEquipmentFactory::new()->create($atributos)
        );
    }

    /**
     * Entrega histórica montada pela fábrica: o cenário interessante aqui é o
     * retroativo, que é o que o checklist encontra quando a empresa liga o
     * módulo com o histórico já lançado.
     */
    private function entregar(
        Company $empresa,
        Technician $tecnico,
        PersonalProtectiveEquipment $epi,
        string $dia,
        bool $assinada = false,
    ): PpeDelivery {
        return $this->naEmpresa($empresa, function () use ($tecnico, $epi, $dia, $assinada): PpeDelivery {
            $fabrica = PpeDeliveryFactory::new()->entregueA($tecnico, $epi, $dia);

            return ($assinada ? $fabrica->assinada() : $fabrica)->create();
        });
    }

    private function fixarRelogioEm(string $diaEmBrasilia, string $hora): void
    {
        $emUtc = CarbonImmutable::parse($diaEmBrasilia.' '.$hora, BusinessDate::fuso())->setTimezone('UTC');

        Carbon::setTestNow(Carbon::parse($emUtc->format('Y-m-d H:i:s'), 'UTC'));
    }

    private function diaEmBrasilia(int $dias): string
    {
        return BusinessDate::hoje()->addDays($dias)->toDateString();
    }

    private function naEmpresa(Company $empresa, Closure $callback): mixed
    {
        return TenantAtual::comTenant((int) $empresa->id, $callback);
    }
}
