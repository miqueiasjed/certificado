<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\ServiceOrderController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/certificates/{certificate}/pdf', [CertificateController::class, 'exportPdf'])->name('certificates.pdf');
Route::get('/service-orders/{serviceOrder}/pdf', [ServiceOrderController::class, 'generatePdf'])->name('service-orders.pdf');
