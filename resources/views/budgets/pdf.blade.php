<!DOCTYPE html>
<html>

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>Orçamento #{{ $budget->id }}</title>
    <style>
        body {
            font-family: sans-serif;
            font-size: 12px;
            color: #333;
        }

        .header {
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 2px solid #ddd;
            padding-bottom: 10px;
        }

        .header h1 {
            margin: 0;
            font-size: 24px;
            color: #2c3e50;
        }

        .header p {
            margin: 2px 0;
            color: #666;
        }

        .section {
            margin-bottom: 20px;
        }

        .section-title {
            font-size: 14px;
            font-weight: bold;
            background-color: #f3f4f6;
            padding: 5px 10px;
            margin-bottom: 10px;
            border-left: 4px solid #10b981;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        th,
        td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }

        th {
            background-color: #f9fafb;
            font-weight: bold;
        }

        .text-right {
            text-align: right;
        }

        .total-row td {
            font-weight: bold;
            background-color: #f3f4f6;
        }

        .info-grid {
            width: 100%;
        }

        .info-grid td {
            border: none;
            padding: 4px;
        }

        .label {
            font-weight: bold;
            color: #555;
            width: 120px;
        }

        .footer {
            margin-top: 50px;
            text-align: center;
            font-size: 10px;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>ORÇAMENTO DE SERVIÇOS</h1>
        <p>Proposta Nº {{ str_pad($budget->id, 6, '0', STR_PAD_LEFT) }}</p>
        <p>Data: {{ \Carbon\Carbon::parse($budget->date)->format('d/m/Y') }}</p>
    </div>

    <div class="section">
        <div class="section-title">DADOS DO CLIENTE</div>
        <table class="info-grid">
            <tr>
                <td class="label">Cliente:</td>
                <td>{{ $budget->client ? $budget->client->name : $budget->prospect_name }}</td>
            </tr>
            <tr>
                <td class="label">Telefone:</td>
                <td>{{ $budget->client ? $budget->client->phone : $budget->prospect_phone }}</td>
            </tr>
            <tr>
                <td class="label">Endereço:</td>
                <td>{{ $budget->client ? $budget->client->address : $budget->prospect_address }}</td>
            </tr>
            @if($budget->client && $budget->client->cnpj)
                <tr>
                    <td class="label">CPF/CNPJ:</td>
                    <td>{{ $budget->client->cnpj }}</td>
                </tr>
            @endif
        </table>
    </div>

    <div class="section">
        <div class="section-title">DETALHES TÉCNICOS</div>
        <table class="info-grid">
            <tr>
                <td class="label">Ambiente:</td>
                <td>{{ $budget->environment_type ? ucfirst($budget->environment_type) : '-' }}</td>
            </tr>
            <tr>
                <td class="label">Pragas Alvo:</td>
                <td>{{ $budget->target_pests ? implode(', ', $budget->target_pests) : '-' }}</td>
            </tr>
            <tr>
                <td class="label">Áreas a Tratar:</td>
                <td>{{ $budget->areas_to_treat ? implode(', ', $budget->areas_to_treat) : '-' }}</td>
            </tr>
            @if($budget->observations)
                <tr>
                    <td class="label">Observações:</td>
                    <td>{{ $budget->observations }}</td>
                </tr>
            @endif
        </table>
    </div>

    <div class="section">
        <div class="section-title">SERVIÇOS PROPOSTOS</div>
        <table>
            <thead>
                <tr>
                    <th>Descrição</th>
                    <th class="text-right" width="80">Qtd</th>
                    <th class="text-right" width="100">Valor Unit.</th>
                    <th class="text-right" width="100">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @php $total = 0; @endphp
                @foreach($budget->services as $service)
                    <tr>
                        <td>{{ $service->description }}</td>
                        <td class="text-right">{{ number_format($service->pivot->quantity, 1, ',', '.') }}</td>
                        <td class="text-right">R$ {{ number_format($service->pivot->unit_price, 2, ',', '.') }}</td>
                        <td class="text-right">R$ {{ number_format($service->pivot->subtotal, 2, ',', '.') }}</td>
                    </tr>
                    @php $total += $service->pivot->subtotal; @endphp
                @endforeach

                @if($budget->discount > 0)
                    <tr>
                        <td colspan="3" class="text-right">Subtotal</td>
                        <td class="text-right">R$ {{ number_format($total, 2, ',', '.') }}</td>
                    </tr>
                    <tr>
                        <td colspan="3" class="text-right">Desconto</td>
                        <td class="text-right">- R$ {{ number_format($budget->discount, 2, ',', '.') }}</td>
                    </tr>
                @endif

                <tr class="total-row">
                    <td colspan="3" class="text-right">TOTAL</td>
                    <td class="text-right">R$ {{ number_format($total - $budget->discount, 2, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="section">
        <div class="section-title">CONDIÇÕES COMERCIAIS</div>
        <table class="info-grid">
            <tr>
                <td class="label">Pagamento:</td>
                <td>{{ $budget->payment_method }}
                    {{ $budget->payment_conditions ? "({$budget->payment_conditions})" : '' }}</td>
            </tr>
            <tr>
                <td class="label">Validade:</td>
                <td>{{ $budget->validity_date ? \Carbon\Carbon::parse($budget->validity_date)->format('d/m/Y') : '10 dias' }}
                </td>
            </tr>
            <tr>
                <td class="label">Execução:</td>
                <td>{{ $budget->execution_deadline ?: 'A combinar' }}</td>
            </tr>
        </table>
    </div>

    <div class="footer">
        <p>Este orçamento é apenas uma estimativa e está sujeito a alteração.</p>
        <p>Gerado em {{ date('d/m/Y H:i') }}</p>
    </div>
</body>

</html>