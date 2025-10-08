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
        $technicians = User::select('id', 'name', 'specialty')->where('is_technician', true)->orderBy('name')->limit(100)->get();
        $serviceTypes = ServiceType::select('id', 'name', 'slug')->where('active', true)->orderBy('sort_order')->orderBy('name')->limit(50)->get();

        Log::info('Técnicos carregados para create: ' . $technicians->count());
        Log::info('Técnicos: ' . $technicians->toJson());

        return Inertia::render('WorkOrders/Create', [
            'clients' => $clients,
            'addresses' => $addresses,
            'technicians' => $technicians,
            'serviceTypes' => $serviceTypes,
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

            // Log simples para debug
            Log::info('Dispositivos disponíveis: ' . $availableDevices->count());
        }

        // Carregar endereços disponíveis do cliente para avistamentos
        $availableAddresses = collect();
        if ($workOrder->client) {
            $availableAddresses = Address::where('client_id', $workOrder->client_id)
                ->orderBy('nickname')
                ->get();

            Log::info('Available Addresses Count: ' . $availableAddresses->count());
        }

        return Inertia::render('WorkOrders/Show', [
            'workOrder' => $workOrder,
            'availableDevices' => $availableDevices,
            'availableAddresses' => $availableAddresses,
        ]);
    }

    public function edit(WorkOrder $workOrder)
    {
        $workOrder->load([
            'client',
            'address.client',
            'technician',
            'technicians',
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
        $technicians = User::select('id', 'name', 'specialty')->where('is_technician', true)->orderBy('name')->limit(100)->get();
        $serviceTypes = ServiceType::select('id', 'name', 'slug')->where('active', true)->orderBy('sort_order')->orderBy('name')->limit(50)->get();

        Log::info('Técnicos carregados para edit: ' . $technicians->count());
        Log::info('Técnicos: ' . $technicians->toJson());

        return Inertia::render('WorkOrders/Edit', [
            'workOrder' => $workOrder,
            'clients' => $clients,
            'addresses' => $addresses,
            'technicians' => $technicians,
            'serviceTypes' => $serviceTypes,
        ]);
    }

    public function update(WorkOrderRequest $request, WorkOrder $workOrder)
    {
        try {
            Log::info('Updating work order', [
                'work_order_id' => $workOrder->id,
                'data' => $request->validated()
            ]);

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
            'user_id' => 'user_' . (auth()->id() ?? 'guest')
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
}
