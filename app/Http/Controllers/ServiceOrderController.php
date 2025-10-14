<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceOrderRequest;
use App\Models\ServiceOrder;
use App\Models\Client;
use App\Models\Service;
use App\Services\ServiceOrderService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Barryvdh\DomPDF\Facade\Pdf;

class ServiceOrderController extends Controller
{
    protected $serviceOrderService;

    public function __construct(ServiceOrderService $serviceOrderService)
    {
        $this->serviceOrderService = $serviceOrderService;
    }

    public function index(Request $request)
    {
        $search = $request->get('search', '');

        if ($search) {
            $serviceOrders = $this->serviceOrderService->searchServiceOrders($search);
        } else {
            $serviceOrders = $this->serviceOrderService->getServiceOrdersPaginated();
        }

        return Inertia::render('ServiceOrders/Index', [
            'serviceOrders' => $serviceOrders,
            'search' => $search,
        ]);
    }

    public function create()
    {
        $clients = Client::orderBy('name')->get();
        $services = Service::where('is_active', true)->orderBy('name')->get();

        return Inertia::render('ServiceOrders/Create', [
            'clients' => $clients,
            'services' => $services,
        ]);
    }

    public function store(ServiceOrderRequest $request)
    {
        $data = $request->validated();

        // Generate order number if not provided
        if (empty($data['order_number'])) {
            $data['order_number'] = $this->generateServiceOrderNumber();
        }

        $serviceOrder = $this->serviceOrderService->createServiceOrder($data);

        return redirect()->route('service-orders.index')
            ->with('success', 'Ordem de serviço criada com sucesso!');
    }

    public function show(ServiceOrder $serviceOrder)
    {
        $serviceOrder->load(['client', 'technician', 'rooms']);

        return Inertia::render('ServiceOrders/Show', [
            'serviceOrder' => $serviceOrder,
        ]);
    }

    public function edit(ServiceOrder $serviceOrder)
    {
        $clients = Client::orderBy('name')->get();
        $services = Service::where('is_active', true)->orderBy('name')->get();

        // Carregar rooms com pivot data
        $serviceOrder->load(['rooms' => function($query) {
            $query->withPivot('observation');
        }]);

        return Inertia::render('ServiceOrders/Edit', [
            'serviceOrder' => $serviceOrder,
            'clients' => $clients,
            'services' => $services,
        ]);
    }

    public function update(ServiceOrderRequest $request, ServiceOrder $serviceOrder)
    {
        $this->serviceOrderService->updateServiceOrder($serviceOrder, $request->validated());

        return redirect()->route('service-orders.show', $serviceOrder)
            ->with('success', 'Ordem de serviço atualizada com sucesso!');
    }

    public function destroy(ServiceOrder $serviceOrder)
    {
        $this->serviceOrderService->deleteServiceOrder($serviceOrder);

        return redirect()->route('service-orders.index')
            ->with('success', 'Ordem de serviço excluída com sucesso!');
    }

    /**
     * Generate PDF for service order
     */
    public function generatePdf(ServiceOrder $serviceOrder)
    {
        $serviceOrder->load([
            'client',
            'technician',
            'service',
            'rooms' => function($query) {
                $query->withPivot('observation');
            }
        ]);

        $pdf = Pdf::loadView('pdf.service-order', [
            'serviceOrder' => $serviceOrder
        ]);

        return $pdf->download('OS-' . $serviceOrder->order_number . '.pdf');
    }

    /**
     * Get rooms by client
     */
    public function getRoomsByClient(Request $request)
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
                ];
            }
        }

        return response()->json(['rooms' => $rooms]);
    }

    /**
     * Generate service order number.
     */
    private function generateServiceOrderNumber(): string
    {
        do {
            $lastOrder = ServiceOrder::orderBy('id', 'desc')->first();
            $nextId = $lastOrder ? $lastOrder->id + 1 : 1;
            $orderNumber = 'SO' . str_pad($nextId, 6, '0', STR_PAD_LEFT);

            // Check if this order number already exists
            $exists = ServiceOrder::where('order_number', $orderNumber)->exists();

            if (!$exists) {
                return $orderNumber;
            }

            // If exists, increment and try again
            $nextId++;
        } while (true);
    }
}
