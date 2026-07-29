<!--
  Lista de adequações do aplicativo do técnico (Plano 13, Task 13.8): as
  adequações registradas nesta visita (ainda na fila local, não confirmadas
  pelo servidor), com miniatura da primeira foto, prioridade e prazo, e botão
  de acrescentar.

  A fonte é sempre a fila local (`sync/fila.js`, `listarPorOrdemETipo()`),
  nunca o servidor: uma adequação some daqui assim que sincroniza de verdade
  (mesma regra de ouro da fila, ver `fila.js`) - o histórico depois disso é
  tela de painel web, não deste aplicativo.
-->
<template>
  <div class="space-y-4">
    <RegistroDeAdequacao
      v-if="mostrarFormulario"
      :work-order-id="workOrderId"
      @salvo="aoSalvarAdequacao"
      @cancelar="aoCancelarAdequacao"
    />

    <template v-else>
      <div v-if="itens.length === 0" class="text-sm text-gray-600">
        Nenhuma adequação registrada nesta visita.
      </div>

      <ul v-else class="space-y-2">
        <li
          v-for="item in itens"
          :key="item.uuid"
          class="flex gap-3 rounded-lg border border-gray-200 bg-white p-3 shadow-sm"
        >
          <img
            v-if="fotoDoItem(item)"
            :src="urlDaFoto(fotoDoItem(item))"
            alt="Foto da adequação"
            class="h-16 w-16 flex-shrink-0 rounded-md object-cover"
          />

          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <span
                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                :class="classesDaPrioridade(item.payload?.priority)"
              >
                {{ rotuloDaPrioridade(item.payload?.priority) }}
              </span>
              <span class="text-xs text-gray-500">{{ rotuloDoTipo(item.payload?.type) }}</span>
            </div>

            <p class="mt-1 text-sm text-gray-900">{{ item.payload?.description }}</p>

            <p class="mt-1 text-xs text-gray-500">
              {{ item.payload?.deadline ? `Prazo: ${formatarData(item.payload.deadline)}` : 'Sem prazo definido' }}
            </p>

            <div class="mt-2 flex items-center justify-between">
              <span class="text-xs font-medium" :class="classesDaSituacao(item.situacao)">
                {{ rotuloDaSituacao(item.situacao) }}
              </span>

              <button
                v-if="item.situacao !== 'enviando'"
                type="button"
                class="text-xs text-red-600 hover:text-red-800"
                @click="pedirRemocao(item)"
              >
                Remover
              </button>
            </div>
          </div>
        </li>
      </ul>

      <button type="button" class="btn-secondary w-full" @click="abrirFormulario">
        Acrescentar adequação
      </button>
    </template>

    <!-- Confirmação de remoção: nunca `confirm()` nativo para ação destrutiva. -->
    <Modal :show="!!itemParaRemover" @close="cancelarRemocao">
      <template #icon>
        <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"
          />
        </svg>
      </template>
      <template #title>Remover adequação</template>
      <template #content>
        <p class="text-sm text-gray-700">
          Esta adequação ainda não foi enviada. Removê-la agora descarta o registro e as fotos
          associadas, sem volta.
        </p>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="removendo" @click="cancelarRemocao">
          Cancelar
        </button>
        <button type="button" class="btn-danger" :disabled="removendo" @click="confirmarRemocao">
          {{ removendo ? 'Removendo...' : 'Remover' }}
        </button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import Modal from '@/Components/Modal.vue';
import { formatarData } from '@/utils/formatDate';
import { listarPorOrdemETipo, aoMudar as aoMudarFila, removerDaFila } from '../sync/fila';
import { listarFotosDoContexto, removerFotoLocal, aoMudar as aoMudarFoto } from '../sync/foto';
import RegistroDeAdequacao from './RegistroDeAdequacao.vue';

const props = defineProps({
    workOrderId: {
        type: [Number, String],
        required: true,
    },
});

const TIPOS = {
    estrutural: 'Estrutural',
    sanitario: 'Sanitário',
    higienico: 'Higiênico',
    quimico: 'Químico',
    outros: 'Outros',
};

const SITUACOES = {
    pendente: { rotulo: 'Aguardando envio', classes: 'text-yellow-700' },
    enviando: { rotulo: 'Enviando...', classes: 'text-blue-700' },
    conflito: { rotulo: 'Precisa de decisão', classes: 'text-yellow-700' },
    falha: { rotulo: 'Falhou ao enviar', classes: 'text-red-700' },
};

const itens = ref([]);
const fotosPorUuid = ref(new Map());
const mostrarFormulario = ref(false);
const itemParaRemover = ref(null);
const removendo = ref(false);

