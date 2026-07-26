<!--
  Substituição do dispositivo do ponto (Task 11.8).

  Este modal É a confirmação exigida pelo projeto para ação de efeito
  permanente (nunca `confirm()` nativo): a frase deixando claro que o
  histórico do anterior é preservado fica no próprio conteúdo, e o botão
  primário só dispara depois que o técnico já viu motivo, data e observação.

  Chama `devices.substituir` por `fetch()` (não `useForm`/Inertia), porque a
  resposta 422 de "já foi substituído" ou "observação obrigatória" precisa
  chegar como JSON dentro do modal, e não como um flash de um redirect.

  Tipo de isca do dispositivo novo
  ---------------------------------
  `Show.vue` não recebe uma lista de tipos de isca do backend (o controller
  `DeviceController::show()` só passa `device`), e não há endpoint JSON para
  buscar o catálogo inteiro fora das páginas Inertia que já o carregam. Sem
  poder alterar PHP, este modal não oferece um select de tipo de isca: o
  dispositivo novo herda automaticamente o tipo do anterior (comportamento
  padrão do backend quando `bait_type_id` não é enviado), e quem precisar
  trocar o tipo o faz editando o dispositivo novo logo depois da troca. Isso é
  registrado explicitamente na tela e no relatório final da task.
-->
<template>
  <Modal :show="show" @close="fechar">
    <template #icon>
      <svg class="h-6 w-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
      </svg>
    </template>

    <template #title>
      Substituir dispositivo
    </template>

    <template #content>
      <div class="space-y-6">
        <div class="rounded-md border border-amber-200 bg-amber-50 p-3">
          <p class="text-sm text-amber-800">
            O dispositivo atual (<strong>{{ device?.codigo_publico }}</strong>) sai de circulação, mas
            continua registrado com todo o histórico dele. O novo dispositivo assume o ponto com um
            código público próprio.
          </p>
        </div>

        <div v-if="erroGeral" class="rounded-md border border-red-200 bg-red-50 p-3">
          <p class="text-sm text-red-800">{{ erroGeral }}</p>
        </div>

        <div>
          <label for="subst-motivo" class="block text-sm font-medium text-gray-700 mb-2">
            Motivo *
          </label>
          <select
            id="subst-motivo"
            v-model="form.motivo"
            required
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            :class="{ 'border-red-500': camposComErro.motivo }"
          >
            <option value="">Selecione o motivo</option>
            <option v-for="opcao in MOTIVOS" :key="opcao.valor" :value="opcao.valor">
              {{ opcao.texto }}
            </option>
          </select>
          <p v-if="camposComErro.motivo" class="mt-1 text-sm text-red-600">
            {{ camposComErro.motivo[0] }}
          </p>
        </div>

        <div>
          <label for="subst-data" class="block text-sm font-medium text-gray-700 mb-2">
            Data da substituição *
          </label>
          <input
            id="subst-data"
            v-model="form.substituido_em"
            type="date"
            required
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            :class="{ 'border-red-500': camposComErro.substituido_em }"
          />
          <p class="mt-1 text-xs text-gray-500">
            Registrando a troca em {{ formatarData(form.substituido_em) }}.
          </p>
          <p v-if="camposComErro.substituido_em" class="mt-1 text-sm text-red-600">
            {{ camposComErro.substituido_em[0] }}
          </p>
        </div>

        <div>
          <label for="subst-observacao" class="block text-sm font-medium text-gray-700 mb-2">
            Observação {{ observacaoObrigatoria ? '*' : '' }}
          </label>
          <textarea
            id="subst-observacao"
            v-model="form.observacao"
            rows="3"
            placeholder="Explique o que aconteceu com o dispositivo anterior"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            :class="{ 'border-red-500': camposComErro.observacao }"
          ></textarea>
          <p v-if="observacaoObrigatoria" class="mt-1 text-xs text-gray-500">
            Com o motivo "Outro", a observação é obrigatória.
          </p>
          <p v-if="camposComErro.observacao" class="mt-1 text-sm text-red-600">
            {{ camposComErro.observacao[0] }}
          </p>
        </div>

        <hr class="border-gray-200" />

        <p class="text-sm font-medium text-gray-700">Dados do dispositivo novo</p>
        <p class="text-xs text-gray-500 -mt-4">
          Pré-preenchidos com os valores do dispositivo atual. O tipo de isca é mantido
          automaticamente; para trocá-lo, edite o dispositivo novo depois da substituição.
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label for="subst-label" class="block text-sm font-medium text-gray-700 mb-2">
              Nome do dispositivo *
            </label>
            <input
              id="subst-label"
              v-model="form.label"
              type="text"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': camposComErro.label }"
            />
            <p v-if="camposComErro.label" class="mt-1 text-sm text-red-600">
              {{ camposComErro.label[0] }}
            </p>
          </div>

          <div>
            <label for="subst-number" class="block text-sm font-medium text-gray-700 mb-2">
              Número/Código *
            </label>
            <input
              id="subst-number"
              v-model="form.number"
              type="text"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': camposComErro.number }"
            />
            <p v-if="camposComErro.number" class="mt-1 text-sm text-red-600">
              {{ camposComErro.number[0] }}
            </p>
          </div>
        </div>

        <div>
          <label for="subst-local" class="block text-sm font-medium text-gray-700 mb-2">
            Observação de localização
          </label>
          <textarea
            id="subst-local"
            v-model="form.default_location_note"
            rows="2"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          ></textarea>
        </div>
      </div>
    </template>

    <template #actions>
      <button type="button" class="btn-secondary" :disabled="enviando" @click="fechar">
        Cancelar
      </button>
      <button
        type="button"
        :disabled="enviando || !formValido"
        class="btn-primary"
        @click="confirmar"
      >
        {{ enviando ? 'Substituindo...' : 'Confirmar substituição' }}
      </button>
    </template>
  </Modal>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import { formatarData, hojeISO } from '@/utils/formatDate';

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  device: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(['close', 'substituido']);

