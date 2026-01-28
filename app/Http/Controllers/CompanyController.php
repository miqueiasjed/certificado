<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Company;
use Inertia\Inertia;
use Illuminate\Support\Facades\Storage;

class CompanyController extends Controller
{
    /**
     * Show the company settings form.
     */
    public function edit()
    {
        return Inertia::render('Settings/Company', [
            'company' => Company::current(),
        ]);
    }

    /**
     * Update the company settings.
     */
    public function update(Request $request)
    {
        $company = Company::current();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'cnpj' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'street' => 'nullable|string|max:255',
            'number' => 'nullable|string|max:20',
            'complement' => 'nullable|string|max:255',
            'district' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:2',
            'zip' => 'nullable|string|max:10',
            'crq' => 'nullable|string|max:50',
            'license_environmental' => 'nullable|string|max:50',
            'license_sanitary' => 'nullable|string|max:50',
            'ceatox_info' => 'nullable|string|max:500',
            'logo_path' => 'nullable|image|max:2048', // 2MB Max
            'signature_operational_path' => 'nullable|image|max:2048',
            'signature_chemical_path' => 'nullable|image|max:2048',
        ]);

        // Handle File Uploads
        if ($request->hasFile('logo_path')) {
            if ($company->logo_path)
                Storage::disk('public')->delete($company->logo_path);
            $validated['logo_path'] = $request->file('logo_path')->store('company', 'public');
        }

        if ($request->hasFile('signature_operational_path')) {
            if ($company->signature_operational_path)
                Storage::disk('public')->delete($company->signature_operational_path);
            $validated['signature_operational_path'] = $request->file('signature_operational_path')->store('company', 'public');
        }

        if ($request->hasFile('signature_chemical_path')) {
            if ($company->signature_chemical_path)
                Storage::disk('public')->delete($company->signature_chemical_path);
            $validated['signature_chemical_path'] = $request->file('signature_chemical_path')->store('company', 'public');
        }

        $company->update($validated);

        return redirect()->back()->with('success', 'Configurações atualizadas com sucesso!');
    }
}
