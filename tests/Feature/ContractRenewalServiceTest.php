<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\WorkOrder;
use App\Services\ContractRenewalService;
use App\Services\GeracaoDeVisitasService;
use App\Support\BusinessDate;
use Carbon\Carbon;
use Database\Factories\AddressFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cobre os critérios de aceitação da Task 23.4: renovar cria contrato novo
 * com a vigência seguinte, o anterior fica marcado como renovado e inalterado
 * no resto, o reajuste (percentual ou por índice) é aplicado e gravado, a
 * geração de visitas do Plano 9 é disparada para o contrato novo, as visitas
 * futuras do anterior são canceladas com motivo, a janela de 90/30 dias é
 * respeitada, a não renovação exige motivo da lista fechada e a cadeia de
 * renovações é navegável.
 */
class ContractRenewalServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 15h UTC = 12h em America/Sao_Paulo (UTC-3): mesmo dia calendário nos
     * dois fusos, para as datas dos cenários abaixo não dependerem de virada
     * de dia entre UTC e o fuso do negócio.
     */
    private const MOMENTO_VIRADA = '2026-07-25 15:00:00';

    private const HOJE = '2026-07-25';

    private ContractRenewalService $renovacao;

    private GeracaoDeVisitasService $geracao;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::MOMENTO_VIRADA, 'UTC'));

        $this->renovacao = app(ContractRenewalService::class);
        $this->geracao = app(GeracaoDeVisitasService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Vigência do contrato novo
    // -----------------------------------------------------------------

    public function test_renovar_cria_contrato_novo_com_vigencia_comecando_no_dia_seguinte_ao_fim_do_anterior(): void
    {
        $anterior = $this->criarContrato([
            'start_date' => '2025-08-25',
            'end_date' => '2026-08-04', // 10 dias à frente de hoje: dentro da janela
            'service_value' => '1000.00',
            'pest_target' => 'Baratas e cupins',
            'payment_method' => 'Boleto mensal',
            'payment_details' => 'Banco X, agência 0001',
            'additional_clause' => 'Cláusula de garantia estendida',
            'jurisdiction' => 'Comarca de São Paulo',
        ]);

        $resultado = $this->renovacao->renovar($anterior, [
            'percentual_reajuste' => 0,
            'end_date' => '2027-08-04',
        ]);

        $this->assertTrue($resultado['success'], $resultado['message']);

        $novo = $resultado['data']['contrato_novo'];

        $this->assertSame('2026-08-05', BusinessDate::diaDe($novo->start_date));
        $this->assertSame('2027-08-04', BusinessDate::diaDe($novo->end_date));
        $this->assertSame($anterior->id, $novo->contrato_anterior_id);
        $this->assertSame($anterior->address_id, $novo->address_id);

        // Cliente, endereço, serviço, periodicidade e cláusulas copiados.
        $this->assertSame($anterior->service_type, $novo->service_type);
        $this->assertSame($anterior->visit_frequency, $novo->visit_frequency);
        $this->assertSame($anterior->visit_frequency_valor, $novo->visit_frequency_valor);
        $this->assertSame($anterior->visit_frequency_unidade, $novo->visit_frequency_unidade);
        $this->assertSame($anterior->visit_count, $novo->visit_count);
        $this->assertSame($anterior->pest_target, $novo->pest_target);
        $this->assertSame($anterior->payment_method, $novo->payment_method);
        $this->assertSame($anterior->payment_details, $novo->payment_details);
        $this->assertSame($anterior->additional_clause, $novo->additional_clause);
        $this->assertSame($anterior->jurisdiction, $novo->jurisdiction);

        // Número de contrato próprio, não copiado do anterior.
        $this->assertNotNull($novo->contract_number);
        $this->assertNotSame($anterior->contract_number, $novo->contract_number);
    }

    public function test_renovar_preserva_a_duracao_do_contrato_anterior_quando_end_date_novo_nao_e_informado(): void
    {
        $anterior = $this->criarContrato([
            'start_date' => '2026-01-01',
            'end_date' => '2026-07-30', // 5 dias à frente de hoje: dentro da janela
        ]);

        $resultado = $this->renovacao->renovar($anterior, []);

        $this->assertTrue($resultado['success'], $resultado['message']);

        $novo = $resultado['data']['contrato_novo'];

        $duracaoAnterior = $anterior->start_date->diffInDays($anterior->end_date);
        $duracaoNova = $novo->start_date->diffInDays($novo->end_date);

        $this->assertSame($duracaoAnterior, $duracaoNova, 'a vigência nova não manteve a mesma duração da anterior');
    }

    // -----------------------------------------------------------------
    // O anterior fica marcado como renovado e inalterado no resto
    // -----------------------------------------------------------------

    public function test_renovar_marca_o_anterior_como_renovado_e_nao_altera_mais_nada_nele(): void
    {
        $anterior = $this->criarContrato([
            'end_date' => '2026-08-04',
            'service_value' => '1000.00',
        ]);

        // `fresh()` antes de capturar: o array de atributos de um model recém
        // criado em memória e o de um recarregado do banco têm formatação
        // interna diferente para coluna `date` (details de implementação do
        // Eloquent), mesmo representando o mesmo valor. Comparar duas
        // leituras do banco elimina esse ruído e deixa o teste sensível só ao
        // que a renovação de fato mudou.
        $antes = $anterior->fresh()->getAttributes();

        $this->renovacao->renovar($anterior, ['percentual_reajuste' => 8, 'end_date' => '2027-08-04']);

        $depois = $anterior->fresh()->getAttributes();

        $this->assertSame('renovado', $depois['situacao_renovacao']);
        $this->assertNotNull($depois['renovado_em']);

        // Fora das duas colunas de marcação, nada mais mudou no anterior.
        // `ksort` porque `getAttributes()` do model recém-criado (em memória,
        // na ordem em que os campos foram passados) e o do `fresh()` (lido do
        // banco, na ordem das colunas) trazem os mesmos pares chave/valor em
        // ordens diferentes, e `===` em array compara também a ordem.
        foreach (['situacao_renovacao', 'renovado_em', 'updated_at'] as $coluna) {
            unset($antes[$coluna], $depois[$coluna]);
        }
        ksort($antes);
        ksort($depois);

        $this->assertSame($antes, $depois, 'o contrato anterior foi alterado além da marcação de renovação');
    }

    // -----------------------------------------------------------------
    // Reajuste: percentual e índice
    // -----------------------------------------------------------------

    public function test_renovar_aplica_o_reajuste_percentual_ao_valor_do_contrato_novo(): void
    {
        $anterior = $this->criarContrato(['end_date' => '2026-08-04', 'service_value' => '1000.00']);

        $resultado = $this->renovacao->renovar($anterior, [
            'percentual_reajuste' => 8,
            'end_date' => '2027-08-04',
        ]);

        $novo = $resultado['data']['contrato_novo'];

        $this->assertSame('1080.00', $novo->service_value);
        $this->assertSame('8.00', $novo->percentual_reajuste);
        $this->assertNull($novo->indice_reajuste);
    }

    public function test_renovar_por_indice_grava_o_indice_e_o_percentual_efetivamente_aplicado(): void
    {
        $anterior = $this->criarContrato(['end_date' => '2026-08-04', 'service_value' => '1000.00']);

        $resultado = $this->renovacao->renovar($anterior, [
            'percentual_reajuste' => 4.5,
            'indice_reajuste' => 'IPCA',
            'end_date' => '2027-08-04',
        ]);

        $novo = $resultado['data']['contrato_novo'];

        $this->assertSame('IPCA', $novo->indice_reajuste);
        $this->assertSame('4.50', $novo->percentual_reajuste);
        $this->assertSame('1045.00', $novo->service_value);
    }

    public function test_renovar_sem_informar_reajuste_mantem_o_valor_do_contrato_anterior(): void
    {
        $anterior = $this->criarContrato(['end_date' => '2026-08-04', 'service_value' => '1000.00']);

        $resultado = $this->renovacao->renovar($anterior, ['end_date' => '2027-08-04']);

        $novo = $resultado['data']['contrato_novo'];

        $this->assertSame('1000.00', $novo->service_value);
        $this->assertSame('0.00', $novo->percentual_reajuste);
    }

    // -----------------------------------------------------------------
    // Geração de visitas do contrato novo (Plano 9)
    // -----------------------------------------------------------------

    public function test_renovar_dispara_a_geracao_das_visitas_do_contrato_novo(): void
    {
        $anterior = $this->criarContrato(['end_date' => '2026-08-04']);

        $resultado = $this->renovacao->renovar($anterior, ['end_date' => '2027-08-04']);

        $novo = $resultado['data']['contrato_novo'];

        $visitas = WorkOrder::query()
            ->where('contract_id', $novo->id)
            ->where('origem', 'contrato')
            ->orderBy('scheduled_date')
            ->get();

        $this->assertGreaterThan(0, $visitas->count(), 'nenhuma visita foi gerada para o contrato novo');
        $this->assertSame(
            BusinessDate::diaDe($novo->start_date),
            BusinessDate::diaDe($visitas->first()->scheduled_date),
            'a primeira visita gerada não coincide com o início da vigência do contrato novo'
        );
        $this->assertSame('scheduled', $visitas->first()->status);
        $this->assertSame($resultado['data']['visitas_geradas'], $visitas->count());
    }

    // -----------------------------------------------------------------
    // Cancelamento das visitas futuras do anterior
    // -----------------------------------------------------------------

    public function test_renovar_cancela_as_visitas_futuras_do_anterior_com_o_motivo_de_renovacao(): void
    {
        // Mensal, ancorado no dia 25: a 12ª visita cai exatamente hoje
        // (2026-07-25) e a 13ª, um mês depois (2026-08-25) — ambas dentro da
        // vigência (end_date 2026-08-29) e ambas "futuras" em relação a hoje.
        $anterior = $this->criarContrato([
            'start_date' => '2025-08-25',
            'end_date' => '2026-08-29',
        ]);

        $geradas = $this->geracao->gerarPara($anterior, $anterior->end_date->toDateString());
        $this->assertCount(2, $geradas, 'pré-condição do teste: esperava 2 visitas futuras geradas antes da renovação');

        $resultado = $this->renovacao->renovar($anterior, ['end_date' => '2027-08-29']);

        $this->assertTrue($resultado['success'], $resultado['message']);
        $this->assertSame(2, $resultado['data']['cancelamento']['canceladas']);

        foreach ($geradas as $visita) {
            $atualizada = $visita->fresh();

            $this->assertSame('cancelled', $atualizada->status);
            $this->assertStringContainsString('Contrato renovado', (string) $atualizada->completion_notes);
        }
    }

    // -----------------------------------------------------------------
    // Janela de renovação: 90 dias antes até 30 dias depois do fim
    // -----------------------------------------------------------------

    public function test_renovar_e_recusado_com_mensagem_clara_quando_faltam_mais_de_90_dias_para_o_fim(): void
    {
        $fim = Carbon::parse(self::HOJE)->addDays(100)->toDateString();
        $anterior = $this->criarContrato(['end_date' => $fim]);

        $resultado = $this->renovacao->renovar($anterior, ['percentual_reajuste' => 5]);

        $this->assertFalse($resultado['success']);
        $this->assertStringContainsString('janela de renovação', $resultado['message']);
        $this->assertSame(1, Contract::query()->count(), 'um contrato novo foi criado mesmo com a renovação recusada');
        $this->assertNull($anterior->fresh()->situacao_renovacao);
    }

    public function test_renovar_e_recusado_com_mensagem_clara_quando_o_contrato_venceu_ha_mais_de_30_dias(): void
    {
        $fim = Carbon::parse(self::HOJE)->subDays(40)->toDateString();
        $anterior = $this->criarContrato(['end_date' => $fim]);

        $resultado = $this->renovacao->renovar($anterior, ['percentual_reajuste' => 5]);

        $this->assertFalse($resultado['success']);
        $this->assertStringContainsString('janela de renovação', $resultado['message']);
        $this->assertSame(1, Contract::query()->count());
        $this->assertNull($anterior->fresh()->situacao_renovacao);
    }

    public function test_renovar_um_contrato_ja_renovado_e_recusado(): void
    {
        $anterior = $this->criarContrato(['end_date' => '2026-08-04', 'situacao_renovacao' => 'renovado']);

        $resultado = $this->renovacao->renovar($anterior, []);

        $this->assertFalse($resultado['success']);
        $this->assertSame(1, Contract::query()->count());
    }

    // -----------------------------------------------------------------
    // Não renovação: motivo da lista fechada
    // -----------------------------------------------------------------

    public function test_registrar_nao_renovacao_recusa_motivo_fora_da_lista_fechada(): void
    {
        $contrato = $this->criarContrato();

        $resultado = $this->renovacao->registrarNaoRenovacao($contrato, 'motivo_qualquer');

        $this->assertFalse($resultado['success']);
        $this->assertNull($contrato->fresh()->situacao_renovacao);
    }

    public function test_registrar_nao_renovacao_com_outro_exige_texto_livre(): void
    {
        $contrato = $this->criarContrato();

        $semTexto = $this->renovacao->registrarNaoRenovacao($contrato, 'outro');
        $this->assertFalse($semTexto['success']);

        $comTexto = $this->renovacao->registrarNaoRenovacao($contrato, 'outro', 'Cliente mudou de cidade');
        $this->assertTrue($comTexto['success']);

        $atualizado = $contrato->fresh();
        $this->assertSame('nao_renovado', $atualizado->situacao_renovacao);
        $this->assertSame('Cliente mudou de cidade', $atualizado->motivo_nao_renovacao);
    }

    public function test_registrar_nao_renovacao_com_motivo_da_lista_grava_o_rotulo(): void
    {
        $contrato = $this->criarContrato();

        $resultado = $this->renovacao->registrarNaoRenovacao($contrato, 'preco');

        $this->assertTrue($resultado['success']);

        $atualizado = $contrato->fresh();
        $this->assertSame('nao_renovado', $atualizado->situacao_renovacao);
        $this->assertSame('Preço', $atualizado->motivo_nao_renovacao);
    }

    // -----------------------------------------------------------------
    // Cadeia de renovações navegável
    // -----------------------------------------------------------------

    public function test_a_cadeia_de_renovacoes_e_navegavel_do_mais_novo_ao_mais_antigo(): void
    {
        $contrato1 = $this->criarContrato([
            'start_date' => '2025-08-25',
            'end_date' => '2026-08-04', // 10 dias à frente de hoje
        ]);

        $primeira = $this->renovacao->renovar($contrato1, ['end_date' => '2026-08-09']);
        $this->assertTrue($primeira['success'], $primeira['message']);
        $contrato2 = $primeira['data']['contrato_novo'];

        // 2026-08-09 continua a apenas 15 dias de hoje (2026-07-25): ainda
        // dentro da janela de 90 dias antes do fim, então a segunda
        // renovação também é permitida no mesmo instante de teste.
        $segunda = $this->renovacao->renovar($contrato2, ['end_date' => '2026-08-19']);
        $this->assertTrue($segunda['success'], $segunda['message']);
        $contrato3 = $segunda['data']['contrato_novo'];

        $this->assertSame($contrato2->id, $contrato3->contrato_anterior_id);
        $this->assertSame($contrato1->id, $contrato2->fresh()->contrato_anterior_id);
        $this->assertNull($contrato1->fresh()->contrato_anterior_id);

        $this->assertSame('renovado', $contrato1->fresh()->situacao_renovacao);
        $this->assertSame('renovado', $contrato2->fresh()->situacao_renovacao);
        $this->assertNull($contrato3->situacao_renovacao);

        // Navegação do mais novo ao mais antigo pela relação Eloquent.
        $this->assertSame($contrato2->id, $contrato3->contratoAnterior->id);
        $this->assertSame($contrato1->id, $contrato3->contratoAnterior->contratoAnterior->id);

        // E do mais antigo ao mais novo, pela relação inversa.
        $this->assertSame($contrato2->id, $contrato1->fresh()->renovacao->id);
    }

    /**
     * Achado da Task 23.4: com a renovação, um endereço passa a ter mais de
     * um `Contract` na tabela. `Address::contract()` é `HasOne`, e sem
     * `latestOfMany()` resolveria para o primeiro registro (o anterior, já
     * renovado), quebrando `ContractController::generatePDF()`, que gera um
     * documento com valor perante fiscalização a partir de `$address->contract`.
     */
    public function test_address_contract_resolve_para_o_contrato_mais_recente_apos_renovacao(): void
    {
        $anterior = $this->criarContrato(['end_date' => '2026-08-04']);
        $address = $anterior->address()->first();

        $resultado = $this->renovacao->renovar($anterior, ['end_date' => '2027-08-04']);
        $novo = $resultado['data']['contrato_novo'];

        $this->assertSame($novo->id, $address->contract()->first()->id);
        $this->assertCount(2, $address->contracts()->get());
    }

    /**
     * Verificação manual sugerida pela Task 23.4: renovar com reajuste de 8%
     * e conferir valor, visitas e cadeia, tudo em um único cenário.
     */
    public function test_cenario_completo_renovacao_com_reajuste_de_oito_por_cento(): void
    {
        $anterior = $this->criarContrato([
            'start_date' => '2025-08-25',
            'end_date' => '2026-08-04',
            'service_value' => '1000.00',
        ]);

        $resultado = $this->renovacao->renovar($anterior, [
            'percentual_reajuste' => 8,
            'end_date' => '2027-08-04',
        ]);

        $this->assertTrue($resultado['success'], $resultado['message']);
        $novo = $resultado['data']['contrato_novo'];

        // Valor.
        $this->assertSame('1080.00', $novo->service_value);
        $this->assertSame('8.00', $novo->percentual_reajuste);

        // Visitas.
        $visitasDoNovo = WorkOrder::query()
            ->where('contract_id', $novo->id)
            ->where('origem', 'contrato')
            ->count();
        $this->assertGreaterThan(0, $visitasDoNovo);

        // Cadeia.
        $this->assertSame($anterior->id, $novo->contrato_anterior_id);
        $this->assertSame('renovado', $anterior->fresh()->situacao_renovacao);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Contrato periódico mensal, salvo com o endereço dele. Mesmo padrão de
     * `ContractEncerramentoTest::criarContrato` e `GeracaoDeVisitasTest`.
     *
     * @param  array<string, mixed>  $atributos
     */
    private function criarContrato(array $atributos = []): Contract
    {
        $addressId = AddressFactory::new()->create()->id;

        return Contract::query()->create(array_merge([
            'address_id' => $addressId,
            'contract_number' => 'CONT-' . str_pad((string) $addressId, 6, '0', STR_PAD_LEFT) . '-ANTERIOR',
            'service_type' => 'periodico',
            'visit_frequency' => 'mensal',
            'visit_frequency_valor' => 1,
            'visit_frequency_unidade' => 'meses',
            'visit_count' => 12,
            'service_value' => '1000.00',
            'start_date' => '2025-08-25',
            'end_date' => '2026-08-04',
        ], $atributos));
    }
}
