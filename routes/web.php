<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CertificateController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/certificates/{certificate}/pdf', [CertificateController::class, 'exportPdf'])->name('certificates.pdf');
