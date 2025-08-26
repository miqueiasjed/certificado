<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ActiveIngredientController;
use App\Http\Controllers\ChemicalGroupController;
use App\Http\Controllers\AntidoteController;
use App\Http\Controllers\OrganRegistrationController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\ServiceOrderController;
use App\Http\Controllers\TechnicianController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\AuthController;
use App\Models\Client;
use App\Models\Product;
use App\Models\Technician;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\Certificate;

// Rotas públicas
Route::get('/', function () {
    return inertia('Dashboard', [
        'stats' => [
            'clients' => Client::count(),
            'products' => Product::count(),
            'technicians' => Technician::count(),
            'services' => Service::count(),
            'serviceOrders' => ServiceOrder::count(),
            'certificates' => Certificate::count(),
        ],
        'recentServiceOrders' => ServiceOrder::with('client')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get(),
        'recentCertificates' => Certificate::with('client')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get(),
    ]);
})->name('home');

Route::get('/login', function () {
    return inertia('Auth/Login');
})->name('login');

Route::post('/login', [AuthController::class, 'login'])->name('login.post');

// Rotas protegidas
Route::middleware(['auth'])->group(function () {
    // Rotas de Clientes
    Route::resource('clients', ClientController::class);

    // Rotas de Produtos
    Route::resource('products', ProductController::class);

    // Rotas de Técnicos
    Route::resource('technicians', TechnicianController::class);

    // Rotas de Serviços
    Route::resource('services', ServiceController::class);

    // Rotas de Ordens de Serviço
    Route::resource('service-orders', ServiceOrderController::class);

    // Rotas de Certificados
    Route::resource('certificates', CertificateController::class);
    Route::get('/certificates/{certificate}/pdf', [CertificateController::class, 'exportPdf'])->name('certificates.pdf');

    // Rotas para criação rápida
    Route::post('/active-ingredients', [ActiveIngredientController::class, 'store']);
    Route::post('/chemical-groups', [ChemicalGroupController::class, 'store']);
    Route::post('/antidotes', [AntidoteController::class, 'store']);
    Route::post('/organ-registrations', [OrganRegistrationController::class, 'store']);

    // Logout
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
