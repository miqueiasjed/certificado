<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PersonalProtectiveEquipment;
use App\Models\PpeDelivery;
use App\Models\Technician;
use App\Models\User;
use App\Services\Ppe\FichaDeEpiService;
use App\Services\Ppe\PpeDeliveryService;
use App\Services\Ppe\PpeService;
use App\Support\TenantAtual;
use Carbon\Carbon;
use Closure;
use Database\Factories\PersonalProtectiveEquipmentFactory;
use Database\Factories\PpeDeliveryFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 28.7 do Plano 28: a ficha de EPI em PDF e a extração por período.
 *
 * Os dois documentos que a NR-6 cobra — o registro individual do item 6.5.1 e a
 * relação extraível do item 6.5.1.1 — conferidos por `dadosDaFicha()` e por
 * `montarCsvDeEntregas()`, que é o conteúdo que o PDF e o CSV imprimem. Abrir o
 * binário do dompdf provaria menos e quebraria por motivo errado; do PDF só se
 * confere aqui que ele é gerado e que é mesmo um PDF.
 *
 * O eixo central é o mesmo do plano: **o CA impresso é o da entrega, nunca o do
 * cadastro**. A ficha diz o que foi entregue naquele dia, e continua dizendo
 * depois de o certificado ser renovado — documento emitido não se recalcula. Se
 * alguém trocar as colunas `numero_ca`/`validade_ca` de `ppe_deliveries` por uma
 * leitura da relação `personalProtectiveEquipment`, é
 * `test_a_ficha_imprime_o_ca_da_entrega_mesmo_depois_de_o_cadastro_ser_renovado`
 * que cai.
 *
 * Os outros eixos, todos sobre o que o documento é obrigado a dizer:
 *
 * - entrega sem assinatura sai marcada como **pendente**, não em branco: a
 *   confirmação do recebimento é o que a norma pede, e a ausência dela é a
 *   pendência que o documento precisa denunciar;
 * - entrega estornada **nunca some** da ficha nem da extração — sai marcada, com
 *   o motivo. Omitir o estorno de um documento entregue à fiscalização é
 *   adulteração de registro, não limpeza de tela;
 * - técnico inativo continua tendo ficha, e técnico sem nenhuma entrega também:
 *   é justamente a ficha de quem já saiu que costuma ser pedida depois;
 * - o CSV abre no Excel em português com a acentuação certa (BOM UTF-8 e ponto e
 *   vírgula), e as datas saem no fuso do negócio, nunca em UTC.
 *
 * O tempo é fixado com `Carbon::setTestNow()`: nenhuma afirmação aqui pode
 * depender do relógio nem do fuso da máquina que roda a suíte.
 */
class FichaDeEpiServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Meio-dia em UTC de 10/08/2026: 09h de Brasília, longe das duas viradas de
     * dia. Escolhido depois do período de julho usado pela extração, para que
     * toda entrega daquele mês seja retroativa — e portanto aceita pelo Service,
     * que recusa data futura.
     */
    private const AGORA = '2026-08-10 12:00:00';

    private Company $empresa;

    private FichaDeEpiService $fichas;

    private PpeDeliveryService $entregas;

    private PpeService $cadastro;

    private Technician $tecnico;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::AGORA);
        TenantAtual::limpar();

        $this->empresa = Company::query()->findOrFail(1);
        $this->fichas = app(FichaDeEpiService::class);
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
    // O CA impresso é o da entrega — o critério central do plano
    // -----------------------------------------------------------------

    /**
     * A afirmação que sustenta o documento inteiro: renovar o certificado no
     * cadastro não pode reescrever a ficha já emitida.
     *
     * Este teste falha no instante em que a ficha passar a ler o CA pela relação
     * `personalProtectiveEquipment`.
     */
    public function test_a_ficha_imprime_o_ca_da_entrega_mesmo_depois_de_o_cadastro_ser_renovado(): void
    {
        $epi = $this->criarEpi(['numero_ca' => 'CA-ANTIGO', 'validade_ca' => '2026-09-30']);

        $this->registrar($epi, ['entregue_em' => '2026-07-10']);

        $this->naEmpresa(fn () => $this->cadastro->atualizar($epi, [
            'numero_ca' => 'CA-RENOVADO',
            'validade_ca' => '2030-09-30',
        ]));

        $linha = $this->ficha()['entregas'][0];

        $this->assertSame('CA-ANTIGO', $linha['numero_ca'], 'a ficha passou a imprimir o CA do cadastro');
        $this->assertSame('30/09/2026', $linha['validade_ca']);

        // Contraprova: a renovação aconteceu de verdade, ou seja, o teste não
        // passou porque o cadastro ficou parado.
        $this->assertSame('CA-RENOVADO', $epi->fresh()->numero_ca);
    }

    /**
     * O outro lado da mesma regra, visto pela ficha: as duas entregas do mesmo
     * modelo saem com os certificados que valiam em cada dia, uma embaixo da
     * outra. É o histórico que a fiscalização lê.
     */
    public function test_a_ficha_mostra_o_ca_de_cada_epoca_lado_a_lado(): void
    {
        $epi = $this->criarEpi(['numero_ca' => 'CA-ANTIGO', 'validade_ca' => '2026-09-30']);

        $this->registrar($epi, ['entregue_em' => '2026-07-10']);

        $this->naEmpresa(fn () => $this->cadastro->atualizar($epi, [
            'numero_ca' => 'CA-RENOVADO',
            'validade_ca' => '2030-09-30',
        ]));

        $this->registrar($epi->fresh(), ['entregue_em' => '2026-08-01', 'motivo' => 'substituicao']);

        $linhas = $this->ficha()['entregas'];

        // Ordem crescente: a ficha se lê da primeira entrega para a última.
        $this->assertSame(['CA-ANTIGO', 'CA-RENOVADO'], array_column($linhas, 'numero_ca'));
        $this->assertSame(['10/07/2026', '01/08/2026'], array_column($linhas, 'entregue_em'));
    }

    /**
     * CA em branco é estado neutro, nunca irregularidade pintada de vermelho:
     * sai como "não informado", na mesma regra do checklist do Plano 24. O mesmo
     * vale para a troca de um modelo sem vida útil cadastrada, que é "sem troca
     * programada" e não uma pendência.
     */
    public function test_ca_ausente_e_troca_ausente_saem_como_estados_neutros(): void
    {
        $epi = $this->naEmpresa(fn (): PersonalProtectiveEquipment => PersonalProtectiveEquipmentFactory::new()
            ->semCa()
            ->semVidaUtil()
            ->create());

        $this->registrar($epi);

        $linha = $this->ficha()['entregas'][0];

        $this->assertSame('—', $linha['numero_ca']);
        $this->assertSame('—', $linha['validade_ca']);
        $this->assertFalse($linha['tem_troca_programada']);
        $this->assertSame(FichaDeEpiService::SEM_TROCA_PROGRAMADA, $linha['trocar_ate']);
    }

    // -----------------------------------------------------------------
    // O que falta também é informação: pendência de assinatura
    // -----------------------------------------------------------------

    public function test_entrega_sem_assinatura_sai_marcada_como_pendente(): void
    {
        $this->registrar($this->criarEpi());

        $ficha = $this->ficha();
        $linha = $ficha['entregas'][0];

        $this->assertFalse($linha['assinada']);
        $this->assertNull($linha['assinado_em']);
        $this->assertNull($linha['assinatura_src']);
        $this->assertSame(1, $ficha['resumo']['pendentes_de_assinatura']);
    }

    public function test_entrega_assinada_sai_do_contador_de_pendencias_com_o_instante_no_fuso_do_negocio(): void
    {
        $epi = $this->criarEpi();

        // 16/07 01h30 em UTC é 15/07 22h30 em Brasília: se a ficha imprimisse o
        // instante cru, o técnico apareceria assinando no dia seguinte.
        $this->naEmpresa(fn (): PpeDelivery => PpeDeliveryFactory::new()
            ->entregueA($this->tecnico, $epi, '2026-07-15')
            ->assinada('2026-07-16 01:30:00')
            ->create());

        $ficha = $this->ficha();
        $linha = $ficha['entregas'][0];

        $this->assertTrue($linha['assinada']);
        $this->assertSame('15/07/2026 às 22h30', $linha['assinado_em']);
        $this->assertSame(0, $ficha['resumo']['pendentes_de_assinatura']);
    }

    /**
     * A entrega estornada não é pendência de assinatura: cobrar assinatura de
     * uma linha já declarada errada seria transformar a correção em nova
     * irregularidade.
     */
    public function test_entrega_estornada_nao_conta_como_pendencia_de_assinatura(): void
    {
        $entrega = $this->registrar($this->criarEpi());

        $this->naEmpresa(fn () => $this->entregas->estornar($entrega, 'Lançada no técnico errado.'));

        $this->assertSame(0, $this->ficha()['resumo']['pendentes_de_assinatura']);
    }

    // -----------------------------------------------------------------
    // O estorno aparece, nunca some
    // -----------------------------------------------------------------

    public function test_entrega_estornada_continua_na_ficha_marcada_e_com_o_motivo(): void
    {
        $epi = $this->criarEpi(['nome' => 'Respirador estornado']);
        $entrega = $this->registrar($epi, ['entregue_em' => '2026-07-10']);

        $this->naEmpresa(fn () => $this->entregas->estornar($entrega, 'Lançada no técnico errado.'));

        $ficha = $this->ficha();

        $this->assertCount(1, $ficha['entregas'], 'a entrega estornada sumiu da ficha');

        $linha = $ficha['entregas'][0];

        $this->assertTrue($linha['estornada']);
        $this->assertSame('Lançada no técnico errado.', $linha['motivo_estorno']);
        $this->assertNotNull($linha['estornada_em']);
        // O que foi entregue continua legível: a linha não vira um vazio
        // marcado de vermelho.
        $this->assertSame('Respirador estornado', $linha['epi']);
        $this->assertSame('10/07/2026', $linha['entregue_em']);
    }

    /**
     * A sequência que prova que a correção foi correção, e não reescrita: a
     * errada, estornada com motivo, e a certa logo abaixo. O resumo separa as
     * duas.
     */
    public function test_o_resumo_separa_entregas_validas_de_estornadas(): void
    {
        $epi = $this->criarEpi();

        $errada = $this->registrar($epi, ['entregue_em' => '2026-07-10']);
        $this->naEmpresa(fn () => $this->entregas->estornar($errada, 'Quantidade errada.'));

        $this->registrar($epi, ['entregue_em' => '2026-07-11', 'motivo' => 'substituicao']);

        $resumo = $this->ficha()['resumo'];

        $this->assertSame(2, $resumo['total']);
        $this->assertSame(1, $resumo['validas']);
        $this->assertSame(1, $resumo['estornadas']);
    }

    public function test_a_devolucao_sai_na_ficha_com_dia_e_motivo(): void
    {
        $entrega = $this->registrar($this->criarEpi(), ['entregue_em' => '2026-07-01']);

        $this->naEmpresa(fn () => $this->entregas->devolver($entrega, 'Desligamento', '2026-07-20'));

        $linha = $this->ficha()['entregas'][0];

        $this->assertSame('20/07/2026', $linha['devolvido_em']);
        $this->assertSame('Desligamento', $linha['motivo_devolucao']);
        $this->assertFalse($linha['estornada'], 'devolução não é estorno');
    }

    // -----------------------------------------------------------------
    // A ficha sobrevive ao técnico
    // -----------------------------------------------------------------

    /**
     * Desligamento não apaga registro cujo arquivamento recomendado é de vinte
     * anos — e é justamente a ficha de quem já saiu que costuma ser pedida
     * depois. A situação sai impressa para o leitor saber que a ficha está
     * encerrada.
     */
    public function test_tecnico_inativo_continua_tendo_ficha_com_a_situacao_impressa(): void
    {
        $this->registrar($this->criarEpi(), ['entregue_em' => '2026-07-10']);

        $this->naEmpresa(fn () => $this->tecnico->update(['is_active' => false]));

        $ficha = $this->naEmpresa(fn (): array => $this->fichas->dadosDaFicha($this->tecnico->fresh()));

        $this->assertSame('Inativo', $ficha['tecnico']['situacao']);
        $this->assertCount(1, $ficha['entregas']);
    }

    /**
     * Ficha vazia é resposta legítima: o técnico recém-admitido ainda não
     * recebeu nada, e a ficha precisa existir para registrar a primeira entrega.
     * Estourar aqui deixaria a tela do Plano 28.6 sem página inicial.
     */
    public function test_tecnico_sem_nenhuma_entrega_gera_ficha_vazia_sem_estourar(): void
    {
        $ficha = $this->ficha();

        $this->assertSame([], $ficha['entregas']);
        $this->assertSame(
            ['total' => 0, 'validas' => 0, 'estornadas' => 0, 'pendentes_de_assinatura' => 0],
            $ficha['resumo']
        );
        $this->assertSame($this->tecnico->name, $ficha['tecnico']['nome']);
    }

    /**
     * Campo não preenchido do cadastro do técnico sai neutro, e não como string
     * vazia que no papel viraria um buraco sem explicação.
     */
    public function test_dados_ausentes_do_tecnico_saem_como_nao_informado(): void
    {
        $ficha = $this->ficha();

        $this->assertSame('—', $ficha['tecnico']['registro']);
        $this->assertSame('—', $ficha['tecnico']['especialidade']);
    }

    /**
     * A ficha de um técnico nunca sai com o CNPJ de outra empresa: seria
     * documento falso, e documento emitido aqui tem valor perante fiscalização.
     * Falhar alto é o comportamento desejado.
     */
    public function test_ficha_de_tecnico_de_outra_empresa_e_recusada(): void
    {
        $outra = Company::create([
            'name' => 'Concorrente da Ficha',
            'cnpj' => '77.777.777/0001-77',
            'email' => 'contato@concorrente-ficha.test',
        ]);

        $tecnicoDaOutra = TenantAtual::comTenant($outra->getKey(), fn (): Technician => Technician::create([
            'name' => 'Técnico da concorrente',
            'email' => 'tecnico-concorrente-ficha@concorrente.test',
            'phone' => '11988887777',
            'is_active' => true,
        ]));

        $this->expectException(RuntimeException::class);

        $this->naEmpresa(fn (): array => $this->fichas->dadosDaFicha($tecnicoDaOutra));
    }

    // -----------------------------------------------------------------
    // O PDF
    // -----------------------------------------------------------------

    /**
     * Do binário só se confere aqui que ele é gerado e que é mesmo um PDF: o
     * conteúdo é conferido por `dadosDaFicha()`, que é a mesma fonte que a view
     * imprime. Este teste existe para pegar o erro de renderização da Blade, que
     * nenhuma asserção sobre o array alcançaria.
     */
    public function test_a_ficha_em_pdf_e_gerada_sem_erro(): void
    {
        $epi = $this->criarEpi(['nome' => 'Óculos de proteção']);
        $entrega = $this->registrar($epi, ['entregue_em' => '2026-07-10']);

        $this->naEmpresa(fn () => $this->entregas->estornar($entrega, 'Substituição por item danificado.'));
        $this->registrar($epi, ['entregue_em' => '2026-07-11', 'motivo' => 'dano']);

        $binario = $this->naEmpresa(fn (): string => $this->fichas->gerarFichaEmPdf($this->tecnico)->output());

        $this->assertStringStartsWith('%PDF', $binario);
        $this->assertGreaterThan(1000, strlen($binario));
    }

    public function test_a_ficha_em_pdf_e_gerada_para_o_tecnico_sem_entrega_nenhuma(): void
    {
        $binario = $this->naEmpresa(fn (): string => $this->fichas->gerarFichaEmPdf($this->tecnico)->output());

        $this->assertStringStartsWith('%PDF', $binario);
    }

    public function test_o_nome_do_arquivo_identifica_o_tecnico(): void
    {
        $this->assertSame(
            'ficha-de-epi-tecnico-do-epi.pdf',
            $this->fichas->nomeDoArquivoDaFicha($this->tecnico)
        );
    }

    // -----------------------------------------------------------------
    // Extração por período: o CSV que se entrega ao fiscal
    // -----------------------------------------------------------------

    /**
     * Sem o BOM, "Óculos" e "Substituição" chegam corrompidos ao Excel em
     * português; com vírgula no lugar do ponto e vírgula, a planilha inteira cai
     * em uma coluna só. O arquivo perderia justamente a legibilidade que a
     * fiscalização exige.
     */
    public function test_o_csv_sai_com_bom_utf8_separador_ponto_e_virgula_e_acentuacao_correta(): void
    {
        $epi = $this->criarEpi(['nome' => 'Óculos de proteção', 'tipo' => 'oculos']);

        $this->registrar($epi, ['entregue_em' => '2026-07-10', 'motivo' => 'substituicao']);

        $csv = $this->extrair('2026-07-01', '2026-07-31');

        $this->assertStringStartsWith("\u{FEFF}", $csv);
        $this->assertTrue(mb_check_encoding($csv, 'UTF-8'), 'o CSV saiu fora de UTF-8');

        $cabecalho = $this->linhas($csv)[0];

        $this->assertSame(FichaDeEpiService::COLUNAS_DA_EXTRACAO, $cabecalho);
        $this->assertContains('Técnico', $cabecalho);
        $this->assertContains('Número do CA', $cabecalho);
        $this->assertContains('Situação', $cabecalho);

        $linha = $this->linhas($csv)[1];

        $this->assertSame('Óculos de proteção', $linha[3]);
        $this->assertSame('Óculos', $linha[4]);
        $this->assertSame('Substituição', $linha[9]);
    }

    public function test_o_csv_traz_uma_linha_por_entrega_no_periodo(): void
    {
        $epi = $this->criarEpi();
        $outroTecnico = $this->criarTecnico('Maria das Luvas');

        $this->registrar($epi, ['entregue_em' => '2026-07-05']);
        $this->registrar($epi, ['entregue_em' => '2026-07-06', 'motivo' => 'substituicao']);
        $this->naEmpresa(fn (): PpeDelivery => $this->entregas->registrar([
            'technician_id' => $outroTecnico->getKey(),
            'personal_protective_equipment_id' => $epi->getKey(),
            'entregue_em' => '2026-07-07',
        ]));

        $linhas = $this->linhas($this->extrair('2026-07-01', '2026-07-31'));

        // 3 entregas + o cabeçalho.
        $this->assertCount(4, $linhas);
        $this->assertSame(
            ['05/07/2026', '06/07/2026', '07/07/2026'],
            array_column(array_slice($linhas, 1), 2)
        );
    }

    /**
     * "De 1º a 31" inclui o dia 31. Recorte que exclui a ponta faz sumir a
     * entrega do último dia do mês, que é exatamente o pedido mais comum.
     */
    public function test_as_duas_pontas_do_periodo_entram_na_extracao(): void
    {
        $epi = $this->criarEpi();

        $this->registrar($epi, ['entregue_em' => '2026-07-01']);
        $this->registrar($epi, ['entregue_em' => '2026-07-15', 'motivo' => 'substituicao']);
        $this->registrar($epi, ['entregue_em' => '2026-07-31', 'motivo' => 'substituicao']);

        $todas = array_slice($this->linhas($this->extrair('2026-07-01', '2026-07-31')), 1);

        $this->assertSame(['01/07/2026', '15/07/2026', '31/07/2026'], array_column($todas, 2));

        // Contraprova: fora do recorte, as pontas somem.
        $miolo = array_slice($this->linhas($this->extrair('2026-07-02', '2026-07-30')), 1);

        $this->assertSame(['15/07/2026'], array_column($miolo, 2));
    }

    public function test_entrega_de_fora_do_periodo_nao_entra(): void
    {
        $epi = $this->criarEpi();

        $this->registrar($epi, ['entregue_em' => '2026-06-30']);
        $this->registrar($epi, ['entregue_em' => '2026-08-01', 'motivo' => 'substituicao']);

        $this->assertCount(1, $this->linhas($this->extrair('2026-07-01', '2026-07-31')));
    }

    /**
     * A entrega estornada permanece na extração, marcada e com o motivo. Tirá-la
     * seria apagar o rastro do próprio erro no documento entregue ao fiscal.
     */
    public function test_entrega_estornada_permanece_na_extracao_marcada_com_o_motivo(): void
    {
        $epi = $this->criarEpi();
        $entrega = $this->registrar($epi, ['entregue_em' => '2026-07-10']);

        $this->naEmpresa(fn () => $this->entregas->estornar($entrega, 'Lançada no técnico errado.'));

        $linhas = $this->linhas($this->extrair('2026-07-01', '2026-07-31'));

        $this->assertCount(2, $linhas, 'a entrega estornada sumiu da extração');

        $linha = $linhas[1];

        $this->assertStringStartsWith('Estornada em ', $linha[14]);
        $this->assertSame('Lançada no técnico errado.', $linha[15]);
    }

    public function test_entrega_valida_e_pendente_de_assinatura_saem_identificadas_na_extracao(): void
    {
        $epi = $this->criarEpi();

        $this->registrar($epi, ['entregue_em' => '2026-07-10']);

        $linha = $this->linhas($this->extrair('2026-07-01', '2026-07-31'))[1];

        $this->assertSame(FichaDeEpiService::PENDENTE_DE_ASSINATURA, $linha[13]);
        $this->assertSame('Válida', $linha[14]);
        $this->assertSame('—', $linha[15]);
    }

    /**
     * O CA da coluna é o copiado no ato da entrega, igual à ficha: renovar o
     * cadastro não reescreve a relação já extraída.
     */
    public function test_o_csv_imprime_o_ca_da_entrega_e_nao_o_do_cadastro(): void
    {
        $epi = $this->criarEpi(['numero_ca' => 'CA-ANTIGO', 'validade_ca' => '2026-09-30']);

        $this->registrar($epi, ['entregue_em' => '2026-07-10']);

        $this->naEmpresa(fn () => $this->cadastro->atualizar($epi, [
            'numero_ca' => 'CA-RENOVADO',
            'validade_ca' => '2030-09-30',
        ]));

        $linha = $this->linhas($this->extrair('2026-07-01', '2026-07-31'))[1];

        $this->assertSame('CA-ANTIGO', $linha[7]);
        $this->assertSame('30/09/2026', $linha[8]);
    }

    /**
     * As datas do CSV saem no fuso do negócio. O instante da assinatura é o caso
     * que denuncia a conversão errada: gravado às 01h30 UTC, ele pertence ao dia
     * anterior em Brasília, e imprimir o dia seguinte colocaria o técnico
     * assinando um recebimento que ainda não tinha acontecido.
     */
    public function test_as_datas_do_csv_saem_no_fuso_do_negocio(): void
    {
        $epi = $this->criarEpi(['validade_ca' => '2026-12-31', 'vida_util_dias' => 30]);

        $this->naEmpresa(fn (): PpeDelivery => PpeDeliveryFactory::new()
            ->entregueA($this->tecnico, $epi, '2026-07-15')
            ->assinada('2026-07-16 01:30:00')
            ->create());

        $linha = $this->linhas($this->extrair('2026-07-01', '2026-07-31'))[1];

        $this->assertSame('15/07/2026', $linha[2], 'o dia da entrega mudou por conversão de fuso');
        $this->assertSame('31/12/2026', $linha[8]);
        $this->assertSame('14/08/2026', $linha[10]);
        $this->assertSame('Assinada em 15/07/2026 às 22h30', $linha[13]);
    }

    public function test_a_extracao_identifica_o_tecnico_e_a_situacao_dele(): void
    {
        $epi = $this->criarEpi();

        $this->registrar($epi, ['entregue_em' => '2026-07-10']);
        $this->naEmpresa(fn () => $this->tecnico->update(['is_active' => false]));

        $linha = $this->linhas($this->extrair('2026-07-01', '2026-07-31'))[1];

        $this->assertSame('Técnico do EPI', $linha[0]);
        $this->assertSame('Inativo', $linha[1]);
    }

    public function test_a_extracao_registra_quem_lancou_a_entrega(): void
    {
        $epi = $this->criarEpi();

        $usuario = $this->naEmpresa(fn (): User => User::factory()->create([
            'name' => 'Almoxarife do EPI',
            'is_active' => true,
        ]));

        $this->registrar($epi, ['entregue_em' => '2026-07-10', 'user_id' => $usuario->getKey()]);

        $this->assertSame(
            'Almoxarife do EPI',
            $this->linhas($this->extrair('2026-07-01', '2026-07-31'))[1][16]
        );
    }

    /**
     * Motivo de estorno com ponto e vírgula não pode partir a linha em duas
     * colunas: quem escreve o motivo é uma pessoa, em texto livre.
     */
    public function test_texto_livre_com_o_separador_dentro_nao_quebra_a_linha(): void
    {
        $epi = $this->criarEpi();
        $entrega = $this->registrar($epi, ['entregue_em' => '2026-07-10']);

        $motivo = 'Técnico errado; a entrega certa é a de 11/07, com "aspas" no meio.';

        $this->naEmpresa(fn () => $this->entregas->estornar($entrega, $motivo));

        $linhas = $this->linhas($this->extrair('2026-07-01', '2026-07-31'));

        $this->assertCount(2, $linhas);
        $this->assertCount(count(FichaDeEpiService::COLUNAS_DA_EXTRACAO), $linhas[1]);
        $this->assertSame($motivo, $linhas[1][15]);
    }

    /**
     * Rede de segurança do Service, fora do HTTP: período invertido devolveria
     * um arquivo vazio, e arquivo vazio entregue ao fiscal parece ausência de
     * entregas.
     */
    public function test_periodo_invertido_e_recusado_pelo_service(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->extrair('2026-07-31', '2026-07-01');
    }

    public function test_o_nome_do_arquivo_da_extracao_carrega_o_periodo(): void
    {
        $this->assertSame(
            'entregas-de-epi-2026-07-01-a-2026-07-31.csv',
            $this->fichas->nomeDoArquivoDaExtracao('2026-07-01', '2026-07-31')
        );
    }

    /**
     * Extração é da empresa do contexto, e só. A pior falha possível deste
     * sistema é a relação de um tenant trazendo o técnico de um concorrente.
     */
    public function test_a_extracao_nao_traz_entrega_de_outra_empresa(): void
    {
        $this->registrar($this->criarEpi(), ['entregue_em' => '2026-07-10']);

        $outra = Company::create([
            'name' => 'Concorrente da Extração',
            'cnpj' => '88.888.888/0001-88',
            'email' => 'contato@concorrente-extracao.test',
        ]);

        TenantAtual::comTenant($outra->getKey(), function (): void {
            $tecnico = Technician::create([
                'name' => 'Técnico da concorrente',
                'email' => 'tecnico-concorrente-extracao@concorrente.test',
                'phone' => '11977776666',
                'is_active' => true,
            ]);

            $epi = PersonalProtectiveEquipmentFactory::new()->create();

            PpeDeliveryFactory::new()->entregueA($tecnico, $epi, '2026-07-11')->create();
        });

        $linhas = $this->linhas($this->extrair('2026-07-01', '2026-07-31'));

        $this->assertCount(2, $linhas);
        $this->assertSame('Técnico do EPI', $linhas[1][0]);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function naEmpresa(Closure $callback): mixed
    {
        return TenantAtual::comTenant($this->empresa->getKey(), $callback);
    }

    /**
     * @return array<string, mixed>
     */
    private function ficha(): array
    {
        return $this->naEmpresa(fn (): array => $this->fichas->dadosDaFicha($this->tecnico));
    }

    private function extrair(string $inicio, string $fim): string
    {
        return $this->naEmpresa(fn (): string => $this->fichas->montarCsvDeEntregas($inicio, $fim));
    }

    /**
     * O CSV desmontado em linhas e colunas, sem o BOM.
     *
     * A leitura usa o mesmo separador e a mesma ausência de escape da escrita:
     * conferir o arquivo com outro dialeto provaria o dialeto do teste, não o do
     * arquivo entregue.
     *
     * @return array<int, array<int, string>>
     */
    private function linhas(string $csv): array
    {
        $conteudo = str_replace("\u{FEFF}", '', $csv);

        $linhas = [];

        foreach (explode("\r\n", rtrim($conteudo, "\r\n")) as $linha) {
            if ($linha === '') {
                continue;
            }

            $linhas[] = str_getcsv($linha, ';', '"', '');
        }

        return $linhas;
    }

    private function criarTecnico(string $nome = 'Técnico do EPI'): Technician
    {
        return $this->naEmpresa(fn (): Technician => Technician::create([
            'name' => $nome,
            'email' => 'tecnico-ficha-'.uniqid().'@dedetizadora.test',
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
}
