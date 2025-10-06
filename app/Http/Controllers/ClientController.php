<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientRequest;
use App\Services\ClientService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Log;

class ClientController extends Controller
{
    public function __construct(
        private ClientService $clientService
    ) {}

    public function index(Request $request): Response
    {
        $search = $request->get('search', '');

        if (!empty($search) && strlen($search) >= 2) {
            $clients = $this->clientService->searchClients($search);
        } else {
            $clients = $this->clientService->getClientsPaginated();
        }

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
            'search' => $search,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Clients/Create');
    }

    public function store(ClientRequest $request)
    {
        Log::info('ClientController::store - Iniciando criação de cliente');

        try {
            $validatedData = $request->validated();
            Log::info('ClientController::store - Dados validados:', $validatedData);

            $client = $this->clientService->createClient($validatedData);

            Log::info('ClientController::store - Cliente criado com sucesso:', [
                'id' => $client->id,
                'name' => $client->name
            ]);

            return redirect()->route('clients.index')
                ->with('success', 'Cliente criado com sucesso!');
        } catch (\Exception $e) {
            Log::error('ClientController::store - Erro ao criar cliente:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return back()->withInput()
                ->with('error', 'Erro ao criar cliente: ' . $e->getMessage());
        }
    }

    public function show(int $id): Response
    {
        $client = $this->clientService->findClientWithAddresses($id);

        if (!$client) {
            abort(404);
        }

        return Inertia::render('Clients/Show', [
            'client' => $client,
        ]);
    }

    public function edit(int $id): Response
    {
        $client = $this->clientService->findClient($id);

        if (!$client) {
            abort(404);
        }

        return Inertia::render('Clients/Edit', [
            'client' => $client,
        ]);
    }

    public function update(ClientRequest $request, int $id)
    {
        try {
            $client = $this->clientService->findClient($id);

            if (!$client) {
                return back()->with('error', 'Cliente não encontrado');
            }

            $this->clientService->updateClient($client, $request->validated());

            return redirect()->route('clients.index')
                ->with('success', 'Cliente atualizado com sucesso!');
        } catch (\Exception $e) {
            return back()->withInput()
                ->with('error', 'Erro ao atualizar cliente: ' . $e->getMessage());
        }
    }

    public function destroy(int $id)
    {
        try {
            $client = $this->clientService->findClient($id);

            if (!$client) {
                return back()->with('error', 'Cliente não encontrado');
            }

            $this->clientService->deleteClient($client);

            return redirect()->route('clients.index')
                ->with('success', 'Cliente excluído com sucesso!');
        } catch (\Exception $e) {
            return back()->with('error', 'Erro ao excluir cliente: ' . $e->getMessage());
        }
    }
}
