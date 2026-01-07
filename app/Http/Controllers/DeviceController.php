<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeviceRequest;
use App\Models\Device;
use App\Services\DeviceService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeviceController extends Controller
{
    protected $deviceService;

    public function __construct(DeviceService $deviceService)
    {
        $this->deviceService = $deviceService;
    }

    public function index(Request $request)
    {
        $query = Device::with(['address.client', 'baitType']);

        // Aplicar filtros
        if ($request->filled('client_id')) {
            $query->whereHas('address', function ($q) use ($request) {
                $q->where('client_id', $request->client_id);
            });
        }

        if ($request->filled('address_id')) {
            $query->where('address_id', $request->address_id);
        }

        if ($request->filled('bait_type_id')) {
            $query->where('bait_type_id', $request->bait_type_id);
        }

        $devices = $query->paginate(15);

        // Carregar dados para filtros
        $clients = \App\Models\Client::orderBy('name')->limit(500)->get();
        $addresses = \App\Models\Address::with('client')->orderBy('nickname')->limit(500)->get();
        $baitTypes = \App\Models\BaitType::orderBy('name')->limit(200)->get();

        return Inertia::render('Devices/Index', [
            'devices' => $devices,
            'filters' => $request->only(['client_id', 'address_id', 'bait_type_id']),
            'clients' => $clients,
            'addresses' => $addresses,
            'baitTypes' => $baitTypes,
        ]);
    }

    public function create()
    {
        $addresses = \App\Models\Address::with('client')->orderBy('nickname')->limit(500)->get();
        $baitTypes = \App\Models\BaitType::orderBy('name')->limit(200)->get();

        return Inertia::render('Devices/Create', [
            'addresses' => $addresses,
            'baitTypes' => $baitTypes,
        ]);
    }

    public function store(DeviceRequest $request)
    {
        $device = $this->deviceService->createDevice($request->validated());

        // Retornar mensagem de sucesso sem redirecionar
        return back()->with('success', 'Dispositivo criado com sucesso!');
    }

    public function show(Device $device)
    {
        $device->load(['address.client', 'baitType']);

        return Inertia::render('Devices/Show', [
            'device' => $device,
        ]);
    }

    public function edit(Device $device, Request $request)
    {
        // Carregar o dispositivo com todos os relacionamentos necessários
        $device->load(['address.client', 'baitType']);

        // Carregar endereços para o formulário de edição
        $addresses = \App\Models\Address::with('client')->orderBy('nickname')->limit(500)->get();

        // Carregar tipos de isca para o formulário de edição
        $baitTypes = \App\Models\BaitType::orderBy('name')->limit(200)->get();

        return Inertia::render('Devices/Edit', [
            'device' => $device,
            'addresses' => $addresses,
            'baitTypes' => $baitTypes,
            'returnUrl' => $request->get('return_url'), // URL de retorno
        ]);
    }

    public function update(DeviceRequest $request, Device $device)
    {
        $this->deviceService->updateDevice($device, $request->validated());

        // Verificar se há uma URL de retorno específica
        $returnUrl = $request->get('return_url');
        if ($returnUrl) {
            return redirect($returnUrl)->with('success', 'Dispositivo atualizado com sucesso!');
        }

        return back()->with('success', 'Dispositivo atualizado com sucesso!');
    }

    public function destroy(Device $device)
    {
        try {
            $this->deviceService->deleteDevice($device);

            return back()->with('success', 'Dispositivo excluído com sucesso!');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Check if device can be deleted.
     */
    public function canDelete(Device $device)
    {
        $canDelete = $this->deviceService->canDeleteDevice($device);

        return response()->json([
            'can_delete' => $canDelete,
            'message' => $canDelete
                ? 'Dispositivo pode ser excluído'
                : 'Dispositivo não pode ser excluído pois está vinculado a ordens de serviço ou eventos'
        ]);
    }

    public function getByAddress($addressId)
    {
        $devices = Device::where('address_id', $addressId)
            ->with(['address.client', 'baitType'])
            ->get();

        return response()->json($devices);
    }
}
