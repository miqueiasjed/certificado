{{--
    Ficha de EPI do técnico (Plano 28, Task 28.5).

    Registro de fornecimento de EPI exigido pelo item 6.5.1 da NR-6. O documento
    é oponível: arquivamento recomendado de 20 anos e prova do empregador em
    reclamatória trabalhista.

    Três regras que esta view não pode quebrar:

    - O CA impresso é o da entrega (cópia gravada no ato), nunca o do cadastro.
      Quem monta os dados é o FichaDeEpiService; aqui não se lê relação nenhuma.
    - Entrega sem assinatura sai marcada como "Pendente de assinatura", e não em
      branco. Entrega estornada sai marcada, com o motivo — omiti-la seria
      adulterar o registro.
    - Nenhuma data é formatada aqui. Tudo chega em texto, já no fuso do negócio.

    O rodapé afirma o que o documento é, e não que a empresa está regular:
    treinamento, uso em campo, higienização e guarda o sistema não enxerga.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Ficha de EPI - {{ $tecnico['nome'] }}</title>
    <style>
        @page {
            margin: 22px 22px 86px 22px;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 10px;
            margin: 0;
            padding: 0;
            color: #333;
            line-height: 1.3;
        }

        .header {
            text-align: center;
            margin-bottom: 14px;
        }

        .header img {
            height: 70px;
            width: auto;
            display: block;
            margin: 0 auto 8px auto;
        }

        .header h1 {
            color: #059669;
            font-size: 18px;
            margin: 6px 0 2px 0;
            font-weight: bold;
            text-transform: uppercase;
        }

        .header .subtitulo {
            font-size: 10px;
            color: #666;
        }

        .company-info {
            text-align: center;
            margin: 0 0 16px 0;
            font-size: 10px;
            color: #059669;
            line-height: 1.3;
            padding: 8px;
            border: 2px solid #059669;
            border-radius: 8px;
            background: #f0fdf4;
        }

        .company-info p {
            margin: 2px 0;
        }

        .section-title {
            font-size: 12px;
            font-weight: bold;
            background-color: #f0fdf4;
            padding: 6px 10px;
            margin: 16px 0 8px 0;
            border-left: 4px solid #059669;
            color: #059669;
            text-transform: uppercase;
        }

        table.info-table {
            width: 100%;
            border-collapse: collapse;
        }

        table.info-table td {
            padding: 6px 8px;
            vertical-align: top;
            font-size: 10px;
            border: 1px solid #e0e0e0;
            width: 33.33%;
        }

        table.info-table td strong {
            display: block;
            font-weight: bold;
            color: #059669;
            font-size: 9px;
            text-transform: uppercase;
            margin-bottom: 2px;
        }

        table.entregas {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }

        table.entregas th {
            background-color: #f0fdf4;
            color: #059669;
            font-size: 8px;
            text-transform: uppercase;
            text-align: left;
            padding: 5px 4px;
            border: 1px solid #d1fae5;
        }

        table.entregas td {
            font-size: 9px;
            padding: 5px 4px;
            border: 1px solid #e0e0e0;
            vertical-align: top;
        }

        table.entregas td.centro {
            text-align: center;
        }

        .epi-detalhe {
            display: block;
            color: #777;
            font-size: 8px;
        }

        .linha-estornada td {
            background-color: #fdf2f2;
            color: #7a2b2b;
        }

        .badge {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 8px;
            font-weight: bold;
            font-size: 8px;
            text-transform: uppercase;
        }

        .badge-estornada {
            background-color: #f8d7da;
            color: #721c24;
        }

        .badge-pendente {
            background-color: #fef3c7;
            color: #92400e;
        }

        .badge-devolvida {
            background-color: #e5e7eb;
            color: #374151;
        }

        /*
         * Largura fixa em vez de `max-width`: o dompdf ignora `max-width` e
         * `max-height` e renderiza a imagem no tamanho natural, que estoura a
         * coluna e embaralha a data ao lado.
         */
        .assinatura-img {
            width: 92px;
            height: auto;
            display: block;
            margin-bottom: 2px;
        }

        .assinatura-data {
            display: block;
            font-size: 8px;
            color: #555;
        }

        .observacao td {
            font-size: 8px;
            background-color: #fdf2f2;
            color: #7a2b2b;
        }

        .observacao-neutra td {
            font-size: 8px;
            background-color: #fafafa;
            color: #555;
        }

        .neutro {
            color: #888;
        }

        .sem-entregas {
            padding: 14px;
            text-align: center;
            color: #666;
            border: 1px dashed #ccc;
            border-radius: 6px;
        }

        .resumo {
            margin-top: 10px;
            font-size: 10px;
            color: #444;
        }

        .resumo strong {
            color: #059669;
        }

        .declaracao {
            margin-top: 16px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 10px;
            font-size: 9px;
            line-height: 1.5;
            text-align: justify;
        }

        /* Declaração e linhas de assinatura andam juntas: separar as duas em
           páginas diferentes produziria uma folha assinada sem o texto que se
           está assinando. */
        .fecho {
            page-break-inside: avoid;
        }

        table.assinaturas {
            width: 100%;
            border-collapse: collapse;
            margin-top: 30px;
        }

        table.assinaturas td {
            width: 50%;
            text-align: center;
            font-size: 9px;
            padding: 0 14px;
        }

        .linha-assinatura {
            border-top: 1px solid #333;
            padding-top: 4px;
        }

        .rodape {
            position: fixed;
            left: 0;
            right: 0;
            bottom: -68px;
            font-size: 8px;
            color: #777;
            line-height: 1.4;
            text-align: center;
            border-top: 1px solid #e0e0e0;
            padding-top: 6px;
        }

        .rodape .norma {
            color: #444;
        }
    </style>
</head>
<body>
    {{-- Rodapé fixo: a nota legal precisa estar em toda página, porque a ficha
         de um técnico antigo passa de uma folha. --}}
    <div class="rodape">
        <p class="norma">
            Este documento é o registro de fornecimento de Equipamento de Proteção Individual
            de que trata o item 6.5.1 da NR-6, e reproduz as entregas registradas neste sistema
            para o trabalhador identificado acima.
        </p>
        <p>
            O documento comprova o que está cadastrado, e não a regularidade da empresa:
            treinamento, uso efetivo em campo, higienização e guarda dos equipamentos
            não são verificados por este sistema.
        </p>
        <p>Emitido em {{ $geradoEm }}.</p>
    </div>

    <div class="header">
        @if($logoSrc)
            <img src="{{ $logoSrc }}" alt="Logo">
        @endif
        <h1>Ficha de Entrega de EPI</h1>
        <div class="subtitulo">Registro de fornecimento de Equipamento de Proteção Individual — NR-6</div>
    </div>

    <div class="company-info">
        @if($empresa['nome'])
            <p><strong>{{ $empresa['nome'] }}</strong></p>
        @endif
        @if($empresa['cnpj'])
            <p>CNPJ: {{ $empresa['cnpj'] }}</p>
        @endif
        @if($empresa['endereco'])
            <p>{{ $empresa['endereco'] }}</p>
        @endif
        @if($empresa['telefone'])
            <p>Fone: {{ $empresa['telefone'] }}</p>
        @endif
    </div>

    <div class="section-title">Trabalhador</div>
    <table class="info-table">
        <tr>
            <td>
                <strong>Nome</strong>
                {{ $tecnico['nome'] }}
            </td>
            <td>
                <strong>Registro</strong>
                {{ $tecnico['registro'] }}
            </td>
            <td>
                <strong>Situação no cadastro</strong>
                {{ $tecnico['situacao'] }}
            </td>
        </tr>
        <tr>
            <td>
                <strong>Função / especialidade</strong>
                {{ $tecnico['especialidade'] }}
            </td>
            <td>
                <strong>E-mail</strong>
                {{ $tecnico['email'] }}
            </td>
            <td>
                <strong>Telefone</strong>
                {{ $tecnico['telefone'] }}
            </td>
        </tr>
    </table>

    <div class="section-title">Equipamentos entregues</div>

    @if(count($entregas) > 0)
        <table class="entregas">
            <thead>
                <tr>
                    <th style="width: 8%;">Entrega</th>
                    <th style="width: 20%;">EPI</th>
                    <th style="width: 5%;">Qtd.</th>
                    <th style="width: 10%;">CA</th>
                    <th style="width: 9%;">Validade do CA</th>
                    <th style="width: 11%;">Motivo</th>
                    <th style="width: 9%;">Trocar até</th>
                    <th style="width: 9%;">Devolução</th>
                    <th style="width: 19%;">Assinatura do recebimento</th>
                </tr>
            </thead>
            <tbody>
                @foreach($entregas as $entrega)
                    <tr class="{{ $entrega['estornada'] ? 'linha-estornada' : '' }}">
                        <td>{{ $entrega['entregue_em'] }}</td>
                        <td>
                            {{ $entrega['epi'] }}
                            <span class="epi-detalhe">
                                {{ $entrega['tipo'] }}@if($entrega['fabricante']) — {{ $entrega['fabricante'] }}@endif
                            </span>
                        </td>
                        <td class="centro">{{ $entrega['quantidade'] }}</td>
                        {{-- CA da entrega, copiado no ato. Em branco é estado
                             neutro, nunca irregularidade. --}}
                        <td>{{ $entrega['numero_ca'] }}</td>
                        <td>{{ $entrega['validade_ca'] }}</td>
                        <td>{{ $entrega['motivo'] }}</td>
                        <td>
                            @if($entrega['tem_troca_programada'])
                                {{ $entrega['trocar_ate'] }}
                            @else
                                <span class="neutro">{{ $entrega['trocar_ate'] }}</span>
                            @endif
                        </td>
                        <td>
                            @if($entrega['devolvido_em'])
                                {{ $entrega['devolvido_em'] }}
                            @else
                                <span class="neutro">—</span>
                            @endif
                        </td>
                        {{-- O estorno é marcado sem esconder a assinatura: uma
                             entrega assinada e depois estornada precisa
                             continuar mostrando que foi assinada. --}}
                        <td>
                            @if($entrega['estornada'])
                                <span class="badge badge-estornada">Estornada</span><br>
                            @endif
                            @if($entrega['assinada'])
                                @if($entrega['assinatura_src'])
                                    <img class="assinatura-img" src="{{ $entrega['assinatura_src'] }}" alt="Assinatura">
                                @endif
                                <span class="assinatura-data">
                                    @if($entrega['assinado_em'])
                                        Assinada em {{ $entrega['assinado_em'] }}
                                    @else
                                        Assinatura registrada
                                    @endif
                                </span>
                            @else
                                <span class="badge badge-pendente">Pendente de assinatura</span>
                            @endif
                        </td>
                    </tr>

                    @if($entrega['estornada'])
                        {{-- O estorno é parte da ficha: some daqui e o registro
                             passa a esconder o próprio erro. --}}
                        <tr class="observacao">
                            <td colspan="9">
                                <strong>Entrega estornada</strong>@if($entrega['estornada_em']) em {{ $entrega['estornada_em'] }}@endif
                                {{-- O motivo sai como o operador escreveu, sem
                                     pontuação acrescentada: é texto de registro. --}}
                                — motivo: {{ $entrega['motivo_estorno'] ?? 'não informado' }}
                            </td>
                        </tr>
                    @elseif($entrega['devolvido_em'] && $entrega['motivo_devolucao'])
                        <tr class="observacao-neutra">
                            <td colspan="9">
                                Devolvido em {{ $entrega['devolvido_em'] }} — motivo: {{ $entrega['motivo_devolucao'] }}
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>

        <div class="resumo">
            <strong>{{ $resumo['total'] }}</strong> entrega(s) registrada(s):
            <strong>{{ $resumo['validas'] }}</strong> válida(s) e
            <strong>{{ $resumo['estornadas'] }}</strong> estornada(s).
            @if($resumo['pendentes_de_assinatura'] > 0)
                <span class="badge badge-pendente">
                    {{ $resumo['pendentes_de_assinatura'] }} pendente(s) de assinatura
                </span>
            @endif
        </div>
    @else
        <div class="sem-entregas">
            Nenhuma entrega de EPI registrada para este trabalhador.
        </div>
    @endif

    {{-- Sem entrega nenhuma não há o que declarar recebido: a ficha vazia sai
         sem a declaração e sem as linhas de assinatura, para que ninguém assine
         um recebimento que não houve. --}}
    @if(count($entregas) > 0)
    <div class="fecho">
    <div class="declaracao">
        Declaro ter recebido os equipamentos de proteção individual relacionados nesta ficha,
        em perfeitas condições de uso, bem como as orientações sobre a sua utilização.
        Comprometo-me a usá-los apenas para a finalidade a que se destinam, a responsabilizar-me
        pela guarda e conservação, a comunicar qualquer alteração que os torne impróprios para uso
        e a devolvê-los quando solicitado ou ao término do contrato de trabalho.
    </div>

    <table class="assinaturas">
        <tr>
            <td>
                <div class="linha-assinatura">
                    {{ $tecnico['nome'] }}<br>
                    Trabalhador
                </div>
            </td>
            <td>
                <div class="linha-assinatura">
                    {{ $empresa['nome'] }}<br>
                    Responsável pela entrega
                </div>
            </td>
        </tr>
    </table>
    </div>
    @endif
</body>
</html>
