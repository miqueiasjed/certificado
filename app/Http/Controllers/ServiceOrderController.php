<?php

namespace App\Http\Controllers;

use App\Models\ServiceOrder;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;

class ServiceOrderController extends Controller
{
    public function generatePdf(ServiceOrder $serviceOrder)
    {
        $pdf = Pdf::loadView('pdf.service-order', [
            'serviceOrder' => $serviceOrder,
        ]);

        return $pdf->stream("OS-{$serviceOrder->id}.pdf");
    }
}
