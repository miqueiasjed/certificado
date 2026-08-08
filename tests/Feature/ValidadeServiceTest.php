<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ComplianceCheck;
use App\Models\Module;
use App\Models\NotificationQueue;
use App\Models\OrganRegistration;
use App\Services\Compliance\ValidadeService;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Task 24.7 do Plano 24: as validades regulatórias são classificadas certo, os
 * avisos saem na cadência combinada, e "não informado" nunca vira "irregular".
 *
 * O teste mais importante deste arquivo é
 * `test_validade_nula_e_nao_informado_e_nunca_irregular`. Hoje o cadastro de
 * validades de todo tenant está em branco, porque as colunas nasceram nulas na
 * Task 24.1; se o sistema tratasse campo vazio como vencimento, ele acusaria de
 * irregular a totalidade dos clientes no primeiro dia, e ninguém voltaria a
 * abrir o checklist depois disso.
 *
 * O relógio é fixado em UTC, e não em Brasília, pelo mesmo motivo já registrado
 * em `EventosDeNotificacaoTest`: `Carbon::setTestNow()` empresta o fuso da
 * instância mockada para toda data criada sem fuso explícito, inclusive a que o
 * Eloquent monta ao ler coluna `datetime`. Fixar em Brasília faria as asserções
 * medirem o defeito do teste, não o comportamento do código.
 */
class ValidadeServiceTest extends TestCase
{
    use RefreshDatabase;

    private const HOJE = '2026-07-26';

    private Company $empresaA;

    private Company $empresaB;

    private ValidadeService $validades;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixarRelogioEm(self::HOJE, '09:00');

        $this->validades = app(ValidadeService::class);

        Company::query()->whereKey(1)->update([
            'name' => 'Dedetizadora A',
            'email' => 'contato@a.test',
        ]);

        $this->empresaA = Company::query()->findOrFail(1);
        $this->empresaB = Company::create([
            'name' => 'Dedetizadora B',
            'email' => 'contato@b.test',
        ]);

        $this->ligarModuloDeConformidade();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Classificação
    // -----------------------------------------------------------------

    /**
     * O teste central do plano. Validade em branco é lacuna de cadastro, e
     * lacuna de cadastro não é irregularidade.
     */
    public function test_validade_nula_e_nao_informado_e_nunca_irregular(): void
    {
        $classificacao = $this->validades->classificar(null);

        $this->assertSame(ValidadeService::SITUACAO_NAO_INFORMADO, $classificacao['situacao']);
        $this->assertNotSame(
            ValidadeService::SITUACAO_IRREGULAR,
            $classificacao['situacao'],
            'validade não informada jamais pode ser classificada como irregular'
        );
        $this->assertNull(
            $classificacao['dias_para_vencer'],
            'sem validade não existe contagem de dias; zero significaria "vence hoje"'
        );

        // E o mesmo vale para a empresa inteira sem nada preenchido, que é o
        // estado real de todo tenant no dia do deploy.
        $documentos = $this->validades->documentosDaEmpresa($this->empresaA);

        $this->assertCount(4, $documentos);

        foreach ($documentos as $documento) {
            $this->assertSame(
                ValidadeService::SITUACAO_NAO_INFORMADO,
                $documento['situacao'],
                "o documento {$documento['item']} de uma empresa sem cadastro não pode ser irregular"
            );
        }
    }

    public function test_classifica_regular_atencao_e_irregular_pelo_dia_de_brasilia(): void
    {
        $this->assertSame(
            ValidadeService::SITUACAO_REGULAR,
            $this->validades->classificar($this->diaEmBrasilia(61))['situacao']
        );

        $this->assertSame(
            ValidadeService::SITUACAO_ATENCAO,
            $this->validades->classificar($this->diaEmBrasilia(60))['situacao']
        );

        // Vence hoje: ainda é atenção, e não irregular. Uma licença que vence
        // hoje vale hoje.
        $hoje = $this->validades->classificar($this->diaEmBrasilia(0));
        $this->assertSame(ValidadeService::SITUACAO_ATENCAO, $hoje['situacao']);
        $this->assertSame(0, $hoje['dias_para_vencer']);

        $ontem = $this->validades->classificar($this->diaEmBrasilia(-1));
        $this->assertSame(ValidadeService::SITUACAO_IRREGULAR, $ontem['situacao']);
        $this->assertSame(-1, $ontem['dias_para_vencer']);
    }

