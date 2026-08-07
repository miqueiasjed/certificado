{{--
    Partial reaproveitado por `pdf/floor-plan.blade.php` (página própria,
    A4 paisagem) e por `pdf/monitoring-report.blade.php` (seção embutida,
    A4 retrato). Quem inclui decide o espaço disponível via `$maxLarguraMm`/
    `$maxAlturaMm`; este partial só encaixa a planta dentro desse espaço
    preservando a proporção da imagem original (nunca distorce o layout
    físico que o croqui documenta).

    Variáveis esperadas:
    - $croqui: array com planta_nome, planta_versao, periodo_label (?string),
      imagem_svg (string data:image/svg+xml, planta + marcadores já
      desenhados por `RelatorioPdfService::svgCroqui()`), aspecto (float
      largura/altura), versao ('tecnico'|'cliente'), pontos (lista de
      numero e, conforme a versão, rotulo+codigo_publico OU estado - sem
      x/y, a posição já está embutida em `imagem_svg`).
    - $maxLarguraMm, $maxAlturaMm: espaço disponível para a imagem da planta.
--}}
@php
    // `imagem_svg` já é a planta com os pontos numerados desenhados por cima
    // (RelatorioPdfService::svgCroqui()): o dompdf usado neste projeto só
    // desenha SVG quando ele chega como fonte de `<img>`, nunca como marcação
    // `<svg>` solta no meio do HTML nem como `<div>` com posicionamento
    // absoluto sobre uma imagem (as duas formas foram tentadas e comparadas
    // no PDF gerado para esta task antes desta versão). Aqui só resta decidir
    // o tamanho final de exibição, preservando a proporção da imagem.
    $aspecto = $croqui['aspecto'] > 0 ? $croqui['aspecto'] : 1.4142;
    $larguraMm = $maxLarguraMm;
    $alturaMm = $larguraMm / $aspecto;

    if ($alturaMm > $maxAlturaMm) {
        $alturaMm = $maxAlturaMm;
        $larguraMm = $alturaMm * $aspecto;
    }

    $larguraMm = round($larguraMm, 2);
    $alturaMm = round($alturaMm, 2);
@endphp

<div class="croqui-bloco">
    <div class="croqui-imagem-area" style="width: {{ $larguraMm }}mm; height: {{ $alturaMm }}mm;">
        <img src="{{ $croqui['imagem_svg'] }}" alt="Planta com os pontos numerados" style="width: {{ $larguraMm }}mm; height: {{ $alturaMm }}mm;">
    </div>

    <table class="croqui-legenda">
        <tr>
            <td colspan="{{ $croqui['versao'] === 'tecnico' ? 3 : 2 }}" class="section-title">
                LEGENDA - {{ strtoupper($croqui['planta_nome']) }} (V{{ $croqui['planta_versao'] }})
                - VERSÃO {{ $croqui['versao'] === 'tecnico' ? 'TÉCNICA' : 'CLIENTE' }}
            </td>
        </tr>
        @if($croqui['versao'] === 'tecnico')
            <tr>
                <th class="col-ponto">Ponto</th>
                <th>Dispositivo</th>
                <th>Código público</th>
            </tr>
            @forelse($croqui['pontos'] as $ponto)
                <tr>
                    <td class="text-center">{{ $ponto['numero'] }}</td>
                    <td>{{ $ponto['rotulo'] }}</td>
                    <td>{{ $ponto['codigo_publico'] ?? 'Não informado' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3">Nenhum dispositivo posicionado nesta planta.</td>
                </tr>
            @endforelse
        @else
            <tr>
                <th class="col-ponto">Ponto</th>
                <th>Estado no período</th>
            </tr>
            @forelse($croqui['pontos'] as $ponto)
                <tr>
                    <td class="text-center">{{ $ponto['numero'] }}</td>
                    <td>{{ $ponto['estado'] ?? 'Sem período associado' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="2">Nenhum dispositivo posicionado nesta planta.</td>
                </tr>
            @endforelse
        @endif
    </table>
</div>
