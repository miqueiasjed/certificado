<?php

namespace App\Http\Controllers;

use App\Models\Antidote;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AntidoteController extends Controller
{
    public function index(Request $request)
    {
        $query = Antidote::query();

        // Filtros
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $antidotes = $query->withCount('products')->orderBy('name')->paginate(15);

        // Estatísticas
        $stats = [
            'total' => Antidote::count(),
        ];

        return Inertia::render('Antidotes/Index', [
            'antidotes' => $antidotes,
            'stats' => $stats,
            'filters' => $request->only(['search']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Antidotes/Create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:antidotes,name',
        ]);

        $antidote = Antidote::create([
            'name' => $request->name,
        ]);

        return redirect()->route('antidotes.index')
            ->with('success', 'Antídoto criado com sucesso!');
    }

    public function show(Antidote $antidote)
    {
        return Inertia::render('Antidotes/Show', [
            'antidote' => $antidote,
        ]);
    }

    public function edit(Antidote $antidote)
    {
        return Inertia::render('Antidotes/Edit', [
            'antidote' => $antidote,
        ]);
    }

    public function update(Request $request, Antidote $antidote)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:antidotes,name,' . $antidote->id,
        ]);

        $antidote->update([
            'name' => $request->name,
        ]);

        return redirect()->route('antidotes.index')
            ->with('success', 'Antídoto atualizado com sucesso!');
    }

    public function destroy(Antidote $antidote)
    {
        // Verificar se há produtos usando este antídoto
        $productsCount = $antidote->products()->count();

        if ($productsCount > 0) {
            return redirect()->back()
                ->with('error', "Não é possível excluir o antídoto '{$antidote->name}' pois existem {$productsCount} produto(s) vinculado(s) a ele.");
        }

        $antidote->delete();

        return redirect()->route('antidotes.index')
            ->with('success', 'Antídoto excluído com sucesso!');
    }
}
