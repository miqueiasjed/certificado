<?php

namespace App\Http\Controllers;

use App\Http\Requests\CertificateRequest;
use App\Models\Certificate;
use App\Models\Client;
use App\Models\WorkOrder;
use App\Models\Product;
use App\Models\Service;
use App\Models\Technician;
use App\Services\CertificateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Barryvdh\DomPDF\Facade\Pdf as FacadePdf;

class CertificateController extends Controller
{
    protected $certificateService;

    public function __construct(CertificateService $certificateService)
    {
        $this->certificateService = $certificateService;
    }

    public function index(Request $request)
    {
        $search = $request->get('search', '');

        if ($search) {
            $certificates = $this->certificateService->searchCertificates($search);
        } else {
            $certificates = $this->certificateService->getCertificatesPaginated();
        }

        return Inertia::render('Certificates/Index', [
            'certificates' => $certificates,
            'search' => $search,
        ]);
    }

    public function create()
    {
        $clients = Client::orderBy('name')->limit(500)->get();
        $products = Product::orderBy('name')->limit(500)->get();
        $services = Service::where('is_active', true)->orderBy('name')->limit(500)->get();
        $activeIngredients = \App\Models\ActiveIngredient::orderBy('name')->limit(200)->get();
        $chemicalGroups = \App\Models\ChemicalGroup::orderBy('name')->limit(200)->get();
        $antidotes = \App\Models\Antidote::orderBy('name')->limit(200)->get();
        $organRegistrations = \App\Models\OrganRegistration::orderBy('record')->limit(200)->get();

        return Inertia::render('Certificates/Create', [
            'clients' => $clients,
            'products' => $products,
            'services' => $services,
            'activeIngredients' => $activeIngredients,
            'chemicalGroups' => $chemicalGroups,
            'antidotes' => $antidotes,
            'organRegistrations' => $organRegistrations,
        ]);
    }

    public function store(CertificateRequest $request)
    {
        $data = $request->validated();

        $certificate = $this->certificateService->createCertificate($data);

        return redirect()->route('certificates.index')
            ->with('success', 'Certificado criado com sucesso!');
    }

    // Criar certificado diretamente a partir de uma OS
    public function storeFromWorkOrder(Request $request, WorkOrder $workOrder)
    {
        $data = $request->validate([
            'execution_date' => 'required|date',
            'warranty' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'procedure_used' => 'required|string|max:2000',
        ]);

        $payload = array_merge($data, [
            'client_id' => $workOrder->client_id,
            'address_id' => $workOrder->address_id,
            'work_order_id' => $workOrder->id,
            'status' => 'active',
        ]);

        $certificate = $this->certificateService->createCertificate($payload);

        return redirect()->route('certificates.show', $certificate)
            ->with('success', 'Certificado emitido a partir da OS com sucesso!');
    }

    public function show(Certificate $certificate)
    {
        $certificate->load([
            'client',
            'workOrder.address.client',
            'products' => function($query) {
                $query->withPivot(['quantity', 'unit']);
            },
            'products.activeIngredient',
            'products.chemicalGroup',
            'products.antidote',
            'products.organRegistration',
            'service'
        ]);

        return Inertia::render('Certificates/Show', [
            'certificate' => $certificate,
        ]);
    }

    public function edit(Certificate $certificate)
    {
        // Carregar relacionamentos do certificado
        $certificate->load([
            'client',
            'products' => function($query) {
                $query->withPivot(['quantity', 'unit']);
            },
            'service'
        ]);

        $clients = Client::orderBy('name')->limit(500)->get();
        $products = Product::orderBy('name')->limit(500)->get();
        $services = Service::where('is_active', true)->orderBy('name')->limit(500)->get();

        // Carregar endereços do cliente para certificados avulsos
        $addresses = $certificate->client ? $certificate->client->addresses()->orderBy('nickname')->get() : collect();

        // Otimizar: carregar apenas work orders do cliente do certificado ou limitar a quantidade
        $workOrders = WorkOrder::with('client')
            ->where('client_id', $certificate->client_id)
            ->orderBy('order_number')
            ->limit(100)
            ->get();

        return Inertia::render('Certificates/Edit', [
            'certificate' => $certificate,
            'clients' => $clients,
            'addresses' => $addresses,
            'products' => $products,
            'services' => $services,
            'workOrders' => $workOrders,
        ]);
    }

    public function update(CertificateRequest $request, Certificate $certificate)
    {
        try {
            Log::info('Atualizando certificado', [
                'certificate_id' => $certificate->id,
                'data' => $request->validated()
            ]);

            $this->certificateService->updateCertificate($certificate, $request->validated());

            Log::info('Certificado atualizado com sucesso', ['certificate_id' => $certificate->id]);

            return redirect()->route('certificates.show', $certificate)
                ->with('success', 'Certificado atualizado com sucesso!');
        } catch (\Exception $e) {
            Log::error('Erro ao atualizar certificado', [
                'certificate_id' => $certificate->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return back()->withErrors(['error' => 'Erro ao atualizar certificado: ' . $e->getMessage()]);
        }
    }

    public function destroy(Certificate $certificate)
    {
        $this->certificateService->deleteCertificate($certificate);

        return redirect()->route('certificates.index')
            ->with('success', 'Certificado excluído com sucesso!');
    }

    public function exportPdf(Certificate $certificate)
    {
        // Carregar as relações necessárias
        $certificate->load([
            'client',
            'address', // Endereço do certificado (para certificados avulsos)
            'workOrder.address.client', // Endereço da OS (para certificados gerados por OS)
            'products.activeIngredient',
            'products.chemicalGroup',
            'products.antidote',
            'products.organRegistration',
            'service'
        ]);

        // Gerar o PDF com os dados fornecidos
        $pdf = FacadePdf::loadView('pdf.certificate', [
            'certificate' => $certificate,
        ])->setPaper('a4', 'landscape'); // Definindo o tamanho do papel e o modo paisagem

        // Retornar o PDF para download
        return $pdf->stream('certificate-' . $certificate->id . '.pdf');
    }
}
