<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ClientService
{
    public function getAllClients(): Collection
    {
        return Client::all();
    }

    public function getClientsPaginated(int $perPage = 15): LengthAwarePaginator
    {
        return Client::paginate($perPage);
    }

    public function findClient(int $id): ?Client
    {
        return Client::find($id);
    }

    public function createClient(array $data): Client
    {
        return Client::create($data);
    }

    public function updateClient(Client $client, array $data): bool
    {
        return $client->update($data);
    }

    public function deleteClient(Client $client): bool
    {
        return $client->delete();
    }

    public function searchClients(string $search): LengthAwarePaginator
    {
        return Client::where(function($query) use ($search) {
            $query->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('cnpj', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%")
                  ->orWhere('state', 'like', "%{$search}%");
        })->paginate(15);
    }
}
