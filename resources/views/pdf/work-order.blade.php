<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ordem de Serviço - {{ $workOrder->order_number }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            margin: 0;
            padding: 30px;
            background-color: #ffffff;
            color: #333;
            line-height: 1.4;
        }


        .document-title {
            text-align: center;
            margin: 0 0 15px 0;
            background: #2f3091;
            color: white;
            padding: 15px;
            border-radius: 8px;
        }

        .company-info {
            text-align: center;
            margin: 0 0 20px 0;
            font-size: 14px;
            color: #2f3091;
            line-height: 1.2;
            padding: 12px;
            border: 2px solid #2f3091;
            border-radius: 8px;
            background: #f8f9fa;
        }

        .document-title h1 {
            font-size: 24px;
            font-weight: bold;
            color: white;
            margin: 0;
            text-transform: uppercase;
        }

        .header-info {
            margin: 0 0 20px 0;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }

        .header-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 14px;
        }

        .header-item {
            flex: 1;
        }

        .header-item strong {
            color: #2f3091;
            font-weight: bold;
        }

        .section {
            margin: 15px 0;
            background: #ffffff;
            border-radius: 8px;
            padding: 15px;
            border: 1px solid #dee2e6;
            border-left: 4px solid #2f3091;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .section h3 {
            margin: 0 0 10px 0;
            color: #2f3091;
            font-size: 14px;
            font-weight: bold;
            text-transform: uppercase;
            border-bottom: 2px solid #2f3091;
            padding-bottom: 3px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 10px;
        }

        .info-item {
            margin-bottom: 5px;
        }

        .info-label {
            font-weight: bold;
            color: #2f3091;
            font-size: 11px;
            text-transform: uppercase;
            margin-bottom: 3px;
        }

        .info-value {
            color: #333;
            font-size: 12px;
            padding: 5px 0;
        }

        .client-address-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .technician-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .technician-item {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 12px;
        }

        .technician-item strong {
            color: #2f3091;
            display: block;
            margin-bottom: 5px;
        }

        .technician-item small {
            color: #666;
            display: block;
            margin-bottom: 3px;
        }

        .product-service-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .product-service-section {
            background: #f8f9fa;
            border-radius: 6px;
            padding: 15px;
        }

        .product-service-section h4 {
            color: #2f3091;
            font-size: 14px;
            font-weight: bold;
            margin: 0 0 12px 0;
            text-transform: uppercase;
            border-bottom: 1px solid #2f3091;
            padding-bottom: 5px;
        }

        .item-card {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 10px;
        }

        .item-card h5 {
            margin: 0 0 8px 0;
            color: #333;
            font-size: 12px;
            font-weight: bold;
        }

        .quantity-badge {
            display: inline-block;
            background: #2f3091;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .observation-text {
            font-style: italic;
            color: #666;
            font-size: 10px;
            margin-top: 5px;
        }

        .room-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
        }

        .room-item {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            padding: 8px;
            text-align: center;
            font-size: 11px;
        }

        .pest-sighting, .device-event {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 10px;
        }

        .pest-sighting h4, .device-event h4 {
            margin: 0 0 8px 0;
            color: #2f3091;
            font-size: 12px;
            font-weight: bold;
        }

        .adequation-section {
            background: #fff3cd;
            border-left-color: #ffc107;
            border: 1px solid #ffeaa7;
        }

        .adequation-content {
            background: white;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #ffeaa7;
        }


        .signature-box {
            text-align: center;
            border-top: 1px solid #333;
            padding-top: 10px;
        }

        .signature-title {
            font-weight: bold;
            font-size: 12px;
            margin-bottom: 10px;
            color: #2f3091;
        }

        .signature-image {
            width: 80px;
            height: auto;
            margin-bottom: 5px;
        }

        .signature-text {
            font-size: 11px;
            color: #666;
        }

        .footer-info {
            text-align: center;
            font-size: 10px;
            color: #666;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #dee2e6;
        }

        .no-data {
            text-align: center;
            color: #666;
            font-style: italic;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 6px;
        }

        .page-break {
            page-break-before: always;
            break-before: page;
        }

        .no-break {
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .break-before {
            page-break-before: auto;
            break-before: auto;
        }

        .signature-section {
            margin-top: 40px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            z-index: 2;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        @media print {
            body {
                margin: 0;
                padding: 20px;
            }

            .section {
                page-break-inside: avoid;
                break-inside: avoid;
                margin: 20px 0;
            }

            .header-info {
                page-break-after: avoid;
                break-after: avoid;
            }

            .product-service-grid {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .technician-list {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            .room-list {
                page-break-inside: avoid;
                break-inside: avoid;
            }
        }
    </style>
</head>

<body>

    <!-- Título do documento -->
    <div class="document-title">
        <h1>ORDEM DE SERVIÇO</h1>
    </div>

    <!-- Informações da empresa -->
    <div class="company-info">
        <p><strong>CNPJ:</strong> 19.228.297/0001-75</p>
        <p>Comunidade 2º Vila Córrego dos Furtados, 153</p>
        <p>Bairro Córrego Fundo, Município de Trairi-CE</p>
        <p><strong>Fone:</strong> (85) 99993-8745</p>
    </div>

    <!-- Informações do cabeçalho -->
    <div class="header-info">
        <div class="header-row">
            <div class="header-item">
                <strong>Número da OS:</strong> {{ $workOrder->order_number }}
            </div>
            <div class="header-item">
                <strong>Data:</strong> {{ $currentDate }}
            </div>
            <div class="header-item">
                <strong>Horário:</strong> {{ $currentTime }}
            </div>
        </div>
        <div class="header-row">
            <div class="header-item">
                <strong>Status:</strong> {{ $workOrder->status_text }}
            </div>
            <div class="header-item">
                <strong>Prioridade:</strong> {{ $workOrder->priority_level_text }}
            </div>
            <div class="header-item">
                <strong>Serviço:</strong> {{ $workOrder->service->name ?? '-' }}
            </div>
        </div>
    </div>

    <!-- Dados do Cliente e Endereço -->
    <div class="section no-break">
        <h3>Dados do Cliente e Endereço</h3>
        <div class="client-address-grid">
            <div>
                <div class="info-item">
                    <div class="info-label">Cliente</div>
                    <div class="info-value">{{ $workOrder->client->name ?? 'Não informado' }}</div>
                </div>
                <div class="info-item">
                    <div class="info-label">CPF/CNPJ</div>
                    <div class="info-value">{{ $workOrder->client->document_number ?? 'Não informado' }}</div>
                </div>
                <div class="info-item">
                    <div class="info-label">Telefone</div>
                    <div class="info-value">{{ $workOrder->client->phone ?? 'Não informado' }}</div>
                </div>
                <div class="info-item">
                    <div class="info-label">Email</div>
                    <div class="info-value">{{ $workOrder->client->email ?? 'Não informado' }}</div>
                </div>
            </div>
            <div>
                <div class="info-item">
                    <div class="info-label">Endereço</div>
                    <div class="info-value">{{ $workOrder->address->nickname ?? 'Não informado' }}</div>
                </div>
                <div class="info-item">
                    <div class="info-label">Logradouro</div>
                    <div class="info-value">{{ $workOrder->address->street ?? 'Não informado' }}, {{ $workOrder->address->number ?? '' }}</div>
                </div>
                <div class="info-item">
                    <div class="info-label">Cidade/Estado</div>
                    <div class="info-value">{{ $workOrder->address->city ?? 'Não informado' }}/{{ $workOrder->address->state ?? '' }}</div>
                </div>
                <div class="info-item">
                    <div class="info-label">CEP</div>
                    <div class="info-value">{{ $workOrder->address->zip ?? 'Não informado' }}</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Data e Horários do Serviço -->
    <div class="section no-break">
        <h3>Data e Horários do Serviço</h3>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Data Agendada</div>
                <div class="info-value">{{ $workOrder->scheduled_date ? \Carbon\Carbon::parse($workOrder->scheduled_date)->format('d/m/Y') : 'Não agendado' }}</div>
            </div>
            @if($workOrder->start_time)
            <div class="info-item">
                <div class="info-label">Horário de Início</div>
                <div class="info-value">{{ \Carbon\Carbon::parse($workOrder->start_time)->format('d/m/Y H:i') }}</div>
            </div>
            @endif
            @if($workOrder->end_time)
            <div class="info-item">
                <div class="info-label">Horário de Término</div>
                <div class="info-value">{{ \Carbon\Carbon::parse($workOrder->end_time)->format('d/m/Y H:i') }}</div>
            </div>
            @endif
            @if($workOrder->duration_text)
            <div class="info-item">
                <div class="info-label">Duração</div>
                <div class="info-value">{{ $workOrder->duration_text }}</div>
            </div>
            @endif
        </div>

        @if($workOrder->description)
        <div class="info-item" style="margin-top: 15px;">
            <div class="info-label">Descrição</div>
            <div class="info-value">{{ $workOrder->description }}</div>
        </div>
        @endif

        @if($workOrder->observations)
        <div class="info-item">
            <div class="info-label">Observações</div>
            <div class="info-value">{{ $workOrder->observations }}</div>
        </div>
        @endif
    </div>

    <!-- Cômodos Atendidos -->
    <div class="section">
        <h3>Cômodos Atendidos</h3>
        @if($workOrder->rooms && $workOrder->rooms->count() > 0)
            <div class="room-list">
                @foreach($workOrder->rooms as $room)
                    <div class="room-item" style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 5px;">
                        <div style="font-size: 14px; font-weight: bold; margin-bottom: 10px; color: #2f3091;">
                            {{ $room->name }}
                        </div>

                        <!-- Observação Geral -->
                        @if($room->pivot->observation)
                            <div style="margin-bottom: 15px;">
                                <strong>Observação Geral:</strong> {{ $room->pivot->observation }}
                            </div>
                        @endif

                        <!-- Evento -->
                        @if($room->pivot->event_type_id)
                        <div style="margin-bottom: 15px; padding: 10px; background-color: #f0f8ff; border-left: 4px solid #2f3091;">
                            <div style="font-weight: bold; margin-bottom: 8px; color: #2f3091;">Evento Realizado</div>
                            @php
                                $eventType = \App\Models\EventType::find($room->pivot->event_type_id);
                            @endphp
                            @if($eventType)
                                <div style="margin-bottom: 5px;">
                                    <strong>Tipo:</strong> {{ $eventType->name }}
                                </div>
                            @endif
                            @if($room->pivot->event_date)
                                <div style="margin-bottom: 5px;">
                                    <strong>Data:</strong> {{ \Carbon\Carbon::parse($room->pivot->event_date)->format('d/m/Y') }}
                                </div>
                            @endif
                            @if($room->pivot->event_description)
                                <div style="margin-bottom: 5px;">
                                    <strong>Descrição:</strong> {{ $room->pivot->event_description }}
                                </div>
                            @endif
                            @if($room->pivot->event_observations)
                                <div style="margin-bottom: 5px;">
                                    <strong>Observações:</strong> {{ $room->pivot->event_observations }}
                                </div>
                            @endif
                        </div>
                        @endif

                        <!-- Avistamento de Praga -->
                        @if($room->pivot->pest_type)
                        <div style="padding: 10px; background-color: #fff8f0; border-left: 4px solid #ff6b35;">
                            <div style="font-weight: bold; margin-bottom: 8px; color: #ff6b35;">Avistamento de Praga</div>
                            <div style="margin-bottom: 5px;">
                                <strong>Tipo:</strong> @php
                                    $pestTypes = [
                                        'cockroach' => 'Barata',
                                        'ant' => 'Formiga',
                                        'spider' => 'Aranha',
                                        'rat' => 'Rato',
                                        'mosquito' => 'Mosquito',
                                        'fly' => 'Mosca',
                                        'termite' => 'Cupim',
                                        'other' => 'Outro'
                                    ];
                                    echo $pestTypes[$room->pivot->pest_type] ?? ucfirst(str_replace('_', ' ', $room->pivot->pest_type));
                                @endphp
                            </div>
                            @if($room->pivot->pest_sighting_date)
                                <div style="margin-bottom: 5px;">
                                    <strong>Data:</strong> {{ \Carbon\Carbon::parse($room->pivot->pest_sighting_date)->format('d/m/Y') }}
                                </div>
                            @endif
                            @if($room->pivot->pest_location)
                                <div style="margin-bottom: 5px;">
                                    <strong>Localização:</strong> {{ $room->pivot->pest_location }}
                                </div>
                            @endif
                            @if($room->pivot->pest_quantity)
                                <div style="margin-bottom: 5px;">
                                    <strong>Quantidade:</strong> {{ $room->pivot->pest_quantity }}
                                </div>
                            @endif
                            @if($room->pivot->pest_observation)
                                <div style="margin-bottom: 5px;">
                                    <strong>Observação:</strong> {{ $room->pivot->pest_observation }}
                                </div>
                            @endif
                        </div>
                        @endif

                        <!-- Dispositivos do Cômodo -->
                        @if($room->devices && $room->devices->count() > 0)
                        <div style="padding: 10px; background-color: #f0f9ff; border-left: 4px solid #3b82f6; margin-top: 10px;">
                            <div style="font-weight: bold; margin-bottom: 8px; color: #3b82f6;">Dispositivos Utilizados</div>
                            @foreach($room->devices as $device)
                                <div style="margin-bottom: 8px; padding: 8px; background-color: #ffffff; border-radius: 4px; border: 1px solid #e5e7eb;">
                                    <div style="font-weight: bold; color: #374151;">{{ $device->label }} ({{ $device->number }})</div>
                                    @if($device->baitType)
                                        <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">
                                            <strong>Tipo de Isca:</strong> {{ $device->baitType->name }}
                                            @if($device->baitType->brand)
                                                - {{ $device->baitType->brand }}
                                            @endif
                                        </div>
                                    @endif
                                    @if($device->default_location_note)
                                        <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">
                                            <strong>Localização:</strong> {{ $device->default_location_note }}
                                        </div>
                                    @endif
                                    <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">
                                        <strong>Status:</strong>
                                        @if($device->active)
                                            <span style="color: #10b981;">Ativo</span>
                                        @else
                                            <span style="color: #ef4444;">Inativo</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <div class="no-data">Nenhum cômodo atendido registrado para esta ordem de serviço</div>
        @endif
    </div>

    <!-- Técnicos Responsáveis -->
    <div class="section">
        <h3>Técnicos Responsáveis</h3>
        @if($workOrder->technicians && $workOrder->technicians->count() > 0)
            <div class="technician-list">
                @foreach($workOrder->technicians as $technician)
                    <div class="technician-item">
                        <strong>{{ $technician->name }}</strong>
                        @if($technician->specialty)
                            <small>{{ $technician->specialty }}</small>
                        @endif
                        @if($technician->registration_number)
                            <small>Registro: {{ $technician->registration_number }}</small>
                        @endif
                        @if($technician->pivot->is_primary)
                            <small style="color: #28a745; font-weight: bold;">Técnico Principal</small>
                        @endif
                    </div>
                @endforeach
            </div>
        @elseif($workOrder->technician)
            <div class="technician-item">
                <strong>{{ $workOrder->technician->name }}</strong>
                @if($workOrder->technician->specialty)
                    <small>{{ $workOrder->technician->specialty }}</small>
                @endif
            </div>
        @else
            <div class="no-data">Nenhum técnico atribuído</div>
        @endif
    </div>

    <!-- Produtos e Serviços -->
    <div class="section no-break">
        <h3>Produtos e Serviços</h3>

        @if(($workOrder->products && $workOrder->products->count() > 0) || ($workOrder->services && $workOrder->services->count() > 0))
            <div class="product-service-grid">
                <!-- Produtos Utilizados -->
                @if($workOrder->products && $workOrder->products->count() > 0)
                    <div class="product-service-section">
                        <h4>Produtos Utilizados</h4>
                        @foreach($workOrder->products as $product)
                            <div class="item-card">
                                <h5>{{ $product->name }}</h5>
                                @if($product->pivot->quantity || $product->pivot->unit)
                                    <div class="quantity-badge">
                                        @if($product->pivot->quantity)
                                            Qtd: {{ $product->pivot->quantity }}
                                        @endif
                                        @if($product->pivot->unit)
                                            {{ $product->pivot->unit }}
                                        @endif
                                    </div>
                                @endif
                                @if($product->pivot->observations)
                                    <div class="observation-text">
                                        <strong>Obs:</strong> {{ $product->pivot->observations }}
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                <!-- Serviços Realizados -->
                @if($workOrder->services && $workOrder->services->count() > 0)
                    <div class="product-service-section">
                        <h4>Serviços Realizados</h4>
                        @foreach($workOrder->services as $service)
                            <div class="item-card">
                                <h5>{{ $service->name }}</h5>
                                @if($service->description)
                                    <div style="margin-bottom: 5px; font-size: 10px; color: #666;">
                                        {{ $service->description }}
                                    </div>
                                @endif
                                @if($service->pivot->observations)
                                    <div class="observation-text">
                                        <strong>Obs:</strong> {{ $service->pivot->observations }}
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @else
            <div class="no-data">Nenhum produto ou serviço registrado para esta ordem de serviço</div>
        @endif
    </div>

    <!-- Manutenção de Dispositivos -->
    <div class="section break-before">
        <h3>Manutenção de Dispositivos</h3>
        @if($workOrder->deviceEvents && $workOrder->deviceEvents->count() > 0)
            @foreach($workOrder->deviceEvents as $event)
                <div class="device-event">
                    <h4>{{ $event->device->name ?? 'Dispositivo não especificado' }}</h4>
                    <div class="info-grid">
                        @if($event->event_date)
                        <div class="info-item">
                            <div class="info-label">Data</div>
                            <div class="info-value">{{ \Carbon\Carbon::parse($event->event_date)->format('d/m/Y') }}</div>
                        </div>
                        @endif
                        @if($event->event_type)
                        <div class="info-item">
                            <div class="info-label">Tipo</div>
                            <div class="info-value">{{ $event->event_type }}</div>
                        </div>
                        @endif
                        @if($event->room)
                        <div class="info-item">
                            <div class="info-label">Cômodo</div>
                            <div class="info-value">{{ $event->room->name }}</div>
                        </div>
                        @endif
                    </div>
                    @if($event->description)
                        <div class="info-item" style="margin-top: 10px;">
                            <div class="info-label">Descrição</div>
                            <div class="info-value">{{ $event->description }}</div>
                        </div>
                    @endif
                    @if($event->observations)
                        <div class="info-item">
                            <div class="info-label">Observações</div>
                            <div class="info-value">{{ $event->observations }}</div>
                        </div>
                    @endif
                </div>
            @endforeach
        @else
            <div class="no-data">Nenhuma manutenção de dispositivo registrada</div>
        @endif
    </div>

    <!-- Consumo ou Substituição de Iscas -->
    <div class="section">
        <h3>Consumo ou Substituição de Iscas</h3>
        <div class="info-grid">
            @if($workOrder->consumption_status)
            <div class="info-item">
                <div class="info-label">Status do Consumo</div>
                <div class="info-value">{{ $workOrder->consumption_status_text }}</div>
            </div>
            @endif
            @if($workOrder->consumption_quantity)
            <div class="info-item">
                <div class="info-label">Quantidade Consumida</div>
                <div class="info-value">{{ $workOrder->consumption_quantity }}</div>
            </div>
            @endif
            @if($workOrder->consumption_notes)
            <div class="info-item">
                <div class="info-label">Observações</div>
                <div class="info-value">{{ $workOrder->consumption_notes }}</div>
            </div>
            @endif
        </div>
        @if(!$workOrder->consumption_status && !$workOrder->consumption_quantity && !$workOrder->consumption_notes)
            <div class="no-data">Nenhuma informação sobre consumo ou substituição de iscas</div>
        @endif
    </div>

    <!-- Adequação -->
    <div class="section adequation-section">
        <h3>Adequação</h3>
        <div class="adequation-content">
            @if($workOrder->adequation_notes)
                {{ $workOrder->adequation_notes }}
            @else
                <div class="no-data">Nenhuma adequação registrada</div>
            @endif
        </div>
    </div>

    <!-- Assinaturas -->
    <div class="signature-section no-break">
        <div class="signature-box">
            <div class="signature-title">Assinatura do Engenheiro Químico</div>
            <img src="{{ public_path('images/signature-quimico.png') }}" alt="Assinatura Químico" class="signature-image">
            <div class="signature-text"><strong>Ass:</strong> Químico Industrial</div>
        </div>
        <div class="signature-box">
            <div class="signature-title">Assinatura do Responsável pelo Local</div>
            <div class="signature-line"></div>
            <div class="signature-text">Nome e CPF</div>
        </div>
    </div>

    <!-- Rodapé -->
    <div class="footer-info">
        <p>Documento gerado automaticamente em {{ $currentDate }} às {{ $currentTime }}</p>
        <p>Sistema de Gestão de Ordens de Serviço - Certificado.test</p>
    </div>
</body>
</html>
