<?php

namespace App\Http\Controllers;

use App\Http\Requests\WorkOrderRequest;
use App\Models\WorkOrder;
use App\Models\Client;
use App\Models\Address;
use App\Models\User;
use App\Models\Technician;
use App\Models\Device;
use App\Models\ServiceType;
use App\Models\Product;
use App\Models\Service;
use App\Services\WorkOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Barryvdh\DomPDF\Facade\Pdf as FacadePdf;

class WorkOrderController extends Controller
{
    protected $workOrderService;

    public function __construct(WorkOrderService $workOrderService)
    {
        $this->workOrderService = $workOrderService;
    }

    public function index(Request $request)
    {
        $query = WorkOrder::with(['client', 'address', 'technician']);

        // Filtros
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->filled('address_id')) {
            $query->where('address_id', $request->address_id);
        }

        if ($request->filled('technician_id')) {
            $query->where('technician_id', $request->technician_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('priority_level')) {
            $query->where('priority_level', $request->priority_level);
        }

        if ($request->filled('service_id')) {
            $query->where('service_id', $request->service_id);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('scheduled_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('scheduled_date', '<=', $request->date_to);
        }

        $workOrders = $query->orderBy('scheduled_date', 'desc')->paginate(15);

        // Carregar dados para os filtros
        $clients = Client::select('id', 'name')->orderBy('name')->limit(200)->get();
        $addresses = Address::select('id', 'client_id', 'nickname', 'street', 'number', 'city', 'state')
            ->orderBy('nickname')
            ->limit(200)
            ->get();
        $technicians = Technician::select('id', 'name', 'specialty')->where('is_active', true)->orderBy('name')->limit(100)->get();
        $services = Service::select('id', 'name')->where('is_active', true)->orderBy('name')->limit(100)->get();

        return Inertia::render('WorkOrders/Index', [
            'workOrders' => $workOrders,
            'filters' => $request->only(['client_id', 'address_id', 'technician_id', 'status', 'priority_level', 'service_id', 'date_from', 'date_to']),
            'clients' => $clients,
            'addresses' => $addresses,
            'technicians' => $technicians,
            'services' => $services,
        ]);
    }

    public function create(Request $request)
    {
        // Otimizar carregamento para evitar timeout
        $clients = Client::select('id', 'name')->orderBy('name')->limit(200)->get();
        $addresses = Address::select('id', 'client_id', 'nickname', 'street', 'number', 'city', 'state')
            ->orderBy('nickname')
            ->limit(200)
            ->get();
        $technicians = Technician::select('id', 'name', 'specialty')->where('is_active', true)->orderBy('name')->limit(100)->get();
        $products = Product::select('id', 'name')->orderBy('name')->limit(100)->get();
        $services = Service::select('id', 'name', 'description')->where('is_active', true)->orderBy('name')->limit(100)->get();

        // Carregar tipos de eventos disponíveis
        $eventTypes = \App\Models\EventType::select('id', 'name', 'description', 'color')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return Inertia::render('WorkOrders/Create', [
            'clients' => $clients,
            'addresses' => $addresses,
            'technicians' => $technicians,
            'products' => $products,
            'services' => $services,
            'eventTypes' => $eventTypes,
            'preselectedClient' => $request->client_id,
            'preselectedAddress' => $request->address_id,
            'preselectedTechnician' => $request->technician_id,
        ]);
    }

    public function store(WorkOrderRequest $request)
    {
        $data = $request->validated();

        // Generate order number if not provided
        if (empty($data['order_number'])) {
            $data['order_number'] = $this->workOrderService->generateOrderNumber();
        }

        $workOrder = $this->workOrderService->createWorkOrder($data);

        return redirect()->route('work-orders.show', $workOrder)
            ->with('success', 'Ordem de serviço criada com sucesso!');
    }

    public function show(WorkOrder $workOrder)
    {
        $workOrder->load([
            'client',
            'address.client',
            'technician',
            'technicians',
            'products' => function($query) {
                $query->withPivot(['quantity', 'unit', 'observations']);
            },
            'service',
            'rooms' => function($query) {
                $query->withPivot([
                    'observation',
                    'event_type_id',
                    'event_date',
                    'event_description',
                    'event_observations',
                    'device_id',
                    'pest_type',
                    'pest_sighting_date',
                    'pest_location',
                    'pest_quantity',
                    'pest_observation'
                ])->with('devices');
            },
            'deviceEvents' => function ($query) {
                $query->with([
                    'device.room.address.client',
                    'device.baitType'
                ])->orderBy('event_date', 'desc');
            },
            'paymentDetails' => function ($query) {
                $query->orderBy('payment_date', 'desc')->orderBy('created_at', 'desc');
            },
            'pestSightings' => function ($query) {
                $query->with([
                    'address.client'
                ])->orderBy('sighting_date', 'desc');
            }
        ]);


        // Accessors já estão incluídos automaticamente via $appends no modelo

        // Dados financeiros agora vêm diretamente da tabela work_orders

        // Carregar dispositivos disponíveis do endereço específico da ordem de serviço
        $availableDevices = collect();
        if ($workOrder->address) {
            $availableDevices = Device::with(['room', 'baitType'])
                ->whereHas('room', function ($query) use ($workOrder) {
                    $query->where('address_id', $workOrder->address_id);
                })
                ->orderBy('label')
                ->get();

        }

        // Carregar endereços disponíveis do cliente para avistamentos
        $availableAddresses = collect();
        if ($workOrder->client) {
            $availableAddresses = Address::where('client_id', $workOrder->client_id)
                ->orderBy('nickname')
                ->get();
        }

        // Carregar produtos e serviços disponíveis
        $availableProducts = Product::select('id', 'name')->orderBy('name')->get();
        $availableServices = Service::select('id', 'name', 'description')->where('is_active', true)->orderBy('name')->get();

        // Carregar técnicos disponíveis
        $availableTechnicians = Technician::select('id', 'name', 'specialty', 'phone', 'email')->orderBy('name')->get();

        // Carregar tipos de eventos disponíveis
        $eventTypes = \App\Models\EventType::select('id', 'name', 'description', 'color')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return Inertia::render('WorkOrders/Show', [
            'workOrder' => $workOrder,
            'availableDevices' => $availableDevices,
            'availableAddresses' => $availableAddresses,
            'availableProducts' => $availableProducts,
            'availableServices' => $availableServices,
            'availableTechnicians' => $availableTechnicians,
            'eventTypes' => $eventTypes,
        ]);
    }

    public function edit(WorkOrder $workOrder)
    {
        $workOrder->load([
            'client',
            'address.client',
            'technician',
            'technicians',
            'products' => function($query) {
                $query->withPivot(['quantity', 'unit', 'observations']);
            },
            'service',
            'rooms' => function($query) {
                $query->withPivot('observation');
            },
            'deviceEvents.device.room.address.client',
            'paymentDetails' => function ($query) {
                $query->orderBy('payment_date', 'desc')->orderBy('created_at', 'desc');
            },
            'pestSightings' => function ($query) {
                $query->with([
                    'address.client'
                ])->orderBy('sighting_date', 'desc');
            }
        ]);

        // Accessors já estão incluídos automaticamente via $appends no modelo

        // Otimizar carregamento para evitar timeout
        $clients = Client::select('id', 'name')->orderBy('name')->limit(200)->get();
        $addresses = Address::select('id', 'client_id', 'nickname', 'street', 'number', 'city', 'state')
            ->orderBy('nickname')
            ->limit(200)
            ->get();
        $technicians = Technician::select('id', 'name', 'specialty')->where('is_active', true)->orderBy('name')->limit(100)->get();
        $products = Product::select('id', 'name')->orderBy('name')->limit(100)->get();
        $services = Service::select('id', 'name', 'description')->where('is_active', true)->orderBy('name')->limit(100)->get();

        // Buscar todos os cômodos do cliente da work order
        $rooms = collect();
        if ($workOrder->client) {
            $workOrder->client->load(['addresses.rooms' => function($query) {
                $query->where('active', true);
            }]);

               foreach ($workOrder->client->addresses as $address) {
                   foreach ($address->rooms as $room) {
                       $rooms->push([
                           'id' => $room->id,
                           'name' => $room->name,
                           'full_name' => $room->name . ' - ' . ($address->nickname ?? $address->short_address),
                           'address' => $address->nickname ?? $address->short_address,
                           'devices' => $room->devices()->where('active', true)->get()->map(function($device) {
                               return [
                                   'id' => $device->id,
                                   'label' => $device->label,
                                   'number' => $device->number,
                                   'display_name' => $device->label . ' (' . $device->number . ')'
                               ];
                           })
                       ]);
                   }
               }
        }

        return Inertia::render('WorkOrders/Edit', [
            'workOrder' => $workOrder,
            'clients' => $clients,
            'addresses' => $addresses,
            'technicians' => $technicians,
            'products' => $products,
            'services' => $services,
            'rooms' => $rooms,
        ]);
    }

    public function update(WorkOrderRequest $request, WorkOrder $workOrder)
    {
        try {

            $this->workOrderService->updateWorkOrder($workOrder, $request->validated());

            return redirect()->route('work-orders.show', $workOrder)
                ->with('success', 'Ordem de serviço atualizada com sucesso!');
        } catch (\Exception $e) {
            Log::error('Error updating work order: ' . $e->getMessage(), [
                'work_order_id' => $workOrder->id,
                'data' => $request->all(),
                'error' => $e->getTraceAsString()
            ]);
            return back()->withErrors(['error' => 'Erro ao atualizar ordem de serviço: ' . $e->getMessage()]);
        }
    }

    public function destroy(WorkOrder $workOrder)
    {
        $this->workOrderService->deleteWorkOrder($workOrder);

        return redirect()->route('work-orders.index')
            ->with('success', 'Ordem de serviço excluída com sucesso!');
    }

    public function getByClient($clientId)
    {
        $workOrders = WorkOrder::where('client_id', $clientId)
            ->with(['address', 'technician'])
            ->orderBy('scheduled_date', 'desc')
            ->get();

        return response()->json($workOrders);
    }

    public function getByAddress($addressId)
    {
        $workOrders = WorkOrder::where('address_id', $addressId)
            ->with(['client', 'technician'])
            ->orderBy('scheduled_date', 'desc')
            ->get();

        return response()->json($workOrders);
    }

    public function getByTechnician($technicianId)
    {
        $workOrders = WorkOrder::where('technician_id', $technicianId)
            ->with(['client', 'address'])
            ->orderBy('scheduled_date', 'desc')
            ->get();

        return response()->json($workOrders);
    }

    public function getByStatus($status)
    {
        $workOrders = WorkOrder::where('status', $status)
            ->with(['client', 'address', 'technician'])
            ->orderBy('scheduled_date', 'desc')
            ->get();

        return response()->json($workOrders);
    }

    public function getPending()
    {
        $workOrders = WorkOrder::pending()
            ->with(['client', 'address', 'technician'])
            ->orderBy('scheduled_date', 'asc')
            ->get();

        return response()->json($workOrders);
    }

    public function getOverdue()
    {
        $workOrders = WorkOrder::overdue()
            ->with(['client', 'address', 'technician'])
            ->orderBy('scheduled_date', 'asc')
            ->get();

        return response()->json($workOrders);
    }

    public function getToday()
    {
        $workOrders = WorkOrder::today()
            ->with(['client', 'address', 'technician'])
            ->orderBy('scheduled_date', 'asc')
            ->get();

        return response()->json($workOrders);
    }

    public function getThisWeek()
    {
        $workOrders = WorkOrder::thisWeek()
            ->with(['client', 'address', 'technician'])
            ->orderBy('scheduled_date', 'asc')
            ->get();

        return response()->json($workOrders);
    }

    public function getThisMonth()
    {
        $workOrders = WorkOrder::thisMonth()
            ->with(['client', 'address', 'technician'])
            ->orderBy('scheduled_date', 'asc')
            ->get();

        return response()->json($workOrders);
    }

    /**
     * Gerar recibo de pagamento em PDF
     */
    public function generateReceipt(WorkOrder $workOrder)
    {
        // Log para debug
        Log::info('GenerateReceipt called', [
            'work_order_id' => $workOrder->id,
            'payment_status' => $workOrder->payment_status,
            'user_id' => 'user_authenticated'
        ]);

        // Verificar se o status é "paid"
        if ($workOrder->payment_status !== 'paid') {
            Log::warning('Receipt generation denied - status not paid', [
                'work_order_id' => $workOrder->id,
                'payment_status' => $workOrder->payment_status
            ]);
            return redirect()->back()->with('error', 'Só é possível emitir recibo para ordens de serviço com status "Pago".');
        }

        try {
            // Carregar as relações necessárias
            $workOrder->load([
                'client',
                'address',
                'paymentDetails' => function($query) {
                    $query->whereNotNull('payment_date')->orderBy('payment_date', 'desc');
                }
            ]);

            // Calcular total pago
            $totalPaid = $workOrder->paymentDetails->sum('amount_paid');

            // Gerar número do recibo (baseado no ID da work order + timestamp)
            $receiptNumber = 'REC-' . str_pad($workOrder->id, 6, '0', STR_PAD_LEFT) . '-' . date('Ymd');

            Log::info('Generating PDF', [
                'work_order_id' => $workOrder->id,
                'total_paid' => $totalPaid,
                'receipt_number' => $receiptNumber
            ]);

            // Gerar o PDF com os dados fornecidos
            $pdf = FacadePdf::loadView('pdf.receipt', [
                'workOrder' => $workOrder,
                'payments' => $workOrder->paymentDetails,
                'totalPaid' => $totalPaid,
                'receiptNumber' => $receiptNumber,
            ])->setPaper('a4', 'landscape'); // Definindo o tamanho do papel e o modo paisagem

            // Retornar o PDF para download
            return $pdf->stream('recibo-pagamento-' . $workOrder->id . '.pdf');
        } catch (\Exception $e) {
            Log::error('Error generating receipt', [
                'work_order_id' => $workOrder->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()->back()->with('error', 'Erro ao gerar recibo: ' . $e->getMessage());
        }
    }

    /**
     * Gerar PDF da Ordem de Serviço
     */
    public function generatePDF(WorkOrder $workOrder)
    {
        try {
            // Carregar todos os dados necessários
            $workOrder->load([
                'client',
                'address.client',
                'technician',
                'technicians',
                'products',
                'service',
                'rooms' => function($query) {
                    $query->withPivot([
                        'observation',
                        'event_type_id',
                        'event_date',
                        'event_description',
                        'event_observations',
                        'device_id',
                        'pest_type',
                        'pest_sighting_date',
                        'pest_location',
                        'pest_quantity',
                        'pest_observation'
                    ]);
                },
                'deviceEvents.device.room',
                'pestSightings' => function ($query) {
                    $query->with(['address.client'])->orderBy('sighting_date', 'desc');
                }
            ]);

            // Preparar dados para o PDF
            $data = [
                'workOrder' => $workOrder,
                'currentDate' => now()->format('d/m/Y'),
                'currentTime' => now()->format('H:i'),
            ];

            // Gerar PDF
            $pdf = FacadePdf::loadView('pdf.work-order', $data);
            $pdf->setPaper('A4', 'portrait');

            $filename = 'OS-' . $workOrder->order_number . '-' . now()->format('Y-m-d') . '.pdf';

            return $pdf->stream($filename);
        } catch (\Exception $e) {
            Log::error('Error generating work order PDF', [
                'work_order_id' => $workOrder->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()->back()->with('error', 'Erro ao gerar PDF da OS: ' . $e->getMessage());
        }
    }

    /**
     * Atualizar produto vinculado à OS
     */
    public function addProduct(Request $request, WorkOrder $workOrder, Product $product)
    {
        try {
            $request->validate([
                'quantity' => 'nullable|numeric|min:0',
                'unit' => 'nullable|string',
                'observations' => 'nullable|string|max:500'
            ]);

            // Verificar se o produto já está vinculado à OS
            if ($workOrder->products()->where('product_id', $product->id)->exists()) {
                return back()->withErrors(['message' => 'Produto já está vinculado a esta ordem de serviço']);
            }

            // Adicionar produto à OS
            $workOrder->products()->attach($product->id, [
                'quantity' => $request->quantity,
                'unit' => $request->unit,
                'observations' => $request->observations,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return back()->with('success', 'Produto adicionado à OS com sucesso');

        } catch (\Exception $e) {
            return back()->withErrors(['message' => 'Erro ao adicionar produto: ' . $e->getMessage()]);
        }
    }

    public function updateProduct(Request $request, WorkOrder $workOrder, Product $product)
    {
        try {
            $request->validate([
                'quantity' => 'nullable|numeric|min:0',
                'unit' => 'nullable|string',
                'observations' => 'nullable|string|max:500'
            ]);

            // Verificar se o produto está vinculado à OS
            if (!$workOrder->products()->where('product_id', $product->id)->exists()) {
                return back()->withErrors(['message' => 'Produto não encontrado nesta ordem de serviço']);
            }

            // Atualizar dados do pivot
            $workOrder->products()->updateExistingPivot($product->id, [
                'quantity' => $request->quantity,
                'unit' => $request->unit,
                'observations' => $request->observations,
                'updated_at' => now()
            ]);

            return back()->with('success', 'Produto atualizado com sucesso');

        } catch (\Exception $e) {
            return back()->withErrors(['message' => 'Erro ao atualizar produto: ' . $e->getMessage()]);
        }
    }

    /**
     * Remover produto da OS
     */
    public function removeProduct(Request $request, WorkOrder $workOrder, Product $product)
    {
        try {
            // Verificar se o produto está vinculado à OS
            if (!$workOrder->products()->where('product_id', $product->id)->exists()) {
                return back()->withErrors(['message' => 'Produto não encontrado nesta ordem de serviço']);
            }

            // Remover o produto da OS
            $workOrder->products()->detach($product->id);

            return back()->with('success', 'Produto removido com sucesso');

        } catch (\Exception $e) {
            return back()->withErrors(['message' => 'Erro ao remover produto: ' . $e->getMessage()]);
        }
    }

    /**
     * Atualizar serviço vinculado à OS
     */
    public function addService(Request $request, WorkOrder $workOrder, Service $service)
    {
        try {
            $request->validate([
                'observations' => 'nullable|string|max:500'
            ]);

            // Verificar se o serviço já está vinculado à OS
            if ($workOrder->services()->where('service_id', $service->id)->exists()) {
                return back()->withErrors(['message' => 'Serviço já está vinculado a esta ordem de serviço']);
            }

            // Adicionar serviço à OS
            $workOrder->services()->attach($service->id, [
                'observations' => $request->observations,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return back()->with('success', 'Serviço adicionado à OS com sucesso');

        } catch (\Exception $e) {
            return back()->withErrors(['message' => 'Erro ao adicionar serviço: ' . $e->getMessage()]);
        }
    }

    public function updateService(Request $request, WorkOrder $workOrder, Service $service)
    {
        try {
            $request->validate([
                'observations' => 'nullable|string|max:500'
            ]);

            // Verificar se o serviço está vinculado à OS
            if (!$workOrder->services()->where('service_id', $service->id)->exists()) {
                return back()->withErrors(['message' => 'Serviço não encontrado nesta ordem de serviço']);
            }

            // Atualizar dados do pivot
            $workOrder->services()->updateExistingPivot($service->id, [
                'observations' => $request->observations,
                'updated_at' => now()
            ]);

            return back()->with('success', 'Serviço atualizado com sucesso');

        } catch (\Exception $e) {
            return back()->withErrors(['message' => 'Erro ao atualizar serviço: ' . $e->getMessage()]);
        }
    }

    /**
     * Remover serviço da OS
     */
    public function removeService(Request $request, WorkOrder $workOrder, Service $service)
    {
        try {
            // Verificar se o serviço está vinculado à OS
            if (!$workOrder->services()->where('service_id', $service->id)->exists()) {
                return back()->withErrors(['message' => 'Serviço não encontrado nesta ordem de serviço']);
            }

            // Remover o serviço da OS
            $workOrder->services()->detach($service->id);

            return back()->with('success', 'Serviço removido com sucesso');

        } catch (\Exception $e) {
            return back()->withErrors(['message' => 'Erro ao remover serviço: ' . $e->getMessage()]);
        }
    }

    /**
     * Adicionar técnico à OS
     */
    public function addTechnician(Request $request, WorkOrder $workOrder, Technician $technician)
    {
        try {
            $request->validate([
                'is_primary' => 'nullable|boolean'
            ]);

            if ($workOrder->technicians()->where('technician_id', $technician->id)->exists()) {
                return back()->withErrors(['message' => 'Técnico já está vinculado a esta ordem de serviço']);
            }

            // Se está marcando como principal, remover principal de outros técnicos
            if ($request->boolean('is_primary')) {
                $workOrder->technicians()->updateExistingPivot($workOrder->technicians->pluck('id')->toArray(), ['is_primary' => false]);
            }

            $workOrder->technicians()->attach($technician->id, [
                'is_primary' => $request->boolean('is_primary', false),
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return back()->with('success', 'Técnico adicionado à OS com sucesso');

        } catch (\Exception $e) {
            return back()->withErrors(['message' => 'Erro ao adicionar técnico: ' . $e->getMessage()]);
        }
    }

    /**
     * Remover técnico da OS
     */
    public function removeTechnician(Request $request, WorkOrder $workOrder, Technician $technician)
    {
        try {
            if (!$workOrder->technicians()->where('technician_id', $technician->id)->exists()) {
                return back()->withErrors(['message' => 'Técnico não encontrado nesta ordem de serviço']);
            }

            $workOrder->technicians()->detach($technician->id);

            return back()->with('success', 'Técnico removido com sucesso');

        } catch (\Exception $e) {
            return back()->withErrors(['message' => 'Erro ao remover técnico: ' . $e->getMessage()]);
        }
    }

    /**
     * Atualizar observação de um cômodo
     */
    public function updateRoomObservation(Request $request, WorkOrder $workOrder, $roomId)
    {
        try {
            $request->validate([
                'observation' => 'nullable|string|max:500'
            ]);

            if (!$workOrder->rooms()->where('room_id', $roomId)->exists()) {
                return back()->withErrors(['message' => 'Cômodo não encontrado nesta ordem de serviço']);
            }

            $workOrder->rooms()->updateExistingPivot($roomId, [
                'observation' => $request->observation,
                'updated_at' => now()
            ]);

            return back()->with('success', 'Observação atualizada com sucesso');

        } catch (\Exception $e) {
            return back()->withErrors(['message' => 'Erro ao atualizar observação: ' . $e->getMessage()]);
        }
    }

    /**
     * Adicionar cômodo à OS
     */
    public function addRoom(Request $request, WorkOrder $workOrder)
    {
        try {
            // Preparar dados para validação
            $data = $request->all();

            // Converter campos opcionais vazios para null
            if (empty($data['device_id']) || $data['device_id'] === '') {
                $data['device_id'] = null;
            }
            if (empty($data['pest_quantity']) || $data['pest_quantity'] === '') {
                $data['pest_quantity'] = null;
            }

            $request->merge($data);

            $validationRules = [
                'room_id' => 'required|exists:rooms,id',
                // Campos de evento (obrigatórios)
                'event_type' => 'required|integer|exists:event_types,id',
                'event_date' => 'required|date',
                'event_description' => 'nullable|string|max:1000',
                'event_observations' => 'nullable|string|max:1000',
                // Campos de avistamento (opcionais)
                'pest_type' => 'nullable|string|max:255',
                'pest_sighting_date' => 'nullable|date',
                'pest_location' => 'nullable|string|max:255',
                'pest_observation' => 'nullable|string|max:1000'
            ];

            // Só validar device_id se não for null
            if ($request->device_id !== null) {
                $validationRules['device_id'] = 'exists:devices,id';
            }

            // Só validar pest_quantity se não for null
            if ($request->pest_quantity !== null) {
                $validationRules['pest_quantity'] = 'integer|min:1';
            }

            $request->validate($validationRules);

            if ($workOrder->rooms()->where('room_id', $request->room_id)->exists()) {
                return back()->withErrors(['message' => 'Cômodo já está vinculado a esta ordem de serviço']);
            }

            $workOrder->rooms()->attach($request->room_id, [
                // Campos de evento (obrigatórios)
                'event_type_id' => $request->event_type,
                'event_date' => $request->event_date,
                'event_description' => $request->event_description ?: null,
                'event_observations' => $request->event_observations ?: null,
                'device_id' => $request->device_id ?: null,
                // Campos de avistamento (opcionais)
                'pest_type' => $request->pest_type ?: null,
                'pest_sighting_date' => $request->pest_sighting_date ?: null,
                'pest_location' => $request->pest_location ?: null,
                'pest_quantity' => $request->pest_quantity ?: null,
                'pest_observation' => $request->pest_observation ?: null,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return back()->with('success', 'Cômodo adicionado com sucesso');

        } catch (\Exception $e) {
            return back()->withErrors(['message' => 'Erro ao adicionar cômodo: ' . $e->getMessage()]);
        }
    }

    /**
     * Remover cômodo da OS
     */
    public function removeRoom(Request $request, WorkOrder $workOrder, $roomId)
    {
        try {
            if (!$workOrder->rooms()->where('room_id', $roomId)->exists()) {
                return back()->withErrors(['message' => 'Cômodo não encontrado nesta ordem de serviço']);
            }

            $workOrder->rooms()->detach($roomId);

            return back()->with('success', 'Cômodo removido com sucesso');

        } catch (\Exception $e) {
            return back()->withErrors(['message' => 'Erro ao remover cômodo: ' . $e->getMessage()]);
        }
    }

    /**
     * Buscar cômodos disponíveis para o cliente da OS
     */
    public function getAvailableRooms(WorkOrder $workOrder)
    {
        try {
            $client = $workOrder->client;

            if (!$client) {
                return response()->json(['rooms' => []]);
            }

            $client->load(['addresses.rooms' => function($query) {
                $query->where('active', true);
            }]);

            $rooms = [];
            $selectedRoomIds = $workOrder->rooms->pluck('id')->toArray();

            foreach ($client->addresses as $address) {
                foreach ($address->rooms as $room) {
                    if (!in_array($room->id, $selectedRoomIds)) {
                        $rooms[] = [
                            'id' => $room->id,
                            'name' => $room->name,
                            'address' => $address->nickname ?? $address->short_address,
                            'full_name' => $room->name . ' - ' . ($address->nickname ?? $address->short_address),
                        ];
                    }
                }
            }

            return response()->json(['rooms' => $rooms]);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Erro ao buscar cômodos: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get rooms by client with devices for Work Orders
     */
    public function getRoomsByClientWithDevices(Request $request)
    {
        $clientId = $request->get('client_id');

        if (!$clientId) {
            return response()->json(['rooms' => []]);
        }

        $client = Client::with(['addresses.rooms' => function($query) {
            $query->where('active', true);
        }])->find($clientId);

        if (!$client) {
            return response()->json(['rooms' => []]);
        }

        $rooms = [];
        foreach ($client->addresses as $address) {
            foreach ($address->rooms as $room) {
                $rooms[] = [
                    'id' => $room->id,
                    'name' => $room->name,
                    'address' => $address->nickname ?? $address->short_address,
                    'full_name' => $room->name . ' - ' . ($address->nickname ?? $address->short_address),
                    'devices' => $room->devices()->where('active', true)->get()->map(function($device) {
                        return [
                            'id' => $device->id,
                            'label' => $device->label,
                            'number' => $device->number,
                            'display_name' => $device->label . ' (' . $device->number . ')'
                        ];
                    })
                ];
            }
        }

        return response()->json(['rooms' => $rooms]);
    }

    /**
     * Adicionar evento a um cômodo
     */
    public function addRoomEvent(Request $request, WorkOrder $workOrder, $roomId)
    {
        try {
            // Preparar dados para validação
            $data = $request->all();

            // Converter device_id vazio para null
            if (empty($data['device_id']) || $data['device_id'] === '') {
                $data['device_id'] = null;
            }

            $request->merge($data);

            $validationRules = [
                'event_type' => 'required|integer|exists:event_types,id',
                'event_date' => 'required|date',
                'event_description' => 'nullable|string|max:1000',
                'event_observations' => 'nullable|string|max:1000',
            ];

            // Só validar device_id se não for null
            if ($request->device_id !== null) {
                $validationRules['device_id'] = 'exists:devices,id';
            }

            $request->validate($validationRules);

            if (!$workOrder->rooms()->where('room_id', $roomId)->exists()) {
                return back()->withErrors(['message' => 'Cômodo não encontrado nesta ordem de serviço']);
            }

            // Verificar se já existe evento para este cômodo
            $room = $workOrder->rooms()->where('room_id', $roomId)->first();
            if ($room->pivot->event_type_id) {
                return back()->withErrors(['message' => 'Já existe um evento registrado para este cômodo']);
            }

            // Adicionar evento
            $workOrder->rooms()->updateExistingPivot($roomId, [
                'event_type_id' => $request->event_type,
                'event_date' => $request->event_date,
                'event_description' => $request->event_description ?: null,
                'event_observations' => $request->event_observations ?: null,
                'device_id' => $request->device_id ?: null
            ]);

            return back()->with('success', 'Evento adicionado com sucesso');

        } catch (\Exception $e) {
            Log::error('Erro ao adicionar evento do cômodo', [
                'work_order_id' => $workOrder->id,
                'room_id' => $roomId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return back()->withErrors(['message' => 'Erro ao adicionar evento: ' . $e->getMessage()]);
        }
    }

    /**
     * Atualizar evento de um cômodo
     */
    public function updateRoomEvent(Request $request, WorkOrder $workOrder, $roomId)
    {
        try {
            // Preparar dados para validação
            $data = $request->all();


            // Converter device_id vazio para null
            if (empty($data['device_id']) || $data['device_id'] === '') {
                $data['device_id'] = null;
            }

            $request->merge($data);

            $validationRules = [
                'event_type' => 'required|integer|exists:event_types,id',
                'event_date' => 'required|date',
                'event_description' => 'nullable|string|max:1000',
                'event_observations' => 'nullable|string|max:1000',
            ];

            // Só validar device_id se não for null
            if ($request->device_id !== null) {
                $validationRules['device_id'] = 'exists:devices,id';
            }

            $request->validate($validationRules);

            if (!$workOrder->rooms()->where('room_id', $roomId)->exists()) {
                return back()->withErrors(['message' => 'Cômodo não encontrado nesta ordem de serviço']);
            }

            // Verificar se existe evento para este cômodo
            $room = $workOrder->rooms()->where('room_id', $roomId)->first();
            if (!$room->pivot->event_type_id) {
                return back()->withErrors(['message' => 'Nenhum evento encontrado para este cômodo']);
            }

            // Atualizar evento
            $workOrder->rooms()->updateExistingPivot($roomId, [
                'event_type_id' => $request->event_type,
                'event_date' => $request->event_date,
                'event_description' => $request->event_description ?: null,
                'event_observations' => $request->event_observations ?: null,
                'device_id' => $request->device_id ?: null
            ]);

            return back()->with('success', 'Evento atualizado com sucesso');

        } catch (\Exception $e) {
            Log::error('Erro ao atualizar evento do cômodo', [
                'work_order_id' => $workOrder->id,
                'room_id' => $roomId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return back()->withErrors(['message' => 'Erro ao atualizar evento: ' . $e->getMessage()]);
        }
    }

    /**
     * Remover evento de um cômodo
     */
    public function removeRoomEvent(Request $request, WorkOrder $workOrder, $roomId)
    {
        try {
            if (!$workOrder->rooms()->where('room_id', $roomId)->exists()) {
                return back()->withErrors(['message' => 'Cômodo não encontrado nesta ordem de serviço']);
            }

            // Verificar se existe evento para este cômodo
            $room = $workOrder->rooms()->where('room_id', $roomId)->first();
            if (!$room->pivot->event_type_id) {
                return back()->withErrors(['message' => 'Nenhum evento encontrado para este cômodo']);
            }

            // Remover evento (limpar campos)
            $workOrder->rooms()->updateExistingPivot($roomId, [
                'event_type_id' => null,
                'event_date' => null,
                'event_description' => null,
                'event_observations' => null,
                'device_id' => null
            ]);

            return back()->with('success', 'Evento removido com sucesso');

        } catch (\Exception $e) {
            Log::error('Erro ao remover evento do cômodo', [
                'work_order_id' => $workOrder->id,
                'room_id' => $roomId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return back()->withErrors(['message' => 'Erro ao remover evento: ' . $e->getMessage()]);
        }
    }

    /**
     * Adicionar avistamento de praga a um cômodo
     */
    public function addRoomPestSighting(Request $request, WorkOrder $workOrder, $roomId)
    {
        try {
            // Preparar dados para validação
            $data = $request->all();

            // Converter pest_quantity vazio para null
            if (empty($data['pest_quantity']) || $data['pest_quantity'] === '') {
                $data['pest_quantity'] = null;
            }

            $request->merge($data);

            $validationRules = [
                'pest_type' => 'required|string|max:255',
                'pest_sighting_date' => 'required|date',
                'pest_location' => 'nullable|string|max:255',
                'pest_observation' => 'nullable|string|max:1000'
            ];

            // Só validar pest_quantity se não for null
            if ($request->pest_quantity !== null) {
                $validationRules['pest_quantity'] = 'integer|min:1';
            }

            $request->validate($validationRules);

            if (!$workOrder->rooms()->where('room_id', $roomId)->exists()) {
                return back()->withErrors(['message' => 'Cômodo não encontrado nesta ordem de serviço']);
            }

            // Verificar se já existe avistamento para este cômodo
            $room = $workOrder->rooms()->where('room_id', $roomId)->first();
            if ($room->pivot->pest_type) {
                return back()->withErrors(['message' => 'Já existe um avistamento registrado para este cômodo']);
            }

            // Adicionar avistamento
            $workOrder->rooms()->updateExistingPivot($roomId, [
                'pest_type' => $request->pest_type,
                'pest_sighting_date' => $request->pest_sighting_date,
                'pest_location' => $request->pest_location ?: null,
                'pest_quantity' => $request->pest_quantity ?: null,
                'pest_observation' => $request->pest_observation ?: null
            ]);

            return back()->with('success', 'Avistamento adicionado com sucesso');

        } catch (\Exception $e) {
            Log::error('Erro ao adicionar avistamento de praga do cômodo', [
                'work_order_id' => $workOrder->id,
                'room_id' => $roomId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return back()->withErrors(['message' => 'Erro ao adicionar avistamento: ' . $e->getMessage()]);
        }
    }

    /**
     * Atualizar avistamento de praga de um cômodo
     */
    public function updateRoomPestSighting(Request $request, WorkOrder $workOrder, $roomId)
    {
        try {
            // Preparar dados para validação
            $data = $request->all();

            // Converter pest_quantity vazio para null
            if (empty($data['pest_quantity']) || $data['pest_quantity'] === '') {
                $data['pest_quantity'] = null;
            }

            $request->merge($data);

            $validationRules = [
                'pest_type' => 'required|string|max:255',
                'pest_sighting_date' => 'required|date',
                'pest_location' => 'nullable|string|max:255',
                'pest_observation' => 'nullable|string|max:1000'
            ];

            // Só validar pest_quantity se não for null
            if ($request->pest_quantity !== null) {
                $validationRules['pest_quantity'] = 'integer|min:1';
            }

            $request->validate($validationRules);

            if (!$workOrder->rooms()->where('room_id', $roomId)->exists()) {
                return back()->withErrors(['message' => 'Cômodo não encontrado nesta ordem de serviço']);
            }

            // Verificar se existe avistamento para este cômodo
            $room = $workOrder->rooms()->where('room_id', $roomId)->first();
            if (!$room->pivot->pest_type) {
                return back()->withErrors(['message' => 'Nenhum avistamento encontrado para este cômodo']);
            }

            // Atualizar avistamento
            $workOrder->rooms()->updateExistingPivot($roomId, [
                'pest_type' => $request->pest_type,
                'pest_sighting_date' => $request->pest_sighting_date,
                'pest_location' => $request->pest_location ?: null,
                'pest_quantity' => $request->pest_quantity ?: null,
                'pest_observation' => $request->pest_observation ?: null
            ]);

            return back()->with('success', 'Avistamento atualizado com sucesso');

        } catch (\Exception $e) {
            Log::error('Erro ao atualizar avistamento de praga do cômodo', [
                'work_order_id' => $workOrder->id,
                'room_id' => $roomId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return back()->withErrors(['message' => 'Erro ao atualizar avistamento: ' . $e->getMessage()]);
        }
    }

    /**
     * Remover avistamento de praga de um cômodo
     */
    public function removeRoomPestSighting(Request $request, WorkOrder $workOrder, $roomId)
    {
        try {
            if (!$workOrder->rooms()->where('room_id', $roomId)->exists()) {
                return back()->withErrors(['message' => 'Cômodo não encontrado nesta ordem de serviço']);
            }

            // Verificar se existe avistamento para este cômodo
            $room = $workOrder->rooms()->where('room_id', $roomId)->first();
            if (!$room->pivot->pest_type) {
                return back()->withErrors(['message' => 'Nenhum avistamento encontrado para este cômodo']);
            }

            // Remover avistamento (limpar campos)
            $workOrder->rooms()->updateExistingPivot($roomId, [
                'pest_type' => null,
                'pest_sighting_date' => null,
                'pest_location' => null,
                'pest_quantity' => null,
                'pest_observation' => null
            ]);

            return back()->with('success', 'Avistamento removido com sucesso');

        } catch (\Exception $e) {
            Log::error('Erro ao remover avistamento de praga do cômodo', [
                'work_order_id' => $workOrder->id,
                'room_id' => $roomId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return back()->withErrors(['message' => 'Erro ao remover avistamento: ' . $e->getMessage()]);
        }
    }
}