    /**
     * Às 23h de Brasília o servidor em UTC já virou o dia. A licença que vence
     * "hoje" em Brasília não pode aparecer vencida por causa disso.
     */
    public function test_corte_de_vencimento_usa_o_dia_de_brasilia_e_nao_o_de_utc(): void
    {
        $this->fixarRelogioEm(self::HOJE, '23:00');

        $this->assertSame(
            '2026-07-27',
            CarbonImmutable::now('UTC')->toDateString(),
            'o cenário só faz sentido com o servidor em UTC já no dia seguinte'
        );

        $classificacao = $this->validades->classificar(self::HOJE);

        $this->assertSame(
            ValidadeService::SITUACAO_ATENCAO,
            $classificacao['situacao'],
            'a licença que vence hoje em Brasília não está vencida às 23h'
        );
        $this->assertSame(0, $classificacao['dias_para_vencer']);
    }

    // -----------------------------------------------------------------
    // Avisos da rotina
    // -----------------------------------------------------------------

    public function test_marcos_de_60_30_e_7_dias_geram_um_aviso_cada(): void
    {
        $this->empresaA->update(['licenca_sanitaria_validade' => $this->diaEmBrasilia(60)]);

        $this->rodarRotina();
        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::DOCUMENTO_REGULATORIO_A_VENCER));

        // Rodar de novo no mesmo dia não repete: a chave de idempotência
        // carrega o marco, e o marco não mudou.
        $this->rodarRotina();
        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::DOCUMENTO_REGULATORIO_A_VENCER));

        // Trinta dias depois, o mesmo documento atinge o marco de 30.
        $this->fixarRelogioEm('2026-08-25', '09:00');
        $this->rodarRotina();
        $this->assertCount(2, $this->itensDoEvento(EventosDeNotificacao::DOCUMENTO_REGULATORIO_A_VENCER));

        // E depois o de 7.
        $this->fixarRelogioEm('2026-09-17', '09:00');
        $this->rodarRotina();
        $this->assertCount(3, $this->itensDoEvento(EventosDeNotificacao::DOCUMENTO_REGULATORIO_A_VENCER));
    }

    public function test_documento_vencido_avisa_semanalmente(): void
    {
        $this->empresaA->update(['licenca_sanitaria_validade' => $this->diaEmBrasilia(-3)]);

        $this->rodarRotina();
        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::DOCUMENTO_REGULATORIO_VENCIDO));

        // No dia seguinte, nada de novo: o marco semanal não virou.
        $this->fixarRelogioEm('2026-07-27', '09:00');
        $this->rodarRotina();
        $this->assertCount(1, $this->itensDoEvento(EventosDeNotificacao::DOCUMENTO_REGULATORIO_VENCIDO));

        // Sete dias depois, sim.
        $this->fixarRelogioEm('2026-08-03', '09:00');
        $this->rodarRotina();
        $this->assertCount(2, $this->itensDoEvento(EventosDeNotificacao::DOCUMENTO_REGULATORIO_VENCIDO));
    }

    public function test_nao_informado_gera_aviso_mensal_de_cadastro_incompleto_e_nunca_de_vencimento(): void
    {
        // Empresa sem nenhuma validade preenchida: o estado real no deploy.
        $this->rodarRotina();

        $this->assertCount(
            0,
            $this->itensDoEvento(EventosDeNotificacao::DOCUMENTO_REGULATORIO_A_VENCER),
            'documento sem validade cadastrada não pode gerar aviso de vencimento'
        );
        $this->assertCount(
            0,
            $this->itensDoEvento(EventosDeNotificacao::DOCUMENTO_REGULATORIO_VENCIDO),
            'documento sem validade cadastrada não pode ser tratado como vencido'
        );

        $incompletos = $this->itensDoEvento(EventosDeNotificacao::CADASTRO_REGULATORIO_INCOMPLETO);
        $this->assertCount(2, $incompletos, 'um aviso por empresa, e as duas estão com o cadastro em branco');
        $this->assertStringContainsString('Licença sanitária', $incompletos[0]->corpo);

        // No dia seguinte, nada: o marco é o mês.
        $this->fixarRelogioEm('2026-07-27', '09:00');
        $this->rodarRotina();
        $this->assertCount(2, $this->itensDoEvento(EventosDeNotificacao::CADASTRO_REGULATORIO_INCOMPLETO));

        // No mês seguinte, um novo.
        $this->fixarRelogioEm('2026-08-01', '09:00');
        $this->rodarRotina();
        $this->assertCount(4, $this->itensDoEvento(EventosDeNotificacao::CADASTRO_REGULATORIO_INCOMPLETO));
    }

    public function test_atualizar_a_validade_encerra_os_avisos_pendentes_na_hora(): void
    {
        $this->empresaA->update(['licenca_sanitaria_validade' => $this->diaEmBrasilia(-3)]);
        $this->empresaA->update(['licenca_ambiental_validade' => $this->diaEmBrasilia(-3)]);

        $this->rodarRotina();

        $pendentes = $this->naEmpresa($this->empresaA, fn () => NotificationQueue::query()
            ->where('evento', EventosDeNotificacao::DOCUMENTO_REGULATORIO_VENCIDO)
            ->where('situacao', NotificationQueue::SITUACAO_PENDENTE)
            ->count());

        $this->assertSame(2, $pendentes, 'a rotina precisava ter enfileirado os dois documentos vencidos');

        // Renovou só a sanitária.
        $this->empresaA->update(['licenca_sanitaria_validade' => $this->diaEmBrasilia(365)]);

        $restantes = $this->naEmpresa($this->empresaA, fn () => NotificationQueue::query()
            ->where('evento', EventosDeNotificacao::DOCUMENTO_REGULATORIO_VENCIDO)
            ->where('situacao', NotificationQueue::SITUACAO_PENDENTE)
            ->get());

        $this->assertCount(
            1,
            $restantes,
            'renovar a licença sanitária precisa calar o aviso dela, e só o dela'
        );
        $this->assertStringContainsString('Licença ambiental', $restantes->first()->corpo);
    }

    // -----------------------------------------------------------------
    // Registro de produto na Anvisa
    // -----------------------------------------------------------------

    public function test_registro_de_produto_vencido_muda_a_situacao_e_volta_ao_atualizar(): void
    {
        $registro = $this->naEmpresa($this->empresaA, fn () => OrganRegistration::create([
            'record' => '3.0123.4567.001-8',
            'validade' => $this->diaEmBrasilia(-1),
        ]));

        // O default `ativo` vem do banco, não do model: a criação não deriva
        // situação nenhuma da validade. Quem deriva é a rotina, e a
        // atualização da validade pelo observer.
        $this->assertSame(
            OrganRegistration::SITUACAO_ATIVO,
            $registro->fresh()->situacao,
            'a criação não deriva situação; quem deriva é a rotina e a atualização de validade'
        );

        $this->rodarRotina();

        $this->assertSame(
            OrganRegistration::SITUACAO_VENCIDO,
            $registro->fresh()->situacao
        );

        // Atualizar a validade traz de volta para ativo na hora, sem esperar a
        // próxima execução da rotina.
        $this->naEmpresa($this->empresaA, function () use ($registro): void {
            $registro->update(['validade' => $this->diaEmBrasilia(365)]);
        });

        $this->assertSame(OrganRegistration::SITUACAO_ATIVO, $registro->fresh()->situacao);
    }

    public function test_registro_cancelado_nunca_volta_a_ativo_sozinho(): void
    {
        $registro = $this->naEmpresa($this->empresaA, fn () => OrganRegistration::create([
            'record' => '3.0123.4567.002-6',
            'validade' => $this->diaEmBrasilia(365),
            'situacao' => OrganRegistration::SITUACAO_CANCELADO,
        ]));

        $this->rodarRotina();

        $this->assertSame(
            OrganRegistration::SITUACAO_CANCELADO,
            $registro->fresh()->situacao,
            'cancelamento é publicado pelo órgão e não pode ser desfeito por uma data futura'
        );
    }

    public function test_registro_sem_validade_informada_continua_ativo(): void
    {
        $registro = $this->naEmpresa($this->empresaA, fn () => OrganRegistration::create([
            'record' => '3.0123.4567.003-4',
        ]));

        $this->rodarRotina();

        $this->assertSame(OrganRegistration::SITUACAO_ATIVO, $registro->fresh()->situacao);

        $situacoes = $this->naEmpresa(
            $this->empresaA,
            fn () => collect($this->validades->registrosDeProduto())->pluck('situacao')->all()
        );

        $this->assertSame([ValidadeService::SITUACAO_NAO_INFORMADO], $situacoes);
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas e entre registros
    // -----------------------------------------------------------------

    public function test_a_rotina_grava_compliance_checks_por_empresa(): void
    {
        $this->empresaA->update(['licenca_sanitaria_validade' => $this->diaEmBrasilia(-1)]);

        $this->rodarRotina();

        $daA = $this->naEmpresa($this->empresaA, fn () => ComplianceCheck::query()->pluck('situacao', 'item'));
        $daB = $this->naEmpresa($this->empresaB, fn () => ComplianceCheck::query()->pluck('situacao', 'item'));

        $this->assertSame(ComplianceCheck::SITUACAO_IRREGULAR, $daA['licenca_sanitaria']);
        $this->assertSame(
            ComplianceCheck::SITUACAO_NAO_APLICAVEL,
            $daB['licenca_sanitaria'],
            'a empresa B não preencheu nada: não aplicável, e nunca irregular'
        );
    }

    public function test_empresa_sem_o_modulo_ligado_nao_recebe_aviso_nenhum(): void
    {
        $this->desligarModuloDeConformidade();

        $this->empresaA->update(['licenca_sanitaria_validade' => $this->diaEmBrasilia(-1)]);

        $this->rodarRotina();

        $this->assertCount(0, $this->itensDoEvento(EventosDeNotificacao::DOCUMENTO_REGULATORIO_VENCIDO));
        $this->assertCount(0, $this->itensDoEvento(EventosDeNotificacao::CADASTRO_REGULATORIO_INCOMPLETO));
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function rodarRotina(): void
    {
        Artisan::call('conformidade:verificar-validades');
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

    /**
     * @return Collection<int, NotificationQueue>
     */
    private function itensDoEvento(string $evento): Collection
    {
        return NotificationQueue::query()
            ->where('evento', $evento)
            ->orderBy('id')
            ->get();
    }

    /**
     * Liga o módulo `conformidade` nas duas empresas.
     *
     * O módulo nasce desligado de propósito (`CatalogoDeModulos`,
     * `sempre_ativo => false`), e é ele o interruptor da rotina. Sem ligar
     * aqui, todo teste de aviso passaria sem testar nada.
     */
    private function ligarModuloDeConformidade(): void
    {
        $modulo = Module::query()->firstOrCreate(
            ['chave' => 'conformidade'],
            ['nome' => 'Conformidade RDC 622/2022', 'descricao' => 'Teste', 'sempre_ativo' => false, 'ordem' => 99]
        );

        $modulo->update(['sempre_ativo' => true]);

        app(\App\Services\ModuleService::class)->esquecerCache();
    }

    private function desligarModuloDeConformidade(): void
    {
        Module::query()->where('chave', 'conformidade')->update(['sempre_ativo' => false]);

        app(\App\Services\ModuleService::class)->esquecerCache();
    }
}
