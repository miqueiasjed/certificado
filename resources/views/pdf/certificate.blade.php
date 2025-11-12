<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificado de Garantia</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 15mm;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 9px;
            margin: 0;
            padding: 0;
            line-height: 1.2;
            color: #000;
            background-color: #d1fae5;
        }

        .header-section {
            text-align: center;
            margin-bottom: 8px;
            position: relative;
        }

        .logo-container {
            position: absolute;
            top: 0;
            right: 0;
        }

        .logo-container img {
            height: 60px;
            width: auto;
        }

        .document-title {
            font-weight: bold;
            font-size: 16px;
            text-transform: uppercase;
            background-color: #10b981;
            color: #fff;
            padding: 8px;
            border: 2px solid #000;
            margin-top: 35px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 5px;
            background-color: #fff;
        }

        table, th, td {
            border: 1px solid #000;
        }

        th, td {
            padding: 4px 6px;
            text-align: left;
            vertical-align: top;
            font-size: 9px;
        }

        th {
            background-color: #10b981;
            color: #fff;
            font-weight: bold;
        }

        .section-title {
            background-color: #10b981;
            color: #fff;
            font-weight: bold;
            font-size: 10px;
            padding: 5px 6px;
            text-align: left;
        }

        .full-width {
            width: 100%;
        }

        .col-33 {
            width: 33%;
        }

        .col-50 {
            width: 50%;
        }

        .text-center {
            text-align: center;
        }

        .text-bold {
            font-weight: bold;
        }

        .footer-section {
            margin-top: 20px;
        }

        .signature-table {
            background-color: transparent;
            border: none;
            margin-top: 5px;
        }

        .signature-table td {
            border: none;
            text-align: center;
            padding: 5px;
        }

        .signature-img {
            width: 60px;
            height: auto;
            display: block;
            margin: 0 auto 3px auto;
        }

        .page-number {
            text-align: right;
            margin-top: 5px;
            font-size: 8px;
        }
    </style>
