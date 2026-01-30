<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orçamento #{{ $budget->id }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm 15mm;
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
            margin-bottom: 10px;
            position: relative;
            min-height: 120px;
        }

        .logo-container {
            position: absolute;
            top: -29px;
            left: 50%;
            transform: translateX(-50%);
        }

        .logo-container img {
            height: 130px;
            width: auto;
        }

        .document-title {
            font-weight: bold;
            font-size: 18px;
            text-transform: uppercase;
            background-color: #059669;
            color: #fff;
            padding: 10px;
            border: 2px solid #000;
            margin-top: 100px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
            background-color: #fff;
        }

        table,
        th,
        td {
            border: 1px solid #000;
        }

        th,
        td {
            padding: 5px 8px;
            text-align: left;
            vertical-align: top;
            font-size: 11px;
        }

        th {
            background-color: #059669;
            color: #fff;
            font-weight: bold;
        }

        .section-title {
            background-color: #059669;
            color: #fff;
            font-weight: bold;
            font-size: 11px;
            padding: 6px 8px;
            text-align: left;
        }

        .col-30 {
            width: 30%;
        }

        .col-33 {
            width: 33.33%;
        }

        .col-50 {
            width: 50%;
        }

        .col-70 {
            width: 70%;
        }

        .text-center {
            text-align: center;
        }

        .text-right {
            text-align: right;
        }

        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 9px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
    </style>
</head>

