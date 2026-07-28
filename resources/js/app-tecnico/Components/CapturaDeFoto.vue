<!--
  Captura de foto do aplicativo do técnico (Plano 12, Task 12.11).

  Fluxo: o técnico escolhe "Tirar foto" (câmera, via `input capture`) ou
  "Escolher da galeria" (o mesmo tipo de input, sem o atributo `capture` - às
  vezes o técnico já fotografou fora do aplicativo). O arquivo escolhido entra
  em pré-visualização, com legenda opcional, antes de qualquer gravação: só ao
  confirmar ("Usar esta foto") é que `sync/foto.js` comprime a imagem
  (corrigindo a rotação pelo EXIF) e grava o `Blob` já comprimido na fila
  própria de fotos (`db.fotos`) - nunca o arquivo original.

  A legenda pode ser preenchida depois, offline: toda foto já gravada (ainda
  não enviada) aparece na grade abaixo com um campo de legenda editável.

  O envio em si não acontece aqui: `iniciarEnvioDeFotos()` (chamado uma vez ao
  montar, idempotente) liga os gatilhos automáticos de conectividade em
  `sync/foto.js`; este componente só mostra a situação de cada foto
  (`pendente`, `enviando`, `falha`) e deixa o técnico editar a legenda ou
  remover uma foto que ainda não foi enviada.
-->
<template>
  <div class="space-y-4">
    <div
      v-if="avisoDeLimite"
      class="rounded-md border p-3"
      :class="limiteAtingido ? 'border-red-200 bg-red-50' : 'border-yellow-200 bg-yellow-50'"
    >
      <p class="text-sm font-medium" :class="limiteAtingido ? 'text-red-800' : 'text-yellow-800'">
        <template v-if="limiteAtingido">
          Limite de {{ LIMITE_DE_FOTOS_PENDENTES }} fotos aguardando envio atingido neste aparelho. Conecte-se à
          internet para sincronizar antes de tirar novas fotos.
        </template>
        <template v-else>
          {{ contagemNaoEnviadas }} fotos aguardando envio neste aparelho. Conecte-se à internet em breve para
          sincronizar.
        </template>
      </p>
    </div>

    <div v-if="!arquivoSelecionado" class="flex flex-wrap gap-2">
      <button type="button" class="btn-primary" :disabled="limiteAtingido" @click="abrirCamera">
        Tirar foto
      </button>
      <button type="button" class="btn-secondary" :disabled="limiteAtingido" @click="abrirGaleria">
        Escolher da galeria
      </button>
    </div>

    <!-- Inputs reais, ocultos: cada botão acima só dispara o clique num deles. -->
    <input
      ref="inputCameraRef"
      type="file"
      accept="image/*"
      capture="environment"
      class="hidden"
      @change="aoSelecionarArquivo"
    />
    <input ref="inputGaleriaRef" type="file" accept="image/*" class="hidden" @change="aoSelecionarArquivo" />

    <!-- Pré-visualização, antes de gravar de fato -->
    <div v-if="arquivoSelecionado" class="rounded-lg border border-gray-200 bg-white p-4 space-y-3">
      <img :src="urlPreview" alt="Pré-visualização da foto" class="max-h-72 w-full rounded-md bg-gray-100 object-contain" />

      <div>
        <label class="mb-1 block text-sm font-medium text-gray-700">Legenda (opcional)</label>
        <input
          v-model="legendaRascunho"
          type="text"
          maxlength="255"
          placeholder="Ex.: Isca na parede sul"
          class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
        />
      </div>

      <p v-if="erro" class="text-sm text-red-600">{{ erro }}</p>

      <div class="flex justify-end gap-3">
        <button type="button" class="btn-secondary" :disabled="comprimindo" @click="descartarSelecao">
          Descartar
        </button>
        <button type="button" class="btn-primary" :disabled="comprimindo" @click="confirmarCaptura">
          {{ comprimindo ? 'Comprimindo...' : 'Usar esta foto' }}
        </button>
      </div>
    </div>

    <!-- Fotos já gravadas localmente para este contexto -->
    <div v-if="fotos.length" class="grid grid-cols-2 gap-3 sm:grid-cols-3">
      <div v-for="foto in fotos" :key="foto.uuid" class="space-y-2 rounded-lg border border-gray-200 bg-white p-2">
        <img :src="urlDaFoto(foto)" alt="Foto capturada" class="h-28 w-full rounded object-cover" />

        <span class="inline-block rounded-full px-2 py-0.5 text-xs font-medium" :class="classesDaSituacao(foto.situacao)">
          {{ textoDaSituacao(foto) }}
        </span>

        <input
          :value="foto.legenda"
          type="text"
          maxlength="255"
          placeholder="Legenda (opcional)"
          :disabled="foto.situacao === 'enviando'"
          class="w-full rounded-md border border-gray-300 px-2 py-1 text-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
          @change="aoEditarLegenda(foto, $event)"
        />

        <div class="flex items-center justify-between gap-2">
          <button
            v-if="foto.situacao === 'falha'"
            type="button"
            class="btn-secondary-sm"
            @click="tentarNovamente(foto.uuid)"
          >
            Tentar de novo
          </button>
          <button
            v-if="foto.situacao !== 'enviando'"
            type="button"
            class="text-xs text-red-600 hover:text-red-800"
            @click="pedirRemocao(foto)"
          >
            Remover
          </button>
        </div>
      </div>
    </div>

    <!-- Confirmação de remoção: nunca `confirm()` nativo para ação destrutiva. -->
    <Modal :show="!!fotoParaRemover" @close="cancelarRemocao">
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
      <template #title>Remover foto</template>
      <template #content>
        <p class="text-sm text-gray-700">
          Esta foto ainda não foi enviada. Removê-la agora descarta o registro local, sem volta.
        </p>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" @click="cancelarRemocao">Cancelar</button>
        <button type="button" class="btn-danger" @click="confirmarRemocao">Remover</button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import Modal from '@/Components/Modal.vue';
