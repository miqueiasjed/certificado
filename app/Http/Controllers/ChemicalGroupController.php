<?php

namespace App\Http\Controllers;

use App\Models\ChemicalGroup;
use Illuminate\Http\Request;

class ChemicalGroupController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:chemical_groups,name',
        ]);

        $chemicalGroup = ChemicalGroup::create([
            'name' => $request->name,
        ]);

        return back()->with('success', 'Grupo químico criado com sucesso!');
    }
}
