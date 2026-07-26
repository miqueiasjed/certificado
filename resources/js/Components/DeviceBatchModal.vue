<!--
  Cadastro em lote dos dispositivos de um endereço (Task 11.8).

  Fica de fora deste componente qualquer chamada Inertia: a rota
  `addresses.devices.lote` responde 200/422 em JSON (ver
  `DeviceController::criarLote`), e o padrão do projeto para isso é `fetch()`
  dentro do modal, não `useForm`.

  Pré-visualização dos rótulos
  -----------------------------
  Replica no frontend a mesma regra de `DeviceBatchService::numerosDaFaixa`:
  o número de casas do zero à esquerda segue o MAIOR número da faixa, com piso
  de três casas. Calculada aqui só para dar feedback antes do envio; quem
  decide de verdade continua sendo o backend.

  `prefixo` obrigatório
  ----------------------
  A especificação desta task descreve o prefixo como opcional, mas
  `StoreDeviceBatchRequest::rules()` marca `prefixo` como `required` de fato.
  Como este componente não pode alterar PHP, o campo aqui segue o que o
  backend realmente exige (obrigatório), e não o que o texto da task descreve.
  Ver observação equivalente no relatório final da task.
-->
<template>
  <Modal :show="show" @close="fechar">
    <template #icon>
      <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
      </svg>
    </template>

    <template #title>
      Cadastrar dispositivos em lote
    </template>

    <template #content>
      <!-- Vista de sucesso: substitui o formulário depois de criar o lote -->
      <div v-if="resultado" class="space-y-4">
        <div class="rounded-md border border-green-200 bg-green-50 p-4">
          <p class="text-sm font-medium text-green-800">{{ resultado.message }}</p>
        </div>

        <button
          type="button"
          class="btn-primary w-full justify-center"
          @click="imprimirCriados"
        >
          Imprimir etiquetas destes {{ resultado.dispositivos.length }}
        </button>

        <button
          type="button"
          class="btn-secondary w-full justify-center"
          @click="criarOutroLote"
        >
          Cadastrar outro lote
        </button>
      </div>

      <!-- Formulário -->
      <div v-else class="space-y-6">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Endereço</label>
          <div class="px-3 py-2 bg-gray-50 border border-gray-300 rounded-md text-sm text-gray-900">
            {{ enderecoDescricao }}
          </div>
        </div>

        <div v-if="erroGeral" class="rounded-md border border-red-200 bg-red-50 p-3">
          <p class="text-sm text-red-800">{{ erroGeral }}</p>
          <ul v-if="conflitos.length" class="mt-2 list-disc pl-5 text-xs text-red-700">
            <li v-for="numero in conflitos" :key="numero">{{ numero }}</li>
          </ul>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label for="lote-quantidade" class="block text-sm font-medium text-gray-700 mb-2">
              Quantidade *
            </label>
            <input
              id="lote-quantidade"
              v-model.number="form.quantidade"
              type="number"
              min="1"
              max="200"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': camposComErro.quantidade }"
            />
            <p v-if="camposComErro.quantidade" class="mt-1 text-sm text-red-600">
              {{ camposComErro.quantidade[0] }}
            </p>
          </div>

          <div>
            <label for="lote-numero-inicial" class="block text-sm font-medium text-gray-700 mb-2">
              Número inicial *
            </label>
            <input
              id="lote-numero-inicial"
              v-model.number="form.numero_inicial"
              type="number"
              min="1"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': camposComErro.numero_inicial }"
            />
            <p v-if="camposComErro.numero_inicial" class="mt-1 text-sm text-red-600">
              {{ camposComErro.numero_inicial[0] }}
            </p>
          </div>
        </div>

        <div>
          <label for="lote-prefixo" class="block text-sm font-medium text-gray-700 mb-2">
            Prefixo do rótulo *
          </label>
          <input
            id="lote-prefixo"
            v-model="form.prefixo"
            type="text"
            maxlength="20"
            required
            placeholder="Ex: PCE"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            :class="{ 'border-red-500': camposComErro.prefixo }"
          />
          <p v-if="camposComErro.prefixo" class="mt-1 text-sm text-red-600">
            {{ camposComErro.prefixo[0] }}
          </p>
        </div>

        <div>
          <label for="lote-bait-type" class="block text-sm font-medium text-gray-700 mb-2">
            Tipo de Isca
          </label>
          <select
            id="lote-bait-type"
            v-model="form.bait_type_id"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          >
            <option value="">Nenhum</option>
            <option v-for="baitType in baitTypes" :key="baitType.id" :value="baitType.id">
              {{ baitType.name }} {{ baitType.brand ? `- ${baitType.brand}` : '' }}
            </option>
          </select>
        </div>

        <div>
          <label for="lote-local" class="block text-sm font-medium text-gray-700 mb-2">
            Local previsto
          </label>
          <textarea
            id="lote-local"
            v-model="form.default_location_note"
            rows="2"
            placeholder="Ex: perímetro externo, junto ao muro"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          ></textarea>
        </div>

        <!-- Pré-visualização dos rótulos, calculada no frontend -->
        <div v-if="previsualizacao" class="rounded-md border border-gray-200 bg-gray-50 p-3">
          <p class="text-xs font-medium text-gray-500 mb-1">Pré-visualização</p>
          <p class="text-sm text-gray-800">
            {{ previsualizacao.texto }}
          </p>
          <p class="text-xs text-gray-500 mt-1">
            {{ previsualizacao.quantidade }} dispositivo(s) neste lote.
          </p>
        </div>
      </div>
    </template>

    <template #actions>
      <button type="button" class="btn-secondary" :disabled="enviando" @click="fechar">
        {{ resultado ? 'Fechar' : 'Cancelar' }}
      </button>
      <button
        v-if="!resultado"
        type="button"
        :disabled="enviando || !formValido"
        class="btn-primary"
        @click="enviar"
      >
        {{ enviando ? 'Criando...' : 'Criar lote' }}
      </button>
    </template>
  </Modal>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  address: {
    type: Object,
    default: null,
  },
  baitTypes: {
    type: Array,
    default: () => [],
  },
});

