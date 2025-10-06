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
use App\Http\Controllers\AddressController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\BaitTypeController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\WorkOrderController;
use App\Http\Controllers\DeviceEventController;
use App\Http\Controllers\PestSightingController;
use App\Http\Controllers\PaymentDetailController;
use App\Http\Controllers\WorkOrderFinancialController;
use App\Http\Controllers\FinancialEntryController;
use App\Http\Controllers\FinancialDashboardController;
use App\Http\Controllers\AuthController;
use App\Models\Client;
use App\Models\Product;
use App\Models\Technician;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\Certificate;

// Rota de recibo (fora de qualquer middleware)
Route::get('/service-orders/{workOrder}/receipt', [WorkOrderController::class, 'generateReceipt'])->name('service-orders.receipt');


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
    Route::post('/work-orders/{workOrder}/certificates', [CertificateController::class, 'storeFromWorkOrder'])->name('work-orders.certificates.store');

    // Rotas de Endereços
    Route::resource('addresses', AddressController::class);
    Route::get('/addresses/client/{clientId}', [AddressController::class, 'getByClient'])->name('addresses.by-client');
    Route::get('/addresses/city/{city}', [AddressController::class, 'getByCity'])->name('addresses.by-city');
    Route::get('/addresses/state/{state}', [AddressController::class, 'getByState'])->name('addresses.by-state');

    // Rotas de Cômodos
    Route::resource('rooms', RoomController::class);
    Route::get('/rooms/address/{addressId}', [RoomController::class, 'getByAddress'])->name('rooms.by-address');

    // Rotas de Tipos de Isca
    Route::resource('bait-types', BaitTypeController::class);

    // Rotas de Dispositivos
    Route::resource('devices', DeviceController::class);
    Route::get('/devices/room/{roomId}', [DeviceController::class, 'getByRoom'])->name('devices.by-room');

    // Rotas de Ordens de Serviço
    Route::resource('work-orders', WorkOrderController::class);
    Route::get('/work-orders/client/{clientId}', [WorkOrderController::class, 'getByClient'])->name('work-orders.by-client');

    // Rotas de Eventos de Dispositivos
    Route::resource('device-events', DeviceEventController::class);

    // Rotas de Avistamentos de Pragas
    Route::resource('pest-sightings', PestSightingController::class);

    // Rotas de Detalhes de Pagamento
Route::resource('payment-details', PaymentDetailController::class);
Route::get('/work-orders/{workOrder}/payment-details', [PaymentDetailController::class, 'getByWorkOrder'])->name('payment-details.by-work-order');
Route::post('/payment-details/{paymentDetail}/reopen', [PaymentDetailController::class, 'reopen'])->name('payment-details.reopen');
Route::put('/work-orders/{workOrder}/financial-info', [WorkOrderFinancialController::class, 'updateFinancialInfo'])->name('work-orders.financial-info.update');

    // Rotas de Entradas Financeiras
    Route::resource('financial-entries', FinancialEntryController::class);
    Route::post('/payment-details/{paymentDetail}/create-financial-entry', [FinancialEntryController::class, 'createFromPayment'])->name('financial-entries.create-from-payment');
    Route::get('/financial-entries/stats', [FinancialEntryController::class, 'getStats'])->name('financial-entries.stats');

    // Dashboard Financeiro
    Route::get('/financial-dashboard', [FinancialDashboardController::class, 'index'])->name('financial-dashboard');

    // Rotas para criação rápida
    Route::post('/active-ingredients', [ActiveIngredientController::class, 'store']);
    Route::post('/chemical-groups', [ChemicalGroupController::class, 'store']);
    Route::post('/antidotes', [AntidoteController::class, 'store']);
    Route::post('/organ-registrations', [OrganRegistrationController::class, 'store']);

    // Logout
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
