<?php

namespace App\Services\Monitoring;

use App\Models\Company;
use App\Models\Device;
use App\Models\DevicePosition;
use App\Models\FloorPlan;
use App\Models\MonitoringReport;
use App\Models\PestSighting;
use App\Services\FloorPlanService;
use Barryvdh\DomPDF\Facade\Pdf as FacadePdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * PDF definitivo do relatório de monitoramento e do croqui da planta (Plano
 * 21, Task 21.6). Substitui os provisórios da Task 21.5
 * (`RelatorioPdfSimplesService`/`monitoring-report-simples.blade.php` e
 * `floor-plan-simples.blade.php`), que existiam só para as rotas não ficarem
 * quebradas antes desta task.
 *
 * Não recalcula nada, mesma regra do provisório que substitui
 * -----------------------------------------------------------------------
 * `dados()` só lê `MonitoringReport::$dados`, o consolidado já congelado por
 * `ConsolidadorDePeriodo` no momento da geração. Nenhum service de
 * consolidação (`TendenciaService`, `PontosCriticosService`,
 * `MapaDeCalorService`, `OcorrenciaPorEspecieService`) é chamado de novo
 * aqui. A soma por período que `graficoEvolucaoMensal()` faz é aritmética de
 * apresentação sobre número já congelado (somar capturas por dispositivo
 * dentro do mesmo período, para caber num gráfico só), não uma nova regra de
 * negócio.
 *
 * Duas exceções deliberadas e documentadas em que este service consulta o
 * banco:
 * - `codigo_publico` do dispositivo, na versão técnica do croqui: o código é
 *   imutável depois de gravado (`Device::booted()`, "Código já informado
 *   nunca é sobrescrito: o código é imutável depois de impresso na
 *   etiqueta"), então buscar o valor atual não muda o retrato histórico.
 * - O arquivo da planta (`FloorPlan::arquivo_path`), pela mesma razão:
 *   `dados.mapa_de_calor[].planta.floor_plan_id` já é a versão congelada no
 *   momento da geração (Task 21.1: versão antiga nunca é sobrescrita, trocar
 *   de planta cria uma linha nova), então carregar essa linha por id é ler a
 *   mesma versão, nunca a planta ativa de hoje.
 *
 * Croqui técnico x cliente
 * -----------------------------------------------------------------------
 * Só o croqui distingue as duas versões (rótulo completo e código público do
 * dispositivo x numeração anônima e estado do ponto no período). O resto do
 * relatório (ranking, mapa de calor, ocorrência por espécie) é igual nas duas
 * versões: `PortalRelatorioController::paraPortal()` já expõe hoje o mesmo
 * `dados` (com rótulo e código de dispositivo) para o cliente no portal, sem
 * nenhuma filtragem, porque nenhuma dessas chaves carrega dado sensível (ver
 * o docblock daquele controller). O croqui é diferente porque a task pede
 * explicitamente uma versão sem o código do dispositivo.
 *
 * Gráficos SVG, sem JavaScript, e sempre como fonte de `<img>`
 * -----------------------------------------------------------------------
 * A geometria (posição e tamanho de cada barra/marcador) é calculada aqui em
 * PHP. O dompdf usado neste projeto, conferido gerando os dois formatos e
 * comparando o PDF resultante, só desenha SVG quando ele chega como fonte de
 * imagem (`<img src="data:image/svg+xml;base64,...">`); um `<svg>` solto no
 * meio do HTML não é desenhado - só o texto dentro dele aparece, como texto
 * comum, sem nenhuma forma. Por isso todo gráfico (`svgEvolucao()`,
 * `svgMapaDeCalor()`, `svgCroqui()`) monta a marcação SVG completa como
 * string em PHP, embute como `data:image/svg+xml;base64` via
 * `svgParaDataUri()`, e a view só faz `<img src="...">`. Nenhum gráfico
 * depende de JavaScript, porque o dompdf não o executa.
 *
 * Sem visita nunca é uma barra de valor zero
 * -----------------------------------------------------------------------
 * Um período em que nenhum dispositivo do endereço teve evento é marcado
 * `sem_visita: true` na barra e desenhado com padrão hachurado, nunca uma
 * barra baixa igual à de "visitado, zero captura" - a mesma distinção que
 * `TendenciaService::evolucaoPorDispositivo()` já preserva no dado bruto.
 *
 * Isolamento por empresa
 * -----------------------------------------------------------------------
 * As duas consultas extras (`Device::whereIn('id', ...)` e
 * `FloorPlan::find()`) usam `BelongsToCompany`: só enxergam linha da empresa
 * corrente. Isso é suficiente porque quem chama este service sempre resolveu
 * `$relatorio`/`$planta` já escopados pela empresa (route model binding ou
 * consulta filtrada), mesmo padrão do resto do Plano 21.
 */
class RelatorioPdfService
{
    public const VERSAO_TECNICO = 'tecnico';

    public const VERSAO_CLIENTE = 'cliente';

    /**
     * @var array<int, string>
     */
    private const VERSOES_VALIDAS = [self::VERSAO_TECNICO, self::VERSAO_CLIENTE];

    private const GRAFICO_LARGURA_MM = 170.0;

    private const GRAFICO_ALTURA_MM = 68.0;

    private const GRAFICO_MARGEM_ESQUERDA_MM = 14.0;

    private const GRAFICO_MARGEM_INFERIOR_MM = 12.0;

    private const GRAFICO_MARGEM_SUPERIOR_MM = 8.0;

    private const MAPA_CALOR_LARGURA_BARRA_MM = 90.0;

    /**
     * Monta o PDF do relatório de monitoramento e devolve o objeto dompdf
     * pronto para `->download()`/`->stream()`. `$versaoCroqui` decide qual
     * versão do croqui embutido sai no documento; o resto do conteúdo é
     * idêntico nas duas.
     */
    public function relatorio(MonitoringReport $relatorio, string $versaoCroqui = self::VERSAO_TECNICO): DomPdf
    {
        return FacadePdf::loadView('pdf.monitoring-report', $this->dados($relatorio, $versaoCroqui))
            ->setPaper('a4', 'portrait');
    }

    /**
     * Monta o PDF do croqui de uma planta, fora do contexto de um relatório
     * (rota `GET /plantas/{floorPlan}/croqui`): usa a versão da planta que a
     * URL pediu (route model binding já resolve isso) e a posição ATUAL de
     * cada dispositivo, sem número de captura de período nenhum - não há
     * período aqui, só o layout.
     */
    public function croqui(FloorPlan $planta, string $versao = self::VERSAO_TECNICO): DomPdf
    {
        return FacadePdf::loadView('pdf.floor-plan', $this->dadosDoCroqui($planta, $versao))
            ->setPaper('a4', 'landscape');
    }

    /**
     * Dados para `pdf.monitoring-report`, expostos publicamente para o teste
     * conferir a estrutura sem precisar abrir o PDF binário (mesmo padrão de
     * `RelatorioPdfSimplesService::dados()`, que esta classe substitui).
     *
     * @return array<string, mixed>
     */
    public function dados(MonitoringReport $relatorio, string $versaoCroqui = self::VERSAO_TECNICO): array
    {
        $this->garantirVersaoValida($versaoCroqui);

        $relatorio->loadMissing([
            'client:id,name,cnpj',
            'address:id,nickname,street,number,district,city,state',
            'geradoPor:id,name',
        ]);

        $dados = $relatorio->dados ?? [];
        $company = Company::current();

        return [
            'company' => $company,
            'logoSrc' => $this->imagemEmBase64(Storage::disk('public'), $company->logo_path),
            'relatorio' => $relatorio,
            'cliente' => $relatorio->client,
            'enderecoUnico' => $relatorio->address,
            'periodo' => [
                'de' => $relatorio->periodo_inicio,
                'ate' => $relatorio->periodo_fim,
            ],
            'geradoEm' => $relatorio->gerado_em,
            'geradoPor' => $relatorio->geradoPor?->name,
            'resumo' => $this->resumoDoPeriodo($dados),
            'porEndereco' => $this->montarPorEndereco($dados, $versaoCroqui),
            'adequacoes' => data_get($dados, 'adequacoes', []),
            'versaoCroqui' => $versaoCroqui,
        ];
    }

    /**
     * Dados para `pdf.floor-plan`, fora de um relatório: posição atual de
     * cada dispositivo, ordenada por `device_id` para a numeração do croqui
     * sair estável entre uma geração e outra da mesma planta.
     *
     * @return array<string, mixed>
     */
    public function dadosDoCroqui(FloorPlan $planta, string $versao = self::VERSAO_TECNICO): array
    {
        $this->garantirVersaoValida($versao);

        $planta->loadMissing([
            'address:id,nickname,street,number,district,city,state',
            'devicePositions.device:id,label,number,codigo_publico',
        ]);

        $posicoes = $planta->devicePositions->sortBy('device_id')->values();

        $pontos = $posicoes
            ->map(fn (DevicePosition $posicao, int $indice): array => $this->normalizarPontoCroqui(
                numero: $indice + 1,
                rotulo: $posicao->device?->full_label,
                codigoPublico: $posicao->device?->codigo_publico,
                estado: null,
                versao: $versao,
            ))
            ->all();

        $pontosXY = $posicoes
            ->map(fn (DevicePosition $posicao, int $indice): array => [
                'numero' => $indice + 1,
                'x' => (float) $posicao->x,
                'y' => (float) $posicao->y,
            ])
            ->all();

        $company = Company::current();

        return [
            'company' => $company,
            'logoSrc' => $this->imagemEmBase64(Storage::disk('public'), $company->logo_path),
            'endereco' => $planta->address,
            'croqui' => [
                'planta_nome' => $planta->nome,
                'planta_versao' => $planta->versao,
                'periodo_label' => null,
                'versao' => $versao,
                'aspecto' => $this->aspectoDaImagem($planta->largura_px, $planta->altura_px),
                'pontos' => $pontos,
                'imagem_svg' => $this->svgCroqui(
                    $this->imagemEmBase64(Storage::disk(FloorPlanService::DISCO), $planta->arquivo_path),
                    $planta->largura_px,
                    $planta->altura_px,
                    $pontosXY
                ),
            ],
        ];
    }

    /**
     * Um item por endereço do retrato (mesma ordem de `dados.tendencia`),
     * juntando por `address_id` o que `ranking_pontos_criticos`,
     * `mapa_de_calor` e `ocorrencia_por_especie` trazem para aquele endereço.
     * Junta por id, e não por posição na lista, porque nada garante às cegas
     * que as quatro listas preservem exatamente a mesma ordem para sempre.
     *
     * @param  array<string, mixed>  $dados
     * @return list<array<string, mixed>>
     */
    private function montarPorEndereco(array $dados, string $versaoCroqui): array
    {
        $tendencias = collect(data_get($dados, 'tendencia', []));
        $rankingPorEndereco = collect(data_get($dados, 'ranking_pontos_criticos', []))->keyBy('address_id');
        $mapaPorEndereco = collect(data_get($dados, 'mapa_de_calor', []))->keyBy('address_id');
        $especiePorEndereco = collect(data_get($dados, 'ocorrencia_por_especie', []))->keyBy('address_id');
        $visitasPorEndereco = collect(data_get($dados, 'visitas.datas', []))->countBy('address_id');

        return $tendencias
            ->map(function (array $tendencia) use (
                $rankingPorEndereco,
                $mapaPorEndereco,
                $especiePorEndereco,
                $visitasPorEndereco,
                $versaoCroqui
            ): array {
                $addressId = $tendencia['address_id'];

                $mapaDeCalor = $mapaPorEndereco->get($addressId, [
                    'suportado' => false,
                    'motivo_nao_suportado' => 'Nenhum dado de mapa de calor apurado para este endereço.',
                    'planta' => null,
                    'escala_absoluta_maxima' => 0,
                    'pontos' => [],
                ]);

                $ranking = $rankingPorEndereco->get($addressId, ['dispositivos' => []]);
                $especies = $especiePorEndereco->get($addressId, ['evolucao_por_especie' => []]);

                return [
                    'address_id' => $addressId,
                    'endereco' => $tendencia['endereco'],
                    'visitas' => (int) ($visitasPorEndereco->get($addressId) ?? 0),
                    'grafico_evolucao' => $this->graficoEvolucaoMensal($tendencia['evolucao_mensal'] ?? []),
                    'ranking_dispositivos' => $ranking['dispositivos'] ?? [],
                    'mapa_de_calor' => $this->barrasMapaDeCalor($mapaDeCalor),
                    'especies' => $this->especiesTraduzidas($especies['evolucao_por_especie'] ?? []),
                    'croqui' => $this->croquiCongeladoDoEndereco($mapaDeCalor, $versaoCroqui),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Visitas, ocorrências no período e a variação contra o período anterior.
     * A fonte da contagem de ocorrência é `ocorrencia_por_especie`
     * (avistamento estruturado por espécie), não `ranking_pontos_criticos`
     * (captura por dispositivo): são definições diferentes e cada seção do
     * PDF fica fiel à sua própria fonte, sem misturar as duas num total só.
     *
     * @param  array<string, mixed>  $dados
     * @return array{visitas: int, ocorrencias_atual: int, ocorrencias_anterior: int, percentual: float|null, a_partir_de_zero: bool}
     */
    private function resumoDoPeriodo(array $dados): array
    {
        $especiesPorEndereco = collect(data_get($dados, 'ocorrencia_por_especie', []))
            ->flatMap(fn (array $endereco): array => $endereco['evolucao_por_especie'] ?? []);

        $atual = (int) $especiesPorEndereco->sum('para');
        $anterior = (int) $especiesPorEndereco->sum('de');

        return [
            'visitas' => (int) data_get($dados, 'visitas.quantidade', 0),
            'ocorrencias_atual' => $atual,
            'ocorrencias_anterior' => $anterior,
            ...$this->calcularVariacaoSimples($anterior, $atual),
        ];
    }

    /**
     * Mesma fórmula de `OcorrenciaPorEspecieService::calcularVariacao()` e de
     * `TendenciaService::calcularVariacao()`: total anterior zero não produz
     * percentual (divisão por zero), e `a_partir_de_zero` sinaliza quando
     * houve crescimento de 0 para N. Duplicada aqui de propósito porque é
     * aritmética de apresentação sobre número já somado neste service, não
     * uma chamada a outro service de consolidação.
     *
     * @return array{percentual: float|null, a_partir_de_zero: bool}
     */
    private function calcularVariacaoSimples(int $anterior, int $atual): array
    {
        if ($anterior === 0) {
            return [
                'percentual' => $atual === 0 ? 0.0 : null,
                'a_partir_de_zero' => $atual > 0,
            ];
        }

        return [
            'percentual' => round((($atual - $anterior) / $anterior) * 100, 2),
            'a_partir_de_zero' => false,
        ];
    }

    /**
     * Geometria (em mm) do gráfico de barras "evolução no período", uma
     * barra por mês de `evolucao_mensal`. Cada barra soma `capturas` de
     * todos os dispositivos daquele mês; o mês sai `sem_visita: true` quando
     * a soma de `visitas` de todos os dispositivos é zero (nenhum
     * dispositivo teve qualquer evento no mês) - só então a barra vira
     * hachura em vez de altura, para nunca se confundir com "visitado, zero
     * captura".
     *
     * @param  array<string, mixed>  $evolucaoMensal
     * @return array{tem_dados: bool, largura: float, altura: float, linha_base: float, maximo: int, barras: list<array<string, mixed>>}
     */
    private function graficoEvolucaoMensal(array $evolucaoMensal): array
    {
        $periodos = $evolucaoMensal['periodos'] ?? [];
        $dispositivos = $evolucaoMensal['dispositivos'] ?? [];

        $moldura = [
            'largura' => self::GRAFICO_LARGURA_MM,
            'altura' => self::GRAFICO_ALTURA_MM,
        ];

        if ($periodos === [] || $dispositivos === []) {
            return [...$moldura, 'tem_dados' => false, 'linha_base' => 0.0, 'maximo' => 0, 'barras' => []];
        }

        $totaisPorPeriodo = [];

        foreach ($periodos as $indice => $periodo) {
            $totalVisitas = 0;
            $totalCapturas = 0;

            foreach ($dispositivos as $dispositivo) {
                $ponto = $dispositivo['serie'][$indice] ?? null;

                if ($ponto === null) {
                    continue;
                }

                $totalVisitas += (int) ($ponto['visitas'] ?? 0);
                $totalCapturas += (int) ($ponto['capturas'] ?? 0);
            }

            $totaisPorPeriodo[] = [
                'chave' => $periodo['chave'],
                'sem_visita' => $totalVisitas === 0,
                'capturas' => $totalCapturas,
            ];
        }

        $maximo = max(1, ...array_column($totaisPorPeriodo, 'capturas'));
        $quantidade = count($totaisPorPeriodo);

        $larguraUtil = self::GRAFICO_LARGURA_MM - self::GRAFICO_MARGEM_ESQUERDA_MM - 4.0;
        $alturaUtil = self::GRAFICO_ALTURA_MM - self::GRAFICO_MARGEM_INFERIOR_MM - self::GRAFICO_MARGEM_SUPERIOR_MM;
        $larguraFaixa = $larguraUtil / $quantidade;
        $linhaBase = self::GRAFICO_MARGEM_SUPERIOR_MM + $alturaUtil;

        $barras = [];

        foreach ($totaisPorPeriodo as $indice => $item) {
            // Barra "sem visita" sai numa altura fixa e baixa, só para a
            // hachura ter onde ser desenhada - não representa magnitude
            // nenhuma, ao contrário da barra normal.
            $altura = $item['sem_visita']
                ? $alturaUtil * 0.14
                : max(($item['capturas'] / $maximo) * $alturaUtil, $item['capturas'] > 0 ? 1.5 : 0.0);

            $barras[] = [
                'x' => round(self::GRAFICO_MARGEM_ESQUERDA_MM + $indice * $larguraFaixa + $larguraFaixa * 0.15, 2),
                'y' => round($linhaBase - $altura, 2),
                'largura' => round($larguraFaixa * 0.7, 2),
                'altura' => round($altura, 2),
                'sem_visita' => $item['sem_visita'],
                'valor' => $item['capturas'],
                'rotulo_eixo' => $item['chave'],
                'rotulo_x' => round(self::GRAFICO_MARGEM_ESQUERDA_MM + $indice * $larguraFaixa + $larguraFaixa / 2, 2),
            ];
        }

        return [
            ...$moldura,
            'tem_dados' => true,
            'linha_base' => round($linhaBase, 2),
            'maximo' => $maximo,
            'barras' => $barras,
            'imagem' => $this->svgEvolucao($barras, $linhaBase, self::GRAFICO_LARGURA_MM),
        ];
    }

    /**
     * Monta o SVG do gráfico de evolução como uma imagem só (`data:image/svg+xml`):
     * o dompdf usado neste projeto só desenha SVG quando ele chega como fonte
     * de `<img>`/`background-image`, nunca como marcação `<svg>` inline no meio
     * do HTML (confirmado renderizando as duas formas e comparando o PDF
     * gerado) - por isso todo gráfico deste service sai como imagem embutida,
     * nunca como tag solta na view.
     *
     * @param  list<array<string, mixed>>  $barras
     */
    private function svgEvolucao(array $barras, float $linhaBase, float $larguraTotal): string
    {
        $conteudo = sprintf(
            '<line x1="%s" y1="%s" x2="%s" y2="%s" stroke="#c3c2b7" stroke-width="0.3" />',
            self::GRAFICO_MARGEM_ESQUERDA_MM - 2, $this->fmt($linhaBase), $this->fmt($larguraTotal - 2), $this->fmt($linhaBase)
        );

        foreach ($barras as $barra) {
            $cor = $barra['sem_visita'] ? '#9CA3AF' : '#059669';

            $conteudo .= sprintf(
                '<rect x="%s" y="%s" width="%s" height="%s" fill="%s" />',
                $this->fmt($barra['x']), $this->fmt($barra['y']), $this->fmt($barra['largura']), $this->fmt(max($barra['altura'], 0.4)), $cor
            );

            if (! $barra['sem_visita']) {
                $conteudo .= sprintf(
                    '<text x="%s" y="%s" font-size="2.6" text-anchor="middle" fill="#0b0b0b">%s</text>',
                    $this->fmt($barra['rotulo_x']), $this->fmt(max($barra['y'] - 1.2, 3)), (int) $barra['valor']
                );
            }

            $conteudo .= sprintf(
                '<text x="%s" y="%s" font-size="2.2" text-anchor="middle" fill="#898781">%s</text>',
                $this->fmt($barra['rotulo_x']), $this->fmt($linhaBase + 4), $this->escaparXml((string) $barra['rotulo_eixo'])
            );

            if ($barra['sem_visita']) {
                $conteudo .= sprintf(
                    '<text x="%s" y="%s" font-size="2.1" text-anchor="middle" fill="#52514e">SEM VISITA</text>',
                    $this->fmt($barra['rotulo_x']), $this->fmt($linhaBase + 7.4)
                );
            }
        }

        return $this->svgParaDataUri($conteudo, self::GRAFICO_LARGURA_MM, self::GRAFICO_ALTURA_MM);
    }

    /**
     * Geometria (em mm) das barras horizontais do mapa de calor por área:
     * uma barra por ponto, comprimento proporcional à `intensidade`
     * normalizada (0 a 1), com `valor_absoluto` e `intensidade_percentual`
     * impressos ao lado - a escala absoluta nunca desacompanhada da
     * normalizada, regra da Task 21.3.
     *
     * @param  array<string, mixed>  $mapaDeCalor
     * @return array{suportado: bool, motivo: string|null, largura_maxima: float, maximo: int, barras: list<array<string, mixed>>}
     */
    private function barrasMapaDeCalor(array $mapaDeCalor): array
    {
        if (! ($mapaDeCalor['suportado'] ?? false)) {
            return [
                'suportado' => false,
                'motivo' => $mapaDeCalor['motivo_nao_suportado'] ?? 'Mapa de calor não disponível para este endereço.',
                'largura_maxima' => self::MAPA_CALOR_LARGURA_BARRA_MM,
                'maximo' => 0,
                'barras' => [],
            ];
        }

        $barras = collect($mapaDeCalor['pontos'] ?? [])
            ->map(function (array $ponto): array {
                $intensidade = (float) ($ponto['intensidade'] ?? 0.0);

                return [
                    'rotulo' => $ponto['rotulo'] ?? '-',
                    'valor_absoluto' => (int) ($ponto['valor_absoluto'] ?? 0),
                    'intensidade_percentual' => (int) round($intensidade * 100),
                    'largura_preenchida' => round($intensidade * self::MAPA_CALOR_LARGURA_BARRA_MM, 2),
                ];
            })
            ->sortByDesc('valor_absoluto')
            ->values()
            ->all();

        $alturaTotal = max(count($barras), 1) * 7.0;
        $larguraTotal = self::MAPA_CALOR_LARGURA_BARRA_MM + 60.0;

        return [
            'suportado' => true,
            'motivo' => null,
            'largura_maxima' => self::MAPA_CALOR_LARGURA_BARRA_MM,
            'maximo' => (int) ($mapaDeCalor['escala_absoluta_maxima'] ?? 0),
            'barras' => $barras,
            'largura_total' => $larguraTotal,
            'altura_total' => $alturaTotal,
            'imagem' => $this->svgMapaDeCalor($barras, self::MAPA_CALOR_LARGURA_BARRA_MM, $larguraTotal, $alturaTotal),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $barras
     */
    private function svgMapaDeCalor(array $barras, float $larguraMaxima, float $larguraTotal, float $alturaTotal): string
    {
        $conteudo = '';

        foreach ($barras as $indice => $barra) {
            $y = $indice * 7.0;

            $conteudo .= sprintf(
                '<rect x="0" y="%s" width="%s" height="4" fill="#e1e0d9" />',
                $this->fmt($y + 1), $this->fmt($larguraMaxima)
            );
            $conteudo .= sprintf(
                '<rect x="0" y="%s" width="%s" height="4" fill="#059669" />',
                $this->fmt($y + 1), $this->fmt($barra['largura_preenchida'])
            );
            $conteudo .= sprintf(
                '<text x="%s" y="%s" font-size="2.6" fill="#0b0b0b">%d (%d%%) - %s</text>',
                $this->fmt($larguraMaxima + 3), $this->fmt($y + 4.2),
                $barra['valor_absoluto'], $barra['intensidade_percentual'], $this->escaparXml((string) $barra['rotulo'])
            );
        }

        return $this->svgParaDataUri($conteudo, $larguraTotal, $alturaTotal);
    }

    /**
     * Traduz cada `pest_type` da evolução por espécie para o rótulo em
     * português, reaproveitando `PestSighting::getPestTypeTextAttribute()`
     * (o accessor só faz `match` sobre o atributo em memória, sem tocar o
     * banco) em vez de duplicar o dicionário de tradução aqui.
     *
     * @param  list<array<string, mixed>>  $evolucaoPorEspecie
     * @return list<array<string, mixed>>
     */
    private function especiesTraduzidas(array $evolucaoPorEspecie): array
    {
        return array_map(
            fn (array $especie): array => [
                ...$especie,
                'rotulo' => (new PestSighting(['pest_type' => $especie['pest_type']]))->pest_type_text,
            ],
            $evolucaoPorEspecie
        );
    }

    /**
     * Croqui embutido no relatório para um endereço, a partir só do que já
     * está congelado em `mapa_de_calor` (posição, rótulo e captura do
     * período) - nenhuma nova consulta de posição ou de evento é feita.
     * `null` quando o endereço não tem planta com posição (mesmo
     * `suportado: false` que `barrasMapaDeCalor()` já trata para a seção de
     * mapa de calor).
     *
     * @param  array<string, mixed>  $mapaDeCalor
     * @return array<string, mixed>|null
     */
    private function croquiCongeladoDoEndereco(array $mapaDeCalor, string $versao): ?array
    {
        $planta = $mapaDeCalor['planta'] ?? null;

        if (! ($mapaDeCalor['suportado'] ?? false) || $planta === null) {
            return null;
        }

        $pontosCongelados = collect($mapaDeCalor['pontos'] ?? [])->sortBy('device_id')->values();

        // Código público só é buscado na versão técnica: é o único dado que
        // `mapa_de_calor.pontos` não carrega (ver o docblock da classe para o
        // porquê de buscar ao vivo ser seguro aqui).
        $codigosPublicos = $versao === self::VERSAO_TECNICO
            ? Device::query()->whereIn('id', $pontosCongelados->pluck('device_id'))->pluck('codigo_publico', 'id')
            : collect();

        // A versão da planta é a congelada (`floor_plan_id`), nunca a ativa
        // de hoje - é o ponto central da regra de negócio desta task.
        $floorPlan = FloorPlan::query()->find($planta['floor_plan_id']);

        $pontos = $pontosCongelados
            ->map(fn (array $ponto, int $indice): array => $this->normalizarPontoCroqui(
                numero: $indice + 1,
                rotulo: $ponto['rotulo'] ?? null,
                codigoPublico: $codigosPublicos[$ponto['device_id']] ?? null,
                estado: $this->estadoDoPontoNoPeriodo((int) ($ponto['valor_absoluto'] ?? 0)),
                versao: $versao,
            ))
            ->all();

        $pontosXY = $pontosCongelados
            ->map(fn (array $ponto, int $indice): array => [
                'numero' => $indice + 1,
                'x' => (float) $ponto['x'],
                'y' => (float) $ponto['y'],
            ])
            ->all();

        return [
            'planta_nome' => $planta['nome'],
            'planta_versao' => $planta['versao'],
            'periodo_label' => null,
            'versao' => $versao,
            'aspecto' => $this->aspectoDaImagem((int) ($planta['largura_px'] ?? 0), (int) ($planta['altura_px'] ?? 0)),
            'pontos' => $pontos,
            'imagem_svg' => $this->svgCroqui(
                $floorPlan !== null ? $this->imagemEmBase64(Storage::disk(FloorPlanService::DISCO), $floorPlan->arquivo_path) : null,
                (int) ($planta['largura_px'] ?? 0),
                (int) ($planta['altura_px'] ?? 0),
                $pontosXY
            ),
        ];
    }

    private function estadoDoPontoNoPeriodo(int $valorAbsoluto): string
    {
        return $valorAbsoluto > 0
            ? sprintf('%d ocorrência(s) no período', $valorAbsoluto)
            : 'Sem ocorrência no período';
    }

    /**
     * @return array{numero: int, rotulo: string|null, codigo_publico: string|null, estado: string|null}
     */
    private function normalizarPontoCroqui(
        int $numero,
        ?string $rotulo,
        ?string $codigoPublico,
        ?string $estado,
        string $versao
    ): array {
        return [
            'numero' => $numero,
            'rotulo' => $versao === self::VERSAO_TECNICO ? ($rotulo ?? sprintf('Ponto %d', $numero)) : null,
            'codigo_publico' => $versao === self::VERSAO_TECNICO ? $codigoPublico : null,
            'estado' => $versao === self::VERSAO_CLIENTE ? $estado : null,
        ];
    }

    /**
     * Planta (imagem ou, na ausência dela, um retângulo neutro) com os
     * pontos numerados desenhados por cima, tudo num `<svg>` só, embutido
     * como `data:image/svg+xml`. O sistema de coordenadas do SVG é o próprio
     * pixel da imagem (`largura_px`/`altura_px`): `x`/`y` de cada ponto
     * (fração de 0 a 1) só precisam ser multiplicados por essas dimensões,
     * sem depender do tamanho final em que o `<img>` que envolve este SVG vai
     * ser exibido na página - quem decide esse tamanho final (em mm) é
     * `pdf/partials/croqui-planta.blade.php`, e o SVG escala para caber nele
     * como qualquer imagem vetorial.
     *
     * @param  list<array{numero: int, x: float, y: float}>  $pontos
     */
    private function svgCroqui(?string $imagemBase64, int $larguraPx, int $alturaPx, array $pontos): string
    {
        $larguraPx = $larguraPx > 0 ? $larguraPx : 1000;
        $alturaPx = $alturaPx > 0 ? $alturaPx : 707;

        $conteudo = $imagemBase64 !== null
            ? sprintf(
                '<image xlink:href="%s" x="0" y="0" width="%d" height="%d" preserveAspectRatio="none" />',
                $imagemBase64, $larguraPx, $alturaPx
            )
            : sprintf('<rect x="0" y="0" width="%d" height="%d" fill="#e1e0d9" />', $larguraPx, $alturaPx);

        $raio = max(min($larguraPx, $alturaPx) * 0.022, 10.0);
        $fonte = $raio * 0.85;

        foreach ($pontos as $ponto) {
            $cx = round($ponto['x'] * $larguraPx, 1);
            $cy = round($ponto['y'] * $alturaPx, 1);

            $conteudo .= sprintf(
                '<circle cx="%s" cy="%s" r="%s" fill="#059669" stroke="#ffffff" stroke-width="%s" />',
                $this->fmt($cx), $this->fmt($cy), $this->fmt($raio), $this->fmt($raio * 0.18)
            );
            $conteudo .= sprintf(
                '<text x="%s" y="%s" font-size="%s" text-anchor="middle" fill="#ffffff">%d</text>',
                $this->fmt($cx), $this->fmt($cy + $fonte * 0.35), $this->fmt($fonte), $ponto['numero']
            );
        }

        return $this->svgParaDataUri($conteudo, (float) $larguraPx, (float) $alturaPx);
    }

    /**
     * Envolve o conteúdo (elementos SVG já prontos, em string) num documento
     * `<svg>` completo e devolve como `data:image/svg+xml;base64`. `$largura`/
     * `$altura` são ao mesmo tempo o `viewBox` e os atributos `width`/`height`
     * do próprio SVG: o `<img>` que o exibe pode redefinir o tamanho final via
     * CSS (mm) sem distorcer nada, porque o `viewBox` preserva a proporção
     * interna.
     */
    private function svgParaDataUri(string $conteudoInterno, float $largura, float $altura): string
    {
        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="%1$s" height="%2$s" viewBox="0 0 %1$s %2$s">%3$s</svg>',
            $this->fmt($largura),
            $this->fmt($altura),
            $conteudoInterno
        );

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Formata número para atributo/texto de SVG com separador decimal
     * estável (ponto, nunca vírgula, independente do locale do servidor).
     */
    private function fmt(float $numero): string
    {
        return rtrim(rtrim(sprintf('%.3F', $numero), '0'), '.') ?: '0';
    }

    /**
     * Escapa texto (rótulo de dispositivo, chave de período) antes de entrar
     * como conteúdo de um elemento `<text>` do SVG: são strings de dado real
     * (nome de dispositivo digitado pelo técnico), nunca literal confiável.
     */
    private function escaparXml(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function aspectoDaImagem(int $larguraPx, int $alturaPx): float
    {
        return $larguraPx > 0 && $alturaPx > 0 ? $larguraPx / $alturaPx : 1.4142;
    }

    /**
     * Converte um arquivo do disco informado para data URI, mesmo padrão de
     * `CertificateService::convertStorageFileToBase64()` e
     * `FloorPlanController::imagemEmBase64()`: dompdf lida melhor com imagem
     * embutida do que com URL remota. `null` sem quebrar o layout quando o
     * caminho é vazio ou o arquivo não existe mais no disco.
     */
    private function imagemEmBase64(Filesystem $disco, ?string $path): ?string
    {
        if (! filled($path) || ! $disco->exists($path)) {
            return null;
        }

        $conteudo = $disco->get($path);
        $mime = $disco->mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode((string) $conteudo);
    }

    private function garantirVersaoValida(string $versao): void
    {
        if (! in_array($versao, self::VERSOES_VALIDAS, true)) {
            throw new InvalidArgumentException(
                sprintf('Versão de croqui inválida: "%s". Use "%s" ou "%s".', $versao, self::VERSAO_TECNICO, self::VERSAO_CLIENTE)
            );
        }
    }
}
