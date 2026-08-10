<?php

use App\Http\Controllers\ActiveIngredientController;
use App\Http\Controllers\AddressController;
use App\Http\Controllers\AddressGeoController;
use App\Http\Controllers\AgendaController;
use App\Http\Controllers\AiDraftController;
use App\Http\Controllers\AntidoteController;
use App\Http\Controllers\AppointmentRequestController;
use App\Http\Controllers\AssinaturaController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BaitTypeController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CadastroEmpresaController;
use App\Http\Controllers\CadastrosController;
use App\Http\Controllers\CashFlowController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\ChargeController;
use App\Http\Controllers\ChartOfAccountController;
use App\Http\Controllers\ChemicalGroupController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientRequestAdminController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\CommissionRuleController;
use App\Http\Controllers\CompanyAvailabilitySettingController;
use App\Http\Controllers\ComplianceController;
use App\Http\Controllers\CompromissoController;
use App\Http\Controllers\ContaSuspensaController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\ContractRenewalController;
use App\Http\Controllers\ContractVisitController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\DeviceEventController;
use App\Http\Controllers\DeviceLabelController;
use App\Http\Controllers\DeviceReplacementController;
use App\Http\Controllers\DeviceScanController;
use App\Http\Controllers\EventTypeController;
use App\Http\Controllers\FinancialDashboardController;
use App\Http\Controllers\FinancialEntryController;
use App\Http\Controllers\FinancialReportController;
use App\Http\Controllers\FinancialWithdrawalController;
use App\Http\Controllers\FiscalClientController;
use App\Http\Controllers\FiscalConfigController;
use App\Http\Controllers\FloorPlanController;
use App\Http\Controllers\GatewayWebhookController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\IndicadoresComerciaisController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MonitoringReportController;
use App\Http\Controllers\NotificationQueueController;
use App\Http\Controllers\NotificationTemplateController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OrganRegistrationController;
use App\Http\Controllers\PayableController;
use App\Http\Controllers\PaymentDetailController;
use App\Http\Controllers\PersonalProtectiveEquipmentController;
use App\Http\Controllers\PestSightingController;
use App\Http\Controllers\Plataforma\AssumirTenantController;
use App\Http\Controllers\Plataforma\DashboardController as PlataformaDashboardController;
use App\Http\Controllers\Plataforma\ModuleController as PlataformaModuleController;
use App\Http\Controllers\Plataforma\PlanController as PlataformaPlanController;
use App\Http\Controllers\Plataforma\ReceitaController as PlataformaReceitaController;
use App\Http\Controllers\Plataforma\TenantController as PlataformaTenantController;
use App\Http\Controllers\Plataforma\UsageController as PlataformaUsageController;
use App\Http\Controllers\PpeDeliveryController;
use App\Http\Controllers\ProductBatchController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReceivableController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RouteController;
use App\Http\Controllers\SatisfactionSurveyController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\ServiceInvoiceController;
use App\Http\Controllers\ServiceOrderController;
use App\Http\Controllers\ServicePpeRequirementController;
use App\Http\Controllers\SignatureProviderConfigController;
use App\Http\Controllers\SignatureRequestController;
use App\Http\Controllers\RefuelingController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\VehicleDocumentController;
use App\Http\Controllers\VehicleMaintenanceController;
use App\Http\Controllers\TechnicianController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ValidadesRegulatoriasController;
use App\Http\Controllers\Webhooks\AssinaturaWebhookController;
use App\Http\Controllers\Webhooks\CobrancaWebhookController;
use App\Http\Controllers\NormativeReferenceController;
use App\Http\Controllers\WorkOrderAdequationController;
use App\Http\Controllers\WorkOrderController;
use App\Http\Controllers\WorkOrderFinancialController;
use App\Http\Controllers\WorkOrderPhotoController;
use App\Http\Controllers\WorkOrderSignatureController;
use App\Http\Middleware\EnsureTenantIsActive;
use App\Models\Client;
use App\Models\Service;
use Illuminate\Support\Facades\Route;

// Rotas públicas, sem login.
//
// São quatro, e cada uma tem a própria forma de autorizar, porque em nenhuma
// delas existe usuário autenticado de quem exigir papel ou permissão: o recibo é
// autorizado pela assinatura da URL, o webhook do gateway pela assinatura que
// o provedor de pagamento manda no cabeçalho, e o aceite de convite pelo token
// de 64 caracteres da URL. O cadastro de empresa é a exceção sem credencial
// nenhuma: ele é aberto por definição, e o que o protege é o `throttle` e o
// fato de o formulário não aceitar nenhum campo que conceda privilégio.
// Nenhuma outra rota do sistema responde sem sessão.

// Webhook do gateway de pagamento (Plano 7, Task 7.6).
//
// Fora do grupo autenticado, fora de qualquer resolução de tenant e fora da
// verificação de CSRF (a exclusão está em `bootstrap/app.php`): quem chama é o
// provedor, que não tem sessão nem token. A autenticidade é conferida no
// controller, por `GatewayAssinatura::validarWebhook()`, antes de qualquer
// gravação; requisição sem assinatura válida sai com 401 sem deixar rastro no
// banco.
//
// `{gateway}` existe para que um segundo provedor entre depois sem mexer no que
// já está publicado no painel do PagBank. O nome é confirmado no controller
// contra a lista conhecida, e o que não estiver nela recebe 404.
Route::post('/webhooks/gateway/{gateway}', [GatewayWebhookController::class, 'handle'])
    ->where('gateway', '[a-z0-9_-]+')
    ->name('webhooks.gateway');

// Webhook de cobrança do tenant ao cliente final (Plano 19, Task 19.4).
//
// Mesmo motivo do webhook acima para ficar fora do grupo autenticado, fora de
// qualquer resolução de tenant e fora da verificação de CSRF (exclusão em
// `bootstrap/app.php`): quem chama é o gateway de pagamento, sem sessão nem
// token. A diferença é o que identifica o tenant: lá é o nome do provedor
// (`{gateway}`), aqui é o `{webhookToken}` da URL, específico de cada
// empresa — `PaymentGatewayConfig::paraToken()` resolve por um hash
// determinístico, porque a coluna `webhook_token` é cifrada com IV aleatório
// e não permite `WHERE webhook_token = ?` direto no banco.
//
// A autenticidade é conferida no controller, por
// `GatewayDeCobranca::validarWebhook()`, antes de qualquer gravação;
// requisição sem assinatura válida sai com 401 sem deixar rastro no banco, e
// token que não corresponde a tenant nenhum sai com 404.
Route::post('/webhooks/cobranca/{webhookToken}', [CobrancaWebhookController::class, 'handle'])
    ->where('webhookToken', '[A-Za-z0-9]+')
    ->name('webhooks.cobranca');

// Webhook do provedor de assinatura eletrônica de contratos (Plano 26, Task
// 26.3).
//
// Mesmo desenho do webhook de cobrança logo acima — fora do grupo autenticado,
// fora da resolução de tenant, fora do CSRF, tenant resolvido pelo
// `{webhookToken}` da URL via `SignatureProviderConfig::paraToken()` — com uma
// diferença que precisa estar clara para quem for mexer aqui: o provedor de
// assinatura **não assina** a requisição, então não há cabeçalho de HMAC a
// conferir.
//
// O que sustenta a segurança desta rota é o corpo não decidir nada. Dele sai
// só o identificador do documento; quem diz o que aconteceu é uma consulta
// autenticada ao provedor, com a credencial do próprio tenant
// (`SignatureRequestService::sincronizar()`). Um POST forjado que acerte o
// token, no pior caso, provoca uma consulta sobre um documento daquele mesmo
// tenant e converge para o estado real — ele não marca contrato como assinado.
// Ver o cabeçalho de `App\Services\Signature\ProvedorPadrao::validarWebhook()`.
Route::post('/webhooks/assinatura/{webhookToken}', [AssinaturaWebhookController::class, 'handle'])
    ->where('webhookToken', '[A-Za-z0-9]+')
    ->name('webhooks.assinatura');

// Recibo de pagamento.
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

