<?php

namespace App\Services;

use App\Jobs\GeocodificarEnderecoJob;
use App\Models\Address;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AddressService
{
    public function getAddressesPaginated(int $perPage = 15): LengthAwarePaginator
    {
        return Address::with(['client'])
            ->withCount('rooms')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function getAddressesByClient(int $clientId): Collection
    {
        return Address::where('client_id', $clientId)
            ->where('active', true)
            ->withCount('rooms')
            ->orderBy('nickname')
            ->get();
    }

    public function searchAddresses(string $search, int $perPage = 15): LengthAwarePaginator
    {
        return Address::with(['client'])
            ->withCount('rooms')
            ->where(function ($query) use ($search) {
                $query->where('nickname', 'like', "%{$search}%")
                    ->orWhere('street', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('state', 'like', "%{$search}%")
                    ->orWhereHas('client', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%");
                    });
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function createAddress(array $data): Address
    {
        $address = Address::create($data);

        // Geocodificação em fila, nunca na requisição (Plano 22, Task 22.2):
        // o cadastro do endereço não pode esperar a resposta do provedor.
        GeocodificarEnderecoJob::dispatch($address->id);

        return $address;
    }

    public function updateAddress(Address $address, array $data): Address
    {
        $address->update($data);

        return $address->fresh();
    }

    public function deleteAddress(Address $address): bool
    {
        return $address->delete();
    }

    public function getAddressById(int $id): ?Address
    {
        return Address::with(['client', 'rooms', 'workOrders'])->find($id);
    }

    public function getAddressesByCity(string $city): Collection
    {
        return Address::where('city', 'like', "%{$city}%")
            ->where('active', true)
            ->with(['client'])
            ->withCount('rooms')
            ->get();
    }

    public function getAddressesByState(string $state): Collection
    {
        return Address::where('state', $state)
            ->where('active', true)
            ->with(['client'])
            ->withCount('rooms')
            ->get();
    }
}
