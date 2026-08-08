<template>
  <Modal :show="show" @close="fechar">
    <template #icon>
      <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
        />
      </svg>
    </template>
    <template #title>
      Renovar contrato {{ contract?.contract_number || (contract ? `#${contract.id}` : '') }}
    </template>
    <template #content>
      <div v-if="estado === 'carregando'" class="py-6 text-center text-sm text-gray-500">
        Carregando dados da renovação...
      </div>

      <div v-else-if="estado === 'recusado'">
        <p class="text-sm text-red-700">
          {{ previa?.motivo_recusa || 'Este contrato não pode ser renovado agora.' }}
        </p>
      </div>

      <div v-else-if="estado === 'resultado'">
        <p class="text-sm font-medium text-green-800 mb-3">{{ resultado?.message }}</p>
        <dl class="text-sm text-gray-700 space-y-1 border border-gray-200 rounded-md p-3">
          <div class="flex justify-between">
            <dt>Visitas geradas</dt>
            <dd class="font-medium text-gray-900">{{ resultado?.data?.visitas_geradas ?? 0 }}</dd>
          </div>
          <div class="flex justify-between">
            <dt>Visitas futuras canceladas do contrato anterior</dt>
            <dd class="font-medium text-gray-900">{{ resultado?.data?.cancelamento?.canceladas ?? 0 }}</dd>
          </div>
        </dl>
      </div>

      <div v-else>
        <p class="text-sm text-gray-700 mb-4">
          Cliente {{ contract?.cliente || '-' }}. Valor atual: <strong>{{ formatarMoeda(previa?.valor_atual) }}</strong>.
          Nova vigência a partir de <strong>{{ formatarData(previa?.inicio_do_novo_contrato) }}</strong>.
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-1">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Percentual de reajuste (%)</label>
            <input
              v-model.number="form.percentual_reajuste"
              type="number"
              step="0.01"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Índice de referência (opcional)</label>
            <input
              v-model="form.indice_reajuste"
              type="text"
              maxlength="50"
              placeholder="Ex.: IPCA, IGP-M"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>
        </div>
        <p class="text-xs text-gray-500 mb-4">
          O índice é só um rótulo para o histórico do contrato. O percentual acima é o que efetivamente reajusta o
          valor: informe-o mesmo quando escolher um índice.
        </p>

        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-1">Data de término do novo contrato (opcional)</label>
          <input
            v-model="form.end_date"
            type="date"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          />
          <p class="mt-1 text-xs text-gray-500">Deixe em branco para manter a mesma duração da vigência anterior.</p>
        </div>

        <div class="rounded-md bg-green-50 border border-green-200 p-3 mb-4">
          <p class="text-sm text-green-800">
            Valor novo previsto: <strong>{{ formatarMoeda(valorNovoPrevisto) }}</strong>
          </p>
        </div>

        <div class="rounded-md bg-amber-50 border border-amber-200 p-3">
          <p class="text-sm font-medium text-amber-900 mb-1">Efeitos ao confirmar</p>
          <ul class="text-sm text-amber-800 list-disc list-inside space-y-0.5">
            <li>Um contrato novo é criado, vigente a partir de {{ formatarData(previa?.inicio_do_novo_contrato) }}.</li>
            <li>{{ previa?.visitas_futuras_a_cancelar ?? 0 }} visita(s) futura(s) do contrato atual será(ão) cancelada(s).</li>
            <li>A quantidade de visitas geradas para o contrato novo só aparece depois de confirmar.</li>
          </ul>
        </div>

        <p v-if="erroEnvio" class="mt-3 text-sm text-red-600">{{ erroEnvio }}</p>
      </div>
    </template>
    <template #actions>
      <template v-if="estado === 'recusado' || estado === 'resultado'">
        <button type="button" class="btn-primary" @click="fechar">Fechar</button>
      </template>
      <template v-else-if="estado !== 'carregando'">
        <button type="button" class="btn-secondary" :disabled="estado === 'enviando'" @click="fechar">Cancelar</button>
        <button type="button" class="btn-primary ml-3" :disabled="estado === 'enviando'" @click="confirmar">
          {{ estado === 'enviando' ? 'Renovando...' : 'Confirmar renovação' }}
        </button>
      </template>
    </template>
  </Modal>
