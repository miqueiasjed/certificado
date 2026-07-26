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
use App\Http\Controllers\AgendaController;
use App\Http\Controllers\DeviceEventController;
use App\Http\Controllers\WorkOrderAdequationController;
use App\Http\Controllers\WorkOrderPhotoController;
use App\Http\Controllers\PestSightingController;
use App\Http\Controllers\PaymentDetailController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\ContractVisitController;
use App\Http\Controllers\WorkOrderFinancialController;
use App\Http\Controllers\FinancialEntryController;
use App\Http\Controllers\FinancialWithdrawalController;
use App\Http\Controllers\CashFlowController;
use App\Http\Controllers\FinancialDashboardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EventTypeController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\NotificationTemplateController;
use App\Http\Controllers\NotificationQueueController;
use App\Models\Client;
use App\Models\Product;
use App\Models\Technician;
use App\Models\Service;
use App\Models\ServiceOrder;
use App\Models\Certificate;
use App\Http\Controllers\BudgetController;

// Recibo de pagamento: única rota do sistema que responde sem login.
//
// Quem abre é o cliente final, que não tem conta aqui, então a autorização não
// pode vir de papel nem de permissão. Ela vem da assinatura da URL: o link é
// emitido pelo próprio sistema com `URL::temporarySignedRoute()` e validade, e
// o middleware `signed` recusa qualquer requisição sem `signature` válida ou
// com `expires` no passado. Link com o id puro, que era o que funcionava antes,
// deixou de abrir o recibo.
//
// O recibo carrega nome do cliente, endereço e valores pagos, e por isso a rota
// aberta era vazamento de dado: bastava conhecer um id. A geração do link fica
// em WorkOrderService::urlAssinadaDoRecibo(), atrás de ordem-servico-ver.
Route::get('/service-orders/{workOrder}/receipt', [WorkOrderController::class, 'generateReceipt'])
    ->middleware('signed')
    ->name('service-orders.receipt');


Route::get('/login', function () {
    return inertia('Auth/Login');
})->name('login');

Route::get('/csrf-token', function () {
    session()->regenerateToken();

    return response()->json([
        'csrf_token' => csrf_token(),
        'message' => 'CSRF token refreshed',
    ]);
})->name('csrf-token');

Route::post('/login', [AuthController::class, 'login'])->name('login.post');

