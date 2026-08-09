<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Module;
use App\Models\NotificationQueue;
use App\Models\PersonalProtectiveEquipment;
use App\Models\Service;
use App\Models\ServicePpeRequirement;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPpeConfirmation;
use App\Services\AppSyncService;
use App\Services\ModuleService;
use App\Services\Ppe\ConfirmacaoDeEpiService;
use App\Support\EventosDeNotificacao;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Closure;
use Database\Factories\PersonalProtectiveEquipmentFactory;
use Database\Factories\ServicePpeRequirementFactory;
use Database\Factories\WorkOrderFactory;
use Database\Factories\WorkOrderPpeConfirmationFactory;
use Database\Seeders\ModulesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 29.6 do Plano 29: a confirmação de EPI que sai do aparelho do técnico,
 * atravessa a fila offline e vira registro — sem duplicar, sem travar a
 * operação e sem levar para o campo o que o campo não precisa.
 *
 * O reenvio é a regra, não a exceção
 * ----------------------------------
 * O aplicativo reenvia o mesmo lote quando a conexão cai depois do envio e antes
 * da resposta, e isso acontece todo dia em quem trabalha em subsolo e em
 * galpão. São **duas portas** de reenvio, e as duas são testadas aqui porque uma
 * não cobre a outra:
 *
 * - mesmo `operacao_uuid` — o lote inteiro voltando, que `AppSyncService`
 *   reconhece;
 * - uuid novo sobre o mesmo par (OS, EPI) — o aparelho que perdeu a fila local,
 *   ou o técnico que corrigiu a resposta, que só a idempotência do
 *   `ConfirmacaoDeEpiService` reconhece.
 *
 * E o reenvio **preserva o `confirmado_em` original**: a hora do registro é a do
 * fato em campo, e deixá-la andar a cada tentativa de sincronização
 * transformaria a prova em uma hora de escritório.
 *
 * Nada aqui bloqueia a execução da OS
 * -----------------------------------
 * Decisão registrada do plano, e a que mais provavelmente será questionada:
 * `test_a_pendencia_de_epi_nao_impede_concluir_a_os` existe para que ela não seja
 * revertida por engano. A única recusa possível é sobre o conteúdo do próprio
 * registro — falta de EPI sem motivo declarado —, e ela vira **conflito de regra
 * de negócio na fila**, com tela onde resolver, nunca erro na cara do técnico.
 *
 * A carga do dia leva o mínimo
 * ----------------------------
 * `id`, `nome`, `tipo` e `obrigatorio`. CA, validade e fabricante não vão para o
 * aparelho: não mudam nada do que o técnico responde, e a carga do dia é
 * justamente onde o aplicativo fica pesado para quem está no campo com sinal
 * ruim.
 */
class ConfirmacaoDeEpiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Meio-dia de Brasília em UTC. O instante é fixado para que nenhuma
     * asserção sobre `confirmado_em` dependa do relógio da máquina.
     */
    private const AGORA = '2026-08-08 15:00:00';

    private Company $empresa;

    private User $usuarioDoTecnico;

    private Technician $tecnico;

    private Service $servico;

    private AppSyncService $sincronizacao;

    private ConfirmacaoDeEpiService $confirmacoes;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::AGORA, 'UTC'));
        TenantAtual::limpar();

        $this->seed(RolesAndPermissionsSeeder::class);
        // O módulo `epi` nasce desligado; o seeder liga o tenant fundador pelo
        // Plano Interno, que é o que a carga do dia consulta.
        $this->seed(ModulesSeeder::class);

        // Sem e-mail na empresa o aviso ao gestor cairia em "sem_destino" e
        // nenhuma linha nasceria na fila de notificação.
        Company::query()->whereKey(1)->update([
            'name' => 'Dedetizadora da confirmação',
            'email' => 'gestor@dedetizadora.test',
        ]);

        $this->empresa = Company::query()->findOrFail(1);
        $this->sincronizacao = app(AppSyncService::class);
        $this->confirmacoes = app(ConfirmacaoDeEpiService::class);

        [$this->usuarioDoTecnico, $this->tecnico] = $this->criarTecnico();

        $this->servico = $this->comTenant(fn (): Service => Service::create([
            'name' => 'Desinsetização',
            'category' => 'controle_de_pragas',
            'price' => 300,
            'is_active' => true,
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Justificativa obrigatória para a falta
    // -----------------------------------------------------------------

    /**
     * Uma falta de EPI sem motivo declarado é um registro que não serve para
     * nada depois — nem para o gestor, nem para a fiscalização.
     */
    public function test_confirmado_falso_sem_justificativa_e_recusado(): void
    {
        $epi = $this->exigir($this->criarEpi());
        $os = $this->criarOrdem();

        try {
            $this->comTenant(fn () => $this->confirmacoes->registrar($os, [
                ['personal_protective_equipment_id' => $epi->getKey(), 'confirmado' => false],
            ]));

            $this->fail('a falta de EPI sem justificativa deveria ter sido recusada');
        } catch (ValidationException $erro) {
            $this->assertArrayHasKey('justificativa', $erro->errors());
            $this->assertStringContainsString('motivo', $erro->errors()['justificativa'][0]);
        }

        $this->assertSame(0, $this->contarConfirmacoes($os), 'a recusa não pode ter gravado meia linha');
    }

    /**
     * A mesma recusa, pela porta por onde ela realmente chega. O técnico está em
     * campo, sem tela de formulário: o erro precisa virar **conflito de regra de
     * negócio**, com a mensagem em português e o valor guardado, tratado pelo
     * fluxo de resolução que o Plano 12 já tem. Exceção crua ali seria uma falha
     * de sincronização que o técnico não consegue resolver.
     */
    public function test_a_falta_sem_justificativa_vira_conflito_de_regra_de_negocio_na_fila(): void
    {
        $epi = $this->exigir($this->criarEpi());
        $os = $this->criarOrdem();

        $resultado = $this->sincronizar($this->operacaoDeEpi($os, [
            ['personal_protective_equipment_id' => $epi->getKey(), 'confirmado' => false],
        ]));

        $this->assertFalse($resultado->foiAplicada());
        $this->assertTrue($resultado->estaEmConflito(), 'a recusa precisa virar conflito, e não erro na cara do técnico');
        $this->assertSame('regra_de_negocio', $resultado->conflito->motivo);
        $this->assertStringContainsString('motivo', mb_strtolower((string) $resultado->mensagem));
        $this->assertSame(0, $this->contarConfirmacoes($os));
    }

    public function test_a_falta_com_justificativa_e_gravada_com_o_motivo(): void
    {
        $epi = $this->exigir($this->criarEpi());
        $os = $this->criarOrdem();

        $resultado = $this->sincronizar($this->operacaoDeEpi($os, [
            [
                'personal_protective_equipment_id' => $epi->getKey(),
                'confirmado' => false,
                'justificativa' => 'Respirador com a troca vencida, sem reposição na base.',
            ],
        ]));

        $this->assertTrue($resultado->foiAplicada(), (string) $resultado->mensagem);

        $linha = $this->confirmacaoDe($os, $epi);

        $this->assertFalse($linha->confirmado);
        $this->assertSame('Respirador com a troca vencida, sem reposição na base.', $linha->justificativa);
        $this->assertSame(
            (int) $this->usuarioDoTecnico->getKey(),
            (int) $linha->user_id,
            'quem confirmou é quem estava com o aparelho'
        );
    }

    // -----------------------------------------------------------------
    // Idempotência: as duas portas do reenvio
    // -----------------------------------------------------------------

    /**
     * Porta 1: o lote inteiro voltando com o mesmo `operacao_uuid`, porque a
     * resposta se perdeu na rede.
     */
    public function test_reenviar_o_mesmo_operacao_uuid_nao_duplica_a_linha(): void
    {
        $epi = $this->exigir($this->criarEpi());
        $os = $this->criarOrdem();

        $operacao = $this->operacaoDeEpi($os, [
            ['personal_protective_equipment_id' => $epi->getKey(), 'confirmado' => true],
        ]);

        $primeira = $this->sincronizar($operacao);
        $segunda = $this->sincronizar($operacao);

        $this->assertTrue($primeira->foiAplicada(), (string) $primeira->mensagem);
        $this->assertTrue($segunda->foiAplicada(), 'o reenvio não pode voltar como erro para o aparelho');
        $this->assertSame($primeira->operacao->id, $segunda->operacao->id);

        $this->assertSame(1, $this->contarConfirmacoes($os), 'o reenvio do mesmo lote não pode virar uma segunda linha');
    }

    /**
     * Porta 2, a que a primeira não cobre: uuid novo sobre o mesmo par (OS, EPI).
     * É o aparelho que perdeu a fila local e o técnico que corrigiu a resposta.
     * Sem esta camada, o segundo envio estouraria a unique composta da Task 29.1
     * e viraria conflito por um registro que o servidor já tem.
     */
    public function test_uuid_novo_sobre_o_mesmo_par_atualiza_a_linha_em_vez_de_duplicar(): void
    {
        $epi = $this->exigir($this->criarEpi());
        $os = $this->criarOrdem();

        $negativa = $this->sincronizar($this->operacaoDeEpi($os, [
            [
                'personal_protective_equipment_id' => $epi->getKey(),
                'confirmado' => false,
                'justificativa' => 'Esqueci o respirador na base.',
            ],
        ]));

        $correcao = $this->sincronizar($this->operacaoDeEpi($os, [
            ['personal_protective_equipment_id' => $epi->getKey(), 'confirmado' => true],
        ]));

        $this->assertTrue($negativa->foiAplicada(), (string) $negativa->mensagem);
        $this->assertTrue($correcao->foiAplicada(), (string) $correcao->mensagem);
        $this->assertNotSame($negativa->operacao->id, $correcao->operacao->id, 'são duas operações distintas na fila');

        $this->assertSame(1, $this->contarConfirmacoes($os), 'o mesmo par (OS, EPI) tem uma linha só');

        $linha = $this->confirmacaoDe($os, $epi);

        $this->assertTrue($linha->confirmado);
        $this->assertNull(
            $linha->justificativa,
            'a justificativa da negativa não pode ficar colada numa confirmação corrigida: o registro vai à fiscalização'
        );
    }

    /**
     * A hora do registro é a do fato em campo, e o campo pode ter acontecido
     * horas antes, sem sinal. Sobrescrevê-la com o instante da sincronização
     * faria a prova andar a cada tentativa de reenvio.
     */
    public function test_o_reenvio_preserva_o_confirmado_em_original(): void
    {
        $epi = $this->exigir($this->criarEpi());
        $os = $this->criarOrdem();

        $noCampo = Carbon::parse('2026-08-08T07:12:00-03:00');

        $this->sincronizar($this->operacaoDeEpi(
            $os,
            [['personal_protective_equipment_id' => $epi->getKey(), 'confirmado' => true]],
            $noCampo
        ));

        $original = $this->confirmacaoDe($os, $epi)->confirmado_em;

        $this->assertTrue(
            $noCampo->equalTo($original),
            'o instante gravado é o do aparelho, convertido para UTC, e não a hora em que a sincronização chegou'
        );

        // Três horas depois o aparelho recupera o sinal e reenvia, agora sem
        // mandar instante nenhum no item.
        Carbon::setTestNow(Carbon::parse(self::AGORA, 'UTC')->addHours(3));

        $this->sincronizar($this->operacaoDeEpi(
            $os,
            [['personal_protective_equipment_id' => $epi->getKey(), 'confirmado' => true]],
            $noCampo
        ));

        $this->assertSame(1, $this->contarConfirmacoes($os));
        $this->assertTrue(
            $noCampo->equalTo($this->confirmacaoDe($os, $epi)->confirmado_em),
            'o reenvio não pode fazer a hora do fato andar'
        );
    }

    // -----------------------------------------------------------------
    // Nada bloqueia a execução da OS
    // -----------------------------------------------------------------

    /**
     * A decisão do plano que mais provavelmente será questionada, e por isso ela
     * tem teste próprio: pendência de EPI é problema de escritório, e travar o
     * técnico em campo por causa dela tira a operação do ar. A OS conclui com o
     * EPI obrigatório declarado em falta, e conclui também sem confirmação
     * nenhuma.
     */
    public function test_a_pendencia_de_epi_nao_impede_concluir_a_os(): void
    {
        $emFalta = $this->exigir($this->criarEpi(['nome' => 'Respirador em falta']));
        $semResposta = $this->exigir($this->criarEpi(['nome' => 'Luva sem resposta']));

        $os = $this->criarOrdem(['status' => 'in_progress']);

        $this->sincronizar($this->operacaoDeEpi($os, [
            [
                'personal_protective_equipment_id' => $emFalta->getKey(),
                'confirmado' => false,
                'justificativa' => 'Sem reposição na base.',
            ],
        ]));

        $conclusao = $this->sincronizar($this->operacao($os, 'execucao', ['acao' => 'concluir']));

        $this->assertTrue(
            $conclusao->foiAplicada(),
            'EPI pendente não pode impedir a conclusão da OS: '.(string) $conclusao->mensagem
        );
        $this->assertSame('completed', $os->fresh()->status);

        $this->assertSame(
            0,
            $this->comTenant(fn (): int => WorkOrderPpeConfirmation::query()
                ->where('work_order_id', $os->getKey())
                ->where('personal_protective_equipment_id', $semResposta->getKey())
                ->count()),
            'EPI exigido que não veio na lista não gera linha: "não informado" nunca é "não usou"'
        );
    }

    // -----------------------------------------------------------------
    // Aviso ao gestor: evento, não vencimento
    // -----------------------------------------------------------------

    /**
     * O aviso é evento, e sai **uma vez por OS**. A garantia é a chave de
     * idempotência do `NotificationService` (evento + destinatário + referência,
     * sem marco): reenviar a sincronização não produz um segundo e-mail, e a
     * rotina diária não transforma a pendência em ruído semanal.
     */
    public function test_o_aviso_ao_gestor_sai_uma_vez_por_os(): void
    {
        $epi = $this->exigir($this->criarEpi(['nome' => 'Respirador semifacial 3M']));
        $os = $this->criarOrdem();

        $confirmacoes = [[
            'personal_protective_equipment_id' => $epi->getKey(),
            'confirmado' => false,
            'justificativa' => 'Sem reposição na base.',
        ]];

        $this->sincronizar($this->operacaoDeEpi($os, $confirmacoes));
        $this->sincronizar($this->operacaoDeEpi($os, $confirmacoes));

        // E a terceira chamada, direta, como faria uma rotina de escritório.
        $this->comTenant(fn () => $this->confirmacoes->avisarExecucaoSemEpi($os->fresh()));

        $avisos = $this->avisosDeExecucaoSemEpi();

        $this->assertCount(1, $avisos, 'o aviso de execução sem EPI sai uma vez por OS, não a cada rotina');
        $this->assertStringContainsString('Respirador semifacial 3M', (string) $avisos->first()->corpo);
        $this->assertStringContainsString('Sem reposição na base.', (string) $avisos->first()->corpo);
    }

    /**
     * Só o EPI **obrigatório naquele serviço** vira aviso. Transformar
     * recomendação em alarme faria o gestor desligar o evento inteiro, e junto
     * com ele o aviso que importa.
     */
    public function test_epi_apenas_recomendado_em_falta_nao_avisa_o_gestor(): void
    {
        $recomendado = $this->exigir($this->criarEpi(['nome' => 'Boné recomendado']), obrigatorio: false);
        $os = $this->criarOrdem();

        $resultado = $this->sincronizar($this->operacaoDeEpi($os, [
            [
                'personal_protective_equipment_id' => $recomendado->getKey(),
                'confirmado' => false,
                'justificativa' => 'Não usei o boné.',
            ],
        ]));

        $this->assertTrue($resultado->foiAplicada(), (string) $resultado->mensagem);
        $this->assertSame(1, $this->contarConfirmacoes($os), 'a recusa do recomendado continua no registro');
        $this->assertCount(0, $this->avisosDeExecucaoSemEpi(), 'recomendação que não virou uso não é alarme');
    }

    /**
     * A confirmação positiva não avisa ninguém. Óbvio, e é o caso que impede o
     * teste acima de passar por acidente.
     */
    public function test_o_uso_confirmado_nao_avisa_o_gestor(): void
    {
        $epi = $this->exigir($this->criarEpi());
        $os = $this->criarOrdem();

        $this->sincronizar($this->operacaoDeEpi($os, [
            ['personal_protective_equipment_id' => $epi->getKey(), 'confirmado' => true],
        ]));

        $this->assertCount(0, $this->avisosDeExecucaoSemEpi());
    }

    // -----------------------------------------------------------------
    // O que a lista aceita e o que ela ignora
    // -----------------------------------------------------------------

    /**
     * O aparelho trabalha com a carga do dia, que pode ter sido baixada antes de
     * alguém mexer na exigência no escritório. Recusar faria a operação da fila
     * falhar em campo, sem tela onde corrigir — e por isso o EPI que a OS não
     * exige é descartado em silêncio, não recusado.
     */
    public function test_epi_que_a_os_nao_exige_e_ignorado_em_silencio(): void
    {
        $exigido = $this->exigir($this->criarEpi(['nome' => 'Respirador exigido']));
        $solto = $this->criarEpi(['nome' => 'Óculos que ninguém exige']);

        $os = $this->criarOrdem();

        $resultado = $this->sincronizar($this->operacaoDeEpi($os, [
            ['personal_protective_equipment_id' => $exigido->getKey(), 'confirmado' => true],
            ['personal_protective_equipment_id' => $solto->getKey(), 'confirmado' => true],
        ]));

        $this->assertTrue($resultado->foiAplicada(), 'o item descartado não pode derrubar o lote: '.(string) $resultado->mensagem);
        $this->assertSame(1, $this->contarConfirmacoes($os));
        $this->assertNotNull($this->confirmacaoDe($os, $exigido));
    }

    /**
     * A exceção do caso acima, e ela existe para não quebrar o reenvio: o EPI
     * que já tem linha nesta OS continua aceitando atualização mesmo depois de a
     * exigência sair do cadastro. O que o técnico confirmou em campo aconteceu, e
     * a exigência removida no escritório não pode transformar um reenvio legítimo
     * em item descartado.
     */
    public function test_exigencia_removida_depois_nao_impede_o_reenvio_da_confirmacao(): void
    {
        $epi = $this->criarEpi();
        $exigencia = $this->exigirComRegistro($epi);
        $os = $this->criarOrdem();

        $this->sincronizar($this->operacaoDeEpi($os, [
            [
                'personal_protective_equipment_id' => $epi->getKey(),
                'confirmado' => false,
                'justificativa' => 'Sem reposição na base.',
            ],
        ]));

        $this->comTenant(fn () => $exigencia->delete());

        $reenvio = $this->sincronizar($this->operacaoDeEpi($os, [
            ['personal_protective_equipment_id' => $epi->getKey(), 'confirmado' => true],
        ]));

        $this->assertTrue($reenvio->foiAplicada(), (string) $reenvio->mensagem);
        $this->assertSame(1, $this->contarConfirmacoes($os));
        $this->assertTrue($this->confirmacaoDe($os, $epi)->confirmado);
    }

    // -----------------------------------------------------------------
    // A carga do dia leva o mínimo
    // -----------------------------------------------------------------

    /**
     * A etapa precisa de quatro campos, e só quatro: `id`, `nome` e `tipo` do
     * EPI, mais a força da exigência. CA, validade e fabricante ficam no
     * escritório — a carga do dia é o ponto onde o aplicativo fica pesado, e quem
     * sofre é quem está no campo com sinal ruim.
     *
     * Este teste falha se alguém acrescentar campo à etapa, de propósito: crescer
     * a carga é uma decisão, não um efeito colateral.
     */
    public function test_a_carga_do_dia_leva_apenas_id_nome_tipo_e_obrigatorio_do_epi(): void
    {
        $epi = $this->exigir($this->criarEpi([
            'nome' => 'Respirador semifacial',
            'tipo' => 'respirador',
            'fabricante' => 'Fabricante Secreto',
            'numero_ca' => 'CA-99887',
        ]));

        $os = $this->criarOrdem(['scheduled_date' => '2026-08-08']);

        $resposta = $this->comToken()->getJson('/api/app/carga');
        $resposta->assertOk();

        $ordem = collect($resposta->json('ordens'))->firstWhere('id', $os->getKey());

        $this->assertNotNull($ordem, 'a OS do dia deveria estar na carga');
        $this->assertSame([[
            'id' => (int) $epi->getKey(),
            'nome' => 'Respirador semifacial',
            'tipo' => 'respirador',
            'obrigatorio' => true,
        ]], $ordem['epis_exigidos']);

        $corpo = $resposta->getContent();

        foreach (['CA-99887', 'Fabricante Secreto', 'validade_ca', 'numero_ca', 'vida_util_dias'] as $vazamento) {
            $this->assertStringNotContainsString(
                $vazamento,
                $corpo,
                "a carga do dia não pode levar {$vazamento} para o aparelho do técnico"
            );
        }
    }

    /**
     * Módulo desligado é lista vazia, e nunca a etapa aparecendo com dado. É o
     * estado do Deploy 1 do plano, com o módulo ainda desligado em todo mundo.
     */
    public function test_sem_o_modulo_de_epi_a_carga_do_dia_nao_leva_a_etapa(): void
    {
        $this->exigir($this->criarEpi());
        $os = $this->criarOrdem(['scheduled_date' => '2026-08-08']);

        $modulo = Module::query()->where('chave', 'epi')->firstOrFail();
        app(ModuleService::class)->bloquearPara($this->empresa, $modulo, 'Desligado no teste.');

        $resposta = $this->comToken()->getJson('/api/app/carga');
        $resposta->assertOk();

        $ordem = collect($resposta->json('ordens'))->firstWhere('id', $os->getKey());

        $this->assertSame([], $ordem['epis_exigidos']);
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas
    // -----------------------------------------------------------------

    /**
     * Registrar confirmação de EPI numa OS de outra empresa é a pior falha
     * possível deste sistema. Dentro da requisição o escopo global já impede
     * alcançar a OS; esta é a segunda tranca, para os contextos em que o tenant
     * é informado e não inferido.
     */
    public function test_confirmacao_em_os_de_outra_empresa_falha_alto(): void
    {
        $concorrente = Company::create(['name' => 'Concorrente da confirmação']);

        $osDoConcorrente = TenantAtual::comTenant(
            (int) $concorrente->getKey(),
            fn (): WorkOrder => WorkOrderFactory::new()->create()
        );

        $epi = $this->exigir($this->criarEpi());

        $this->expectException(RuntimeException::class);

        $this->comTenant(fn () => $this->confirmacoes->registrar($osDoConcorrente, [
            ['personal_protective_equipment_id' => $epi->getKey(), 'confirmado' => true],
        ]));
    }

    /**
     * Linha herdada da fábrica: a confirmação da outra empresa existe no banco e
     * continua invisível daqui, mesmo consultando pela tabela.
     */
    public function test_confirmacao_de_outra_empresa_nao_aparece_na_consulta_deste_tenant(): void
    {
        $concorrente = Company::create(['name' => 'Concorrente do registro']);

        TenantAtual::comTenant((int) $concorrente->getKey(), function (): void {
            $epi = PersonalProtectiveEquipmentFactory::new()->create(['nome' => 'Respirador da concorrente']);
            $os = WorkOrderFactory::new()->create();

            WorkOrderPpeConfirmationFactory::new()->naOrdem($os, $epi)->create();
        });

        $this->assertSame(
            0,
            $this->comTenant(fn (): int => WorkOrderPpeConfirmation::query()->count()),
            'a confirmação da concorrente não pode ser contada por este tenant'
        );
        $this->assertSame(
            1,
            TenantAtual::comTenant(
                (int) $concorrente->getKey(),
                fn (): int => WorkOrderPpeConfirmation::query()->count()
            )
        );
    }

    // -----------------------------------------------------------------
    // Apoio: cenário
    // -----------------------------------------------------------------

    /**
     * @return array{0: User, 1: Technician}
     */
    private function criarTecnico(): array
    {
        return $this->comTenant(function (): array {
            $usuario = User::factory()->create([
                'name' => 'Técnico da confirmação',
                'is_active' => true,
            ]);
            $usuario->assignRole('tecnico');

            $tecnico = Technician::create([
                'name' => 'Cadastro do técnico da confirmação',
                'email' => 'tecnico-confirmacao-'.uniqid().'@dedetizadora.test',
                'phone' => '11999990000',
                'is_active' => true,
                'user_id' => $usuario->getKey(),
            ]);

            return [$usuario->fresh(), $tecnico];
        });
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
     * O serviço do cenário passa a exigir o EPI.
     */
    private function exigir(PersonalProtectiveEquipment $epi, bool $obrigatorio = true): PersonalProtectiveEquipment
    {
        $this->exigirComRegistro($epi, $obrigatorio);

        return $epi;
    }

    private function exigirComRegistro(
        PersonalProtectiveEquipment $epi,
        bool $obrigatorio = true
    ): ServicePpeRequirement {
        return $this->comTenant(function () use ($epi, $obrigatorio): ServicePpeRequirement {
            $fabrica = ServicePpeRequirementFactory::new()->exigidoPor($this->servico, $epi);

            return ($obrigatorio ? $fabrica : $fabrica->recomendado())->create();
        });
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function criarOrdem(array $atributos = []): WorkOrder
    {
        return $this->comTenant(function () use ($atributos): WorkOrder {
            $ordem = WorkOrderFactory::new()->create(array_merge([
                'technician_id' => $this->tecnico->getKey(),
                'scheduled_date' => '2026-08-08',
                'status' => 'in_progress',
            ], $atributos));

            $ordem->services()->attach($this->servico->getKey());

            return $ordem->fresh();
        });
    }

    // -----------------------------------------------------------------
    // Apoio: fila offline
    // -----------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $confirmacoes
     * @return array<string, mixed>
     */
    private function operacaoDeEpi(WorkOrder $os, array $confirmacoes, ?Carbon $registradaEm = null): array
    {
        return $this->operacao($os, 'confirmacao_epi', ['confirmacoes' => $confirmacoes], $registradaEm);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function operacao(
        WorkOrder $os,
        string $tipo,
        array $payload,
        ?Carbon $registradaEm = null
    ): array {
        return [
            'uuid' => (string) Str::uuid(),
            'tipo' => $tipo,
            'work_order_id' => $os->getKey(),
            'registrada_em' => ($registradaEm ?? now())->toJSON(),
            'updated_at_conhecido' => null,
            'payload' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $operacao
     */
    private function sincronizar(array $operacao): mixed
    {
        return $this->sincronizacao->aplicar($operacao, $this->usuarioDoTecnico->fresh());
    }

    /**
     * O aplicativo do técnico entra por token do Sanctum, como em produção: é a
     * requisição que resolve o tenant, e é ela que decide se a etapa de EPI
     * entra na carga.
     */
    private function comToken(): self
    {
        return $this->withToken(
            $this->usuarioDoTecnico->fresh()->createToken('Aparelho do teste')->plainTextToken
        );
    }

    // -----------------------------------------------------------------
    // Apoio: leitura
    // -----------------------------------------------------------------

    private function contarConfirmacoes(WorkOrder $os): int
    {
        return $this->comTenant(fn (): int => WorkOrderPpeConfirmation::query()
            ->where('work_order_id', $os->getKey())
            ->count());
    }

    private function confirmacaoDe(WorkOrder $os, PersonalProtectiveEquipment $epi): WorkOrderPpeConfirmation
    {
        $linha = $this->comTenant(fn (): ?WorkOrderPpeConfirmation => WorkOrderPpeConfirmation::query()
            ->where('work_order_id', $os->getKey())
            ->where('personal_protective_equipment_id', $epi->getKey())
            ->first());

        $this->assertNotNull($linha, "a confirmação do EPI {$epi->nome} deveria ter sido gravada");

        return $linha;
    }

    /**
     * @return \Illuminate\Support\Collection<int, NotificationQueue>
     */
    private function avisosDeExecucaoSemEpi(): \Illuminate\Support\Collection
    {
        return $this->comTenant(fn () => NotificationQueue::query()
            ->where('evento', EventosDeNotificacao::EXECUCAO_SEM_EPI_EXIGIDO)
            ->orderBy('id')
            ->get());
    }

    private function comTenant(Closure $callback): mixed
    {
        return TenantAtual::comTenant((int) $this->empresa->getKey(), $callback);
    }
}
