<?php

use App\Http\Controllers\AvisoAtualizacaoController;
use Illuminate\Support\Facades\Route;

// Aviso de atualização ao entrar (pop-up "O sistema foi atualizado").
//
// Mesmo envelope do grupo autenticado de routes/web.php (`auth` +
// `tenant.ativo`), sem `permission:`: todo funcionário logado consulta os
// próprios avisos, e o filtro por público e permissão acontece no service.
Route::middleware(['auth', 'tenant.ativo'])->group(function () {
    Route::get('/avisos-atualizacao/pendentes', [AvisoAtualizacaoController::class, 'pendentes'])
        ->name('avisos-atualizacao.pendentes');
    Route::post('/avisos-atualizacao/lidos', [AvisoAtualizacaoController::class, 'marcarLidos'])
        ->middleware('throttle:30,1')
        ->name('avisos-atualizacao.lidos');
});
