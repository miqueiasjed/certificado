<?php

namespace Tests\Feature;

use App\Exceptions\CaVencidoException;
use App\Exceptions\EntregaDeEpiEstornadaException;
use App\Exceptions\EpiComEntregaException;
use App\Models\Company;
use App\Models\PersonalProtectiveEquipment;
use App\Models\PpeDelivery;
use App\Models\Technician;
use App\Services\Ppe\PpeDeliveryService;
use App\Services\Ppe\PpeService;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\PersonalProtectiveEquipmentFactory;
use Database\Factories\PpeDeliveryFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Task 28.7 do Plano 28: as regras da entrega de EPI, provadas pelo Service.
 *
 * O teste central do plano está no primeiro bloco: **o CA é cópia, não
 * relação**. Renovar o certificado no cadastro não pode reescrever a ficha do
 * ano passado, pela mesma razão pela qual documento emitido não se recalcula.
 * Se alguém trocar as colunas `numero_ca`/`validade_ca` de `ppe_deliveries` por
 * uma leitura de `personalProtectiveEquipment`, é aqui que a suíte cai.
 *
 * Os demais blocos, na ordem da especificação da task:
 *
 * - as duas validades são independentes (`validade_ca` do cadastro contra
 *   `trocar_ate` da entrega): mexer em uma nunca move a outra;
 * - vida útil ausente produz `trocar_ate` **nulo**, e não um prazo inventado;
 * - CA vencido recusa a entrega, com o caminho de correção na mensagem;
 * - virada de dia: às 21h30 de Brasília o CA que vence hoje ainda vale. Este
 *   bloco falha no dia em que `BusinessDate` virar `now()`;
 * - a entrega é imutável: estorno preserva a linha e bloqueia tudo depois dele;
 * - devolução não é estorno;
 * - técnico e EPI de empresas diferentes nunca entram na mesma ficha.
 *
 * O tempo é sempre fixado com `Carbon::setTestNow()`: nenhuma afirmação aqui
 * pode depender do relógio nem do fuso da máquina que roda a suíte.
 */
class PpeDeliveryServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Meio-dia em UTC de um dia útil qualquer: 09h de Brasília, longe das duas
     * viradas de dia. O bloco de virada de dia usa o próprio instante.
     */
    private const AGORA = '2026-07-15 12:00:00';

    private Company $empresa;

    private PpeDeliveryService $entregas;

    private PpeService $cadastro;

    private Technician $tecnico;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::AGORA);
        TenantAtual::limpar();

        $this->empresa = Company::query()->findOrFail(1);
        $this->entregas = app(PpeDeliveryService::class);
        $this->cadastro = app(PpeService::class);

        $this->tecnico = $this->criarTecnico();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // O CA é cópia, não relação — o critério central do plano
    // -----------------------------------------------------------------

    public function test_a_entrega_copia_o_numero_e_a_validade_do_ca_do_cadastro(): void
    {
        $epi = $this->criarEpi([
            'numero_ca' => 'CA-11111',
            'validade_ca' => '2026-12-31',
        ]);

        $entrega = $this->registrar($epi);

        $this->assertSame('CA-11111', $entrega->numero_ca);
        $this->assertSame('2026-12-31', BusinessDate::diaDe($entrega->validade_ca));
    }

    /**
     * A afirmação que sustenta o plano inteiro: a ficha diz o que foi entregue
     * naquele dia, e continua dizendo depois de o cadastro ser renovado.
     *
     * Este teste falha no instante em que o CA da entrega passar a ser lido
     * pela relação `personalProtectiveEquipment`.
     */
    public function test_renovar_o_ca_no_cadastro_nao_reescreve_a_entrega_ja_registrada(): void
    {
        $epi = $this->criarEpi([
            'numero_ca' => 'CA-ANTIGO',
            'validade_ca' => '2026-09-30',
        ]);

        $entrega = $this->registrar($epi);

        $this->naEmpresa(fn () => $this->cadastro->atualizar($epi, [
            'numero_ca' => 'CA-RENOVADO',
            'validade_ca' => '2030-09-30',
        ]));

        $entrega->refresh();

        $this->assertSame('CA-ANTIGO', $entrega->numero_ca, 'o CA da entrega mudou junto com o cadastro');
        $this->assertSame('2026-09-30', BusinessDate::diaDe($entrega->validade_ca));

        // Contraprova: o cadastro mudou de verdade, ou seja, o teste não passou
        // porque a renovação não aconteceu.
        $this->assertSame('CA-RENOVADO', $epi->fresh()->numero_ca);
    }

    /**
     * A entrega feita **depois** da renovação nasce com o CA novo. É o outro
     * lado da mesma regra: a cópia é do dia da entrega, não um congelamento
     * eterno do primeiro valor cadastrado.
     */
    public function test_a_entrega_seguinte_nasce_com_o_ca_renovado(): void
    {
        $epi = $this->criarEpi(['numero_ca' => 'CA-ANTIGO', 'validade_ca' => '2026-09-30']);

        $antiga = $this->registrar($epi);

        $this->naEmpresa(fn () => $this->cadastro->atualizar($epi, [
            'numero_ca' => 'CA-RENOVADO',
            'validade_ca' => '2030-09-30',
        ]));

        $nova = $this->registrar($epi->fresh(), ['motivo' => 'substituicao']);

        $this->assertSame('CA-ANTIGO', $antiga->refresh()->numero_ca);
        $this->assertSame('CA-RENOVADO', $nova->numero_ca);
    }

    // -----------------------------------------------------------------
    // As duas validades são independentes
    // -----------------------------------------------------------------

    public function test_renovar_a_validade_do_ca_nao_adia_a_troca_do_item_entregue(): void
    {
        $epi = $this->criarEpi(['validade_ca' => '2026-09-30', 'vida_util_dias' => 30]);

        $entrega = $this->registrar($epi);

        $this->assertSame('2026-08-14', BusinessDate::diaDe($entrega->trocar_ate));

        $this->naEmpresa(fn () => $this->cadastro->atualizar($epi, ['validade_ca' => '2030-09-30']));

        $this->assertSame(
            '2026-08-14',
            BusinessDate::diaDe($entrega->refresh()->trocar_ate),
            'renovar o certificado do fabricante não pode adiar a troca do item na mão do técnico'
        );
    }

    public function test_alterar_a_vida_util_do_cadastro_nao_move_a_troca_ja_calculada(): void
    {
        $epi = $this->criarEpi(['vida_util_dias' => 30]);

        $entrega = $this->registrar($epi);

        $this->naEmpresa(fn () => $this->cadastro->atualizar($epi, ['vida_util_dias' => 365]));

        $this->assertSame(
            '2026-08-14',
            BusinessDate::diaDe($entrega->refresh()->trocar_ate),
            'a troca é calculada no ato da entrega e não se recalcula depois'
        );
    }

    public function test_registrar_entrega_nao_altera_a_validade_do_ca_do_cadastro(): void
    {
        $epi = $this->criarEpi(['validade_ca' => '2026-12-31', 'vida_util_dias' => 30]);

        $this->registrar($epi);

        $this->assertSame('2026-12-31', BusinessDate::diaDe($epi->fresh()->validade_ca));
    }

    // -----------------------------------------------------------------
    // Cálculo da troca programada
    // -----------------------------------------------------------------

    public function test_trocar_ate_e_o_dia_da_entrega_mais_a_vida_util(): void
    {
        $epi = $this->criarEpi(['vida_util_dias' => 180]);

        $entrega = $this->registrar($epi, ['entregue_em' => '2026-01-10']);

        $this->assertSame('2026-07-09', BusinessDate::diaDe($entrega->trocar_ate));
    }

    /**
     * "Sem troca programada" é um estado legítimo. Prazo padrão inventado vira
     * alerta falso, e alerta falso faz o usuário desligar o alerta inteiro.
     */
    public function test_sem_vida_util_cadastrada_a_troca_fica_nula(): void
    {
        $epi = $this->criarEpi(['vida_util_dias' => null]);

        $this->assertNull($this->registrar($epi)->trocar_ate);
    }

    /**
     * Ausente é diferente de zero: zero é escolha do cadastro e produz troca no
     * próprio dia da entrega.
     */
    public function test_vida_util_zero_produz_troca_no_proprio_dia_da_entrega(): void
    {
        $epi = $this->criarEpi(['vida_util_dias' => 0]);

        $entrega = $this->registrar($epi, ['entregue_em' => '2026-07-10']);

        $this->assertSame('2026-07-10', BusinessDate::diaDe($entrega->trocar_ate));
    }

    // -----------------------------------------------------------------
    // Recusa: CA vencido, EPI inativo, data futura
    // -----------------------------------------------------------------

    public function test_epi_com_ca_vencido_e_recusado_com_o_caminho_de_correcao_na_mensagem(): void
    {
        $epi = $this->criarEpi(['numero_ca' => 'CA-98765', 'validade_ca' => '2026-07-14']);

        try {
            $this->registrar($epi);
            $this->fail('a entrega com CA vencido precisava ser recusada');
        } catch (CaVencidoException $recusa) {
            $this->assertStringContainsString('CA-98765', $recusa->getMessage());
            $this->assertStringContainsString('14/07/2026', $recusa->getMessage());
            $this->assertStringContainsString('Cadastros > EPI', $recusa->getMessage());
            $this->assertSame(1, $recusa->diasVencido());
        }

        $this->assertSame(0, $this->contarEntregas(), 'nada podia ter sido gravado');
    }

    public function test_ca_que_vence_hoje_ainda_permite_a_entrega(): void
    {
        $epi = $this->criarEpi(['validade_ca' => '2026-07-15']);

        $this->assertNotNull($this->registrar($epi)->getKey());
    }

    /**
     * Campo em branco é estado neutro, nunca irregularidade — mesma regra do
     * checklist do Plano 24. EPI sem certificado cadastrado é entregue e fica
     * fora da varredura de vencimento.
     */
    public function test_epi_sem_validade_de_ca_pode_ser_entregue(): void
    {
        $epi = $this->criarEpi(['numero_ca' => null, 'validade_ca' => null]);

        $entrega = $this->registrar($epi);

        $this->assertNull($entrega->numero_ca);
        $this->assertNull($entrega->validade_ca);
    }

    public function test_epi_inativo_e_recusado(): void
    {
        $epi = $this->criarEpi(['nome' => 'Luva aposentada', 'ativo' => false]);

        try {
            $this->registrar($epi);
            $this->fail('EPI inativo não podia ser entregue');
        } catch (ValidationException $recusa) {
            $this->assertArrayHasKey('personal_protective_equipment_id', $recusa->errors());
            $this->assertStringContainsString(
                'Luva aposentada',
                $recusa->errors()['personal_protective_equipment_id'][0]
            );
        }
    }

    public function test_data_de_entrega_futura_e_recusada(): void
    {
        $epi = $this->criarEpi();

        $this->expectException(ValidationException::class);

        $this->registrar($epi, ['entregue_em' => '2026-07-16']);
    }

    public function test_quantidade_menor_que_um_e_recusada(): void
    {
        $epi = $this->criarEpi();

        $this->expectException(ValidationException::class);

        $this->registrar($epi, ['quantidade' => 0]);
    }

    public function test_motivo_fora_do_catalogo_e_recusado(): void
    {
        $epi = $this->criarEpi();

        $this->expectException(ValidationException::class);

        $this->registrar($epi, ['motivo' => 'porque_sim']);
    }

    public function test_sem_tecnico_ou_sem_epi_a_entrega_e_recusada(): void
    {
        $epi = $this->criarEpi();

        try {
            $this->naEmpresa(fn () => $this->entregas->registrar([
                'personal_protective_equipment_id' => $epi->getKey(),
            ]));
            $this->fail('entrega sem técnico precisava ser recusada');
        } catch (ValidationException $recusa) {
            $this->assertArrayHasKey('technician_id', $recusa->errors());
        }

        try {
            $this->naEmpresa(fn () => $this->entregas->registrar([
                'technician_id' => $this->tecnico->getKey(),
            ]));
            $this->fail('entrega sem EPI precisava ser recusada');
        } catch (ValidationException $recusa) {
            $this->assertArrayHasKey('personal_protective_equipment_id', $recusa->errors());
        }
    }

    // -----------------------------------------------------------------
    // Virada de dia: 21h30 de Brasília ainda é o dia anterior
    // -----------------------------------------------------------------

    /**
     * Com o relógio em 26/07/2026 00h30 **UTC**, em Brasília são 21h30 do dia
     * 25. Um CA com validade 25/07/2026 vence hoje, e o que vence hoje ainda
     * vale hoje: a entrega é aceita e nasce com `entregue_em` no dia 25.
     *
     * Este teste falha no dia em que alguém trocar `BusinessDate` por `now()`:
     * em UTC já seria dia 26, e o certificado apareceria como vencido.
     */
    public function test_as_2130_de_brasilia_o_ca_que_vence_hoje_ainda_vale_e_a_entrega_e_do_dia_certo(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-26 00:30:00', 'UTC'));

        $epi = $this->criarEpi(['validade_ca' => '2026-07-25', 'vida_util_dias' => 30]);

        $entrega = $this->registrar($epi);

        $this->assertSame('2026-07-25', BusinessDate::diaDe($entrega->entregue_em));
        $this->assertSame('2026-07-25', BusinessDate::diaDe($entrega->validade_ca));
        $this->assertSame('2026-08-24', BusinessDate::diaDe($entrega->trocar_ate));
    }

    /**
     * Contraprova do mesmo instante: o certificado que venceu no dia 24
     * continua vencido, ou seja, a regra do dia de Brasília não vira uma
     * tolerância de um dia para tudo.
     */
    public function test_as_2130_de_brasilia_o_ca_vencido_ontem_continua_recusado(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-07-26 00:30:00', 'UTC'));

        $epi = $this->criarEpi(['validade_ca' => '2026-07-24']);

        $this->expectException(CaVencidoException::class);

        $this->registrar($epi);
    }

    // -----------------------------------------------------------------
    // Estorno: a entrega é imutável
    // -----------------------------------------------------------------

    public function test_estorno_sem_motivo_e_recusado(): void
    {
        $entrega = $this->registrar($this->criarEpi());

        try {
            $this->naEmpresa(fn () => $this->entregas->estornar($entrega, '   '));
            $this->fail('estorno sem motivo precisava ser recusado');
        } catch (ValidationException $recusa) {
            $this->assertArrayHasKey('motivo_estorno', $recusa->errors());
        }

        $this->assertNull($entrega->refresh()->estornada_em);
    }

    /**
     * Estorno preserva a linha e é ponto final: assinar, devolver ou estornar
     * de novo ressuscitaria um registro já declarado errado.
     */
    public function test_estorno_preserva_a_linha_e_bloqueia_assinar_devolver_e_estornar_de_novo(): void
    {
        $entrega = $this->registrar($this->criarEpi());

        $this->naEmpresa(fn () => $this->entregas->estornar($entrega, 'Lançada no técnico errado.'));

        $entrega->refresh();

        $this->assertNotNull($entrega->estornada_em);
        $this->assertSame('Lançada no técnico errado.', $entrega->motivo_estorno);
        $this->assertDatabaseHas('ppe_deliveries', ['id' => $entrega->getKey()]);

        foreach ([
            fn () => $this->entregas->assinar($entrega, base64_encode('png')),
            fn () => $this->entregas->devolver($entrega, 'Troca'),
            fn () => $this->entregas->estornar($entrega, 'De novo'),
        ] as $operacao) {
            try {
                $this->naEmpresa($operacao);
                $this->fail('operação sobre entrega estornada precisava ser recusada');
            } catch (EntregaDeEpiEstornadaException $recusa) {
                $this->assertStringContainsString('Lançada no técnico errado.', $recusa->getMessage());
                $this->assertStringContainsString('Registre uma nova entrega', $recusa->getMessage());
            }
        }
    }

    /**
     * O Service não expõe caminho de edição nem de exclusão, e não pode passar
     * a expor: a entrega é documento oponível, com arquivamento recomendado de
     * 20 anos, e a correção é o estorno com motivo.
     */
    public function test_o_service_de_entrega_nao_tem_metodo_de_alteracao_nem_de_exclusao(): void
    {
        $metodos = get_class_methods(PpeDeliveryService::class);

        $this->assertSame(
            ['registrar', 'assinar', 'estornar', 'devolver'],
            $metodos,
            'apareceu método público novo em PpeDeliveryService: entrega não se edita nem se exclui'
        );
    }

    // -----------------------------------------------------------------
    // Devolução: aconteceu e terminou
    // -----------------------------------------------------------------

    public function test_devolucao_registra_dia_e_motivo_sem_estornar_a_entrega(): void
    {
        $entrega = $this->registrar($this->criarEpi(), ['entregue_em' => '2026-07-01']);

        $this->naEmpresa(fn () => $this->entregas->devolver($entrega, 'Desligamento', '2026-07-10'));

        $entrega->refresh();

        $this->assertSame('2026-07-10', BusinessDate::diaDe($entrega->devolvido_em));
        $this->assertSame('Desligamento', $entrega->motivo_devolucao);
        $this->assertNull($entrega->estornada_em, 'devolução não é estorno');
    }

    public function test_devolucao_sem_data_cai_em_hoje_no_fuso_do_negocio(): void
    {
        $entrega = $this->registrar($this->criarEpi(), ['entregue_em' => '2026-07-01']);

        $this->naEmpresa(fn () => $this->entregas->devolver($entrega, 'Dano'));

        $this->assertSame('2026-07-15', BusinessDate::diaDe($entrega->refresh()->devolvido_em));
    }

    public function test_devolucao_anterior_a_entrega_e_recusada_citando_o_dia_da_entrega(): void
    {
        $entrega = $this->registrar($this->criarEpi(), ['entregue_em' => '2026-07-10']);

        try {
            $this->naEmpresa(fn () => $this->entregas->devolver($entrega, 'Troca', '2026-07-09'));
            $this->fail('devolução anterior à entrega precisava ser recusada');
        } catch (ValidationException $recusa) {
            $this->assertStringContainsString('10/07/2026', $recusa->errors()['devolvido_em'][0]);
        }
    }

    public function test_devolucao_futura_e_sem_motivo_sao_recusadas(): void
    {
        $entrega = $this->registrar($this->criarEpi());

        try {
            $this->naEmpresa(fn () => $this->entregas->devolver($entrega, 'Troca', '2026-07-16'));
            $this->fail('devolução futura precisava ser recusada');
        } catch (ValidationException $recusa) {
            $this->assertArrayHasKey('devolvido_em', $recusa->errors());
        }

        try {
            $this->naEmpresa(fn () => $this->entregas->devolver($entrega, '  '));
            $this->fail('devolução sem motivo precisava ser recusada');
        } catch (ValidationException $recusa) {
            $this->assertArrayHasKey('motivo_devolucao', $recusa->errors());
        }
    }

    // -----------------------------------------------------------------
    // Assinatura de recebimento
    // -----------------------------------------------------------------

    public function test_assinatura_grava_o_arquivo_em_disco_e_so_o_caminho_na_coluna(): void
    {
        Storage::fake('public');

        $entrega = $this->registrar($this->criarEpi());
        $png = base64_encode('conteudo-png-de-teste');

        $this->naEmpresa(fn () => $this->entregas->assinar($entrega, 'data:image/png;base64,'.$png));

        $entrega->refresh();

        $this->assertNotNull($entrega->assinado_em);
        $this->assertStringStartsWith('ppe-deliveries/'.$entrega->getKey().'/assinaturas/', $entrega->assinatura_path);
        $this->assertStringNotContainsString($png, (string) $entrega->assinatura_path);
        Storage::disk('public')->assertExists($entrega->assinatura_path);
    }

    /**
     * Reassinar é correção da mesma linha. Duplicar faria a ficha mentir sobre
     * a quantidade entregue.
     */
    public function test_reassinar_corrige_a_mesma_linha_e_nao_cria_uma_segunda_entrega(): void
    {
        Storage::fake('public');

        $entrega = $this->registrar($this->criarEpi());

        $this->naEmpresa(fn () => $this->entregas->assinar($entrega, base64_encode('primeira')));
        $primeiro = $entrega->refresh()->assinatura_path;

        $this->naEmpresa(fn () => $this->entregas->assinar($entrega, base64_encode('segunda')));
        $segundo = $entrega->refresh()->assinatura_path;

        $this->assertNotSame($primeiro, $segundo);
        $this->assertSame(1, $this->contarEntregas());
        // O arquivo antigo continua no disco: o caminho dele está no histórico
        // de auditoria, e apagar deixaria aquele registro apontando para o nada.
        Storage::disk('public')->assertExists($primeiro);
    }

    public function test_assinatura_vazia_ou_fora_do_base64_e_recusada(): void
    {
        Storage::fake('public');

        $entrega = $this->registrar($this->criarEpi());

        foreach (['   ', 'isto-não-é-base64-!!!'] as $imagem) {
            try {
                $this->naEmpresa(fn () => $this->entregas->assinar($entrega, $imagem));
                $this->fail('assinatura inválida precisava ser recusada');
            } catch (ValidationException $recusa) {
                $this->assertArrayHasKey('assinatura', $recusa->errors());
            }
        }

        $this->assertNull($entrega->refresh()->assinado_em);
    }

    // -----------------------------------------------------------------
    // Isolamento entre empresas
    // -----------------------------------------------------------------

    /**
     * A pior falha possível deste sistema: a ficha de um técnico apontando para
     * o cadastro de um concorrente. Fora de requisição HTTP o escopo global não
     * filtra (comando artisan, seeder, job), e é ali que esta rede de segurança
     * do Service precisa valer.
     */
    public function test_tecnico_e_epi_de_empresas_diferentes_sao_recusados(): void
    {
        $outra = Company::create([
            'name' => 'Concorrente do EPI',
            'cnpj' => '55.555.555/0001-55',
            'email' => 'contato@concorrente-epi.test',
        ]);

        $epiDaOutra = TenantAtual::comTenant(
            $outra->getKey(),
            fn (): PersonalProtectiveEquipment => PersonalProtectiveEquipmentFactory::new()->create()
        );

        try {
            $this->entregas->registrar([
                'technician_id' => $this->tecnico->getKey(),
                'personal_protective_equipment_id' => $epiDaOutra->getKey(),
            ]);
            $this->fail('técnico e EPI de empresas diferentes não podiam entrar na mesma ficha');
        } catch (ValidationException $recusa) {
            $this->assertStringContainsString(
                'empresas diferentes',
                $recusa->errors()['personal_protective_equipment_id'][0]
            );
        }

        $this->assertSame(0, PpeDelivery::query()->count());
    }

    /**
     * Dentro do tenant, o EPI da outra empresa nem é encontrado: o escopo
     * global responde antes, e o Service devolve o 404 do `findOrFail`.
     */
    public function test_dentro_do_tenant_o_epi_de_outra_empresa_nao_e_encontrado(): void
    {
        $outra = Company::create([
            'name' => 'Concorrente do EPI 2',
            'cnpj' => '66.666.666/0001-66',
            'email' => 'contato@concorrente-epi-2.test',
        ]);

        $epiDaOutra = TenantAtual::comTenant(
            $outra->getKey(),
            fn (): PersonalProtectiveEquipment => PersonalProtectiveEquipmentFactory::new()->create()
        );

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $this->naEmpresa(fn () => $this->entregas->registrar([
            'technician_id' => $this->tecnico->getKey(),
            'personal_protective_equipment_id' => $epiDaOutra->getKey(),
        ]));
    }

    // -----------------------------------------------------------------
    // Cadastro: inativar em vez de excluir
    // -----------------------------------------------------------------

    public function test_epi_com_entrega_registrada_nao_pode_ser_excluido_e_a_recusa_aponta_a_inativacao(): void
    {
        $epi = $this->criarEpi(['nome' => 'Respirador em uso']);
        $this->registrar($epi);

        try {
            $this->naEmpresa(fn () => $this->cadastro->excluir($epi));
            $this->fail('EPI com entrega não podia ser excluído');
        } catch (EpiComEntregaException $recusa) {
            $this->assertSame(1, $recusa->entregas);
            $this->assertStringContainsString('Inative o cadastro', $recusa->getMessage());
        }

        $this->assertDatabaseHas('personal_protective_equipments', ['id' => $epi->getKey()]);
    }

    public function test_epi_nunca_entregue_pode_ser_excluido(): void
    {
        $epi = $this->criarEpi();

        $this->naEmpresa(fn () => $this->cadastro->excluir($epi));

        $this->assertDatabaseMissing('personal_protective_equipments', ['id' => $epi->getKey()]);
    }

    public function test_inativar_tira_o_modelo_das_novas_entregas_e_reativar_devolve(): void
    {
        $epi = $this->criarEpi();

        $this->naEmpresa(fn () => $this->cadastro->inativar($epi));

        try {
            $this->registrar($epi->fresh());
            $this->fail('EPI inativo não podia ser entregue');
        } catch (ValidationException) {
            // esperado
        }

        $this->naEmpresa(fn () => $this->cadastro->reativar($epi));

        $this->assertNotNull($this->registrar($epi->fresh())->getKey());
    }

    /**
     * A fábrica de entregas escreve o mesmo que o Service escreveria: sem isso,
     * o cenário histórico montado por ela (Task 28.7) produziria linhas que o
     * sistema real nunca gera, e os testes de alerta e de ficha estariam
     * conferindo ficção.
     */
    public function test_a_fabrica_de_entrega_reproduz_a_copia_do_ca_feita_pelo_service(): void
    {
        $epi = $this->criarEpi(['numero_ca' => 'CA-33333', 'validade_ca' => '2026-12-31', 'vida_util_dias' => 30]);

        $doService = $this->registrar($epi, ['entregue_em' => '2026-07-10']);
        $daFabrica = $this->naEmpresa(fn (): PpeDelivery => PpeDeliveryFactory::new()
            ->entregueA($this->tecnico, $epi, '2026-07-10')
            ->create());

        $this->assertSame($doService->numero_ca, $daFabrica->numero_ca);

        foreach (['validade_ca', 'trocar_ate', 'entregue_em'] as $coluna) {
            $this->assertSame(
                BusinessDate::diaDe($doService->{$coluna}),
                BusinessDate::diaDe($daFabrica->{$coluna}),
                "a fábrica divergiu do Service em {$coluna}"
            );
        }
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function naEmpresa(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->getKey(), $callback);
    }

    private function criarTecnico(): Technician
    {
        return $this->naEmpresa(fn (): Technician => Technician::create([
            'name' => 'Técnico do EPI',
            'email' => 'tecnico-epi-'.uniqid().'@dedetizadora.test',
            'phone' => '11999990000',
            'is_active' => true,
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
     * @param  array<string, mixed>  $dados
     */
    private function registrar(PersonalProtectiveEquipment $epi, array $dados = []): PpeDelivery
    {
        return $this->naEmpresa(fn (): PpeDelivery => $this->entregas->registrar(array_merge([
            'technician_id' => $this->tecnico->getKey(),
            'personal_protective_equipment_id' => $epi->getKey(),
        ], $dados)));
    }

    private function contarEntregas(): int
    {
        return $this->naEmpresa(fn (): int => PpeDelivery::query()->count());
    }
}
