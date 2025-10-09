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

        if ($request->filled('order_type')) {
            $query->where('order_type', $request->order_type);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('scheduled_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('scheduled_date', '<=', $request->date_to);
        }

        $workOrders = $query->orderBy('scheduled_date', 'desc')->paginate(15);

        return Inertia::render('WorkOrders/Index', [
            'workOrders' => $workOrders,
            'filters' => $request->only(['client_id', 'address_id', 'technician_id', 'status', 'priority_level', 'order_type', 'date_from', 'date_to']),
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
        $serviceTypes = ServiceType::select('id', 'name', 'slug')->where('active', true)->orderBy('sort_order')->orderBy('name')->limit(50)->get();
        $products = Product::select('id', 'name')->orderBy('name')->limit(100)->get();
        $services = Service::select('id', 'name', 'description')->where('is_active', true)->orderBy('name')->limit(100)->get();

        return Inertia::render('WorkOrders/Create', [
            'clients' => $clients,
            'addresses' => $addresses,
            'technicians' => $technicians,
            'serviceTypes' => $serviceTypes,
            'products' => $products,
            'services' => $services,
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
            'products',
            'services',
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

        return Inertia::render('WorkOrders/Show', [
            'workOrder' => $workOrder,
            'availableDevices' => $availableDevices,
            'availableAddresses' => $availableAddresses,
            'availableProducts' => $availableProducts,
            'availableServices' => $availableServices,
            'availableTechnicians' => $availableTechnicians,
        ]);
    }

    public function edit(WorkOrder $workOrder)
    {
        $workOrder->load([
            'client',
            'address.client',
            'technician',
            'technicians',
            'products',
            'services',
            'serviceType',
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
        $serviceTypes = ServiceType::select('id', 'name', 'slug')->where('active', true)->orderBy('sort_order')->orderBy('name')->limit(50)->get();
        $products = Product::select('id', 'name')->orderBy('name')->limit(100)->get();
        $services = Service::select('id', 'name', 'description')->where('is_active', true)->orderBy('name')->limit(100)->get();

        return Inertia::render('WorkOrders/Edit', [
            'workOrder' => $workOrder,
            'clients' => $clients,
            'addresses' => $addresses,
            'technicians' => $technicians,
            'serviceTypes' => $serviceTypes,
            'products' => $products,
            'services' => $services,
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
                'serviceType',
                'products',
                'services',
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
                'quantity' => 'required|integer|min:1',
                'observations' => 'nullable|string|max:500'
            ]);

            // Verificar se o produto já está vinculado à OS
            if ($workOrder->products()->where('product_id', $product->id)->exists()) {
                return response()->json(['message' => 'Produto já está vinculado a esta ordem de serviço'], 400);
            }

            // Adicionar produto à OS
            $workOrder->products()->attach($product->id, [
                'quantity' => $request->quantity,
                'observations' => $request->observations,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return response()->json(['message' => 'Produto adicionado à OS com sucesso']);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Erro ao adicionar produto: ' . $e->getMessage()], 500);
        }
    }

    public function updateProduct(Request $request, WorkOrder $workOrder, Product $product)
    {
        try {
            $request->validate([
                'quantity' => 'required|integer|min:1',
                'observations' => 'nullable|string|max:500'
            ]);

            // Verificar se o produto está vinculado à OS
            if (!$workOrder->products()->where('product_id', $product->id)->exists()) {
                return response()->json(['message' => 'Produto não encontrado nesta ordem de serviço'], 404);
            }

            // Atualizar dados do pivot
            $workOrder->products()->updateExistingPivot($product->id, [
                'quantity' => $request->quantity,
                'observations' => $request->observations,
                'updated_at' => now()
            ]);

            return response()->json(['message' => 'Produto atualizado com sucesso']);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Erro ao atualizar produto: ' . $e->getMessage()], 500);
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
                return response()->json(['message' => 'Produto não encontrado nesta ordem de serviço'], 404);
            }

            // Remover o produto da OS
            $workOrder->products()->detach($product->id);

            return response()->json(['message' => 'Produto removido com sucesso']);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Erro ao remover produto: ' . $e->getMessage()], 500);
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
                return response()->json(['message' => 'Serviço já está vinculado a esta ordem de serviço'], 400);
            }

            // Adicionar serviço à OS
            $workOrder->services()->attach($service->id, [
                'observations' => $request->observations,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            return response()->json(['message' => 'Serviço adicionado à OS com sucesso']);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Erro ao adicionar serviço: ' . $e->getMessage()], 500);
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
                return response()->json(['message' => 'Serviço não encontrado nesta ordem de serviço'], 404);
            }

            // Atualizar dados do pivot
            $workOrder->services()->updateExistingPivot($service->id, [
                'observations' => $request->observations,
                'updated_at' => now()
            ]);

            return response()->json(['message' => 'Serviço atualizado com sucesso']);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Erro ao atualizar serviço: ' . $e->getMessage()], 500);
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
                return response()->json(['message' => 'Serviço não encontrado nesta ordem de serviço'], 404);
            }

            // Remover o serviço da OS
            $workOrder->services()->detach($service->id);

            return response()->json(['message' => 'Serviço removido com sucesso']);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Erro ao remover serviço: ' . $e->getMessage()], 500);
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
                return response()->json(['message' => 'Técnico já está vinculado a esta ordem de serviço'], 400);
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

            return response()->json(['message' => 'Técnico adicionado à OS com sucesso']);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Erro ao adicionar técnico: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Remover técnico da OS
     */
    public function removeTechnician(Request $request, WorkOrder $workOrder, Technician $technician)
    {
        try {
            if (!$workOrder->technicians()->where('technician_id', $technician->id)->exists()) {
                return response()->json(['message' => 'Técnico não encontrado nesta ordem de serviço'], 404);
            }

            $workOrder->technicians()->detach($technician->id);

            return response()->json(['message' => 'Técnico removido com sucesso']);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Erro ao remover técnico: ' . $e->getMessage()], 500);
        }
    }
}
