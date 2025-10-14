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

            return redirect()->back()
                ->with('success', 'Endereço criado com sucesso!');
        } catch (\Exception $e) {
            return redirect()->back()
                ->withErrors(['error' => 'Erro ao criar endereço: ' . $e->getMessage()])
                ->withInput();
        }
    }

    public function show(Address $address)
    {
        $address->load(['client', 'rooms.devices', 'workOrders']);

        return Inertia::render('Addresses/Show', [
            'address' => $address,
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
}
