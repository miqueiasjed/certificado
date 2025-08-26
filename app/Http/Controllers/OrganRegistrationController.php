<?php

namespace App\Http\Controllers;

use App\Models\OrganRegistration;
use Illuminate\Http\Request;

class OrganRegistrationController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'record' => 'required|string|max:255|unique:organ_registrations,record',
        ]);

        $organRegistration = OrganRegistration::create([
            'record' => $request->record,
        ]);

        return back()->with('success', 'Registro ministerial criado com sucesso!');
    }
}
