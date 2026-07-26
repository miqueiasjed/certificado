<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etiquetas de Dispositivos</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 8mm 6mm;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 9px;
            margin: 0;
            padding: 0;
            color: #000;
            background-color: #fff;
        }

        .cabecalho {
            margin-bottom: 4mm;
        }

        .cabecalho .titulo {
            font-weight: bold;
            font-size: 13px;
        }

        .cabecalho .subtitulo {
            font-size: 10px;
            color: #333;
        }

        table.grade {
            width: 100%;
            border-collapse: collapse;
        }

        table.grade td {
            width: 33.33%;
            padding: 0;
            vertical-align: top;
        }

        /*
         * page-break-inside: avoid no <tr>, e não só na etiqueta em si: é a
         * linha inteira da grade (as 3 colunas) que não pode ser cortada
         * entre páginas, senão a etiqueta do meio quebra ao meio.
         */
        table.grade tr {
            page-break-inside: avoid;
        }

        .etiqueta {
            page-break-inside: avoid;
            border: 1px dashed #999;
            margin: 2mm;
            padding: 2.5mm;
            text-align: center;
        }

        .etiqueta .qr {
            width: 20mm;
            height: 20mm;
            margin: 0 auto;
        }

        .etiqueta .qr svg {
            width: 100%;
            height: 100%;
        }

        .etiqueta .label {
            font-weight: bold;
            font-size: 10px;
            margin-top: 1.5mm;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .etiqueta .numero {
            font-size: 9px;
            color: #333;
        }

        .etiqueta .codigo {
            font-family: "Courier New", monospace;
            font-size: 11px;
            font-weight: bold;
            letter-spacing: 0.5px;
            margin-top: 1.5mm;
        }

        .etiqueta .local {
            font-size: 8px;
            color: #444;
            margin-top: 1.5mm;
        }

        .sem-dispositivos {
            text-align: center;
            font-size: 11px;
            color: #333;
            margin-top: 10mm;
        }
    </style>
</head>

<body>
    <div class="cabecalho">
        <div class="titulo">Etiquetas de Dispositivos</div>
        <div class="subtitulo">{{ $endereco->full_address }}</div>
    </div>

    @if ($etiquetas->isEmpty())
        <div class="sem-dispositivos">Nenhum dispositivo ativo neste endereço.</div>
    @else
        <table class="grade">
            @foreach ($etiquetas->chunk(3) as $linha)
                <tr>
                    @foreach ($linha as $etiqueta)
                        @php $dispositivo = $etiqueta['dispositivo']; @endphp
                        <td>
                            <div class="etiqueta">
                                <div class="qr">{!! $etiqueta['qr'] !!}</div>
                                <div class="label">{{ $dispositivo->label }}</div>
                                <div class="numero">Nº {{ $dispositivo->number }}</div>
                                <div class="codigo">{{ $dispositivo->codigo_publico }}</div>
                                @if ($dispositivo->default_location_note)
                                    <div class="local">
                                        {{ \Illuminate\Support\Str::limit($dispositivo->default_location_note, 40) }}
                                    </div>
                                @endif
                            </div>
                        </td>
                    @endforeach
                    @for ($i = $linha->count(); $i < 3; $i++)
                        <td></td>
                    @endfor
                </tr>
            @endforeach
        </table>
    @endif
</body>

</html>
