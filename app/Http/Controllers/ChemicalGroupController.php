<?php

namespace App\Http\Controllers;

use App\Models\ChemicalGroup;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ChemicalGroupController extends Controller
{
    public function index(Request $request)
    {
        $query = ChemicalGroup::query();

        // Filtros
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $chemicalGroups = $query->withCount('products')->orderBy('name')->paginate(15);

        // Estatísticas
        $stats = [
            'total' => ChemicalGroup::count(),
        ];

        return Inertia::render('ChemicalGroups/Index', [
            'chemicalGroups' => $chemicalGroups,
            'stats' => $stats,
            'filters' => $request->only(['search']),
        ]);
    }

    public function create()
    {
        return Inertia::render('ChemicalGroups/Create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:chemical_groups,name',
        ]);

        $chemicalGroup = ChemicalGroup::create([
            'name' => $request->name,
        ]);

        return redirect()->route('chemical-groups.index')
            ->with('success', 'Grupo químico criado com sucesso!');
    }

    public function show(ChemicalGroup $chemicalGroup)
    {
        return Inertia::render('ChemicalGroups/Show', [
            'chemicalGroup' => $chemicalGroup,
        ]);
    }

    public function edit(ChemicalGroup $chemicalGroup)
    {
        return Inertia::render('ChemicalGroups/Edit', [
            'chemicalGroup' => $chemicalGroup,
        ]);
    }

    public function update(Request $request, ChemicalGroup $chemicalGroup)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:chemical_groups,name,' . $chemicalGroup->id,
        ]);

        $chemicalGroup->update([
            'name' => $request->name,
        ]);

        return redirect()->route('chemical-groups.index')
            ->with('success', 'Grupo químico atualizado com sucesso!');
    }

    public function destroy(ChemicalGroup $chemicalGroup)
    {
        // Verificar se há produtos usando este grupo químico
        $productsCount = $chemicalGroup->products()->count();

        if ($productsCount > 0) {
            return redirect()->back()
                ->with('error', "Não é possível excluir o grupo químico '{$chemicalGroup->name}' pois existem {$productsCount} produto(s) vinculado(s) a ele.");
        }

        $chemicalGroup->delete();

        return redirect()->route('chemical-groups.index')
            ->with('success', 'Grupo químico excluído com sucesso!');
    }
}
