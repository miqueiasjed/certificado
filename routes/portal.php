<?php

use App\Http\Controllers\Portal\ClientRequestController;
use App\Http\Controllers\Portal\PortalAuthController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\Portal\PortalDocumentController;
use App\Http\Middleware\AutenticarCliente;
use App\Http\Middleware\EnsurePortalModuleIsActive;
use Illuminate\Support\Facades\Route;

// Portal do cliente (Plano 15, Task 15.2): acesso externo, separado do login
// dos funcionários. Arquivo próprio de propósito - é o que torna possível
// revisar de uma vez tudo que o cliente final alcança (ver a revisão de
// segurança dedicada exigida antes da liberação, em .claude/tasks/15/INDEX.md).
//
// O "envelope" (prefixo `/portal`, nome de rota `portal.` e o middleware
// `web`, que dá sessão/cookies/CSRF a este arquivo) é aplicado em
// bootstrap/app.php, não aqui: mesmo espírito de routes/api.php, onde quem
// registra o arquivo decide o prefixo e o grupo de middleware.
//
// Nenhuma rota daqui aceita `company_id` por parâmetro, corpo ou domínio: o
// tenant do cliente vem sempre de `client_users.company_id`, resolvido dentro
// de `AutenticarCliente` a partir do usuário autenticado no guard `cliente`.
// Confundir isso é a falha mais grave possível neste plano.

Route::get('/login', [PortalAuthController::class, 'showLogin'])->name('login');
Route::post('/login', [PortalAuthController::class, 'login'])
    ->middleware('throttle:portal-login')
    ->name('login.post');

// `logout` exige o guard `cliente` pelo próprio `AutenticarCliente`: sair só
// faz sentido para quem está autenticado, e o middleware já cobre o caso de
// não estar (redireciona para o login em vez de estourar).
Route::post('/logout', [PortalAuthController::class, 'logout'])
    ->middleware(AutenticarCliente::class)
    ->name('logout');

// Recuperação de senha: pedir o link e, mais abaixo, o mesmo par de rotas de
// "definir senha" usado pelo convite.
//
// `throttle:20,1` no GET, mesmo critério das telas públicas de
// routes/web.php (cadastro, aceite de convite): tela que não grava nada não
// pode travar quem só está navegando ou recarregando. O POST é mais apertado
// (`10,1`) porque dispara e-mail e consulta `client_users` sem escopo de
// empresa.
Route::get('/senha/esqueci', [PortalAuthController::class, 'showEsqueciSenha'])
    ->middleware('throttle:20,1')
    ->name('senha.esqueci');
Route::post('/senha/esqueci', [PortalAuthController::class, 'esqueciSenha'])
    ->middleware('throttle:10,1')
    ->name('senha.esqueci.post');

// Definir senha: a mesma tela e a mesma ação atendem o primeiro acesso (link
// do convite) e a recuperação (link de "esqueci minha senha"). Os dois lados
// gravam no mesmo par de colunas de `ClientUser`
// (`convite_token`/`convite_expira_em`) e terminam em
// `ClientUserService::definirSenha()`, então não há necessidade de duas rotas
// diferentes.
//
// `{token}` é string livre, nunca Model Binding, pelo mesmo motivo do convite
// de usuário em routes/web.php: token inválido, expirado ou já usado precisa
// cair na mesma tela com a mensagem do que aconteceu, e o 404 automático do
// binding devolveria uma página crua para quem só clicou no link do e-mail.
Route::get('/senha/definir/{token}', [PortalAuthController::class, 'showDefinirSenha'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:30,1')
    ->name('senha.definir');
Route::post('/senha/definir/{token}', [PortalAuthController::class, 'definirSenha'])
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:20,1')
    ->name('senha.definir.post');

// Painel, visitas, certificados, contratos, adequações, faturas e download de
// documento (Plano 15, Task 15.4): tudo atrás de `AutenticarCliente` e, além
// dela, do módulo `portal_cliente` (Plano 6) - o portal é liberável por
// plano, e tenant sem o módulo fica sem nenhuma destas rotas, inclusive para
// cliente já convidado. Nenhum controller aqui monta consulta Eloquent
// própria: tudo passa por `PortalService` (Task 15.3).
Route::middleware(AutenticarCliente::class)->group(function () {
    // Página "portal indisponível": de propósito fora do grupo com
    // `EnsurePortalModuleIsActive` logo abaixo, senão o redirecionamento dela
    // apontaria para si mesma (mesmo cuidado do `modulo-indisponivel` de
    // routes/web.php, Task 6.4).
    Route::get('/modulo-indisponivel', [PortalController::class, 'moduloIndisponivel'])
        ->name('modulo-indisponivel');

    Route::middleware(EnsurePortalModuleIsActive::class)->group(function () {
        Route::get('/', [PortalController::class, 'index'])->name('painel');
        Route::get('/visitas', [PortalController::class, 'visitas'])->name('visitas');
        Route::get('/visitas/{id}', [PortalController::class, 'visita'])
            ->whereNumber('id')
            ->name('visitas.show');
        Route::get('/certificados', [PortalController::class, 'certificados'])->name('certificados');
        Route::get('/contratos', [PortalController::class, 'contratos'])->name('contratos');
        Route::get('/adequacoes', [PortalController::class, 'adequacoes'])->name('adequacoes');
        Route::get('/faturas', [PortalController::class, 'faturas'])->name('faturas');

        // Solicitações de atendimento (Plano 15, Task 15.5): abrir, listar e
        // ver a própria. O formulário de abertura nunca aceita prioridade -
        // ela nasce sempre `normal` (`ClientRequestService::abrir()`), e só
        // muda pela avaliação da empresa no painel. `{id}` é numérico e
        // simples, nunca Model Binding: solicitação inexistente ou de outro
        // cliente cai no mesmo 404 decidido dentro de
        // `ClientRequestService::minha()`, igual ao padrão de
        // `PortalController::visita()`.
        Route::get('/solicitacoes', [ClientRequestController::class, 'index'])->name('solicitacoes.index');
        Route::post('/solicitacoes', [ClientRequestController::class, 'store'])->name('solicitacoes.store');
        Route::get('/solicitacoes/{id}', [ClientRequestController::class, 'show'])
            ->whereNumber('id')
            ->name('solicitacoes.show');

        // `{tipo}` é string livre (visita/certificado/contrato/fatura), nunca
        // enum de rota: tipo desconhecido cai no mesmo 404 do `id` que não é
        // do cliente, decidido inteiro dentro de `PortalService::documento()`.
        Route::get('/documentos/{tipo}/{id}', [PortalDocumentController::class, 'download'])
            ->whereNumber('id')
            ->name('documentos.download');
    });
});
