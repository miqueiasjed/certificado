<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recibo de Pagamento</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            line-height: 1.3;
            color: #333;
            padding: 15mm;
            background: white;
            page-break-inside: avoid;
        }

        /* Header simples com logo */
        .header {
            text-align: left;
            margin-bottom: 20px;
        }

        .logo {
            color: #059669;
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        /* Informações da empresa centralizadas */
        .company-info {
            text-align: center;
            margin-bottom: 20px;
            font-size: 10px;
            line-height: 1.4;
        }

        .company-info p {
            margin-bottom: 2px;
        }

        /* Layout em duas colunas */
        .two-column-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }

        /* Seções com bordas simples */
        .section {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 10px;
            background: #fafafa;
            page-break-inside: avoid;
            height: fit-content;
        }

        .section-title {
            color: #059669;
            font-weight: bold;
            font-size: 11px;
            margin-bottom: 6px;
            border-bottom: 1px solid #059669;
            padding-bottom: 3px;
        }

        .section-content {
            font-size: 10px;
        }

        /* Campos de informação */
        .field-row {
            margin-bottom: 3px;
        }

        .field-inline {
            display: inline-block;
            margin-right: 15px;
        }

        .field-label {
            font-weight: bold;
            color: #333;
        }

        .field-value {
            color: #333;
        }

        /* Seção de pagamento com destaque - largura total */
        .payment-section {
            grid-column: 1 / -1;
            border: 2px solid #059669;
            border-radius: 6px;
            padding: 12px;
            background: white;
            page-break-inside: avoid;
        }

        .payment-section .section-title {
            font-size: 12px;
            color: #059669;
        }

        /* Linhas de valores */
        .value-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 3px 0;
            font-size: 10px;
        }

        .value-label {
            font-weight: bold;
            color: #333;
        }

        .value-amount {
            font-weight: bold;
            color: #059669;
        }

        .value-amount.discount {
            color: #dc3545;
        }

        /* Histórico de pagamentos */
        .payment-history {
            background: #f0fdf4;
            border-radius: 4px;
            padding: 8px;
            margin: 8px 0;
        }

        .payment-history-title {
            color: #059669;
            font-weight: bold;
            font-size: 10px;
            margin-bottom: 5px;
        }

        .payment-item {
            display: flex;
            justify-content: space-between;
            padding: 2px 0;
            font-size: 9px;
        }

        .payment-item-left {
            color: #333;
        }

        .payment-item-right {
            font-weight: bold;
            color: #059669;
        }

        /* Linha separadora */
        .separator {
            border-top: 1px solid #059669;
            margin: 6px 0;
            padding-top: 6px;
        }

        /* Totais finais */
        .final-row {
            font-size: 11px;
            font-weight: bold;
            padding: 3px 0;
        }

        .final-row .value-amount {
            font-size: 12px;
        }

        /* Evitar quebra de página */
        .no-break {
            page-break-inside: avoid;
        }

        /* Impressão */
        @media print {
            body {
                padding: 10mm;
                font-size: 10px;
            }

            .section,
            .payment-section {
                page-break-inside: avoid;
            }

            .two-column-layout {
                gap: 10px;
            }
        }
    </style>
</head>