import {
    capturarFoto,
    listarFotosDoContexto,
    contarNaoEnviadas,
    atualizarLegenda,
    removerFotoLocal,
    tentarNovamente as tentarNovamenteFoto,
    aoMudar,
    iniciarEnvioDeFotos,
    atingiuLimite as atingiuLimiteDeFotos,
    AVISO_DE_FOTOS_PENDENTES,
    LIMITE_DE_FOTOS_PENDENTES,
} from '../sync/foto';

const props = defineProps({
    workOrderId: {
        type: [Number, String],
        required: true,
    },
    // Mesmo trio de campos que `POST /api/app/fotos` espera (Task 12.5) e que
    // o upload do painel web já usa (`WorkOrderTabContent.vue`).
    entityType: {
        type: String,
        required: true,
        validator: (valor) => ['adequation', 'device_event', 'room_event'].includes(valor),
    },
    entityId: {
        type: [Number, String],
        default: null,
    },
    roomId: {
        type: [Number, String],
        default: null,
    },
});

const emit = defineEmits(['capturada']);

const inputCameraRef = ref(null);
const inputGaleriaRef = ref(null);

const arquivoSelecionado = ref(null);
const urlPreview = ref(null);
const legendaRascunho = ref('');
const comprimindo = ref(false);
const erro = ref(null);

const fotos = ref([]);
const contagemNaoEnviadas = ref(0);
const fotoParaRemover = ref(null);

// Cache de URLs de objeto para os thumbnails (fora do reativo de propósito -
// é infraestrutura de exibição, não estado de tela). Cada uma é revogada
// assim que a foto correspondente sai da lista (enviada ou removida) e todas
// são revogadas na desmontagem, para não vazar memória.
const urlsPorUuid = new Map();
let pararDeEscutar = null;

const avisoDeLimite = computed(() => contagemNaoEnviadas.value >= AVISO_DE_FOTOS_PENDENTES);
const limiteAtingido = computed(() => contagemNaoEnviadas.value >= LIMITE_DE_FOTOS_PENDENTES);

function abrirCamera() {
    inputCameraRef.value?.click();
}

function abrirGaleria() {
    inputGaleriaRef.value?.click();
}

function aoSelecionarArquivo(evento) {
    const arquivo = evento.target.files?.[0] ?? null;
    evento.target.value = ''; // permite escolher o mesmo arquivo de novo depois de descartar.

    if (!arquivo) {
        return;
    }

    descartarSelecao(); // limpa uma seleção anterior ainda não confirmada, se houver.

    arquivoSelecionado.value = arquivo;
    urlPreview.value = URL.createObjectURL(arquivo);
    legendaRascunho.value = '';
    erro.value = null;
}