// Cache de URLs de objeto para as miniaturas, mesmo padrão de `CapturaDeFoto.vue`:
// fora do reativo de propósito (infraestrutura de exibição, não estado de
// tela), revogada assim que a foto correspondente sai da lista e na desmontagem.
const urlsPorUuid = new Map();
let pararDeEscutarFila = null;
let pararDeEscutarFotos = null;

function rotuloDoTipo(tipo) {
    return TIPOS[tipo] || 'Adequação';
}

function rotuloDaPrioridade(prioridade) {
    if (prioridade === 'alta') return 'Alta';
    if (prioridade === 'baixa') return 'Baixa';
    return 'Média';
}

function classesDaPrioridade(prioridade) {
    if (prioridade === 'alta') return 'bg-red-100 text-red-800';
    if (prioridade === 'baixa') return 'bg-blue-100 text-blue-800';
    return 'bg-yellow-100 text-yellow-800';
}

function rotuloDaSituacao(situacao) {
    return SITUACOES[situacao]?.rotulo || situacao;
}

function classesDaSituacao(situacao) {
    return SITUACOES[situacao]?.classes || 'text-gray-500';
}

function fotoDoItem(item) {
    return fotosPorUuid.value.get(item.uuid) || null;
}

function urlDaFoto(foto) {
    if (!urlsPorUuid.has(foto.uuid)) {
        urlsPorUuid.set(foto.uuid, URL.createObjectURL(foto.blob));
    }

    return urlsPorUuid.get(foto.uuid);
}

function limparUrlsOrfas(mapaAtual) {
    const uuidsAtuais = new Set([...mapaAtual.values()].filter(Boolean).map((foto) => foto.uuid));

    for (const [uuid, url] of urlsPorUuid) {
        if (!uuidsAtuais.has(uuid)) {
            URL.revokeObjectURL(url);
            urlsPorUuid.delete(uuid);
        }
    }
}

/**
 * A primeira foto de cada adequação (a "miniatura", pedida pela Task 13.8),
 * achada localmente pelo uuid gerado em `RegistroDeAdequacao.vue`
 * (`payload.uuid`) - o mesmo valor usado como `entityId` de `CapturaDeFoto`
 * no momento da captura. `listarFotosDoContexto()` já devolve em ordem de
 * captura, então o primeiro item da lista é a primeira foto.
 */
async function carregarFotos() {
    const mapa = new Map();

    for (const item of itens.value) {
        const uuidDaAdequacao = item.payload?.uuid;

        if (!uuidDaAdequacao) {
            continue;
        }

        const fotos = await listarFotosDoContexto(props.workOrderId, {
            entityType: 'adequation',
            entityId: uuidDaAdequacao,
        });

        if (fotos.length) {
            mapa.set(item.uuid, fotos[0]);
        }
    }

    limparUrlsOrfas(mapa);
    fotosPorUuid.value = mapa;
}

async function carregar() {
    itens.value = await listarPorOrdemETipo(props.workOrderId, 'adequacao');
    await carregarFotos();
}

function abrirFormulario() {
    mostrarFormulario.value = true;
}

function aoSalvarAdequacao() {
    mostrarFormulario.value = false;
    // `enfileirar()` já notifica `aoMudarFila`, que religa `carregar()`
    // sozinho - nada a fazer aqui além de fechar o formulário.
}

function aoCancelarAdequacao() {
    mostrarFormulario.value = false;
}

function pedirRemocao(item) {
    itemParaRemover.value = item;
}

function cancelarRemocao() {
    itemParaRemover.value = null;
}

async function confirmarRemocao() {
    if (!itemParaRemover.value) {
        return;
    }

    removendo.value = true;

    try {
        const uuidDaAdequacao = itemParaRemover.value.payload?.uuid;

        if (uuidDaAdequacao) {
            const fotos = await listarFotosDoContexto(props.workOrderId, {
                entityType: 'adequation',
                entityId: uuidDaAdequacao,
            });

            await Promise.all(fotos.map((foto) => removerFotoLocal(foto.uuid)));
        }

        await removerDaFila([itemParaRemover.value.uuid]);
    } finally {
        removendo.value = false;
        itemParaRemover.value = null;
    }
}

onMounted(async () => {
    await carregar();

    pararDeEscutarFila = aoMudarFila(carregar);
    pararDeEscutarFotos = aoMudarFoto(carregarFotos);
});

onBeforeUnmount(() => {
    pararDeEscutarFila?.();
    pararDeEscutarFotos?.();

    for (const url of urlsPorUuid.values()) {
        URL.revokeObjectURL(url);
    }
    urlsPorUuid.clear();
});
</script>
