<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contrato de Dedetização</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            margin: 0;
            padding: 40px;
            line-height: 1.6;
            color: #333;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            font-weight: bold;
            font-size: 16px;
        }

        .section {
            margin-bottom: 20px;
            text-align: justify;
        }

        .section-title {
            font-weight: bold;
            font-size: 13px;
            margin-top: 15px;
            margin-bottom: 10px;
        }

        .clause {
            margin-bottom: 15px;
        }

        .clause-title {
            font-weight: bold;
            margin-bottom: 8px;
        }

        .signature-section {
            margin-top: 50px;
            page-break-inside: avoid;
        }

        .signature-box {
            margin-top: 60px;
            text-align: center;
            border-top: 1px solid #333;
            padding-top: 10px;
            width: 300px;
            margin-left: auto;
            margin-right: auto;
        }

        .company-info {
            text-align: center;
            margin-bottom: 20px;
            font-size: 11px;
        }

        .fill-field {
            border-bottom: 1px solid #333;
            display: inline-block;
            min-width: 200px;
            padding: 0 5px;
        }
    </style>
</head>

<body>
    <!-- Cabeçalho com Logo e Título -->
    <div style="text-align: center; margin-bottom: 20px;">
        @php
            $logoPath = $company->logo_path ? public_path('storage/' . $company->logo_path) : null;
        @endphp
        @if($logoPath)
            <img src="{{ $logoPath }}" alt="Logo"
                style="height: 200px; width: auto; display: block; margin: 0 auto 0px auto;">
        @endif
        <div class="header" style="margin-top: 10px;">
            CONTRATO DE PRESTAÇÃO DE SERVIÇOS DE<br>
            DEDETIZAÇÃO
        </div>
    </div>

    <div class="section">
        <p>Pelo presente instrumento particular, de um lado:</p>
        <p><strong>CONTRATADA:</strong> {{ $company->name }}, pessoa jurídica de direito privado
            @if($company->cnpj)
                , inscrita no CNPJ n° {{ $company->cnpj }}
            @endif
            @if($company->street)
                , com sede à {{ $company->street }}
                @if($company->number), {{ $company->number }}@endif
                @if($company->district) - {{ $company->district }}@endif
                @if($company->city), {{ $company->city }}@endif
                @if($company->state)/{{ $company->state }}@endif
            @endif
            @if($company->legal_representative)
                , neste ato devidamente representada por {{ $company->legal_representative }}
                @if($company->legal_representative_cpf), portador do CPF n° {{ $company->legal_representative_cpf }}@endif
                @if($company->legal_representative_rg) e RG n° {{ $company->legal_representative_rg }}@endif
            @endif
            , doravante denominada CONTRATADA;
        </p>
    </div>

    <div class="section">
        <p>E, de outro lado:</p>
        <p><strong>CONTRATANTE:</strong> {{ $client->name }},
            @if($client->cnpj && strlen(preg_replace('/[^0-9]/', '', $client->cnpj)) == 14)
                inscrito no CNPJ n° {{ $client->cnpj }},
            @elseif($client->cnpj && strlen(preg_replace('/[^0-9]/', '', $client->cnpj)) == 11)
                inscrito no CPF n° {{ $client->cnpj }} e RG n° {{ 'XXX' }},
            @else
                inscrito no CPF n° [XXX.XXX.XXX-XX] e RG n° [XXX],
            @endif
            residente e domiciliado à
            {{ $address->street }}, {{ $address->number }} - {{ $address->district }},
            {{ $address->city }}/{{ $address->state }} - CEP: {{ $address->zip }}, doravante denominado CONTRATANTE;
        </p>
    </div>

    <div class="section">
        <p>CONTRATANTE e CONTRATADA são referidos individualmente como "Parte" e em conjunto como "Partes".</p>
        <p>As Partes acima identificadas têm justo e acordado o presente Contrato de Prestação de Serviços de
            Dedetização, que se regerá pelas cláusulas e condições seguintes, bem como pela legislação aplicável,
            em especial o Código Civil e o Código de Defesa do Consumidor (Lei nº 8.078/90), conforme o caso.</p>
    </div>

    <div class="section-title">CLÁUSULA 1ª – DO OBJETO</div>
    <div class="clause">
        <p><strong>1.1. Objeto do Contrato:</strong> O presente contrato tem por objeto a prestação, pela CONTRATADA, de
            serviços de dedetização, desinsetização e controle de pragas urbanas no imóvel do
            CONTRATANTE, localizado em {{ $address->street }}, {{ $address->number }} - {{ $address->district }},
            {{ $address->city }}/{{ $address->state }}. Os serviços visam combater e
            controlar pragas como insetos (baratas, formigas, mosquitos, etc.), aracnídeos, roedores e demais
            vetores especificados no escopo abaixo, mediante aplicação de técnicas e produtos adequados.
        </p>
    </div>

    <div class="clause">
        <p><strong>1.2. Serviço Pontual ou Periódico:</strong> Os serviços poderão ser prestados de forma:</p>
        <p><strong>Pontual (Visita Única):</strong> execução única do serviço de dedetização, na data agendada de
            {{ $contract->start_date ? $contract->start_date->format('d/m/Y') : '____/____/____' }}, abrangendo o
            tratamento das pragas indicadas neste contrato. Após a realização dessa
            visita e eventuais retornos dentro da garantia, o contrato será considerado cumprido.
        </p>
        <p><strong>Periódica (Manutenção Programada):</strong> execução de serviços de dedetização de forma contínua,
            com visitas periódicas
            @if($contract->service_type === 'periodico' && $contract->visit_frequency)
                @php
                    $frequencyLabels = [
                        'weekly' => 'semanais',
                        'biweekly' => 'quinzenais',
                        'monthly' => 'mensais'
                    ];
                    $frequencyLabel = $frequencyLabels[$contract->visit_frequency] ?? 'mensais';
                    $visitCount = $contract->visit_count ?? 1;
                    echo $visitCount . ' visita' . ($visitCount > 1 ? 's' : '') . ' ' . $frequencyLabel;
                @endphp
            @else
                [X visitas semanais/quinzenais/mensais]
            @endif
            durante o período de
            @if($contract->start_date && $contract->end_date)
                {{ $contract->start_date->diffInMonths($contract->end_date) }} meses
            @else
                [___] meses
            @endif
            , com início em {{ $contract->start_date ? $contract->start_date->format('d/m/Y') : '____/____/____' }} e
            término previsto em {{ $contract->end_date ? $contract->end_date->format('d/m/Y') : '____/____/____' }}. O
            cronograma de visitas
            deverá ser acordado entre as Partes e poderá constar em anexo a este contrato. Cada visita
            realizada será registrada em relatório/certificado individual.
        </p>
    </div>

    <div class="clause">
        <p><strong>1.3. Escopo das Pragas Tratadas:</strong> A dedetização abrangerá especificamente o combate às
            seguintes pragas-alvo, conforme necessidade identificada:
            @if($contract->pest_target)
                {{ $contract->pest_target }}
            @else
                [listar as pragas e/ou pragas comuns abrangidas, por exemplo: baratas, formigas, mosquitos, ratos, cupins,
                etc.]
            @endif
            . Qualquer extensão do
            escopo para pragas não listadas deverá ser objeto de aditivo ou contrato em separado.
        </p>
    </div>

    <div class="clause">
        <p><strong>1.4. Metodologia:</strong> A CONTRATADA empregará métodos adequados de controle de pragas, podendo
            incluir aplicação de gel inseticida em pontos estratégicos, pulverização com solução líquida,
            polvilhamento de inseticida em pó, instalação de iscas e armadilhas, entre outros, conforme o tipo de
            praga e o grau de infestação. Somente serão utilizados produtos saneantes regularizados e
            autorizados pela ANVISA, apropriados para o fim proposto, obedecendo às concentrações e modos
            de uso recomendados pelos fabricantes e pela legislação vigente. A aplicação será feita por técnicos
            capacitados e com equipamentos de proteção individual (EPIs), assegurando a segurança de todos
            os envolvidos.</p>
    </div>

    <div class="section-title">CLÁUSULA 2ª – DAS OBRIGAÇÕES DA CONTRATADA</div>
    <div class="clause">
        <p><strong>2.1. Qualidade e Segurança na Execução:</strong> A CONTRATADA obriga-se a prestar os serviços com
            diligência, qualidade e dentro das normas técnicas aplicáveis, adotando as melhores práticas de
            controle de pragas. Os profissionais designados deverão ser devidamente treinados e capacitados,
            atuando sob supervisão de um Responsável Técnico habilitado, nos termos das exigências legais.
            A empresa declara possuir as licenças sanitárias e ambientais necessárias ao exercício da atividade
            de dedetização, expedidas pelos órgãos competentes, e manterá tais autorizações válidas durante
            toda a vigência deste contrato.</p>
    </div>

    <div class="clause">
        <p><strong>2.2. Fornecimento de Materiais e Equipamentos:</strong> Todo o material necessário à execução do
            serviço – incluindo os produtos dedetizantes, equipamentos de aplicação, ferramentas e EPIs – será
            fornecido pela CONTRATADA, já incluído no preço contratado, salvo disposição expressa em
            contrário. A CONTRATADA utilizará produtos registrados na ANVISA e adequados ao ambiente
            (residencial, comercial ou industrial) do CONTRATANTE, evitando danos às pessoas, animais e ao
            patrimônio.</p>
    </div>

    <div class="clause">
        <p><strong>2.3. Orientações e Cuidados:</strong> Antes e durante a execução dos serviços, a CONTRATADA deverá
            orientar o CONTRATANTE sobre os procedimentos preparatórios e cuidados posteriores à
            dedetização. Isso inclui, mas não se limita a: cobrir ou armazenar alimentos, utensílios e objetos
            sensíveis; afastar pessoas, crianças, idosos ou animais do local durante a aplicação pelo tempo
            necessário; manter o ambiente ventilado após o serviço; limpar adequadamente superfícies conforme
            instrução; e quaisquer outras recomendações de segurança pertinentes.</p>
    </div>

    <div class="clause">
        <p><strong>2.4. Prazo de Realização:</strong> A prestação do serviço deverá ocorrer na data e horário agendados
            entre
            as Partes. Em caso de contrato periódico, as visitas seguirão o cronograma acordado.</p>
    </div>

    <div class="clause">
        <p><strong>2.5. Garantia do Serviço – Reaplicação:</strong> A CONTRATADA garante a eficácia dos serviços
            prestados até o término da vigência do contrato, conforme estabelecido na Cláusula 6ª. Durante a vigência do
            contrato, caso ocorra reincidência das pragas tratadas ou se o
            resultado não for satisfatório em função de vício na prestação do serviço, a CONTRATADA obriga-se
            a reexecutar o serviço sem custo adicional para o CONTRATANTE, dentro do escopo originalmente
            contratado, tão logo seja informada da falha.</p>
    </div>

    <div class="clause">
        <p><strong>2.6. Certificado de Execução do Serviço:</strong> Concluída cada aplicação, a CONTRATADA entregará
            ao CONTRATANTE um Certificado de Execução do Serviço, também chamado de Certificado de
            Dedetização/Garantia, contendo as informações exigidas pela legislação sanitária aplicável.</p>
    </div>

    <div class="section-title">CLÁUSULA 3ª – DAS OBRIGAÇÕES DO CONTRATANTE</div>
    <div class="clause">
        <p><strong>3.1. Acesso ao Local:</strong> O CONTRATANTE deverá disponibilizar o imóvel e proporcionar livre
            acesso
            às áreas a serem tratadas na data e horário combinados, permitindo que a equipe da CONTRATADA
            realize os serviços sem impedimentos.</p>
    </div>

    <div class="clause">
        <p><strong>3.2. Preparação do Ambiente:</strong> Conforme orientado pela CONTRATADA, o CONTRATANTE deverá
            preparar o local antes do serviço. Isso envolve, por exemplo: retirar ou cobrir alimentos expostos,
            louças, utensílios de cozinha, brinquedos infantis e itens sensíveis; afastar pessoas não essenciais,
            incluindo crianças, gestantes, idosos e animais de estimação do ambiente durante a aplicação e pelo
            tempo de segurança indicado.</p>
    </div>

    <div class="clause">
        <p><strong>3.3. Cuidados Pós-Serviço:</strong> Após a conclusão do serviço, o CONTRATANTE deverá seguir as
            recomendações da CONTRATADA para garantir a eficácia do tratamento.</p>
    </div>

    <div class="clause">
        <p><strong>3.4. Pagamento:</strong> O CONTRATANTE obriga-se a pagar à CONTRATADA o valor ajustado na
            Cláusula 4ª, na forma e prazo ali estipulados.</p>
    </div>

    <div class="clause">
        <p><strong>3.5. Informar Alergias ou Condições Especiais:</strong> Caso o CONTRATANTE, seus familiares ou
            ocupantes do imóvel tenham alguma condição de saúde sensível, deverá informar previamente à
            CONTRATADA para que sejam tomadas precauções adicionais.</p>
    </div>

    <div class="section-title">CLÁUSULA 4ª – DO PREÇO E FORMA DE PAGAMENTO</div>
    <div class="clause">
        <p><strong>4.1. Valor do Serviço:</strong> Pelo serviço ora contratado, o CONTRATANTE pagará à CONTRATADA o
            valor total de
            @if($contract->service_value)
                R$ {{ number_format($contract->service_value, 2, ',', '.') }}
            @else
                R$ [______]
            @endif
            .
        </p>
    </div>

    <div class="clause">
        <p><strong>4.2. Forma de Pagamento:</strong> O pagamento será efetuado da seguinte forma:
            @if($contract->payment_method)
                {{ $contract->payment_method }}
            @else
                [descrever a forma de pagamento acordada]
            @endif
        </p>
        @if($contract->payment_details)
            <p><strong>Dados para Pagamento:</strong><br>
                {{ $contract->payment_details }}
            </p>
        @endif
    </div>

    <div class="clause">
        <p><strong>4.3. Atraso no Pagamento:</strong> Em caso de inadimplência, o valor devido ficará sujeito à correção
            monetária pelo IPCA, acrescido de juros de mora de 1% (um por cento) ao mês e multa de 2% (dois
            por cento) sobre o valor total em atraso.</p>
    </div>

    <div class="section-title">CLÁUSULA 5ª – DA DOCUMENTAÇÃO E CERTIFICADOS</div>
    <div class="clause">
        <p><strong>5.1. Certificado de Garantia/Execução do Serviço:</strong> Ao final de cada serviço realizado, a
            CONTRATADA emitirá e entregará ao CONTRATANTE um Certificado de Execução do Serviço de
            Dedetização, conforme previsto na legislação sanitária.</p>
    </div>

    <div class="clause">
        <p><strong>5.2. Nota Fiscal:</strong> A CONTRATADA emitirá Nota Fiscal de Serviço ao CONTRATANTE, conforme a
            legislação tributária aplicável.</p>
    </div>

    <div class="section-title">CLÁUSULA 6ª – DA VIGÊNCIA E RESCISÃO</div>
    <div class="clause">
        <p><strong>6.1. Vigência:</strong>
            @if($contract->service_type === 'pontual')
                Contrato de Visita Única: Este contrato tem vigência início na data de sua assinatura e será considerado
                cumprido e encerrado após a realização do serviço descrito na Cláusula 1ª e o decurso do prazo de garantia
                correspondente.
            @else
                Contrato Periódico: O presente contrato vigorará pelo prazo estipulado, iniciando-se em
                {{ $contract->start_date ? $contract->start_date->format('d/m/Y') : '____/____/____' }} e terminando em
                {{ $contract->end_date ? $contract->end_date->format('d/m/Y') : '____/____/____' }}.
            @endif
        </p>
    </div>

    <div class="clause">
        <p><strong>6.2. Rescisão Antecipada:</strong> Qualquer das Partes poderá rescindir este contrato antes do
            término
            mediante notificação por escrito à outra parte, com antecedência mínima de 30 (trinta) dias.</p>
    </div>

    <div class="section-title">CLÁUSULA 7ª – DISPOSIÇÕES GERAIS</div>
    <div class="clause">
        <p><strong>7.1. Alterações no Contrato:</strong> Qualquer modificação nas condições aqui pactuadas deverá ser
            feita mediante acordo escrito entre as Partes, por meio de termo aditivo a este contrato.</p>
    </div>

    <div class="clause">
        <p><strong>7.2. Integralidade:</strong> Este contrato representa o acordo integral entre as Partes sobre seu
            objeto,
            substituindo quaisquer entendimentos ou negociações anteriores, verbais ou escritas.</p>
    </div>

    <div class="clause">
        <p><strong>7.3. Relação de Consumo:</strong> As Partes reconhecem que esta relação contratual caracteriza
            relação de
            consumo, aplicando-se as normas de proteção ao consumidor (Lei nº 8.078/90 – CDC).</p>
    </div>

    <div class="section-title">CLÁUSULA 8ª – DO FORO</div>
    <div class="clause">
        <p><strong>8.1.</strong> Para dirimir quaisquer dúvidas ou litígios oriundos deste contrato, as Partes elegem de
            comum
            acordo o Foro da Comarca de {{ $contract->jurisdiction }}, como o competente para solucionar
            a
            questão, com renúncia de qualquer outro, por mais privilegiado que seja.</p>
    </div>

    @if($contract->additional_clause)
        <div class="section-title">CLÁUSULA 9ª – CLÁUSULA ADICIONAL</div>
        <div class="clause">
            <p><strong>9.1. Disposições Especiais:</strong> {!! nl2br(e($contract->additional_clause)) !!}</p>
        </div>
    @endif

    <div class="signature-section">
        <p style="text-align: center; margin-top: 50px;">
            E por estarem assim justos e contratados, firmam o presente instrumento em 2 (duas) vias de igual teor e
            forma, juntamente com duas testemunhas, para que produza seus efeitos legais.
        </p>
        <p style="text-align: center; margin-top: 30px;">
            {{ $contract->jurisdiction }}, {{ $currentDate }}.
        </p>

        <div style="display: flex; justify-content: space-around; margin-top: 80px;">
            <div class="signature-box">
                <p><strong>CONTRATANTE – {{ $client->name }}</strong></p>
                @if($client->cnpj && strlen(preg_replace('/[^0-9]/', '', $client->cnpj)) == 14)
                    <p>CNPJ: {{ $client->cnpj }}</p>
                @elseif($client->cnpj && strlen(preg_replace('/[^0-9]/', '', $client->cnpj)) == 11)
                    <p>CPF: {{ $client->cnpj }}</p>
                @else
                    <p>CPF: [XXX.XXX.XXX-XX]</p>
                @endif
            </div>

            <div class="signature-box">
                <p><strong>CONTRATADA – {{ $company->name }}</strong></p>
                @if($company->cnpj)
                    <p>CNPJ: {{ $company->cnpj }}</p>
                @endif
                @if($company->legal_representative)
                    <p>Representante Legal: {{ $company->legal_representative }}</p>
                @endif
            </div>
        </div>
    </div>
</body>

</html>