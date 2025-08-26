<?php

namespace App\Http\Controllers;

use App\Models\Antidote;
use Illuminate\Http\Request;

class AntidoteController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255|unique:antidotes,name',
        ]);

        $antidote = Antidote::create([
            'name' => $request->name,
        ]);

        return back()->with('success', 'Antídoto criado com sucesso!');
    }
}
