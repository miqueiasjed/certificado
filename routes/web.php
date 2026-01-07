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
use App\Http\Controllers\CadastrosController;
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
use App\Http\Controllers\ContractController;
use App\Http\Controllers\WorkOrderFinancialController;
use App\Http\Controllers\FinancialEntryController;
use App\Http\Controllers\FinancialWithdrawalController;
use App\Http\Controllers\CashFlowController;
use App\Http\Controllers\FinancialDashboardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EventTypeController;
use App\Models\Client;
use App\Models\Product;
use App\Models\Technician;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\Certificate;

// Rota de recibo (fora de qualquer middleware)
Route::get('/service-orders/{workOrder}/receipt', [WorkOrderController::class, 'generateReceipt'])->name('service-orders.receipt');


Route::get('/login', function () {
    return inertia('Auth/Login');
})->name('login');

Route::post('/login', [AuthController::class, 'login'])->name('login.post');

// Rotas protegidas
Route::middleware(['auth'])->group(function () {
    // Dashboard principal
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');
    // Rotas de Clientes
    Route::resource('clients', ClientController::class);

    // API route to get client addresses
    Route::get('/api/clients/{client}/addresses', [ClientController::class, 'getAddresses']);

    // Rotas de Cadastros
    Route::get('/cadastros', [CadastrosController::class, 'index'])->name('cadastros.index');

    // Rotas de Produtos
    Route::resource('products', ProductController::class);

    // Rotas de Técnicos
    Route::resource('technicians', TechnicianController::class);

    // Rotas de Princípio Ativo
    Route::resource('active-ingredients', ActiveIngredientController::class);

    // Rotas de Grupo Químico
    Route::resource('chemical-groups', ChemicalGroupController::class);

    // Rotas de Antídoto
    Route::resource('antidotes', AntidoteController::class);

    // Rotas de Registro Ministério da Saúde
    Route::resource('organ-registrations', OrganRegistrationController::class);

    // Rotas de Serviços
    Route::resource('services', ServiceController::class);

    // Rotas de Ordens de Serviço
    Route::resource('service-orders', ServiceOrderController::class);
    Route::get('/service-orders/{serviceOrder}/pdf', [ServiceOrderController::class, 'generatePdf'])->name('service-orders.pdf');
    Route::get('/service-orders/rooms/by-client', [ServiceOrderController::class, 'getRoomsByClient'])->name('service-orders.rooms.by-client');

    // Rotas de Certificados
    Route::resource('certificates', CertificateController::class);
    Route::get('/certificates/{certificate}/pdf', [CertificateController::class, 'exportPdf'])->name('certificates.pdf');
    Route::post('/work-orders/{workOrder}/certificates', [CertificateController::class, 'storeFromWorkOrder'])->name('work-orders.certificates.store');

    // Rotas de Endereços
    Route::resource('addresses', AddressController::class);
    Route::get('/addresses/client/{clientId}', [AddressController::class, 'getByClient'])->name('addresses.by-client');
    Route::get('/addresses/city/{city}', [AddressController::class, 'getByCity'])->name('addresses.by-city');
    Route::get('/addresses/state/{state}', [AddressController::class, 'getByState'])->name('addresses.by-state');
    Route::get('/addresses/{address}/contract/pdf', [ContractController::class, 'generatePDF'])->name('addresses.contract.pdf');
    
    // Rotas para gerenciar dispositivos em endereços
    Route::get('/addresses/{address}/devices', [AddressController::class, 'getDevices'])->name('addresses.devices.index');
    Route::post('/addresses/{address}/devices', [AddressController::class, 'storeDevice'])->name('addresses.devices.store');
    Route::put('/addresses/{address}/devices/{device}', [AddressController::class, 'updateDevice'])->name('addresses.devices.update');
    Route::delete('/addresses/{address}/devices/{device}', [AddressController::class, 'deleteDevice'])->name('addresses.devices.delete');

    // Rotas de Contratos
    Route::resource('contracts', ContractController::class);
    Route::get('/addresses/{address}/contracts/create', [ContractController::class, 'create'])->name('addresses.contracts.create');
    Route::post('/addresses/{address}/contracts', [ContractController::class, 'store'])->name('addresses.contracts.store');
    Route::post('/contracts', [ContractController::class, 'store'])->name('contracts.store');

    // Rotas de Cômodos
    Route::resource('rooms', RoomController::class);
    Route::get('/rooms/address/{addressId}', [RoomController::class, 'getByAddress'])->name('rooms.by-address');

    // Rotas de Tipos de Isca
    Route::resource('bait-types', BaitTypeController::class);

    // Rotas de Dispositivos
    Route::resource('devices', DeviceController::class);
    Route::get('/devices/address/{addressId}', [DeviceController::class, 'getByAddress'])->name('devices.by-address');
    Route::get('/devices/{device}/can-delete', [DeviceController::class, 'canDelete'])->name('devices.can-delete');

    // Rotas de Ordens de Serviço
    Route::resource('work-orders', WorkOrderController::class);
    Route::get('/work-orders/client/{clientId}', [WorkOrderController::class, 'getByClient'])->name('work-orders.by-client');
    Route::get('/work-orders/{workOrder}/pdf', [WorkOrderController::class, 'generatePDF'])->name('work-orders.pdf');

    // Rotas para gerenciar produtos e serviços das work orders
    Route::post('/work-orders/{workOrder}/products/{product}', [WorkOrderController::class, 'addProduct'])->name('work-orders.products.add');
    Route::put('/work-orders/{workOrder}/products/{product}', [WorkOrderController::class, 'updateProduct'])->name('work-orders.products.update');
    Route::delete('/work-orders/{workOrder}/products/{product}', [WorkOrderController::class, 'removeProduct'])->name('work-orders.products.remove');
    Route::post('/work-orders/{workOrder}/services/{service}', [WorkOrderController::class, 'addService'])->name('work-orders.services.add');
    Route::put('/work-orders/{workOrder}/services/{service}', [WorkOrderController::class, 'updateService'])->name('work-orders.services.update');
    Route::delete('/work-orders/{workOrder}/services/{service}', [WorkOrderController::class, 'removeService'])->name('work-orders.services.remove');

    // Rotas para gerenciar técnicos das work orders
    Route::post('/work-orders/{workOrder}/technicians/{technician}', [WorkOrderController::class, 'addTechnician'])->name('work-orders.technicians.add');
    Route::delete('/work-orders/{workOrder}/technicians/{technician}', [WorkOrderController::class, 'removeTechnician'])->name('work-orders.technicians.remove');

    // Rotas para gerenciar cômodos das work orders
    Route::post('/work-orders/{workOrder}/rooms', [WorkOrderController::class, 'addRoom'])->name('work-orders.rooms.add');
    Route::put('/work-orders/{workOrder}/rooms/{roomId}/observation', [WorkOrderController::class, 'updateRoomObservation'])->name('work-orders.rooms.update-observation');
    Route::delete('/work-orders/{workOrder}/rooms/{roomId}', [WorkOrderController::class, 'removeRoom'])->name('work-orders.rooms.remove');
    Route::get('/work-orders/{workOrder}/rooms/available', [WorkOrderController::class, 'getAvailableRooms'])->name('work-orders.rooms.available');
    Route::get('/work-orders/rooms/by-client', [WorkOrderController::class, 'getRoomsByClientWithDevices'])->name('work-orders.rooms.by-client');
    Route::get('/work-orders/devices/by-address', [WorkOrderController::class, 'getDevicesByAddress'])->name('work-orders.devices.by-address');

    // Rotas para gerenciar eventos de cômodos
    Route::post('/work-orders/{workOrder}/rooms/{roomId}/event', [WorkOrderController::class, 'addRoomEvent'])->name('work-orders.rooms.event.add');
    Route::put('/work-orders/{workOrder}/rooms/{roomId}/event', [WorkOrderController::class, 'updateRoomEvent'])->name('work-orders.rooms.event.update');
    Route::delete('/work-orders/{workOrder}/rooms/{roomId}/event', [WorkOrderController::class, 'removeRoomEvent'])->name('work-orders.rooms.event.remove');

    // Rotas para gerenciar avistamentos de praga de cômodos
    Route::post('/work-orders/{workOrder}/rooms/{roomId}/pest-sighting', [WorkOrderController::class, 'addRoomPestSighting'])->name('work-orders.rooms.pest-sighting.add');
    Route::put('/work-orders/{workOrder}/rooms/{roomId}/pest-sighting', [WorkOrderController::class, 'updateRoomPestSighting'])->name('work-orders.rooms.pest-sighting.update');
    Route::delete('/work-orders/{workOrder}/rooms/{roomId}/pest-sighting', [WorkOrderController::class, 'removeRoomPestSighting'])->name('work-orders.rooms.pest-sighting.remove');

    // Rotas para gerenciar eventos de dispositivos
    Route::post('/work-orders/{workOrder}/devices/{deviceId}/events', [WorkOrderController::class, 'addDeviceEvent'])->name('work-orders.devices.event.add');
    Route::put('/work-orders/{workOrder}/devices/{deviceId}/events/{eventId}', [WorkOrderController::class, 'updateDeviceEvent'])->name('work-orders.devices.event.update');
    Route::delete('/work-orders/{workOrder}/devices/{deviceId}/events/{eventId}', [WorkOrderController::class, 'deleteDeviceEvent'])->name('work-orders.devices.event.delete');

    // Rotas de Eventos de Dispositivos
    Route::resource('device-events', DeviceEventController::class);

    // Rotas de Avistamentos de Pragas
    Route::resource('pest-sightings', PestSightingController::class);

    // Rotas de Tipos de Evento
    Route::resource('event-types', EventTypeController::class);

    // Rotas de Detalhes de Pagamento
Route::resource('payment-details', PaymentDetailController::class);
Route::get('/work-orders/{workOrder}/payment-details', [PaymentDetailController::class, 'getByWorkOrder'])->name('payment-details.by-work-order');
Route::post('/payment-details/{paymentDetail}/reopen', [PaymentDetailController::class, 'reopen'])->name('payment-details.reopen');
Route::put('/work-orders/{workOrder}/financial-info', [WorkOrderFinancialController::class, 'updateFinancialInfo'])->name('work-orders.financial-info.update');

    // Rotas de Entradas Financeiras
    Route::resource('financial-entries', FinancialEntryController::class);
    Route::post('/payment-details/{paymentDetail}/create-financial-entry', [FinancialEntryController::class, 'createFromPayment'])->name('financial-entries.create-from-payment');
    Route::get('/financial-entries/stats', [FinancialEntryController::class, 'getStats'])->name('financial-entries.stats');

    // Rotas de Saídas Financeiras
    Route::resource('financial-withdrawals', FinancialWithdrawalController::class);
    Route::get('/financial-withdrawals/stats', [FinancialWithdrawalController::class, 'getStats'])->name('financial-withdrawals.stats');

    // Rotas de Fluxo de Caixa
    Route::get('/cash-flow', [CashFlowController::class, 'index'])->name('cash-flow');
    Route::get('/cash-flow/stats', [CashFlowController::class, 'getStats'])->name('cash-flow.stats');
    Route::get('/cash-flow/export', [CashFlowController::class, 'export'])->name('cash-flow.export');

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
