<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\Contract;
use Barryvdh\DomPDF\Facade\Pdf as FacadePdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ContractController extends Controller
{
    /**
     * Listar todos os contratos
     */
    public function index(Request $request)
    {
        $search = $request->get('search', '');

        $query = Contract::with(['address.client'])
            ->orderBy('created_at', 'desc');

        // Buscar por nome do cliente
        if ($search) {
            $query->whereHas('address.client', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        $contracts = $query->paginate(15);

        return Inertia::render('Contracts/Index', [
            'contracts' => $contracts,
            'search' => $search,
        ]);
    }

    /**
     * Mostrar formulário para criar/editar contrato
     */
    public function create(Request $request, Address $address = null)
    {
        $addresses = \App\Models\Address::with('client')
            ->orderBy('nickname')
            ->get()
            ->map(function ($addr) {
                return [
                    'id' => $addr->id,
                    'nickname' => $addr->nickname,
                    'street' => $addr->street,
                    'number' => $addr->number,
                    'city' => $addr->city,
                    'state' => $addr->state,
                    'client' => [
                        'id' => $addr->client->id,
                        'name' => $addr->client->name,
                    ],
                ];
            });

        return Inertia::render('Contracts/Create', [
            'address' => $address ? $address->load('client') : null,
            'addresses' => $addresses,
        ]);
    }

    /**
     * Criar novo contrato
     */
    public function store(Request $request, Address $address = null)
    {
        // Regras de validação
        $rules = [
            'contract_number' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'service_value' => 'nullable|numeric|min:0',
            'service_type' => 'required|in:pontual,periodico',
            'visit_frequency' => 'required|in:weekly,biweekly,monthly',
            'visit_count' => 'required|integer|min:1',
            'pest_target' => 'nullable|string',
            'payment_method' => 'nullable|string',
            'payment_details' => 'nullable|string',
            'additional_clause' => 'nullable|string',
            'jurisdiction' => 'nullable|string',
        ];

        // Se não veio pela rota com endereço, exigir address_id
        if (!$address) {
            $rules['address_id'] = 'required|exists:addresses,id';
        }

        $validated = $request->validate($rules);

        // Se veio pela rota com endereço, usar esse, senão usar do request
        $addressId = $address ? $address->id : $validated['address_id'];
        $address = $address ?: Address::findOrFail($addressId);

        // Gerar número do contrato se não fornecido
        if (empty($validated['contract_number'])) {
            $validated['contract_number'] = 'CONT-' . str_pad($address->id, 6, '0', STR_PAD_LEFT) . '-' . date('Ymd');
        }

        // Remover address_id do validated antes de criar
        unset($validated['address_id']);

        // Criar ou atualizar contrato
        $contract = $address->contract()->updateOrCreate(
            ['address_id' => $address->id],
            $validated
        );

        return redirect()->route('contracts.index')
            ->with('success', 'Contrato criado com sucesso!');
    }

    /**
     * Gerar PDF do contrato para um endereço
     */
    public function generatePDF(Address $address)
    {
        try {
            // Carregar as relações necessárias
            $address->load(['client', 'contract']);

            // Verificar se existe contrato, se não, redirecionar para criar
            if (!$address->contract) {
                return redirect()->route('addresses.contracts.create', $address)
                    ->with('error', 'É necessário criar o contrato antes de gerar o PDF.');
            }

            $contract = $address->contract;

            $company = \App\Models\Company::current();

            // Preparar dados para o PDF
            $data = [
                'address' => $address,
                'client' => $address->client,
                'contract' => $contract,
                'company' => $company,
                'currentDate' => now()->format('d/m/Y'),
                'currentTime' => now()->format('H:i'),
            ];

            // Gerar PDF
            $pdf = FacadePdf::loadView('pdf.contract', $data);
            $pdf->setPaper('A4', 'portrait');

            $filename = 'contrato-' . $address->id . '-' . now()->format('Y-m-d') . '.pdf';

            // Retornar o PDF para stream (igual ao WorkOrderController)
            return $pdf->stream($filename);
        } catch (\Exception $e) {
            Log::error('Erro ao gerar PDF do contrato: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Erro ao gerar contrato: ' . $e->getMessage());
        }
    }

    /**
     * Editar contrato
     */
    public function edit(Contract $contract)
    {
        $contract->load('address.client');

        // Garantir que as datas estejam no formato YYYY-MM-DD para o input type="date"
        $contract->start_date = $contract->start_date ? $contract->start_date->format('Y-m-d') : null;
        $contract->end_date = $contract->end_date ? $contract->end_date->format('Y-m-d') : null;

        return Inertia::render('Contracts/Edit', [
            'contract' => $contract,
            'address' => $contract->address,
        ]);
    }

    /**
     * Atualizar contrato
     */
    public function update(Request $request, Contract $contract)
    {
        $validated = $request->validate([
            'contract_number' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'service_value' => 'nullable|numeric|min:0',
            'service_type' => 'required|in:pontual,periodico',
            'visit_frequency' => 'required|in:weekly,biweekly,monthly',
            'visit_count' => 'required|integer|min:1',
            'pest_target' => 'nullable|string',
            'payment_method' => 'nullable|string',
            'payment_details' => 'nullable|string',
            'additional_clause' => 'nullable|string',
            'jurisdiction' => 'nullable|string',
        ]);

        $contract->update($validated);

        return redirect()->route('contracts.index')
            ->with('success', 'Contrato atualizado com sucesso!');
    }

    /**
     * Excluir contrato
     */
    public function destroy(Contract $contract)
    {
        $contract->delete();

        return redirect()->route('contracts.index')
            ->with('success', 'Contrato excluído com sucesso!');
    }
}
