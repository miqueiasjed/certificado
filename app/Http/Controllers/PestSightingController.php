<?php

namespace App\Http\Controllers;

use App\Http\Requests\PestSightingRequest;
use App\Models\PestSighting;
use App\Models\Address;
use App\Models\WorkOrder;
use App\Services\PestSightingService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PestSightingController extends Controller
{
    protected $pestSightingService;

    public function __construct(PestSightingService $pestSightingService)
    {
        $this->pestSightingService = $pestSightingService;
    }

    public function index(Request $request)
    {
        $query = PestSighting::with(['address.client', 'workOrder.technician']);

        // Filtros
        if ($request->filled('address_id')) {
            $query->where('address_id', $request->address_id);
        }

        if ($request->filled('work_order_id')) {
            $query->where('work_order_id', $request->work_order_id);
        }

        if ($request->filled('pest_type')) {
            $query->where('pest_type', $request->pest_type);
        }

        if ($request->filled('severity_level')) {
            $query->where('severity_level', $request->severity_level);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('sighting_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('sighting_date', '<=', $request->date_to);
        }

        $pestSightings = $query->orderBy('sighting_date', 'desc')->paginate(15);

        return Inertia::render('PestSightings/Index', [
            'pestSightings' => $pestSightings,
            'filters' => $request->only(['address_id', 'work_order_id', 'pest_type', 'severity_level', 'date_from', 'date_to']),
        ]);
    }

    public function create(Request $request)
    {
        $addresses = Address::with('client')->orderBy('nickname')->get();
        $workOrders = WorkOrder::with('technician')->orderBy('order_number')->get();

        return Inertia::render('PestSightings/Create', [
            'addresses' => $addresses,
            'workOrders' => $workOrders,
            'preselectedAddress' => $request->address_id,
            'preselectedWorkOrder' => $request->work_order_id,
        ]);
    }

    public function store(PestSightingRequest $request)
    {
        try {
            $pestSighting = $this->pestSightingService->createPestSighting($request->validated());

            return back()->with('success', 'Avistamento de praga criado com sucesso!');
        } catch (\Exception $e) {
            return back()->withErrors(['message' => 'Erro ao criar avistamento: ' . $e->getMessage()]);
        }
    }

    public function show(PestSighting $pestSighting)
    {
        $pestSighting->load(['address.client', 'workOrder.technician', 'items']);

        // Se a requisição espera JSON (chamadas via fetch/AJAX) ou explicitamente passar ?json=1, retorna JSON
        if (request()->wantsJson() || request()->boolean('json')) {
            return response()->json([
                'success' => true,
                'pestSighting' => $pestSighting,
            ]);
        }

        return Inertia::render('PestSightings/Show', [
            'pestSighting' => $pestSighting,
        ]);
    }

    public function edit(PestSighting $pestSighting)
    {
        $pestSighting->load(['address.client', 'workOrder.technician', 'items']);
        $addresses = Address::with('client')->orderBy('nickname')->get();
        $workOrders = WorkOrder::with('technician')->orderBy('order_number')->get();

        return Inertia::render('PestSightings/Edit', [
            'pestSighting' => $pestSighting,
            'addresses' => $addresses,
            'workOrders' => $workOrders,
        ]);
    }

    public function update(PestSightingRequest $request, PestSighting $pestSighting)
    {
        try {
            $updated = $this->pestSightingService->updatePestSighting($pestSighting, $request->validated());

            if ($updated) {
                // Recarregar o modelo com relacionamentos
                $pestSighting->refresh();
                $pestSighting->load(['address.client', 'workOrder.technician']);

                return back()->with('success', 'Avistamento de praga atualizado com sucesso!');
            } else {
                return back()->withErrors(['message' => 'Erro ao atualizar avistamento de praga']);
            }
        } catch (\Exception $e) {
            return back()->withErrors(['message' => 'Erro ao atualizar avistamento: ' . $e->getMessage()]);
        }
    }

    public function destroy(PestSighting $pestSighting)
    {
        $this->pestSightingService->deletePestSighting($pestSighting);

        return redirect()->route('pest-sightings.index')
            ->with('success', 'Avistamento de praga excluído com sucesso!');
    }

    public function getByAddress($addressId)
    {
        $pestSightings = PestSighting::where('address_id', $addressId)
            ->with(['workOrder.technician', 'items'])
            ->orderBy('sighting_date', 'desc')
            ->get();

        return response()->json($pestSightings);
    }

    public function getByWorkOrder($workOrderId)
    {
        $pestSightings = PestSighting::where('work_order_id', $workOrderId)
            ->with(['address.client', 'items'])
            ->orderBy('sighting_date', 'desc')
            ->get();

        return response()->json($pestSightings);
    }

    public function getByPestType($pestType)
    {
        $pestSightings = PestSighting::where('pest_type', $pestType)
            ->with(['address.client', 'workOrder.technician'])
            ->orderBy('sighting_date', 'desc')
            ->get();

        return response()->json($pestSightings);
    }
}

