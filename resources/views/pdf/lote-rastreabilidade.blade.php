<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rastreabilidade do Lote {{ $lote['lote'] }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            margin: 0;
            padding: 20px;
            color: #333;
            line-height: 1.3;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
        }

        .header img {
            height: 90px;
            width: auto;
            display: block;
            margin: 0 auto 10px auto;
        }

        .header h1 {
            color: #059669;
            font-size: 20px;
            margin: 10px 0 0 0;
            font-weight: bold;
            text-transform: uppercase;
        }

        .company-info {
            text-align: center;
            margin: 0 0 20px 0;
            font-size: 11px;
            color: #059669;
            line-height: 1.3;
            padding: 10px;
            border: 2px solid #059669;
            border-radius: 8px;
            background: #f0fdf4;
        }

        .company-info p {
            margin: 2px 0;
        }

        .section-title {
            font-size: 13px;
            font-weight: bold;
            background-color: #f0fdf4;
            padding: 8px 10px;
            margin: 20px 0 10px 0;
            border-left: 4px solid #059669;
            color: #059669;
        }

        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        .info-table td {
            padding: 8px;
            vertical-align: top;
            font-size: 11px;
            border: 1px solid #e0e0e0;
        }

        .info-table td strong {
            display: block;
            font-weight: bold;
            color: #059669;
            font-size: 10px;
            margin-bottom: 3px;
        }

        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: bold;
            font-size: 10px;
        }

        .status-ok { background-color: #d4edda; color: #059669; }
        .status-vencido { background-color: #f8d7da; color: #721c24; }

        table.aplicacoes {
            width: 100%;
            border-collapse: collapse;
            margin-top: 5px;
        }

        table.aplicacoes th {
            background-color: #f0fdf4;
            color: #059669;
            font-size: 10px;
            text-transform: uppercase;
            text-align: left;
            padding: 6px 8px;
            border: 1px solid #d1fae5;
        }

        table.aplicacoes td {
            font-size: 10px;
            padding: 6px 8px;
            border: 1px solid #e0e0e0;
            vertical-align: top;
        }

        table.aplicacoes tr:nth-child(even) {
            background-color: #fafafa;
        }

        .total-row {
            margin-top: 12px;
            text-align: right;
            font-size: 12px;
            font-weight: bold;
            color: #059669;
        }

        .sem-aplicacoes {
            padding: 15px;
            text-align: center;
            color: #666;
            border: 1px dashed #ccc;
            border-radius: 6px;
        }

        .footer-info {
            margin-top: 30px;
            font-size: 9px;
            color: #999;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        @if($logoSrc)
            <img src="{{ $logoSrc }}" alt="Logo">
        @endif
        <h1>Rastreabilidade de Lote</h1>
    </div>

    <div class="company-info">
        @if($company->name)
            <p><strong>{{ $company->name }}</strong></p>
        @endif
        @if($company->cnpj)
            <p>CNPJ: {{ $company->cnpj }}</p>
        @endif
        @if($company->full_address)
            <p>{{ $company->full_address }}</p>
        @endif
        @if($company->phone)
            <p>Fone: {{ $company->phone }}</p>
        @endif
    </div>

    <div class="section-title">DADOS DO LOTE</div>
    <table class="info-table">
        <tr>
            <td class="col-50">
                <strong>Produto</strong>
                {{ $lote['produto'] }}
            </td>
            <td class="col-50">
                <strong>Lote</strong>
                {{ $lote['lote'] }}
            </td>
        </tr>
        <tr>
            <td>
                <strong>Validade</strong>
                {{ $lote['validade'] }}
                @if($lote['vencido'])
                    <span class="status-badge status-vencido">VENCIDO</span>
                @else
                    <span class="status-badge status-ok">DENTRO DA VALIDADE</span>
                @endif
            </td>
            <td>
                <strong>Recebido em</strong>
                {{ $lote['recebido_em'] }}
            </td>
        </tr>
        <tr>
            <td>
                <strong>Custo unitário</strong>
                R$ {{ number_format($lote['custo_unitario'], 2, ',', '.') }}
            </td>
            <td>
                <strong>Saldo atual</strong>
                {{ number_format($lote['saldo'], 4, ',', '.') }} {{ $lote['unidade'] }}
            </td>
        </tr>
        @if($lote['fornecedor'] || $lote['nota_fiscal'])
        <tr>
            <td>
                <strong>Fornecedor</strong>
                {{ $lote['fornecedor'] ?? '-' }}
            </td>
            <td>
                <strong>Nota fiscal</strong>
                {{ $lote['nota_fiscal'] ?? '-' }}
            </td>
        </tr>
        @endif
    </table>

    <div class="section-title">APLICAÇÕES REGISTRADAS</div>

    @if(count($aplicacoes) > 0)
        <table class="aplicacoes">
            <thead>
                <tr>
                    <th>Data</th>
                    <th>OS</th>
                    <th>Cliente</th>
                    <th>Endereço</th>
                    <th>Técnico</th>
                    <th>Quantidade</th>
                </tr>
            </thead>
            <tbody>
                @foreach($aplicacoes as $aplicacao)
                <tr>
                    <td>{{ $aplicacao['data'] }}</td>
                    <td>{{ $aplicacao['numero'] }}</td>
                    <td>{{ $aplicacao['cliente'] }}</td>
                    <td>{{ $aplicacao['endereco'] }}</td>
                    <td>{{ $aplicacao['tecnico'] ?? '-' }}</td>
                    <td>{{ number_format($aplicacao['quantidade'], 4, ',', '.') }} {{ $lote['unidade'] }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="total-row">
            Total aplicado: {{ number_format($totalAplicado, 4, ',', '.') }} {{ $lote['unidade'] }}
        </div>
    @else
        <div class="sem-aplicacoes">Nenhuma aplicação registrada para este lote.</div>
    @endif

    <div class="footer-info">
        Documento gerado em {{ $geradoEm }}
    </div>
</body>
</html>
