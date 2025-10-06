<?php

namespace App\Console\Commands;

use App\Models\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestClientCreation extends Command
{
    protected $signature = 'test:client-creation';
    protected $description = 'Test client creation directly in database';

    public function handle()
    {
        $this->info('Testing client creation...');

        $testData = [
            'name' => 'Cliente Teste ' . now()->format('Y-m-d H:i:s'),
            'email' => 'teste' . time() . '@exemplo.com',
            'phone' => '(11) 99999-9999',
            'cnpj' => '123.456.789-00',
            'notes' => 'Cliente criado via comando de teste'
        ];

        try {
            $this->info('Creating client with data: ' . json_encode($testData));

            $client = Client::create($testData);

            $this->info('Client created successfully!');
            $this->info('ID: ' . $client->id);
            $this->info('Name: ' . $client->name);
            $this->info('Email: ' . $client->email);

            // Verificar se o cliente foi realmente salvo
            $savedClient = Client::find($client->id);
            if ($savedClient) {
                $this->info('Client found in database: ' . $savedClient->name);
            } else {
                $this->error('Client NOT found in database!');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Error creating client: ' . $e->getMessage());
            Log::error('TestClientCreation error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return Command::FAILURE;
        }
    }
}
