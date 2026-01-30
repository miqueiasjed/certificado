<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ordem de Serviço - {{ $workOrder->order_number }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            margin: 0;
            padding: 20px;
            background-color: #ffffff;
            color: #333;
            line-height: 1.3;
        }


        .header-container {
            display: flex;
            align-items: center;
            margin: 0 0 20px 0;
            padding: 15px;
            border-radius: 8px;
        }

        .logo-section {
            flex: 0 0 auto;
            margin-left: 20px;
        }

        .logo-section img {
            height: 100px;
            width: auto;
            display: block;
        }

        .title-section {
            flex: 1;
            text-align: left;
            color: #059669;
        }

        .document-title {
            margin: 0;
            background: transparent;
            color: #059669;
            padding: 0;
            border-radius: 0;
        }

        .company-info {
            text-align: center;
            margin: 0 0 20px 0;
            font-size: 14px;
            color: #059669;
            line-height: 1.2;
            padding: 12px;
            border: 2px solid #059669;
            border-radius: 8px;
            background: #f0fdf4;
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
            color: #059669;
            font-weight: bold;
        }

        .section-header {
            background-color: #f8f9fa;
            color: #333;
            font-weight: bold;
            font-size: 14px;
            padding: 12px 15px;
            margin: 20px 0 15px 0;
            border-left: 4px solid #059669;
            border-radius: 4px;
        }

        .info-section {
            margin-bottom: 25px;
        }

        .info-row {
            display: flex;
            margin-bottom: 8px;
            font-size: 12px;
        }

        .info-label {
            font-weight: bold;
            color: #333;
            min-width: 120px;
            margin-right: 10px;
        }

        .info-value {
            color: #333;
            flex: 1;
        }

        .info-table {
            width: 100%;
            margin-bottom: 10px;
            border-collapse: collapse;
        }

        .info-table td {
            padding: 8px;
            vertical-align: top;
            font-size: 11px;
            border: 1px solid #e0e0e0;
        }

        .info-table td strong {
            font-weight: bold;
            color: #059669;
            display: block;
            margin-bottom: 3px;
            font-size: 10px;
        }

        .table-header {
            display: flex;
            justify-content: space-around;
            align-items: flex-start;
            padding: 0;
            margin-bottom: 10px;
        }

        .table-header-item {
            font-size: 11px;
            text-align: center;
            flex: 1;
            padding: 0 5px;
        }

        .table-header-item strong {
            font-weight: bold;
            color: #333;
            display: block;
            margin-bottom: 5px;
            font-size: 10px;
        }

        .table-header-item div {
            font-size: 11px;
            color: #333;
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
            color: #059669;
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
            color: #059669;
            font-size: 14px;
            font-weight: bold;
            margin: 0 0 12px 0;
            text-transform: uppercase;
            border-bottom: 1px solid #059669;
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
            background: #059669;
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
            color: #059669;
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
            color: #059669;
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
            grid-template-columns: 1fr 1fr 1fr;
            gap: 20px;
            z-index: 2;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        /* Classe para compactar quando há muito conteúdo */
        body.compact-mode {
            font-size: 9px;
            padding: 10px;
        }

        body.compact-mode .header-info,
        body.compact-mode .info-section {
            margin-bottom: 8px;
        }

        body.compact-mode .section-header {
            font-size: 11px;
            padding: 6px 10px;
            margin: 10px 0 8px 0;
        }

        body.compact-mode .info-table td {
            padding: 4px;
            font-size: 9px;
        }

        body.compact-mode .company-info {
            margin: 0 0 10px 0;
            padding: 8px;
            font-size: 11px;
        }

        body.compact-mode h1 {
            font-size: 18px !important;
        }

        body.compact-mode .signature-section {
            margin-top: 15px;
            gap: 20px;
        }

        body.compact-mode .signature-box {
            padding-top: 5px;
        }

        body.compact-mode .signature-title {
            font-size: 10px;
            margin-bottom: 5px;
        }

        body.compact-mode .signature-image {
            width: 60px;
        }

        body.compact-mode .footer-info {
            margin-top: 15px;
            padding-top: 10px;
            font-size: 8px;
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

@php
    // A partir de 2 produtos, ativa modo compacto para caber em 1 página
    $productCount = $workOrder->products ? $workOrder->products->count() : 0;
    $compactMode = $productCount >= 2;
@endphp
<body class="{{ $compactMode ? 'compact-mode' : '' }}">

    <!-- Cabeçalho com Logo e Título -->
    <div style="text-align: center; margin-bottom: 20px;">
        @if(isset($logoSrc) && $logoSrc)
            <img src="{{ $logoSrc }}" alt="Logo" style="height: 130px; width: auto; display: block; margin: 0 auto 10px auto;">
        @else
            <!-- Fallback se não tiver logo, apenas o título -->
        @endif
        <h1 style="color: #059669; font-size: 24px; margin: 10px 0 0 0; font-weight: bold;">ORDEM DE SERVIÇO</h1>
    </div>

    <!-- Informações da empresa -->
    <div class="company-info">
        @if($company->name)
        <p><strong>{{ $company->name }}</strong></p>
        @endif

        @if($company->cnpj)
        <p><strong>CNPJ:</strong> {{ $company->cnpj }}</p>
        @endif

        @if($company->full_address)
        <p>{{ $company->full_address }}</p>
        @endif

        @if($company->phone)
        <p><strong>Fone:</strong> {{ $company->phone }}</p>
        @endif

        @if($company->email)
        <p><strong>E-mail:</strong> {{ $company->email }}</p>
        @endif

        @if($company->register_visa)
        <p><strong>Registro VISA:</strong> {{ $company->register_visa }}</p>
        @endif

        @if($company->register_crea)
        <p><strong>Registro CREA:</strong> {{ $company->register_crea }}</p>
        @endif
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

    <!-- Cliente -->
    <div class="section-header">Cliente</div>
    <div class="info-section">
        <table class="info-table">
            <tr>
                <td><strong>Nome:</strong> {{ $workOrder->client->name ?? 'Não informado' }}</td>
                <td><strong>E-mail:</strong> {{ $workOrder->client->email ?? 'Não informado' }}</td>
                <td><strong>Endereço:</strong> {{ $workOrder->address->street ?? 'Não informado' }}, {{ $workOrder->address->number ?? '' }} - {{ $workOrder->address->city ?? 'Não informado' }} - {{ $workOrder->address->state ?? '' }}, Brasil</td>
                <td><strong>Razão social:</strong> {{ $workOrder->client->name ?? 'Não informado' }}</td>
            </tr>
            <tr>
                <td><strong>Telefone:</strong> {{ $workOrder->client->phone ?? 'Não informado' }}</td>
                <td><strong>CPF/CNPJ:</strong> {{ $workOrder->client->document_number ?? 'Não informado' }}</td>
                <td colspan="2"></td>
            </tr>
        </table>
    </div>

    <!-- Dados gerais -->
    <div class="section-header">Dados gerais</div>
    <div class="info-section">
        <table class="info-table">
            <tr>
                <td><strong>Número de identificação:</strong> {{ $workOrder->order_number }}</td>
                <td><strong>Data e horário da criação:</strong> {{ $workOrder->created_at->format('d/m/Y H:i:s') }}</td>
                <td><strong>Data e horário do atendimento:</strong> {{ $workOrder->service_date ? \Carbon\Carbon::parse($workOrder->service_date)->format('d/m/Y H:i:s') : 'Não agendado' }}</td>
            </tr>
            <tr>
                <td><strong>Status da ordem:</strong> {{ $workOrder->status_text }}</td>
                <td><strong>Prioridade:</strong> {{ $workOrder->priority_level_text }}</td>
                <td><strong>Tipo de serviço:</strong> {{ $workOrder->service->name ?? 'Não informado' }}</td>
            </tr>
        </table>
    </div>

    <!-- Data e Horários do Serviço -->
    <div class="section-header">Data e Horários do Serviço</div>
    <div class="info-section">
        <table class="info-table">
            <tr>
                <td><strong>Data Agendada:</strong> {{ $workOrder->scheduled_date ? \Carbon\Carbon::parse($workOrder->scheduled_date)->format('d/m/Y') : 'Não agendado' }}</td>
                <td><strong>Duração:</strong> {{ $workOrder->duration_text ?? '-' }}</td>
            </tr>
            @if($workOrder->description || $workOrder->observations)
            <tr>
                @if($workOrder->description)
                <td><strong>Descrição:</strong> {{ $workOrder->description }}</td>
                @endif
                @if($workOrder->observations)
                <td><strong>Observações:</strong> {{ $workOrder->observations }}</td>
                @endif
            </tr>
            @endif
        </table>
    </div>

    <!-- Cômodos Atendidos -->
    <div class="section-header">Cômodos Atendidos</div>
    <div class="info-section">
        @if($workOrder->rooms && $workOrder->rooms->count() > 0)
            @foreach($workOrder->rooms as $room)
                <div style="margin-bottom: 20px;">
                    <h4 style="color: #059669; font-size: 14px; margin: 10px 0;">{{ $room->name }}</h4>

                    @if($room->pivot->observation)
                    <p style="margin: 5px 0; font-size: 11px;"><strong>Observação:</strong> {{ $room->pivot->observation }}</p>
                    @endif

                    <!-- Evento Realizado -->
                    @if($room->pivot->event_type_id)
                    @php
                        $eventType = \App\Models\EventType::find($room->pivot->event_type_id);
                    @endphp
                    <table class="info-table" style="margin-top: 10px;">
                        <tr style="background-color: #f0fdf4;">
                            <td colspan="4" style="font-weight: bold; color: #059669;">Evento Realizado</td>
                        </tr>
                        <tr>
                            <td><strong>Tipo:</strong> {{ $eventType->name ?? '-' }}</td>
                            <td><strong>Data:</strong> {{ $room->pivot->event_date ? \Carbon\Carbon::parse($room->pivot->event_date)->format('d/m/Y') : '-' }}</td>
                            <td colspan="2"><strong>Descrição:</strong> {{ $room->pivot->event_description ?? '-' }}</td>
                        </tr>
                        @if($room->pivot->event_observations)
                        <tr>
                            <td colspan="4"><strong>Observações:</strong> {{ $room->pivot->event_observations }}</td>
                        </tr>
                        @endif
                    </table>
                    @endif

                    <!-- Avistamento de Praga -->
                    @if($room->pivot->pest_type)
                    <table class="info-table" style="margin-top: 10px;">
                        <tr style="background-color: #fff8f0;">
                            <td colspan="4" style="font-weight: bold; color: #ff6b35;">Avistamento de Praga</td>
                        </tr>
                        <tr>
                            <td><strong>Tipo:</strong> @php
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
                            @endphp</td>
                            <td><strong>Data:</strong> {{ $room->pivot->pest_sighting_date ? \Carbon\Carbon::parse($room->pivot->pest_sighting_date)->format('d/m/Y') : '-' }}</td>
                            <td><strong>Localização:</strong> {{ $room->pivot->pest_location ?? '-' }}</td>
                            <td><strong>Quantidade:</strong> {{ $room->pivot->pest_quantity ?? '-' }}</td>
                        </tr>
                        @if($room->pivot->pest_observation)
                        <tr>
                            <td colspan="4"><strong>Observação:</strong> {{ $room->pivot->pest_observation }}</td>
                        </tr>
                        @endif
                    </table>
                    @endif
                </div>
            @endforeach
        @else
            <p>Nenhum cômodo atendido registrado para esta ordem de serviço</p>
        @endif
    </div>

    <!-- Dispositivos Utilizados -->
    <div class="section-header">Dispositivos Utilizados</div>
    <div class="info-section">
        @php
            // Buscar todos os dispositivos da OS (com ou sem eventos)
            // Combinar dispositivos da tabela work_order_device com dispositivos únicos dos eventos
            $devicesFromTable = $workOrder->devices ?? collect();
            $events = $workOrder->workOrderDeviceEvents ?? collect();

            // IDs de dispositivos da tabela
            $deviceIdsFromTable = $devicesFromTable->pluck('id')->toArray();

            // IDs de dispositivos únicos dos eventos
            $deviceIdsFromEvents = $events->pluck('device_id')->unique()->toArray();

            // Combinar IDs únicos
            $allDeviceIds = array_unique(array_merge($deviceIdsFromTable, $deviceIdsFromEvents));

            // Buscar todos os dispositivos
            $devices = collect();
            if (count($allDeviceIds) > 0) {
                $devices = \App\Models\Device::whereIn('id', $allDeviceIds)
                    ->where('address_id', $workOrder->address_id)
                    ->with(['baitType'])
                    ->get();
            }
        @endphp

        @if($devices && $devices->count() > 0)
            @foreach($devices as $device)
                <div style="margin-bottom: 20px;">
                    <h4 style="color: #059669; font-size: 14px; margin: 10px 0;">{{ $device->label }} ({{ $device->number }})</h4>

                    <table class="info-table" style="margin-top: 10px;">
                        <tr>
                            <td><strong>Tipo de Isca:</strong> {{ $device->baitType ? $device->baitType->name : '-' }}</td>
                            <td><strong>Localização:</strong> {{ $device->default_location_note ?? '-' }}</td>
                            <td>
                                <strong>Status:</strong>
                                @if($device->active)
                                    <span style="color: #059669;">Ativo</span>
                                @else
                                    <span style="color: #dc2626;">Inativo</span>
                                @endif
                            </td>
                        </tr>
                    </table>

                    <!-- Eventos do Dispositivo -->
                    @php
                        $deviceEvents = $workOrder->workOrderDeviceEvents ? $workOrder->workOrderDeviceEvents->where('device_id', $device->id) : collect();
                    @endphp
                    @if($deviceEvents && $deviceEvents->count() > 0)
                    <div style="margin-top: 10px; padding: 8px; background-color: #f9fafb; border-left: 3px solid #059669;">
                        <p style="font-weight: bold; color: #059669; margin-bottom: 5px; font-size: 12px;">
                            Eventos do Dispositivo
                        </p>
                        <table class="info-table" style="margin-top: 5px; font-size: 10px;">
                            <tr>
                                <th style="background-color: #059669; color: #fff; font-weight: bold; padding: 4px;">Tipo</th>
                                <th style="background-color: #059669; color: #fff; font-weight: bold; padding: 4px;">Data</th>
                                <th style="background-color: #059669; color: #fff; font-weight: bold; padding: 4px;">Descrição</th>
                            </tr>
                            @foreach($deviceEvents as $event)
                            <tr>
                                <td style="padding: 4px;">{{ $event->eventType->name ?? 'Não informado' }}</td>
                                <td style="padding: 4px;">{{ $event->event_date ? $event->event_date->format('d/m/Y') : '-' }}</td>
                                <td style="padding: 4px;">{{ $event->event_description ?? '-' }}</td>
                            </tr>
                            @if($event->event_observations)
                            <tr>
                                <td colspan="3" style="background-color: #fafafa; padding: 4px; font-size: 9px;">
                                    <strong>Observações:</strong> {{ $event->event_observations }}
                                </td>
                            </tr>
                            @endif
                            @endforeach
                        </table>
                    </div>
                    @else
                    <div style="margin-top: 10px; padding: 8px; background-color: #f9fafb; border-left: 3px solid #9ca3af;">
                        <p style="font-style: italic; color: #6b7280; font-size: 11px;">
                            Nenhum evento registrado para este dispositivo.
                        </p>
                    </div>
                    @endif
                </div>
            @endforeach
        @else
            <p>Nenhum dispositivo registrado para esta ordem de serviço</p>
        @endif
    </div>

    <!-- Técnicos Responsáveis -->
    <div class="section-header">Técnicos Responsáveis</div>
    <div class="info-section">
        @if($workOrder->technicians && $workOrder->technicians->count() > 0)
            <table class="info-table">
                <tr>
                    <th style="background-color: #f0fdf4; font-weight: bold;">Nome</th>
                    <th style="background-color: #f0fdf4; font-weight: bold;">Especialidade</th>
                    <th style="background-color: #f0fdf4; font-weight: bold;">Registro</th>
                    <th style="background-color: #f0fdf4; font-weight: bold;">Função</th>
                </tr>
                @foreach($workOrder->technicians as $technician)
                <tr>
                    <td>{{ $technician->name }}</td>
                    <td>{{ $technician->specialty ?? '-' }}</td>
                    <td>{{ $technician->registration_number ?? '-' }}</td>
                    <td>
                        @if($technician->pivot->is_primary)
                            <span style="color: #059669; font-weight: bold;">Técnico Principal</span>
                        @else
                            Técnico
                        @endif
                    </td>
                </tr>
                @endforeach
            </table>
        @elseif($workOrder->technician)
            <table class="info-table">
                <tr>
                    <td><strong>Nome:</strong> {{ $workOrder->technician->name }}</td>
                    <td><strong>Especialidade:</strong> {{ $workOrder->technician->specialty ?? '-' }}</td>
                </tr>
            </table>
        @else
            <p>Nenhum técnico atribuído</p>
        @endif
    </div>

    <!-- Produtos e Serviços -->
    <div class="section-header">Produtos</div>
    <div class="info-section">
        @if($workOrder->products && $workOrder->products->count() > 0)
            <table class="info-table">
                <tr>
                    <th style="background-color: #f0fdf4; font-weight: bold;">Produto</th>
                    <th style="background-color: #f0fdf4; font-weight: bold;">Quantidade</th>
                    <th style="background-color: #f0fdf4; font-weight: bold;">Unidade</th>
                    <th style="background-color: #f0fdf4; font-weight: bold;">Observações</th>
                </tr>
                @foreach($workOrder->products as $product)
                <tr>
                    <td>{{ $product->name }}</td>
                    <td>{{ $product->pivot->quantity ?? '-' }}</td>
                    <td>{{ $product->pivot->unit ?? '-' }}</td>
                    <td>{{ $product->pivot->observations ?? '-' }}</td>
                </tr>
                @endforeach
            </table>
        @endif

        @if($workOrder->services && $workOrder->services->count() > 0)
            <table class="info-table">
                <tr>
                    <th style="background-color: #f0fdf4; font-weight: bold;">Serviço</th>
                    <th style="background-color: #f0fdf4; font-weight: bold;">Descrição</th>
                    <th style="background-color: #f0fdf4; font-weight: bold;">Observações</th>
                </tr>
                @foreach($workOrder->services as $service)
                <tr>
                    <td>{{ $service->name }}</td>
                    <td>{{ $service->description ?? '-' }}</td>
                    <td>{{ $service->pivot->observations ?? '-' }}</td>
                </tr>
                @endforeach
            </table>
        @endif

        @if((!$workOrder->products || $workOrder->products->count() == 0) && (!$workOrder->services || $workOrder->services->count() == 0))
            <p>Nenhum produto ou serviço registrado para esta ordem de serviço</p>
        @endif
    </div>

    <!-- Adequação -->
    <div class="section-header">Adequação</div>
    <div class="info-section">
        @if($workOrder->adequation_notes)
            <table class="info-table">
                <tr>
                    <td style="padding: 15px;">{{ $workOrder->adequation_notes }}</td>
                </tr>
            </table>
        @else
            <p>Nenhuma adequação registrada</p>
        @endif
    </div>

    <!-- Assinaturas -->
    <div class="signature-section no-break">
        <div class="signature-box">
            @if(isset($opSrc) && $opSrc)
                <img src="{{ $opSrc }}" alt="Assinatura Gerente" class="signature-image">
            @else
                <div style="height: 50px;"></div>
            @endif
            
            <strong>Ass:</strong> Gerente de Operações<br>
            <div class="signature-box">{{ $company->operational_manager_name }}</div>
        </div>
        <div class="signature-box">
            @if(isset($chemSrc) && $chemSrc)
                <img src="{{ $chemSrc }}" alt="Assinatura Químico" class="signature-image">
            @else
                <!-- Fallback opcional ou linha em branco -->
                <div style="height: 50px;"></div>
            @endif
            <strong>Ass:</strong> Responsável Técnico<br>
            <div class="signature-box">{{ $company->technical_responsible_name }}</div>
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
