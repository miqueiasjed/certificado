<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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

    public function findClientWithAddresses(int $id): ?Client
    {
        return Client::with('addresses')->find($id);
    }

    public function createClient(array $data): Client
    {
        Log::info('ClientService::createClient - Dados recebidos:', $data);

        return DB::transaction(function () use ($data) {
            try {
                $client = Client::create($data);

                Log::info('ClientService::createClient - Cliente criado com sucesso:', [
                    'id' => $client->id,
                    'name' => $client->name,
                    'email' => $client->email
                ]);

                // Verificar se o cliente foi realmente salvo
                $savedClient = Client::find($client->id);
                if (!$savedClient) {
                    throw new \Exception('Cliente não foi encontrado após criação');
                }

                Log::info('ClientService::createClient - Cliente confirmado no banco:', [
                    'id' => $savedClient->id,
                    'name' => $savedClient->name
                ]);

                return $client;
            } catch (\Exception $e) {
                Log::error('ClientService::createClient - Erro ao criar cliente:', [
                    'error' => $e->getMessage(),
                    'data' => $data
                ]);
                throw $e;
            }
        });
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
                  ->orWhere('cnpj', 'like', "%{$search}%");
        })->paginate(15);
    }
}
