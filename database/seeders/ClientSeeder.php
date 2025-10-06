<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Address;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        // Criar clientes básicos
        $clients = [
            [
                'name' => 'João Silva',
                'email' => 'joao.silva@email.com',
                'phone' => '(11) 99999-9999',
                'cnpj' => '12.345.678/0001-90',
            ],
            [
                'name' => 'Maria Santos',
                'email' => 'maria.santos@email.com',
                'phone' => '(11) 88888-8888',
                'cnpj' => '98.765.432/0001-10',
            ],
            [
                'name' => 'Pedro Oliveira',
                'email' => 'pedro.oliveira@email.com',
                'phone' => '(11) 77777-7777',
                'cnpj' => '45.678.912/0001-34',
            ]
        ];

        foreach ($clients as $clientData) {
            $client = Client::create($clientData);

            // Criar endereços para cada cliente
            Address::create([
                'client_id' => $client->id,
                'nickname' => 'Casa',
                'street' => 'Rua das Flores',
                'number' => '123',
                'district' => 'Centro',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zip' => '01234-567',
                'reference' => 'Próximo ao shopping',
                'active' => true,
            ]);

            Address::create([
                'client_id' => $client->id,
                'nickname' => 'Trabalho',
                'street' => 'Av. Paulista',
                'number' => '456',
                'district' => 'Bela Vista',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zip' => '01310-100',
                'reference' => null,
                'active' => true,
            ]);
        }

        $this->command->info('Clientes e endereços criados com sucesso!');
    }
}
