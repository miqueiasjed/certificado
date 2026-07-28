<!--
    Stub mínimo da tela de Conta (Plano 12, Task 12.9).

    A tela completa (lista de sessões, revogação de aparelho, saída) é de
    outra task. Este stub só mostra a identidade de quem está logado, lida do
    que o login (Task 12.9, `Login.vue`) gravou em `meta.usuario_logado`.

    De propósito, sem botão de sair aqui: `limparBaseLocal()`
    (`db/repositorio.js`) só pode ser ligada a um botão real depois de checar
    que a fila de sincronização está vazia, checagem que ainda não existe
    neste aplicativo. Um logout sem essa checagem apagaria trabalho do
    técnico ainda não confirmado pelo servidor.
-->
<template>
    <div class="max-w-lg mx-auto px-4 py-6 space-y-4">
        <h1 class="text-lg font-semibold text-gray-900">Conta</h1>

        <div class="bg-white rounded-lg border border-gray-200 shadow-sm p-4 space-y-1">
            <p class="text-sm font-medium text-gray-900">{{ usuario?.name || 'Técnico' }}</p>
            <p class="text-sm text-gray-700">{{ usuario?.email }}</p>
            <p v-if="empresa?.name" class="text-sm text-gray-700">{{ empresa.name }}</p>
        </div>

        <p class="text-sm text-gray-600">Sessões do aparelho e saída do aplicativo chegam em uma próxima etapa.</p>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { obterMeta } from '../db/repositorio';

const usuario = ref(null);
const empresa = ref(null);

onMounted(async () => {
    const usuarioLogado = await obterMeta('usuario_logado');

    usuario.value = usuarioLogado?.usuario ?? null;
    empresa.value = usuarioLogado?.empresa ?? null;
});
</script>
