<!--
    Raiz do aplicativo do técnico (Plano 12, Task 12.9).

    Substitui o splash provisório que `app-tecnico.js` montava desde a Task
    12.6. Decide entre Login e a casca autenticada (`AppLayout` + página
    atual) e faz o roteamento entre Dia/Pendências/Conta.

    De propósito, sem Vue Router nem qualquer roteamento por URL: o Blade só
    serve uma única rota para o aplicativo inteiro (`GET /app`, ver
    `routes/web.php`), e o service worker (`vite.config.js`) só cacheia essa
    casca. Trocar de "página" aqui é só trocar qual componente aparece dentro
    do mesmo documento - nunca uma navegação de verdade -, o que é também o
    que torna a troca segura offline, sem depender de nenhuma rota adicional
    existir no servidor.

    A Execução de uma OS (`Execucao.vue`, Task 13.6) é a única página que sai
    de dentro do `AppLayout`: como `Login.vue`, ela ocupa a tela inteira, sem
    a navegação inferior, porque tem rodapé fixo próprio ("Concluir visita").
    `ordemEmExecucaoId` guarda qual OS está aberta; `Dia.vue` emite
    `abrir-ordem` ao tocar num cartão, e `Execucao.vue` emite `voltar` para
    devolver a tela a `AppLayout`/`Dia.vue`, sempre na aba "Dia".

    A Assinatura do cliente (`Assinatura.vue`, Task 13.9) segue o mesmo
    padrão, num estado próprio (`ordemEmAssinaturaId`), mutuamente exclusivo
    com `ordemEmExecucaoId`: `Execucao.vue` emite `ir-para-assinatura` ao
    concluir a visita (ou ao reabrir uma OS já concluída, mas ainda sem
    assinatura), o que troca um estado pelo outro; `Assinatura.vue` emite
    `voltar` para devolver a tela a `AppLayout`/`Dia.vue`, do mesmo jeito que
    `Execucao.vue`.
-->
<template>
    <div v-if="verificandoSessao" class="min-h-screen flex items-center justify-center bg-gray-50 px-6">
        <div class="text-center">
            <img src="/images/logo.png" alt="Sistema de Certificados" class="h-14 w-auto mx-auto mb-4" />
            <p class="text-sm text-gray-500">Aplicativo do técnico</p>
        </div>
    </div>

    <Login v-else-if="!autenticado" @autenticado="aoAutenticar" />

    <Execucao
        v-else-if="ordemEmExecucaoId"
        :ordem-id="ordemEmExecucaoId"
        @voltar="ordemEmExecucaoId = null"
        @ir-para-assinatura="irParaAssinatura"
    />

    <Assinatura v-else-if="ordemEmAssinaturaId" :ordem-id="ordemEmAssinaturaId" @voltar="ordemEmAssinaturaId = null" />

    <AppLayout v-else :pagina-atual="paginaAtual" @navegar="paginaAtual = $event">
        <Dia v-if="paginaAtual === 'dia'" @abrir-ordem="ordemEmExecucaoId = $event" />
        <Pendencias v-else-if="paginaAtual === 'pendencias'" />
        <Conta v-else-if="paginaAtual === 'conta'" />
    </AppLayout>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import AppLayout from './Layouts/AppLayout.vue';
import Login from './Pages/Login.vue';
import Dia from './Pages/Dia.vue';
import Pendencias from './Pages/Pendencias.vue';
import Conta from './Pages/Conta.vue';
import Execucao from './Pages/Execucao.vue';
import Assinatura from './Pages/Assinatura.vue';
import { obterMeta } from './db/repositorio';
import { retomarAposLogin } from './sync/sincronizador';

const verificandoSessao = ref(true);
const autenticado = ref(false);
const paginaAtual = ref('dia');
const ordemEmExecucaoId = ref(null);
const ordemEmAssinaturaId = ref(null);

onMounted(async () => {
    const token = await obterMeta('token');

    if (token) {
        autenticado.value = true;

        // Reabertura já logada (inclusive em modo avião): destrava o
        // sincronizador e tenta enviar o que ficou pendente, exatamente
        // como documentado em `retomarAposLogin()`.
        retomarAposLogin();
    }

    verificandoSessao.value = false;
});

function aoAutenticar() {
    autenticado.value = true;
    paginaAtual.value = 'dia';
}

/**
 * "Concluir visita" e "coletar assinatura" são, para o técnico, o mesmo
 * gesto (ver `Execucao.vue`): ao emitir este evento, a OS que estava aberta
 * em `Execucao.vue` passa a abrir em `Assinatura.vue`, sem passar por
 * `Dia.vue` no meio do caminho.
 */
function irParaAssinatura() {
    ordemEmAssinaturaId.value = ordemEmExecucaoId.value;
    ordemEmExecucaoId.value = null;
}
</script>
