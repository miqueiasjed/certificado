<?php

namespace App\Http\Controllers;

use App\Models\OrganRegistration;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrganRegistrationController extends Controller
{
    public function index(Request $request)
    {
        $query = OrganRegistration::query();

        // Filtros
        if ($request->filled('search')) {
            $query->where('record', 'like', '%' . $request->search . '%');
        }

        $organRegistrations = $query->withCount('products')->orderBy('record')->paginate(15);

        // Estatísticas
        $stats = [
            'total' => OrganRegistration::count(),
        ];

        return Inertia::render('OrganRegistrations/Index', [
            'organRegistrations' => $organRegistrations,
            'stats' => $stats,
            'filters' => $request->only(['search']),
        ]);
    }

    public function create()
    {
        return Inertia::render('OrganRegistrations/Create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'record' => 'required|string|max:255|unique:organ_registrations,record',
        ]);

        $organRegistration = OrganRegistration::create([
            'record' => $request->record,
        ]);

        return redirect()->route('organ-registrations.index')
            ->with('success', 'Registro ministerial criado com sucesso!');
    }

    public function show(OrganRegistration $organRegistration)
    {
        return Inertia::render('OrganRegistrations/Show', [
            'organRegistration' => $organRegistration,
        ]);
    }

    public function edit(OrganRegistration $organRegistration)
    {
        return Inertia::render('OrganRegistrations/Edit', [
            'organRegistration' => $organRegistration,
        ]);
    }

    public function update(Request $request, OrganRegistration $organRegistration)
    {
        $request->validate([
            'record' => 'required|string|max:255|unique:organ_registrations,record,' . $organRegistration->id,
        ]);

        $organRegistration->update([
            'record' => $request->record,
        ]);

        return redirect()->route('organ-registrations.index')
            ->with('success', 'Registro ministerial atualizado com sucesso!');
    }

    public function destroy(OrganRegistration $organRegistration)
    {
        // Verificar se há produtos usando este registro ministerial
        $productsCount = $organRegistration->products()->count();

        if ($productsCount > 0) {
            return redirect()->back()
                ->with('error', "Não é possível excluir o registro '{$organRegistration->record}' pois existem {$productsCount} produto(s) vinculado(s) a ele.");
        }

        $organRegistration->delete();

        return redirect()->route('organ-registrations.index')
            ->with('success', 'Registro ministerial excluído com sucesso!');
    }
}