const emit = defineEmits(['close', 'criado']);

const PISO_DE_CASAS = 3;

function formPadrao() {
  return {
    quantidade: 1,
    numero_inicial: 1,
    prefixo: '',
    bait_type_id: '',
    default_location_note: '',
  };
}

const form = ref(formPadrao());
const enviando = ref(false);
const erroGeral = ref('');
const conflitos = ref([]);
const camposComErro = ref({});
const resultado = ref(null);

const enderecoDescricao = computed(() => {
  if (!props.address) return '';

  const rua = [props.address.street, props.address.number].filter(Boolean).join(', ');
  return [props.address.nickname, rua].filter(Boolean).join(' - ');
});

function rotulo(numero) {
  const prefixo = form.value.prefixo.trim();
  return prefixo === '' ? numero : `${prefixo} ${numero}`;
}

// Mesma regra de `DeviceBatchService::numerosDaFaixa`: comprimento segue o
// MAIOR número da faixa, respeitando o piso de três casas.
const previsualizacao = computed(() => {
  const quantidade = Number(form.value.quantidade);
  const inicial = Number(form.value.numero_inicial);

  if (!Number.isInteger(quantidade) || quantidade < 1 || quantidade > 200) return null;
  if (!Number.isInteger(inicial) || inicial < 1) return null;

  const final = inicial + quantidade - 1;
  const casas = Math.max(PISO_DE_CASAS, String(final).length);

  const primeiros = [];
  const tamanhoAmostra = Math.min(quantidade, 3);
  for (let i = 0; i < tamanhoAmostra; i++) {
    primeiros.push(rotulo(String(inicial + i).padStart(casas, '0')));
  }

  const rotuloFinal = rotulo(String(final).padStart(casas, '0'));

  let texto = primeiros.join(', ');
  if (quantidade > primeiros.length) {
    texto += quantidade > primeiros.length + 1 ? ` … ${rotuloFinal}` : `, ${rotuloFinal}`;
  }

  return { texto, quantidade };
});

const formValido = computed(() => {
  const quantidade = Number(form.value.quantidade);
  const inicial = Number(form.value.numero_inicial);

  return Number.isInteger(quantidade) && quantidade >= 1 && quantidade <= 200
    && Number.isInteger(inicial) && inicial >= 1
    && form.value.prefixo.trim().length > 0
    && !!props.address;
});

watch(() => props.show, (visivel) => {
  if (visivel) {
    form.value = formPadrao();
    erroGeral.value = '';
    conflitos.value = [];
    camposComErro.value = {};
    resultado.value = null;
  }
});

function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
}

async function enviar() {
  if (!formValido.value || enviando.value || !props.address) return;

  enviando.value = true;
  erroGeral.value = '';
  conflitos.value = [];
  camposComErro.value = {};

  // Campos opcionais só entram no corpo quando preenchidos: `bait_type_id`
  // tem regra de closure no backend (StoreDeviceBatchRequest) que roda mesmo
  // com o campo ausente SE ele for enviado como `null` explícito, e nesse
  // caso falha por não achar tipo de isca nenhum. Omitir a chave por
  // completo (e não mandar `null`) é o que faz o backend tratar como "sem
  // tipo de isca".
  const payload = {
    quantidade: Number(form.value.quantidade),
    numero_inicial: Number(form.value.numero_inicial),
    prefixo: form.value.prefixo.trim(),
  };

  if (form.value.bait_type_id) {
    payload.bait_type_id = form.value.bait_type_id;
  }

  if (form.value.default_location_note.trim() !== '') {
    payload.default_location_note = form.value.default_location_note.trim();
  }

  try {
    const resposta = await fetch(route('addresses.devices.lote', props.address.id), {
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
      erroGeral.value = dados.message || 'Não foi possível criar o lote de dispositivos.';
      conflitos.value = dados.numeros_em_conflito || [];
      camposComErro.value = dados.errors || {};
      return;
    }

    resultado.value = { dispositivos: dados.dispositivos, message: dados.message };
    emit('criado', dados.dispositivos);
  } catch (erro) {
    erroGeral.value = 'Não foi possível falar com o servidor agora. Verifique a conexão e tente novamente.';
  } finally {
    enviando.value = false;
  }
}

function imprimirCriados() {
  if (!resultado.value || !props.address) return;

  const ids = resultado.value.dispositivos.map((dispositivo) => dispositivo.id);
  const parametros = new URLSearchParams();
  ids.forEach((id) => parametros.append('device_ids[]', id));

  const url = `${route('addresses.devices.etiquetas', props.address.id)}?${parametros.toString()}`;
  window.open(url, '_blank');
}

function criarOutroLote() {
  resultado.value = null;
  form.value = formPadrao();
  erroGeral.value = '';
  conflitos.value = [];
  camposComErro.value = {};
}

function fechar() {
  if (enviando.value) return;
  emit('close');
}
</script>
