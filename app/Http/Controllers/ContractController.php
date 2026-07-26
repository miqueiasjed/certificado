<?php

namespace App\Http\Controllers;

use App\Http\Requests\ContractEncerrarRequest;
use App\Http\Requests\ContractRequest;
use App\Services\ContractService;
use App\Services\GeracaoDeVisitasService;
use App\Models\Address;
use App\Models\Contract;
use Barryvdh\DomPDF\Facade\Pdf as FacadePdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ContractController extends Controller
{
    protected $contractService;

    public function __construct(
        ContractService $contractService,
        private readonly GeracaoDeVisitasService $geracaoDeVisitas,
    ) {
        $this->contractService = $contractService;
    }

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

        // Quantas visitas futuras cada contrato perderia se fosse encerrado
        // agora: o botão "Encerrar" precisa avisar isso antes da confirmação.
        $contracts->getCollection()->transform(function (Contract $contract) {
            $contract->visitas_futuras_count = $this->contractService->contarVisitasFuturas($contract);

            return $contract;
        });

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
    public function store(ContractRequest $request, Address $address = null)
    {
        $validated = $request->validated();

        // Se veio pela rota com endereço, usar esse, senão usar do request
        $address = $address ?: Address::findOrFail($validated['address_id']);

        // address_id é só para localizar o endereço: não é campo do contrato
        unset($validated['address_id']);

        $this->contractService->criar($address, $validated);

        return redirect()->route('contracts.index')
            ->with('success', 'Contrato criado com sucesso!');
    }

    /**
     * Exibir contrato, com as visitas previstas do calendário (Task 9.7).
     */
    public function show(Contract $contract)
    {
        $contract->load('address.client');

        return Inertia::render('Contracts/Show', [
            'contract' => $contract,
            'address' => $contract->address,
            'visitas' => $this->geracaoDeVisitas->visitasComSituacao($contract),
            'visitasFuturasCount' => $this->contractService->contarVisitasFuturas($contract),
        ]);
    }

    /**
     * Gerar PDF do contrato para um endereço
     */
    public function generatePDF(Address $address)
    {
        try {
            // Carregar o contrato do endereço
            $address->load('contract');

            // Verificar se existe contrato, se não, redirecionar para criar
            if (!$address->contract) {
                return redirect()->route('addresses.contracts.create', $address)
                    ->with('error', 'É necessário criar o contrato antes de gerar o PDF.');
            }

            // Usar service para preparar dados
            $data = $this->contractService->preparePdfData($address->contract);

            // Adicionar dados extras necessários para o PDF que não estão no contrato
            $data['address'] = $address;
            $data['client'] = $address->client;

            // Gerar PDF
            $pdf = FacadePdf::loadView('pdf.contract', $data)
                ->setPaper('A4', 'portrait');

            return $pdf->stream('contrato-' . $address->id . '-' . now()->format('Y-m-d') . '.pdf');

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
    public function update(ContractRequest $request, Contract $contract)
    {
        $validated = $request->validated();

        try {
            $resultado = $this->contractService->atualizar($contract, $validated);
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar contrato: ' . $e->getMessage(), ['contract_id' => $contract->id]);

            return redirect()->back()->with('error', 'Erro ao atualizar contrato: ' . $e->getMessage());
        }

        return redirect()->route('contracts.index')
            ->with('success', $resultado['message']);
    }

    /**
     * Encerrar contrato: cancela as visitas futuras não executadas e fecha a
     * vigência (grava `end_date`), sem excluir o registro nem tocar nas
     * visitas já executadas.
     *
     * É o caminho que `ContractService::excluir()` indica quando recusa
     * apagar um contrato com histórico de visita executada.
     */
    public function encerrar(ContractEncerrarRequest $request, Contract $contract)
    {
        $resultado = $this->contractService->encerrar($contract, $request->validated('motivo'));

        if (!$resultado['success']) {
            return redirect()->back()->with('error', $resultado['message']);
        }

        return redirect()->back()->with('success', $resultado['message']);
    }

    /**
     * Excluir contrato
     */
    public function destroy(Contract $contract)
    {
        $resultado = $this->contractService->excluir($contract);

        if (!$resultado['success']) {
            return redirect()->back()->with('error', $resultado['message']);
        }

        return redirect()->route('contracts.index')
            ->with('success', $resultado['message']);
    }
}
