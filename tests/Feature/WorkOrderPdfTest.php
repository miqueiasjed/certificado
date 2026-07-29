<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\WorkOrder;
use App\Models\WorkOrderSignature;
use App\Services\WorkOrderService;
use App\Support\TenantAtual;
use Barryvdh\DomPDF\Facade\Pdf as FacadePdf;
use Carbon\Carbon;
use Database\Factories\WorkOrderFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Task 13.5 do Plano 13: assinatura do cliente coletada em campo, ao lado da
 * assinatura do responsável técnico da empresa, no PDF da OS.
 *
 * Cobre os três estados de `situacao_assinatura` no bloco de assinaturas de
 * `pdf/work-order.blade.php`: sem assinatura (precisa continuar idêntico ao
 * que já existia antes desta task), assinada e recusada. Cada teste também
 * gera o PDF de verdade (`FacadePdf::loadView(...)->output()`), não só o
 * HTML, porque o critério de aceitação pede o documento gerado sem erro.
 */
class WorkOrderPdfTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BASE64_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private Company $empresa;

    private WorkOrderService $service;

    /**
     * Caminhos reais gravados em storage/app/public durante o teste, para
     * limpar no tearDown.
     *
     * `WorkOrderService::convertStorageFileToBase64()` lê o arquivo direto de
     * `storage_path('app/public/...')`, sem passar pela abstração de disco do
     * Storage facade (é o mesmo padrão já usado para a assinatura do
     * responsável técnico da empresa), então `Storage::fake()` não tem efeito
     * aqui: o arquivo precisa existir de verdade para o teste da OS assinada.
     *
     * @var array<int, string>
     */
    private array $arquivosCriados = [];

    protected function setUp(): void
    {
        parent::setUp();

        TenantAtual::limpar();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->empresa = Company::query()->firstOrFail();
        $this->service = app(WorkOrderService::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->arquivosCriados as $caminho) {
            if (file_exists($caminho)) {
                unlink($caminho);
            }
        }

        TenantAtual::limpar();

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Sem assinatura: nada pode mudar
    // -----------------------------------------------------------------

    public function test_os_sem_assinatura_mantem_o_bloco_do_cliente_identico_ao_atual(): void
    {
        $ordem = $this->criarOrdem();

        $html = $this->renderizarPdf($ordem);

        // Exatamente o que o bloco do cliente já mostrava antes desta task.
        $this->assertStringContainsString('Responsável pelo Local', $html);
        $this->assertStringContainsString('Nome e CPF', $html);

        // Nada do vocabulário novo (assinada/recusada/coordenada) pode vazar
        // para o documento de uma OS sem assinatura coletada.
        $this->assertStringNotContainsString('Assinado em', $html);
        $this->assertStringNotContainsString('recusada pelo cliente', $html);
        $this->assertStringNotContainsString('Coleta registrada em', $html);

        // Task 13.10: além das checagens de presença/ausência acima (que já
        // pegam troca de palavra ou vazamento do vocabulário novo), o bloco
        // do cliente sem assinatura é comparado, normalizado, com um arquivo
        // de referência versionado em tests/fixtures/pdf/. Esse bloco é o
        // único do rodapé sem nenhum dado dinâmico (nome, data, empresa), o
        // que torna a comparação exata estável entre execuções, e protege
        // contra uma mudança estrutural (markup, ordem dos campos) que as
        // asserções de string acima não pegariam. Mudança de layout em
        // documento emitido para o cliente exige atenção (CLAUDE.md): se a
        // mudança for intencional, atualize também o arquivo de referência.
        $this->assertSame(
            $this->normalizarBlocoDeAssinatura(
                file_get_contents(base_path('tests/fixtures/pdf/bloco-cliente-sem-assinatura.html'))
            ),
            $this->normalizarBlocoDeAssinatura($this->extrairBlocoDeAssinaturaDoCliente($html)),
            'o bloco de assinatura do cliente (OS sem assinatura) mudou em relação à referência '
                .'versionada em tests/fixtures/pdf/bloco-cliente-sem-assinatura.html'
        );

        $this->assertGeraPdfSemErro($ordem);
    }

    // -----------------------------------------------------------------
    // Assinada: imagem, nome, documento, vínculo, data e hora
    // -----------------------------------------------------------------

    public function test_os_assinada_mostra_imagem_nome_documento_vinculo_data_e_hora(): void
    {
        $ordem = $this->criarOrdem(['situacao_assinatura' => 'assinada']);
        $caminhoImagem = $this->gravarImagemDeAssinaturaReal($ordem);

        TenantAtual::comTenant($this->empresa->id, function () use ($ordem, $caminhoImagem): void {
            WorkOrderSignature::create([
                'work_order_id' => $ordem->id,
                'assinante_nome' => 'Maria da Silva',
                'assinante_documento' => '123.456.789-00',
                'assinante_vinculo' => 'Síndica do condomínio',
                'imagem_path' => $caminhoImagem,
                // 17:32 UTC = 14:32 em America/Sao_Paulo (UTC-3, sem horário
                // de verão desde 2019): confere que a hora exibida é a de
                // Brasília, não a UTC gravada.
                'coletada_em' => Carbon::create(2026, 7, 24, 17, 32, 0, 'UTC'),
                'latitude' => -23.5505,
                'longitude' => -46.6333,
                'precisao_metros' => 12,
                'origem' => 'aplicativo',
            ]);
        });

        $html = $this->renderizarPdf($ordem->fresh());

        $this->assertStringContainsString('Maria da Silva', $html);
        $this->assertStringContainsString('123.456.789-00', $html);
        $this->assertStringContainsString('Síndica do condomínio', $html);
        $this->assertStringContainsString('Assinado em 24/07/2026 às 14h32', $html);
        $this->assertStringContainsString('client-signature-image', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);

        // Coordenada discreta no rodapé, com a precisão informada.
        $this->assertStringContainsString('Coleta registrada em -23.5505, -46.6333 (precisão de 12 m)', $html);

        $this->assertGeraPdfSemErro($ordem->fresh());
    }

    // -----------------------------------------------------------------
    // Recusada: texto de recusa e motivo
    // -----------------------------------------------------------------

    public function test_os_recusada_mostra_o_texto_de_recusa_e_o_motivo(): void
    {
        $ordem = $this->criarOrdem([
            'situacao_assinatura' => 'recusada',
            'recusa_motivo' => 'Cliente alegou não ter tempo no momento da visita.',
            // 13:00 UTC = 10:00 em America/Sao_Paulo
            'recusa_registrada_em' => Carbon::create(2026, 7, 24, 13, 0, 0, 'UTC'),
        ]);

        $html = $this->renderizarPdf($ordem);

        $this->assertStringContainsString('Assinatura recusada pelo cliente em 24/07/2026', $html);
        $this->assertStringContainsString('Cliente alegou não ter tempo no momento da visita.', $html);
        $this->assertStringNotContainsString('Responsável pelo Local', $html);

        $this->assertGeraPdfSemErro($ordem);
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    private function criarOrdem(array $atributos = []): WorkOrder
    {
        return TenantAtual::comTenant(
            $this->empresa->id,
            fn () => WorkOrderFactory::new()->create(array_merge(['status' => 'completed'], $atributos))
        );
    }

    private function renderizarPdf(WorkOrder $ordem): string
    {
        return TenantAtual::comTenant($this->empresa->id, function () use ($ordem) {
            $dados = $this->service->preparePdfData($ordem);

            return View::make('pdf.work-order', $dados)->render();
        });
    }

    /**
     * Gera o PDF de verdade com o DomPDF (não só a view Blade): se algo no
     * template quebrar a renderização, a exceção estoura aqui.
     */
    private function assertGeraPdfSemErro(WorkOrder $ordem): void
    {
        TenantAtual::comTenant($this->empresa->id, function () use ($ordem): void {
            $dados = $this->service->preparePdfData($ordem);

            $conteudo = FacadePdf::loadView('pdf.work-order', $dados)->output();

            $this->assertNotEmpty($conteudo);
            $this->assertStringStartsWith('%PDF', $conteudo);
        });
    }

    /**
     * Isola o terceiro `signature-box` do HTML renderizado (o bloco do
     * cliente): os dois primeiros são fixos (gerente operacional e
     * responsável técnico da empresa), e o do cliente é sempre o último dos
     * três, sem `div` aninhada da mesma classe dentro dele.
     *
     * Primeiro recorta a seção inteira de assinaturas pelos comentários HTML
     * `<!-- Assinaturas -->` e `<!-- Rodapé -->` do template (cada um
     * aparece uma única vez, e atravessa o Blade sem alteração): dentro
     * desse recorte, curto e isolado do resto do documento, localiza a
     * última tag de abertura `signature-box` e retira, de trás para frente,
     * só os dois fechamentos externos que encerram a própria caixa e a
     * seção inteira (mais o parágrafo de coordenadas, quando presente).
     * Ancorar a remoção no fim de um recorte já pequeno evita o risco de um
     * `preg_match` genérico casar com um par de `</div>` de outra parte do
     * documento, bem mais longo.
     */
    private function extrairBlocoDeAssinaturaDoCliente(string $html): string
    {
        $posicaoInicioSecao = strpos($html, '<!-- Assinaturas -->');
        $posicaoFimSecao = strpos($html, '<!-- Rodapé -->');

        $this->assertNotFalse($posicaoInicioSecao, 'o comentário "Assinaturas" não foi encontrado no HTML gerado');
        $this->assertNotFalse($posicaoFimSecao, 'o comentário "Rodapé" não foi encontrado no HTML gerado');

        $secaoDeAssinaturas = substr($html, $posicaoInicioSecao, $posicaoFimSecao - $posicaoInicioSecao);

        $marcadorAbertura = '<div class="signature-box">';
        $posicaoDaCaixa = strripos($secaoDeAssinaturas, $marcadorAbertura);

        $this->assertNotFalse($posicaoDaCaixa, 'o bloco de assinatura do cliente não foi encontrado no HTML gerado');

        $restante = substr($secaoDeAssinaturas, $posicaoDaCaixa + strlen($marcadorAbertura));

        return preg_replace(
            '/\s*<\/div>\s*<\/div>\s*(<p[^>]*class="sig-coleta-local"[^>]*>.*<\/p>)?\s*$/s',
            '',
            $restante
        );
    }

    /**
     * Colapsa espaços/quebras de linha em um único espaço antes de comparar:
     * a indentação do Blade renderizado não é parte do critério, só o
     * markup e o texto em si.
     */
    private function normalizarBlocoDeAssinatura(string $bloco): string
    {
        return trim(preg_replace('/\s+/', ' ', $bloco));
    }

    private function gravarImagemDeAssinaturaReal(WorkOrder $ordem): string
    {
        $caminhoRelativo = 'work-orders/'.$ordem->id.'/signatures/teste-task-13-5.png';
        $caminhoAbsoluto = storage_path('app/public/'.$caminhoRelativo);

        if (! is_dir(dirname($caminhoAbsoluto))) {
            mkdir(dirname($caminhoAbsoluto), 0777, true);
        }

        file_put_contents($caminhoAbsoluto, base64_decode(self::PNG_BASE64_1X1));

        $this->arquivosCriados[] = $caminhoAbsoluto;

        return $caminhoRelativo;
    }
}
