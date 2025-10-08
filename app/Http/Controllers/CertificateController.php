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
        // Se work_order_id vier, garantir consistência do cliente
        if (!empty($data['work_order_id'])) {
            $wo = WorkOrder::find($data['work_order_id']);
            if ($wo) {
                $data['client_id'] = $wo->client_id;
            }
        }

        $certificate = $this->certificateService->createCertificate($data);

        return redirect()->route('certificates.index')
            ->with('success', 'Certificado criado com sucesso!');
    }

    // Criar certificado diretamente a partir de uma OS
    public function storeFromWorkOrder(Request $request, WorkOrder $workOrder)
    {
        $data = $request->validate([
            'execution_date' => 'nullable|date',
            'warranty' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ]);

        $payload = array_merge($data, [
            'client_id' => $workOrder->client_id,
            'work_order_id' => $workOrder->id,
            'status' => 'issued',
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
            'products.activeIngredient',
            'products.chemicalGroup',
            'products.antidote',
            'products.organRegistration',
            'services'
        ]);

        return Inertia::render('Certificates/Show', [
            'certificate' => $certificate,
        ]);
    }

    public function edit(Certificate $certificate)
    {
        // Carregar relacionamentos do certificado
        $certificate->load(['client', 'products', 'services']);

        $clients = Client::orderBy('name')->limit(500)->get();
        $products = Product::orderBy('name')->limit(500)->get();
        $services = Service::where('is_active', true)->orderBy('name')->limit(500)->get();

        // Otimizar: carregar apenas work orders do cliente do certificado ou limitar a quantidade
        $workOrders = WorkOrder::with('client')
            ->where('client_id', $certificate->client_id)
            ->orderBy('order_number')
            ->limit(100)
            ->get();

        return Inertia::render('Certificates/Edit', [
            'certificate' => $certificate,
            'clients' => $clients,
            'products' => $products,
            'services' => $services,
            'workOrders' => $workOrders,
        ]);
    }

    public function update(CertificateRequest $request, Certificate $certificate)
    {
        $this->certificateService->updateCertificate($certificate, $request->validated());

        return redirect()->route('certificates.show', $certificate)
            ->with('success', 'Certificado atualizado com sucesso!');
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
            'workOrder.address.client',
            'products.activeIngredient',
            'products.chemicalGroup',
            'products.antidote',
            'products.organRegistration',
            'services'
        ]);

        // Gerar o PDF com os dados fornecidos
        $pdf = FacadePdf::loadView('pdf.certificate', [
            'certificate' => $certificate,
        ])->setPaper('a4', 'landscape'); // Definindo o tamanho do papel e o modo paisagem

        // Retornar o PDF para download
        return $pdf->stream('certificate-' . $certificate->id . '.pdf');
    }
}
