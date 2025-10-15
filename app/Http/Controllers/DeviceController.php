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
        $query = Device::with(['room.address.client', 'baitType']);

        // Aplicar filtros
        if ($request->filled('client_id')) {
            $query->whereHas('room.address', function ($q) use ($request) {
                $q->where('client_id', $request->client_id);
            });
        }

        if ($request->filled('address_id')) {
            $query->whereHas('room', function ($q) use ($request) {
                $q->where('address_id', $request->address_id);
            });
        }

        if ($request->filled('room_id')) {
            $query->where('room_id', $request->room_id);
        }

        if ($request->filled('bait_type_id')) {
            $query->where('bait_type_id', $request->bait_type_id);
        }

        $devices = $query->paginate(15);

        // Carregar dados para filtros
        $clients = \App\Models\Client::orderBy('name')->limit(500)->get();
        $addresses = \App\Models\Address::with('client')->orderBy('nickname')->limit(500)->get();
        $rooms = \App\Models\Room::with('address.client')->orderBy('name')->limit(500)->get();
        $baitTypes = \App\Models\BaitType::orderBy('name')->limit(200)->get();

        return Inertia::render('Devices/Index', [
            'devices' => $devices,
            'filters' => $request->only(['client_id', 'address_id', 'room_id', 'bait_type_id']),
            'clients' => $clients,
            'addresses' => $addresses,
            'rooms' => $rooms,
            'baitTypes' => $baitTypes,
        ]);
    }

    public function create()
    {
        $rooms = \App\Models\Room::with('address.client')->orderBy('name')->limit(500)->get();
        $baitTypes = \App\Models\BaitType::orderBy('name')->limit(200)->get();

        return Inertia::render('Devices/Create', [
            'rooms' => $rooms,
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
        $device->load(['room.address.client', 'baitType']);

        return Inertia::render('Devices/Show', [
            'device' => $device,
        ]);
    }

    public function edit(Device $device, Request $request)
    {
        // Carregar o dispositivo com todos os relacionamentos necessários
        $device->load(['room.address.client', 'baitType']);

        // Carregar apenas os cômodos do endereço atual do dispositivo
        $rooms = \App\Models\Room::with('address.client')
            ->where('address_id', $device->room->address_id)
            ->orderBy('name')
            ->get();

        // Carregar tipos de isca para o formulário de edição
        $baitTypes = \App\Models\BaitType::orderBy('name')->limit(200)->get();

        return Inertia::render('Devices/Edit', [
            'device' => $device,
            'rooms' => $rooms,
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

    public function getByRoom($roomId)
    {
        $devices = Device::where('room_id', $roomId)
            ->with(['room.address.client'])
            ->get();

        return response()->json($devices);
    }
}