</template>

<script setup>
/**
 * Modal de renovação de contrato (Plano 23, Task 23.8), sobre os endpoints da
 * Task 23.6 (`ContractRenewalController`). Abre sempre chamando `previa`
 * primeiro (nunca deixa confirmar sem saber se é elegível), mostra o valor
 * reajustado e os efeitos (contrato novo, visitas futuras canceladas) antes
 * de confirmar, e só informa a quantidade de visitas geradas depois da
 * resposta do `POST .../renovar` - `previa` não calcula isso de propósito
 * (ver o docblock do controller).
 *
 * `contract` é o item de linha vindo de `Contracts/AVencer.vue`
 * ({ id, contract_number, end_date, situacao_renovacao, cliente, endereco }).
 * O payload de "a vencer" não traz o valor do contrato - por isso o valor
 * atual só aparece aqui, depois de `previa` devolver `valor_atual`, e a linha
 * da lista na tela-mãe não mostra valor nenhum.
 */
import { computed, reactive, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import { formatarData } from '@/utils/formatDate';

const props = defineProps({
  show: { type: Boolean, default: false },
  contract: { type: Object, default: null },
});

const emit = defineEmits(['close', 'renovado']);

const estado = ref('carregando'); // carregando | recusado | formulario | enviando | resultado
const previa = ref(null);
const resultado = ref(null);
const erroEnvio = ref('');

const form = reactive({
  percentual_reajuste: 0,
  indice_reajuste: '',
  end_date: '',
});

function formatarMoeda(valor) {
  if (valor === null || valor === undefined || valor === '') return '-';

  return Number(valor).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

// Pré-visualização calculada no frontend só para o usuário decidir o
// percentual antes de confirmar. O valor de verdade é sempre o que
// `ContractRenewalService::valorComReajuste()` grava na resposta do POST.
const valorNovoPrevisto = computed(() => {
  const atual = Number(previa.value?.valor_atual ?? 0);
  const percentual = Number(form.percentual_reajuste || 0);

  return atual * (1 + percentual / 100);
});

async function carregarPrevia() {
  estado.value = 'carregando';
  previa.value = null;

  try {
    const resposta = await fetch(route('contracts.renovar.previa', props.contract.id), {
      headers: { Accept: 'application/json' },
    });
    const dados = await resposta.json();

    previa.value = dados;
    estado.value = dados.elegivel ? 'formulario' : 'recusado';
  } catch (erro) {
    previa.value = { elegivel: false, motivo_recusa: 'Não foi possível carregar os dados da renovação.' };
    estado.value = 'recusado';
  }
}

watch(
  () => props.show,
  (aberto) => {
    if (!aberto || !props.contract) return;

    resultado.value = null;
    erroEnvio.value = '';
    form.percentual_reajuste = 0;
    form.indice_reajuste = '';
    form.end_date = '';

    carregarPrevia();
  }
);

async function confirmar() {
  estado.value = 'enviando';
  erroEnvio.value = '';

  try {
    const resposta = await fetch(route('contracts.renovar', props.contract.id), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
        Accept: 'application/json',
      },
      body: JSON.stringify({
        percentual_reajuste: form.percentual_reajuste || 0,
        indice_reajuste: form.indice_reajuste || undefined,
        end_date: form.end_date || undefined,
      }),
    });

    const dados = await resposta.json();

    if (resposta.ok && dados.success) {
      resultado.value = dados;
      estado.value = 'resultado';
      emit('renovado');
    } else {
      erroEnvio.value = dados.message || 'Não foi possível renovar o contrato.';
      estado.value = 'formulario';
    }
  } catch (erro) {
    erroEnvio.value = 'Não foi possível renovar o contrato.';
    estado.value = 'formulario';
  }
}

function fechar() {
  if (estado.value === 'enviando') return;

  emit('close');
}
</script>