<body>
    @php
        $company = \App\Models\Company::current();
        $logoPath = $company->logo_path ? public_path('storage/' . $company->logo_path) : null;
    @endphp

    <!-- Header simples -->
    <div class="header">
        @if($logoPath)
            <img src="{{ $logoPath }}" alt="Logo" style="height: 50px; width: auto; display: block; margin-bottom: 5px;">
        @else
            @if($company->name)
                <div class="logo">{{ $company->name }}</div>
            @endif
        @endif
    </div>

    <!-- Informações da empresa -->
    @if($company->cnpj || $company->full_address || $company->phone)
        <div class="company-info">
            @if($company->cnpj)
                <p><strong>CNPJ:</strong> {{ $company->cnpj }}</p>
            @endif
            @if($company->full_address)
                <p>{{ $company->full_address }}</p>
            @endif
            @if($company->phone)
                <p><strong>Fone:</strong> {{ $company->phone }}</p>
            @endif
        </div>
    @endif

    <!-- Layout em duas colunas -->
    <div class="two-column-layout">
        <!-- Coluna 1: Recibo e Cliente -->
        <div>
            <!-- Recibo de Pagamento -->
            <div class="section no-break">
                <div class="section-title">📄 RECIBO DE PAGAMENTO</div>
                <div class="section-content">
                    <div class="field-row">
                        <span class="field-label">Recibo Nº:</span> {{ $receiptNumber }}
                    </div>
                    <div class="field-row">
                        <span class="field-label">Data de Emissão:</span> {{ now()->format('d/m/Y') }}
                    </div>
                </div>
            </div>

            <!-- Dados do Cliente -->
            <div class="section no-break" style="margin-top: 10px;">
                <div class="section-title">👤 Dados do Cliente</div>
                <div class="section-content">
                    <div class="field-row">
                        <span class="field-label">Nome do Cliente:</span>
                    </div>
                    <div class="field-row" style="margin-bottom: 5px;">
                        {{ $workOrder->client->name ?? 'Não informado' }}
                    </div>
                    <div class="field-row">
                        <span class="field-label">CPF/CNPJ:</span> {{ $workOrder->client->cnpj ?? 'Não informado' }}
                    </div>
                    <div class="field-row">
                        <span class="field-label">Endereço:</span>
                    </div>
                    <div class="field-row">
                        @php($address = $workOrder->address ?? null)
                        @if($address)
                            {{ $address->street }}, {{ $address->number }} - {{ $address->district }},
                            {{ $address->city }}/{{ $address->state }} - CEP: {{ $address->zip }}
                        @else
                            Não informado
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <!-- Coluna 2: Detalhes do Serviço -->
        <div>
            <div class="section no-break">
                <div class="section-title">🔧 Detalhes do Serviço</div>
                <div class="section-content">
                    <div class="field-row">
                        <span class="field-label">Ordem de Serviço:</span> #{{ $workOrder->id }}
                    </div>
                    <div class="field-row">
                        <span class="field-label">Data do Serviço:</span>
                        {{ $workOrder->scheduled_date ? $workOrder->scheduled_date->format('d/m/Y') : 'Não informada' }}
                    </div>
                    <div class="field-row">
                        <span class="field-label">Descrição do Serviço:</span>
                    </div>
                    <div class="field-row">
                        {{ $workOrder->description ?? 'Atendimento emergencial para infestação de ratos' }}
                    </div>
                </div>
            </div>
        </div>

        <!-- Detalhes do Pagamento - Largura Total -->
        <div class="payment-section no-break">
            <div class="section-title">💰 Detalhes do Pagamento</div>

            @if($workOrder->total_cost)
                <div class="value-row">
                    <span class="value-label">Custo Total:</span>
                    <span class="value-amount">R$ {{ number_format($workOrder->total_cost, 2, ',', '.') }}</span>
                </div>
            @endif

            @if($workOrder->discount_amount && $workOrder->discount_amount > 0)
                <div class="value-row">
                    <span class="value-label">Desconto:</span>
                    <span class="value-amount discount">- R$
                        {{ number_format($workOrder->discount_amount, 2, ',', '.') }}</span>
                </div>
            @endif

            @if($workOrder->final_amount)
                <div class="value-row">
                    <span class="value-label">Valor Final:</span>
                    <span class="value-amount">R$ {{ number_format($workOrder->final_amount, 2, ',', '.') }}</span>
                </div>
            @endif

            <!-- Histórico de pagamentos -->
            @if($payments && $payments->count() > 0)
                <div class="payment-history">
                    <div class="payment-history-title">Histórico de Pagamentos</div>
                    @foreach($payments as $payment)
                        <div class="payment-item">
                            <span class="payment-item-left">
                                {{ $payment->payment_date ? $payment->payment_date->format('d/m/Y') : 'Data não informada' }}
                                @if($payment->payment_method_text)
                                    - {{ $payment->payment_method_text }}
                                @endif
                            </span>
                            <span class="payment-item-right">R$ {{ number_format($payment->amount_paid, 2, ',', '.') }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            <!-- Separador e totais -->
            <div class="separator">
                <div class="value-row final-row">
                    <span class="value-label">Total Pago:</span>
                    <span class="value-amount">R$ {{ number_format($totalPaid, 2, ',', '.') }}</span>
                </div>
                <div class="value-row final-row">
                    <span class="value-label">Status:</span>
                    <span class="value-amount">{{ $workOrder->payment_status_text ?? 'Pago' }}</span>
                </div>
            </div>
        </div>
    </div>

</body>

</html>