<?php

namespace App\Http\Controllers;

use App\Models\ActiveIngredient;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ActiveIngredientController extends Controller
{
    public function index(Request $request)
    {
        $query = ActiveIngredient::query();

        // Filtros
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $activeIngredients = $query->withCount('products')->orderBy('name')->paginate(15);

        // Estatísticas
        $stats = [
            'total' => ActiveIngredient::count(),
        ];

        return Inertia::render('ActiveIngredients/Index', [
            'activeIngredients' => $activeIngredients,
            'stats' => $stats,
            'filters' => $request->only(['search']),
        ]);
    }

    public function create()
    {
        return Inertia::render('ActiveIngredients/Create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:active_ingredients,name',
        ]);

        $activeIngredient = ActiveIngredient::create([
            'name' => $request->name,
        ]);

        return redirect()->route('active-ingredients.index')
            ->with('success', 'Princípio ativo criado com sucesso!');
    }

    public function show(ActiveIngredient $activeIngredient)
    {
        return Inertia::render('ActiveIngredients/Show', [
            'activeIngredient' => $activeIngredient,
        ]);
    }

    public function edit(ActiveIngredient $activeIngredient)
    {
        return Inertia::render('ActiveIngredients/Edit', [
            'activeIngredient' => $activeIngredient,
        ]);
    }

    public function update(Request $request, ActiveIngredient $activeIngredient)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:active_ingredients,name,' . $activeIngredient->id,
        ]);

        $activeIngredient->update([
            'name' => $request->name,
        ]);

        return redirect()->route('active-ingredients.index')
            ->with('success', 'Princípio ativo atualizado com sucesso!');
    }

    public function destroy(ActiveIngredient $activeIngredient)
    {
        // Verificar se há produtos usando este princípio ativo
        $productsCount = $activeIngredient->products()->count();

        if ($productsCount > 0) {
            return redirect()->back()
                ->with('error', "Não é possível excluir o princípio ativo '{$activeIngredient->name}' pois existem {$productsCount} produto(s) vinculado(s) a ele.");
        }

        $activeIngredient->delete();

        return redirect()->route('active-ingredients.index')
            ->with('success', 'Princípio ativo excluído com sucesso!');
    }
}
