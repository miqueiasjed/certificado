<?php

namespace App\Http\Controllers;

use App\Http\Requests\CertificateRequest;
use App\Models\Certificate;
use App\Models\Client;
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
        $clients = Client::orderBy('name')->get();
        $products = Product::orderBy('name')->get();
        $services = Service::where('is_active', true)->orderBy('name')->get();

        return Inertia::render('Certificates/Create', [
            'clients' => $clients,
            'products' => $products,
            'services' => $services,
        ]);
    }

    public function store(CertificateRequest $request)
    {
        $certificate = $this->certificateService->createCertificate($request->validated());

        return redirect()->route('certificates.index')
            ->with('success', 'Certificado criado com sucesso!');
    }

    public function show(Certificate $certificate)
    {
        $certificate->load(['client', 'products.activeIngredient', 'products.chemicalGroup', 'products.antidote', 'products.organRegistration', 'services']);

        return Inertia::render('Certificates/Show', [
            'certificate' => $certificate,
        ]);
    }

    public function edit(Certificate $certificate)
    {
        // Carregar relacionamentos do certificado
        $certificate->load(['client', 'products', 'services']);

        $clients = Client::orderBy('name')->get();
        $products = Product::orderBy('name')->get();
        $services = Service::where('is_active', true)->orderBy('name')->get();

        return Inertia::render('Certificates/Edit', [
            'certificate' => $certificate,
            'clients' => $clients,
            'products' => $products,
            'services' => $services,
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
        $certificate->load(['client', 'products.activeIngredient', 'products.chemicalGroup', 'products.antidote', 'products.organRegistration', 'services']);

        // Gerar o PDF com os dados fornecidos
        $pdf = FacadePdf::loadView('pdf.certificate', [
            'certificate' => $certificate,
        ])->setPaper('a4', 'landscape'); // Definindo o tamanho do papel e o modo paisagem

        // Retornar o PDF para download
        return $pdf->stream('certificate-' . $certificate->id . '.pdf');
    }
}
