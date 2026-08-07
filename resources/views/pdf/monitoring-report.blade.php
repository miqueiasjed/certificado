<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Monitoramento</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 12mm 15mm;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 10.5px;
            margin: 0;
            padding: 0;
            line-height: 1.35;
            color: #000;
            background-color: #fff;
        }

        h1, h2, h3, p {
            margin: 0;
        }

        .quebra-pagina {
            page-break-before: always;
        }

        /* ---------- Capa ---------- */
        .capa {
            text-align: center;
            padding-top: 30px;
        }

        .capa .logo-container img {
            height: 110px;
            width: auto;
            margin-bottom: 14px;
        }

        .capa .titulo-documento {
            font-weight: bold;
            font-size: 22px;
            text-transform: uppercase;
            background-color: #059669;
            color: #fff;
            padding: 14px;
            border: 2px solid #000;
            margin: 0 20px 24px 20px;
        }

        .capa table {
            width: 100%;
            border-collapse: collapse;
            margin: 0 20px;
        }

        .capa table, .capa th, .capa td {
            border: 1px solid #000;
        }

        .capa th, .capa td {
            padding: 6px 10px;
            text-align: left;
            font-size: 11px;
        }

        .capa th {
            background-color: #059669;
            color: #fff;
            width: 35%;
        }

        /* ---------- Seções comuns ---------- */
        .section-title {
            background-color: #059669;
            color: #fff;
            font-weight: bold;
            font-size: 12px;
            padding: 7px 9px;
            margin-top: 16px;
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .subsection-title {
            font-weight: bold;
            font-size: 11.5px;
            color: #0b0b0b;
            margin-top: 10px;
            margin-bottom: 6px;
            border-bottom: 1px solid #c3c2b7;
            padding-bottom: 3px;
        }

        table.tabela-padrao {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }

        table.tabela-padrao, table.tabela-padrao th, table.tabela-padrao td {
            border: 1px solid #898781;
        }

        table.tabela-padrao th, table.tabela-padrao td {
            padding: 4px 6px;
            font-size: 10px;
            text-align: left;
        }

        table.tabela-padrao th {
            background-color: #059669;
            color: #fff;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .texto-muted {
            color: #52514e;
            font-size: 9.5px;
        }

        .aviso-vazio {
            border: 1px solid #c3c2b7;
            background-color: #f9f9f7;
            padding: 8px;
            font-size: 10px;
            color: #52514e;
            margin-bottom: 8px;
        }

        /* ---------- Resumo (stat tiles) ---------- */
        table.resumo-tiles {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }

        table.resumo-tiles td {
            border: 1px solid #898781;
            width: 33.33%;
            padding: 8px;
            text-align: center;
            vertical-align: top;
        }

        .resumo-tiles .rotulo {
            font-size: 9px;
            color: #52514e;
            text-transform: uppercase;
        }

        .resumo-tiles .valor {
            font-size: 18px;
            font-weight: bold;
            color: #0b0b0b;
        }

        /* ---------- Legenda com marcador ---------- */
        .legenda-grafico {
            font-size: 9px;
            color: #52514e;
            margin-bottom: 4px;
        }

        .marcador-legenda {
            display: inline-block;
            width: 8px;
            height: 8px;
            margin-right: 3px;
        }

        /* ---------- Croqui (compartilhado com pdf/partials/croqui-planta) ---------- */
        .croqui-bloco {
            width: 100%;
        }

        .croqui-imagem-area {
            border: 1px solid #000;
            background-color: #f4f4f4;
        }

        .croqui-sem-imagem {
            padding: 20px;
            text-align: center;
            color: #555;
        }

        table.croqui-legenda {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        table.croqui-legenda, table.croqui-legenda th, table.croqui-legenda td {
            border: 1px solid #000;
        }

        table.croqui-legenda th, table.croqui-legenda td {
            padding: 4px 6px;
            text-align: left;
            font-size: 9.5px;
        }

        table.croqui-legenda th {
            background-color: #059669;
            color: #fff;
        }

        .col-ponto {
            width: 15%;
        }

        /* ---------- Rodapé (responsável técnico) ---------- */
        .rodape-responsavel {
            margin-top: 24px;
            padding-top: 10px;
            border-top: 1px solid #000;
            font-size: 10px;
        }
    </style>
</head>

<body>
    {{-- ================= CAPA ================= --}}
    <div class="capa">
        <div class="logo-container">
            @if($logoSrc)
                <img src="{{ $logoSrc }}" alt="Logo">
            @endif
        </div>

        <div class="titulo-documento">Relatório de Monitoramento</div>

        <table>
            <tr>
                <th>Empresa contratada</th>
                <td>{{ $company->name ?? 'Não informado' }}@if($company->cnpj) - CNPJ {{ $company->cnpj }}@endif</td>
            </tr>
            <tr>
                <th>Cliente</th>
                <td>{{ $cliente?->name ?? 'Não informado' }}@if($cliente?->cnpj) - CNPJ/CPF {{ $cliente->cnpj }}@endif</td>
            </tr>
            <tr>
                <th>Endereço(s) do relatório</th>
                <td>
                    @if($enderecoUnico)
                        {{ $enderecoUnico->nickname ?: $enderecoUnico->street }}, {{ $enderecoUnico->number }}
                        @if($enderecoUnico->district) - {{ $enderecoUnico->district }}@endif
                        @if($enderecoUnico->city) - {{ $enderecoUnico->city }}/{{ $enderecoUnico->state }}@endif
                    @elseif(count($porEndereco) > 0)
                        {{ collect($porEndereco)->pluck('endereco')->implode('; ') }}
                    @else
                        Não informado
                    @endif
                </td>
            </tr>
            <tr>
                <th>Período coberto</th>
                <td>{{ $periodo['de']?->format('d/m/Y') ?? '-' }} a {{ $periodo['ate']?->format('d/m/Y') ?? '-' }}</td>
            </tr>
            <tr>
                <th>Relatório gerado em</th>
                <td>{{ $geradoEm?->format('d/m/Y H:i') ?? '-' }}@if($geradoPor) por {{ $geradoPor }}@endif</td>
            </tr>
        </table>
    </div>

    {{-- ================= RESUMO DO PERÍODO ================= --}}
    <div class="quebra-pagina"></div>
    <div class="section-title">Resumo do período</div>

    <table class="resumo-tiles">
        <tr>
            <td>
                <div class="rotulo">Visitas realizadas</div>
                <div class="valor">{{ $resumo['visitas'] }}</div>
            </td>
            <td>
                <div class="rotulo">Ocorrências registradas</div>
                <div class="valor">{{ $resumo['ocorrencias_atual'] }}</div>
            </td>
            <td>
                <div class="rotulo">Comparação com período anterior</div>
                <div class="valor">
                    @if($resumo['percentual'] === null)
                        Sem base de comparação
                    @elseif($resumo['a_partir_de_zero'])
                        De 0 para {{ $resumo['ocorrencias_atual'] }}
                    @else
                        {{ $resumo['percentual'] >= 0 ? '+' : '' }}{{ number_format($resumo['percentual'], 1, ',', '.') }}%
                    @endif
                </div>
            </td>
        </tr>
    </table>
    <p class="texto-muted">
        Ocorrências do período anterior de mesma duração: {{ $resumo['ocorrencias_anterior'] }}.
        Contagem de ocorrência por avistamento de espécie (`pest_sightings`), detalhada na seção "Ocorrência por espécie".
    </p>

    {{-- ================= BLOCOS POR ENDEREÇO ================= --}}
    @forelse($porEndereco as $item)
        <div class="section-title">Endereço: {{ $item['endereco'] }}</div>

        {{-- ---- Evolução em gráfico ---- --}}
        <div class="subsection-title">Evolução no período (capturas por mês)</div>
        @if($item['grafico_evolucao']['tem_dados'])
            @php $g = $item['grafico_evolucao']; @endphp
            <div class="legenda-grafico">
                <span class="marcador-legenda" style="background-color:#059669;"></span> Capturas no mês
                <span class="marcador-legenda" style="background-color:#9CA3AF; margin-left:10px;"></span> Sem visita no mês (nenhum dispositivo teve evento registrado)
            </div>
            {{-- A geometria (barras, marcação "sem visita") já vem pronta em
                 $g['imagem']: RelatorioPdfService::svgEvolucao() monta o SVG
                 inteiro em PHP e embute como data URI, porque o dompdf deste
                 projeto só desenha SVG quando ele chega como fonte de
                 imagem, nunca como marcação <svg> solta no HTML. --}}
            <img src="{{ $g['imagem'] }}" alt="Evolução de capturas por mês" style="width: {{ $g['largura'] }}mm; height: {{ $g['altura'] }}mm;">
            <div class="texto-muted">
                @foreach($g['barras'] as $barra)
                    {{ $barra['rotulo_eixo'] }}: {{ $barra['sem_visita'] ? 'SEM VISITA' : $barra['valor'].' captura(s)' }}@if(!$loop->last); @endif
                @endforeach
            </div>
        @else
            <div class="aviso-vazio">Sem dispositivo cadastrado ou sem período apurado neste endereço para montar o gráfico de evolução.</div>
        @endif

        {{-- ---- Ranking dos pontos críticos ---- --}}
        <div class="subsection-title">Ranking dos pontos críticos</div>
        @if(count($item['ranking_dispositivos']) > 0)
            <table class="tabela-padrao">
                <tr>
                    <th>Dispositivo</th>
                    <th>Código público</th>
                    <th class="text-center">Visitas</th>
                    <th class="text-center">Capturas</th>
                    <th class="text-center">Média/visita</th>
                    <th>Tendência</th>
                </tr>
                @foreach($item['ranking_dispositivos'] as $dispositivo)
                    <tr>
                        <td>{{ $dispositivo['rotulo'] }}</td>
                        <td>{{ $dispositivo['codigo_publico'] ?? '-' }}</td>
                        <td class="text-center">{{ $dispositivo['visitas'] }}</td>
                        <td class="text-center">{{ $dispositivo['capturas_total'] }}</td>
                        <td class="text-center">{{ number_format($dispositivo['media_por_visita'], 2, ',', '.') }}</td>
                        <td>
                            @php $tendencia = $dispositivo['tendencia']; @endphp
                            @switch($tendencia['estado'])
                                @case('subindo') Subindo @break
                                @case('caindo') Caindo @break
                                @case('estavel') Estável @break
                                @default Sem comparação
                            @endswitch
                            @if($tendencia['percentual'] !== null)
                                ({{ $tendencia['percentual'] >= 0 ? '+' : '' }}{{ number_format($tendencia['percentual'], 1, ',', '.') }}%)
                            @endif
                        </td>
                    </tr>
                @endforeach
            </table>
        @else
            <div class="aviso-vazio">Nenhum dispositivo com visita registrada neste endereço no período.</div>
        @endif

        {{-- ---- Mapa de calor por área ---- --}}
        <div class="subsection-title">Mapa de calor por área</div>
        @php $mapa = $item['mapa_de_calor']; @endphp
        @if($mapa['suportado'])
            <p class="texto-muted">
                Escala absoluta do período: a barra cheia (intensidade 100%) corresponde a {{ $mapa['maximo'] }}
                {{ $mapa['maximo'] === 1 ? 'captura' : 'capturas' }} no ponto de maior ocorrência deste endereço.
                A intensidade normalizada nunca aparece sem o número absoluto ao lado.
            </p>
            {{-- $mapa['imagem'] já traz as barras desenhadas
                 (RelatorioPdfService::svgMapaDeCalor()), pelo mesmo motivo do
                 gráfico de evolução acima: SVG só é desenhado pelo dompdf
                 quando embutido como fonte de imagem. --}}
            <img src="{{ $mapa['imagem'] }}" alt="Mapa de calor por área" style="width: {{ $mapa['largura_total'] }}mm; height: {{ $mapa['altura_total'] }}mm;">
            <table class="tabela-padrao">
                <tr>
                    <th>Ponto</th>
                    <th class="text-center">Valor absoluto</th>
                    <th class="text-center">Intensidade normalizada</th>
                </tr>
                @foreach($mapa['barras'] as $barra)
                    <tr>
                        <td>{{ $barra['rotulo'] }}</td>
                        <td class="text-center">{{ $barra['valor_absoluto'] }}</td>
                        <td class="text-center">{{ $barra['intensidade_percentual'] }}%</td>
                    </tr>
                @endforeach
            </table>
        @else
            <div class="aviso-vazio">{{ $mapa['motivo'] }}</div>
        @endif

        {{-- ---- Ocorrência por espécie ---- --}}
        <div class="subsection-title">Ocorrência por espécie</div>
        <table class="tabela-padrao">
            <tr>
                <th>Espécie</th>
                <th class="text-center">Período anterior</th>
                <th class="text-center">Período atual</th>
                <th class="text-center">Variação</th>
            </tr>
            @foreach($item['especies'] as $especie)
                <tr>
                    <td>{{ $especie['rotulo'] }}</td>
                    <td class="text-center">{{ $especie['de'] }}</td>
                    <td class="text-center">{{ $especie['para'] }}</td>
                    <td class="text-center">
                        @if($especie['percentual'] === null)
                            -
                        @elseif($especie['a_partir_de_zero'])
                            De 0 para {{ $especie['para'] }}
                        @else
                            {{ $especie['percentual'] >= 0 ? '+' : '' }}{{ number_format($especie['percentual'], 1, ',', '.') }}%
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>

        {{-- ---- Croqui com os pontos (página própria) ---- --}}
        <div class="quebra-pagina"></div>
        <div class="section-title">Croqui - {{ $item['endereco'] }}</div>
        @if($item['croqui'])
            @include('pdf.partials.croqui-planta', ['croqui' => $item['croqui'], 'maxLarguraMm' => 175, 'maxAlturaMm' => 140])
        @else
            <div class="aviso-vazio">{{ $mapa['motivo'] ?? 'Este endereço ainda não tem planta com dispositivo posicionado.' }}</div>
        @endif

        @if(!$loop->last)
            <div class="quebra-pagina"></div>
        @endif
    @empty
        <div class="aviso-vazio">Nenhum endereço apurado neste relatório.</div>
    @endforelse

    {{-- ================= ADEQUAÇÕES EM ABERTO ================= --}}
    <div class="section-title">Adequações em aberto</div>
    @if(count($adequacoes) > 0)
        <table class="tabela-padrao">
            <tr>
                <th>Adequação</th>
                <th>Prazo</th>
                <th class="text-center">Dias em aberto</th>
            </tr>
            @foreach($adequacoes as $adequacao)
                <tr>
                    <td>{{ $adequacao['descricao'] ?? '-' }}</td>
                    <td>{{ $adequacao['prazo'] ?? '-' }}</td>
                    <td class="text-center">{{ $adequacao['dias_em_aberto'] ?? '-' }}</td>
                </tr>
            @endforeach
        </table>
    @else
        <div class="aviso-vazio">Nenhuma adequação em aberto registrada neste período.</div>
    @endif

    {{-- ================= RODAPÉ: RESPONSÁVEL TÉCNICO ================= --}}
    <div class="rodape-responsavel">
        <strong>{{ $company->technical_responsible_title ?? 'Responsável Técnico' }}:</strong>
        {{ $company->technical_responsible_name ?? 'Não informado' }}
        @if($company->crq)
            - Registro no conselho: {{ $company->crq }}
        @endif
    </div>
</body>

</html>
