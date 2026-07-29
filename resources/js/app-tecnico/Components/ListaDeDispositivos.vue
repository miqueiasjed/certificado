<!--
  Lista de dispositivos da OS em execução (Plano 13, Task 13.6).

  Todos os dispositivos vêm de `listarDispositivosDaOrdem()` (IndexedDB local,
  Task 12.7), nunca da rede: a carga do dia já embute, para cada ordem, a
  lista completa e atual dos dispositivos do endereço (`AppDayLoadService`).

  "Já registrado nesta visita" é decidido pela fila de sincronização
  (`sync/fila.js`, Task 12.8), e não por um campo do próprio dispositivo: a
  carga do dia não traz o histórico de eventos já registrados, só o cadastro
  do dispositivo. Por isso o sinal de "Registrado" reflete só o que este
  aparelho enfileirou nesta sessão (`pendente`, `enviando`, `conflito` ou
  `falha` - qualquer situação em que a operação ainda exista na fila local).
  Uma vez confirmada pelo servidor (`aplicada`), a operação some da fila por
  design (ver `sync/fila.js`), e o sinal deixa de aparecer até a próxima
  carga do dia trazer alguma confirmação equivalente - limitação aceita nesta
  task, documentada aqui e no relatório da Task 13.6.

  A leitura de QR/código resolve sempre OFFLINE, via
  `useLeituraDeDispositivoOffline` injetado em `LeitorDeQrCode.vue`
  (`@/Components/LeitorDeQrCode.vue`, Task 11.7): nunca chama
  `/api/devices/ler/{codigo}`. O aviso de "outro endereço" e o de "etiqueta
  substituída" já aparecem dentro do próprio leitor (mesma linguagem da Task
  11.7), sem bloquear - ao confirmar, o leitor emite `lido(codigo)` e este
  componente abre direto o registro daquele dispositivo.
-->
<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between gap-3">
      <p class="text-sm font-medium text-gray-700">{{ textoDoContador }}</p>
    </div>

    <button
      type="button"
      class="btn-primary w-full py-3 text-base"
      :disabled="somenteLeitura"
      @click="leitorAberto = true"
    >
      Ler QR code
    </button>

    <p v-if="carregando" class="text-sm text-gray-500">Carregando dispositivos...</p>

    <p v-else-if="dispositivos.length === 0" class="text-sm text-gray-600">
      Nenhum dispositivo cadastrado neste endereço.
    </p>

    <ul v-else class="space-y-2">
      <li v-for="dispositivo in dispositivosOrdenados" :key="dispositivo.id">
        <button
          type="button"
          class="w-full min-h-[44px] flex items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white p-4 text-left shadow-sm"
          @click="dispositivoSelecionado = dispositivo"
        >
          <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-gray-900">
              {{ dispositivo.rotulo }} <span v-if="dispositivo.numero">(nº {{ dispositivo.numero }})</span>
            </p>
            <p v-if="localPrevisto(dispositivo)" class="truncate text-xs text-gray-500">
              {{ localPrevisto(dispositivo) }}
            </p>
          </div>

          <span
            v-if="registrados.has(dispositivo.codigo_publico)"
            class="shrink-0 rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-800"
          >
            Registrado
          </span>
        </button>
      </li>
    </ul>

    <LeitorDeQrCode
      :show="leitorAberto"
      :work-order-id="ordem.id"
      :use-leitura="useLeituraDeDispositivoOffline"
      @lido="aoLerCodigo"
      @fechado="leitorAberto = false"
    />

    <RegistroDeEvento
      v-if="dispositivoSelecionado"
      :dispositivo="dispositivoSelecionado"
      :ordem="ordem"
      :somente-leitura="somenteLeitura"
      @salvo="aoSalvarRegistro"
      @fechar="dispositivoSelecionado = null"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import LeitorDeQrCode from '@/Components/LeitorDeQrCode.vue';
import { listarDispositivosDaOrdem, obterDispositivoPorCodigoPublico } from '../db/repositorio';
import { listarPorOrdemETipo, aoMudar } from '../sync/fila';
import { useLeituraDeDispositivoOffline } from '../Composables/useLeituraDeDispositivoOffline';
import RegistroDeEvento from './RegistroDeEvento.vue';

const TIPO_DA_OPERACAO = 'evento_dispositivo';

const props = defineProps({
  ordem: {
    type: Object,
    required: true,
  },
  somenteLeitura: {
    type: Boolean,
    default: false,
  },
});

const dispositivos = ref([]);
const carregando = ref(true);
const registrados = ref(new Set());
const leitorAberto = ref(false);
const dispositivoSelecionado = ref(null);
let pararDeEscutarFila = null;

const dispositivosOrdenados = computed(() =>
  [...dispositivos.value].sort((a, b) => {
    const rotuloA = (a.rotulo || '').localeCompare(b.rotulo || '');

    if (rotuloA !== 0) return rotuloA;

    return String(a.numero || '').localeCompare(String(b.numero || ''));
  }),
);

const textoDoContador = computed(
  () => `${registrados.value.size} de ${dispositivos.value.length} dispositivos registrados`,
);

/**
 * "Local previsto" do dispositivo (`Device::default_location_note` no
 * backend, exibido em `Devices/Show.vue` do painel principal). Lacuna
 * documentada: `AppDayLoadService::mapearOrdem()` não inclui esta coluna na
 * seleção de dispositivos da carga do dia, então ela nunca chega a este
 * aparelho hoje. Em vez de inventar um valor, a linha simplesmente não
 * aparece quando o campo não existir localmente. Fechar esta lacuna é mudança
 * de backend (acrescentar a coluna à consulta do Service), fora do escopo
 * desta task de frontend.
 */
function localPrevisto(dispositivo) {
  return dispositivo.local_previsto || dispositivo.default_location_note || '';
}

async function carregarDispositivos() {
  carregando.value = true;
  dispositivos.value = await listarDispositivosDaOrdem(Number(props.ordem.id));
  carregando.value = false;
}

async function atualizarRegistrados() {
  const operacoes = await listarPorOrdemETipo(Number(props.ordem.id), TIPO_DA_OPERACAO);

  registrados.value = new Set(
    operacoes.map((operacao) => operacao.payload?.codigo_publico).filter(Boolean),
  );
}

async function aoLerCodigo(codigo) {
  leitorAberto.value = false;

  const encontrado = await obterDispositivoPorCodigoPublico(codigo);

  if (encontrado) {
    dispositivoSelecionado.value = encontrado;
  }
}

async function aoSalvarRegistro() {
  dispositivoSelecionado.value = null;
  await atualizarRegistrados();
}

onMounted(async () => {
  await Promise.all([carregarDispositivos(), atualizarRegistrados()]);
  pararDeEscutarFila = aoMudar(atualizarRegistrados);
});

onUnmounted(() => {
  pararDeEscutarFila?.();
});
</script>
