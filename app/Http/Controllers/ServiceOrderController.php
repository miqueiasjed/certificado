<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceOrderRequest;
use App\Models\ServiceOrder;
use App\Models\Client;
use App\Models\Service;
use App\Services\ServiceOrderService;
use Illuminate\Http\Request;
use Inertia\Inertia;

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
        $serviceOrder = $this->serviceOrderService->createServiceOrder($request->validated());

        return redirect()->route('service-orders.index')
            ->with('success', 'Ordem de serviço criada com sucesso!');
    }

    public function show(ServiceOrder $serviceOrder)
    {
        $serviceOrder->load(['client', 'service']);

        return Inertia::render('ServiceOrders/Show', [
            'serviceOrder' => $serviceOrder,
        ]);
    }

    public function edit(ServiceOrder $serviceOrder)
    {
        $clients = Client::orderBy('name')->get();
        $services = Service::where('is_active', true)->orderBy('name')->get();

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
}
