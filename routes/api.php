<?php

use App\Http\Controllers\Api\AppAuthController;
use App\Http\Controllers\Api\AppSyncDownloadController;
use App\Http\Controllers\Api\AppSyncUploadController;
use App\Http\Controllers\RouteController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Registradas em bootstrap/app.php sob o prefixo "/api" e o grupo de
| middleware "api" (sem sessão, sem CSRF). Hoje só existe o aplicativo do
| técnico, sob "/app" (Plano 12).
*/

// Aplicativo do técnico (Plano 12: fundação offline).
//
// Arquivo disputado entre as Tasks 12.2, 12.3 e 12.5, nesta ordem: 12.2 monta
// o grupo e a autenticação; 12.3 acrescenta a carga do dia; 12.5 acrescenta a
// sincronização em lote e o envio de foto. Todas entram dentro do grupo
// "auth:sanctum" abaixo, que já resolve o tenant (o usuário autenticado por
// token carrega o `company_id`, e `TenantAtual` lê dele).
Route::prefix('app')->name('app.')->group(function () {
    // Único endpoint público do grupo: sem token ainda não há o que resolver.
    Route::post('/login', [AppAuthController::class, 'login'])->name('login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AppAuthController::class, 'logout'])->name('logout');
        Route::get('/sessoes', [AppAuthController::class, 'sessoes'])->name('sessoes.index');
        Route::delete('/sessoes/{id}', [AppAuthController::class, 'revogarSessao'])
            ->whereNumber('id')
            ->name('sessoes.destroy');

        // Carga do dia do técnico (Task 12.3): ordens de serviço do período
        // pedido mais os catálogos de apoio, sem nenhum campo financeiro.
        Route::get('/carga', [AppSyncDownloadController::class, 'carga'])->name('carga');

        // Roteiro do dia do técnico logado (Plano 22, Task 22.5): ordem,
        // coordenadas e chegada estimada de cada parada. Além desta rota (útil
        // para um pedido pontual, ex. "atualizar o roteiro de hoje"), o mesmo
        // resumo entra dentro da CARGA do dia acima
        // (`AppDayLoadService::carregar()`, chave `roteiro`), porque é de lá
        // que o técnico sem sinal em campo lê o próprio roteiro offline - sem
        // isso, esta rota sozinha não ajudaria em nada assim que o aparelho
        // perdesse a conexão.
        Route::get('/roteiro', [RouteController::class, 'appRoteiro'])->name('roteiro');

        // Sincronização em lote e envio de foto (Task 12.5). O limite de
        // taxa é por token (RateLimiter "app-sincronizacao", registrado em
        // AppServiceProvider), não por IP: o mesmo IP de rede carrega vários
        // aparelhos, e o mesmo aparelho troca de IP no meio do dia.
        Route::post('/sincronizar', [AppSyncUploadController::class, 'sincronizar'])
            ->middleware('throttle:app-sincronizacao')
            ->name('sincronizar');

        Route::post('/fotos', [AppSyncUploadController::class, 'fotos'])
            ->middleware('throttle:app-sincronizacao')
            ->name('fotos');

        Route::get('/conflitos', [AppSyncUploadController::class, 'conflitos'])->name('conflitos.index');

        Route::post('/conflitos/{conflito}/resolver', [AppSyncUploadController::class, 'resolverConflito'])
            ->whereNumber('conflito')
            ->name('conflitos.resolver');
    });
});
