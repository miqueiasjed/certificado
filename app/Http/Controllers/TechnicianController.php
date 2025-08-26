<?php

namespace App\Http\Controllers;

use App\Http\Requests\TechnicianRequest;
use App\Models\Technician;
use App\Services\TechnicianService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TechnicianController extends Controller
{
    protected $technicianService;

    public function __construct(TechnicianService $technicianService)
    {
        $this->technicianService = $technicianService;
    }

    public function index(Request $request)
    {
        $search = $request->get('search', '');

        if ($search) {
            $technicians = $this->technicianService->searchTechnicians($search);
        } else {
            $technicians = $this->technicianService->getTechniciansPaginated();
        }

        return Inertia::render('Technicians/Index', [
            'technicians' => $technicians,
            'search' => $search,
        ]);
    }

    public function create()
    {
        return Inertia::render('Technicians/Create');
    }

    public function store(TechnicianRequest $request)
    {
        $technician = $this->technicianService->createTechnician($request->validated());

        return redirect()->route('technicians.index')
            ->with('success', 'Técnico criado com sucesso!');
    }

    public function show(Technician $technician)
    {
        return Inertia::render('Technicians/Show', [
            'technician' => $technician,
        ]);
    }

    public function edit(Technician $technician)
    {
        return Inertia::render('Technicians/Edit', [
            'technician' => $technician,
        ]);
    }

    public function update(TechnicianRequest $request, Technician $technician)
    {
        $this->technicianService->updateTechnician($technician, $request->validated());

        return redirect()->route('technicians.show', $technician)
            ->with('success', 'Técnico atualizado com sucesso!');
    }

    public function destroy(Technician $technician)
    {
        $this->technicianService->deleteTechnician($technician);

        return redirect()->route('technicians.index')
            ->with('success', 'Técnico excluído com sucesso!');
    }
}