// Rotas protegidas
Route::middleware(['auth'])->group(function () {
    // Dashboard principal
    // Sem permissão específica: qualquer usuário autenticado entra. O que cada
    // papel enxerga dentro do dashboard é decidido no controller.
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');

    // Rotas de Clientes
    Route::resource('clients', ClientController::class)
        ->middlewareFor(['index', 'show'], 'permission:cliente-ver')
        ->middlewareFor(['create', 'store'], 'permission:cliente-criar')
        ->middlewareFor(['edit', 'update'], 'permission:cliente-editar')
        ->middlewareFor('destroy', 'permission:cliente-excluir');

    // API route to get client addresses
    Route::get('/api/clients/{client}/addresses', [ClientController::class, 'getAddresses'])->middleware('permission:cliente-ver');

    // Rotas de Cadastros
    Route::get('/cadastros', [CadastrosController::class, 'index'])->middleware('permission:cadastro-ver')->name('cadastros.index');

    // Rotas de Produtos
    Route::resource('products', ProductController::class)
        ->middlewareFor(['index', 'show'], 'permission:cadastro-ver')
        ->middlewareFor(['create', 'store'], 'permission:produto-criar')
        ->middlewareFor(['edit', 'update'], 'permission:produto-editar')
        ->middlewareFor('destroy', 'permission:produto-excluir');

    // Rotas de Técnicos
    Route::resource('technicians', TechnicianController::class)
        ->middlewareFor(['index', 'show'], 'permission:tecnico-ver')
        ->middlewareFor(['create', 'store'], 'permission:tecnico-criar')
        ->middlewareFor(['edit', 'update'], 'permission:tecnico-editar')
        ->middlewareFor('destroy', 'permission:tecnico-excluir');

    // Rotas de Princípio Ativo
    Route::resource('active-ingredients', ActiveIngredientController::class)
        ->middlewareFor(['index', 'show'], 'permission:cadastro-ver')
        ->middlewareFor(['create', 'store', 'edit', 'update', 'destroy'], 'permission:principio-ativo-gerenciar');

    // Rotas de Grupo Químico
    Route::resource('chemical-groups', ChemicalGroupController::class)
        ->middlewareFor(['index', 'show'], 'permission:cadastro-ver')
        ->middlewareFor(['create', 'store', 'edit', 'update', 'destroy'], 'permission:grupo-quimico-gerenciar');

    // Rotas de Antídoto
    Route::resource('antidotes', AntidoteController::class)
        ->middlewareFor(['index', 'show'], 'permission:cadastro-ver')
        ->middlewareFor(['create', 'store', 'edit', 'update', 'destroy'], 'permission:antidoto-gerenciar');

    // Rotas de Registro Ministério da Saúde
    Route::resource('organ-registrations', OrganRegistrationController::class)
        ->middlewareFor(['index', 'show'], 'permission:cadastro-ver')
        ->middlewareFor(['create', 'store', 'edit', 'update', 'destroy'], 'permission:registro-orgao-gerenciar');

    // Rotas de Serviços
    Route::resource('services', ServiceController::class)
        ->middlewareFor(['index', 'show'], 'permission:cadastro-ver')
        ->middlewareFor(['create', 'store', 'edit', 'update', 'destroy'], 'permission:servico-gerenciar');

    // Rotas de Ordens de Serviço
    Route::resource('service-orders', ServiceOrderController::class)
        ->middlewareFor(['index', 'show'], 'permission:servico-agendado-ver')
        ->middlewareFor(['create', 'store'], 'permission:servico-agendado-criar')
        ->middlewareFor(['edit', 'update'], 'permission:servico-agendado-editar')
        ->middlewareFor('destroy', 'permission:servico-agendado-excluir');
    Route::get('/service-orders/{serviceOrder}/pdf', [ServiceOrderController::class, 'generatePdf'])->middleware('permission:servico-agendado-ver')->name('service-orders.pdf');
    Route::get('/service-orders/rooms/by-client', [ServiceOrderController::class, 'getRoomsByClient'])->middleware('permission:servico-agendado-ver')->name('service-orders.rooms.by-client');

    // Rotas de Certificados
    // create e store exigem certificado-emitir: o formulário de emissão é o
    // primeiro passo da emissão, e deixá-lo em certificado-ver abriria a tela
    // para quem não pode emitir.
    Route::resource('certificates', CertificateController::class)
        ->middlewareFor(['index', 'show'], 'permission:certificado-ver')
        ->middlewareFor(['create', 'store'], 'permission:certificado-emitir')
        ->middlewareFor(['edit', 'update'], 'permission:certificado-editar')
        ->middlewareFor('destroy', 'permission:certificado-excluir');
    Route::get('/certificates/{certificate}/pdf', [CertificateController::class, 'exportPdf'])->middleware('permission:certificado-ver')->name('certificates.pdf');
    Route::post('/work-orders/{workOrder}/certificates', [CertificateController::class, 'storeFromWorkOrder'])->middleware('permission:certificado-emitir')->name('work-orders.certificates.store');

    // Rotas de Endereços
    Route::resource('addresses', AddressController::class)
        ->middlewareFor(['index', 'show'], 'permission:endereco-ver')
        ->middlewareFor(['create', 'store'], 'permission:endereco-criar')
        ->middlewareFor(['edit', 'update'], 'permission:endereco-editar')
        ->middlewareFor('destroy', 'permission:endereco-excluir');
    Route::get('/addresses/client/{clientId}', [AddressController::class, 'getByClient'])->middleware('permission:endereco-ver')->name('addresses.by-client');
    Route::get('/addresses/city/{city}', [AddressController::class, 'getByCity'])->middleware('permission:endereco-ver')->name('addresses.by-city');
    Route::get('/addresses/state/{state}', [AddressController::class, 'getByState'])->middleware('permission:endereco-ver')->name('addresses.by-state');
    Route::get('/addresses/{address}/contract/pdf', [ContractController::class, 'generatePDF'])->middleware('permission:contrato-ver')->name('addresses.contract.pdf');

    // Rotas para gerenciar dispositivos em endereços
    // O recurso editado aqui é o endereço, por isso a permissão é endereco-*.
    Route::get('/addresses/{address}/devices', [AddressController::class, 'getDevices'])->middleware('permission:endereco-ver')->name('addresses.devices.index');
    Route::post('/addresses/{address}/devices', [AddressController::class, 'storeDevice'])->middleware('permission:endereco-criar')->name('addresses.devices.store');
    Route::put('/addresses/{address}/devices/{device}', [AddressController::class, 'updateDevice'])->middleware('permission:endereco-editar')->name('addresses.devices.update');
    Route::delete('/addresses/{address}/devices/{device}', [AddressController::class, 'deleteDevice'])->middleware('permission:endereco-excluir')->name('addresses.devices.delete');
    Route::delete('/addresses/{address}/rooms/{room}', [AddressController::class, 'deleteRoom'])->middleware('permission:comodo-gerenciar')->name('addresses.rooms.delete');

    // Rotas de Contratos
    // O painel de pendências (Task 9.7) precisa ser registrado antes do
    // resource: GET /contracts/{contract} (show) casaria com
    // /contracts/pendencias primeiro, tentando bindar um contrato com id
    // "pendencias" e devolvendo 404 em vez do painel.
    Route::get('/contracts/pendencias', [ContractVisitController::class, 'pendencias'])->middleware('permission:contrato-ver')->name('contracts.pendencias');

    Route::resource('contracts', ContractController::class)
        ->middlewareFor(['index', 'show'], 'permission:contrato-ver')
        ->middlewareFor(['create', 'store'], 'permission:contrato-criar')
        ->middlewareFor(['edit', 'update'], 'permission:contrato-editar')
        ->middlewareFor('destroy', 'permission:contrato-excluir');
    Route::get('/addresses/{address}/contracts/create', [ContractController::class, 'create'])->middleware('permission:contrato-criar')->name('addresses.contracts.create');
    Route::post('/addresses/{address}/contracts', [ContractController::class, 'store'])->middleware('permission:contrato-criar')->name('addresses.contracts.store');
    // POST /contracts declarado de novo aqui: por ser registrado depois, ele
    // substitui o store do resource na tabela de rotas. Sem esta permissão a do
    // resource seria ignorada e a criação de contrato ficaria aberta.
    Route::post('/contracts', [ContractController::class, 'store'])->middleware('permission:contrato-criar')->name('contracts.store');

    // Encerramento do contrato: cancela as visitas futuras não executadas e
    // fecha a vigência, com efeito cascata na agenda igual ao de
    // `atualizar()` quando muda o calendário. Mesma permissão de editar, por
    // isso: não pode ficar mais frouxo do que editar o contrato.
    Route::post('/contracts/{contract}/encerrar', [ContractController::class, 'encerrar'])->middleware('permission:contrato-editar')->name('contracts.encerrar');

    // Visitas do contrato (Task 9.7): leitura exige contrato-ver, geração sob
    // demanda exige contrato-editar por ser escrita na agenda.
    Route::get('/contracts/{contrato}/visitas', [ContractVisitController::class, 'index'])->middleware('permission:contrato-ver')->name('contracts.visitas.index');
    Route::post('/contracts/{contrato}/visitas/gerar', [ContractVisitController::class, 'gerar'])->middleware('permission:contrato-editar')->name('contracts.visitas.gerar');

    // Justificativa de data prevista que não virou visita: é o que tira a
    // pendência de conformidade do painel sem criar OS retroativa. Mesma
    // permissão da geração (`contrato-editar`), e por dois motivos: registrar
    // o motivo é escrita no contrato, e silenciar um alerta de conformidade
    // não pode ser mais fácil do que gerar a visita que o alerta pede.
    // Remover a justificativa devolve a pendência ao painel, que é a direção
    // segura, e por isso não exige permissão mais forte que registrar.
    Route::post('/contracts/{contrato}/visitas/justificativas', [ContractVisitController::class, 'justificar'])->middleware('permission:contrato-editar')->name('contracts.visitas.justificativas.store');
    Route::delete('/contracts/{contrato}/visitas/justificativas/{justificativa}', [ContractVisitController::class, 'removerJustificativa'])->middleware('permission:contrato-editar')->name('contracts.visitas.justificativas.destroy');

    // Rotas de Cômodos
    Route::resource('rooms', RoomController::class)
        ->middlewareFor(['index', 'show'], 'permission:endereco-ver')
        ->middlewareFor(['create', 'store', 'edit', 'update', 'destroy'], 'permission:comodo-gerenciar');
    Route::get('/rooms/address/{addressId}', [RoomController::class, 'getByAddress'])->middleware('permission:comodo-gerenciar')->name('rooms.by-address');

    // Rotas de Tipos de Isca
    Route::resource('bait-types', BaitTypeController::class)
        ->middlewareFor(['index', 'show'], 'permission:cadastro-ver')
        ->middlewareFor(['create', 'store', 'edit', 'update', 'destroy'], 'permission:tipo-isca-gerenciar');

    // Rotas de Dispositivos
    Route::resource('devices', DeviceController::class)
        ->middlewareFor(['index', 'show'], 'permission:dispositivo-ver')
        ->middlewareFor(['create', 'store'], 'permission:dispositivo-criar')
        ->middlewareFor(['edit', 'update'], 'permission:dispositivo-editar')
        ->middlewareFor('destroy', 'permission:dispositivo-excluir');
    Route::get('/devices/address/{addressId}', [DeviceController::class, 'getByAddress'])->middleware('permission:dispositivo-ver')->name('devices.by-address');
    // can-delete responde se o dispositivo pode ser removido, então acompanha a
    // permissão de exclusão e não a de leitura.
    Route::get('/devices/{device}/can-delete', [DeviceController::class, 'canDelete'])->middleware('permission:dispositivo-excluir')->name('devices.can-delete');

    // Rotas de Ordens de Serviço
    Route::resource('work-orders', WorkOrderController::class)
        ->middlewareFor(['index', 'show'], 'permission:ordem-servico-ver')
        ->middlewareFor(['create', 'store'], 'permission:ordem-servico-criar')
        ->middlewareFor(['edit', 'update'], 'permission:ordem-servico-editar')
        ->middlewareFor('destroy', 'permission:ordem-servico-excluir');
    Route::get('/work-orders/client/{clientId}', [WorkOrderController::class, 'getByClient'])->middleware('permission:ordem-servico-ver')->name('work-orders.by-client');
    Route::get('/work-orders/{workOrder}/pdf', [WorkOrderController::class, 'generatePDF'])->middleware('permission:ordem-servico-ver')->name('work-orders.pdf');

    // Agenda em calendário: mesma permissão de leitura de OS, porque é a
    // mesma informação, só que organizada por data em vez de listagem.
    Route::get('/agenda', [AgendaController::class, 'index'])->middleware('permission:ordem-servico-ver')->name('agenda.index');
    Route::get('/agenda/dados', [AgendaController::class, 'dados'])->middleware('permission:ordem-servico-ver')->name('agenda.dados');
    // Carga por técnico (Task 10.7): mesma permissão de leitura de dados(), é
    // a mesma informação agregada por técnico em vez de por visita.
    Route::get('/agenda/carga', [AgendaController::class, 'carga'])->middleware('permission:ordem-servico-ver')->name('agenda.carga');

    // Escrita pelo calendário: reagendar, atribuir técnico e a lista de
    // técnicos livres que alimenta a atribuição. As três exigem
    // ordem-servico-editar, porque as três existem para alterar a OS. A lista
    // de disponíveis entra no mesmo grupo de propósito: ela só serve para
    // atribuir, e quem não pode atribuir não precisa saber a agenda de cada
    // técnico.
    Route::get('/agenda/tecnicos-disponiveis', [AgendaController::class, 'tecnicosDisponiveis'])->middleware('permission:ordem-servico-editar')->name('agenda.tecnicos-disponiveis');
    Route::put('/agenda/{workOrder}/reagendar', [AgendaController::class, 'reagendar'])->middleware('permission:ordem-servico-editar')->name('agenda.reagendar');
    Route::put('/agenda/{workOrder}/tecnico', [AgendaController::class, 'atribuirTecnico'])->middleware('permission:ordem-servico-editar')->name('agenda.atribuir-tecnico');

    // Rotas para gerenciar produtos e serviços das work orders
    Route::post('/work-orders/{workOrder}/products/{product}', [WorkOrderController::class, 'addProduct'])->middleware('permission:ordem-servico-executar')->name('work-orders.products.add');
    Route::put('/work-orders/{workOrder}/products/{product}', [WorkOrderController::class, 'updateProduct'])->middleware('permission:ordem-servico-executar')->name('work-orders.products.update');
    Route::delete('/work-orders/{workOrder}/products/{product}', [WorkOrderController::class, 'removeProduct'])->middleware('permission:ordem-servico-executar')->name('work-orders.products.remove');
    Route::post('/work-orders/{workOrder}/services/{service}', [WorkOrderController::class, 'addService'])->middleware('permission:ordem-servico-executar')->name('work-orders.services.add');
    Route::put('/work-orders/{workOrder}/services/{service}', [WorkOrderController::class, 'updateService'])->middleware('permission:ordem-servico-executar')->name('work-orders.services.update');
    Route::delete('/work-orders/{workOrder}/services/{service}', [WorkOrderController::class, 'removeService'])->middleware('permission:ordem-servico-executar')->name('work-orders.services.remove');

    // Rotas para gerenciar técnicos das work orders
    Route::post('/work-orders/{workOrder}/technicians/{technician}', [WorkOrderController::class, 'addTechnician'])->middleware('permission:ordem-servico-executar')->name('work-orders.technicians.add');
    Route::delete('/work-orders/{workOrder}/technicians/{technician}', [WorkOrderController::class, 'removeTechnician'])->middleware('permission:ordem-servico-executar')->name('work-orders.technicians.remove');

    // Rotas para gerenciar cômodos das work orders
    // As três rotas de leitura (available, rooms/by-client e devices/by-address)
    // alimentam selects do formulário de criação e da aba de execução, então
    // ficam em ordem-servico-ver. Quem cria a OS nem sempre a executa, e exigir
    // ordem-servico-executar quebraria o formulário para o papel comercial.
    Route::post('/work-orders/{workOrder}/rooms', [WorkOrderController::class, 'addRoom'])->middleware('permission:ordem-servico-executar')->name('work-orders.rooms.add');
    Route::put('/work-orders/{workOrder}/rooms/{roomId}/observation', [WorkOrderController::class, 'updateRoomObservation'])->middleware('permission:ordem-servico-executar')->name('work-orders.rooms.update-observation');
    Route::delete('/work-orders/{workOrder}/rooms/{roomId}', [WorkOrderController::class, 'removeRoom'])->middleware('permission:ordem-servico-executar')->name('work-orders.rooms.remove');
    Route::get('/work-orders/{workOrder}/rooms/available', [WorkOrderController::class, 'getAvailableRooms'])->middleware('permission:ordem-servico-ver')->name('work-orders.rooms.available');
    Route::get('/work-orders/rooms/by-client', [WorkOrderController::class, 'getRoomsByClientWithDevices'])->middleware('permission:ordem-servico-ver')->name('work-orders.rooms.by-client');
    Route::get('/work-orders/devices/by-address', [WorkOrderController::class, 'getDevicesByAddress'])->middleware('permission:ordem-servico-ver')->name('work-orders.devices.by-address');

    // Rotas para gerenciar eventos de cômodos
    Route::post('/work-orders/{workOrder}/rooms/{roomId}/event', [WorkOrderController::class, 'addRoomEvent'])->middleware('permission:ordem-servico-executar')->name('work-orders.rooms.event.add');
    Route::put('/work-orders/{workOrder}/rooms/{roomId}/event', [WorkOrderController::class, 'updateRoomEvent'])->middleware('permission:ordem-servico-executar')->name('work-orders.rooms.event.update');
    Route::delete('/work-orders/{workOrder}/rooms/{roomId}/event', [WorkOrderController::class, 'removeRoomEvent'])->middleware('permission:ordem-servico-executar')->name('work-orders.rooms.event.remove');

    // Rotas para gerenciar avistamentos de praga de cômodos
    Route::post('/work-orders/{workOrder}/rooms/{roomId}/pest-sighting', [WorkOrderController::class, 'addRoomPestSighting'])->middleware('permission:ordem-servico-executar')->name('work-orders.rooms.pest-sighting.add');
    Route::put('/work-orders/{workOrder}/rooms/{roomId}/pest-sighting', [WorkOrderController::class, 'updateRoomPestSighting'])->middleware('permission:ordem-servico-executar')->name('work-orders.rooms.pest-sighting.update');
    Route::delete('/work-orders/{workOrder}/rooms/{roomId}/pest-sighting', [WorkOrderController::class, 'removeRoomPestSighting'])->middleware('permission:ordem-servico-executar')->name('work-orders.rooms.pest-sighting.remove');

    // Rotas para gerenciar eventos de dispositivos
    Route::post('/work-orders/{workOrder}/devices/{deviceId}/events', [WorkOrderController::class, 'addDeviceEvent'])->middleware('permission:ordem-servico-executar')->name('work-orders.devices.event.add');
    Route::put('/work-orders/{workOrder}/devices/{deviceId}/events/{eventId}', [WorkOrderController::class, 'updateDeviceEvent'])->middleware('permission:ordem-servico-executar')->name('work-orders.devices.event.update');
    Route::delete('/work-orders/{workOrder}/devices/{deviceId}/events/{eventId}', [WorkOrderController::class, 'deleteDeviceEvent'])->middleware('permission:ordem-servico-executar')->name('work-orders.devices.event.delete');

    // Rotas de Adequações
    Route::post('/work-orders/{workOrder}/adequations', [WorkOrderAdequationController::class, 'store'])->middleware('permission:ordem-servico-executar')->name('work-orders.adequations.store');
    Route::put('/work-orders/{workOrder}/adequations/{adequation}', [WorkOrderAdequationController::class, 'update'])->middleware('permission:ordem-servico-executar')->name('work-orders.adequations.update');
    Route::delete('/work-orders/{workOrder}/adequations/{adequation}', [WorkOrderAdequationController::class, 'destroy'])->middleware('permission:ordem-servico-executar')->name('work-orders.adequations.destroy');

    // Rotas de Fotos da OS
    Route::post('/work-orders/{workOrder}/photos', [WorkOrderPhotoController::class, 'store'])->middleware('permission:ordem-servico-executar')->name('work-orders.photos.store');
    Route::delete('/work-orders/{workOrder}/photos/{photo}', [WorkOrderPhotoController::class, 'destroy'])->middleware('permission:ordem-servico-executar')->name('work-orders.photos.destroy');

    // Rotas de Eventos de Dispositivos
    Route::resource('device-events', DeviceEventController::class)
        ->middlewareFor(['index', 'show'], 'permission:cadastro-ver')
        ->middlewareFor(['create', 'store', 'edit', 'update', 'destroy'], 'permission:evento-dispositivo-gerenciar');

    // Rotas de Avistamentos de Pragas
    Route::resource('pest-sightings', PestSightingController::class)
        ->middlewareFor(['index', 'show'], 'permission:cadastro-ver')
        ->middlewareFor(['create', 'store', 'edit', 'update', 'destroy'], 'permission:avistamento-praga-gerenciar');

    // Rotas de Tipos de Evento
    Route::resource('event-types', EventTypeController::class)
        ->middlewareFor(['index', 'show'], 'permission:cadastro-ver')
        ->middlewareFor(['create', 'store', 'edit', 'update', 'destroy'], 'permission:tipo-evento-gerenciar');

    // Rotas de Detalhes de Pagamento
    Route::resource('payment-details', PaymentDetailController::class)
        ->only(['store', 'show', 'update', 'destroy'])
        ->middlewareFor('show', 'permission:pagamento-ver')
        ->middlewareFor('store', 'permission:pagamento-registrar')
        ->middlewareFor('update', 'permission:pagamento-editar')
        ->middlewareFor('destroy', 'permission:pagamento-excluir');
    Route::get('/work-orders/{workOrder}/payment-details', [PaymentDetailController::class, 'getByWorkOrder'])->middleware('permission:pagamento-ver')->name('payment-details.by-work-order');
    Route::post('/payment-details/{paymentDetail}/reopen', [PaymentDetailController::class, 'reopen'])->middleware('permission:pagamento-reabrir')->name('payment-details.reopen');
    Route::put('/work-orders/{workOrder}/financial-info', [WorkOrderFinancialController::class, 'updateFinancialInfo'])->middleware('permission:pagamento-editar')->name('work-orders.financial-info.update');

    // Rotas de Entradas Financeiras
    // A rota de estatísticas precisa vir antes do resource: declarada depois,
    // {financial_entry} captura a string "stats" e o endpoint nunca responde.
    Route::get('/financial-entries/stats', [FinancialEntryController::class, 'getStats'])->middleware('permission:financeiro-ver')->name('financial-entries.stats');
    Route::resource('financial-entries', FinancialEntryController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor(['index', 'show'], 'permission:financeiro-ver')
        ->middlewareFor('store', 'permission:financeiro-lancamento-criar')
        ->middlewareFor('update', 'permission:financeiro-lancamento-editar')
        ->middlewareFor('destroy', 'permission:financeiro-lancamento-excluir');
    Route::post('/payment-details/{paymentDetail}/create-financial-entry', [FinancialEntryController::class, 'createFromPayment'])->middleware('permission:pagamento-registrar')->name('financial-entries.create-from-payment');

    // Rotas de Saídas Financeiras
    // Mesma armadilha das entradas: "stats" antes do resource.
    Route::get('/financial-withdrawals/stats', [FinancialWithdrawalController::class, 'getStats'])->middleware('permission:financeiro-ver')->name('financial-withdrawals.stats');
    Route::resource('financial-withdrawals', FinancialWithdrawalController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->middlewareFor('index', 'permission:financeiro-ver')
        ->middlewareFor('store', 'permission:financeiro-saida-criar')
        ->middlewareFor('update', 'permission:financeiro-saida-editar')
        ->middlewareFor('destroy', 'permission:financeiro-saida-excluir');

    // Rotas de Fluxo de Caixa
    Route::get('/cash-flow', [CashFlowController::class, 'index'])->middleware('permission:financeiro-ver')->name('cash-flow');
    Route::get('/cash-flow/stats', [CashFlowController::class, 'getStats'])->middleware('permission:financeiro-ver')->name('cash-flow.stats');
    Route::get('/cash-flow/export', [CashFlowController::class, 'export'])->middleware('permission:financeiro-exportar')->name('cash-flow.export');

    // Dashboard Financeiro
    Route::get('/financial-dashboard', [FinancialDashboardController::class, 'index'])->middleware('permission:financeiro-ver')->name('financial-dashboard');

    // Rotas para criação rápida
    // Registradas depois dos resources, estas quatro substituem o store de cada
    // um na tabela de rotas. Sem a permissão aqui, a do resource nunca seria
    // avaliada e a criação rápida ficaria aberta a qualquer autenticado.
    Route::post('/active-ingredients', [ActiveIngredientController::class, 'store'])->middleware('permission:principio-ativo-gerenciar');
    Route::post('/chemical-groups', [ChemicalGroupController::class, 'store'])->middleware('permission:grupo-quimico-gerenciar');
    Route::post('/antidotes', [AntidoteController::class, 'store'])->middleware('permission:antidoto-gerenciar');
    Route::post('/organ-registrations', [OrganRegistrationController::class, 'store'])->middleware('permission:registro-orgao-gerenciar');

    // Rotas de Orçamentos
    Route::resource('budgets', BudgetController::class)
        ->middlewareFor(['index', 'show'], 'permission:orcamento-ver')
        ->middlewareFor(['create', 'store'], 'permission:orcamento-criar')
        ->middlewareFor(['edit', 'update'], 'permission:orcamento-editar')
        ->middlewareFor('destroy', 'permission:orcamento-excluir');
    Route::get('/budgets/{budget}/pdf', [BudgetController::class, 'pdf'])->middleware('permission:orcamento-ver')->name('budgets.pdf');
    Route::post('/budgets/{budget}/convert', [BudgetController::class, 'convert'])->middleware('permission:orcamento-converter')->name('budgets.convert');

    // Rotas de Notificações (Plano 14)
    // /notificacoes/templates tem dois segmentos e /notificacoes/{item}/... tem
    // três, então nenhuma das duas colide com a listagem em /notificacoes (um
    // segmento): diferente do caso de /contracts/pendencias, a ordem de
    // registro aqui não importa.
    //
    // Nenhum endpoint envia notificação. "templates" edita o texto que o
    // próximo enfileiramento vai usar; a fila só recoloca em pendente
    // (reenviar) ou marca como cancelada (cancelar) para o despachante da
    // Task 14.3 agir. notificacao-ver cobre leitura; notificacao-gerenciar
    // cobre toda escrita.
    Route::get('/notificacoes/templates', [NotificationTemplateController::class, 'index'])
        ->middleware('permission:notificacao-ver')
        ->name('notificacoes.templates.index');
    Route::put('/notificacoes/templates/{evento}/{canal}', [NotificationTemplateController::class, 'update'])
        ->where(['evento' => '[a-z_]+', 'canal' => 'email|whatsapp'])
        ->middleware('permission:notificacao-gerenciar')
        ->name('notificacoes.templates.update');
    Route::delete('/notificacoes/templates/{evento}/{canal}', [NotificationTemplateController::class, 'destroy'])
        ->where(['evento' => '[a-z_]+', 'canal' => 'email|whatsapp'])
        ->middleware('permission:notificacao-gerenciar')
        ->name('notificacoes.templates.destroy');

    Route::get('/notificacoes', [NotificationQueueController::class, 'index'])
        ->middleware('permission:notificacao-ver')
        ->name('notificacoes.index');
    Route::post('/notificacoes/{item}/reenviar', [NotificationQueueController::class, 'reenviar'])
        ->middleware('permission:notificacao-gerenciar')
        ->name('notificacoes.reenviar');
    Route::post('/notificacoes/{item}/cancelar', [NotificationQueueController::class, 'cancelar'])
        ->middleware('permission:notificacao-gerenciar')
        ->name('notificacoes.cancelar');
    Route::get('/notificacoes/{item}/whatsapp', [NotificationQueueController::class, 'whatsapp'])
        ->middleware('permission:notificacao-ver')
        ->name('notificacoes.whatsapp');

    // Configurações da Empresa
    Route::get('/settings/company', [\App\Http\Controllers\CompanyController::class, 'edit'])->middleware('permission:empresa-configurar')->name('settings.company.edit');
    Route::post('/settings/company', [\App\Http\Controllers\CompanyController::class, 'update'])->middleware('permission:empresa-configurar')->name('settings.company.update');

    // Rotas de Gestão de Usuários
    // O `{user}` destas rotas é resolvido por `User::resolveRouteBindingQuery()`,
    // que filtra pela empresa corrente e devolve 404 para usuário de outra
    // empresa. Rota nova com `{user}` herda essa proteção sem precisar repetir
    // nada; o que não é herdado é id de usuário vindo do corpo da requisição,
    // que continua exigindo `User::daEmpresaAtual()` antes do uso.
    Route::get('/settings/users', [UserController::class, 'index'])->middleware('permission:usuario-ver')->name('settings.users.index');
    Route::post('/settings/users', [UserController::class, 'store'])->middleware('permission:usuario-criar')->name('settings.users.store');
    Route::put('/settings/users/{user}', [UserController::class, 'update'])->middleware('permission:usuario-editar')->name('settings.users.update');
    Route::patch('/settings/users/{user}/status', [UserController::class, 'alterarStatus'])->middleware('permission:usuario-desativar')->name('settings.users.status');

    // Rota de Histórico de Auditoria
    // {tipo} é um apelido de uma lista fechada de models auditados (ver
    // AuditoriaService::MODELS_AUDITADOS), nunca um FQCN vindo do cliente.
    Route::get('/audit-logs/{tipo}/{id}', [AuditLogController::class, 'index'])
        ->where(['tipo' => '[a-z-]+', 'id' => '[0-9]+'])
        ->middleware('permission:auditoria-ver')
        ->name('audit-logs.index');

    // Logout
    // Sem permissão: sair do sistema não pode depender de papel.
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