// Aceite de convite de usuário (Plano 8, Task 8.4).
//
// A rota mais sensível deste arquivo depois do cadastro público: ela cria um
// usuário dentro de uma empresa existente, sem sessão nenhuma. Três decisões
// sustentam isso, e nenhuma delas está aqui por acaso.
//
// 1. **A autorização é o token.** 64 caracteres aleatórios, único no banco, de
//    uso único, gerado em `Invitation::booted()`. `InvitationController` não
//    consulta `Auth` nem `Company::current()` nestes dois métodos: fora do
//    grupo `auth` não existe tenant resolvido, e a empresa do usuário criado é
//    sempre a do convite, aplicada com `TenantAtual::comTenant()` dentro do
//    `InvitationService`.
//
// 2. **O papel vem do convite.** O formulário manda nome e senha, e mais nada.
//    Um campo de papel aqui entregaria administrador para quem tivesse o link.
//
// 3. **`throttle` nas duas.** Rota pública que grava usuário, mesmo critério
//    do cadastro de empresa (Task 8.7). Adivinhar o token não é o risco que
//    isso cobre (64 caracteres aleatórios não se descobrem por tentativa), e
//    sim o abuso automatizado da rota. Por isso a folga: várias pessoas da
//    mesma empresa aceitando o convite de trás do mesmo IP, cada uma errando a
//    confirmação de senha uma ou duas vezes, precisa caber no minuto.
//
// O `{token}` é string livre, nunca Model Binding: token inválido, de outra
// empresa ou truncado precisa cair na mesma tela com a mensagem do que
// aconteceu, e o 404 automático do binding devolveria uma página crua para
// quem só clicou no link que recebeu. A restrição alfanumérica cobre o formato
// que `Str::random()` produz.
Route::get('/convite/{token}', [InvitationController::class, 'show'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:30,1')
    ->name('convite.show');
Route::post('/convite/{token}', [InvitationController::class, 'aceitar'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:20,1')
    ->name('convite.aceitar');

// Cadastro público de empresa (Plano 8, Task 8.7).
//
// A rota mais sensível deste arquivo: uma requisição anônima cria uma empresa,
// um usuário administrador e algumas dezenas de cadastros de catálogo. Fica
// fora do grupo autenticado, fora de `tenant.ativo` e fora de qualquer
// `module:<chave>`, e tem que ficar: quem chega aqui não tem sessão, não tem
// empresa resolvida e não tem plano do qual derivar módulo. O detalhamento do
// que sustenta a segurança dela está no cabeçalho de `CadastroEmpresaController`.
//
// Os dois `throttle` são por IP e por minuto, e os limites são diferentes de
// propósito:
//
// - `20,1` no GET: é só a página do formulário, e quem recarrega, volta do
//   login e recarrega de novo não pode esbarrar em bloqueio.
// - `5,1` no POST: é o mais apertado do sistema (o aceite de convite, que
//   também grava usuário, tem 20). Cinco tentativas por minuto sobram para
//   quem erra a confirmação de senha duas ou três vezes, e não sobram para
//   quem quer povoar a tabela de tenants por script. A conta de tentativas
//   inclui as recusadas na validação: o custo que se está limitando é o da
//   requisição, não o do cadastro concluído.
//
// Se um dia várias pessoas legítimas passarem a se cadastrar de trás do mesmo
// IP (evento, coworking), o limite do POST é o que precisa subir, nunca sair.
Route::get('/cadastro', [CadastroEmpresaController::class, 'create'])
    ->middleware('throttle:20,1')
    ->name('cadastro.create');
Route::post('/cadastro', [CadastroEmpresaController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('cadastro.store');

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

// Casca do aplicativo do técnico (Plano 12, Task 12.6).
//
// Fora do grupo `auth` de propósito: é a mesma razão da rota `/login` acima.
// O aplicativo tem autenticação própria por token Sanctum, resolvida dentro
// dele mesmo contra `/api/app/login` (Task 12.2), e não por sessão de
// cookie. Esta rota só entrega o HTML/JS/CSS que o service worker guarda em
// precache (vite.config.js) - inclusive a tela de login do aplicativo -,
// para que ele abra mesmo sem rede antes de qualquer autenticação.
//
// Sem `throttle`: diferente do cadastro público e do aceite de convite, esta
// rota não grava nada, é leitura pura de um HTML estático de poucos KB, e é
// exatamente o que o service worker precisa buscar sem fricção na primeira
// visita e a cada nova versão.
Route::get('/app', function () {
    return view('app-tecnico');
})->name('app-tecnico');

// Rotas protegidas
//
// `tenant.ativo` (Plano 7, Task 7.7) entra no grupo inteiro, e não rota a rota
// como o `module:<chave>` do Plano 6: suspensão por falta de pagamento bloqueia
// o sistema todo do tenant, não um módulo dele. Aplicado aqui, e não como append
// global em `bootstrap/app.php`, para não alcançar as rotas públicas nem a área
// `/plataforma` (o motivo completo está no comentário do alias, em
// `bootstrap/app.php`). As poucas rotas que continuam abertas ao tenant suspenso
// estão em `EnsureTenantIsActive::ROTAS_LIBERADAS`, por nome.
Route::middleware(['auth', 'tenant.ativo'])->group(function () {
    // Dashboard principal
    // Sem permissão específica: qualquer usuário autenticado entra. O que cada
    // papel enxerga dentro do dashboard é decidido no controller.
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');

    // Trilha de primeiros passos (Plano 8, Task 8.9): as duas ações que o
    // bloco `TrilhaDePrimeirosPassos` do dashboard dispara, sempre voltando
    // para a página de origem (`back()`). Sem permissão própria: quem já vê o
    // dashboard já pode decidir o que fazer com a própria trilha.
    Route::post('/onboarding/ignorar/{chave}', [OnboardingController::class, 'ignorar'])->name('onboarding.ignorar');
    Route::post('/onboarding/retomar/{chave}', [OnboardingController::class, 'retomar'])->name('onboarding.retomar');

    // Página "Módulo indisponível" (Plano 6, Task 6.4): destino do
    // redirecionamento de `EnsureModuleIsActive` quando uma requisição Inertia
    // esbarra em módulo inativo. Fica de propósito fora de qualquer grupo com
    // `module:*`: se ela própria caísse em outro bloqueio de módulo, o
    // redirecionamento nunca teria para onde ir. O componente Vue
    // `ModuloIndisponivel` nasce só na Task 6.8; até lá, a rota aponta para
    // uma página Inertia que ainda não existe, o que é esperado aqui.
    //
    // Nome e descrição vêm do banco (fonte única em `CatalogoDeModulos`, via
    // seeder), não redeclarados aqui nem no componente Vue: a chave sozinha
    // não é o suficiente para a tela, e duplicar nome/descrição no frontend
    // divergiria assim que o catálogo mudasse.
    Route::get('/modulo-indisponivel/{modulo}', function (string $modulo) {
        $info = \App\Models\Module::query()->where('chave', $modulo)->first();

        return inertia('ModuloIndisponivel', [
            'modulo' => $modulo,
            'nome' => $info->nome ?? 'Este módulo',
            'descricao' => $info->descricao ?? 'Um recurso adicional do sistema.',
        ]);
    })->name('modulo-indisponivel');

    // Página "Conta suspensa" (Plano 7, Task 7.7): destino do redirecionamento
    // de `EnsureTenantIsActive` quando a empresa está suspensa por falta de
    // pagamento. Continua dentro do grupo `tenant.ativo`, e não é bloqueada por
    // ele porque o nome dela está em `EnsureTenantIsActive::ROTAS_LIBERADAS`;
    // fora da lista, o redirecionamento apontaria para si mesmo em laço.
    //
    // Sem permissão específica: quem precisa ver por que o acesso parou é
    // qualquer usuário da empresa, inclusive o técnico que não mexe em
    // financeiro.
    Route::get('/conta-suspensa', [ContaSuspensaController::class, 'show'])
        ->name(EnsureTenantIsActive::ROTA_CONTA_SUSPENSA);

    // Tela de assinatura do tenant (Plano 7, Task 7.8): o próprio plano, a
    // situação da cobrança e as faturas dos últimos 12 meses.
    //
    // As três rotas nascem nomeadas `assinatura.faturas*` de propósito: é o
    // padrão que `EnsureTenantIsActive::ROTAS_LIBERADAS` já libera para o
    // tenant suspenso (Task 7.7), e nomear diferente aqui bloquearia a
    // própria tela que precisa mostrar o boleto para o tenant pagar. Por
    // isso continuam dentro deste grupo, e não fora dele: fora perderiam o
    // `auth`, que a rota também precisa.
    //
    // Sem permissão em `show`/`faturaShow`: qualquer usuário autenticado da
    // empresa vê o próprio plano e as próprias faturas, mesmo critério de
    // `/conta-suspensa`. Só a troca de forma de pagamento exige
    // `assinatura-gerenciar`, por ser a única escrita deste grupo.
    Route::get('/assinatura', [AssinaturaController::class, 'show'])->name('assinatura.faturas.index');
    Route::get('/assinatura/faturas/{invoice}', [AssinaturaController::class, 'faturaShow'])->name('assinatura.faturas.show');
    Route::post('/assinatura/forma-pagamento', [AssinaturaController::class, 'trocarFormaDePagamento'])
        ->middleware('permission:assinatura-gerenciar')
        ->name('assinatura.faturas.forma-pagamento');

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
    Route::get('/addresses/{address}/contract/pdf', [ContractController::class, 'generatePDF'])->middleware(['permission:contrato-ver', 'module:contratos'])->name('addresses.contract.pdf');

    // Rotas para gerenciar dispositivos em endereços
    // O recurso editado aqui é o endereço, por isso a permissão é endereco-*.
    Route::get('/addresses/{address}/devices', [AddressController::class, 'getDevices'])->middleware('permission:endereco-ver')->name('addresses.devices.index');
    Route::post('/addresses/{address}/devices', [AddressController::class, 'storeDevice'])->middleware('permission:endereco-criar')->name('addresses.devices.store');
    Route::put('/addresses/{address}/devices/{device}', [AddressController::class, 'updateDevice'])->middleware('permission:endereco-editar')->name('addresses.devices.update');
    Route::delete('/addresses/{address}/devices/{device}', [AddressController::class, 'deleteDevice'])->middleware('permission:endereco-excluir')->name('addresses.devices.delete');
    Route::delete('/addresses/{address}/rooms/{room}', [AddressController::class, 'deleteRoom'])->middleware('permission:comodo-gerenciar')->name('addresses.rooms.delete');

    // Rotas de Contratos
    // Todas as rotas deste bloco, mais as três "addresses.contract(s).*"
    // registradas junto de Endereços/Dispositivos, acumulam `module:contratos`
    // (Plano 6, Task 6.4) com a permissão já existente: tenant sem o módulo
    // fica barrado mesmo tendo `contrato-ver`/`contrato-editar`.
    //
    // O painel de pendências (Task 9.7) precisa ser registrado antes do
    // resource: GET /contracts/{contract} (show) casaria com
    // /contracts/pendencias primeiro, tentando bindar um contrato com id
    // "pendencias" e devolvendo 404 em vez do painel. Mesmo motivo para
    // /contracts/a-vencer (Task 23.5/23.6), logo abaixo.
    Route::get('/contracts/pendencias', [ContractVisitController::class, 'pendencias'])->middleware(['permission:contrato-ver', 'module:contratos'])->name('contracts.pendencias');

    // Painel de contratos a vencer, por marco configurado, e vencidos sem
    // tratativa (Plano 23, Task 23.5/23.6). Leitura de
    // `ContractAlertService`, mesma permissão de `contracts.pendencias`.
    Route::get('/contracts/a-vencer', [ContractRenewalController::class, 'aVencer'])->middleware(['permission:contrato-ver', 'module:contratos'])->name('contracts.a-vencer');

    Route::resource('contracts', ContractController::class)
        ->middlewareFor(['index', 'show'], 'permission:contrato-ver')
        ->middlewareFor(['create', 'store'], 'permission:contrato-criar')
        ->middlewareFor(['edit', 'update'], 'permission:contrato-editar')
        ->middlewareFor('destroy', 'permission:contrato-excluir')
        ->middleware('module:contratos');
    Route::get('/addresses/{address}/contracts/create', [ContractController::class, 'create'])->middleware(['permission:contrato-criar', 'module:contratos'])->name('addresses.contracts.create');
    Route::post('/addresses/{address}/contracts', [ContractController::class, 'store'])->middleware(['permission:contrato-criar', 'module:contratos'])->name('addresses.contracts.store');
    // POST /contracts declarado de novo aqui: por ser registrado depois, ele
    // substitui o store do resource na tabela de rotas. Sem esta permissão a do
    // resource seria ignorada e a criação de contrato ficaria aberta. Pelo
    // mesmo motivo, `module:contratos` precisa se repetir aqui: sem ele, esta
    // sobrescrita reabriria a criação de contrato para tenant sem o módulo.
    Route::post('/contracts', [ContractController::class, 'store'])->middleware(['permission:contrato-criar', 'module:contratos'])->name('contracts.store');

    // Encerramento do contrato: cancela as visitas futuras não executadas e
    // fecha a vigência, com efeito cascata na agenda igual ao de
    // `atualizar()` quando muda o calendário. Mesma permissão de editar, por
    // isso: não pode ficar mais frouxo do que editar o contrato.
    Route::post('/contracts/{contract}/encerrar', [ContractController::class, 'encerrar'])->middleware(['permission:contrato-editar', 'module:contratos'])->name('contracts.encerrar');

    // Renovação de contrato (Plano 23, Task 23.4/23.6): renovar, registrar
    // não renovação e marcar em negociação são as três saídas do painel de
    // contratos a vencer, todas atrás da mesma permissão `contrato-renovar`
    // (ver o comentário dela em `SyncPermissions::catalogo()`). A prévia
    // (`renovar/previa`) é GET e não grava nada, mas fica atrás da mesma
    // permissão de renovar, e não de `contrato-ver`: ela só importa para
    // quem está prestes a renovar, não para consulta geral do contrato.
    Route::get('/contracts/{contract}/renovar/previa', [ContractRenewalController::class, 'previa'])->middleware(['permission:contrato-renovar', 'module:contratos'])->name('contracts.renovar.previa');
    Route::post('/contracts/{contract}/renovar', [ContractRenewalController::class, 'renovar'])->middleware(['permission:contrato-renovar', 'module:contratos'])->name('contracts.renovar');
    Route::post('/contracts/{contract}/nao-renovar', [ContractRenewalController::class, 'naoRenovar'])->middleware(['permission:contrato-renovar', 'module:contratos'])->name('contracts.nao-renovar');
    Route::post('/contracts/{contract}/em-negociacao', [ContractRenewalController::class, 'emNegociacao'])->middleware(['permission:contrato-renovar', 'module:contratos'])->name('contracts.em-negociacao');

    // Visitas do contrato (Task 9.7): leitura exige contrato-ver, geração sob
    // demanda exige contrato-editar por ser escrita na agenda.
    Route::get('/contracts/{contrato}/visitas', [ContractVisitController::class, 'index'])->middleware(['permission:contrato-ver', 'module:contratos'])->name('contracts.visitas.index');
    Route::post('/contracts/{contrato}/visitas/gerar', [ContractVisitController::class, 'gerar'])->middleware(['permission:contrato-editar', 'module:contratos'])->name('contracts.visitas.gerar');

    // Justificativa de data prevista que não virou visita: é o que tira a
    // pendência de conformidade do painel sem criar OS retroativa. Mesma
    // permissão da geração (`contrato-editar`), e por dois motivos: registrar
    // o motivo é escrita no contrato, e silenciar um alerta de conformidade
    // não pode ser mais fácil do que gerar a visita que o alerta pede.
    // Remover a justificativa devolve a pendência ao painel, que é a direção
    // segura, e por isso não exige permissão mais forte que registrar.
    Route::post('/contracts/{contrato}/visitas/justificativas', [ContractVisitController::class, 'justificar'])->middleware(['permission:contrato-editar', 'module:contratos'])->name('contracts.visitas.justificativas.store');
    Route::delete('/contracts/{contrato}/visitas/justificativas/{justificativa}', [ContractVisitController::class, 'removerJustificativa'])->middleware(['permission:contrato-editar', 'module:contratos'])->name('contracts.visitas.justificativas.destroy');

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

    // Substituição do dispositivo do ponto: cria o dispositivo novo, marca o
    // anterior como substituído e grava a linha que liga os dois. Mesma
    // permissão de editar, por isso: a troca substitui a edição do dispositivo
    // danificado, e não pode ficar mais frouxa do que ela. Não usa
    // `dispositivo-criar` porque o dispositivo novo é consequência da troca, e
    // quem só pode cadastrar não pode retirar um dispositivo de circulação.
    Route::post('/devices/{device}/substituir', [DeviceReplacementController::class, 'store'])->middleware('permission:dispositivo-editar')->name('devices.substituir');

    // Cadastro em lote dos dispositivos de um endereço: cria a faixa numerada
    // inteira em uma transação, ou nenhuma linha.
    //
    // O caminho fica sob `/addresses/{address}` porque o lote só existe dentro
    // de um endereço (é ele que define a faixa de números), e é ali que o
    // Model Binding traz o endereço já escopado por empresa. A permissão, ao
    // contrário das outras rotas daquele bloco, é `dispositivo-criar` e não
    // `endereco-criar`: o que nasce aqui são dezenas de dispositivos, e quem
    // pode cadastrar endereço não fica com o poder de povoar o imóvel inteiro.
    // Por isso a rota mora aqui, junto do resto do módulo de dispositivos.
    Route::post('/addresses/{address}/devices/lote', [DeviceController::class, 'criarLote'])->middleware('permission:dispositivo-criar')->name('addresses.devices.lote');

    // Folha de etiquetas (QR code) dos dispositivos de um endereço, para
    // imprimir, recortar e colar. Não fazia parte de nenhuma task do Plano 11 -
    // a Task 11.3 criou só o Service e a view, e a Task 11.8 (telas) pressupõe
    // uma URL para abrir a folha em nova aba. Permissão de leitura, porque
    // imprimir etiqueta não altera nada.
    Route::get('/addresses/{address}/devices/etiquetas', [DeviceLabelController::class, 'folha'])->middleware('permission:dispositivo-ver')->name('addresses.devices.etiquetas');

    // Leitura do código da etiqueta / QR code do dispositivo.
    //
    // O `{codigo}` é uma string livre e nunca vira Model Binding: código
    // malformado, inexistente e de outra empresa precisam sair todos com 200 e
    // `situacao: nao_encontrado`, e o 404 automático do binding entregaria uma
    // resposta diferente para o primeiro caso. Sem restrição de formato na rota
    // pelo mesmo motivo.
    //
    // As duas rotas apontam para o mesmo método e devolvem o mesmo JSON. A
    // versão `/api` não sai do grupo `auth`: quem consome é o frontend Vue desta
    // aplicação, autenticado por sessão, e não um cliente externo com token. O
    // prefixo existe só para o leitor de QR e o aplicativo do Plano 12 terem um
    // caminho estável no dia em que `/devices/ler/{codigo}` ganhar uma tela.
    // Precedente do mesmo arranjo: `/api/clients/{client}/addresses`.
    //
    // Permissão `dispositivo-ver`, e não `ordem-servico-executar`: a leitura
    // avulsa (sem work_order_id) é consulta de dispositivo, e o papel `tecnico`
    // já carrega essa permissão. O vínculo do técnico com a ordem informada é
    // verificado por WorkOrderAccessService dentro do Service, porque depende da
    // instância e o middleware não alcança.
    Route::get('/devices/ler/{codigo}', [DeviceScanController::class, 'ler'])->middleware('permission:dispositivo-ver')->name('devices.ler');
    Route::get('/api/devices/ler/{codigo}', [DeviceScanController::class, 'ler'])->middleware('permission:dispositivo-ver')->name('api.devices.ler');

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

    // Compromisso avulso da Agenda (Plano 30, Task 30.3): visita ou tarefa
    // que entra no calendário sem depender de uma OS já existente. Extensão
    // da Agenda, então sem middleware de módulo, mesmo critério do bloco
    // acima: só `permission:compromisso-gerenciar`, única permissão de
    // escrita porque compromisso não tem a segregação de quem-cria e
    // quem-corrige que a entrega de EPI tem.
    Route::post('/compromissos', [CompromissoController::class, 'store'])->middleware('permission:compromisso-gerenciar')->name('compromissos.store');
    Route::put('/compromissos/{compromisso}', [CompromissoController::class, 'update'])->middleware('permission:compromisso-gerenciar')->name('compromissos.update');
    Route::delete('/compromissos/{compromisso}', [CompromissoController::class, 'destroy'])->middleware('permission:compromisso-gerenciar')->name('compromissos.destroy');
    Route::post('/compromissos/{compromisso}/cancelar', [CompromissoController::class, 'cancelar'])->middleware('permission:compromisso-gerenciar')->name('compromissos.cancelar');
    Route::post('/compromissos/{compromisso}/concluir', [CompromissoController::class, 'concluir'])->middleware('permission:compromisso-gerenciar')->name('compromissos.concluir');
    Route::post('/compromissos/{compromisso}/promover-os', [CompromissoController::class, 'promoverParaOs'])->middleware('permission:compromisso-gerenciar')->name('compromissos.promover-os');

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

    // Rotas de Assinatura da OS (Plano 13, Task 13.4): coleta feita no
    // computador do escritório e recusa. A coleta pelo aplicativo em campo
    // não tem rota própria de propósito: ela chega pelo lote de
    // sincronização (AplicadorDeAssinatura, Task 13.3), para manter a mesma
    // garantia de idempotência das demais operações offline.
    Route::post('/work-orders/{workOrder}/signature', [WorkOrderSignatureController::class, 'store'])->middleware('permission:os.assinar')->name('work-orders.signature.store');
    Route::post('/work-orders/{workOrder}/signature/refusal', [WorkOrderSignatureController::class, 'recusar'])->middleware('permission:os.assinar')->name('work-orders.signature.refuse');
    // Correção justificada de OS já assinada, reservada ao administrador:
    // técnico corrigir a própria OS assinada anularia o efeito do travamento.
    Route::post('/work-orders/{workOrder}/correction', [WorkOrderSignatureController::class, 'corrigir'])->middleware('permission:os.corrigir_assinada')->name('work-orders.correction.store');

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
    // Daqui até o Dashboard Financeiro, todo o bloco acumula `module:financeiro`
    // (Plano 6, Task 6.4) com a permissão já existente: os dois precisam
    // passar. Inclui as duas rotas aninhadas em /work-orders/{workOrder}/...:
    // mesmo com o prefixo de URL diferente, `payment-details.by-work-order` e
    // `work-orders.financial-info.update` leem/escrevem o mesmo dado de
    // pagamento que o resource abaixo, e ficariam abertas em tenant sem o
    // módulo se fossem esquecidas aqui.
    Route::resource('payment-details', PaymentDetailController::class)
        ->only(['store', 'show', 'update', 'destroy'])
        ->middlewareFor('show', 'permission:pagamento-ver')
        ->middlewareFor('store', 'permission:pagamento-registrar')
        ->middlewareFor('update', 'permission:pagamento-editar')
        ->middlewareFor('destroy', 'permission:pagamento-excluir')
        ->middleware('module:financeiro');
    Route::get('/work-orders/{workOrder}/payment-details', [PaymentDetailController::class, 'getByWorkOrder'])->middleware(['permission:pagamento-ver', 'module:financeiro'])->name('payment-details.by-work-order');
    Route::post('/payment-details/{paymentDetail}/reopen', [PaymentDetailController::class, 'reopen'])->middleware(['permission:pagamento-reabrir', 'module:financeiro'])->name('payment-details.reopen');
    Route::put('/work-orders/{workOrder}/financial-info', [WorkOrderFinancialController::class, 'updateFinancialInfo'])->middleware(['permission:pagamento-editar', 'module:financeiro'])->name('work-orders.financial-info.update');

    // Rotas de Entradas Financeiras
    // A rota de estatísticas precisa vir antes do resource: declarada depois,
    // {financial_entry} captura a string "stats" e o endpoint nunca responde.
    Route::get('/financial-entries/stats', [FinancialEntryController::class, 'getStats'])->middleware(['permission:financeiro-ver', 'module:financeiro'])->name('financial-entries.stats');
    Route::resource('financial-entries', FinancialEntryController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy'])
        ->middlewareFor(['index', 'show'], 'permission:financeiro-ver')
        ->middlewareFor('store', 'permission:financeiro-lancamento-criar')
        ->middlewareFor('update', 'permission:financeiro-lancamento-editar')
        ->middlewareFor('destroy', 'permission:financeiro-lancamento-excluir')
        ->middleware('module:financeiro');
    Route::post('/payment-details/{paymentDetail}/create-financial-entry', [FinancialEntryController::class, 'createFromPayment'])->middleware(['permission:pagamento-registrar', 'module:financeiro'])->name('financial-entries.create-from-payment');

    // Rotas de Saídas Financeiras
    // Mesma armadilha das entradas: "stats" antes do resource.
    Route::get('/financial-withdrawals/stats', [FinancialWithdrawalController::class, 'getStats'])->middleware(['permission:financeiro-ver', 'module:financeiro'])->name('financial-withdrawals.stats');
    Route::resource('financial-withdrawals', FinancialWithdrawalController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->middlewareFor('index', 'permission:financeiro-ver')
        ->middlewareFor('store', 'permission:financeiro-saida-criar')
        ->middlewareFor('update', 'permission:financeiro-saida-editar')
        ->middlewareFor('destroy', 'permission:financeiro-saida-excluir')
        ->middleware('module:financeiro');

    // Rotas de Fluxo de Caixa
    Route::get('/cash-flow', [CashFlowController::class, 'index'])->middleware(['permission:financeiro-ver', 'module:financeiro'])->name('cash-flow');
    Route::get('/cash-flow/stats', [CashFlowController::class, 'getStats'])->middleware(['permission:financeiro-ver', 'module:financeiro'])->name('cash-flow.stats');
    Route::get('/cash-flow/export', [CashFlowController::class, 'export'])->middleware(['permission:financeiro-exportar', 'module:financeiro'])->name('cash-flow.export');

    // Dashboard Financeiro
    Route::get('/financial-dashboard', [FinancialDashboardController::class, 'index'])->middleware(['permission:financeiro-ver', 'module:financeiro'])->name('financial-dashboard');

    // Contas a receber, contas a pagar, plano de contas, inadimplência e
    // previsão de caixa (Plano 18, Task 18.7).
    //
    // O `module:financeiro` fica no grupo, e não repetido rota a rota como no
    // bloco financeiro acima: aqui o bloco inteiro nasce nesta task, então a
    // garantia estrutural ("rota nova dentro do grupo já nasce bloqueada para
    // tenant sem o módulo") vale mais que o paralelismo com as rotas antigas,
    // que cresceram aos poucos e por isso carregam o middleware uma a uma.
    // Mesmo arranjo já usado no bloco de estoque, abaixo.
    //
    // Quatro permissões, cada uma com um alcance diferente:
    //
    // - `financeiro-titulos`: listar, criar e cancelar título dos dois lados,
    //   e alterar o valor de um recorrente. Mexe no que a empresa tem a cobrar
    //   e a pagar, sem tocar em dinheiro já lançado.
    // - `financeiro-baixar`: a baixa, que é o que transforma "o cliente pagou"
    //   em dinheiro no caixa. Quem cadastra a cobrança não é necessariamente
    //   quem confirma o recebimento no extrato.
    // - `financeiro-estornar`: só o estorno, e ela já existia desde a Task
    //   18.4. Rota separada da baixa de propósito: é a única ação que altera
    //   caixa já fechado, e por isso não pode viajar junto da permissão que
    //   qualquer operador de cobrança recebe.
    // - `financeiro-ver`: a inadimplência e a previsão, que são leitura pura
    //   do mesmo dinheiro que o painel financeiro e o fluxo de caixa já
    //   mostram. Exigir permissão de título para *olhar* a inadimplência
    //   deixaria de fora quem hoje já vê o painel.
    //
    // O plano de contas fica com `financeiro-plano-de-contas`, separada das
    // demais: mudar a árvore de categorias reescreve todo relatório por
    // categoria, e não anda junto do cadastro de título.
    //
    // As rotas de parcela ficam sob `/parcelas/{parcela}` (quatro segmentos) e
    // não colidem com `/{titulo}/...` (três segmentos), então a ordem de
    // registro aqui não importa, diferente do caso de `/contracts/pendencias`.
    Route::middleware('module:financeiro')->group(function () {
        Route::get('/contas-a-receber', [ReceivableController::class, 'index'])
            ->middleware('permission:financeiro-titulos')
            ->name('contas-a-receber.index');
        Route::post('/contas-a-receber', [ReceivableController::class, 'store'])
            ->middleware('permission:financeiro-titulos')
            ->name('contas-a-receber.store');
        Route::post('/contas-a-receber/{titulo}/cancelar', [ReceivableController::class, 'cancelar'])
            ->middleware('permission:financeiro-titulos')
            ->name('contas-a-receber.cancelar');
        Route::post('/contas-a-receber/parcelas/{parcela}/baixar', [ReceivableController::class, 'baixar'])
            ->middleware('permission:financeiro-baixar')
            ->name('contas-a-receber.baixar');
        Route::post('/contas-a-receber/parcelas/{parcela}/estornar', [ReceivableController::class, 'estornar'])
            ->middleware('permission:financeiro-estornar')
            ->name('contas-a-receber.estornar');

        Route::get('/contas-a-pagar', [PayableController::class, 'index'])
            ->middleware('permission:financeiro-titulos')
            ->name('contas-a-pagar.index');
        Route::post('/contas-a-pagar', [PayableController::class, 'store'])
            ->middleware('permission:financeiro-titulos')
            ->name('contas-a-pagar.store');
        Route::post('/contas-a-pagar/{titulo}/cancelar', [PayableController::class, 'cancelar'])
            ->middleware('permission:financeiro-titulos')
            ->name('contas-a-pagar.cancelar');
        Route::post('/contas-a-pagar/{titulo}/valor', [PayableController::class, 'alterarValor'])
            ->middleware('permission:financeiro-titulos')
            ->name('contas-a-pagar.valor');
        Route::post('/contas-a-pagar/parcelas/{parcela}/baixar', [PayableController::class, 'baixar'])
            ->middleware('permission:financeiro-baixar')
            ->name('contas-a-pagar.baixar');
        Route::post('/contas-a-pagar/parcelas/{parcela}/estornar', [PayableController::class, 'estornar'])
            ->middleware('permission:financeiro-estornar')
            ->name('contas-a-pagar.estornar');

        Route::get('/financeiro/inadimplencia', [FinancialReportController::class, 'inadimplencia'])
            ->middleware('permission:financeiro-ver')
            ->name('financeiro.inadimplencia');
        Route::get('/financeiro/previsao', [FinancialReportController::class, 'previsao'])
            ->middleware('permission:financeiro-ver')
            ->name('financeiro.previsao');

        Route::get('/contas', [ChartOfAccountController::class, 'index'])
            ->middleware('permission:financeiro-plano-de-contas')
            ->name('contas.index');
        Route::post('/contas', [ChartOfAccountController::class, 'store'])
            ->middleware('permission:financeiro-plano-de-contas')
            ->name('contas.store');
        Route::put('/contas/{conta}', [ChartOfAccountController::class, 'update'])
            ->middleware('permission:financeiro-plano-de-contas')
            ->name('contas.update');
        Route::delete('/contas/{conta}', [ChartOfAccountController::class, 'destroy'])
            ->middleware('permission:financeiro-plano-de-contas')
            ->name('contas.destroy');
    });

    // `/daily-cash-balances` está listado como rota do módulo financeiro na
    // especificação desta task, mas `DailyCashBalanceController` não tem
    // nenhuma rota registrada neste arquivo hoje (o controller existe, órfão,
    // de antes deste plano). Nada para proteger aqui até essa rota nascer;
    // quando nascer, entra neste mesmo bloco com `module:financeiro`.

    // Rotas de Estoque, Lotes e Inventário (Plano 17, Task 17.7)
    //
    // Primeiro bloco do sistema sob `module:estoque`. O middleware fica no
    // grupo, e não repetido rota a rota como no financeiro: aqui o bloco nasce
    // inteiro nesta task, então a garantia estrutural ("rota nova dentro do
    // grupo já nasce bloqueada para tenant sem o módulo") vale mais que o
    // paralelismo com as rotas financeiras, que cresceram aos poucos e por isso
    // carregam o middleware uma a uma. `gatherMiddleware()` e
    // `php artisan route:list` mostram os dois empilhados do mesmo jeito.
    //
    // Três permissões, cada uma com um alcance diferente: `estoque-ver` para a
    // leitura do saldo e para a rastreabilidade (em incidente, a informação
    // precisa sair rápido, e por isso ela é a permissão mais larga das três);
    // `estoque-movimentar` para o que altera o razão (entrada, transferência,
    // descarte e o cadastro de lote, que carrega a entrada inicial); e
    // `estoque-inventariar` para a contagem física, que reescreve o saldo.
    // O técnico não recebe nenhuma das três nesta entrega: o consumo em campo é
    // a baixa automática pela OS (Task 17.4), que não passa por estas rotas.
    Route::middleware('module:estoque')->group(function () {
        Route::get('/estoque', [StockController::class, 'index'])
            ->middleware('permission:estoque-ver')
            ->name('estoque.index');
        Route::post('/estoque/entrada', [StockController::class, 'entrada'])
            ->middleware('permission:estoque-movimentar')
            ->name('estoque.entrada');
        Route::post('/estoque/transferencia', [StockController::class, 'transferencia'])
            ->middleware('permission:estoque-movimentar')
            ->name('estoque.transferencia');
        Route::post('/estoque/descarte', [StockController::class, 'descarte'])
            ->middleware('permission:estoque-movimentar')
            ->name('estoque.descarte');

        // Lotes. `/lotes/{lote}/rastreabilidade` tem três segmentos e não
        // colide com nenhuma das rotas de dois segmentos abaixo, então a ordem
        // de registro aqui não importa (diferente do caso de
        // `/contracts/pendencias`).
        Route::get('/lotes', [ProductBatchController::class, 'index'])
            ->middleware('permission:estoque-ver')
            ->name('lotes.index');
        Route::get('/lotes/{lote}/rastreabilidade', [ProductBatchController::class, 'rastreabilidade'])
            ->middleware('permission:estoque-ver')
            ->name('lotes.rastreabilidade');
        Route::get('/lotes/{lote}/rastreabilidade/pdf', [ProductBatchController::class, 'rastreabilidadePdf'])
            ->middleware('permission:estoque-ver')
            ->name('lotes.rastreabilidade.pdf');
        Route::post('/lotes', [ProductBatchController::class, 'store'])
            ->middleware('permission:estoque-movimentar')
            ->name('lotes.store');
        Route::put('/lotes/{lote}', [ProductBatchController::class, 'update'])
            ->middleware('permission:estoque-movimentar')
            ->name('lotes.update');
        Route::delete('/lotes/{lote}', [ProductBatchController::class, 'destroy'])
            ->middleware('permission:estoque-movimentar')
            ->name('lotes.destroy');

        // Inventário. Contar, finalizar e cancelar são o mesmo fluxo de
        // contagem física e ficam todos sob `estoque-inventariar`: quem conta
        // precisa poder fechar o que contou.
        Route::get('/inventarios', [InventoryController::class, 'index'])
            ->middleware('permission:estoque-inventariar')
            ->name('inventarios.index');
        Route::post('/inventarios', [InventoryController::class, 'store'])
            ->middleware('permission:estoque-inventariar')
            ->name('inventarios.store');
        Route::get('/inventarios/{inventario}', [InventoryController::class, 'show'])
            ->middleware('permission:estoque-inventariar')
            ->name('inventarios.show');
        Route::put('/inventarios/{inventario}/itens/{item}', [InventoryController::class, 'contar'])
            ->middleware('permission:estoque-inventariar')
            ->name('inventarios.itens.update');
        Route::post('/inventarios/{inventario}/finalizar', [InventoryController::class, 'finalizar'])
            ->middleware('permission:estoque-inventariar')
            ->name('inventarios.finalizar');
        Route::post('/inventarios/{inventario}/cancelar', [InventoryController::class, 'cancelar'])
            ->middleware('permission:estoque-inventariar')
            ->name('inventarios.cancelar');
    });

    // Cobrança recorrente (Plano 19, Task 19.6): emissão de boleto e Pix a
    // partir do título a receber, conciliação com o gateway e configuração
    // da credencial e da régua de cobrança.
    //
    // `module:cobranca_recorrente` no grupo inteiro, mesmo critério do bloco
    // de estoque acima: o bloco nasce inteiro nesta task, então a garantia
    // estrutural ("rota nova dentro do grupo já nasce bloqueada para tenant
    // sem o módulo") vale mais que o paralelismo com blocos antigos que
    // cresceram aos poucos.
    //
    // Três permissões, cada uma com um alcance diferente: `cobranca-ver`
    // cobre a listagem e a conciliação (leitura pura); `cobranca-emitir`
    // cobre emitir, reemitir, cancelar e reprocessar evento pendente (mexe na
    // cobrança em si, nunca na credencial); `cobranca-configurar` fica
    // reservada ao administrador (RolesAndPermissionsSeeder) para a
    // credencial do gateway e a régua - salvar um segredo que fala com o
    // dinheiro do tenant não pode ficar ao alcance de quem só emite boleto no
    // dia a dia.
    Route::middleware('module:cobranca_recorrente')->group(function () {
        Route::get('/cobrancas', [ChargeController::class, 'index'])
            ->middleware('permission:cobranca-ver')
            ->name('cobrancas.index');
        Route::post('/cobrancas', [ChargeController::class, 'store'])
            ->middleware('permission:cobranca-emitir')
            ->name('cobrancas.store');
        Route::post('/cobrancas/{cobranca}/reemitir', [ChargeController::class, 'reemitir'])
            ->middleware('permission:cobranca-emitir')
            ->name('cobrancas.reemitir');
        Route::post('/cobrancas/{cobranca}/cancelar', [ChargeController::class, 'cancelar'])
            ->middleware('permission:cobranca-emitir')
            ->name('cobrancas.cancelar');

        // Conciliação: relatório do período e a ação de reprocessar um
        // evento pendente. `{eventoId}` nunca é route-model binding - ver o
        // cabeçalho de `ChargeController` sobre `GatewayEvent` não ter escopo
        // automático de empresa.
        Route::get('/cobrancas/conciliacao', [ChargeController::class, 'conciliacao'])
            ->middleware('permission:cobranca-ver')
            ->name('cobrancas.conciliacao');
        Route::post('/cobrancas/conciliacao/eventos/{eventoId}/reprocessar', [ChargeController::class, 'reprocessarEvento'])
            ->whereNumber('eventoId')
            ->middleware('permission:cobranca-emitir')
            ->name('cobrancas.conciliacao.reprocessar');

        Route::get('/cobrancas/configuracao', [ChargeController::class, 'configuracao'])
            ->middleware('permission:cobranca-configurar')
            ->name('cobrancas.configuracao.index');
        Route::put('/cobrancas/configuracao', [ChargeController::class, 'configuracaoAtualizar'])
            ->middleware('permission:cobranca-configurar')
            ->name('cobrancas.configuracao.update');
        // Task 19.7: botão "validar credencial" com resposta imediata, sem
        // esperar a próxima emissão real para descobrir um token errado.
        Route::post('/cobrancas/configuracao/validar', [ChargeController::class, 'validarCredencial'])
            ->middleware('permission:cobranca-configurar')
            ->name('cobrancas.configuracao.validar');
    });

    // Assinatura eletrônica de contratos (Plano 26, Task 26.4).
    //
    // `module:assinatura_eletronica` no grupo inteiro, mesmo critério do bloco
    // de cobrança acima: o bloco nasce inteiro nesta task, e o módulo nasce
    // **desligado para todos** (`CatalogoDeModulos`), então nenhum tenant vê
    // botão de uma integração que ele não contratou.
    //
    // Duas permissões, com alcances deliberadamente diferentes:
    //
    // - `contrato-enviar-assinatura` cobre enviar, ver a situação, reenviar,
    //   cancelar e baixar o documento. Entra pelo prefixo `contrato-` que o
    //   papel comercial já recebe (RolesAndPermissionsSeeder), porque quem
    //   fecha a venda é quem manda o contrato para o cliente assinar — é o
    //   "coordenador" que a task menciona. Continua fora do papel leitura,
    //   que filtra por sufixo `-ver`.
    // - `assinatura-eletronica-configurar` fica reservada ao administrador
    //   (nenhum prefixo do seeder a alcança), mesmo critério de
    //   `cobranca-configurar`: a credencial guardada aqui assina contrato em
    //   nome da empresa, e isso não pode ficar ao alcance de quem só envia
    //   contrato no dia a dia.
    //
    // As rotas de configuração vêm **antes** das de `{contract}` de propósito?
    // Não é necessário: os caminhos não colidem (`/assinaturas/configuracao`
    // x `/contratos/{contract}/assinatura`). Ficam juntas por assunto.
    Route::middleware('module:assinatura_eletronica')->group(function () {
        Route::get('/contratos/{contract}/assinatura', [SignatureRequestController::class, 'show'])
            ->middleware('permission:contrato-enviar-assinatura')
            ->name('contratos.assinatura.show');
        Route::post('/contratos/{contract}/assinatura', [SignatureRequestController::class, 'store'])
            ->middleware('permission:contrato-enviar-assinatura')
            ->name('contratos.assinatura.store');

        // O pedido vem sempre aninhado no contrato, e não solto em
        // `/assinaturas/{id}`: o escopo global por empresa barra pedido de
        // outro tenant, mas só o vínculo explícito com o contrato da rota
        // impede que um id de pedido de OUTRO contrato da mesma empresa seja
        // cancelado por engano (ver `SignatureRequestController`).
        Route::post('/contratos/{contract}/assinatura/{assinatura}/reenviar', [SignatureRequestController::class, 'reenviar'])
            ->middleware('permission:contrato-enviar-assinatura')
            ->name('contratos.assinatura.reenviar');
        Route::post('/contratos/{contract}/assinatura/{assinatura}/cancelar', [SignatureRequestController::class, 'cancelar'])
            ->middleware('permission:contrato-enviar-assinatura')
            ->name('contratos.assinatura.cancelar');
        Route::get('/contratos/{contract}/assinatura/{assinatura}/documento', [SignatureRequestController::class, 'documento'])
            ->middleware('permission:contrato-enviar-assinatura')
            ->name('contratos.assinatura.documento');

        Route::get('/assinaturas/configuracao', [SignatureProviderConfigController::class, 'index'])
            ->middleware('permission:assinatura-eletronica-configurar')
            ->name('assinaturas.configuracao.index');
        Route::put('/assinaturas/configuracao', [SignatureProviderConfigController::class, 'update'])
            ->middleware('permission:assinatura-eletronica-configurar')
            ->name('assinaturas.configuracao.update');
        Route::post('/assinaturas/configuracao/validar', [SignatureProviderConfigController::class, 'validar'])
            ->middleware('permission:assinatura-eletronica-configurar')
            ->name('assinaturas.configuracao.validar');
        // Endpoint separado porque revela o webhook_token em claro — ver o
        // cabeçalho de `SignatureProviderConfigController::webhookUrl()`.
        Route::get('/assinaturas/configuracao/webhook', [SignatureProviderConfigController::class, 'webhookUrl'])
            ->middleware('permission:assinatura-eletronica-configurar')
            ->name('assinaturas.configuracao.webhook');
    });

    Route::middleware('module:nfse')->group(function () {
        Route::get('/fiscal/clientes/{cliente}', [FiscalClientController::class, 'show'])
            ->middleware('permission:fiscal-emitir')
            ->name('fiscal.clientes.show');
        Route::put('/fiscal/clientes/{cliente}', [FiscalClientController::class, 'update'])
            ->middleware('permission:fiscal-emitir')
            ->name('fiscal.clientes.update');

        Route::get('/notas', [ServiceInvoiceController::class, 'index'])
            ->middleware('permission:fiscal-ver')
            ->name('notas.index');
        Route::post('/notas', [ServiceInvoiceController::class, 'store'])
            ->middleware('permission:fiscal-emitir')
            ->name('notas.store');
        Route::get('/notas/pendencias', [ServiceInvoiceController::class, 'pendencias'])
            ->middleware('permission:fiscal-ver')
            ->name('notas.pendencias');
        Route::post('/notas/pendencias/reprocessar', [ServiceInvoiceController::class, 'reprocessarPendencias'])
            ->middleware('permission:fiscal-emitir')
            ->name('notas.pendencias.reprocessar');
        Route::post('/notas/{nota}/cancelar', [ServiceInvoiceController::class, 'cancelar'])
            ->middleware('permission:fiscal-cancelar')
            ->name('notas.cancelar');
        Route::post('/notas/{nota}/substituir', [ServiceInvoiceController::class, 'substituir'])
            ->middleware('permission:fiscal-cancelar')
            ->name('notas.substituir');
        Route::post('/notas/{nota}/reprocessar', [ServiceInvoiceController::class, 'reprocessar'])
            ->middleware('permission:fiscal-emitir')
            ->name('notas.reprocessar');
        Route::get('/notas/{nota}/pdf', [ServiceInvoiceController::class, 'pdf'])
            ->middleware('permission:fiscal-ver')
            ->name('notas.pdf');
        Route::get('/notas/{nota}/xml', [ServiceInvoiceController::class, 'xml'])
            ->middleware('permission:fiscal-ver')
            ->name('notas.xml');

        Route::get('/fiscal/configuracao', [FiscalConfigController::class, 'show'])
            ->middleware('permission:fiscal-configurar')
            ->name('fiscal.configuracao.show');
        Route::put('/fiscal/configuracao', [FiscalConfigController::class, 'update'])
            ->middleware('permission:fiscal-configurar')
            ->name('fiscal.configuracao.update');
    });

    // Monitoramento (Plano 21, Task 21.5): relatório consolidado por período,
    // geração/congelamento, publicação no portal, e a planta versionada do
    // endereço com o posicionamento dos dispositivos sobre ela.
    //
    // `module:monitoramento` no grupo inteiro, mesmo critério dos blocos de
    // estoque e cobrança recorrente acima: o bloco nasce inteiro nesta task,
    // então a garantia estrutural ("rota nova já nasce bloqueada para tenant
    // sem o módulo") vale mais que replicar o middleware rota a rota.
    //
    // Quatro permissões, cada uma com um alcance diferente:
    //
    // - `monitoramento-ver`: a visão ao vivo (`GET /monitoramento`), a lista
    //   e o detalhe de relatório já gerado. Leitura pura.
    // - `monitoramento-gerar`: congela o consolidado em `monitoring_reports`.
    //   Mexe em dado novo, nunca no que já foi entregue.
    // - `monitoramento-publicar`: alterna a visibilidade no portal do
    //   cliente. Mais restrita que `monitoramento-gerar` de propósito: quem
    //   gera o relatório não é necessariamente quem decide que ele já pode
    //   ir para a auditoria do cliente (ver `RolesAndPermissionsSeeder`).
    // - `planta-gerenciar` (já existe desde a Task 21.4): cobre toda ação de
    //   planta, inclusive a listagem - o catálogo não tem uma permissão
    //   "planta-ver" separada, decisão já tomada na Task 21.4.
    // Conformidade com a RDC 622/2022 (Plano 24, Task 24.5).
    //
    // `module:conformidade` no grupo inteiro, mesmo critério dos blocos de
    // monitoramento e roteirização: o módulo nasce desligado
    // (`CatalogoDeModulos`, `sempre_ativo => false`), e é o mesmo
    // interruptor que a rotina `conformidade:verificar-validades` consulta.
    // Isso é o que permite subir esta entrega (Deploy 3) sem tela nova e sem
    // aviso nenhum, preencher as validades reais do tenant com calma e só
    // então ligar (Deploy 4).
    //
    // Duas permissões:
    //
    // - `conformidade-ver`: o checklist e as pendências de documentação das
    //   execuções. Leitura, e por isso entra no papel `leitura` pelo sufixo
    //   `-ver` de `RolesAndPermissionsSeeder`.
    // - `conformidade-gerenciar`: recalcular o checklist sob demanda e o CRUD
    //   da referência normativa. Mais restrita de propósito: o texto legal
    //   que sai nos documentos emitidos vai para a mão de fiscal, e quem
    //   consulta o checklist antes de uma fiscalização não é necessariamente
    //   quem decide esse texto. Nenhum prefixo ou sufixo do seeder a alcança,
    //   então só administrador recebe, mesmo critério de
    //   `planta-gerenciar`/`cobranca-configurar`/`endereco-geo`.
    //
    // `/conformidade/referencias` vem declarado como rotas soltas, e não como
    // `Route::resource`, porque não existe tela de criação nem de edição em
    // página própria: o cadastro inteiro acontece em modal dentro do índice
    // (Task 24.6), então `create` e `edit` não teriam destino.
    Route::middleware('module:conformidade')->group(function () {
        Route::get('/conformidade', [ComplianceController::class, 'index'])
            ->middleware('permission:conformidade-ver')
            ->name('conformidade.index');

        Route::post('/conformidade/verificar', [ComplianceController::class, 'verificar'])
            ->middleware('permission:conformidade-gerenciar')
            ->name('conformidade.verificar');

        Route::get('/conformidade/pendencias-de-execucao', [ComplianceController::class, 'pendenciasDeExecucao'])
            ->middleware('permission:conformidade-ver')
            ->name('conformidade.pendencias-de-execucao');

        Route::get('/conformidade/referencias', [NormativeReferenceController::class, 'index'])
            ->middleware('permission:conformidade-gerenciar')
            ->name('conformidade.referencias.index');
        Route::post('/conformidade/referencias', [NormativeReferenceController::class, 'store'])
            ->middleware('permission:conformidade-gerenciar')
            ->name('conformidade.referencias.store');
        // `{referencia}` chega por route-model binding. `NormativeReference`
        // NÃO tem escopo global por empresa (a linha de company_id nulo é a
        // referência padrão da plataforma, e o escopo a esconderia), então o
        // binding sozinho não separa tenant nenhum: quem separa é
        // `NormativeReferenceController::garantirQueEDoTenant()`, chamado nas
        // duas rotas abaixo, com resposta 404 para registro de outra empresa.
        Route::put('/conformidade/referencias/{referencia}', [NormativeReferenceController::class, 'update'])
            ->middleware('permission:conformidade-gerenciar')
            ->name('conformidade.referencias.update');
        Route::delete('/conformidade/referencias/{referencia}', [NormativeReferenceController::class, 'destroy'])
            ->middleware('permission:conformidade-gerenciar')
            ->name('conformidade.referencias.destroy');

        // Validades dos documentos regulatórios da empresa (Task 24.6).
        // Fica em `/settings`, junto das demais configurações da empresa, e
        // não em `/conformidade`, porque é cadastro e não relatório: quem
        // chega aqui vem de "Configurações" com os documentos na mão. A
        // permissão é `conformidade-gerenciar`, e não `empresa-configurar`,
        // porque é o mesmo conjunto de dados que o checklist e os avisos
        // leem — quem pode mudar a validade é quem responde pela
        // conformidade.
        Route::get('/settings/validades', [ValidadesRegulatoriasController::class, 'edit'])
            ->middleware('permission:conformidade-gerenciar')
            ->name('settings.validades.edit');
        Route::post('/settings/validades', [ValidadesRegulatoriasController::class, 'update'])
            ->middleware('permission:conformidade-gerenciar')
            ->name('settings.validades.update');
    });

    Route::middleware('module:monitoramento')->group(function () {
        Route::get('/monitoramento', [MonitoringReportController::class, 'index'])
            ->middleware('permission:monitoramento-ver')
            ->name('monitoramento.index');

        // `/monitoramento/relatorios` (lista e geração) precisa vir antes de
        // `/monitoramento/relatorios/{monitoringReport}` só por clareza de
        // leitura - path fixo e path com parâmetro nunca colidem no Laravel,
        // diferente do caso "stats antes do resource" comentado no bloco
        // financeiro acima.
        Route::get('/monitoramento/relatorios', [MonitoringReportController::class, 'relatorios'])
            ->middleware('permission:monitoramento-ver')
            ->name('monitoramento.relatorios.index');
        Route::post('/monitoramento/relatorios', [MonitoringReportController::class, 'store'])
            ->middleware('permission:monitoramento-gerar')
            ->name('monitoramento.relatorios.store');
        Route::get('/monitoramento/relatorios/{monitoringReport}', [MonitoringReportController::class, 'show'])
            ->middleware('permission:monitoramento-ver')
            ->name('monitoramento.relatorios.show');
        Route::get('/monitoramento/relatorios/{monitoringReport}/pdf', [MonitoringReportController::class, 'pdf'])
            ->middleware('permission:monitoramento-ver')
            ->name('monitoramento.relatorios.pdf');
        Route::post('/monitoramento/relatorios/{monitoringReport}/publicar', [MonitoringReportController::class, 'publicar'])
            ->middleware('permission:monitoramento-publicar')
            ->name('monitoramento.relatorios.publicar');
        Route::post('/monitoramento/relatorios/{monitoringReport}/despublicar', [MonitoringReportController::class, 'despublicar'])
            ->middleware('permission:monitoramento-publicar')
            ->name('monitoramento.relatorios.despublicar');

        // Planta do endereço e posicionamento dos dispositivos (service e
        // FormRequests prontos desde a Task 21.4; `StoreFloorPlanRequest` e
        // `UpdateDevicePositionsRequest` já checam `planta-gerenciar` no
        // próprio `authorize()`, e a permissão aqui na rota é defesa em
        // profundidade, mesmo padrão do resto do sistema).
        Route::get('/enderecos/{address}/plantas', [FloorPlanController::class, 'index'])
            ->middleware('permission:planta-gerenciar')
            ->name('enderecos.plantas.index');
        // Editor de arrastar-soltar (Task 21.7): entrada pelo endereço, sem
        // planta escolhida ainda, ou por uma planta específica (o seletor do
        // próprio editor navega para a segunda ao trocar de planta).
        Route::get('/enderecos/{address}/plantas/editor', [FloorPlanController::class, 'editorPorEndereco'])
            ->middleware('permission:planta-gerenciar')
            ->name('enderecos.plantas.editor');
        Route::get('/plantas/{floorPlan}/editor', [FloorPlanController::class, 'editor'])
            ->middleware('permission:planta-gerenciar')
            ->name('plantas.editor');
        Route::post('/enderecos/{address}/plantas', [FloorPlanController::class, 'store'])
            ->middleware('permission:planta-gerenciar')
            ->name('enderecos.plantas.store');
        Route::post('/plantas/{floorPlan}/substituir', [FloorPlanController::class, 'substituir'])
            ->middleware('permission:planta-gerenciar')
            ->name('plantas.substituir');
        Route::put('/plantas/{floorPlan}/posicoes', [FloorPlanController::class, 'posicoes'])
            ->middleware('permission:planta-gerenciar')
            ->name('plantas.posicoes');
        Route::delete('/plantas/{floorPlan}/posicoes/{device}', [FloorPlanController::class, 'removerPosicao'])
            ->middleware('permission:planta-gerenciar')
            ->name('plantas.posicoes.remover');
        // `?versao=tecnico|cliente` (Task 21.6); sem o parâmetro, sai a
        // versão técnica, padrão desta rota de uso interno.
        Route::get('/plantas/{floorPlan}/croqui', [FloorPlanController::class, 'croqui'])
            ->middleware('permission:planta-gerenciar')
            ->name('plantas.croqui');
    });

    // Roteirização e rastreamento em campo (Plano 22, Task 22.5): roteiro do
    // dia por técnico, otimização, reordenação manual, mapa das paradas e
    // pendências/correção de coordenada de endereço.
    //
    // `module:roteirizacao` no grupo inteiro, mesmo critério dos blocos de
    // estoque, cobrança recorrente e monitoramento acima: o bloco nasce
    // inteiro nesta task, então a garantia estrutural ("rota nova já nasce
    // bloqueada para tenant sem o módulo") vale mais que replicar o
    // middleware rota a rota.
    //
    // Três permissões, cada uma com um alcance diferente:
    //
    // - `roteiro-ver`: o roteiro do dia e o mapa das paradas. Leitura pura.
    //   Termina em "-ver", então entra automaticamente no papel `leitura`
    //   pelo filtro genérico de `RolesAndPermissionsSeeder`; entra também,
    //   explicitamente, no papel `tecnico` - é o técnico que precisa ver o
    //   próprio roteiro (o `GET /roteiros` do painel cobre o mesmo caso de
    //   uso do `GET /api/app/roteiro` do aplicativo, para quem também usa o
    //   painel web).
    // - `roteiro-gerenciar`: reotimizar (`POST /roteiros/otimizar`) e
    //   reordenar manualmente (`PUT /roteiros/{route}/ordem`). Fica de fora
    //   do papel `tecnico` de propósito: decidir a ordem do dia inteiro
    //   (obra na rua, cliente que só recebe depois das 14h) é ação de quem
    //   coordena a operação, não do técnico individual executando uma
    //   parada de cada vez.
    // - `endereco-geo`: pendências de geocodificação, correção manual de
    //   coordenada e nova tentativa. Coordenada errada quebra o roteiro do
    //   dia inteiro (regra de negócio inegociável da Task 22.5), então fica
    //   reservada ao mesmo critério de `planta-gerenciar`/`cobranca-configurar`:
    //   nenhum prefixo ou sufixo de `RolesAndPermissionsSeeder` a alcança,
    //   só administrador recebe.
    Route::middleware('module:roteirizacao')->group(function () {
        // `/roteiros/painel` e `/enderecos/pendencias-geo/painel` (Task
        // 22.6): casca Inertia de `Roteiros/Index.vue` e
        // `Enderecos/PendenciasGeo.vue`. Buraco de integração da Task 22.5,
        // que só expôs os dois blocos abaixo como JSON puro - ver o
        // docblock de `RouteController::painel()` para o raciocínio
        // completo. Path próprio, não `/roteiros`/`/enderecos/pendencias-geo`
        // com `wantsJson()`: não sobrecarrega a rota JSON já testada
        // (`RouteEndpointTest`).
        Route::get('/roteiros/painel', [RouteController::class, 'painel'])
            ->middleware('permission:roteiro-ver')
            ->name('roteiros.painel');
        Route::get('/roteiros', [RouteController::class, 'index'])
            ->middleware('permission:roteiro-ver')
            ->name('roteiros.index');
        // `POST /roteiros/simular` (Task 22.6): mesma permissão de
        // `otimizar`, mesmo corpo (`OtimizarRotaRequest`), mas não persiste -
        // devolve a comparação "ordem atual vs. ordem otimizada" que a tela
        // mostra ANTES do usuário confirmar a otimização de verdade. Ver o
        // docblock de `RouteService::simularOtimizacao()`.
        Route::post('/roteiros/simular', [RouteController::class, 'simular'])
            ->middleware('permission:roteiro-gerenciar')
            ->name('roteiros.simular');
        Route::post('/roteiros/otimizar', [RouteController::class, 'otimizar'])
            ->middleware('permission:roteiro-gerenciar')
            ->name('roteiros.otimizar');
        Route::put('/roteiros/{route}/ordem', [RouteController::class, 'ordem'])
            ->middleware('permission:roteiro-gerenciar')
            ->name('roteiros.ordem');
        Route::get('/roteiros/{route}/mapa', [RouteController::class, 'mapa'])
            ->middleware('permission:roteiro-ver')
            ->name('roteiros.mapa');

        Route::get('/enderecos/pendencias-geo/painel', [AddressGeoController::class, 'painel'])
            ->middleware('permission:endereco-geo')
            ->name('enderecos.pendencias-geo.painel');
        Route::get('/enderecos/pendencias-geo', [AddressGeoController::class, 'pendencias'])
            ->middleware('permission:endereco-geo')
            ->name('enderecos.pendencias-geo');
        Route::put('/enderecos/{address}/coordenada', [AddressGeoController::class, 'definirCoordenada'])
            ->middleware('permission:endereco-geo')
            ->name('enderecos.coordenada.update');
        Route::post('/enderecos/{address}/geocodificar', [AddressGeoController::class, 'geocodificar'])
            ->middleware('permission:endereco-geo')
            ->name('enderecos.geocodificar');
    });

    // Frota e veículos (Plano 27, Task 27.4): cadastro do veículo,
    // abastecimento, manutenção e documentação, com o custo de deslocamento
    // entrando na margem da OS (Task 27.2) e os alertas de vencimento na
    // rotina `frota:verificar` (Task 27.3).
    //
    // `module:frota` no grupo inteiro, mesmo critério dos blocos de estoque,
    // cobrança recorrente, monitoramento e roteirização acima: o bloco nasce
    // inteiro nesta task, então a garantia estrutural ("rota nova já nasce
    // bloqueada para tenant sem o módulo") vale mais que replicar o middleware
    // rota a rota. O módulo nasce desligado para todos os tenants, e a rotina
    // de verificação nasce sem agendamento (ver `VerificarFrota`): ligar os
    // dois é decisão por tenant, depois de os veículos estarem cadastrados.
    //
    // Duas permissões, no par "-ver"/"-gerenciar" já usado por
    // `comodo-gerenciar`, `planta-gerenciar` e `servico-gerenciar`:
    //
    // - `frota-ver`: lista de veículos, ficha, histórico de abastecimento e de
    //   manutenção, documentos. Leitura pura. Termina em "-ver", então entra
    //   automaticamente no papel `leitura` pelo filtro genérico de
    //   `RolesAndPermissionsSeeder`.
    // - `frota-gerenciar`: tudo que escreve — cadastro do veículo, registro de
    //   abastecimento e de manutenção, documentos e a geração do título a
    //   pagar. Fica só com o administrador (nenhum prefixo ou sufixo do seeder
    //   a alcança), pelo mesmo critério de `planta-gerenciar`: registrar
    //   abastecimento move o hodômetro do veículo, que é o número de onde sai
    //   o custo por quilômetro que entra na margem financeira das OS.
    //
    // O técnico não recebe nenhuma das duas nesta entrega. Registrar o próprio
    // abastecimento pelo aplicativo é caso de uso real, mas exige a tela do
    // app (Plano 12) e uma decisão sobre até onde o técnico enxerga custo da
    // frota; abrir `frota-gerenciar` para ele agora daria, junto, acesso ao
    // cadastro e ao lançamento financeiro.
    Route::middleware('module:frota')->group(function () {
        Route::get('/veiculos', [VehicleController::class, 'index'])
            ->middleware('permission:frota-ver')
            ->name('frota.index');
        Route::post('/veiculos', [VehicleController::class, 'store'])
            ->middleware('permission:frota-gerenciar')
            ->name('frota.store');
        Route::get('/veiculos/{vehicle}', [VehicleController::class, 'show'])
            ->middleware('permission:frota-ver')
            ->name('frota.show');
        Route::put('/veiculos/{vehicle}', [VehicleController::class, 'update'])
            ->middleware('permission:frota-gerenciar')
            ->name('frota.update');
        Route::delete('/veiculos/{vehicle}', [VehicleController::class, 'destroy'])
            ->middleware('permission:frota-gerenciar')
            ->name('frota.destroy');

        Route::get('/veiculos/{vehicle}/abastecimentos', [RefuelingController::class, 'index'])
            ->middleware('permission:frota-ver')
            ->name('frota.abastecimentos.index');
        Route::post('/veiculos/{vehicle}/abastecimentos', [RefuelingController::class, 'store'])
            ->middleware('permission:frota-gerenciar')
            ->name('frota.abastecimentos.store');
        Route::post('/veiculos/{vehicle}/abastecimentos/{refueling}/titulo', [RefuelingController::class, 'gerarTitulo'])
            ->middleware('permission:frota-gerenciar')
            ->name('frota.abastecimentos.titulo');

        Route::get('/veiculos/{vehicle}/manutencoes', [VehicleMaintenanceController::class, 'index'])
            ->middleware('permission:frota-ver')
            ->name('frota.manutencoes.index');
        Route::post('/veiculos/{vehicle}/manutencoes', [VehicleMaintenanceController::class, 'store'])
            ->middleware('permission:frota-gerenciar')
            ->name('frota.manutencoes.store');
        Route::put('/veiculos/{vehicle}/manutencoes/{maintenance}', [VehicleMaintenanceController::class, 'update'])
            ->middleware('permission:frota-gerenciar')
            ->name('frota.manutencoes.update');
        Route::post('/veiculos/{vehicle}/manutencoes/{maintenance}/titulo', [VehicleMaintenanceController::class, 'gerarTitulo'])
            ->middleware('permission:frota-gerenciar')
            ->name('frota.manutencoes.titulo');

        Route::get('/veiculos/{vehicle}/documentos', [VehicleDocumentController::class, 'index'])
            ->middleware('permission:frota-ver')
            ->name('frota.documentos.index');
        Route::post('/veiculos/{vehicle}/documentos', [VehicleDocumentController::class, 'store'])
            ->middleware('permission:frota-gerenciar')
            ->name('frota.documentos.store');
        Route::post('/veiculos/{vehicle}/documentos/{document}', [VehicleDocumentController::class, 'update'])
            ->middleware('permission:frota-gerenciar')
            ->name('frota.documentos.update');
        Route::delete('/veiculos/{vehicle}/documentos/{document}', [VehicleDocumentController::class, 'destroy'])
            ->middleware('permission:frota-gerenciar')
            ->name('frota.documentos.destroy');
    });

    // Controle de EPI (Plano 28, Task 28.4): cadastro dos modelos de EPI com o
    // Certificado de Aprovação do fabricante e a ficha de entrega ao técnico,
    // que é o registro exigido pelo item 6.5.1 da NR-6 e a prova do empregador
    // em reclamatória trabalhista.
    //
    // `module:epi` no grupo inteiro, mesmo critério dos blocos de estoque,
    // cobrança recorrente, monitoramento, roteirização e frota acima: o bloco
    // nasce inteiro nesta task, então a garantia estrutural ("rota nova já nasce
    // bloqueada para tenant sem o módulo") vale mais que replicar o middleware
    // rota a rota. O módulo nasce desligado para todos os tenants, e a rotina de
    // verificação (`epi:verificar`, Task 28.3) nasce sem agendamento: ligar os
    // dois é decisão por tenant, e só depois de os modelos de EPI estarem
    // cadastrados com CA e validade.
    //
    // Quatro permissões, e não duas, pelo alcance de cada ação (o raciocínio
    // completo está em `SyncPermissions::catalogo()`):
    //
    // - `epi-ver`: listagem do cadastro, ficha do técnico e a relação de
    //   entregas. Leitura pura; termina em "-ver", então entra no papel
    //   `leitura` pelo filtro genérico de `RolesAndPermissionsSeeder`.
    // - `epi-gerenciar`: o cadastro do modelo de EPI, número do CA e validade
    //   incluídos — é daqui que sai a cópia do CA gravada em cada entrega
    //   futura.
    // - `epi-entregar`: registrar a entrega, colher a assinatura de recebimento
    //   e registrar a devolução.
    // - `epi-estornar`: **altera prova documental**, e por isso é separada.
    //
    // Não existe rota de exclusão de entrega, e não pode passar a existir: a
    // entrega é documento oponível, com arquivamento recomendado de 20 anos, e
    // também não se edita. Erro vira estorno com motivo, e a linha continua na
    // ficha e na extração por período, marcada como estornada. A ausência da
    // rota é parte da regra, não esquecimento — o `PpeDeliveryService` sequer
    // tem método de exclusão.
    //
    // Ordem de registro: as duas rotas com segmento literal (`/epis/entregas` e
    // `/epis/tecnicos/...`) vêm antes de `/epis/{epi}`, senão o curinga as
    // engoliria e "entregas" viraria um id de EPI que nunca existe.
    Route::middleware('module:epi')->group(function () {
        // Ficha de entrega ao técnico.
        Route::get('/epis/entregas', [PpeDeliveryController::class, 'index'])
            ->middleware('permission:epi-ver')
            ->name('epi.entregas.index');
        Route::post('/epis/entregas', [PpeDeliveryController::class, 'store'])
            ->middleware('permission:epi-entregar')
            ->name('epi.entregas.store');
        Route::post('/epis/entregas/{delivery}/assinatura', [PpeDeliveryController::class, 'assinar'])
            ->middleware('permission:epi-entregar')
            ->name('epi.entregas.assinar');
        Route::post('/epis/entregas/{delivery}/devolucao', [PpeDeliveryController::class, 'devolver'])
            ->middleware('permission:epi-entregar')
            ->name('epi.entregas.devolver');
        Route::post('/epis/entregas/{delivery}/estorno', [PpeDeliveryController::class, 'estornar'])
            ->middleware('permission:epi-estornar')
            ->name('epi.entregas.estornar');

        Route::get('/epis/tecnicos/{technician}', [PpeDeliveryController::class, 'ficha'])
            ->middleware('permission:epi-ver')
            ->name('epi.tecnicos.ficha');

        // Documentos da Task 28.5. A extração por período é o que o item
        // 6.5.1.1 da NR-6 exige para aceitar o registro eletrônico; a ficha em
        // PDF é o documento que se apresenta em fiscalização. As duas são
        // leitura e ficam sob `epi-ver`.
        //
        // `/epis/extracao` precisa vir antes de `/epis/{epi}`, senão o curinga
        // do cadastro engole o segmento literal.
        Route::get('/epis/extracao', [PpeDeliveryController::class, 'extracao'])
            ->middleware('permission:epi-ver')
            ->name('epi.extracao');
        Route::get('/epis/tecnicos/{technician}/ficha-pdf', [PpeDeliveryController::class, 'fichaPdf'])
            ->middleware('permission:epi-ver')
            ->name('epi.tecnicos.ficha-pdf');

        // EPI exigido por serviço (Plano 29, Task 29.3). É a declaração de
        // escritório do que a execução de cada serviço exige vestir, e a origem
        // de tudo que o Plano 29 faz depois: a etapa de confirmação que a carga
        // do dia leva ao aplicativo, o que a `ConfirmacaoDeEpiService` aceita
        // gravar da fila offline e o que o checklist da RDC 622/2022 cobra.
        //
        // As quatro rotas ficam sob `permission:epi-gerenciar`, leitura
        // incluída, e **nenhuma permissão nova entrou no catálogo nesta task**:
        // declarar o que a empresa passa a exigir em campo é configuração do
        // mesmo cadastro que `epi-gerenciar` já governa (modelo de EPI, número
        // do CA, validade), e não a leitura operacional que `epi-ver` abre.
        //
        // `/epis/exigencias` precisa vir antes de `/epis/{epi}`, pelo mesmo
        // motivo de `/epis/entregas`, `/epis/tecnicos` e `/epis/extracao`: o
        // curinga do cadastro engoliria o segmento literal, e "exigencias"
        // viraria um id de EPI que nunca existe.
        //
        // Diferente da entrega (documento oponível, que só admite estorno), a
        // exigência é cadastro de configuração e tem exclusão de verdade. A
        // remoção não apaga confirmação nenhuma já registrada em OS: o que o
        // técnico confirmou vestir em campo aconteceu.
        Route::get('/epis/exigencias', [ServicePpeRequirementController::class, 'index'])
            ->middleware('permission:epi-gerenciar')
            ->name('epi.exigencias.index');
        Route::post('/epis/exigencias', [ServicePpeRequirementController::class, 'store'])
            ->middleware('permission:epi-gerenciar')
            ->name('epi.exigencias.store');
        Route::put('/epis/exigencias/{requirement}', [ServicePpeRequirementController::class, 'update'])
            ->middleware('permission:epi-gerenciar')
            ->whereNumber('requirement')
            ->name('epi.exigencias.update');
        Route::delete('/epis/exigencias/{requirement}', [ServicePpeRequirementController::class, 'destroy'])
            ->middleware('permission:epi-gerenciar')
            ->whereNumber('requirement')
            ->name('epi.exigencias.destroy');

        // Cadastro do modelo de EPI.
        Route::get('/epis', [PersonalProtectiveEquipmentController::class, 'index'])
            ->middleware('permission:epi-ver')
            ->name('epi.index');
        Route::post('/epis', [PersonalProtectiveEquipmentController::class, 'store'])
            ->middleware('permission:epi-gerenciar')
            ->name('epi.store');
        Route::get('/epis/{epi}', [PersonalProtectiveEquipmentController::class, 'show'])
            ->middleware('permission:epi-ver')
            ->name('epi.show');
        Route::put('/epis/{epi}', [PersonalProtectiveEquipmentController::class, 'update'])
            ->middleware('permission:epi-gerenciar')
            ->name('epi.update');
        Route::post('/epis/{epi}/inativar', [PersonalProtectiveEquipmentController::class, 'inativar'])
            ->middleware('permission:epi-gerenciar')
            ->name('epi.inativar');
        Route::post('/epis/{epi}/reativar', [PersonalProtectiveEquipmentController::class, 'reativar'])
            ->middleware('permission:epi-gerenciar')
            ->name('epi.reativar');
        // A exclusão vale só para o cadastro que nunca foi entregue. Com
        // entrega registrada, o `PpeService` recusa e o controller devolve 422
        // apontando a inativação: a ficha antiga aponta para este cadastro.
        Route::delete('/epis/{epi}', [PersonalProtectiveEquipmentController::class, 'destroy'])
            ->middleware('permission:epi-gerenciar')
            ->name('epi.destroy');

        // A ficha em PDF (`epi.tecnicos.ficha-pdf`) e a extração por período
        // (`epi.extracao`) entram neste grupo, sob `permission:epi-ver`, na
        // Task 28.5.
    });

    // Módulos ainda sem nenhuma rota no sistema (`portal_cliente`,
    // `app_tecnico`, `laudo_ia`): seguem só declarados em
    // `CatalogoDeModulos` e ganham `module:<chave>` na task que implementar
    // o respectivo plano.
    //
    // `notificacoes` (Plano 14) é exceção diferente das demais: já tem rota
    // registrada (grupo "Rotas de Notificações", abaixo) mas ainda sem
    // `module:notificacoes` - fica para quando o módulo entrar no escopo de
    // uma task dedicada, para não gatilhar bloqueio sem o resto do fluxo
    // (telas, aviso de plano) pronto. `monitoramento` e `roteirizacao`
    // deixaram de ser exceção, cada um na task que deu a ele a primeira rota:
    // nasceram já com o respectivo `module:<chave>` desde o primeiro deploy,
    // ver os blocos acima.

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

    // Rotas de Comissão, Meta e Indicadores Comerciais (Plano 23, Task 23.6)
    //
    // Sem `module:<chave>` neste bloco: `CatalogoDeModulos` ainda não tem um
    // módulo "comercial"/"comissões" (mesmo caso de `notificacoes` até a task
    // que lhe desse módulo próprio - ver o comentário logo acima, nas rotas
    // de Endereço/Roteirização). Cada rota carrega só a permissão do
    // catálogo desta task.
    //
    // Não há `GET /comissoes/{comissao}` (a Task 23.6 não pede exibição de
    // uma comissão avulsa, só a listagem por competência, logo abaixo), então
    // `/comissoes/regras` não corre o risco de colisão que `/contracts/pendencias`
    // tem com o `show` do resource de contrato.
    Route::get('/comissoes/regras', [CommissionRuleController::class, 'index'])->middleware('permission:comissoes-apurar')->name('comissoes.regras.index');
    Route::post('/comissoes/regras', [CommissionRuleController::class, 'store'])->middleware('permission:comissoes-apurar')->name('comissoes.regras.store');
    Route::put('/comissoes/regras/{regra}', [CommissionRuleController::class, 'update'])->middleware('permission:comissoes-apurar')->name('comissoes.regras.update');
    Route::delete('/comissoes/regras/{regra}', [CommissionRuleController::class, 'destroy'])->middleware('permission:comissoes-apurar')->name('comissoes.regras.destroy');

    // Listagem por competência: a restrição a "só a própria comissão" salvo
    // `comissoes-ver-todas` é feita dentro do controller
    // (`CommissionController::escopoDoUsuario()`), não pelo middleware - é
    // autorização a nível de dado, não de rota.
    Route::get('/comissoes', [CommissionController::class, 'index'])->middleware('permission:comissoes-ver')->name('comissoes.index');
    Route::post('/comissoes/apurar', [CommissionController::class, 'apurar'])->middleware('permission:comissoes-apurar')->name('comissoes.apurar');
    Route::post('/comissoes/{comissao}/fechar', [CommissionController::class, 'fechar'])->middleware('permission:comissoes-fechar')->name('comissoes.fechar');
    // `comissoes-reabrir` já existe desde a Task 23.2 e é conferida de novo
    // dentro do próprio `CommissionClosingService::reabrir()`: defesa em
    // profundidade, mesmo critério de `financeiro-estornar`/`pagamento-reabrir`.
    Route::post('/comissoes/{comissao}/reabrir', [CommissionController::class, 'reabrir'])->middleware('permission:comissoes-reabrir')->name('comissoes.reabrir');
    // `pagar` reaproveita `comissoes-fechar`: marcar como paga só é possível
    // a partir de uma comissão já fechada, e é a continuação natural do
    // mesmo fluxo de encerramento, não uma ação nova (ver o comentário da
    // permissão em `SyncPermissions::catalogo()`).
    Route::post('/comissoes/{comissao}/pagar', [CommissionController::class, 'pagar'])->middleware('permission:comissoes-fechar')->name('comissoes.pagar');

    // Metas: toda escrita (CRUD e lote) exige `meta-gerenciar`;
    // `acompanhamento` é liberada a qualquer autenticado, com a própria
    // restrição de "só a própria meta" dentro do controller
    // (`GoalController::acompanhamento()`), mesmo padrão de
    // `CommissionController::index()`.
    Route::get('/metas/acompanhamento', [GoalController::class, 'acompanhamento'])->name('metas.acompanhamento');
    Route::get('/metas', [GoalController::class, 'index'])->middleware('permission:meta-gerenciar')->name('metas.index');
    Route::post('/metas', [GoalController::class, 'store'])->middleware('permission:meta-gerenciar')->name('metas.store');
    Route::post('/metas/lote', [GoalController::class, 'storeLote'])->middleware('permission:meta-gerenciar')->name('metas.lote');
    Route::put('/metas/{meta}', [GoalController::class, 'update'])->middleware('permission:meta-gerenciar')->name('metas.update');
    Route::delete('/metas/{meta}', [GoalController::class, 'destroy'])->middleware('permission:meta-gerenciar')->name('metas.destroy');

    // Painel comercial: conversão, ticket médio e tempo até o fechamento
    // (Task 23.3/23.6). Gate por `orcamento-ver` - ver o docblock de
    // `IndicadoresComerciaisController` para o motivo de reaproveitar essa
    // permissão em vez de criar uma nova.
    Route::get('/comercial/indicadores', [IndicadoresComerciaisController::class, 'index'])->middleware('permission:orcamento-ver')->name('comercial.indicadores');

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

    // Solicitações de atendimento abertas pelo cliente no portal (Plano 15,
    // Task 15.5). solicitacao-responder cobre tanto responder quanto mudar a
    // situação: as duas são ação de atendimento da mesma fila, e não faz
    // sentido quem responde não poder marcar como resolvida (ou o inverso).
    Route::get('/solicitacoes', [ClientRequestAdminController::class, 'index'])
        ->middleware('permission:solicitacao-ver')
        ->name('solicitacoes.index');
    Route::post('/solicitacoes/{solicitacao}/responder', [ClientRequestAdminController::class, 'responder'])
        ->middleware('permission:solicitacao-responder')
        ->name('solicitacoes.responder');
    Route::post('/solicitacoes/{solicitacao}/situacao', [ClientRequestAdminController::class, 'situacao'])
        ->middleware('permission:solicitacao-responder')
        ->name('solicitacoes.situacao');

    // Pedidos de horário do agendamento online (Plano 16, Task 16.4).
    // agendamento-responder cobre tanto confirmar quanto recusar: as duas são
    // resposta da empresa ao mesmo pedido, mesmo critério de
    // solicitacao-responder acima.
    Route::get('/solicitacoes-de-horario', [AppointmentRequestController::class, 'index'])
        ->middleware('permission:agendamento-ver')
        ->name('solicitacoes-de-horario.index');
    Route::post('/solicitacoes-de-horario/{pedido}/confirmar', [AppointmentRequestController::class, 'confirmar'])
        ->middleware('permission:agendamento-responder')
        ->name('solicitacoes-de-horario.confirmar');
    Route::post('/solicitacoes-de-horario/{pedido}/recusar', [AppointmentRequestController::class, 'recusar'])
        ->middleware('permission:agendamento-responder')
        ->name('solicitacoes-de-horario.recusar');

    // Painel de satisfação (Plano 16, Task 16.7): indicadores por período,
    // técnico e tipo de serviço, e a fila de notas baixas com pendência de
    // contato. Mesmas permissões da Task 16.4 - ver o docblock do
    // controller.
    Route::get('/satisfacao', [SatisfactionSurveyController::class, 'index'])
        ->middleware('permission:agendamento-ver')
        ->name('satisfacao.index');
    Route::post('/satisfacao/{pesquisa}/contato-feito', [SatisfactionSurveyController::class, 'marcarContatoFeito'])
        ->middleware('permission:agendamento-responder')
        ->name('satisfacao.contato-feito');

    // Configurações da Empresa
    Route::get('/settings/company', [\App\Http\Controllers\CompanyController::class, 'edit'])->middleware('permission:empresa-configurar')->name('settings.company.edit');
    Route::post('/settings/company', [\App\Http\Controllers\CompanyController::class, 'update'])->middleware('permission:empresa-configurar')->name('settings.company.update');

    // Configuração de disponibilidade e agendamento online (Plano 16, Task
    // 16.7): dias de atendimento, teto por período, antecedência, janela,
    // chave da página pública e o slug dela.
    Route::get('/settings/disponibilidade', [CompanyAvailabilitySettingController::class, 'edit'])
        ->middleware('permission:empresa-configurar')
        ->name('settings.disponibilidade.edit');
    Route::put('/settings/disponibilidade', [CompanyAvailabilitySettingController::class, 'update'])
        ->middleware('permission:empresa-configurar')
        ->name('settings.disponibilidade.update');

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

    // Convites de usuário (Plano 8, Task 8.4).
    //
    // As quatro exigem `usuario-criar`, inclusive a listagem: convidar é criar
    // usuário por outro caminho, e quem não pode criar não precisa da tela.
    // Nenhuma permissão nova nasce aqui, então não há nada a acrescentar em
    // `SyncPermissions`.
    //
    // `settings.convites.index` é o nome que a trilha de primeiros passos já
    // aponta desde a Task 8.5 (`PassosDeOnboarding`, passo `equipe`), e é onde
    // a tela da Task 8.8 vai morar.
    //
    // `{convite}` é resolvido com o escopo global de `Invitation` ativo:
    // convite de outra empresa não é encontrado e a resposta é 404, sem
    // verificação extra em cada ação.
    //
    // O par cancelar/reenviar é POST e DELETE, nunca GET: os dois mudam estado
    // (reenviar troca o token e invalida o link anterior), e ação disparada por
    // prefetch do navegador ou por varredura de link não pode derrubar o
    // convite de ninguém.
    Route::get('/settings/convites', [InvitationController::class, 'index'])->middleware('permission:usuario-criar')->name('settings.convites.index');
    Route::post('/settings/convites', [InvitationController::class, 'store'])->middleware('permission:usuario-criar')->name('settings.convites.store');
    Route::delete('/settings/convites/{convite}', [InvitationController::class, 'destroy'])->middleware('permission:usuario-criar')->name('settings.convites.destroy');
    Route::post('/settings/convites/{convite}/reenviar', [InvitationController::class, 'reenviar'])->middleware('permission:usuario-criar')->name('settings.convites.reenviar');

    // Rota de Histórico de Auditoria
    // {tipo} é um apelido de uma lista fechada de models auditados (ver
    // AuditoriaService::MODELS_AUDITADOS), nunca um FQCN vindo do cliente.
    Route::get('/audit-logs/{tipo}/{id}', [AuditLogController::class, 'index'])
        ->where(['tipo' => '[a-z-]+', 'id' => '[0-9]+'])
        ->middleware('permission:auditoria-ver')
        ->name('audit-logs.index');

    // Laudo assistido por IA (Plano 25, Task 25.5): geração do rascunho de
    // parecer, revisão pelo responsável técnico, descarte e sugestão de preço
    // pelo histórico da própria empresa.
    //
    // `module:laudo_ia` no grupo inteiro, mesmo critério dos blocos de
    // estoque, cobrança recorrente, monitoramento e roteirização acima. O
    // módulo nasce desligado para todos, então nenhum tenant alcança estas
    // rotas até a liberação explícita.
    //
    // Duas permissões, e a separação entre elas é a regra central do plano:
    //
    // - `ia-gerar`: produz rascunho e pede sugestão de preço. Rascunho não
    //   emite documento nenhum, então a permissão é larga.
    // - `ia-revisar`: aprova o texto e libera a emissão. Quem aprova assume a
    //   responsabilidade profissional pelo laudo, e por isso a permissão fica
    //   com administrador e responsável técnico (ver `RolesAndPermissionsSeeder`).
    //
    // Descartar exige `ia-revisar` junto com gerar-de-novo: descartar é
    // decidir que aquele texto não serve, que é julgamento de quem revisa.
    Route::middleware('module:laudo_ia')->group(function () {
        Route::post('/ia/pareceres', [AiDraftController::class, 'store'])
            ->middleware('permission:ia-gerar')
            ->name('ia.pareceres.store');
        Route::get('/ia/rascunhos/{aiDraft}', [AiDraftController::class, 'show'])
            ->middleware('permission:ia-gerar|ia-revisar')
            ->name('ia.rascunhos.show');
        Route::put('/ia/rascunhos/{aiDraft}/revisar', [AiDraftController::class, 'revisar'])
            ->middleware('permission:ia-revisar')
            ->name('ia.rascunhos.revisar');
        Route::post('/ia/rascunhos/{aiDraft}/descartar', [AiDraftController::class, 'descartar'])
            ->middleware('permission:ia-revisar')
            ->name('ia.rascunhos.descartar');
        Route::post('/ia/precos/sugerir', [AiDraftController::class, 'sugerirPreco'])
            ->middleware('permission:ia-gerar')
            ->name('ia.precos.sugerir');
    });

    // Logout
    // Sem permissão: sair do sistema não pode depender de papel.
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});

// Área da plataforma.
//
// Tudo acima deste ponto é o mundo das empresas: cada requisição enxerga apenas
// o tenant do usuário autenticado. Daqui para baixo é o mundo do super admin,
// que não pertence a empresa nenhuma e enxerga todos os tenants ao mesmo tempo.
// Os dois grupos ficam separados de propósito, e nenhuma rota de empresa entra
// aqui dentro.
//
// Decisão de autenticação, registrada na Task 5.3 do Plano 5: mesmo formulário
// de login, mesma tabela `users`, redirecionamento diferente depois de entrar.
// Um guard separado dobraria o custo de manutenção de sessão, recuperação de
// senha e auditoria sem ganho real de isolamento. O isolamento vem do
// middleware `platform.admin` (`EnsurePlatformAdmin`), que exige
// `is_platform_admin` e liga `TenantAtual::semEscopo()` só durante estas
// requisições.
//
// Enquanto não assume um tenant, o super admin continua vendo a empresa do
// próprio `company_id` na área das empresas. É intencional: a ação explícita de
// assumir outro tenant é a Task 5.5.
Route::prefix('plataforma')->name('plataforma.')->middleware(['auth', 'platform.admin'])->group(function () {
    // Modo suporte: entrar em um tenant e sair dele (Task 5.5).
    //
    // As duas são POST porque mudam estado e ficam em auditoria. Assumir tenant
    // por link seria acesso a dado de cliente real disparado por um GET, ou
    // seja, por prefetch do navegador, por crawler ou por uma imagem em e-mail.
    //
    // `{company}` é o binding padrão de `App\Models\Company`, que não usa
    // `BelongsToCompany` e por isso encontra qualquer empresa. É o comportamento
    // desejado: escolher entre todos os tenants é o propósito desta área.
    Route::post('/tenants/{company}/assumir', [AssumirTenantController::class, 'assumir'])->name('tenants.assumir');

    // Sem `{company}`: sai de onde estiver, e a empresa vem da sessão. Um id na
    // URL só criaria a chance de a saída não bater com a entrada.
    Route::post('/liberar', [AssumirTenantController::class, 'liberar'])->name('liberar');

    // CRUD de tenants e ações de ciclo de vida (Task 5.7).
    //
    // Mesmo `{company}` das rotas de assumir/liberar acima: `Company` não usa
    // `BelongsToCompany`, então o binding sempre enxerga qualquer tenant, em
    // qualquer rota deste grupo. Sem `destroy`: `TenantService` não exclui
    // tenant de propósito (ver o cabeçalho do Service), então não existe
    // endpoint para isso.
    Route::resource('tenants', PlataformaTenantController::class)
        ->parameters(['tenants' => 'company'])
        ->except(['destroy']);

    // POST porque mudam estado (situação do tenant) e ficam em auditoria, mesmo
    // critério das rotas de assumir/liberar.
    Route::post('/tenants/{company}/suspender', [PlataformaTenantController::class, 'suspender'])->name('tenants.suspender');
    Route::post('/tenants/{company}/reativar', [PlataformaTenantController::class, 'reativar'])->name('tenants.reativar');

    // CRUD do catálogo de planos comerciais (Task 5.7). `destroy` recusa plano
    // com tenant vinculado (ver `PlanController::destroy`); desativar
    // (`ativo = false`) é o caminho normal para tirar um plano de circulação.
    Route::resource('planos', PlataformaPlanController::class)
        ->parameters(['planos' => 'plan']);

    // Painel geral e uso por tenant (Task 5.8).
    //
    // `/plataforma` sem mais nada é a página inicial da área, para onde o
    // login do super admin redireciona (Task 5.3). Precisa vir registrada
    // aqui dentro do grupo para herdar `platform.admin`; sem ela, o painel
    // inteiro cai em 404 assim que alguém entra.
    Route::get('/', [PlataformaDashboardController::class, 'index'])->name('dashboard');

    // Uso de um tenant específico: mês corrente ao vivo, histórico e
    // percentual do teto do plano. Fora do `Route::resource('tenants', ...)`
    // acima porque não é uma ação de CRUD do cadastro, é uma tela de
    // consulta à parte.
    Route::get('/tenants/{company}/uso', [PlataformaUsageController::class, 'show'])->name('tenants.uso');

    // Gestão de módulos por plano e por tenant (Plano 6, Task 6.7): liga o
    // catálogo de módulos ao que cada plano libera e à exceção pontual de
    // cada tenant. Nenhuma delas apaga dado de domínio, só a visibilidade do
    // módulo (ver o cabeçalho de `ModuleService`).
    //
    // `{plano}`/`{empresa}` (e não `{plan}`/`{company}`, como no restante
    // deste grupo) porque `ModuleController` usa esses nomes de variável em
    // português: o binding implícito exige que o nome do parâmetro na rota
    // bata com o nome do argumento no método.
    Route::get('/modulos', [PlataformaModuleController::class, 'index'])->name('modulos.index');

    Route::get('/planos/{plano}/modulos', [PlataformaModuleController::class, 'porPlano'])->name('planos.modulos.edit');
    Route::put('/planos/{plano}/modulos', [PlataformaModuleController::class, 'salvarPlano'])->name('planos.modulos.update');

    Route::get('/tenants/{empresa}/modulos', [PlataformaModuleController::class, 'porTenant'])->name('tenants.modulos.index');
    Route::post('/tenants/{empresa}/modulos/liberar', [PlataformaModuleController::class, 'liberarNoTenant'])->name('tenants.modulos.liberar');
    Route::post('/tenants/{empresa}/modulos/bloquear', [PlataformaModuleController::class, 'bloquearNoTenant'])->name('tenants.modulos.bloquear');
    Route::post('/tenants/{empresa}/modulos/remover', [PlataformaModuleController::class, 'removerRegraNoTenant'])->name('tenants.modulos.remover');

    // Painel de receita da plataforma (Plano 7, Task 7.8): contadores de
    // assinatura, receita recorrente mensal, faturas em aberto e a série de
    // cancelamentos dos últimos 6 meses. Sem permissão própria: `platform.admin`
    // já é a barreira, mesmo critério do painel geral acima.
    Route::get('/receita', [PlataformaReceitaController::class, 'index'])->name('receita.index');
});
