<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddressRequest;
use App\Models\Address;
use App\Services\AddressService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AddressController extends Controller
{
    protected $addressService;

    public function __construct(AddressService $addressService)
    {
        $this->addressService = $addressService;
    }

    public function index(Request $request)
    {
        $search = $request->get('search', '');

        if ($search) {
            $addresses = $this->addressService->searchAddresses($search);
        } else {
            $addresses = $this->addressService->getAddressesPaginated();
        }

        return Inertia::render('Addresses/Index', [
            'addresses' => $addresses,
            'search' => $search,
        ]);
    }

    public function create()
    {
        $clients = \App\Models\Client::orderBy('name')->limit(500)->get();

        return Inertia::render('Addresses/Create', [
            'clients' => $clients,
        ]);
    }

    public function store(AddressRequest $request)
    {
        try {
            $address = $this->addressService->createAddress($request->validated());

            return redirect()->route('addresses.index')
                ->with('success', 'Endereço criado com sucesso!');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withErrors(['error' => 'Erro ao criar endereço: ' . $e->getMessage()])
                ->withInput();
        }
    }

    public function show(Address $address)
    {
        $address->load(['client', 'rooms', 'devices.baitType', 'workOrders']);
        
        $baitTypes = \App\Models\BaitType::orderBy('name')->limit(200)->get();

        return Inertia::render('Addresses/Show', [
            'address' => $address,
            'baitTypes' => $baitTypes,
        ]);
    }

    public function edit(Address $address)
    {
        $clients = \App\Models\Client::orderBy('name')->limit(500)->get();

        return Inertia::render('Addresses/Edit', [
            'address' => $address,
            'clients' => $clients,
        ]);
    }

    public function update(AddressRequest $request, Address $address)
    {
        $this->addressService->updateAddress($address, $request->validated());

        return redirect()->route('addresses.show', $address)
            ->with('success', 'Endereço atualizado com sucesso!');
    }

    public function destroy(Address $address)
    {
        $this->addressService->deleteAddress($address);

        return redirect()->route('addresses.index')
            ->with('success', 'Endereço excluído com sucesso!');
    }

    public function getByClient(Request $request, $clientId)
    {
        $addresses = $this->addressService->getAddressesByClient($clientId);

        return response()->json(['addresses' => $addresses]);
    }

    public function getByCity(Request $request, $city)
    {
        $addresses = $this->addressService->getAddressesByCity($city);

        return response()->json($addresses);
    }

    public function getByState(Request $request, $state)
    {
        $addresses = $this->addressService->getAddressesByState($state);

        return response()->json($addresses);
    }

    /**
     * Get devices for an address
     */
    public function getDevices(Address $address)
    {
        $devices = $address->devices()->with(['baitType'])->get();

        return response()->json(['devices' => $devices]);
    }

    /**
     * Store a new device for an address
     */
    public function storeDevice(Request $request, Address $address)
    {
        $request->validate([
            'label' => 'required|string|max:255',
            'number' => 'required|string|max:255',
            'bait_type_id' => 'nullable|exists:bait_types,id',
            'default_location_note' => 'nullable|string',
            'active' => 'boolean',
        ]);

        $device = $address->devices()->create([
            'label' => $request->label,
            'number' => $request->number,
            'bait_type_id' => $request->bait_type_id,
            'default_location_note' => $request->default_location_note,
            'active' => $request->boolean('active', true),
        ]);

        $device->load('baitType');

        return response()->json([
            'success' => true,
            'device' => $device,
            'message' => 'Dispositivo criado com sucesso!'
        ]);
    }

    /**
     * Update a device for an address
     */
    public function updateDevice(Request $request, Address $address, \App\Models\Device $device)
    {
        // Verificar se o dispositivo pertence ao endereço
        if ($device->address_id !== $address->id) {
            return response()->json([
                'success' => false,
                'message' => 'Dispositivo não pertence a este endereço'
            ], 403);
        }

        $request->validate([
            'label' => 'required|string|max:255',
            'number' => 'required|string|max:255',
            'bait_type_id' => 'nullable|exists:bait_types,id',
            'default_location_note' => 'nullable|string',
            'active' => 'boolean',
        ]);

        $device->update([
            'label' => $request->label,
            'number' => $request->number,
            'bait_type_id' => $request->bait_type_id,
            'default_location_note' => $request->default_location_note,
            'active' => $request->boolean('active', true),
        ]);

        $device->load('baitType');

        return response()->json([
            'success' => true,
            'device' => $device,
            'message' => 'Dispositivo atualizado com sucesso!'
        ]);
    }

    /**
     * Delete a device from an address
     */
    public function deleteDevice(Address $address, \App\Models\Device $device)
    {
        // Verificar se o dispositivo pertence ao endereço
        if ($device->address_id !== $address->id) {
            return response()->json([
                'success' => false,
                'message' => 'Dispositivo não pertence a este endereço'
            ], 403);
        }

        try {
            // Verificar se pode deletar (não está vinculado a eventos de OS)
            $hasWorkOrderEvents = $device->workOrderEvents()->exists();
            
            if ($hasWorkOrderEvents) {
                return response()->json([
                    'success' => false,
                    'message' => 'Dispositivo não pode ser excluído pois está vinculado a eventos de ordens de serviço'
                ], 422);
            }

            $device->delete();

            return response()->json([
                'success' => true,
                'message' => 'Dispositivo excluído com sucesso!'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao excluir dispositivo: ' . $e->getMessage()
            ], 500);
        }
    }
}