</head>
<body>
    <!-- Cabeçalho -->
    <div class="header-section">
        <div class="logo-container">
            <img src="{{ public_path('images/logo.png') }}" alt="Logo">
        </div>
        <div class="document-title">
            CERTIFICADO DE GARANTIA
        </div>
    </div>

    <!-- Tabela: Dados do Certificado -->
    <table>
        <tr>
            <td class="col-33"><strong>N° do Certificado</strong></td>
            <td class="col-33"><strong>Data de Emissão</strong></td>
            <td class="col-33"><strong>Data de Validade (Garantia)</strong></td>
        </tr>
        <tr>
            <td>{{ $certificate->id ?? 'Não informado' }}</td>
            <td>{{ $certificate->execution_date ? $certificate->execution_date->format('d/m/Y') : ($certificate->workOrder->scheduled_date?->format('d/m/Y') ?? 'Não informada') }}</td>
            <td>{{ $certificate->warranty ? $certificate->warranty->format('d/m/Y') : 'Não informada' }}</td>
        </tr>
    </table>

    <!-- Tabela: Dados da Empresa Contratada -->
    <table>
        <tr>
            <td colspan="2" class="section-title">DADOS DA EMPRESA CONTRATADA</td>
        </tr>
        <tr>
            <td class="col-50"><strong>Razão Social</strong></td>
            <td class="col-50"><strong>CNPJ</strong></td>
        </tr>
        <tr>
            <td>EMPRESA CONTROLADORA DE PRAGAS LTDA</td>
            <td>19.228.297/0001-75</td>
        </tr>
        <tr>
            <td colspan="2"><strong>Endereço</strong></td>
        </tr>
        <tr>
            <td colspan="2">Comunidade 2º Vila Córrego dos Furtados, 153, Bairro Córrego Fundo, Município de Trairi-CE</td>
        </tr>
        <tr>
            <td class="col-50"><strong>Telefone</strong></td>
            <td class="col-50"><strong>CRQ - Conselho Regional de Química</strong></td>
        </tr>
        <tr>
            <td>(85) 99993-8745</td>
            <td>10º REGIÃO Nº 5.253</td>
        </tr>
    </table>

    <!-- Tabela: Dados do Cliente -->
    <table>
        <tr>
            <td colspan="2" class="section-title">DADOS DO CLIENTE</td>
        </tr>
        <tr>
            <td class="col-50"><strong>Nome/Razão Social</strong></td>
            <td class="col-50"><strong>CPF/CNPJ</strong></td>
        </tr>
        <tr>
            <td>{{ $certificate->client->name ?? 'Não informado' }}</td>
            <td>{{ $certificate->client->cnpj ?? 'Não informado' }}</td>
        </tr>
        <tr>
            <td colspan="2"><strong>Endereço do Local do Serviço</strong></td>
        </tr>
        <tr>
            <td colspan="2">
                @php
                    $address = null;
                    if ($certificate->workOrder && $certificate->workOrder->address) {
                        $address = $certificate->workOrder->address;
                    } elseif ($certificate->address) {
                        $address = $certificate->address;
                    }
                @endphp
                @if($address)
                    {{ $address->street }}, {{ $address->number }}@if($address->district) - {{ $address->district }}@endif, {{ $address->city }}/{{ $address->state }}@if($address->zip) - CEP: {{ $address->zip }}@endif
                @else
                    Não informado
                @endif
            </td>
        </tr>
    </table>

    <!-- Tabela: Dados do Serviço -->
    <table>
        <tr>
            <td colspan="2" class="section-title">DADOS DO SERVIÇO EXECUTADO</td>
        </tr>
        <tr>
            <td class="col-50"><strong>Serviço Prestado</strong></td>
            <td class="col-50"><strong>Data da Execução</strong></td>
        </tr>
        <tr>
            <td>
                @if($certificate->service)
                    {{ $certificate->service->name }}
                @else
                    Não informado
                @endif
            </td>
            <td>{{ $certificate->execution_date ? $certificate->execution_date->format('d/m/Y') : ($certificate->workOrder->scheduled_date?->format('d/m/Y') ?? 'Não informada') }}</td>
        </tr>
        <tr>
            <td colspan="2"><strong>Procedimento Utilizado</strong></td>
        </tr>
        <tr>
            <td colspan="2">{{ $certificate->procedure_used ?? 'Não informado' }}</td>
        </tr>
    </table>

    <!-- Tabela: Produtos Utilizados -->
    @if($certificate->products && $certificate->products->count() > 0)
    <table>
        <tr>
            <td colspan="4" class="section-title">PRODUTOS UTILIZADOS</td>
        </tr>
        <tr>
            <th>Princípio Ativo</th>
            <th>Grupo Químico</th>
            <th>Antídoto</th>
            <th>Registro MS</th>
        </tr>
        @foreach ($certificate->products as $product)
        <tr>
            <td>{{ $product->activeIngredient->name ?? 'Não informado' }}</td>
            <td>{{ $product->chemicalGroup->name ?? 'Não informado' }}</td>
            <td>{{ $product->antidote->name ?? 'Não informado' }}</td>
            <td>{{ $product->organRegistration->record ?? 'Não informado' }}</td>
        </tr>
        @endforeach
    </table>
    @else
    <table>
        <tr>
            <td class="section-title">PRODUTOS UTILIZADOS</td>
        </tr>
        <tr>
            <td>Nenhum produto informado</td>
        </tr>
    </table>
    @endif

    <!-- Tabela: Informações Legais -->
    <table>
        <tr>
            <td colspan="3" class="section-title">INFORMAÇÕES LEGAIS E DE SEGURANÇA</td>
        </tr>
        <tr>
            <td class="col-33 text-center"><strong>N° Licença Ambiental</strong></td>
            <td class="col-33 text-center"><strong>N° Alvará Sanitário</strong></td>
            <td class="col-33 text-center"><strong>CEATOX</strong></td>
        </tr>
        <tr>
            <td class="text-center">177/2024</td>
            <td class="text-center">062/2025</td>
            <td class="text-center">(85) 3235-5050</td>
        </tr>
        <tr>
            <td colspan="3" style="padding: 4px; font-size: 8px;">
                <strong>CEATOX:</strong> Centro de Assistência Toxicológica - Em caso de intoxicação ou acidente, ligue imediatamente.<br>
                <strong>Obs.:</strong> Este certificado garante a eficácia do serviço até a data de validade indicada.
            </td>
        </tr>
    </table>

    <!-- Tabela: Assinaturas -->
    <table class="signature-table">
        <tr>
            <td style="width: 50%;">
                <img src="{{ public_path('images/signature-operational.png') }}" alt="Assinatura Gerente" class="signature-img">
                <div style="border-top: 1px solid #000; padding-top: 3px; margin-top: 3px; font-size: 8px;">
                    <strong>Gerente de Operações</strong>
                </div>
            </td>
            <td style="width: 50%;">
                <img src="{{ public_path('images/signature-quimico.png') }}" alt="Assinatura Químico" class="signature-img">
                <div style="border-top: 1px solid #000; padding-top: 3px; margin-top: 3px; font-size: 8px;">
                    <strong>Químico Industrial</strong><br>
                    CRQ 10º Região Nº 5.253
                </div>
            </td>
        </tr>
    </table>

    <!-- Número da Página -->
    <div class="page-number">
        Página 1 de 1
    </div>
</body>
</html>