<body>
    <!-- Cabeçalho -->
    @php
        $company = \App\Models\Company::current();
        $logoPath = $company->logo_path ? storage_path('app/public/' . $company->logo_path) : null;
    @endphp
    <div class="header-section">
        <div class="logo-container">
            @if($logoPath)
                <img src="{{ $logoPath }}" alt="Logo">
            @endif
        </div>
        <div class="document-title">
            ORÇAMENTO DE SERVIÇOS
        </div>
    </div>

    <!-- Tabela: Dados do Orçamento -->
    <table>
        <tr>
            <td class="col-33"><strong>N° do Orçamento: </strong>{{ str_pad($budget->id, 6, '0', STR_PAD_LEFT) }}</td>
            <td class="col-33"><strong>Data: </strong>
                {{ \Carbon\Carbon::parse($budget->date)->format('d/m/Y') }}
            </td>
            <td class="col-33"><strong>Validade: </strong>
                {{ $budget->validity_date ? \Carbon\Carbon::parse($budget->validity_date)->format('d/m/Y') : '10 dias' }}
            </td>
        </tr>
    </table>

    <!-- Tabela: Dados da Empresa Contratada -->
    @if($company->name || $company->cnpj || $company->full_address || $company->phone || $company->crq)
        <table>
            <tr>
                <td colspan="2" class="section-title">DADOS DA EMPRESA</td>
            </tr>
            <tr>
                <td class="col-70"><strong>Razão Social: </strong>{{ $company->name }}</td>
                <td class="col-30"><strong>CNPJ: </strong>{{ $company->cnpj }}</td>
            </tr>
            <tr>
                <td colspan="2"><strong>Endereço:</strong> {{ $company->full_address }}</td>
            </tr>
            <tr>
                <td class="col-50"><strong>Telefone:</strong> {{ $company->phone }}</td>
                <td class="col-50">
                    @if($company->crq)
                        CRQ - {{ $company->crq }}
                    @endif
                </td>
            </tr>
        </table>
    @endif

    <!-- Tabela: Dados do Cliente -->
    <table>
        <tr>
            <td colspan="2" class="section-title">DADOS DO CLIENTE</td>
        </tr>
        <tr>
            <td class="col-50"><strong>Cliente:
                </strong>{{ $budget->client ? $budget->client->name : $budget->prospect_name }}</td>
            <td class="col-50"><strong>Telefone:
                </strong>{{ $budget->client ? $budget->client->phone : $budget->prospect_phone }}</td>
        </tr>
        <tr>
            <td colspan="2"><strong>Endereço:
                </strong>{{ $budget->client ? $budget->client->address : $budget->prospect_address }}</td>
        </tr>
        @if($budget->client && $budget->client->cnpj)
            <tr>
                <td colspan="2"><strong>CPF/CNPJ: </strong>{{ $budget->client->cnpj }}</td>
            </tr>
        @endif
    </table>

    <!-- Tabela: Detalhes Técnicos -->
    <table>
        <tr>
            <td colspan="2" class="section-title">DETALHES TÉCNICOS</td>
        </tr>
        @php
            $environments = [
                'residential' => 'Residencial',
                'commercial' => 'Comercial',
                'industrial' => 'Industrial',
                'food' => 'Alimentício',
                'hospital' => 'Hospitalar',
                'school' => 'Escola',
                'condo' => 'Condomínio',
                'other' => 'Outros',
            ];
            $envLabel = $budget->environment_type && isset($environments[$budget->environment_type])
                ? $environments[$budget->environment_type]
                : ($budget->environment_type ? ucfirst($budget->environment_type) : '-');
        @endphp
        <tr>
            <td class="col-50"><strong>Ambiente: </strong>{{ $envLabel }}</td>
            <td class="col-50"><strong>Pragas Alvo:
                </strong>{{ $budget->target_pests ? implode(', ', $budget->target_pests) : '-' }}</td>
        </tr>
        <tr>
            <td colspan="2"><strong>Áreas a Tratar:
                </strong>{{ $budget->areas_to_treat ? implode(', ', $budget->areas_to_treat) : '-' }}</td>
        </tr>
        @if($budget->observations)
            <tr>
                <td colspan="2"><strong>Observações: </strong>{{ $budget->observations }}</td>
            </tr>
        @endif
    </table>

    <!-- Tabela: Serviços Propostos -->
    <table>
        <tr>
            <td colspan="4" class="section-title">SERVIÇOS PROPOSTOS</td>
        </tr>
        <tr>
            <th>Descrição</th>
            <th class="text-right" style="width: 80px;">Qtd</th>
            <th class="text-right" style="width: 100px;">Valor Unit.</th>
            <th class="text-right" style="width: 100px;">Subtotal</th>
        </tr>

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
                <td colspan="3" class="text-right"><strong>Subtotal</strong></td>
                <td class="text-right">R$ {{ number_format($total, 2, ',', '.') }}</td>
            </tr>
            <tr>
                <td colspan="3" class="text-right"><strong>Desconto</strong></td>
                <td class="text-right">- R$ {{ number_format($budget->discount, 2, ',', '.') }}</td>
            </tr>
        @endif

        <tr>
            <td colspan="3" class="text-right" style="background-color: #f3f4f6;"><strong>TOTAL</strong></td>
            <td class="text-right" style="background-color: #f3f4f6;"><strong>R$
                    {{ number_format($total - $budget->discount, 2, ',', '.') }}</strong></td>
        </tr>
    </table>

    <!-- Tabela: Condições Comerciais -->
    <table>
        <tr>
            <td colspan="2" class="section-title">CONDIÇÕES COMERCIAIS</td>
        </tr>
        <tr>
            <td class="col-50"><strong>Forma de Pagamento: </strong>{{ $budget->payment_method }}
                {{ $budget->payment_conditions ? "({$budget->payment_conditions})" : '' }}
            </td>
            <td class="col-50"><strong>Prazo de Execução: </strong>{{ $budget->execution_deadline ?: 'A combinar' }}
            </td>
        </tr>
    </table>



    @if($company->signature_responsible_path)
        <div style="margin-top: 30px; text-align: center;">
            <img src="{{ storage_path('app/public/' . $company->signature_responsible_path) }}"
                style="height: 60px; width: auto;" alt="Assinatura Responsável"><br>
            <div
                style="font-size: 10px; margin-top: 5px; border-top: 1px solid #000; display: inline-block; padding-top: 5px; min-width: 200px;">
                Responsável pelo Orçamento
            </div>
        </div>
    @endif

    <div class="footer">
        <p>Este orçamento é válido até a data de validade indicada acima e está sujeito a alteração sem aviso prévio
            após este período.</p>
        <p>Gerado em {{ date('d/m/Y H:i') }}</p>
    </div>
</body>

</html>