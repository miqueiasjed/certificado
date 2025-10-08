<?php

namespace App\Http\Controllers;

use App\Http\Requests\ServiceRequest;
use App\Models\Service;
use App\Services\ServiceService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ServiceController extends Controller
{
    protected $serviceService;

    public function __construct(ServiceService $serviceService)
    {
        $this->serviceService = $serviceService;
    }

    public function index(Request $request)
    {
        $search = $request->get('search', '');

        if ($search) {
            $services = $this->serviceService->searchServices($search);
        } else {
            $services = $this->serviceService->getServicesPaginated();
        }

        return Inertia::render('Services/Index', [
            'services' => $services,
            'search' => $search,
        ]);
    }

    public function create()
    {
        return Inertia::render('Services/Create');
    }

    public function store(ServiceRequest $request)
    {
        $service = $this->serviceService->createService($request->validated());

        // Se for uma requisição do modal (identificada por um parâmetro especial), retorna JSON
        if ($request->has('_from_modal') || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Serviço criado com sucesso!',
                'service' => $service
            ]);
        }

        // Caso contrário, redireciona normalmente
        return redirect()->route('services.index')
            ->with('success', 'Serviço criado com sucesso!');
    }

    public function show(Service $service)
    {
        return Inertia::render('Services/Show', [
            'service' => $service,
        ]);
    }

    public function edit(Service $service)
    {
        return Inertia::render('Services/Edit', [
            'service' => $service,
        ]);
    }

    public function update(ServiceRequest $request, Service $service)
    {
        $this->serviceService->updateService($service, $request->validated());

        return redirect()->route('services.show', $service)
            ->with('success', 'Serviço atualizado com sucesso!');
    }

    public function destroy(Service $service)
    {
        $this->serviceService->deleteService($service);

        return redirect()->route('services.index')
            ->with('success', 'Serviço excluído com sucesso!');
    }
}
