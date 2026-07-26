<?php

namespace Tests\Feature;

use App\Models\Contract;
use Database\Factories\AddressFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Task 9.2: backfill de visit_frequency_valor e visit_frequency_unidade a
 * partir do texto livre em visit_frequency.
 *
 * O mapeamento aqui decide a data de visita de contrato periódico (Task 9.3
 * em diante lê estas colunas para calcular datas de visita), e contrato
 * periódico sem visita gerada no período é pendência de conformidade com a
 * RDC 622/2022. Valor mapeado errado não quebra em runtime, ele gera visita
 * na data errada para um cliente real: por isso cada caso do mapeamento
 * mínimo tem teste próprio, em vez de confiar em revisão manual do comando.
 *
 * O caso "biweekly" -> 15 dias (não 2 semanas) é a decisão menos óbvia do
 * mapeamento: no rótulo já usado em Contracts/Create.vue e Edit.vue,
 * "biweekly" é exibido como "Quinzenal", não como "a cada duas semanas". O
 * teste correspondente documenta que isso foi deliberado, para que uma
 * "correção" futura alinhando ao sentido literal do inglês não passe sem
 * quebrar um teste.
 */
class BackfillPeriodicidadeTest extends TestCase
{
    use RefreshDatabase;

    private int $addressId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->addressId = AddressFactory::new()->create()->id;
    }

    // -----------------------------------------------------------------
    // --dry-run não grava nada
    // -----------------------------------------------------------------

    public function test_dry_run_nao_grava_nada(): void
    {
        $convertivel = $this->criarContrato('mensal');
        $pendente = $this->criarContrato('abacate');

        $this->artisan('contratos:backfill-periodicidade', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertNull($convertivel->fresh()->visit_frequency_valor, 'dry-run não deveria gravar o convertível');
        $this->assertNull($pendente->fresh()->visit_frequency_valor, 'dry-run não deveria gravar a pendência');
        $this->assertSame('mensal', $convertivel->fresh()->visit_frequency, 'dry-run não deveria alterar visit_frequency');
    }

    // -----------------------------------------------------------------
    // Literais em inglês: o que o formulário emite hoje
    // -----------------------------------------------------------------

    public function test_weekly_e_convertido_para_uma_semana(): void
    {
        $contrato = $this->criarContrato('weekly');

        $this->confirmarBackfill(1);

        $this->assertSame(1, $contrato->fresh()->visit_frequency_valor);
        $this->assertSame('semanas', $contrato->fresh()->visit_frequency_unidade);
    }

    /**
     * Caso deliberado, não literal: "biweekly" vira 15 dias, seguindo o
     * rótulo "Quinzenal" já usado nas telas de contrato, não o sentido
     * literal do inglês ("a cada duas semanas"). Ver docblock da classe.
     */
    public function test_biweekly_e_convertido_para_quinze_dias_e_nao_para_duas_semanas(): void
    {
        $contrato = $this->criarContrato('biweekly');

        $this->confirmarBackfill(1);

        $contrato->refresh();

        $this->assertSame(15, $contrato->visit_frequency_valor, 'biweekly deveria virar 15, não 2');
        $this->assertSame('dias', $contrato->visit_frequency_unidade, 'biweekly deveria virar dias, não semanas');
        $this->assertNotEquals(
            ['valor' => 2, 'unidade' => 'semanas'],
            ['valor' => $contrato->visit_frequency_valor, 'unidade' => $contrato->visit_frequency_unidade],
            'biweekly não pode ser tratado como "a cada duas semanas"'
        );
    }

    public function test_monthly_e_convertido_para_um_mes(): void
    {
        $contrato = $this->criarContrato('monthly');

        $this->confirmarBackfill(1);

        $this->assertSame(1, $contrato->fresh()->visit_frequency_valor);
        $this->assertSame('meses', $contrato->fresh()->visit_frequency_unidade);
    }

    // -----------------------------------------------------------------
    // Literais em português: mapeamento mínimo da Task 9.2
    // -----------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string, 1: int, 2: string}>
     */
    public static function literaisEmPortuguesProvider(): iterable
    {
        yield 'semanal' => ['semanal', 1, 'semanas'];
        yield 'quinzenal' => ['quinzenal', 15, 'dias'];
        yield 'mensal' => ['mensal', 1, 'meses'];
        yield 'bimestral' => ['bimestral', 2, 'meses'];
        yield 'trimestral' => ['trimestral', 3, 'meses'];
        yield 'semestral' => ['semestral', 6, 'meses'];
        yield 'anual' => ['anual', 12, 'meses'];
    }

    #[DataProvider('literaisEmPortuguesProvider')]
    public function test_literal_em_portugues_e_convertido_para_o_valor_e_unidade_esperados(
        string $bruto,
        int $valorEsperado,
        string $unidadeEsperada
    ): void {
        $contrato = $this->criarContrato($bruto);

        $this->confirmarBackfill(1);

        $this->assertSame($valorEsperado, $contrato->fresh()->visit_frequency_valor, "valor incorreto para \"{$bruto}\"");
        $this->assertSame($unidadeEsperada, $contrato->fresh()->visit_frequency_unidade, "unidade incorreta para \"{$bruto}\"");
    }

    public function test_literal_e_reconhecido_independente_de_caixa_e_acento(): void
    {
        $maiuscula = $this->criarContrato('MENSAL');
        $comMaiusculaEAcentoImplicito = $this->criarContrato('Trimestral');

        $this->confirmarBackfill(2);

        $this->assertSame(1, $maiuscula->fresh()->visit_frequency_valor);
        $this->assertSame('meses', $maiuscula->fresh()->visit_frequency_unidade);

        $this->assertSame(3, $comMaiusculaEAcentoImplicito->fresh()->visit_frequency_valor);
        $this->assertSame('meses', $comMaiusculaEAcentoImplicito->fresh()->visit_frequency_unidade);
    }

    // -----------------------------------------------------------------
    // Número puro: significado histórico (meses), de quando a coluna
    // ainda era integer
    // -----------------------------------------------------------------

    public function test_numero_puro_e_convertido_como_meses(): void
    {
        $tres = $this->criarContrato('3');
        $doze = $this->criarContrato('12');

        $this->confirmarBackfill(2);

        $this->assertSame(3, $tres->fresh()->visit_frequency_valor);
        $this->assertSame('meses', $tres->fresh()->visit_frequency_unidade);

        $this->assertSame(12, $doze->fresh()->visit_frequency_valor);
        $this->assertSame('meses', $doze->fresh()->visit_frequency_unidade);
    }

    // -----------------------------------------------------------------
    // Número seguido de palavra: extrai os dois
    // -----------------------------------------------------------------

    /**
     * @return iterable<string, array{0: string, 1: int, 2: string}>
     */
    public static function numeroComUnidadeProvider(): iterable
    {
        yield '3 meses' => ['3 meses', 3, 'meses'];
        yield '2 semanas' => ['2 semanas', 2, 'semanas'];
        yield '15 dias' => ['15 dias', 15, 'dias'];
        // "ano" não é unidade reconhecida por Contract::periodicidadeEmDias(),
        // então "1 ano" converte para o equivalente em meses.
        yield '1 ano' => ['1 ano', 12, 'meses'];
    }

    #[DataProvider('numeroComUnidadeProvider')]
    public function test_numero_com_unidade_por_extenso_extrai_valor_e_unidade(
        string $bruto,
        int $valorEsperado,
        string $unidadeEsperada
    ): void {
        $contrato = $this->criarContrato($bruto);

        $this->confirmarBackfill(1);

        $this->assertSame($valorEsperado, $contrato->fresh()->visit_frequency_valor, "valor incorreto para \"{$bruto}\"");
        $this->assertSame($unidadeEsperada, $contrato->fresh()->visit_frequency_unidade, "unidade incorreta para \"{$bruto}\"");
    }

    // -----------------------------------------------------------------
    // Pendências: nunca convertido, nunca chutado
    // -----------------------------------------------------------------

    public function test_valor_vazio_vira_pendencia_sem_conversao(): void
    {
        $contrato = $this->criarContrato('');

        $this->artisan('contratos:backfill-periodicidade', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($contrato->fresh()->visit_frequency_valor);
        $this->assertNull($contrato->fresh()->visit_frequency_unidade);
    }

    public function test_valor_nulo_vira_pendencia_sem_conversao(): void
    {
        $contrato = $this->criarContrato(null);

        $this->artisan('contratos:backfill-periodicidade', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($contrato->fresh()->visit_frequency_valor);
        $this->assertNull($contrato->fresh()->visit_frequency_unidade);
    }

    /**
     * Usa Artisan::call() + Artisan::output() (mesmo padrão de
     * PurgeAuditLogsTest::test_routines_status_lista_a_rotina_de_expurgo_de_auditoria)
     * em vez de expectsOutputToContain: a saída da tabela é capturada inteira
     * de uma vez, sem o risco de o Symfony Table dividir a linha em mais de
     * uma chamada de escrita e quebrar um match por substring.
     */
    public function test_valor_nao_reconhecido_vira_pendencia_e_aparece_na_listagem(): void
    {
        $contrato = $this->criarContrato('abacate');

        $this->assertSame(0, Artisan::call('contratos:backfill-periodicidade', ['--dry-run' => true]));
        $saida = Artisan::output();

        $this->assertStringContainsString('abacate', $saida, 'o valor lixo deveria aparecer na listagem de pendências');
        $this->assertStringContainsString('Pendências (não reconhecido): 1', $saida);

        $this->assertNull($contrato->fresh()->visit_frequency_valor, 'valor lixo nunca deveria ser convertido');
        $this->assertNull($contrato->fresh()->visit_frequency_unidade);
    }

    /**
     * Periodicidade zero não tem significado de negócio (geraria visita todo
     * dia ou nunca, a depender de quem lê o valor mais adiante): o comando
     * trata como não reconhecido, nunca como uma conversão válida.
     */
    public function test_valor_zero_vira_pendencia_em_vez_de_conversao(): void
    {
        $numeroPuroZero = $this->criarContrato('0');
        $comUnidadeZero = $this->criarContrato('0 meses');

        $this->artisan('contratos:backfill-periodicidade', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($numeroPuroZero->fresh()->visit_frequency_valor, '"0" não deveria ser convertido');
        $this->assertNull($comUnidadeZero->fresh()->visit_frequency_valor, '"0 meses" não deveria ser convertido');
    }

    // -----------------------------------------------------------------
    // Contrato pontual: isento, nunca entra na lista de pendências
    // -----------------------------------------------------------------

    public function test_contrato_pontual_com_valor_vazio_e_isento_e_nao_e_pendencia(): void
    {
        $pontual = Contract::query()->create([
            'address_id' => $this->addressId,
            'service_type' => 'pontual',
            'visit_frequency' => '',
        ]);

        $this->assertSame(0, Artisan::call('contratos:backfill-periodicidade', ['--dry-run' => true]));
        $saida = Artisan::output();

        $this->assertStringContainsString('Isentos (service_type = pontual): 1', $saida);
        $this->assertStringContainsString('Pendências (não reconhecido): 0', $saida, 'contrato pontual não deveria contar como pendência');

        $this->assertNull($pontual->fresh()->visit_frequency_valor);
    }

    /**
     * Mesmo com um valor reconhecível, contrato pontual não precisa de
     * periodicidade: o comando não converte, porque service_type decide a
     * isenção antes de olhar o conteúdo de visit_frequency.
     */
    public function test_contrato_pontual_com_valor_reconhecivel_nao_e_convertido(): void
    {
        $pontual = Contract::query()->create([
            'address_id' => $this->addressId,
            'service_type' => 'pontual',
            'visit_frequency' => 'mensal',
        ]);

        $this->artisan('contratos:backfill-periodicidade')
            ->assertSuccessful();

        $this->assertNull($pontual->fresh()->visit_frequency_valor, 'contrato pontual não deveria ser convertido mesmo com valor reconhecível');
        $this->assertNull($pontual->fresh()->visit_frequency_unidade);
    }

    // -----------------------------------------------------------------
    // Idempotência
    // -----------------------------------------------------------------

    public function test_contrato_ja_convertido_nao_e_tocado_de_novo(): void
    {
        $jaConvertido = Contract::query()->create([
            'address_id' => $this->addressId,
            'service_type' => 'periodico',
            'visit_frequency' => 'mensal',
            'visit_frequency_valor' => 99,
            'visit_frequency_unidade' => 'meses',
        ]);

        // Nenhum outro candidato: a execução real não deveria nem perguntar
        // confirmação, porque não há nada convertível.
        $this->artisan('contratos:backfill-periodicidade')->assertSuccessful();

        $jaConvertido->refresh();
        $this->assertSame(99, $jaConvertido->visit_frequency_valor, 'contrato já convertido não deveria ser tocado');
        $this->assertSame('meses', $jaConvertido->visit_frequency_unidade);
    }

    public function test_rodar_duas_vezes_na_segunda_nao_converte_nada_de_novo(): void
    {
        $contrato = $this->criarContrato('mensal');

        $this->confirmarBackfill(1);

        $this->assertSame(1, $contrato->fresh()->visit_frequency_valor);

        // Segunda rodada: o único candidato já foi convertido, então não há
        // mais nada a fazer e o comando nem chega a pedir confirmação.
        $this->artisan('contratos:backfill-periodicidade')->assertSuccessful();

        $this->assertSame(1, $contrato->fresh()->visit_frequency_valor, 'segunda execução não deveria alterar o valor já convertido');
        $this->assertSame('meses', $contrato->fresh()->visit_frequency_unidade);
    }

    /**
     * Pendência que continua sem conversão reconhecida em uma segunda
     * rodada não deveria virar erro nem trava: o comando relata zero
     * alterações e segue disponível para rodar de novo depois que alguém
     * resolver a pendência na tela.
     */
    public function test_pendencia_continua_pendente_em_uma_segunda_rodada_sem_erro(): void
    {
        $pendente = $this->criarContrato('abacate');

        $this->artisan('contratos:backfill-periodicidade', ['--dry-run' => true])->assertSuccessful();
        $this->artisan('contratos:backfill-periodicidade', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull($pendente->fresh()->visit_frequency_valor);
    }

    // -----------------------------------------------------------------
    // Integridade: contagem e coluna original intactas
    // -----------------------------------------------------------------

    public function test_visit_frequency_original_nunca_e_alterada(): void
    {
        $contrato = $this->criarContrato('mensal');

        $this->confirmarBackfill(1);

        $this->assertSame('mensal', $contrato->fresh()->visit_frequency, 'visit_frequency é a rede de segurança do backfill e não pode ser tocada');
    }

    public function test_contagem_de_contratos_nao_muda_durante_o_backfill(): void
    {
        $this->criarContrato('mensal');
        $this->criarContrato('abacate');
        $this->criarContrato('');

        $totalAntes = Contract::query()->count();

        $this->confirmarBackfill(1);

        $this->assertSame($totalAntes, Contract::query()->count(), 'o backfill não deveria criar nem apagar contratos');
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function criarContrato(?string $visitFrequency, string $serviceType = 'periodico'): Contract
    {
        return Contract::query()->create([
            'address_id' => $this->addressId,
            'service_type' => $serviceType,
            'visit_frequency' => $visitFrequency,
        ]);
    }

    /**
     * Roda o comando real confirmando a gravação, para os testes que só
     * querem chegar no estado gravado sem repetir a interação em cada um.
     */
    private function confirmarBackfill(int $quantidadeEsperada): void
    {
        $this->artisan('contratos:backfill-periodicidade')
            ->expectsConfirmation("Gravar a conversão em {$quantidadeEsperada} contrato(s)?", 'yes')
            ->assertSuccessful();
    }
}
