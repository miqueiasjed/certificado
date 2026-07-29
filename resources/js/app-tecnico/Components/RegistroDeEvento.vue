<!--
  Registro de evento de um dispositivo, dentro da execução da OS (Plano 13,
  Task 13.6).

  Regra de ouro: salvar grava local (fila de sincronização, `sync/fila.js`) e
  volta para a lista imediatamente, sem esperar nenhuma resposta do servidor -
  a Task 13.6 exige isso explicitamente ("a interface nunca espera rede").

  Dispositivo já registrado nesta visita: reabre o mesmo registro para edição,
  em vez de criar um segundo. Como a operação enfileirada ainda não foi
  enviada (situação `pendente`), o payload dela é atualizado no lugar
  (`atualizarPendente`, em `sync/fila.js`), sem trocar o uuid. Se a operação já
  saiu para o servidor (`enviando`, `conflito`, `falha`, ou já foi confirmada e
  saiu da fila), não há como fazer upsert nela: o aplicador do servidor
  (`AplicadorDeEventoDeDispositivo`) sempre grava um registro novo a cada
  operação aplicada. Nesse caso, salvar aqui enfileira uma operação nova -
  lacuna aceita nesta task de frontend, documentada também no relatório da
  Task 13.6.

  Campos aceitos pelo aplicador do servidor (`AplicadorDeEventoDeDispositivo`,
  Task 13.2): `codigo_publico`, `event_type_id`, `event_description`,
  `event_observations`, `bait_consumption_status`, `pest_found`.
  `work_order_id` e `registrada_em` são preenchidos pelo próprio
  `AppSyncService` a partir dos campos de nível superior da operação
  enfileirada (não fazem parte deste payload).

  "Quantidade" (pedida pela Task 13.6) não tem coluna própria em
  `work_order_device_events` (conferido na migration da Task 13.2: só
  `bait_consumption_status` e `pest_found` foram acrescentados, nenhuma
  quantidade). Por isso, quando preenchida, entra como a primeira linha de
  `event_observations`, num formato fixo ("Quantidade observada: N"), que
  também é reconhecido de volta ao reabrir o registro para edição.
-->
<template>
  <Modal :show="true" @close="fechar">
    <template #icon>
      <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M9 12h6m-6 4h6M9 8h6M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"
        />
      </svg>
    </template>

    <template #title>
      {{ dispositivo.rotulo }}<span v-if="dispositivo.numero"> (nº {{ dispositivo.numero }})</span>
    </template>

    <template #content>
      <div class="space-y-4 text-left">
        <p v-if="somenteLeitura" class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
          Esta ordem de serviço já foi assinada. Os dados abaixo são só para consulta.
        </p>

        <p v-else-if="operacaoExistente" class="text-xs text-gray-500">
          Editando o registro feito nesta visita, ainda não enviado ao servidor.
        </p>

        <div>
          <label class="mb-1 block text-sm font-medium text-gray-700">Tipo de evento</label>
          <select
            v-model="form.eventTypeId"
            :disabled="somenteLeitura"
            class="w-full rounded-md border border-gray-300 px-3 py-3 text-base shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
          >
            <option value="">Selecione</option>
            <option v-for="tipo in tiposDeEvento" :key="tipo.id" :value="tipo.id">{{ tipo.nome }}</option>
          </select>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-gray-700">Consumo de isca</label>
          <select
            v-model="form.baitConsumptionStatus"
            :disabled="somenteLeitura"
            class="w-full rounded-md border border-gray-300 px-3 py-3 text-base shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
          >
            <option value="">Não avaliado</option>
            <option v-for="opcao in ESCALA_CONSUMO_DE_ISCA" :key="opcao.valor" :value="opcao.valor">
              {{ opcao.rotulo }}
            </option>
          </select>
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-gray-700">Praga encontrada</label>
          <select
            v-model="form.pestFound"
            :disabled="somenteLeitura"
            class="w-full rounded-md border border-gray-300 px-3 py-3 text-base shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
          >
            <option value="">Nenhuma</option>
            <option v-for="praga in pragas" :key="praga.id" :value="praga.id">{{ praga.nome }}</option>
          </select>
        </div>

        <div v-if="form.pestFound">
          <label class="mb-1 block text-sm font-medium text-gray-700">Quantidade observada</label>
          <input
            v-model="form.quantidade"
            type="number"
            min="0"
            inputmode="numeric"
            :disabled="somenteLeitura"
            class="w-full rounded-md border border-gray-300 px-3 py-3 text-base shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
          />
        </div>

        <div>
          <label class="mb-1 block text-sm font-medium text-gray-700">Observação</label>
          <textarea
            v-model="form.observacoes"
            rows="3"
            :disabled="somenteLeitura"
            class="w-full rounded-md border border-gray-300 px-3 py-2 text-base shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
          ></textarea>
        </div>
      </div>
    </template>

    <template #actions>
      <button type="button" class="btn-secondary" :disabled="salvando" @click="fechar">
        {{ somenteLeitura ? 'Fechar' : 'Cancelar' }}
      </button>
      <button v-if="!somenteLeitura" type="button" class="btn-primary" :disabled="!podeSalvar" @click="salvar">
        {{ salvando ? 'Salvando...' : 'Salvar' }}
      </button>
    </template>
  </Modal>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import Modal from '@/Components/Modal.vue';
