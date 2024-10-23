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
            padding: 30px;
            position: relative;
            z-index: 2;
        }

        .full-page-image {
            width: 29.7cm; /* Largura no formato A4 */
            height: 21cm; /* Altura no formato A4 */
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: -1; /* Garante que a imagem fique atrás do conteúdo */
            margin: 0;
            padding: 0;
            background-image: url('{{ public_path('images/teste.png') }}');
            background-repeat: no-repeat;
            background-size: cover;
            background-position: center;
            page-break-before: always; /* Faz com que a imagem apareça em cada nova página */
        }

        .company-info {
            text-align: center;
            margin-top: 60px;
            font-size: 16px;
            color: #2f3091;
            line-height: 0.8;
            z-index: 2;
        }

        .cert-title {
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            margin-top: 10px; /* Ajuste o espaçamento superior entre o título e as informações da empresa */
            z-index: 2;
        }

        .client-info {
            display: block; /* Informações do cliente uma abaixo da outra */
            margin-bottom: 20px;
            font-size: 16px;
            z-index: 2;
        }

        .client-info div {
            width: 48%; /* Cada coluna ocupa 48% da largura */
        }

        .service-info {
            font-size: 16px;
            text-align: center; /* Centraliza o conteúdo */
            margin-bottom: 20px; /* Espaço entre as informações e a tabela */
            z-index: 2;
        }
        .service-info p {
            display: inline-block; /* Exibe "Serviço Prestado", "Data do Serviço" e "Garantia" na mesma linha */
            margin: 0 15px; /* Espaço entre os itens */
        }

        p {
            margin: 5px 0; /* Ajusta o espaçamento entre os parágrafos para as informações do cliente */
        }

        .content {
            margin: 30px;
            position: relative;
            z-index: 2;
        }

        h1 {
            text-align: center;
            font-size: 20px;
            margin-bottom: 20px;
        }

        /* Tabela centralizada */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            text-align: center; /* Centraliza o conteúdo dentro da tabela */
        }

        table, th, td {
            border: 1px solid black;
        }

        th, td {
            padding: 10px;
            text-align: center; /* Centraliza o conteúdo horizontalmente */
            vertical-align: middle; /* Centraliza o conteúdo verticalmente */
        }

        .section-title {
            font-size: 14px;
            margin-top: 20px;
        }

        .procedures p {
            font-size: 14px;
            margin: 5px 0;
            text-align: center; /* Centraliza o texto dos procedimentos */
        }

        .footer-ceatox {
            position: absolute;
            bottom: 30px; /* Fixa o CEATOX no rodapé */
            left: 0;
            width: 50%; /* O CEATOX ocupa metade da largura */
            text-align: left;
            z-index: 2;
        }

        .signature-1{
            position: absolute;
            bottom: 30px; /* Fixa o CEATOX no rodapé */
            left: 60%;
            width: 50%; /* O CEATOX ocupa metade da largura */
            text-align: left;
            z-index: 2;
        }

        .signature-2{
            position: absolute;
            bottom: 30px; /* Fixa o CEATOX no rodapé */
            left: 80%;
            width: 50%; /* O CEATOX ocupa metade da largura */
            text-align: left;
            z-index: 2;
        }

        .signature-1 img {
            width: 100px;
            height: auto;
            margin-bottom: 5px;
        }

        .signature-2 img {
            width: 100px;
            height: auto;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>

    <!-- Imagem de fundo ocupando toda a página -->
    <img src="{{ public_path('images/teste.png') }}" class="full-page-image" alt="Overlay">

    <!-- Informações da empresa -->
    <div class="company-info">
        <p><strong>CNPJ:</strong> 19.228.297/0001-75</p>
        <p>Comunidade 2º Vila Córrego dos Furtados, 153</p>
        <p>Bairro Córrego Fundo, Município de Trairi-CE</p>
        <p><strong>Fone:</strong> (85) 99993-8745</p>
    </div>

    <!-- Título do certificado -->
    <h1 class="cert-title">CERTIFICADO DE GARANTIA</h1>

     <!-- Informações do cliente e serviço em duas colunas -->
    <div class="client-info">
        <p><strong>Cliente:</strong> {{ $certificate->client->name }}</p>
        <p><strong>CNPJ:</strong> {{ $certificate->client->cnpj }}</p>
        <p><strong>Endereço:</strong> {{ $certificate->client->address }}</p>
        <p><strong>Serviço Prestado:</strong> {{ $certificate->service->description }}</p>
    </div>

    <!-- Serviço Prestado, Data do Serviço, Garantia centralizados -->
    <div class="service-info">
        <p><strong>Serviço Prestado:</strong> {{ $certificate->service->description }}</p>
        <p><strong>Data do Serviço:</strong> {{ $certificate->date->format('d/m/Y') }}</p>
        <p><strong>Garantia:</strong> {{ $certificate->assurance->format('d/m/Y') }} Dias</p>
    </div>

    <!-- Conteúdo principal -->
    <div class="content">
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

        <div class="procedures">
            <p><strong>Procedimento Utilizado:</strong>
                @foreach ($certificate->procedures as $procedure)
                    {{ $procedure->name }}@if(!$loop->last) - @endif
                @endforeach
            </p>
        </div>
    </div>

    <!-- CEATOX em um contêiner separado -->
    <div class="footer-ceatox">
        <p>CEATOX: (85) 3235-5050 (CENTRO DE ASSISTÊNCIA TOXICOLÓGICA)</p>
        <p>CONSELHO REGIONAL DE QUÍMICA - 10º REGIÃO Nº 5.253</p>
    </div>

    <!-- signature 1 em um contêiner separado -->
    <div class="signature-1">
        <img src="{{ public_path('images/signature-quimico.png') }}" alt="Assinatura Gerente">
        <p><strong>Ass:</strong> Gerente de Operações</p>
    </div>

    <!-- signature 2 em um contêiner separado -->
    <div class="signature-2">
        <img src="{{ public_path('images/signature-quimico.png') }}" alt="Assinatura Químico">
        <p><strong>Ass:</strong> Químico Industrial</p>
    </div>

</body>
</html>
