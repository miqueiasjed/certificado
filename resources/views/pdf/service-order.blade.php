<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OS #{{ $serviceOrder->id }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            color: #333;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #059669;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .title {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
            color: #059669;
        }
        .subtitle {
            font-size: 18px;
            color: #666;
        }
        .section {
            margin-bottom: 30px;
        }
        .section-title {
            font-size: 16px;
            font-weight: bold;
            background-color: #f0fdf4;
            padding: 10px;
            margin-bottom: 15px;
            border-left: 4px solid #059669;
            color: #059669;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .info-item {
            margin-bottom: 10px;
        }
        .info-label {
            font-weight: bold;
            color: #059669;
        }
        .info-value {
            margin-top: 5px;
        }
        .rooms-section, .devices-section {
            margin-top: 20px;
        }
        .item {
            border: 1px solid #ddd;
            padding: 10px;
            margin-bottom: 10px;
            border-radius: 5px;
        }
        .item-title {
            font-weight: bold;
            margin-bottom: 5px;
        }
        .signature-section {
            margin-top: 50px;
            text-align: center;
        }
        .signature-line {
            border-top: 1px solid #333;
            width: 200px;
            margin: 20px auto;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 12px;
        }
        .status-pending { background-color: #fff3cd; color: #856404; }
        .status-in_progress { background-color: #d1ecf1; color: #0c5460; }
        .status-completed { background-color: #d4edda; color: #059669; }
        .status-cancelled { background-color: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">ORDEM DE SERVIÇO</div>
        <div class="subtitle">OS #{{ $serviceOrder->order_number }}</div>
    </div>

    <div class="section">
        <div class="section-title">INFORMAÇÕES GERAIS</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Status:</div>
                <div class="info-value">
                    <span class="status-badge status-{{ $serviceOrder->status }}">
                        {{ $serviceOrder->status_label }}
                    </span>
                </div>
            </div>
            <div class="info-item">
                <div class="info-label">Data da OS:</div>
                <div class="info-value">{{ $serviceOrder->formatted_order_date }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Horário:</div>
                <div class="info-value">{{ $serviceOrder->formatted_start_time }} - {{ $serviceOrder->formatted_end_time }}</div>
            </div>
            @if($serviceOrder->technician)
            <div class="info-item">
                <div class="info-label">Técnico Responsável:</div>
                <div class="info-value">{{ $serviceOrder->technician->name }}</div>
            </div>
            @endif
        </div>
    </div>

    <div class="section">
        <div class="section-title">CLIENTE</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Nome:</div>
                <div class="info-value">{{ $serviceOrder->client->name }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">E-mail:</div>
                <div class="info-value">{{ $serviceOrder->client->email }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Telefone:</div>
                <div class="info-value">{{ $serviceOrder->client->phone }}</div>
            </div>
            <div class="info-item">
                <div class="info-label">Endereço:</div>
                <div class="info-value">
                    {{ $serviceOrder->client->address }}, {{ $serviceOrder->client->number }}<br>
                    {{ $serviceOrder->client->neighborhood }} - {{ $serviceOrder->client->city }}/{{ $serviceOrder->client->state }}<br>
                    CEP: {{ $serviceOrder->client->zip_code }}
                </div>
            </div>
        </div>
    </div>

    @if($serviceOrder->service)
    <div class="section">
        <div class="section-title">SERVIÇO</div>
        <div class="info-value">{{ $serviceOrder->service->name }}</div>
        @if($serviceOrder->service->description)
        <div class="text-sm text-gray-600 mt-2">{{ $serviceOrder->service->description }}</div>
        @endif
    </div>
    @endif

    @if($serviceOrder->description)
    <div class="section">
        <div class="section-title">DESCRIÇÃO DO SERVIÇO</div>
        <div class="info-value">{{ $serviceOrder->description }}</div>
    </div>
    @endif

    @if($serviceOrder->rooms->count() > 0)
    <div class="section">
        <div class="section-title">CÔMODOS ATENDIDOS</div>
        <div class="rooms-section">
            @foreach($serviceOrder->rooms as $room)
            <div class="item">
                <div class="item-title">{{ $room->name }}</div>
                @if($room->pivot->observation)
                <div><strong>Observação do atendimento:</strong> {{ $room->pivot->observation }}</div>
                @endif
            </div>
            @endforeach
        </div>
    </div>
    @endif

    @if($serviceOrder->devices->count() > 0)
    <div class="section">
        <div class="section-title">DISPOSITIVOS</div>
        <div class="devices-section">
            @foreach($serviceOrder->devices as $device)
            <div class="item">
                <div class="item-title">{{ $device->name }}</div>
                @if($device->brand || $device->model)
                <div>
                    @if($device->brand) Marca: {{ $device->brand }} @endif
                    @if($device->model) Modelo: {{ $device->model }} @endif
                </div>
                @endif
                @if($device->description)
                <div>Descrição: {{ $device->description }}</div>
                @endif
                <div>Quantidade: {{ $device->quantity }}</div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    @if($serviceOrder->products->count() > 0)
    <div class="section">
        <div class="section-title">PRODUTOS NECESSÁRIOS</div>
        <div class="devices-section">
            @foreach($serviceOrder->products as $product)
            <div class="item">
                <div class="item-title">{{ $product->product->name }}</div>
                <div>Quantidade: {{ $product->quantity }}</div>
                @if($product->notes)
                <div>Observações: {{ $product->notes }}</div>
                @endif
            </div>
            @endforeach
        </div>
    </div>
    @endif

    @if($serviceOrder->special_instructions)
    <div class="section">
        <div class="section-title">INSTRUÇÕES ESPECIAIS PARA O TÉCNICO</div>
        <div class="info-value">{{ $serviceOrder->special_instructions }}</div>
    </div>
    @endif

    @if($serviceOrder->observations)
    <div class="section">
        <div class="section-title">OBSERVAÇÕES</div>
        <div class="info-value">{{ $serviceOrder->observations }}</div>
    </div>
    @endif

    <div class="signature-section">
        <div class="signature-line"></div>
        <div>Assinatura do Responsável</div>
        <div style="margin-top: 20px; font-size: 12px; color: #666;">
            Nome: _________________________________<br>
            CPF: _________________________________<br>
            Data: _________________________________
        </div>
    </div>
</body>
</html>
