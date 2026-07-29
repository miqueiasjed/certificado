<?php

use App\Http\Controllers\Publico\AgendamentoPublicoController;
use App\Http\Controllers\Publico\PesquisaController;
use Illuminate\Support\Facades\Route;

// ROTAS PÚBLICAS, SEM AUTENTICAÇÃO NENHUMA (Plano 16, Task 16.3).
//
// Este é o único arquivo de rotas do sistema que qualquer pessoa da internet
// alcança sem login. Ele existe separado por isso: é o que torna possível
// revisar de uma vez, linha a linha, tudo que está exposto (exigência registrada
// em .claude/tasks/16/INDEX.md, item 3 da ordem de aplicação em produção).
//
// Regras que valem para toda rota deste arquivo, e que a revisão precisa cobrar
// de qualquer rota nova:
//
// 1. **O tenant vem do parâmetro da URL, e de mais nada.** Aqui não existe
//    usuário autenticado, então `TenantAtual::id()` devolve `null` e o escopo
//    global por empresa NÃO filtra nada (ver `BelongsToCompany`). Toda consulta
//    precisa rodar dentro de `TenantAtual::comTenant()`, o que acontece dentro
//    de `PublicSchedulingService`. Controller daqui não monta consulta Eloquent.
// 2. **Nada de dado autenticado na resposta.** O grupo de middleware `publico`
//    (bootstrap/app.php) usa `HandleInertiaPublicoRequests`, e não o
//    `HandleInertiaRequests` do painel: a sessão do navegador pode ser de um
//    funcionário logado em outra aba, e as props do painel levariam o e-mail e
//    as permissões dele para dentro de uma página pública.
// 3. **Nada de Model Binding.** `{slug}` e `{token}` são string livre, resolvidos
//    dentro do Service, que decide o 404. Binding automático aqui exigiria
//    escopo por empresa que não existe antes de o tenant ser resolvido.
// 4. **Toda rota tem limite de taxa.** Sem login não há nada a bloquear depois
//    do fato: a proteção é antes, na entrada.
//
// O "envelope" (grupo de middleware `publico` e prefixo de nome `publico.`) é
// aplicado em bootstrap/app.php, mesmo padrão de routes/portal.php. Não há
// prefixo de URL: as páginas públicas ficam na raiz, porque o endereço é
// divulgado ao cliente final e `empresa.com.br/agendar/nome-da-empresa` é o que
// alguém consegue digitar.

// Página pública de pedido de horário.
//
// `{slug}` restrito a minúsculas, números e hífen: slug com qualquer outro
// caractere nem chega ao controller e cai no mesmo 404 de slug inexistente. O
// teto de 60 caracteres evita URL absurda virando consulta.
//
// Leitura (`show` e `grade`) e escrita (`store`) têm limites diferentes de
// propósito. Ler é navegação: 30 por minuto cabe folgado em quem abre a página,
// troca de mês algumas vezes e recarrega, e ainda barra varredura de slug.
// Escrever tem limite de requisição mais apertado (10 por minuto, 30 por hora)
// contra marretada.
//
// O limite do plano (3 pedidos por hora por IP e 1 por hora por telefone) NÃO
// está aqui: ele conta pedido gravado, não requisição, e vive em
// `PublicSchedulingService::registrar()`. O motivo está no docblock de
// `recusarAcimaDoLimite()`: contado por middleware, um erro de digitação no
// formulário consumiria a cota da hora de quem está de boa-fé.
Route::get('/agendar/{slug}', [AgendamentoPublicoController::class, 'show'])
    ->where('slug', '[a-z0-9][a-z0-9-]{0,59}')
    ->middleware('throttle:agendamento-publico-leitura')
    ->name('agendar.show');

Route::get('/agendar/{slug}/grade', [AgendamentoPublicoController::class, 'grade'])
    ->where('slug', '[a-z0-9][a-z0-9-]{0,59}')
    ->middleware('throttle:agendamento-publico-leitura')
    ->name('agendar.grade');

Route::post('/agendar/{slug}', [AgendamentoPublicoController::class, 'store'])
    ->where('slug', '[a-z0-9][a-z0-9-]{0,59}')
    ->middleware('throttle:agendamento-publico')
    ->name('agendar.store');

// Página pública de resposta da pesquisa de satisfação (Task 16.5).
//
// Aqui não existe slug: o `{token}` de 64 caracteres é o que resolve a empresa,
// dentro de `SatisfactionSurveyService::resolverToken()`. Ele é a única
// credencial da resposta, e por isso o tamanho: token curto seria adivinhável por
// varredura, e responder a pesquisa de outro cliente contamina o indicador da
// empresa.
//
// A restrição do parâmetro é deliberadamente mais larga que o formato real do
// token (64 alfanuméricos). O motivo: link quebrado no meio pelo cliente de
// e-mail é o caso mais comum de erro nesta rota, e uma restrição exata devolveria
// a página crua de 404 justamente para quem precisa da explicação. Com a
// restrição larga, o pedido chega ao controller e recebe a página de "link não
// encontrado", com o texto do que fazer. O teto de 128 caracteres e o alfabeto
// fechado ainda barram URL absurda antes de qualquer consulta, e o Service confere
// o formato exato sem tocar no banco quando ele não bate.
//
// Nenhum estado do token responde 404 de propósito: inválido, expirado e já
// respondido têm cada um a sua página, com texto próprio (ver o controller).
//
// Os dois limites de taxa seguem o mesmo critério das rotas de agendamento: ler é
// navegação (30 por minuto por IP, que também barra varredura de token), e
// escrever é mais apertado (10 por minuto, 30 por hora) contra marretada. Ver
// `AppServiceProvider::registrarLimitesDaPesquisaPublica()`.
Route::get('/pesquisa/{token}', [PesquisaController::class, 'show'])
    ->where('token', '[A-Za-z0-9_-]{1,128}')
    ->middleware('throttle:pesquisa-publica-leitura')
    ->name('pesquisa.show');

Route::post('/pesquisa/{token}', [PesquisaController::class, 'store'])
    ->where('token', '[A-Za-z0-9_-]{1,128}')
    ->middleware('throttle:pesquisa-publica')
    ->name('pesquisa.store');
