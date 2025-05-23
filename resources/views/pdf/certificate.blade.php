<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificado de Garantia</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
        }

        .certificate-container {
            width: 29.7cm;
            height: 21cm;
            margin: 10px auto;
            background-color: white;
            position: relative;
            border: 6px solid #4CAF50;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
            padding: 15px;
            box-sizing: border-box;
        }

        .certificate-container::before {
            content: '';
            position: absolute;
            top: 8px;
            left: 8px;
            right: 8px;
            bottom: 8px;
            border: 1px solid #4CAF50;
            pointer-events: none;
        }

        .header {
            text-align: center;
            margin-bottom: 10px;
            position: relative;
        }

        .logo-container {
            margin-bottom: 8px;
        }

        .logo-image {
            width: 120px;
            height: auto;
            margin: 0 auto 5px;
            display: block;
        }

        .company-info {
            text-align: center;
            margin-bottom: 10px;
            font-size: 10px;
            color: #2f3091;
            line-height: 1.2;
        }

        .cert-title {
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            margin: 10px 0;
            color: #333;
        }

        .client-info {
            margin-bottom: 8px;
            font-size: 11px;
        }

        .client-info p {
            margin: 2px 0;
        }

        .service-info {
            font-size: 12px;
            text-align: center;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-around;
            background: #f9f9f9;
            padding: 5px;
            border-radius: 3px;
        }

        .service-info p {
            margin: 0;
            padding: 0 5px;
        }

        .procedures {
            text-align: center;
            margin-bottom: 8px;
            font-size: 11px;
            background: #e8f5e8;
            padding: 5px;
            border-radius: 3px;
        }

        .procedures p {
            margin: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            text-align: center;
        }

        table, th, td {
            border: 1px solid #4CAF50;
        }

        th {
            background-color: #4CAF50;
            color: white;
            padding: 6px;
            font-size: 10px;
        }

        td {
            padding: 4px;
            text-align: center;
            vertical-align: middle;
            font-size: 9px;
        }

        .footer-container {
            position: relative;
            height: 80px;
            margin-top: 10px;
        }

        .footer-ceatox {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50%;
            text-align: left;
            font-size: 9px;
        }

        .footer-ceatox p {
            margin: 1px 0;
        }

        .footer-ambiental {
            position: absolute;
            top: 0;
            right: 120px;
            text-align: center;
            font-size: 10px;
            background: #f0f8f0;
            border: 1px solid #4CAF50;
            padding: 3px;
            border-radius: 3px;
            width: 80px;
        }

        .footer-ambiental p {
            margin: 1px 0;
        }

        .footer-sanitario {
            position: absolute;
            top: 0;
            right: 30px;
            text-align: center;
            font-size: 10px;
            background: #f0f8f0;
            border: 1px solid #4CAF50;
            padding: 3px;
            border-radius: 3px;
            width: 80px;
        }

        .footer-sanitario p {
            margin: 1px 0;
        }

        .signature-1 {
            position: absolute;
            bottom: 0;
            right: 120px;
            text-align: center;
            width: 80px;
        }

        .signature-1 img {
            width: 60px;
            height: auto;
            margin-bottom: 2px;
        }

        .signature-1 p {
            margin: 0;
            font-size: 8px;
            font-weight: bold;
        }

        .signature-2 {
            position: absolute;
            bottom: 0;
            right: 20px;
            text-align: center;
            width: 80px;
        }

        .signature-2 img {
            width: 70px;
            height: auto;
            margin-bottom: 2px;
        }

        .signature-2 p {
            margin: 0;
            font-size: 8px;
            font-weight: bold;
        }

        .green-accent {
            background: linear-gradient(90deg, #4CAF50, rgba(76,175,80,0.3), #4CAF50);
            height: 2px;
            margin: 8px 0;
        }

        /* Para impressão - forçar uma página única */
        @media print {
            body {
                background-color: white;
            }
            .certificate-container {
                margin: 0;
                box-shadow: none;
                border: 6px solid #4CAF50;
                page-break-inside: avoid;
                page-break-after: avoid;
                page-break-before: avoid;
            }

            table {
                page-break-inside: avoid;
            }

            .footer-container {
                page-break-inside: avoid;
            }
        }

        @page {
            size: A4 landscape;
            margin: 0.5cm;
        }
    </style>
</head>

<body>
    <div class="certificate-container">
        <div class="header">
            <div class="logo-container">
                <img src="{{ public_path('images/logo-ari-dedetizacao.png') }}" alt="ARI Dedetização" class="logo-image">
            </div>

            <div class="company-info">
                <p><strong>CNPJ:</strong> 19.228.297/0001-75</p>
                <p>Comunidade 2º Vila Córrego dos Furtados, 153</p>
                <p>Bairro Córrego Fundo, Município de Trairi-CE</p>
                <p><strong>Fone:</strong> (85) 99993-8745</p>
            </div>
        </div>

        <div class="green-accent"></div>

        <h1 class="cert-title">CERTIFICADO DE GARANTIA</h1>

        <div class="client-info">
            <p><strong>Cliente:</strong> {{ $certificate->client->name }}</p>
            <p><strong>CNPJ:</strong> {{ $certificate->client->cnpj }}</p>
            <p><strong>Endereço:</strong>
                {{ $certificate->client->address }},{{ $certificate->client->number }},{{ $certificate->client->neighborhood }},{{ $certificate->client->city }}
            </p>
        </div>

        <div class="service-info">
            <p><strong>Serviço Prestado:</strong> {{ $certificate->service->description }}</p>
            <p><strong>Data do Serviço:</strong> {{ $certificate->date->format('d/m/Y') }}</p>
            <p><strong>Garantia:</strong> {{ $certificate->assurance->format('d/m/Y') }}</p>
        </div>

        <div class="procedures">
            <p><strong>Procedimento Utilizado:</strong>
                @foreach ($certificate->procedures as $procedure)
                    {{ $procedure->name }}@if (!$loop->last)
                        -
                    @endif
                @endforeach
            </p>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Princípio Ativo</th>
                    <th>Grupo Químico</th>
                    <th>Antídoto</th>
                    <th>Registro do Ministério da Saúde</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($certificate->products as $product)
                    <tr>
                        <td>{{ $product->activeIngredient->name }}</td>
                        <td>{{ $product->chemicalGroup->name }}</td>
                        <td>{{ $product->antidote->name }}</td>
                        <td>{{ $product->organRegistration->record }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Footer com altura limitada -->
        <div class="footer-container">
            <!-- CEATOX -->
            <div class="footer-ceatox">
                <p>CEATOX: (85) 3235-5050 (CENTRO DE ASSISTÊNCIA TOXICOLÓGICA)</p>
                <p>CONSELHO REGIONAL DE QUÍMICA - 10º REGIÃO Nº 5.253</p>
            </div>

            <!-- N° Licença Ambiental -->
            <div class="footer-ambiental">
                <p><strong>N° Licença Ambiental</strong></p>
                <p>177/2024</p>
            </div>

            <!-- N° Alvará Sanitário -->
            <div class="footer-sanitario">
                <p><strong>N° Alvará Sanitário</strong></p>
                <p>062/2025</p>
            </div>

            <!-- Assinatura 1 -->
            <div class="signature-1">
                <img src="{{ public_path('images/signature-operational.png') }}" alt="Assinatura Gerente">
                <p><strong>Ass:</strong> Gerente de Operações</p>
            </div>

            <!-- Assinatura 2 -->
            <div class="signature-2">
                <img src="{{ public_path('images/signature-quimico.png') }}" alt="Assinatura Químico">
                <p><strong>Ass:</strong> Químico Industrial</p>
            </div>
        </div>
    </div>
</body>

</html>
