<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ComplianceCheck;
use App\Models\NormativeReference;
use App\Models\OrganRegistration;
use App\Services\Compliance\ChecklistService;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
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
 * produtos, EPI, treinamento, licença afixada, letreiro na fachada) acontece
 * fora do sistema. Por isso existe teste cobrando que ela esteja na resposta.
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
    // Apoio
    // -----------------------------------------------------------------

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
