<?php
namespace App\Http\Controllers;

use App\Models\Certificate;
use Barryvdh\DomPDF\Facade\Pdf as FacadePdf;
use Illuminate\Http\Request;

class CertificateController extends Controller
{
    public function exportPdf(Certificate $certificate)
    {
        // Carregar as relações necessárias
        $certificate->load(['client', 'products', 'procedures', 'service']); // Carregar relacionamentos

        // Gerar o PDF com os dados fornecidos
        $pdf = FacadePdf::loadView('pdf.certificate', [
            'certificate' => $certificate,
        ])->setPaper('a4', 'landscape'); // Definindo o tamanho do papel e o modo paisagem

        // Retornar o PDF para download
        return $pdf->stream('certificate-' . $certificate->id . '.pdf');
    }
}