import { listarTiposDeEvento, listarPragas } from '../db/repositorio';
import { enfileirar, listarPorOrdemETipo, atualizarPendente } from '../sync/fila';

const TIPO_DA_OPERACAO = 'evento_dispositivo';

// Mesma escala de `App\Models\DeviceEvent::getBaitConsumptionStatusTextAttribute()`
// (a tabela mais antiga do projeto, `device_events`), reaproveitada aqui
// porque `work_order_device_events.bait_consumption_status` (Task 13.2) é uma
// coluna de texto livre, sem enum no banco - o vocabulário do projeto para
// este campo é o que já existe naquele model.
const ESCALA_CONSUMO_DE_ISCA = [
  { valor: 'none', rotulo: 'Não houve' },
  { valor: 'partial', rotulo: 'Parcial' },
  { valor: 'total', rotulo: 'Total' },
  { valor: 'spoiled', rotulo: 'Estragada' },
  { valor: 'replacement', rotulo: 'Reposição' },
];

const REGEX_QUANTIDADE = /^Quantidade observada: ([0-9]+(?:[.,][0-9]+)?)\n?/;

const props = defineProps({
  dispositivo: {
    type: Object,
    required: true,
  },
  ordem: {
    type: Object,
    required: true,
  },
  somenteLeitura: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['salvo', 'fechar']);

const tiposDeEvento = ref([]);
const pragas = ref([]);
const operacaoExistente = ref(null);
const salvando = ref(false);

const form = reactive({
  eventTypeId: '',
  baitConsumptionStatus: '',
  pestFound: '',
  quantidade: '',
  observacoes: '',
});

const podeSalvar = computed(() => form.eventTypeId !== '' && !props.somenteLeitura && !salvando.value);

function separarQuantidadeDaObservacao(texto) {
  if (!texto) {
    return { quantidade: '', observacoes: '' };
  }

  const encontrado = REGEX_QUANTIDADE.exec(texto);

  if (!encontrado) {
    return { quantidade: '', observacoes: texto };
  }

  return { quantidade: encontrado[1], observacoes: texto.slice(encontrado[0].length) };
}

function montarObservacao() {
  const partes = [];

  if (form.quantidade !== '' && form.quantidade !== null && form.quantidade !== undefined) {
    partes.push(`Quantidade observada: ${form.quantidade}`);
  }

  const textoLivre = form.observacoes.trim();

  if (textoLivre !== '') {
    partes.push(textoLivre);
  }

  return partes.length ? partes.join('\n') : null;
}

function preencherComOperacao(payload) {
  form.eventTypeId = payload?.event_type_id ?? '';
  form.baitConsumptionStatus = payload?.bait_consumption_status ?? '';
  form.pestFound = payload?.pest_found ?? '';

  const { quantidade, observacoes } = separarQuantidadeDaObservacao(payload?.event_observations);
  form.quantidade = quantidade;
  form.observacoes = observacoes;
}

async function carregarOperacaoExistente() {
  const operacoes = await listarPorOrdemETipo(Number(props.ordem.id), TIPO_DA_OPERACAO);
  const doDispositivo = operacoes.filter(
    (operacao) => operacao.payload?.codigo_publico === props.dispositivo.codigo_publico,
  );

  operacaoExistente.value = doDispositivo.length ? doDispositivo[doDispositivo.length - 1] : null;

  if (operacaoExistente.value) {
    preencherComOperacao(operacaoExistente.value.payload);
  }
}

async function salvar() {
  if (!podeSalvar.value) {
    return;
  }

  salvando.value = true;

  try {
    const payload = {
      codigo_publico: props.dispositivo.codigo_publico,
      event_type_id: form.eventTypeId || null,
      event_description: null,
      event_observations: montarObservacao(),
      bait_consumption_status: form.baitConsumptionStatus || null,
      pest_found: form.pestFound || null,
    };

    const editadoNoLugar =
      operacaoExistente.value && operacaoExistente.value.situacao === 'pendente'
        ? await atualizarPendente(operacaoExistente.value.uuid, payload)
        : false;

    if (!editadoNoLugar) {
      await enfileirar({
        tipo: TIPO_DA_OPERACAO,
        work_order_id: Number(props.ordem.id),
        payload,
        updated_at_conhecido: props.ordem.updated_at ?? null,
      });
    }

    emit('salvo', { dispositivo: props.dispositivo });
  } finally {
    salvando.value = false;
  }
}

function fechar() {
  if (salvando.value) {
    return;
  }

  emit('fechar');
}

onMounted(async () => {
  const [tipos, catalogoDePragas] = await Promise.all([listarTiposDeEvento(), listarPragas()]);
  tiposDeEvento.value = tipos;
  pragas.value = catalogoDePragas;

  await carregarOperacaoExistente();
});
</script>
