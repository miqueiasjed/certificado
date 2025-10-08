<?php

namespace App\Http\Controllers;

use App\Models\ServiceType;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ServiceTypeController extends Controller
{
    public function index(Request $request)
    {
        $query = ServiceType::query();

        // Filtros
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->filled('active')) {
            $query->where('active', $request->active);
        }

        $serviceTypes = $query->withCount('serviceOrders')->orderBy('sort_order')->orderBy('name')->paginate(15);

        // Estatísticas
        $stats = [
            'total' => ServiceType::count(),
            'active' => ServiceType::where('active', true)->count(),
            'inactive' => ServiceType::where('active', false)->count(),
        ];

        return Inertia::render('ServiceTypes/Index', [
            'serviceTypes' => $serviceTypes,
            'stats' => $stats,
            'filters' => $request->only(['search', 'active']),
        ]);
    }

    public function create()
    {
        return Inertia::render('ServiceTypes/Create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:service_types,name',
            'description' => 'nullable|string',
            'active' => 'boolean',
        ]);

        $serviceType = ServiceType::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'description' => $request->description,
            'active' => $request->active ?? true,
            'sort_order' => ServiceType::max('sort_order') + 1,
        ]);

        return redirect()->route('service-types.index')
            ->with('success', 'Tipo de serviço criado com sucesso!');
    }

    public function show(ServiceType $serviceType)
    {
        $serviceOrdersCount = $serviceType->serviceOrders()->count();

        return Inertia::render('ServiceTypes/Show', [
            'serviceType' => $serviceType,
            'serviceOrdersCount' => $serviceOrdersCount,
        ]);
    }

    public function edit(ServiceType $serviceType)
    {
        return Inertia::render('ServiceTypes/Edit', [
            'serviceType' => $serviceType,
        ]);
    }

    public function update(Request $request, ServiceType $serviceType)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:service_types,name,' . $serviceType->id,
            'description' => 'nullable|string',
            'active' => 'boolean',
        ]);

        $serviceType->update([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'description' => $request->description,
            'active' => $request->active ?? true,
        ]);

        return redirect()->route('service-types.index')
            ->with('success', 'Tipo de serviço atualizado com sucesso!');
    }

    public function destroy(ServiceType $serviceType)
    {
        try {
            // Verificar se há ordens de serviço usando este tipo
            $serviceOrdersCount = $serviceType->serviceOrders()->count();

            if ($serviceOrdersCount > 0) {
                return redirect()->back()
                    ->with('error', "Não é possível excluir o tipo '{$serviceType->name}' pois existem {$serviceOrdersCount} ordem(ns) de serviço vinculada(s) a ele.");
            }

            $serviceType->delete();

            return redirect()->route('service-types.index')
                ->with('success', 'Tipo de serviço excluído com sucesso!');
        } catch (\Illuminate\Database\QueryException $e) {
            // Capturar erro de foreign key constraint
            if ($e->getCode() == 23000) {
                $serviceOrdersCount = $serviceType->serviceOrders()->count();
                return redirect()->back()
                    ->with('error', "Não é possível excluir o tipo '{$serviceType->name}' pois existem {$serviceOrdersCount} ordem(ns) de serviço vinculada(s) a ele.");
            }

            // Re-throw outros erros
            throw $e;
        }
    }

    // Método para API (usado pelo modal)
    public function getActive()
    {
        $serviceTypes = ServiceType::getActiveTypes();

        return response()->json($serviceTypes);
    }
}