const MOTIVOS = [
  { valor: 'danificado', texto: 'Danificado' },
  { valor: 'perdido', texto: 'Perdido' },
  { valor: 'desgaste', texto: 'Desgaste' },
  { valor: 'troca_de_tipo', texto: 'Troca de tipo' },
  { valor: 'outro', texto: 'Outro' },
];

function formPadrao() {
  return {
    motivo: '',
    substituido_em: hojeISO(),
    observacao: '',
    label: props.device?.label || '',
    number: props.device?.number || '',
    default_location_note: props.device?.default_location_note || '',
  };
}

const form = ref(formPadrao());
const enviando = ref(false);
const erroGeral = ref('');
const camposComErro = ref({});

const observacaoObrigatoria = computed(() => form.value.motivo === 'outro');

const formValido = computed(() => {
  if (!form.value.motivo || !form.value.substituido_em) return false;
  if (!form.value.label.trim() || !form.value.number.trim()) return false;
  if (observacaoObrigatoria.value && !form.value.observacao.trim()) return false;

  return true;
});

watch(() => props.show, (visivel) => {
  if (visivel) {
    form.value = formPadrao();
    erroGeral.value = '';
    camposComErro.value = {};
  }
});

function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
}

async function confirmar() {
  if (!formValido.value || enviando.value || !props.device) return;

  enviando.value = true;
  erroGeral.value = '';
  camposComErro.value = {};

  // `bait_type_id` fica de fora de propósito: omitir a chave (e não mandar
  // `null`) é o que faz o backend herdar o tipo de isca do dispositivo
  // anterior. Ver comentário no cabeçalho do arquivo.
  const payload = {
    motivo: form.value.motivo,
    substituido_em: form.value.substituido_em,
    label: form.value.label.trim(),
    number: form.value.number.trim(),
    default_location_note: form.value.default_location_note.trim(),
  };

  if (form.value.observacao.trim() !== '') {
    payload.observacao = form.value.observacao.trim();
  }

  try {
    const resposta = await fetch(route('devices.substituir', props.device.id), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
        Accept: 'application/json',
      },
      body: JSON.stringify(payload),
    });

    const dados = await resposta.json();

    if (!resposta.ok) {
      erroGeral.value = dados.message || 'Não foi possível substituir o dispositivo.';
      camposComErro.value = dados.errors || {};
      return;
    }

    emit('substituido', { dispositivo_novo: dados.dispositivo_novo, historico: dados.historico });
    emit('close');
  } catch (erro) {
    erroGeral.value = 'Não foi possível falar com o servidor agora. Verifique a conexão e tente novamente.';
  } finally {
    enviando.value = false;
  }
}

function fechar() {
  if (enviando.value) return;
  emit('close');
}
</script>