function descartarSelecao() {
    if (urlPreview.value) {
        URL.revokeObjectURL(urlPreview.value);
    }

    arquivoSelecionado.value = null;
    urlPreview.value = null;
    legendaRascunho.value = '';
    erro.value = null;
}

/**
 * Comprime (em `sync/foto.js`) e grava o registro local. A compressão
 * acontece aqui, no momento da captura, nunca no envio - o arquivo original
 * (`arquivoSelecionado`) sai de escopo ao final desta função e não é gravado
 * em nenhum lugar.
 */
async function confirmarCaptura() {
    if (!arquivoSelecionado.value || comprimindo.value) {
        return;
    }

    if (await atingiuLimiteDeFotos()) {
        erro.value = `Limite de ${LIMITE_DE_FOTOS_PENDENTES} fotos aguardando envio atingido. Sincronize antes de continuar.`;
        return;
    }

    comprimindo.value = true;
    erro.value = null;

    try {
        const registro = await capturarFoto({
            workOrderId: props.workOrderId,
            contexto: { entityType: props.entityType, entityId: props.entityId, roomId: props.roomId },
            arquivo: arquivoSelecionado.value,
            legenda: legendaRascunho.value,
        });

        descartarSelecao();
        emit('capturada', registro);
        await atualizarListaEContagem();
    } catch (falha) {
        erro.value = falha?.message || 'Não foi possível processar esta foto. Tente novamente.';
    } finally {
        comprimindo.value = false;
    }
}

async function atualizarListaEContagem() {
    const [listaAtual, total] = await Promise.all([
        listarFotosDoContexto(props.workOrderId, {
            entityType: props.entityType,
            entityId: props.entityId,
            roomId: props.roomId,
        }),
        contarNaoEnviadas(),
    ]);

    fotos.value = listaAtual;
    contagemNaoEnviadas.value = total;

    limparUrlsOrfas(listaAtual);
}

function limparUrlsOrfas(listaAtual) {
    const uuidsAtuais = new Set(listaAtual.map((foto) => foto.uuid));

    for (const [uuid, url] of urlsPorUuid) {
        if (!uuidsAtuais.has(uuid)) {
            URL.revokeObjectURL(url);
            urlsPorUuid.delete(uuid);
        }
    }
}

function urlDaFoto(foto) {
    if (!urlsPorUuid.has(foto.uuid)) {
        urlsPorUuid.set(foto.uuid, URL.createObjectURL(foto.blob));
    }

    return urlsPorUuid.get(foto.uuid);
}

function textoDaSituacao(foto) {
    if (foto.situacao === 'enviando') return 'Enviando...';
    if (foto.situacao === 'falha') return `Falhou: ${foto.ultimo_erro || 'erro desconhecido'}`;
    return 'Aguardando envio';
}

function classesDaSituacao(situacao) {
    if (situacao === 'enviando') return 'bg-blue-100 text-blue-800';
    if (situacao === 'falha') return 'bg-red-100 text-red-800';
    return 'bg-yellow-100 text-yellow-800';
}

async function aoEditarLegenda(foto, evento) {
    await atualizarLegenda(foto.uuid, evento.target.value);
}

async function tentarNovamente(uuid) {
    await tentarNovamenteFoto(uuid);
}

function pedirRemocao(foto) {
    fotoParaRemover.value = foto;
}

function cancelarRemocao() {
    fotoParaRemover.value = null;
}

async function confirmarRemocao() {
    if (!fotoParaRemover.value) {
        return;
    }

    await removerFotoLocal(fotoParaRemover.value.uuid);
    fotoParaRemover.value = null;
}

onMounted(async () => {
    // Idempotente: qualquer tela que use este componente liga os gatilhos de
    // envio de foto (evento `online`, intervalo), sem duplicar listener nem
    // intervalo se mais de uma instância montar na mesma sessão.
    await iniciarEnvioDeFotos();
    await atualizarListaEContagem();
    pararDeEscutar = aoMudar(atualizarListaEContagem);
});

onBeforeUnmount(() => {
    pararDeEscutar?.();
    descartarSelecao();

    for (const url of urlsPorUuid.values()) {
        URL.revokeObjectURL(url);
    }
    urlsPorUuid.clear();
});
</script>
