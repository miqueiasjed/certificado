<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Croqui da Planta</title>
    <style>
        @page {
            size: A4 landscape;
            margin: 10mm 12mm;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            margin: 0;
            padding: 0;
            line-height: 1.3;
            color: #000;
            background-color: #fff;
        }

        .header-section {
            text-align: center;
            margin-bottom: 8px;
            position: relative;
            min-height: 60px;
        }

        .logo-container {
            position: absolute;
            top: 0;
            left: 0;
        }

        .logo-container img {
            height: 50px;
            width: auto;
        }

        .document-title {
            font-weight: bold;
            font-size: 16px;
            text-transform: uppercase;
            background-color: #059669;
            color: #fff;
            padding: 8px;
            border: 2px solid #000;
        }

        .subtitulo {
            margin-top: 6px;
            font-size: 11px;
        }

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
            margin-top: 12px;
        }

        table.croqui-legenda,
        table.croqui-legenda th,
        table.croqui-legenda td {
            border: 1px solid #000;
        }

        table.croqui-legenda th,
        table.croqui-legenda td {
            padding: 4px 6px;
            text-align: left;
            font-size: 10px;
        }

        table.croqui-legenda th {
            background-color: #059669;
            color: #fff;
        }

        .section-title {
            background-color: #059669;
            color: #fff;
            font-weight: bold;
            font-size: 11px;
            padding: 6px 8px;
            text-align: left;
        }

        .col-ponto {
            width: 15%;
        }

        .text-center {
            text-align: center;
        }

        .rodape {
            margin-top: 10px;
            font-size: 9px;
            color: #444;
        }
    </style>
</head>

<body>
    <div class="header-section">
        <div class="logo-container">
            @if($logoSrc)
                <img src="{{ $logoSrc }}" alt="Logo">
            @endif
        </div>
        <div class="document-title">CROQUI DA PLANTA</div>
        <div class="subtitulo">
            <strong>Endereço:</strong>
            @if($endereco)
                {{ $endereco->nickname ?: $endereco->street }}, {{ $endereco->number }}
                @if($endereco->district) - {{ $endereco->district }}@endif
            @else
                Não informado
            @endif
        </div>
    </div>

    @include('pdf.partials.croqui-planta', ['croqui' => $croqui, 'maxLarguraMm' => 220, 'maxAlturaMm' => 150])

    <div class="rodape">
        Croqui gerado em {{ \App\Support\BusinessDate::agora()->format('d/m/Y H:i') }}. Versão da planta: "{{ $croqui['planta_nome'] }}" (v{{ $croqui['planta_versao'] }}).
    </div>
</body>

</html>
