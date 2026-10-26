<?php

use App\Http\Controllers\Plataforma\ProblemReportController as PlataformaProblemReportController;
use App\Http\Controllers\ProblemReportController;
use Illuminate\Support\Facades\Route;

// Relatos de problema: o botão "Relatar problema" do painel e a fila de
// relatos da área da plataforma. O par do portal do cliente está em
// routes/portal.php (`portal.relatos.store`).
//
// Arquivo próprio, registrado em bootstrap/app.php com o grupo `web`, como
// routes/portal.php e routes/publico.php: o envelope de cada grupo abaixo é o
// mesmo das rotas equivalentes de routes/web.php.

// Mundo das empresas: mesmo par `auth` + `tenant.ativo` do grupo autenticado
// de routes/web.php. Sem `permission:`: relatar problema não pode depender de
// papel, todo funcionário logado precisa conseguir avisar que algo quebrou.
//
// `throttle:relato-de-problema` limita por conta (ver
// `AppServiceProvider::registrarLimiteDeRelatoDeProblema()`).
Route::middleware(['auth', 'tenant.ativo'])->group(function () {
    Route::post('/relatos', [ProblemReportController::class, 'store'])
        ->middleware('throttle:relato-de-problema')
        ->name('relatos.store');
});

// Área da plataforma: mesmo envelope do grupo `plataforma.` de routes/web.php.
// A tela de detalhe é o `source_url` que o sistema central de suporte recebe
// em cada relato. `{relato}` é numérico e simples, sem Model Binding (ver o
// cabeçalho de `Plataforma\ProblemReportController`).
Route::prefix('plataforma')->name('plataforma.')->middleware(['auth', 'platform.admin'])->group(function () {
    Route::get('/relatos', [PlataformaProblemReportController::class, 'index'])->name('relatos.index');
    Route::get('/relatos/{relato}', [PlataformaProblemReportController::class, 'show'])
        ->whereNumber('relato')
        ->name('relatos.show');
    Route::put('/relatos/{relato}/situacao', [PlataformaProblemReportController::class, 'alterarSituacao'])
        ->whereNumber('relato')
        ->name('relatos.situacao');
});
