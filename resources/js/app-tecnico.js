// Ponto de entrada do aplicativo do técnico (Plano 12, Task 12.6).
//
// Fica de propósito fora do `resources/js/app.js`: aquele carrega o Inertia
// inteiro do painel web, e o aplicativo do técnico precisa abrir offline, em
// tela cheia, sem nenhuma dessas dependências. Por isso este arquivo não
// importa `./bootstrap` (aquele monta a sincronização de CSRF por cookie de
// sessão, que não existe aqui: a autenticação do aplicativo é por token
// Sanctum, guardado no IndexedDB pela Task 12.9).
//
// As telas de verdade (login e lista do dia) chegam na Task 12.9, em
// `./app-tecnico/App.vue`. O que existe aqui é a casca: o registro do
// service worker com o fluxo de atualização sob confirmação do técnico, e o
// ponto de montagem Vue.

import { createApp } from 'vue';
import { registerSW } from 'virtual:pwa-register';
import App from './app-tecnico/App.vue';

createApp(App).mount('#app-tecnico');

// Aviso discreto de atualização disponível.
//
// Fica fora da árvore Vue acima de propósito: independe de qualquer estado de
// tela que as próximas tasks venham a montar ali dentro. `registerType:
// 'prompt'` (vite.config.js) garante que o novo service worker fica esperando
// em vez de assumir sozinho - só troca quando o técnico confirma, para não
// descartar o estado de uma OS em execução.
function mostrarAvisoDeAtualizacao(aplicarAtualizacao) {
    if (document.getElementById('app-tecnico-atualizacao')) {
        return;
    }

    const aviso = document.createElement('div');
    aviso.id = 'app-tecnico-atualizacao';
    aviso.setAttribute('role', 'status');
    aviso.className = [
        'fixed', 'inset-x-0', 'bottom-0', 'z-50',
        'bg-gray-900', 'text-white', 'text-sm',
        'px-4', 'py-3',
        'flex', 'items-center', 'justify-between', 'gap-4',
    ].join(' ');

    aviso.innerHTML = `
        <span>Uma nova versão do aplicativo está disponível.</span>
        <div class="flex items-center gap-2 shrink-0">
            <button type="button" id="app-tecnico-atualizacao-aplicar"
                class="bg-green-600 hover:bg-green-700 px-3 py-1.5 rounded text-xs font-medium transition-colors">
                Atualizar agora
            </button>
            <button type="button" id="app-tecnico-atualizacao-depois"
                class="text-gray-300 hover:text-white px-2 py-1.5 text-xs">
                Depois
            </button>
        </div>
    `;

    document.body.appendChild(aviso);

    document.getElementById('app-tecnico-atualizacao-aplicar').addEventListener('click', () => {
        aviso.remove();
        aplicarAtualizacao();
    });

    document.getElementById('app-tecnico-atualizacao-depois').addEventListener('click', () => {
        // "Depois" só esconde o aviso desta sessão. O service worker novo
        // continua esperando; o técnico é avisado de novo na próxima vez que
        // o aplicativo detectar a atualização pendente (próxima navegação ou
        // verificação periódica do registro).
        aviso.remove();
    });
}

const aplicarAtualizacao = registerSW({
    immediate: true,
    onNeedRefresh() {
        mostrarAvisoDeAtualizacao(() => aplicarAtualizacao(true));
    },
    onOfflineReady() {
        // Aplicativo pronto para uso offline. Sem aviso ao técnico: é o
        // estado esperado do aplicativo, não uma novidade que exija atenção.
    },
});
