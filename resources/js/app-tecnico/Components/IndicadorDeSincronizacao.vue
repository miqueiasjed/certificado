<!--
    Indicador de sincronização do aplicativo do técnico (Plano 12, Task 12.10).

    Vive no cabeçalho (`AppLayout.vue`) e aparece em todas as telas do
    aplicativo, o dia inteiro - é o que permite ao técnico saber, a qualquer
    momento, se pode confiar que o que ele registrou já saiu do aparelho.

    Quatro estados, sempre expressos em texto (nunca só em cor: o técnico pode
    ter daltonismo, e a tela é lida sob sol) e nunca em animação piscante:

    - "atenção": há conflito ou falha aguardando decisão do técnico. É o único
      estado tocável - toca e vai para Pendências.
    - "enviando": o sincronizador está no meio de um ciclo de envio agora.
    - "aguardando envio": nada sendo enviado neste instante, mas há operação
      pendente na fila local.
    - "tudo sincronizado": fila local vazia, com a hora do último envio bem
      sucedido (hora local do aparelho, via `formatDate.js`).

    Prioridade de exibição (um estado por vez): atenção primeiro, porque é o
    único que exige uma decisão do técnico e não se resolve sozinho; depois
    enviando; depois aguardando envio; só quando nenhum dos três se aplica,
    tudo sincronizado.

    As contagens vêm de `useSincronizacao.js`, que por sua vez lê sempre a
    fila local (`sync/fila.js`) - nunca o servidor.
-->
<template>
    <button
        v-if="precisaDeAtencao"
        type="button"
        class="inline-flex items-center text-xs font-medium text-red-800 bg-red-100 px-2.5 py-1.5 rounded-full min-h-[32px]"
        @click="$emit('abrir-pendencias')"
    >
        {{ totalDeAtencao }} registro{{ totalDeAtencao === 1 ? '' : 's' }} precisa{{
            totalDeAtencao === 1 ? '' : 'm'
        }}
        de você
    </button>

    <span
        v-else-if="sincronizando"
        class="inline-flex items-center text-xs font-medium text-blue-800 bg-blue-100 px-2.5 py-1.5 rounded-full"
    >
        Enviando {{ pendentes }} registro{{ pendentes === 1 ? '' : 's' }}
    </span>

    <span
        v-else-if="pendentes > 0"
        class="inline-flex items-center text-xs font-medium text-yellow-800 bg-yellow-100 px-2.5 py-1.5 rounded-full"
    >
        {{ pendentes }} registro{{ pendentes === 1 ? '' : 's' }} aguardando envio
    </span>

    <span
        v-else
        class="inline-flex items-center text-xs font-medium text-green-800 bg-green-100 px-2.5 py-1.5 rounded-full"
    >
        Tudo sincronizado{{ horaDoUltimoEnvio ? ` às ${horaDoUltimoEnvio}` : '' }}
    </span>
</template>

<script setup>
import { computed } from 'vue';
import { useSincronizacao } from '../Composables/useSincronizacao';
import { formatarHora } from '@/utils/formatDate';

defineEmits(['abrir-pendencias']);

const { pendentes, emConflito, comFalha, sincronizando, ultimaSincronizacao } = useSincronizacao();

// Conflito e falha são, os dois, trabalho do técnico ainda sem decisão: o
// indicador soma os dois num único aviso, e a tela de Pendências (Task 12.10)
// é quem separa cada um em bloco próprio.
const totalDeAtencao = computed(() => emConflito.value + comFalha.value);
const precisaDeAtencao = computed(() => totalDeAtencao.value > 0);

const horaDoUltimoEnvio = computed(() =>
    ultimaSincronizacao.value ? formatarHora(ultimaSincronizacao.value) : '',
);
</script>
