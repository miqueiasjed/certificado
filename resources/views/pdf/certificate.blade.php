<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificado de Garantia</title>
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

        .full-width {
            width: 100%;
        }

        .col-33 {
            width: 33%;
        }

        .col-50 {
            width: 50%;
        }

        .col-70 {
            width: 70%;
        }

        .col-30 {
            width: 30%;
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
            margin-top: 8px;
        }

        .signature-table td {
            border: none;
            text-align: center;
            padding: 8px;
            vertical-align: bottom;
        }

        .signature-img {
            width: 80px;
            height: 80px;
            display: block;
            margin: 0 auto 5px auto;
            object-fit: contain;
        }

        .signature-line {
            border-top: 1px solid #000;
            padding-top: 5px;
            margin-top: 5px;
            font-size: 10px;
            text-align: center;
            min-height: 35px;
        }

        .page-number {
            text-align: right;
            margin-top: 8px;
            font-size: 9px;
        }
    </style>
</head>

<body>
    @php
        $company = \App\Models\Company::current();
    @endphp
    <!-- Cabeçalho -->
    <div class="header-section">
        <div class="logo-container">
            @if($logoSrc)
                <img src="{{ $logoSrc }}" alt="Logo">
            @endif
        </div>
        <div class="document-title">
            CERTIFICADO DE GARANTIA
        </div>
    </div>

    <!-- Tabela: Dados do Certificado -->
    <table>
        <tr>
            <td class="col-33"><strong>N° do Certificado: </strong>{{ $certificate->id ?? 'Não informado' }}</td>
            <td class="col-33"><strong>Data de Emissão: </strong>
                {{ $certificate->created_at ? $certificate->created_at->format('d/m/Y') : 'Não informada' }}
            </td>
            <td class="col-33"><strong>Data de Validade:
                </strong>{{ $certificate->warranty ? $certificate->warranty->format('d/m/Y') : 'Não informada' }}</td>
        </tr>
    </table>

    <!-- Tabela: Dados da Empresa Contratada -->
    @if($company->name || $company->cnpj || $company->full_address || $company->phone)
        <table>
            <tr>
                <td colspan="2" class="section-title">DADOS DA EMPRESA CONTRATADA</td>
            </tr>
            <tr>
                <td class="col-70"><strong>Razão Social: </strong>{{ $company->name }}</td>
                <td class="col-30"><strong>CNPJ: </strong>{{ $company->cnpj }}</td>
            </tr>
            <tr>
                <td colspan="2"><strong>Endereço:</strong> {{ $company->full_address }}</td>
            </tr>
            <tr>
                <td class="col-50"><strong>Telefone</strong> {{ $company->phone }}</td>
                <td class="col-50">
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
            <td class="col-50"><strong>Nome/Razão Social: </strong>{{ $certificate->client->name ?? 'Não informado' }}
            </td>
            <td class="col-50"><strong>CPF/CNPJ: </strong>{{ $certificate->client->cnpj ?? 'Não informado' }}</td>
        </tr>
        <tr>
            <td colspan="2"><strong>Local do Serviço: </strong>
                @php
                    $address = null;
                    if ($certificate->workOrder && $certificate->workOrder->address) {
                        $address = $certificate->workOrder->address;
                    } elseif ($certificate->address) {
                        $address = $certificate->address;
                    }
                @endphp
                @if($address)
                    {{ $address->street }}, {{ $address->number }}@if($address->district) - {{ $address->district }}@endif,
                    {{ $address->city }}/{{ $address->state }}@if($address->zip) - CEP: {{ $address->zip }}@endif
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
            <td colspan="2"><strong>Serviço Prestado:</strong>
                @if($certificate->service)
                    {{ $certificate->service->name }}
                @else
                    Não informado
                @endif
            </td>
        </tr>

        <tr>
            <td class="col-50"><strong>Data da Execução:</strong>
                {{ $certificate->execution_date ? $certificate->execution_date->format('d/m/Y') : ($certificate->workOrder->scheduled_date?->format('d/m/Y') ?? 'Não informada') }}
            </td>
            <td class="col-50"><strong>Procedimento Utilizado:</strong>
                {{ $certificate->procedure_used ?? 'Não informado' }}
            </td>
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
    @if($company->license_environmental || $company->license_sanitary || $company->license_business || $company->register_visa || $company->register_crea || $company->ceatox_info)
        <table>
            <tr>
                <td colspan="3" class="section-title">INFORMAÇÕES LEGAIS E DE SEGURANÇA</td>
            </tr>
            <tr>
                <td class="col-33 text-center">
                    @if($company->license_environmental)
                        <strong>N° Licença Ambiental: </strong>{{ $company->license_environmental }}
                    @endif
                </td>
                <td class="col-33 text-center">
                    @if($company->license_sanitary)
                        <strong>N° Alvará Sanitário: </strong>{{ $company->license_sanitary }}
                    @endif
                </td>
                <td class="col-33 text-center">
                    @if($company->license_business)
                        <strong>N° Alvará de Funcionamento: </strong>{{ $company->license_business }}
                    @endif
                </td>
            </tr>
            @if($company->register_visa || $company->register_crea)
                <tr>
                    <td class="col-33 text-center">
                        @if($company->register_visa)
                            <strong>Registro VISA: </strong>{{ $company->register_visa }}
                        @endif
                    </td>
                    <td class="col-33 text-center">
                        @if($company->register_crea)
                            <strong>Registro CREA: </strong>{{ $company->register_crea }}
                        @endif
                    </td>
                    <td class="col-33 text-center"></td>
                </tr>
            @endif
            <tr>
                <td colspan="3" style="padding: 6px; font-size: 9px;">
                    @if($company->ceatox_info)
                        <strong>Mais Informações:</strong> {{ $company->ceatox_info }}<br>
                    @endif
                    <strong>Obs.:</strong> Este certificado garante a eficácia do serviço até a data de validade indicada.
                </td>
            </tr>
        </table>
    @endif

    <!-- Tabela: Assinaturas -->
    @if($sigOpSrc || $sigChemSrc)
        <table class="signature-table">
            <tr>
                <td style="width: 50%;">
                    @if($sigOpSrc)
                        <img src="{{ $sigOpSrc }}" alt="Assinatura Gerente" class="signature-img">
                        <div class="signature-line">
                            <strong>Gerente de Operações</strong>
                        </div>
                    @endif
                </td>
                <td style="width: 50%;">
                    @if($sigChemSrc)
                        <img src="{{ $sigChemSrc }}" alt="Assinatura Químico" class="signature-img">
                        <div class="signature-line">
                            <strong>Químico Industrial</strong><br>

                        </div>
                    @endif
                </td>
            </tr>
        </table>
    @endif
</body>

</html>