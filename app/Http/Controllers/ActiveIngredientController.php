<?php

namespace App\Http\Controllers;

use App\Models\ActiveIngredient;
use Illuminate\Http\Request;

class ActiveIngredientController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:active_ingredients,name',
        ]);

        $activeIngredient = ActiveIngredient::create([
            'name' => $request->name,
        ]);

        return back()->with('success', 'Princípio ativo criado com sucesso!');
    }
}
